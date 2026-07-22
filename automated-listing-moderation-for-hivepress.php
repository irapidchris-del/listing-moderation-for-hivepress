<?php
/**
 * Plugin Name: Automated Listing Moderation for HivePress
 * Description: Blocks or risk-scores listing submissions containing blocked words, phrases, regex patterns, phone numbers, email addresses, website URLs, duplicate content or AI-flagged text and photos, with a per-vendor submission limit, a verified-vendor bypass, and a Moderation score column and meta box in the dashboard. Configure under HivePress → Settings → Listings → Automated Moderation.
 * Version:     1.3.4
 * Author:      ChrisB @ HivePress Community
 * Author URI:  https://community.hivepress.io/u/chrisb/summary
 * Text Domain: automated-listing-moderation-for-hivepress
 * Requires:    HivePress
 *
 * @package Automated_Listing_Moderation_For_HivePress
 *
 * HOW IT WORKS (all internals verified against HivePress core source, v1.x):
 *
 * 1. Settings are registered via the `hivepress/v1/settings` config filter.
 *    Settings-screen fields persist as options prefixed with `hp_`, and
 *    each field's `description` is rendered by HivePress admin as a hover
 *    tooltip through wp_kses_post. The OpenAI key lives on the native
 *    Integrations tab, following the core reCAPTCHA convention.
 *
 * 2. Validation hooks `hivepress/v1/forms/listing_update/errors`.
 *    Form::validate() fires this filter for a form's own class AND every
 *    parent class, and Forms\Listing_Submit extends Forms\Listing_Update,
 *    so one hook covers both the submit and edit frontend forms. Errors
 *    returned here refuse the submission before anything is saved.
 *
 * 3. TWO ENFORCEMENT LEVELS:
 *    - BLOCK: the form is refused with a specific inline error.
 *    - RISK SCORE: soft signals accumulate points; if the total reaches
 *      the configured threshold the listing is accepted but HELD AS
 *      PENDING for admin review instead of publishing. This reuses
 *      HivePress's native moderation flow: for new submissions the
 *      plugin sets a `_hpalm_flagged` marker and filters
 *      `option_hp_listing_enable_moderation` (the exact option the
 *      submit-complete redirect reads to choose pending vs publish,
 *      verified in Controllers\Listing), so the native pending status,
 *      admin notification email and admin-menu pending badge all apply.
 *      For edits to published listings the plugin calls
 *      set_status('pending') on the form's model inside the errors
 *      filter, the same technique core itself uses for moderated
 *      attributes. When an admin approves a flagged listing, an
 *      update_status hook (priority 9, before core's email handler at
 *      10) briefly forces the same option on so the native approval
 *      email still reaches the vendor, then removes the marker.
 *
 *    THERE IS DELIBERATELY NO AUTO-TRASH TIER. Heuristics false-positive,
 *    and silently destroying a legitimate vendor's listing is the worst
 *    possible failure mode for a marketplace. Pending review is the
 *    safety net: a human always makes the final negative decision.
 *
 * 4. Default risk weights (customise via the `hpalm_risk_weights` filter):
 *    phone 25, email 25, url 15, excessive caps 15, duplicate title 40,
 *    duplicate description 40, AI flag 50. Each signal counts once per
 *    submission. Suggested threshold: 21.
 *
 * 5. DUPLICATE DETECTION stores an md5 fingerprint of the normalised
 *    (lowercased, tag-stripped, whitespace-collapsed) title and
 *    description as hidden post meta on every listing save, so a duplicate
 *    check is one small query rather than a content scan. Existing
 *    listings are fingerprinted automatically in background batches of 200
 *    per admin page load. Note that WordPress does not index postmeta
 *    values, so MySQL narrows by the fingerprint meta key and compares
 *    within it: fast at typical directory sizes, but not a constant-time
 *    lookup on sites with hundreds of thousands of listings.
 *
 * 6. OPENAI MODERATION (optional) sends the listing text to OpenAI's
 *    free Moderation endpoint (model omni-moderation-latest) and acts on
 *    the returned `flagged` boolean. Requires an OpenAI API key. FAILS
 *    OPEN: if the API is unreachable, times out (8s) or errors, the
 *    submission proceeds as if not flagged, since an outage must never take
 *    down listing submission. Site owners should disclose the data
 *    sharing in their privacy policy.
 *
 * 7. The OpenAI key is stored as `hp_openai_api_key`, a deliberately
 *    generic name so a single key can be shared with any other OpenAI
 *    integration on the site. For that reason uninstall.php does NOT
 *    delete it: removing a credential another plugin might rely on would
 *    be a destructive side effect. Clear the field before uninstalling if
 *    you want the key gone.
 *
 * 8. v1.3.0 ADDITIONS. Verified-vendor bypass reuses HivePress's native
 *    Verified checkbox on the vendor profile; when enabled, listings from
 *    verified vendors skip every check. The submission limit counts a
 *    vendor's publish and pending listings from the last 24 hours using
 *    the local post_date column, because WordPress zeroes post_date_gmt
 *    for draft, pending and auto-draft posts (date_floating statuses,
 *    verified in WP 7.0 wp-includes/post.php). AI photo review sends all
 *    listing photos to the same free OpenAI moderation endpoint in a
 *    single request. Risk-scored outcomes are stored per listing as
 *    _hpalm_score, _hpalm_signals and _hpalm_threshold and surfaced in a
 *    sortable Moderation column and a listing meta box; a clean
 *    resubmission removes the record so the admin screens always describe
 *    the listing's current content.
 *
 * KNOWN LIMITATIONS (by design, documented honestly):
 * - Admin-side edits in wp-admin bypass frontend forms and are not
 *   checked. Frontend edits by logged-in admins ARE checked.
 * - Keywords match inside longer words ("class" matches "classic"); use
 *   Patterns with \b for whole words.
 * - WordPress strips angle brackets from settings textareas on save, so
 *   regex assertions containing "<" (e.g. lookbehinds) cannot be used.
 * - A flagged EDIT of a published listing is held as pending without an
 *   admin email (core only emails for moderated-attribute changes); the
 *   native pending badge on the admin menu still shows it.
 * - Obfuscated contact details written as words ("oh seven one two",
 *   "name at gmail dot com") are not detected; add Patterns for those.
 * - Leet-speak detection reverses common substitutions to their visual
 *   look-alike letter (@=a, $=s, !=i, 0=o, 1=i/l, 3=e, 4=a, 5=s, 7=t).
 *   Substitutions that change the word (f0ck for fuck) need their own
 *   keyword or pattern. Not exhaustive by design.
 */

defined( 'ABSPATH' ) || exit;

/*
 * -------------------------------------------------------------------------
 * Settings.
 * -------------------------------------------------------------------------
 */

/**
 * Returns the shared mode options for detector selects.
 *
 * The HivePress Select field automatically prepends the placeholder as an
 * empty first option (verified in Fields\Select::boot), so the empty
 * value doubles as "Disabled".
 *
 * @return array
 */
function hpalm_get_mode_options() {
	return [
		'block' => esc_html__( 'Block submission', 'automated-listing-moderation-for-hivepress' ),
		'score' => esc_html__( 'Add to risk score', 'automated-listing-moderation-for-hivepress' ),
	];
}

/**
 * Registers settings: the Automated Moderation section on the Listings
 * tab, and the OpenAI section on the Integrations tab.
 *
 * Core listings-tab section orders: display = 10, submission = 20,
 * expiration = 30 (verified), so this section slots in at 25.
 *
 * @param array $settings HivePress settings configuration.
 * @return array
 */
function hpalm_register_settings( $settings ) {
	if ( isset( $settings['listings']['sections'] ) ) {
		$modes = hpalm_get_mode_options();

		$settings['listings']['sections']['automated_moderation'] = [
			'title'  => esc_html__( 'Automated Moderation', 'automated-listing-moderation-for-hivepress' ),
			'_order' => 25,

			'fields' => [
				'listing_bypass_verified_vendors'      => [
					'label'       => esc_html__( 'Verified Vendors', 'automated-listing-moderation-for-hivepress' ),
					'caption'     => esc_html__( 'Skip checks for verified vendors', 'automated-listing-moderation-for-hivepress' ),
					'description' => esc_html__( 'Skip every moderation check for listings from vendors marked as verified. Vendors can be verified by ticking the Verified checkbox on their profile in the WordPress dashboard.', 'automated-listing-moderation-for-hivepress' ),
					'type'        => 'checkbox',
					'_order'      => 2,
				],

				'listing_velocity_limit'               => [
					'label'       => esc_html__( 'Submission Limit', 'automated-listing-moderation-for-hivepress' ),
					'description' => esc_html__( 'The maximum number of listings each vendor can submit within 24 hours. Editing an existing listing does not count towards the limit. Leave empty to disable.', 'automated-listing-moderation-for-hivepress' ),
					'type'        => 'number',
					'min_value'   => 1,
					'_order'      => 4,
				],

				'listing_blocked_keywords'             => [
					'label'       => esc_html__( 'Blocked Keywords', 'automated-listing-moderation-for-hivepress' ),
					'description' => __( 'Words or phrases that block a listing from being submitted. Separate with commas or new lines. Matching is case-insensitive and matches <strong>inside</strong> longer words: blocking <code>class</code> also blocks <code>classic</code>. For whole-word matching, use Blocked Patterns with <code>\b</code> boundaries instead. The button below imports a starter list of common profanity.', 'automated-listing-moderation-for-hivepress' ),
					'placeholder' => 'cheap, cash in hand, whatsapp me',
					'type'        => 'textarea',
					'max_length'  => 10240,
					'_order'      => 10,
				],

				'listing_blocked_patterns'             => [
					'label'       => esc_html__( 'Blocked Patterns', 'automated-listing-moderation-for-hivepress' ),
					'description' => __( 'Advanced: regular expressions, <strong>one per line only</strong>. Commas are regex syntax, so they do not separate patterns here. No delimiters, case-insensitive. Examples: <code>\bclass\b</code> blocks the whole word only, not "classic". <code>\bcash\s*only\b</code> blocks "cash only" with any spacing. <code>colou?r</code> blocks both spellings. Escape a literal ~ as <code>\~</code>. Invalid patterns are skipped. Note: WordPress strips angle brackets on save, so assertions containing &lt; cannot be used.', 'automated-listing-moderation-for-hivepress' ),
					'placeholder' => '\bcash\s*only\b',
					'type'        => 'textarea',
					'max_length'  => 10240,
					'_order'      => 20,
				],

				'listing_block_evasion'                => [
					'label'       => esc_html__( 'Catch Character Evasion', 'automated-listing-moderation-for-hivepress' ),
					'caption'     => esc_html__( 'Also match accented and leet-speak look-alikes of blocked words', 'automated-listing-moderation-for-hivepress' ),
					'description' => __( 'Re-checks keywords and patterns against copies of the text with accents removed, invisible characters stripped and common leet substitutions reversed, so blocking <code>fuck</code> also catches <code>fùck</code> and zero-width-character tricks, and blocking <code>shit</code> also catches <code>sh1t</code> and <code>$hit</code>. Substitutions are reversed to their look-alike letter (0 becomes o, 1 becomes i or l, $ becomes s), so a substitution that changes the word (like <code>f0ck</code>) needs its own keyword or pattern. Legitimate accented text (e.g. "café", French names) is NOT blocked by this option; it only widens what your existing keywords match.', 'automated-listing-moderation-for-hivepress' ),
					'type'        => 'checkbox',
					'_order'      => 30,
				],

				'listing_block_phones'                 => [
					'label'       => esc_html__( 'Phone Numbers', 'automated-listing-moderation-for-hivepress' ),
					'description' => __( 'Detects sequences of 9 to 14 digits, optionally separated by spaces, dashes or brackets, with an optional leading +, e.g. <code>07123 456789</code> or <code>+44 7911 123456</code>. Prices, years and dates are not affected. Sharing phone numbers is often an attempt to move bookings off-platform.', 'automated-listing-moderation-for-hivepress' ),
					'placeholder' => esc_html__( 'Disabled', 'automated-listing-moderation-for-hivepress' ),
					'type'        => 'select',
					'options'     => $modes,
					'_order'      => 40,
				],

				'listing_block_emails'                 => [
					'label'       => esc_html__( 'Email Addresses', 'automated-listing-moderation-for-hivepress' ),
					'description' => __( 'Detects anything shaped like an email address, e.g. <code>name@example.com</code>. Obfuscated forms ("name at gmail dot com") are not detected; add a Blocked Pattern for those if needed.', 'automated-listing-moderation-for-hivepress' ),
					'placeholder' => esc_html__( 'Disabled', 'automated-listing-moderation-for-hivepress' ),
					'type'        => 'select',
					'options'     => $modes,
					'_order'      => 50,
				],

				'listing_block_urls'                   => [
					'label'       => esc_html__( 'Website Addresses', 'automated-listing-moderation-for-hivepress' ),
					'description' => __( 'Detects links starting with http, https or www, plus bare domains ending in a common extension such as <code>example.com</code> or <code>example.co.uk</code>. Note this also applies to your own site address if vendors mention it.', 'automated-listing-moderation-for-hivepress' ),
					'placeholder' => esc_html__( 'Disabled', 'automated-listing-moderation-for-hivepress' ),
					'type'        => 'select',
					'options'     => $modes,
					'_order'      => 60,
				],

				'listing_check_duplicate_titles'       => [
					'label'       => esc_html__( 'Duplicate Titles', 'automated-listing-moderation-for-hivepress' ),
					'description' => __( 'Detects when another live or pending listing already has the same title (ignoring case and extra spaces). Compares a precomputed fingerprint of each listing rather than scanning listing content, so it costs one extra database query per submission. Existing listings are fingerprinted automatically in the background after you enable this.', 'automated-listing-moderation-for-hivepress' ),
					'placeholder' => esc_html__( 'Disabled', 'automated-listing-moderation-for-hivepress' ),
					'type'        => 'select',
					'options'     => $modes,
					'_order'      => 70,
				],

				'listing_check_duplicate_descriptions' => [
					'label'       => esc_html__( 'Duplicate Descriptions', 'automated-listing-moderation-for-hivepress' ),
					'description' => __( 'Detects when another live or pending listing already has an identical description (ignoring case, formatting and extra spaces). Like duplicate titles, this compares stored fingerprints rather than scanning content. Near-duplicates with small wording changes are not detected.', 'automated-listing-moderation-for-hivepress' ),
					'placeholder' => esc_html__( 'Disabled', 'automated-listing-moderation-for-hivepress' ),
					'type'        => 'select',
					'options'     => $modes,
					'_order'      => 80,
				],

				'listing_block_ai'                     => [
					'label'       => esc_html__( 'AI Text Review (OpenAI)', 'automated-listing-moderation-for-hivepress' ),
					'description' => __( 'Sends the listing text to OpenAI\'s free Moderation endpoint (model omni-moderation-latest), which flags hate, harassment, violence, self-harm and sexual content. Requires an OpenAI API key, entered on the Integrations tab. Listing text is sent to OpenAI\'s servers; disclose this in your privacy policy. If the API is unreachable, submissions proceed unchecked rather than failing.', 'automated-listing-moderation-for-hivepress' ),
					'placeholder' => esc_html__( 'Disabled', 'automated-listing-moderation-for-hivepress' ),
					'type'        => 'select',
					'options'     => $modes,
					'_order'      => 90,
				],

				'listing_block_ai_images'              => [
					'label'       => esc_html__( 'AI Photo Review (OpenAI)', 'automated-listing-moderation-for-hivepress' ),
					'description' => esc_html__( 'Check listing photos with the free OpenAI moderation endpoint. All photos are reviewed in a single request. Requires an OpenAI API key on the Integrations tab, and the site must be publicly reachable so OpenAI can fetch the photos. If the service is unavailable the submission proceeds unchecked.', 'automated-listing-moderation-for-hivepress' ),
					'type'        => 'select',
					'options'     => hpalm_get_mode_options(),
					'placeholder' => esc_html__( 'Disabled', 'automated-listing-moderation-for-hivepress' ),
					'_order'      => 95,
				],

				'listing_score_caps'                   => [
					'label'       => esc_html__( 'Excessive Capitals', 'automated-listing-moderation-for-hivepress' ),
					'caption'     => esc_html__( 'Add shouty text to the risk score', 'automated-listing-moderation-for-hivepress' ),
					'description' => __( 'Adds risk points when a field is written mostly in capitals (70% or more of at least 12 letters), e.g. "BUY NOW CHEAP!!!". This is a risk signal only, never an outright block, and only counts when a Risk Threshold is set below.', 'automated-listing-moderation-for-hivepress' ),
					'type'        => 'checkbox',
					'_order'      => 100,
				],

				'listing_risk_threshold'               => [
					'label'       => esc_html__( 'Risk Threshold', 'automated-listing-moderation-for-hivepress' ),
					'description' => __( 'Enables risk scoring. Signals set to "Add to risk score" accumulate points: phone 25, email 25, website 15, excessive capitals 15, duplicate title 40, duplicate description 40, AI text flag 50, AI photo flag 50. If the total reaches this threshold, the listing is accepted but held as Pending for your review instead of publishing, using the native HivePress moderation flow. Suggested value: 21, so any contact detail or any duplicate triggers review, but a single website mention alone does not. Leave empty to disable scoring. There is deliberately no auto-delete tier: a human always makes the final call.', 'automated-listing-moderation-for-hivepress' ),
					'type'        => 'number',
					'min_value'   => 1,
					'_order'      => 110,
				],
			],
		];
	}

	if ( isset( $settings['integrations']['sections'] ) ) {

		// Create the OpenAI section only if no other plugin has added one
		// already, then append the field to it. Assigning the whole section
		// unconditionally would silently overwrite another plugin's fields.
		if ( ! isset( $settings['integrations']['sections']['openai'] ) ) {
			$settings['integrations']['sections']['openai'] = [
				'title'  => 'OpenAI',
				'_order' => 40,
				'fields' => [],
			];
		}

		// The option name is deliberately generic (hp_openai_api_key) so that
		// one OpenAI key can be shared by any integration on the site. The
		// field is guarded like the section, so any other plugin using the
		// same shared-key contract can register it first without duplication
		// in either load order.
		if ( ! isset( $settings['integrations']['sections']['openai']['fields']['openai_api_key'] ) ) {
			$settings['integrations']['sections']['openai']['fields']['openai_api_key'] = [
				'label'       => esc_html__( 'API Key', 'automated-listing-moderation-for-hivepress' ),
				'description' => __( 'Your OpenAI API key, shared by any installed extension that uses OpenAI\'s free Moderation endpoint. Moderation calls are free, but an OpenAI API account is required to obtain a key.', 'automated-listing-moderation-for-hivepress' ),
				'type'        => 'text',
				'max_length'  => 256,
				'_order'      => 10,
			];
		}
	}

	return $settings;
}
add_filter( 'hivepress/v1/settings', 'hpalm_register_settings' );

/*
 * -------------------------------------------------------------------------
 * Configuration helpers.
 * -------------------------------------------------------------------------
 */

/**
 * Splits a textarea option into a clean array of non-empty entries.
 * Keywords accept commas AND new lines; patterns split on new lines ONLY,
 * because commas are regex syntax (e.g. \d{2,5}).
 *
 * @param string $option_name  Option name (with hp_ prefix).
 * @param bool   $split_commas Whether commas also act as separators.
 * @return array
 */
function hpalm_get_lines( $option_name, $split_commas = false ) {
	$value = get_option( $option_name );

	if ( ! is_string( $value ) || '' === trim( $value ) ) {
		return [];
	}

	$parts = $split_commas ? preg_split( '/[\n,]+/', $value ) : explode( "\n", $value );

	// preg_split returns false on a PCRE error, and array_map would then raise
	// a TypeError, which is fatal in PHP 8.
	if ( ! is_array( $parts ) ) {
		return [];
	}

	return array_values( array_filter( array_map( 'trim', $parts ) ) );
}

/**
 * Gets a detector mode: '', 'block' or 'score'.
 * A stored value of '1' (a checkbox from plugin version 1.1) is treated
 * as 'block' for backwards compatibility.
 *
 * @param string $option_name Option name (with hp_ prefix).
 * @return string
 */
function hpalm_get_mode( $option_name ) {
	$value = get_option( $option_name );

	if ( '1' === $value || 1 === $value || true === $value ) {
		return 'block';
	}

	return in_array( $value, [ 'block', 'score' ], true ) ? $value : '';
}

/**
 * Narrows an option or meta value to a non-negative integer.
 *
 * get_option() and get_post_meta() return mixed, so this is the plugin's
 * single narrowing point for numeric settings and stored scores: numeric
 * values become their absolute integer, anything else becomes 0, which is
 * the safe "disabled" or "unscored" state everywhere it is used.
 *
 * @param mixed $value Raw option or meta value.
 * @return int
 */
function hpalm_absint( $value ) {
	return is_numeric( $value ) ? abs( (int) $value ) : 0;
}

/**
 * Returns the risk weights, filterable for customisation. Example:
 *
 *     add_filter( 'hpalm_risk_weights', function( $w ) {
 *         $w['url'] = 30;
 *         return $w;
 *     } );
 *
 * @return array
 */
function hpalm_get_risk_weights() {
	return apply_filters(
		'hpalm_risk_weights',
		[
			'phone'     => 25,
			'email'     => 25,
			'url'       => 15,
			'caps'      => 15,
			'dup_title' => 40,
			'dup_desc'  => 40,
			'ai'        => 50,
			'ai_image'  => 50,
		]
	);
}

/*
 * -------------------------------------------------------------------------
 * Text normalisation and matching.
 * -------------------------------------------------------------------------
 */

/**
 * Builds evasion-normalised variants of a text: accents removed and
 * invisible characters stripped, plus leet-speak reversals. The digit 1
 * commonly stands for either "i" or "l", so both variants are produced.
 * Variants only ADD detection for configured keywords; they are never
 * checked against anything except the admin's own blocklists.
 *
 * @param string $text Original text.
 * @return array Unique variants that differ from the original.
 */
function hpalm_get_text_variants( $text ) {
	// Zero-width space/non-joiner/joiner, BOM, soft hyphen.
	$base = str_replace( [ "\xE2\x80\x8B", "\xE2\x80\x8C", "\xE2\x80\x8D", "\xEF\xBB\xBF", "\xC2\xAD" ], '', $text );

	if ( function_exists( 'remove_accents' ) ) {
		$base = remove_accents( $base );
	}

	$leet = strtr(
		$base,
		[
			'@' => 'a',
			'$' => 's',
			'!' => 'i',
			'0' => 'o',
			'3' => 'e',
			'4' => 'a',
			'5' => 's',
			'7' => 't',
		]
	);

	$variants = [ $base, strtr( $leet, [ '1' => 'i' ] ), strtr( $leet, [ '1' => 'l' ] ) ];

	return array_values( array_unique( array_diff( $variants, [ $text ] ) ) );
}

/**
 * Checks one piece of text against keywords and patterns.
 *
 * Keywords use preg_quote + case-insensitive matching (the same technique
 * as the HivePress Messages blocked-keywords check), attempted with the
 * `u` modifier first so case-insensitivity covers accented UTF-8
 * keywords, falling back without `u` on invalid byte sequences. Patterns
 * use a ~ delimiter and are guarded: invalid patterns are skipped, never
 * fatal.
 *
 * @param string $text     Text to check.
 * @param array  $keywords Plain keywords/phrases.
 * @param array  $patterns Regex patterns (no delimiters).
 * @return string|null The offending word/phrase found, or null if clean.
 */
function hpalm_find_blocked_text( $text, $keywords, $patterns ) {
	if ( '' === $text ) {
		return null;
	}

	foreach ( $keywords as $keyword ) {
		$result = @preg_match( '/' . preg_quote( $keyword, '/' ) . '/iu', $text ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- invalid UTF-8 must not raise a warning; a false result is handled on the next line.

		if ( false === $result ) {
			$result = @preg_match( '/' . preg_quote( $keyword, '/' ) . '/i', $text ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- byte-mode fallback; a false result simply skips the keyword.
		}

		if ( 1 === $result ) {
			return $keyword;
		}
	}

	foreach ( $patterns as $pattern ) {
		$matches = [];
		$result  = @preg_match( '~' . $pattern . '~iu', $text, $matches ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- admin-supplied regex may be invalid; that must never raise a warning, and failure is handled explicitly.

		if ( false === $result ) {
			$result = @preg_match( '~' . $pattern . '~i', $text, $matches ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- byte-mode fallback for invalid UTF-8; a false result skips the pattern.
		}

		if ( 1 === $result && isset( $matches[0] ) && '' !== $matches[0] ) {
			return $matches[0];
		}
	}

	return null;
}

/**
 * Runs the keyword/pattern check on the text and, when evasion checking
 * is enabled, on its normalised variants too.
 *
 * @param string $text     Text to check.
 * @param array  $keywords Plain keywords/phrases.
 * @param array  $patterns Regex patterns.
 * @param bool   $evasion  Whether to also check normalised variants.
 * @return string|null
 */
function hpalm_find_blocked_text_deep( $text, $keywords, $patterns, $evasion ) {
	$found = hpalm_find_blocked_text( $text, $keywords, $patterns );

	if ( null === $found && $evasion && ( $keywords || $patterns ) ) {
		foreach ( hpalm_get_text_variants( $text ) as $variant ) {
			$found = hpalm_find_blocked_text( $variant, $keywords, $patterns );

			if ( null !== $found ) {
				break;
			}
		}
	}

	return $found;
}

/**
 * Finds a contact detail of one type in the text.
 *
 * @param string $text Text to check.
 * @param string $type One of 'phone', 'email', 'url'.
 * @return string|null Matched text, or null.
 */
function hpalm_find_contact_detail( $text, $type ) {
	if ( '' === $text ) {
		return null;
	}

	if ( 'email' === $type && preg_match( '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', $text, $m ) ) {
		return $m[0];
	}

	if ( 'url' === $type ) {
		if ( preg_match( '~(?:https?://|www\.)[^\s<>"\']+~i', $text, $m ) ) {
			return $m[0];
		}

		// Bare domains: labels followed by a common final extension. The
		// extension allowlist keeps false positives out ("St.Andrews",
		// "No.7", "e.g." never match).
		if ( preg_match( '~\b[a-z0-9](?:[a-z0-9\-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9\-]*[a-z0-9])?)*\.(?:com|net|org|io|uk|co|me|info|biz|app|dev|online|site|shop|store|xyz|club|link|live|pro|tv)\b~i', $text, $m ) ) {
			return $m[0];
		}
	}

	if ( 'phone' === $type ) {
		// 9-14 digits, each pair optionally separated by up to two of:
		// space, dash, bracket. Optional leading + and (. Dots and commas
		// are deliberately NOT separators, so IP addresses, prices and
		// decimal chains never match. Lookarounds stop partial matches
		// inside longer pure-digit runs.
		if ( preg_match( '/(?<!\d)\+?\(?\d(?:[\s\-()]{0,2}\d){8,13}(?!\d)/', $text, $m ) ) {
			return trim( $m[0] );
		}
	}

	return null;
}

/**
 * Detects excessive capitalisation: at least 12 letters, of which 70% or
 * more are uppercase. HTML is stripped first so markup cannot skew the
 * ratio.
 *
 * @param string $text Text to check.
 * @return bool
 */
function hpalm_has_excessive_caps( $text ) {
	if ( function_exists( 'wp_strip_all_tags' ) ) {
		$text = wp_strip_all_tags( $text );
	}

	$letters = preg_match_all( '/\p{L}/u', $text );

	if ( false === $letters || $letters < 12 ) {
		return false;
	}

	$upper = preg_match_all( '/\p{Lu}/u', $text );

	return false !== $upper && ( $upper / $letters ) >= 0.7;
}

/*
 * -------------------------------------------------------------------------
 * Duplicate detection (fingerprint meta).
 * -------------------------------------------------------------------------
 */

/**
 * Builds the normalised fingerprint of a piece of content: tags stripped,
 * lowercased, whitespace collapsed, then hashed. Trivial evasion by
 * changing spacing, case or formatting therefore does not defeat the
 * duplicate check.
 *
 * @param string $text Content.
 * @return string md5 hash, or empty string for empty content.
 */
function hpalm_content_hash( $text ) {
	if ( ! is_string( $text ) ) {
		return '';
	}

	if ( function_exists( 'wp_strip_all_tags' ) ) {
		$text = wp_strip_all_tags( $text );
	}

	$text = function_exists( 'mb_strtolower' ) ? mb_strtolower( $text ) : strtolower( $text );

	// The /u modifier makes preg_replace return null when the subject is not
	// valid UTF-8, which listing content imported from a legacy source can be.
	// Without this fallback trim() would be handed null, and every affected
	// listing would hash to md5( '' ) and so look like a duplicate of every
	// other one. The non-unicode pattern still yields a distinct fingerprint;
	// if even that fails, no fingerprint is stored rather than a wrong one.
	$collapsed = preg_replace( '/\\s+/u', ' ', $text );

	if ( ! is_string( $collapsed ) ) {
		$collapsed = preg_replace( '/\\s+/', ' ', $text );
	}

	if ( ! is_string( $collapsed ) ) {
		return '';
	}

	$collapsed = trim( $collapsed );

	return '' === $collapsed ? '' : md5( $collapsed );
}

/**
 * Whether either duplicate check is enabled.
 *
 * @return bool
 */
function hpalm_duplicates_enabled() {
	return '' !== hpalm_get_mode( 'hp_listing_check_duplicate_titles' ) || '' !== hpalm_get_mode( 'hp_listing_check_duplicate_descriptions' );
}

/**
 * Stores/refreshes the fingerprints for one listing.
 *
 * Hooked to hivepress/v1/models/listing/create and .../update, which fire
 * with ($listing_id, $listing) after save (verified). Writing meta
 * directly via update_post_meta does not re-fire model hooks, so there is
 * no recursion. Fingerprints are maintained even while the duplicate
 * checks are switched off, so re-enabling them later never compares
 * against stale data (the one-time backfill only runs once).
 *
 * @param int    $listing_id Listing ID.
 * @param object $listing    Listing object (unused; raw post fields are
 *                           read directly so this also works standalone).
 */
function hpalm_store_hashes( $listing_id, $listing = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- signature fixed by the HivePress hook.
	$post = get_post( $listing_id );

	if ( ! $post instanceof WP_Post || 'hp_listing' !== $post->post_type ) {
		return;
	}

	update_post_meta( $listing_id, '_hpalm_title_hash', hpalm_content_hash( $post->post_title ) );
	update_post_meta( $listing_id, '_hpalm_desc_hash', hpalm_content_hash( $post->post_content ) );
}
add_action( 'hivepress/v1/models/listing/create', 'hpalm_store_hashes', 10, 2 );
add_action( 'hivepress/v1/models/listing/update', 'hpalm_store_hashes', 10, 2 );

/**
 * Backfills fingerprints for pre-existing listings, 200 per admin page
 * load, so activation on an established site never blocks a request.
 */
function hpalm_backfill_hashes() {
	if ( ! hpalm_duplicates_enabled() || get_option( 'hpalm_hashes_backfilled' ) ) {
		return;
	}

	$posts = get_posts(
		[
			'post_type'        => 'hp_listing',
			'post_status'      => 'any',
			'numberposts'      => 200,
			'meta_query'       => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				[
					'key'     => '_hpalm_desc_hash',
					'compare' => 'NOT EXISTS',
				],
			],
		]
	);

	// Reuse the same routine the save hooks use, so the two paths can never
	// normalise content differently. get_posts has primed the post cache, so
	// the get_post() call inside costs no extra query.
	foreach ( $posts as $post ) {
		hpalm_store_hashes( $post->ID );
	}

	if ( count( $posts ) < 200 ) {
		update_option( 'hpalm_hashes_backfilled', 1, false );
	}
}
add_action( 'admin_init', 'hpalm_backfill_hashes' );

/**
 * Checks whether another live or pending listing already carries the
 * given fingerprint. One query, comparing stored fingerprints rather than
 * scanning listing content.
 *
 * @param string $hash       Content fingerprint.
 * @param string $meta_key   _hpalm_title_hash or _hpalm_desc_hash.
 * @param int    $exclude_id Listing ID to exclude (the one being edited).
 * @return bool
 */
function hpalm_is_duplicate( $hash, $meta_key, $exclude_id ) {
	global $wpdb;

	if ( '' === $hash ) {
		return false;
	}

	$found = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->prepare(
			"SELECT p.ID FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
			WHERE p.post_type = 'hp_listing'
			AND p.post_status IN ( 'publish', 'pending' )
			AND m.meta_key = %s AND m.meta_value = %s
			AND p.ID != %d
			LIMIT 1",
			$meta_key,
			$hash,
			$exclude_id
		)
	);

	return ! empty( $found );
}

/*
 * -------------------------------------------------------------------------
 * OpenAI moderation.
 * -------------------------------------------------------------------------
 */

/**
 * Sends one request to OpenAI's free Moderation endpoint.
 *
 * FAILS OPEN: returns null (treated as not flagged) when no key is set,
 * the request errors, times out, or the response cannot be parsed. A
 * moderation outage must never take down listing submission.
 *
 * @param array|string $input Moderation input: a text string, or an array of multi-modal input objects.
 * @param int          $timeout Request timeout in seconds.
 * @return bool|null True if flagged, false if clean, null if unavailable.
 */
function hpalm_ai_moderation_request( $input, $timeout ) {
	$key = get_option( 'hp_openai_api_key' );

	if ( ! is_string( $key ) || '' === trim( $key ) ) {
		return null;
	}

	$body = wp_json_encode(
		[
			'model' => 'omni-moderation-latest',
			'input' => $input,
		]
	);

	// wp_json_encode returns false if the text cannot be encoded, so treat
	// that as unavailable rather than posting an empty body to OpenAI.
	if ( ! is_string( $body ) ) {
		return null;
	}

	$response = wp_remote_post(
		'https://api.openai.com/v1/moderations',
		[
			'timeout' => $timeout,
			'headers' => [
				'Authorization' => 'Bearer ' . trim( $key ),
				'Content-Type'  => 'application/json',
			],
			'body'    => $body,
		]
	);

	if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
		return null;
	}

	$body = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( ! is_array( $body ) || ! isset( $body['results'] ) || ! is_array( $body['results'] ) ) {
		return null;
	}

	$first = isset( $body['results'][0] ) && is_array( $body['results'][0] ) ? $body['results'][0] : null;

	if ( null === $first || ! isset( $first['flagged'] ) ) {
		return null;
	}

	return (bool) $first['flagged'];
}

/**
 * Checks listing text against the OpenAI moderation endpoint.
 *
 * @param string $text Combined listing text.
 * @return bool|null True when flagged, false when clean, null when unavailable.
 */
function hpalm_ai_flagged( $text ) {
	if ( '' === trim( $text ) ) {
		return null;
	}

	return hpalm_ai_moderation_request(
		function_exists( 'mb_substr' ) ? mb_substr( $text, 0, 8000 ) : substr( $text, 0, 8000 ),
		8
	);
}

/**
 * Checks listing photos against the OpenAI moderation endpoint.
 *
 * All photos are sent as one request: the endpoint accepts an array of
 * multi-modal input objects and flags the result if any of them is flagged
 * (verified against the OpenAI API reference; images up to 20 MB, and the
 * endpoint is free). The timeout is longer than for text because OpenAI
 * fetches each photo from this site before classifying it.
 *
 * @param array $urls Publicly reachable image URLs.
 * @return bool|null True when flagged, false when clean, null when unavailable.
 */
function hpalm_ai_flags_images( $urls ) {
	$input = [];

	foreach ( $urls as $url ) {
		$input[] = [
			'type'      => 'image_url',
			'image_url' => [
				'url' => $url,
			],
		];
	}

	if ( ! $input ) {
		return null;
	}

	return hpalm_ai_moderation_request( $input, 15 );
}

/**
 * Collects the public URLs of a form's listing photos.
 *
 * The images field holds attachment IDs (normalised to an array by
 * Fields\Attachment_Upload when multiple, verified). URLs that are not
 * absolute http(s) are skipped, since OpenAI could never fetch them. The
 * number of photos sent is capped, filterable via hpalm_max_ai_images.
 *
 * @param object $form Form object.
 * @return array
 */
function hpalm_collect_image_urls( $form ) {
	$urls = [];

	foreach ( $form->get_fields() as $field ) {
		if ( 'images' !== $field->get_name() || $field->is_disabled() ) {
			continue;
		}

		$ids = $field->get_value();

		foreach ( array_slice( (array) $ids, 0, max( 0, (int) apply_filters( 'hpalm_max_ai_images', 10 ) ) ) as $id ) {
			$url = wp_get_attachment_image_url( (int) $id, 'large' );

			if ( ! is_string( $url ) || ! $url ) {
				$url = wp_get_attachment_url( (int) $id );
			}

			if ( is_string( $url ) && preg_match( '#^https?://#i', $url ) ) {
				$urls[] = $url;
			}
		}
	}

	return array_values( array_unique( $urls ) );
}

/*
 * -------------------------------------------------------------------------
 * Submission gates (verified-vendor bypass and velocity limit).
 * -------------------------------------------------------------------------
 */

/**
 * Deletes a listing's stored moderation score, signals and threshold.
 *
 * The guard read is effectively free: the first get_post_meta() call for a
 * post primes the full meta cache, so clean submissions skip three delete
 * queries at the cost of one cached read.
 *
 * @param int $listing_id Listing ID.
 */
function hpalm_delete_audit_trail( $listing_id ) {
	if ( '' === get_post_meta( $listing_id, '_hpalm_score', true ) ) {
		return;
	}

	delete_post_meta( $listing_id, '_hpalm_score' );
	delete_post_meta( $listing_id, '_hpalm_signals' );
	delete_post_meta( $listing_id, '_hpalm_threshold' );
}

/**
 * Checks whether the vendor behind a listing is marked as verified.
 *
 * The listing's vendor relation (post_parent) is used when set. New
 * submissions start as an auto-draft created with only status, drafted and
 * user filled (verified in Controllers\Listing::redirect_listing_submit_page),
 * so post_parent is empty at that point and the vendor is resolved through
 * the listing's user instead, matching core's own fallback in the listings
 * admin column. The verified flag itself is HivePress's native Verified
 * checkbox on the vendor profile (configs/meta-boxes.php).
 *
 * @param object $listing Listing model object.
 * @return bool
 */
function hpalm_is_vendor_verified( $listing ) {
	if ( ! class_exists( '\HivePress\Models\Vendor' ) ) {
		return false;
	}

	$vendor    = null;
	$vendor_id = (int) $listing->get_vendor();

	if ( $vendor_id ) {
		$vendor = \HivePress\Models\Vendor::query()->get_by_id( $vendor_id );
	} else {
		$user_id = (int) $listing->get_user();

		if ( ! $user_id && function_exists( 'get_current_user_id' ) ) {
			$user_id = (int) get_current_user_id();
		}

		if ( $user_id ) {

			// Filtering by user and publish status mirrors core's own vendor
			// lookup (Controllers\User, verified), so a leftover auto-draft
			// vendor row can never shadow the real profile.
			$vendor = \HivePress\Models\Vendor::query()->filter(
				[
					'user'   => $user_id,
					'status' => 'publish',
				]
			)->get_first();
		}
	}

	return is_object( $vendor ) && (bool) $vendor->get_verified();
}

/**
 * Checks the 24-hour submission limit for a listing's vendor.
 *
 * Applies only to new submissions (status auto-draft); editing an existing
 * listing never counts. The count covers publish and pending listings, so
 * held spam attempts still use up the limit, while manual drafts do not.
 *
 * The date comparison deliberately uses the local post_date column via
 * wp_date(): WordPress zeroes post_date_gmt to 0000-00-00 00:00:00 for the
 * draft, pending and auto-draft statuses (date_floating in
 * wp-includes/post.php, verified against WordPress 7.0), so a query on the
 * GMT column would silently miss every pending listing.
 *
 * @param object $listing Listing model object.
 * @param int    $listing_id Listing ID (excluded from the count).
 * @return string|null An error message when the limit is reached.
 */
function hpalm_check_velocity( $listing, $listing_id ) {
	$limit = hpalm_absint( get_option( 'hp_listing_velocity_limit' ) );

	if ( $limit < 1 || 'auto-draft' !== $listing->get_status() ) {
		return null;
	}

	$user_id = (int) $listing->get_user();

	if ( ! $user_id && function_exists( 'get_current_user_id' ) ) {
		$user_id = (int) get_current_user_id();
	}

	if ( ! $user_id ) {
		return null;
	}

	$after = wp_date( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );

	if ( ! is_string( $after ) ) {
		return null;
	}

	$recent = get_posts(
		[
			'post_type'    => 'hp_listing',
			'post_status'  => [ 'publish', 'pending' ],
			'author'       => $user_id,
			'fields'       => 'ids',
			'numberposts'  => $limit, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_numberposts -- bounded by the admin's own limit setting; used as an existence probe.
			'post__not_in' => [ $listing_id ],
			'date_query'   => [
				[
					'after'     => $after,
					'inclusive' => true,
				],
			],
		]
	);

	if ( is_array( $recent ) && count( $recent ) >= $limit ) {
		return esc_html__( 'You have reached the maximum number of listings that can be submitted within 24 hours. Please try again later.', 'automated-listing-moderation-for-hivepress' );
	}

	return null;
}

/*
 * -------------------------------------------------------------------------
 * Pending-hold plumbing (risk scoring outcome).
 * -------------------------------------------------------------------------
 */

/**
 * Request-scoped override used to briefly force the native moderation
 * option on, so HivePress's own approval email fires when an admin
 * approves a listing that this plugin held for review.
 *
 * @param bool|null $set Pass true to enable the override.
 * @return bool
 */
function hpalm_moderation_override( $set = null ) {
	static $override = false;

	if ( null !== $set ) {
		$override = (bool) $set;
	}

	return $override;
}

/**
 * Filters the native moderation option. Verified: the submit-complete
 * redirect reads exactly this option to choose pending vs publish, and
 * core's approval-email handler re-reads it on status change.
 *
 * @param mixed $value Option value.
 * @return mixed
 */
function hpalm_filter_moderation_option( $value ) {

	// Reentrancy guard. This filter reads post meta, and a plugin that reads
	// this option while meta is being fetched would otherwise recurse. The
	// guard makes that impossible rather than merely unlikely.
	static $checking = false;

	if ( $value || $checking ) {
		return $value;
	}

	if ( hpalm_moderation_override() ) {
		return true;
	}

	$flagged = false;

	// During the frontend submit flow, the listing being finalised is in the
	// request context, set by the redirect callback of the listing_submit_page
	// route. That route is item _order 0 of the chained Listing_Submit menu, so
	// the router always runs it before the complete page's own redirect reads
	// this option (verified in Components\Router and Menus\Listing_Submit).
	if ( function_exists( 'hivepress' ) ) {
		$checking = true;

		// Core::__get is a plain component lookup that returns null before
		// components boot (verified), so the request component must be checked
		// before use in case this option is read very early.
		$request = hivepress()->request;

		if ( is_object( $request ) && method_exists( $request, 'get_context' ) ) {
			$listing = $request->get_context( 'listing' );

			if ( is_object( $listing ) && method_exists( $listing, 'get_id' ) && $listing->get_id() ) {
				$flagged = (bool) get_post_meta( $listing->get_id(), '_hpalm_flagged', true );
			}
		}

		$checking = false;
	}

	return $flagged ? true : $value;
}
add_filter( 'option_hp_listing_enable_moderation', 'hpalm_filter_moderation_option' );

/*
 * WordPress applies the `option_{name}` filter only when the option row
 * exists in the database. When the row is missing it applies
 * `default_option_{name}` instead and returns immediately, so both filters
 * must be hooked for the hold to be reliable. HivePress adds its settings
 * options via Admin::init_settings on the `hivepress/v1/activate` action,
 * which fires on activation or when the extension count changes but NOT on
 * a plain version update (verified in Core::install), so the presence of
 * the row cannot be assumed on every site.
 */
add_filter( 'default_option_hp_listing_enable_moderation', 'hpalm_filter_moderation_option' );

/**
 * When a listing this plugin flagged leaves pending (admin approved or
 * rejected it), enable the override BEFORE core's handler runs (core
 * hooks this action at priority 10 and re-checks the moderation option;
 * verified in Components\Listing), so the native approval email still
 * reaches the vendor. Then clear the marker.
 *
 * Signature verified: ($listing_id, $new_status, $old_status, $listing).
 *
 * @param int    $listing_id Listing ID.
 * @param string $new_status New status.
 * @param string $old_status Old status.
 * @param object $listing    Listing object.
 */
function hpalm_handle_status_change( $listing_id, $new_status, $old_status, $listing ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- signature fixed by the HivePress hook.
	if ( 'pending' === $old_status && in_array( $new_status, [ 'publish', 'trash' ], true ) && get_post_meta( $listing_id, '_hpalm_flagged', true ) ) {
		hpalm_moderation_override( true );

		// The override covers both branches because core's moderation-gated
		// handler (Components\Listing::update_status, verified) sends the
		// native approval email on publish AND the rejection email on trash.
		// The marker clears on approval only: a rejected listing keeps it, so
		// the Moderation column still explains a trashed row, and a restored
		// listing returns to the pending queue with its context intact.
		if ( 'publish' === $new_status ) {
			delete_post_meta( $listing_id, '_hpalm_flagged' );
		}
	}
}
add_action( 'hivepress/v1/models/listing/update_status', 'hpalm_handle_status_change', 9, 4 );

/**
 * Resets the moderation override once core's own status-change handler has
 * run (core registers it at priority 10, verified in Components\Listing), so
 * the override is scoped to a single status transition. Without this, an
 * admin bulk-approving several listings at once could trigger core's
 * approval email for listings this plugin never flagged.
 */
function hpalm_reset_moderation_override() {
	hpalm_moderation_override( false );
}
add_action( 'hivepress/v1/models/listing/update_status', 'hpalm_reset_moderation_override', 11 );

/**
 * Holds a listing for admin review.
 *
 * For a published listing being edited, the status is set to pending on
 * the model directly, the same technique core uses for moderated
 * attributes: the controller fill()s form values (status is not a form
 * value) and then save()s, persisting the pending status. For everything
 * else (auto-drafts mid-submission, drafts), only the marker is set; the
 * moderation-option filter above turns it into a pending status through
 * the native submit-complete flow, complete with the native admin
 * notification email.
 *
 * @param object $listing Listing model from the form.
 */
function hpalm_hold_listing( $listing ) {
	update_post_meta( $listing->get_id(), '_hpalm_flagged', 1 );

	if ( 'publish' === $listing->get_status() ) {
		$listing->set_status( 'pending' );
	}
}

/*
 * -------------------------------------------------------------------------
 * Main validation.
 * -------------------------------------------------------------------------
 */

/**
 * Validates the listing submit/update forms against all configured
 * checks. Hooked to hivepress/v1/forms/listing_update/errors, which (via
 * the parent-class loop in Form::validate()) also fires for
 * Listing_Submit.
 *
 * Phase 1 (BLOCK): keywords, patterns, evasion variants and any detector
 * set to Block refuse the form with a specific error. Nothing is saved.
 *
 * Phase 2 (SCORE): if a risk threshold is set and no block occurred,
 * score-mode signals accumulate (each type once per submission). At or
 * above the threshold, the listing is accepted but held as pending.
 *
 * @param array  $errors Form errors.
 * @param object $form   Form object.
 * @return array
 */
function hpalm_validate_listing_form( $errors, $form ) {
	if ( ! empty( $errors ) || ! is_object( $form ) || ! method_exists( $form, 'get_fields' ) ) {
		return $errors;
	}

	// The listing model behind the form (Model_Form::get_model, verified).
	// Resolved before anything else because the gates below need it.
	$listing    = method_exists( $form, 'get_model' ) ? $form->get_model() : null;
	$listing_id = ( is_object( $listing ) && method_exists( $listing, 'get_id' ) && $listing->get_id() ) ? (int) $listing->get_id() : 0;

	// Gate 1: verified vendors skip every check. Any stale hold marker is
	// cleared too, so a listing held before its vendor was verified cannot
	// stay stuck in the pending flow.
	if ( $listing_id && get_option( 'hp_listing_bypass_verified_vendors' ) && hpalm_is_vendor_verified( $listing ) ) {
		delete_post_meta( $listing_id, '_hpalm_flagged' );
		hpalm_delete_audit_trail( $listing_id );

		return $errors;
	}

	// Gate 2: the 24-hour submission limit. Nothing else is worth checking
	// or reporting when the submission itself is over the limit.
	if ( $listing_id ) {
		$velocity_error = hpalm_check_velocity( $listing, $listing_id );

		if ( null !== $velocity_error ) {
			return [ $velocity_error ];
		}
	}

	// Configuration.
	$keywords  = hpalm_get_lines( 'hp_listing_blocked_keywords', true );
	$patterns  = hpalm_get_lines( 'hp_listing_blocked_patterns' );
	$evasion   = (bool) get_option( 'hp_listing_block_evasion' );
	$threshold = hpalm_absint( get_option( 'hp_listing_risk_threshold' ) );

	$modes = [
		'phone'     => hpalm_get_mode( 'hp_listing_block_phones' ),
		'email'     => hpalm_get_mode( 'hp_listing_block_emails' ),
		'url'       => hpalm_get_mode( 'hp_listing_block_urls' ),
		'dup_title' => hpalm_get_mode( 'hp_listing_check_duplicate_titles' ),
		'dup_desc'  => hpalm_get_mode( 'hp_listing_check_duplicate_descriptions' ),
		'ai'        => hpalm_get_mode( 'hp_listing_block_ai' ),
		'ai_image'  => hpalm_get_mode( 'hp_listing_block_ai_images' ),
	];

	$scoring_on = $threshold >= 1;
	$caps_on    = $scoring_on && get_option( 'hp_listing_score_caps' );

	if ( ! $keywords && ! $patterns && ! array_filter( $modes ) && ! $caps_on ) {
		return $errors;
	}

	// Gather the scannable free-text fields (Text covers Textarea, Email
	// and URL fields: title, description and text-based attributes).
	$entries     = [];
	$title       = null;
	$description = null;

	foreach ( $form->get_fields() as $field ) {
		if ( $field->is_disabled() || ! is_a( $field, '\HivePress\Fields\Text' ) ) {
			continue;
		}

		$value = $field->get_value();

		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			continue;
		}

		$label = $field->get_label( esc_html__( 'a listing field', 'automated-listing-moderation-for-hivepress' ) );

		$entries[] = [
			'label' => $label,
			'value' => $value,
		];

		if ( 'title' === $field->get_name() ) {
			$title = $value;
		} elseif ( 'description' === $field->get_name() ) {
			$description = $value;
		}
	}

	if ( ! $entries ) {
		return $errors;
	}

	$detector_messages = [
		/* translators: 1: the detected text, 2: the field label (e.g. Description). */
		'phone' => esc_html__( 'Phone numbers are not allowed in listings ("%1$s" found in %2$s). Please remove it to continue.', 'automated-listing-moderation-for-hivepress' ),
		/* translators: 1: the detected text, 2: the field label (e.g. Description). */
		'email' => esc_html__( 'Email addresses are not allowed in listings ("%1$s" found in %2$s). Please remove it to continue.', 'automated-listing-moderation-for-hivepress' ),
		/* translators: 1: the detected text, 2: the field label (e.g. Description). */
		'url'   => esc_html__( 'Website addresses are not allowed in listings ("%1$s" found in %2$s). Please remove it to continue.', 'automated-listing-moderation-for-hivepress' ),
	];

	// ---- Phase 1: blocking checks. ----

	$signals = [];

	foreach ( $entries as $entry ) {
		$found = hpalm_find_blocked_text_deep( $entry['value'], $keywords, $patterns, $evasion );

		if ( null !== $found ) {
			$errors[] = sprintf(
				/* translators: 1: the blocked word or phrase, 2: the field label (e.g. Description). */
				esc_html__( '"%1$s" is a blocked word or phrase (found in %2$s). To continue with submitting your listing, please remove or update it.', 'automated-listing-moderation-for-hivepress' ),
				esc_html( $found ),
				esc_html( $entry['label'] )
			);

			continue;
		}

		$email_found = false;

		foreach ( [ 'email', 'url', 'phone' ] as $type ) {
			if ( '' === $modes[ $type ] ) {
				continue;
			}

			// An email address always contains a domain, so when an email
			// has already matched in this field, the URL check runs on a
			// copy of the text with email addresses removed. The same
			// address is never reported or scored twice, but a separate
			// URL in the same field still counts.
			$haystack = $entry['value'];

			if ( 'url' === $type && $email_found ) {
				$stripped = preg_replace( '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', ' ', $haystack );

				// preg_replace returns null if PCRE hits a limit on a very
				// long field. Keep the original text in that case rather than
				// passing null on: the worst outcome is that an email domain
				// also counts as a URL, never a crash or a skipped check.
				if ( is_string( $stripped ) ) {
					$haystack = $stripped;
				}
			}

			$found = hpalm_find_contact_detail( $haystack, $type );

			if ( null === $found ) {
				continue;
			}

			if ( 'email' === $type ) {
				$email_found = true;
			}

			if ( 'block' === $modes[ $type ] ) {
				$errors[] = sprintf( $detector_messages[ $type ], esc_html( $found ), esc_html( $entry['label'] ) );
			} else {
				$signals[ $type ] = true;
			}
		}

		if ( $caps_on && hpalm_has_excessive_caps( $entry['value'] ) ) {
			$signals['caps'] = true;
		}
	}

	// Duplicates (once per submission, against submitted values).
	$dup_checks = [
		'dup_title' => [ $title, '_hpalm_title_hash', esc_html__( 'A listing with this exact title already exists. Please choose a different title.', 'automated-listing-moderation-for-hivepress' ) ],
		'dup_desc'  => [ $description, '_hpalm_desc_hash', esc_html__( 'A listing with this exact description already exists. Please write a unique description.', 'automated-listing-moderation-for-hivepress' ) ],
	];

	foreach ( $dup_checks as $type => $check ) {
		if ( '' === $modes[ $type ] || null === $check[0] ) {
			continue;
		}

		if ( hpalm_is_duplicate( hpalm_content_hash( $check[0] ), $check[1], $listing_id ) ) {
			if ( 'block' === $modes[ $type ] ) {
				$errors[] = $check[2];
			} else {
				$signals[ $type ] = true;
			}
		}
	}

	// AI review runs last and only when nothing has blocked yet, so a
	// remote call is never spent on an already-refused submission.
	if ( '' !== $modes['ai'] && ! $errors ) {
		$texts = wp_list_pluck( $entries, 'value' );

		if ( true === hpalm_ai_flagged( implode( "\n\n", $texts ) ) ) {
			if ( 'block' === $modes['ai'] ) {
				$errors[] = esc_html__( 'Your listing appears to contain content that violates our content guidelines. Please revise it and try again.', 'automated-listing-moderation-for-hivepress' );
			} else {
				$signals['ai'] = true;
			}
		}
	}

	// AI photo review mirrors the text review: last, and never spent on an
	// already-refused submission.
	if ( '' !== $modes['ai_image'] && ! $errors ) {
		$image_urls = hpalm_collect_image_urls( $form );

		if ( $image_urls && true === hpalm_ai_flags_images( $image_urls ) ) {
			if ( 'block' === $modes['ai_image'] ) {
				$errors[] = esc_html__( 'One or more of your listing photos appears to contain inappropriate content. Please replace it and try again.', 'automated-listing-moderation-for-hivepress' );
			} else {
				$signals['ai_image'] = true;
			}
		}
	}

	if ( $errors ) {

		// array_values keeps the REST response a JSON array: array_unique
		// preserves keys, and a gap would make json_encode emit an object.
		return array_values( array_unique( $errors ) );
	}

	// ---- Phase 2: risk scoring. ----

	if ( $listing_id ) {
		$score         = 0;
		$signal_points = [];

		if ( $scoring_on ) {
			$weights = hpalm_get_risk_weights();

			foreach ( array_keys( $signals ) as $signal ) {
				$points = isset( $weights[ $signal ] ) ? (int) $weights[ $signal ] : 0;

				if ( $points > 0 ) {
					$signal_points[ $signal ] = $points;
				}

				$score += $points;
			}
		}

		// Store the outcome as an audit trail for the admin screens: the
		// score, the per-signal breakdown, and the threshold in force at
		// the time. A clean submission removes the record, and so does a
		// submission made while scoring is disabled, so the admin screens
		// always describe the listing's current content.
		if ( $scoring_on && $score > 0 ) {
			update_post_meta( $listing_id, '_hpalm_score', $score );
			update_post_meta( $listing_id, '_hpalm_signals', $signal_points );
			update_post_meta( $listing_id, '_hpalm_threshold', $threshold );
		} else {
			hpalm_delete_audit_trail( $listing_id );
		}

		if ( $scoring_on && $score >= $threshold ) {
			hpalm_hold_listing( $listing );
		} elseif ( get_post_meta( $listing_id, '_hpalm_flagged', true ) ) {

			// Clear a marker left by an earlier attempt or by a previous
			// configuration, so a listing is never held for a reason that no
			// longer applies. Post meta is cached, so this read is cheap and
			// avoids a needless delete query on every clean submission.
			delete_post_meta( $listing_id, '_hpalm_flagged' );
		}
	}

	return $errors;
}
add_filter( 'hivepress/v1/forms/listing_update/errors', 'hpalm_validate_listing_form', 10, 2 );

/*
 * -------------------------------------------------------------------------
 * Admin visibility: moderation column and listing meta box.
 * -------------------------------------------------------------------------
 */

/**
 * Returns the human-readable labels for the risk signals.
 *
 * @return array
 */
function hpalm_get_signal_labels() {
	return [
		'phone'     => esc_html__( 'Phone number detected', 'automated-listing-moderation-for-hivepress' ),
		'email'     => esc_html__( 'Email address detected', 'automated-listing-moderation-for-hivepress' ),
		'url'       => esc_html__( 'Website address detected', 'automated-listing-moderation-for-hivepress' ),
		'caps'      => esc_html__( 'Excessive capital letters', 'automated-listing-moderation-for-hivepress' ),
		'dup_title' => esc_html__( 'Duplicate title', 'automated-listing-moderation-for-hivepress' ),
		'dup_desc'  => esc_html__( 'Duplicate description', 'automated-listing-moderation-for-hivepress' ),
		'ai'        => esc_html__( 'AI flagged the listing text', 'automated-listing-moderation-for-hivepress' ),
		'ai_image'  => esc_html__( 'AI flagged a listing photo', 'automated-listing-moderation-for-hivepress' ),
	];
}

/**
 * Adds the Moderation column to the listings screen.
 *
 * Registered at priority 11: HivePress core adds its Vendor column at the
 * default priority by rebuilding the array with array_merge (verified in
 * Components\Listing::add_listing_admin_columns), so running after it keeps
 * both columns. The column is inserted before Date when present.
 *
 * @param array $columns Column labels keyed by column ID.
 * @return array
 */
function hpalm_add_admin_columns( $columns ) {
	if ( ! is_array( $columns ) ) {
		return $columns;
	}

	$label   = esc_html__( 'Moderation', 'automated-listing-moderation-for-hivepress' );
	$updated = [];

	foreach ( $columns as $key => $value ) {
		if ( 'date' === $key ) {
			$updated['hpalm_score'] = $label;
		}

		$updated[ $key ] = $value;
	}

	if ( ! isset( $updated['hpalm_score'] ) ) {
		$updated['hpalm_score'] = $label;
	}

	return $updated;
}

/**
 * Renders the Moderation column value.
 *
 * @param string $column Column ID.
 * @param int    $listing_id Listing ID.
 */
function hpalm_render_admin_columns( $column, $listing_id ) {
	if ( 'hpalm_score' !== $column ) {
		return;
	}

	$score = get_post_meta( $listing_id, '_hpalm_score', true );

	if ( '' === $score || false === $score ) {
		echo '&mdash;';

		return;
	}

	echo esc_html( number_format_i18n( hpalm_absint( $score ) ) );

	if ( get_post_meta( $listing_id, '_hpalm_flagged', true ) ) {
		echo ' <span style="color:#b32d2e;font-weight:600;">' . esc_html__( '(held)', 'automated-listing-moderation-for-hivepress' ) . '</span>';
	}
}

/**
 * Makes the Moderation column sortable.
 *
 * @param array $columns Sortable columns.
 * @return array
 */
function hpalm_sortable_columns( $columns ) {
	$columns['hpalm_score'] = 'hpalm_score';

	return $columns;
}

/**
 * Sorts the listings screen by moderation score when requested.
 *
 * Sorting by a meta value normally drops every post without that meta key,
 * so the query pairs NOT EXISTS with EXISTS, the standard pattern for
 * keeping unscored listings in the list.
 *
 * @param object $query WP_Query instance.
 */
function hpalm_sort_by_score( $query ) {
	if ( ! is_admin() || ! $query->is_main_query() ) {
		return;
	}

	if ( 'hp_listing' !== $query->get( 'post_type' ) || 'hpalm_score' !== $query->get( 'orderby' ) ) {
		return;
	}

	$query->set(
		'meta_query',
		[
			'relation' => 'OR',
			[
				'key'     => '_hpalm_score',
				'compare' => 'NOT EXISTS',
			],
			[
				'key'     => '_hpalm_score',
				'compare' => 'EXISTS',
			],
		]
	);

	$query->set( 'orderby', 'meta_value_num' );
}

/**
 * Registers the Moderation meta box on the listing edit screen.
 */
function hpalm_register_meta_box() {
	add_meta_box(
		'hpalm_moderation',
		esc_html__( 'Moderation', 'automated-listing-moderation-for-hivepress' ),
		'hpalm_render_meta_box',
		'hp_listing',
		'side'
	);
}

/**
 * Renders the Moderation meta box: the score, the threshold in force when
 * it was recorded, the held state, and the per-signal breakdown. Display
 * only; nothing here is saved.
 *
 * @param object $post Post object.
 */
function hpalm_render_meta_box( $post ) {
	$listing_id = is_object( $post ) && isset( $post->ID ) ? (int) $post->ID : 0;
	$score      = $listing_id ? get_post_meta( $listing_id, '_hpalm_score', true ) : '';

	if ( '' === $score || false === $score ) {
		echo '<p>' . esc_html__( 'No risk signals were recorded for the current content of this listing.', 'automated-listing-moderation-for-hivepress' ) . '</p>';

		return;
	}

	$threshold = hpalm_absint( get_post_meta( $listing_id, '_hpalm_threshold', true ) );
	$signals   = get_post_meta( $listing_id, '_hpalm_signals', true );
	$labels    = hpalm_get_signal_labels();

	echo '<p><strong>' . esc_html(
		sprintf(
			/* translators: 1: the risk score, 2: the risk threshold. */
			__( 'Risk score: %1$s of %2$s', 'automated-listing-moderation-for-hivepress' ),
			number_format_i18n( hpalm_absint( $score ) ),
			number_format_i18n( $threshold )
		)
	) . '</strong></p>';

	if ( get_post_meta( $listing_id, '_hpalm_flagged', true ) ) {
		echo '<p style="color:#b32d2e;">' . esc_html__( 'This listing is currently held for review.', 'automated-listing-moderation-for-hivepress' ) . '</p>';
	}

	if ( is_array( $signals ) && $signals ) {
		echo '<ul style="margin:0;">';

		foreach ( $signals as $signal => $points ) {
			$label = isset( $labels[ $signal ] ) ? $labels[ $signal ] : $signal;

			echo '<li>' . esc_html( $label ) . ' (' . esc_html( number_format_i18n( hpalm_absint( $points ) ) ) . ')</li>';
		}

		echo '</ul>';
	}
}

if ( is_admin() ) {
	add_filter( 'manage_hp_listing_posts_columns', 'hpalm_add_admin_columns', 11 );
	add_action( 'manage_hp_listing_posts_custom_column', 'hpalm_render_admin_columns', 10, 2 );
	add_filter( 'manage_edit-hp_listing_sortable_columns', 'hpalm_sortable_columns' );
	add_action( 'pre_get_posts', 'hpalm_sort_by_score' );
	add_action( 'add_meta_boxes', 'hpalm_register_meta_box' );
}

/*
 * -------------------------------------------------------------------------
 * Starter blocklist import.
 * -------------------------------------------------------------------------
 */

/**
 * Returns the starter blocklist for the one-click import.
 *
 * Split into two groups on purpose. Substring-safe words go into Blocked
 * Keywords. Words that would false-positive as substrings go into Blocked
 * Patterns with \b word boundaries: the classic example being the c-word
 * inside the town name "Scunthorpe", or "wank" inside "swanky", both of
 * which a UK marketplace can realistically expect in legitimate listings.
 *
 * @return array Two keys: 'keywords' and 'patterns'.
 */
function hpalm_get_starter_list() {
	return [
		'keywords' => [
			'fuck',
			'shit',
			'twat',
			'tosser',
			'bollocks',
			'bellend',
			'knobhead',
			'dickhead',
			'arsehole',
			'asshole',
			'bastard',
			'bitch',
			'slut',
			'whore',
			'cocksucker',
			'blowjob',
			'handjob',
			'jizz',
			'porn',
		],
		'patterns' => [
			'\bcunt\w*',
			'\bwank\w*',
			'\bprick\b',
			'\btits\b',
		],
	];
}

/**
 * Adds the "Import starter blocklist" button below the Blocked Patterns
 * field on the HivePress settings screen (slug hp_settings, verified).
 *
 * Client-side only: the button merges the starter list into the two
 * textareas (deduplicated) and the admin still reviews and clicks Save
 * Changes, so no AJAX endpoint or nonce surface exists beyond the
 * manage_options requirement of the page itself.
 */
function hpalm_render_import_button() {
	// $_GET values can be arrays (?page[]=x), and sanitize_key() would pass
	// one into strtolower(), a fatal TypeError on PHP 8. Narrow to string
	// first; a non-string page can never be the settings screen anyway.
	$raw  = isset( $_GET['page'] ) ? wp_unslash( $_GET['page'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$page = is_string( $raw ) ? sanitize_key( $raw ) : '';

	if ( 'hp_settings' !== $page || ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$list = hpalm_get_starter_list();
	?>
	<script>
	( function() {
		var kw = document.querySelector( 'textarea[name="hp_listing_blocked_keywords"]' );
		var pt = document.querySelector( 'textarea[name="hp_listing_blocked_patterns"]' );

		if ( ! kw || ! pt ) {
			return;
		}

		var words    = <?php echo wp_json_encode( $list['keywords'] ); ?>;
		var patterns = <?php echo wp_json_encode( $list['patterns'] ); ?>;

		function mergeInto( textarea, items, splitCommas ) {
			var existing = textarea.value.split( splitCommas ? /[\n,]+/ : /\n/ )
				.map( function( s ) { return s.trim(); } )
				.filter( function( s ) { return s.length; } );

			items.forEach( function( item ) {
				if ( existing.indexOf( item ) === -1 ) {
					existing.push( item );
				}
			} );

			textarea.value = existing.join( '\n' );
		}

		var wrap = document.createElement( 'p' );
		var btn  = document.createElement( 'button' );

		btn.type      = 'button';
		btn.className = 'button';
		btn.textContent = <?php echo wp_json_encode( esc_html__( 'Import starter blocklist', 'automated-listing-moderation-for-hivepress' ) ); ?>;
		btn.title       = <?php echo wp_json_encode( esc_html__( 'Adds common profanity to Blocked Keywords, plus whole-word patterns for terms that appear inside innocent words. Duplicates are skipped. Review the lists, then click Save Changes.', 'automated-listing-moderation-for-hivepress' ) ); ?>;

		var note = document.createElement( 'span' );
		note.style.cssText  = 'margin-left:8px;color:#666;';
		note.textContent    = <?php echo wp_json_encode( esc_html__( 'Fills both fields above. Remember to save.', 'automated-listing-moderation-for-hivepress' ) ); ?>;

		btn.addEventListener( 'click', function() {
			mergeInto( kw, words, true );
			mergeInto( pt, patterns, false );
			note.textContent = <?php echo wp_json_encode( esc_html__( 'Starter list added. Review the fields, then click Save Changes.', 'automated-listing-moderation-for-hivepress' ) ); ?>;
		} );

		wrap.appendChild( btn );
		wrap.appendChild( note );
		pt.parentNode.appendChild( wrap );
	} )();
	</script>
	<?php
}
add_action( 'admin_footer', 'hpalm_render_import_button' );

/**
 * Loads the plugin translations.
 *
 * HivePress loads text domains only for registered extensions
 * (Core::load_textdomains), and this plugin deliberately hooks core filters
 * rather than registering itself as an extension, so it loads its own here.
 * Hooked to init rather than earlier, as WordPress requires translations to
 * be loaded no earlier than that.
 */
function hpalm_load_textdomain() {
	load_plugin_textdomain( 'automated-listing-moderation-for-hivepress', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}
add_action( 'init', 'hpalm_load_textdomain' );

/**
 * Shows an admin notice if HivePress is not active.
 */
function hpalm_admin_notice() {
	if ( ! class_exists( '\HivePress\Core' ) && current_user_can( 'activate_plugins' ) ) {
		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html__( 'Automated Listing Moderation for HivePress requires the HivePress plugin to be installed and activated.', 'automated-listing-moderation-for-hivepress' )
		);
	}
}
add_action( 'admin_notices', 'hpalm_admin_notice' );
