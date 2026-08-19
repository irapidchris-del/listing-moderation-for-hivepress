<?php
/**
 * Plugin Name: Automated Listing Moderation for HivePress
 * Plugin URI:  https://github.com/irapidchris-del/listing-moderation-for-hivepress
 * Description: Blocks or risk-scores listing submissions containing blocked words, phrases, regex patterns, phone numbers, email addresses, website URLs, duplicate content or AI-flagged text and photos, with a per-vendor submission limit, a verified-vendor bypass, and a Moderation score column and meta box in the dashboard. Configure under HivePress → Settings → Listings → Automated Moderation.
 * Version:     1.6.6
 * Author:      ChrisB @ HivePress Community
 * Author URI:  https://community.hivepress.io/u/chrisb/summary
 * License:     GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: listing-moderation-for-hivepress
 * Domain Path: /languages/
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Requires Plugins: hivepress
 * Update URI:  https://github.com/irapidchris-del/listing-moderation-for-hivepress
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
 *    phone 25, email 25, url 15, excessive caps 15, AI photo flag 50, duplicate title 40,
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
 *    Moderation endpoint (model omni-moderation-latest) and acts on
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
 *    verified in WP 7.0 wp-includes/post.php). AI photo review sends each
 *    listing photo to the same OpenAI moderation endpoint in its own
 *    request, because that endpoint accepts exactly one image per call.
 *    Risk-scored outcomes are stored per listing as
 *    _hpalm_score, _hpalm_signals and _hpalm_threshold and surfaced in a
 *    sortable Moderation column and a listing meta box; a clean
 *    resubmission removes the record so the admin screens always describe
 *    the listing's current content.
 *
 * 9. VALIDATION HOOK CHOICE. Blocking uses the FORM errors filter, not
 *    hivepress/v1/models/listing/errors, deliberately. The model filter
 *    fires inside Model::validate() on every save path, which would also
 *    refuse an administrator's own save in wp-admin (leaving a flagged
 *    listing unfixable) and would refuse the set_status('pending') save
 *    this plugin performs to hold a listing, defeating the hold itself.
 *    The form filter also exposes field labels, which is what makes the
 *    inline errors name the offending field.
 *
 * KNOWN LIMITATIONS (by design, documented honestly):
 * - Admin-side edits in wp-admin bypass frontend forms and are not
 *   checked. Frontend edits by logged-in admins ARE checked. See note 9
 *   above for why this is deliberate rather than an oversight.
 * - While a listing is held as pending, its vendor cannot add photos to
 *   it: core's attachment upload endpoint returns 403 for a parent post
 *   whose status is not auto-draft, draft or publish
 *   (Controllers\Attachment, verified). Text edits still work, and the
 *   vendor can replace photos once the listing is approved.
 * - Rejecting a held listing by trashing it also trashes the vendor's
 *   profile if that profile is still a draft, because this plugin routes
 *   approvals and rejections through the native moderation flow and core
 *   does that inside the same handler (Components\Listing::update_status,
 *   verified). This matches what a site with native moderation enabled
 *   already does.
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
	// A check set to "Add to risk score" does nothing whatsoever until a Risk
	// Threshold is entered: hpalm_validate_listing_form blanks the mode when
	// the threshold is under 1, deliberately, so a scoring check never spends
	// a database query or an OpenAI call on a result that cannot be used. The
	// option has to admit that rather than sit there looking switched on.
	// Fields\Select validates against the option KEYS, so a stored 'score'
	// survives this untouched, and settings configs are rebuilt per request
	// so the label corrects itself the moment a threshold is saved.
	$scoring = hpalm_absint( get_option( 'hp_alm_risk_threshold' ) ) >= 1;

	return [
		'block' => esc_html__( 'Block submission', 'listing-moderation-for-hivepress' ),
		'score' => $scoring
			? esc_html__( 'Add to risk score', 'listing-moderation-for-hivepress' )
			: esc_html__( 'Add to risk score (set a Risk Threshold below first)', 'listing-moderation-for-hivepress' ),
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

		$settings['listings']['sections']['alm_moderation'] = [
			'title'       => esc_html__( 'Automated Moderation', 'listing-moderation-for-hivepress' ),
			'description' => esc_html__( 'Checks each listing as it is submitted or edited on your site, and either refuses it or holds it for you to approve. Listings edited in the WordPress dashboard are never checked. Each check below can refuse a listing outright, or add points to a risk score. A listing that is held keeps the status Pending: you will find it under Listings in the WordPress dashboard, where you can publish it or bin it. Checks set to "Add to risk score" only take effect once a Risk Threshold is entered below. Added by the Automated Listing Moderation for HivePress plugin.', 'listing-moderation-for-hivepress' ),
			'_order'      => 25,

			'fields'      => [
				'alm_bypass_verified_vendors'      => [
					'label'       => esc_html__( 'Verified Vendors', 'listing-moderation-for-hivepress' ),
					'caption'     => esc_html__( 'Skip checks for verified vendors', 'listing-moderation-for-hivepress' ),
					'description' => esc_html__( 'Listings from vendors you have marked as verified skip every check on this screen, including the submission limit below. To verify someone, open Vendors in the WordPress dashboard, edit them, and tick Verified.', 'listing-moderation-for-hivepress' ),
					'type'        => 'checkbox',
					'_order'      => 2,
				],

				'alm_velocity_limit'               => [
					'label'       => esc_html__( 'Submission Limit (listings per 24 hours)', 'listing-moderation-for-hivepress' ),
					'description' => esc_html__( 'How many new listings one vendor may add in any 24 hours. Only listings that are live or waiting for review count towards it, so hidden, expired and binned ones do not. Editing an existing listing never counts. Verified vendors are exempt if you have ticked the setting above. Leave empty for no limit.', 'listing-moderation-for-hivepress' ),
					'type'        => 'number',
					'min_value'   => 1,
					'_order'      => 4,
				],

				'alm_blocked_keywords'             => [
					'label'       => esc_html__( 'Blocked Keywords', 'listing-moderation-for-hivepress' ),
					/* translators: %20 and %2f inside this text are literal examples of percent-encoded characters, not placeholders. */
					'description' => __( 'Words or phrases that block a listing from being submitted. Separate with commas or new lines. Matching is case-insensitive and matches <strong>inside</strong> longer words: blocking <code>class</code> also blocks <code>classic</code>. For whole-word matching, use Blocked Patterns with <code>\b</code> boundaries instead. Note: WordPress removes a percent sign when the next two characters are digits or the letters a to f (so <code>%20</code> and <code>%2f</code>) when saving, so such sequences cannot be blocked. The buttons under Blocked Patterns add ready-made starter lists to both boxes for you to review.', 'listing-moderation-for-hivepress' ),
					'placeholder' => __( 'cheap, cash in hand, WhatsApp me', 'listing-moderation-for-hivepress' ),
					'type'        => 'textarea',
					'max_length'  => 10240,
					'_order'      => 10,
				],

				'alm_blocked_patterns'             => [
					'label'       => esc_html__( 'Blocked Patterns', 'listing-moderation-for-hivepress' ),
					/* translators: %2f inside this text is a literal example of a percent-encoded character, not a placeholder. */
					'description' => __( 'Advanced: regular expressions, <strong>one per line only</strong>. Commas are regex syntax, so they do not separate patterns here. No delimiters, case-insensitive. Examples: <code>\bclass\b</code> blocks the whole word only, not "classic". <code>\bcash\s*only\b</code> blocks "cash only" with any spacing. <code>colou?r</code> blocks both spellings. Escape a literal ~ as <code>\~</code>. Invalid patterns are skipped. Note: WordPress strips angle brackets on save, so assertions containing &lt; cannot be used, and it removes a percent sign when the next two characters are digits or the letters a to f (like <code>%2f</code>), so write such patterns without a literal percent sign.', 'listing-moderation-for-hivepress' ),
					/* translators: this is a regular-expression example; keep the backslashes as they are. */
					'placeholder' => __( '\bcash\s*only\b', 'listing-moderation-for-hivepress' ),
					'type'        => 'textarea',
					'max_length'  => 10240,
					'_order'      => 20,
				],

				'alm_block_evasion'                => [
					'label'       => esc_html__( 'Disguised Spellings', 'listing-moderation-for-hivepress' ),
					'caption'     => esc_html__( 'Also catch blocked words disguised with symbols or accents', 'listing-moderation-for-hivepress' ),
					'description' => __( 'Re-checks keywords and patterns against copies of the text with accents removed, invisible characters stripped and common leet substitutions reversed, so blocking <code>fuck</code> also catches <code>fùck</code> and zero-width-character tricks, and blocking <code>shit</code> also catches <code>sh1t</code> and <code>$hit</code>. Substitutions are reversed to their look-alike letter (0 becomes o, 1 becomes i or l, $ becomes s), so a substitution that changes the word (like <code>f0ck</code>) needs its own keyword or pattern. This never blocks a word you have not blocked yourself, but it does mean accented spellings of your blocked words match too: block <code>cafe</code> and <code>café</code> is blocked as well. Worth a thought if your listings are written in a language that uses accents.', 'listing-moderation-for-hivepress' ),
					'type'        => 'checkbox',
					'_order'      => 30,
				],

				'alm_block_phones'                 => [
					'label'       => esc_html__( 'Phone Numbers', 'listing-moderation-for-hivepress' ),
					'description' => __( 'Detects sequences of 9 to 14 digits, optionally separated by spaces, dashes or brackets, with an optional leading +, e.g. <code>07123 456789</code> or <code>+44 7911 123456</code>. Prices, years, dates and IP addresses are not affected. Be aware that <strong>any</strong> other run of 9 to 14 digits also matches, including barcodes, ISBNs and long order or reference numbers: if your listings routinely carry those, choose "Add to risk score" rather than "Block submission", so a genuine listing is held for your review instead of being refused. Sharing phone numbers is often an attempt to move bookings off-platform.', 'listing-moderation-for-hivepress' ),
					'placeholder' => esc_html__( 'Disabled', 'listing-moderation-for-hivepress' ),
					'type'        => 'select',
					'options'     => $modes,
					'_order'      => 40,
				],

				'alm_block_emails'                 => [
					'label'       => esc_html__( 'Email Addresses', 'listing-moderation-for-hivepress' ),
					'description' => __( 'Detects anything shaped like an email address, e.g. <code>name@example.com</code>. Obfuscated forms ("name at gmail dot com") are not detected; add a Blocked Pattern for those if needed.', 'listing-moderation-for-hivepress' ),
					'placeholder' => esc_html__( 'Disabled', 'listing-moderation-for-hivepress' ),
					'type'        => 'select',
					'options'     => $modes,
					'_order'      => 50,
				],

				'alm_block_urls'                   => [
					'label'       => esc_html__( 'Website Addresses', 'listing-moderation-for-hivepress' ),
					'description' => __( 'Detects links starting with http, https or www, plus bare domains ending in a common extension such as <code>example.com</code> or <code>example.co.uk</code>. Note this also applies to your own site address if vendors mention it.', 'listing-moderation-for-hivepress' ),
					'placeholder' => esc_html__( 'Disabled', 'listing-moderation-for-hivepress' ),
					'type'        => 'select',
					'options'     => $modes,
					'_order'      => 60,
				],

				'alm_check_duplicate_titles'       => [
					'label'       => esc_html__( 'Duplicate Titles', 'listing-moderation-for-hivepress' ),
					'description' => __( 'Detects when another live or pending listing already has the same title, ignoring case and extra spaces. It costs one small database query per submission. Listings that already exist are prepared automatically in the background, 200 at a time, so on a large site an older listing may not be recognised as a duplicate for the first few minutes. Note that if your site builds titles automatically from attributes (the Title Format setting on this tab), vendors never type a title, so there is nothing for this check to compare and it stays quiet.', 'listing-moderation-for-hivepress' ),
					'placeholder' => esc_html__( 'Disabled', 'listing-moderation-for-hivepress' ),
					'type'        => 'select',
					'options'     => $modes,
					'_order'      => 70,
				],

				'alm_check_duplicate_descriptions' => [
					'label'       => esc_html__( 'Duplicate Descriptions', 'listing-moderation-for-hivepress' ),
					'description' => __( 'Detects when another live or pending listing already has an identical description, ignoring case, formatting and extra spaces. The match has to be exact: change one word and it is no longer spotted, so this catches copy-and-paste rather than rewriting.', 'listing-moderation-for-hivepress' ),
					'placeholder' => esc_html__( 'Disabled', 'listing-moderation-for-hivepress' ),
					'type'        => 'select',
					'options'     => $modes,
					'_order'      => 80,
				],

				'alm_block_ai'                     => [
					'label'       => esc_html__( 'AI Text Review (OpenAI)', 'listing-moderation-for-hivepress' ),
					'description' => __( 'Sends the words in the listing to OpenAI\'s content checking service, which looks for hate, harassment, violence, self-harm and sexual content. Words only; photos are covered by the setting below. Needs an OpenAI API key on the Integrations tab. The checks cost nothing, but OpenAI refuses every request on an account with no credit or payment method. The listing text leaves your site, so say so in your privacy policy. If OpenAI cannot be reached the submission goes through unchecked rather than failing, and a warning appears on this screen.', 'listing-moderation-for-hivepress' ),
					'placeholder' => esc_html__( 'Disabled', 'listing-moderation-for-hivepress' ),
					'type'        => 'select',
					'options'     => $modes,
					'_order'      => 90,
				],

				'alm_block_ai_images'              => [
					'label'       => esc_html__( 'AI Photo Review (OpenAI)', 'listing-moderation-for-hivepress' ),
					'description' => esc_html__( 'Check listing photos with the OpenAI moderation endpoint, which flags sexual, violent and self-harm imagery. Each photo is checked separately, up to ten per listing, and checking stops as soon as one is flagged, so a listing with several photos takes a little longer to submit. The photos themselves are sent to OpenAI, so the check works even on a site that is not yet public. The exception is a site that keeps its uploads in cloud storage rather than on this server: there the photo\'s web address is sent instead, and OpenAI has to be able to reach it. Photos leave your site either way, so say so in your privacy policy. Videos are never sent or checked. Requires an OpenAI API key on the Integrations tab. The calls cost nothing, but OpenAI refuses every request on an account with no credit or payment method. If the service is unavailable the submission proceeds unchecked, and a warning appears on this screen.', 'listing-moderation-for-hivepress' ),
					'type'        => 'select',
					'options'     => hpalm_get_mode_options(),
					'placeholder' => esc_html__( 'Disabled', 'listing-moderation-for-hivepress' ),
					'_order'      => 95,
				],

				'alm_score_caps'                   => [
					'label'       => esc_html__( 'Excessive Capitals', 'listing-moderation-for-hivepress' ),
					'caption'     => esc_html__( 'Add shouty text to the risk score', 'listing-moderation-for-hivepress' ),
					'description' => __( 'Adds risk points when a field is written mostly in capitals (70% or more of at least 12 letters), e.g. "BUY NOW CHEAP!!!". This is a risk signal only, never an outright block, and only counts when a Risk Threshold is set below.', 'listing-moderation-for-hivepress' ),
					'type'        => 'checkbox',
					'_order'      => 100,
				],

				'alm_risk_threshold'               => [
					'label'       => esc_html__( 'Risk Threshold (points)', 'listing-moderation-for-hivepress' ),
					'description' => __( 'Points are added up for every check you set to "Add to risk score". When a listing\'s total reaches this number, the listing is accepted but held as Pending for you to approve, instead of going live. This is a trigger point, not a score out of ten and not a percentage: a lower number holds more listings, a higher number holds fewer. Points per check: phone number 25, email address 25, website address 15, excessive capitals 15, duplicate title 40, duplicate description 40, AI text flag 50, AI photo flag 50. A good starting point is 20: one contact detail, or one duplicate, is then enough to hold a listing, while a website address on its own, or shouty capitals on their own, is not. Leave empty to switch scoring off entirely. A listing is never deleted automatically; the worst that happens is that it waits for you.', 'listing-moderation-for-hivepress' ),
					'type'        => 'number',
					'min_value'   => 1,
					'_order'      => 110,
				],
			],
		];

		// The removal section sits last on the tab, after every core section
		// (expiration = 30, and extension sections observed up to 100).
		$settings['listings']['sections']['alm_removal'] = [
			'title'       => esc_html__( 'Removing Automated Listing Moderation', 'listing-moderation-for-hivepress' ),
			'description' => esc_html__( 'When you delete this plugin, WordPress always warns that deleting it "will also delete its data". Unless the box below is ticked, that warning does not apply: your settings and moderation records are kept, so you can reinstall later and carry on where you left off. Deactivating never removes anything.', 'listing-moderation-for-hivepress' ),
			'_order'      => 999,

			'fields'      => [
				'alm_delete_data' => [
					'label'       => esc_html__( 'Data Removal', 'listing-moderation-for-hivepress' ),
					'caption'     => esc_html__( 'Delete all data when this plugin is deleted', 'listing-moderation-for-hivepress' ),
					'description' => esc_html__( 'Tick this only if you want deleting the plugin to permanently remove every Automated Moderation setting, every stored risk score and held-for-review marker, and the duplicate-detection fingerprints. This cannot be undone. The shared OpenAI API key is kept either way, because other extensions may use it.', 'listing-moderation-for-hivepress' ),
					'type'        => 'checkbox',
					'_order'      => 10,
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
				'title'       => 'OpenAI',
				'description' => esc_html__( 'Your OpenAI connection, shared by any plugin on this site that uses OpenAI, so one key serves them all. Automated Listing Moderation uses it for the optional AI text and photo checks under Settings > Listings. Those checks cost nothing to run, but OpenAI refuses every request until the account has a payment method or purchased credit on it, so a brand new account will not work until you add one.', 'listing-moderation-for-hivepress' ),
				'_order'      => 40,
				'fields'      => [],
			];
		}

		// The option name is deliberately generic (hp_openai_api_key) so that
		// one OpenAI key can be shared by any integration on the site. The
		// field is guarded like the section, so any other plugin using the
		// same shared-key contract can register it first without duplication
		// in either load order.
		if ( ! isset( $settings['integrations']['sections']['openai']['fields']['openai_api_key'] ) ) {
			$settings['integrations']['sections']['openai']['fields']['openai_api_key'] = [
				'label'       => esc_html__( 'API Key', 'listing-moderation-for-hivepress' ),
				'description' => __( 'Only needed if you switch on AI text review or AI photo review under Settings > Listings; leave it empty otherwise. Create a key in the API keys section of <a href="https://platform.openai.com/api-keys" target="_blank" rel="noopener noreferrer">platform.openai.com</a>, then paste it here. The checks themselves cost nothing, but OpenAI allows no request at all until the account has a payment method or purchased credit, so a brand new account refuses every call. If the connection ever stops working, a warning appears on the Listings tab.', 'listing-moderation-for-hivepress' ),
				'type'        => 'text',
				'max_length'  => 256,
				'_order'      => 10,
			];
		}
	}

	return $settings;
}
add_filter( 'hivepress/v1/settings', 'hpalm_register_settings' );

/**
 * Migrates settings stored under the 1.4.x option names.
 *
 * Versions up to 1.4.2 stored settings as hp_listing_* names, which are not
 * plugin-unique (core's own listing settings share that shape, and another
 * extension could plausibly claim the same name). 1.5.0 renames every field
 * to alm_*, giving hp_alm_* options. This copies each stored old value to
 * its new name once, then removes the old row, so an upgrade keeps every
 * setting. Runs at priority 5 so the backfill (priority 10) and everything
 * later in the request reads migrated values.
 */
function hpalm_migrate_option_names() {
	if ( get_option( 'hpalm_options_migrated' ) ) {
		return;
	}

	$renames = [
		'bypass_verified_vendors',
		'velocity_limit',
		'blocked_keywords',
		'blocked_patterns',
		'block_evasion',
		'block_phones',
		'block_emails',
		'block_urls',
		'check_duplicate_titles',
		'check_duplicate_descriptions',
		'block_ai',
		'block_ai_images',
		'score_caps',
		'risk_threshold',
	];

	foreach ( $renames as $name ) {
		$old = get_option( 'hp_listing_' . $name, null );

		if ( null === $old ) {
			continue;
		}

		// An EMPTY new value counts as not set, and that distinction is the
		// whole safety of this routine. HivePress seeds a row for every
		// registered settings field with add_option( name, '' ) whenever it
		// activates or updates (Admin::init_settings, verified), so on an
		// upgrade the hp_alm_* rows can already exist holding ''. Treating
		// those as "already migrated" would skip the copy and then delete
		// the old row, silently wiping every setting the owner had.
		$new = get_option( 'hp_alm_' . $name, null );

		if ( null === $new || '' === $new ) {
			update_option( 'hp_alm_' . $name, $old );
		}

		delete_option( 'hp_listing_' . $name );
	}

	// Autoloaded deliberately: this flag is read on every request, so it
	// must not cost a query once the migration is done.
	update_option( 'hpalm_options_migrated', 1 );
}

/*
 * Hooked to `init` rather than `admin_init` for two reasons. A background or
 * WP-CLI update reaches the front end first, and until the migration runs the
 * new option names do not exist, so every check would silently read as off on
 * exactly the requests that matter. And priority 5 puts this ahead of
 * HivePress's own settings seeding (Core::install runs on init at 10000), so
 * the values are moved across before anything can create an empty row over
 * the top of them.
 */
add_action( 'init', 'hpalm_migrate_option_names', 5 );

/**
 * The two settings whose value may legitimately contain a backslash.
 *
 * @return array
 */
function hpalm_backslash_options() {
	return [ 'hp_alm_blocked_keywords', 'hp_alm_blocked_patterns' ];
}

/**
 * Keeps backslashes alive through the HivePress settings screen.
 *
 * Every backslash in Blocked Patterns was being destroyed, on both the way in
 * and the way out, because the value passes through wp_unslash() one time too
 * many:
 *
 *   Saving   wp-admin/options.php:343 unslashes the posted value, undoing
 *            WordPress's own magic quotes and leaving the real text. That
 *            value then reaches HivePress's settings validator, whose field
 *            runs wp_unslash() a SECOND time in Fields\Text::normalize()
 *            (hivepress/includes/fields/class-text.php:183). \bcash\b was
 *            therefore stored as bcashb.
 *
 *   Showing  Admin::register_settings passes get_option() straight in as the
 *            field's `default` (class-admin.php:307). That value is already
 *            unslashed, so the same normalize() strips it again and the box
 *            displays bcashb. Pressing Save Changes then wrote the ruined
 *            text back, so merely opening the tab and saving an unrelated
 *            setting was enough to wipe every pattern.
 *
 * Measured live, through a real form post: typing \bcash\s*only\b stored
 * bcashs*onlyb. That silently disarms whole-word matching, which is exactly
 * what the field's own instructions and all four starter lists rely on, and
 * nothing about the listing screens would ever have shown it.
 *
 * The repair is to add one level of slashes at each point where HivePress is
 * about to remove one, so the two cancel out. Both are attached from
 * admin_init priority 11, immediately after register_settings has run, which
 * guarantees they exist only when the HivePress code that consumes them
 * exists. A front-end submission, a WP-CLI write or the rename migration
 * never sees either filter and reads the option exactly as stored.
 *
 * HivePress's own `slash` field argument does not help here: it re-slashes
 * AFTER unslashing, so the backslashes have already gone by then.
 */
function hpalm_protect_backslashes() {
	foreach ( hpalm_backslash_options() as $option ) {

		// Priority 9 puts this ahead of the validator register_setting() adds
		// at the default priority.
		add_filter( 'sanitize_option_' . $option, 'wp_slash', 9 );
	}
}

add_action( 'admin_init', 'hpalm_protect_backslashes', 11 );

/**
 * Pre-slashes the stored value that HivePress reads into the settings field.
 *
 * Scoped to the single moment it is needed. register_settings() runs on
 * admin_init at the default priority and captures get_option() as the field's
 * default, so the filter goes on just before and comes off just after; no
 * other code reads these options inside that window.
 *
 * @param bool $on Whether to add the filter or remove it.
 */
function hpalm_slash_settings_display( $on = true ) {
	foreach ( hpalm_backslash_options() as $option ) {
		if ( $on ) {
			add_filter( 'option_' . $option, 'wp_slash' );
		} else {
			remove_filter( 'option_' . $option, 'wp_slash' );
		}
	}
}

add_action(
	'admin_init',
	function () {
		hpalm_slash_settings_display( true );
	},
	9
);

add_action(
	'admin_init',
	function () {
		hpalm_slash_settings_display( false );
	},
	11
);

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
 * Both get_option() and get_post_meta() return mixed, so this is the
 * plugin's single narrowing point for numeric settings and scores: numeric
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
 * Checks whether a value is a HivePress listing model object.
 *
 * Model field getters are magic (Models\Model::__call), so method_exists()
 * is false for most of them and is_callable() is true for any name at all:
 * neither can tell a real listing model from another object, and a
 * method_exists() guard on a magic getter silently disables whatever it
 * guards. instanceof is the only reliable test, and class_exists() keeps it
 * safe when HivePress is not loaded.
 *
 * @param mixed $listing Value to test.
 * @return bool
 */
function hpalm_is_listing_model( $listing ) {
	return class_exists( '\HivePress\Models\Listing' ) && $listing instanceof \HivePress\Models\Listing;
}

/**
 * Returns the risk weights, filterable for customisation.
 *
 * Example:
 *
 *     add_filter( 'hpalm_risk_weights', function( $w ) {
 *         $w['url'] = 30;
 *         return $w;
 *     } );
 *
 * @return array
 */
function hpalm_get_risk_weights() {

	/**
	 * Filters the risk points added by each moderation signal.
	 *
	 * @hook hpalm_risk_weights
	 * @param {array} $weights Points keyed by signal: phone, email, url, caps, dup_title, dup_desc, ai, ai_image.
	 * @return {array} Filtered weights.
	 */
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
	return '' !== hpalm_get_mode( 'hp_alm_check_duplicate_titles' ) || '' !== hpalm_get_mode( 'hp_alm_check_duplicate_descriptions' );
}

/**
 * Stores/refreshes the fingerprints for one listing.
 *
 * Hooked to the raw WordPress `save_post` action rather than the HivePress
 * model create/update actions, DELIBERATELY. Every HivePress model hook
 * returns early once the `HP_IMPORT` constant is defined, which core does
 * on WordPress's own `import_start` action (Components\Hook::start_import,
 * verified), so a WXR import, a demo-content import or any other importer
 * would create listings that never get a fingerprint. Because the one-time
 * backfill below records completion permanently, those listings would then
 * stay invisible to the duplicate check forever. `save_post` fires on every
 * insert and update regardless of import state.
 *
 * Fingerprints are maintained even while the duplicate checks are switched
 * off, so re-enabling them later never compares against stale data. Writing
 * meta directly via update_post_meta does not re-fire save_post, so there
 * is no recursion, and revisions and autosaves are excluded by the post
 * type check because WordPress stores both as the `revision` type.
 *
 * @param int $listing_id Listing ID.
 */
function hpalm_store_hashes( $listing_id ) {
	$post = get_post( $listing_id );

	if ( ! $post instanceof WP_Post || 'hp_listing' !== $post->post_type ) {
		return;
	}

	update_post_meta( $listing_id, '_hpalm_title_hash', hpalm_content_hash( $post->post_title ) );
	update_post_meta( $listing_id, '_hpalm_desc_hash', hpalm_content_hash( $post->post_content ) );
}
add_action( 'save_post', 'hpalm_store_hashes' );

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
			'post_type'   => 'hp_listing',
			'post_status' => 'any',
			'numberposts' => 200, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_numberposts -- fixed background backfill batch size, bounded by design.
			'meta_query'  => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- NOT EXISTS narrowing is the point: the backfill must find only unhashed listings, and the batch is capped at 200.
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

/**
 * Re-opens the fingerprint backfill on every activation.
 *
 * The completion flag is otherwise permanent, and fingerprints are only
 * maintained by the save_post hook, which does not exist while the plugin is
 * deactivated. Listings created during a deactivation window (chasing a
 * plugin conflict, a migration, a staging clone running with plugins off)
 * would therefore be invisible to duplicate detection forever. Clearing the
 * flag is safe to repeat: the backfill only queries listings with no
 * fingerprint, so on a healthy site it finds zero rows and re-sets the flag
 * on the same request.
 */
function hpalm_reopen_backfill() {
	delete_option( 'hpalm_hashes_backfilled' );
}
register_activation_hook( __FILE__, 'hpalm_reopen_backfill' );
/**
 * Runs the backfill from admin page loads, gated to logged-in users.
 *
 * WordPress fires admin_init for UNAUTHENTICATED admin-ajax.php requests too, so
 * without the gate any anonymous AJAX hit could trigger a 200-listing batch.
 * The work is bounded and input-free, so this is load shaping rather than a
 * security fix, but background work should not be driftable by anonymous
 * traffic when a login gate costs one function call.
 */
function hpalm_backfill_hashes_admin() {
	if ( is_user_logged_in() ) {
		hpalm_backfill_hashes();
	}
}
add_action( 'admin_init', 'hpalm_backfill_hashes_admin' );

/*
 * The backfill also rides HivePress's own hourly Action Scheduler event, so
 * it completes even on a site whose admin never opens wp-admin after
 * enabling the duplicate checks. The function is idempotent and marks
 * itself finished, so double-hooking costs one option read per run. The
 * admin_init path stays as the fallback for sites where HivePress is
 * deactivated before the backfill finishes.
 */
add_action( 'hivepress/v1/events/hourly', 'hpalm_backfill_hashes' );

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

	$found = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one prepared existence probe on an indexed join; WP_Query would fetch full rows for a boolean answer. No caching: a stale result would let a duplicate through.
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
 * Records whether the last AI moderation call worked.
 *
 * Failing open is right, but failing open SILENTLY is not: a site owner who
 * switches AI review on and is never told the calls are failing believes
 * they are protected while every submission goes unchecked. The commonest
 * cause is an OpenAI account with no usable quota, which returns HTTP 429
 * even though the moderation endpoint itself costs nothing to call.
 *
 * Written only when the state changes, so a working site never pays for a
 * database write, and stored as a transient so a fixed account clears
 * itself even if no submission happens for a while.
 *
 * @param string $reason Empty string when the call succeeded, otherwise a reason code.
 */
function hpalm_record_ai_health( $reason ) {
	$current = get_transient( 'hpalm_ai_error' );

	if ( '' === $reason ) {
		if ( false !== $current ) {
			delete_transient( 'hpalm_ai_error' );
		}

		return;
	}

	if ( $current !== $reason ) {
		set_transient( 'hpalm_ai_error', $reason, WEEK_IN_SECONDS );
	}
}

/**
 * Sends one request to OpenAI's Moderation endpoint.
 *
 * FAILS OPEN: returns null (treated as not flagged) when no key is set,
 * the request errors, times out, or the response cannot be parsed. A
 * moderation outage must never take down listing submission. Every failure
 * is recorded so the settings screen can say so out loud.
 *
 * @param array|string $input Moderation input: a text string, or an array of multi-modal input objects.
 * @param int          $timeout Request timeout in seconds.
 * @return bool|null True if flagged, false if clean, null if unavailable.
 */
function hpalm_ai_moderation_request( $input, $timeout ) {
	$key = get_option( 'hp_openai_api_key' );

	if ( ! is_string( $key ) || '' === trim( $key ) ) {
		hpalm_record_ai_health( 'no_key' );

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
			'timeout'    => $timeout,

			// Without an explicit user-agent WordPress sends
			// "WordPress/{version}; {site url}" (class-wp-http.php, verified),
			// leaking the site address and WordPress version to OpenAI on
			// every moderated submission. The slug/version pair identifies
			// the client and nothing else.
			'user-agent' => 'listing-moderation-for-hivepress/' . hpalm_get_version(),

			'headers'    => [
				'Authorization' => 'Bearer ' . trim( $key ),
				'Content-Type'  => 'application/json',
			],
			'body'       => $body,
		]
	);

	if ( is_wp_error( $response ) ) {
		hpalm_record_ai_health( 'unreachable' );

		return null;
	}

	$code = (int) wp_remote_retrieve_response_code( $response );

	if ( 200 !== $code ) {

		// The status alone is not enough to explain anything useful, so the
		// error code in the body is read first. Two of these matter a great
		// deal in practice: 429 is returned BOTH for an account with no
		// credit and for ordinary rate limiting, and a 400 carrying
		// image_url_unavailable means OpenAI could not download the photos
		// at all. That last one is the difference between "AI photo review
		// found nothing" and "AI photo review never saw your photos", which
		// otherwise looks identical from the outside: the submission is
		// accepted either way.
		$error = json_decode( wp_remote_retrieve_body( $response ), true );
		$slug  = ( is_array( $error ) && isset( $error['error']['code'] ) && is_string( $error['error']['code'] ) ) ? $error['error']['code'] : '';

		if ( 'image_url_unavailable' === $slug ) {
			hpalm_record_ai_health( 'image_unreachable' );
		} elseif ( 'invalid_image_format' === $slug ) {
			hpalm_record_ai_health( 'image_format' );
		} elseif ( 401 === $code || 403 === $code ) {
			hpalm_record_ai_health( 'key_rejected' );
		} elseif ( 429 === $code ) {
			hpalm_record_ai_health( 'rate_limit_exceeded' === $slug ? 'rate_limited' : 'no_quota' );
		} else {
			hpalm_record_ai_health( 'http_error' );
		}

		return null;
	}

	$body = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( ! is_array( $body ) || ! isset( $body['results'] ) || ! is_array( $body['results'] ) ) {
		hpalm_record_ai_health( 'bad_response' );

		return null;
	}

	$first = isset( $body['results'][0] ) && is_array( $body['results'][0] ) ? $body['results'][0] : null;

	if ( null === $first || ! isset( $first['flagged'] ) ) {
		hpalm_record_ai_health( 'bad_response' );

		return null;
	}

	hpalm_record_ai_health( '' );

	return (bool) $first['flagged'];
}

/**
 * Checks listing text against the OpenAI moderation endpoint.
 *
 * Sent as a plain string, which is the shape the endpoint documents and
 * accepts (verified live, and it agrees with the multi-modal form).
 *
 * The cap is 50,000 characters, a safety valve rather than an API limit:
 * 60,000 was accepted without complaint when measured, while HivePress's
 * own description field allows 10,240 before any custom attributes are
 * added. An earlier 8,000 was low enough that a long listing could carry
 * violating text past the cut and never have it read.
 *
 * @param string $text Combined listing text.
 * @return bool|null True when flagged, false when clean, null when unavailable.
 */
function hpalm_ai_flagged( $text ) {
	if ( '' === trim( $text ) ) {
		return null;
	}

	return hpalm_ai_moderation_request(
		function_exists( 'mb_substr' ) ? mb_substr( $text, 0, 50000 ) : substr( $text, 0, 50000 ),
		8
	);
}

/**
 * Checks listing photos against the OpenAI moderation endpoint.
 *
 * ONE REQUEST PER PHOTO, and that is not a style choice. The endpoint takes
 * exactly one image per call: send two and it answers
 * 400 too_many_images, "Number of images (2) exceeds maximum of 1"
 * (measured against the live API, 2026-08-11). Earlier versions batched
 * every photo into a single call, so on any listing with more than one
 * photo the request failed, the plugin failed open, and the listing was
 * accepted. It looked exactly like a clean pass, and a one-photo listing
 * worked perfectly, which is why it survived so long. Never batch these.
 *
 * Checking stops at the first photo that is flagged, so the common case of
 * a clean listing costs one call per photo and a bad one usually costs
 * fewer. A whole-run deadline keeps a slow API from holding up a
 * submission indefinitely.
 *
 * Returns false ONLY when every photo came back with a definite verdict.
 * If any photo could not be checked, the answer is null, because "some of
 * these photos were never looked at" is not the same as "these photos are
 * fine", and the caller treats null as unavailable.
 *
 * @param array $urls Image inputs: inline data URLs, or web addresses.
 * @return bool|null True when a photo is flagged, false when all are clean, null when unavailable.
 */
function hpalm_ai_flags_images( $urls ) {
	if ( ! $urls ) {
		return null;
	}

	/**
	 * Filters the total number of seconds photo review may spend.
	 *
	 * @hook hpalm_ai_image_budget
	 * @param {int} $seconds Whole-run deadline across every photo. Default 20.
	 * @return {int} Filtered deadline.
	 */
	$deadline = microtime( true ) + max( 5, (int) apply_filters( 'hpalm_ai_image_budget', 20 ) );

	$all_verdicts = true;
	$last_reason  = '';

	foreach ( $urls as $url ) {
		if ( microtime( true ) >= $deadline ) {
			hpalm_record_ai_health( 'image_timeout' );

			$all_verdicts = false;

			break;
		}

		$result = hpalm_ai_moderation_request(
			[
				[
					'type'      => 'image_url',
					'image_url' => [ 'url' => $url ],
				],
			],
			8
		);

		if ( true === $result ) {
			return true;
		}

		if ( null === $result ) {
			$all_verdicts = false;

			// Remember why, because a LATER photo succeeding in this same run
			// would clear the recorded reason (every parseable 200 does), and
			// the order of photos in a gallery must not decide whether the
			// owner is warned. Read now, re-assert after the loop.
			$reason = get_transient( 'hpalm_ai_error' );

			if ( is_string( $reason ) && '' !== $reason ) {
				$last_reason = $reason;
			}
		}
	}

	if ( ! $all_verdicts && '' !== $last_reason ) {
		hpalm_record_ai_health( $last_reason );
	}

	return $all_verdicts ? false : null;
}

/**
 * Collects the public URLs of a form's listing photos.
 *
 * Photos are uploaded through separate attachment requests while the form
 * is filled in, so the images field carries no value in the submitted form
 * data (Fields\Attachment_Upload renders no value inputs, and the REST
 * controller overwrites every field from the request params; both
 * verified). The listing model's own accessor is therefore the reliable
 * source: Models\Listing::get_images__id() (verified) returns the IDs of
 * the photos already attached to the listing. The form field is still read
 * as a secondary source in case a custom flow does post IDs. URLs that are
 * not absolute http(s) are skipped, since OpenAI could never fetch them.
 * The number of photos sent is capped, filterable via hpalm_max_ai_images.
 *
 * @param object $form Form object.
 * @return array
 */
function hpalm_collect_image_urls( $form ) {
	$ids = [];

	$listing = method_exists( $form, 'get_model' ) ? $form->get_model() : null;

	if ( hpalm_is_listing_model( $listing ) && $listing->get_id() ) {
		$ids = (array) $listing->get_images__id();
	}

	foreach ( $form->get_fields() as $field ) {
		if ( 'images' === $field->get_name() && ! $field->is_disabled() ) {
			$ids = array_merge( $ids, (array) $field->get_value() );
		}
	}

	$urls = [];

	/**
	 * Filters how many listing photos are sent for AI review per submission.
	 *
	 * @hook hpalm_max_ai_images
	 * @param {int} $limit Maximum number of photos. Default 10.
	 * @return {int} Filtered maximum.
	 */
	$max = max( 0, (int) apply_filters( 'hpalm_max_ai_images', 10 ) );

	// Budget for inlined image data, so a listing full of large photos can
	// never build a request big enough to time out or exhaust memory. Once
	// it is spent the remaining photos fall back to their URL.
	$budget = 8 * MB_IN_BYTES;

	// Videos come back from the SAME accessor as photos: when HivePress's
	// "Allow uploading videos" display setting is on, core appends 'video' to
	// the formats get_images__id() collects (models/class-listing.php:211-224),
	// so the gallery IDs are a mixed list. A video must be dropped here, not
	// later: the inline path correctly refuses it, but the URL fallback would
	// then hand OpenAI the raw .mp4 address, wasting up to 8 seconds of the
	// run budget per video on a request that cannot succeed and recording a
	// spurious format failure against a working setup. Filtering before the
	// slice also stops videos consuming photo slots from the per-submission
	// cap.
	$ids = array_filter( array_unique( array_map( 'intval', $ids ) ), 'wp_attachment_is_image' );

	foreach ( array_slice( $ids, 0, $max ) as $id ) {
		if ( ! $id ) {
			continue;
		}

		$inline = hpalm_get_inline_image( $id, $budget );

		if ( $inline ) {
			$budget -= strlen( $inline );
			$urls[]  = $inline;

			continue;
		}

		$url = wp_get_attachment_image_url( $id, 'large' );

		if ( ! is_string( $url ) || ! $url ) {
			$url = wp_get_attachment_url( $id );
		}

		if ( is_string( $url ) && preg_match( '#^https?://#i', $url ) ) {
			$urls[] = $url;
		}
	}

	return array_values( array_unique( $urls ) );
}

/**
 * Returns one listing photo as an inline data URL, or '' to use its web
 * address instead.
 *
 * Sending the picture itself rather than a link to it is what makes photo
 * review work at all on a real site. OpenAI does not receive images: given a
 * URL it goes and downloads it, so the check silently does nothing whenever
 * it cannot, which covers a site that is not public, one behind a login, and
 * any host with hotlink protection, a firewall or a CDN rule that refuses
 * requests not made by a browser. Every one of those failures looked
 * identical to "the photos were checked and were fine".
 *
 * The resized copy is preferred over the original: moderation does not need
 * full resolution, and it keeps the request small. When there is no readable
 * local file, which is the case on sites that offload uploads to cloud
 * storage, this returns '' so the caller falls back to the URL and the old
 * behaviour applies.
 *
 * @param int $id     Attachment ID.
 * @param int $budget Bytes of encoded data still available this request.
 * @return string Data URL, or '' when the file cannot be inlined.
 */
function hpalm_get_inline_image( $id, $budget ) {
	if ( $budget <= 0 ) {
		return '';
	}

	$path  = '';
	$sized = image_get_intermediate_size( $id, 'large' );

	if ( is_array( $sized ) && ! empty( $sized['path'] ) ) {
		$uploads   = wp_get_upload_dir();
		$candidate = trailingslashit( $uploads['basedir'] ) . $sized['path'];

		if ( is_readable( $candidate ) ) {
			$path = $candidate;
		}
	}

	if ( ! $path ) {
		$full = get_attached_file( $id );

		if ( is_string( $full ) && $full && is_readable( $full ) ) {
			$path = $full;
		}
	}

	if ( ! $path ) {
		return '';
	}

	// Only the formats the endpoint accepts, and only what fits the budget.
	// Base64 inflates by about a third, which the comparison accounts for.
	$type = wp_check_filetype( $path );
	$mime = isset( $type['type'] ) ? $type['type'] : '';

	if ( ! in_array( $mime, [ 'image/jpeg', 'image/png', 'image/webp', 'image/gif' ], true ) ) {
		return '';
	}

	$size = (int) filesize( $path );

	if ( $size < 1 || ( $size * 4 / 3 ) > $budget ) {
		return '';
	}

	$bytes = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a local upload off disk to send for moderation; WP_Filesystem is for writes and would add a credentials path for no benefit here.

	if ( false === $bytes ) {
		return '';
	}

	return 'data:' . $mime . ';base64,' . base64_encode( $bytes ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- this is the transport encoding the OpenAI image endpoint requires, not obfuscation.
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
 * The listing's vendor relation (post_parent) resolves to the Vendor model
 * object itself: relation fields with a _model argument return objects, not
 * IDs (verified in Model::_get_value), so the object is used directly. New
 * submissions start as an auto-draft created with only status, drafted and
 * user filled (verified in Controllers\Listing::redirect_listing_submit_page),
 * so the relation is empty at that point and the vendor is resolved through
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

	$vendor = $listing->get_vendor();

	if ( ! is_object( $vendor ) ) {

		// The ID accessor (get_user__id) returns the raw stored ID, the same
		// accessor core uses in Controllers\Listing::update_listing (verified).
		$user_id = (int) $listing->get_user__id();

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
	$limit = hpalm_absint( get_option( 'hp_alm_velocity_limit' ) );

	if ( $limit < 1 || 'auto-draft' !== $listing->get_status() ) {
		return null;
	}

	// get_user() would return the User model object (relation fields resolve
	// to objects); the __id accessor returns the raw author ID, matching
	// core's own usage in Controllers\Listing::update_listing (verified).
	$user_id = (int) $listing->get_user__id();

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

		// Naming the number matters: a vendor told they have hit "the maximum"
		// without being told what it is cannot tell whether to wait an hour or
		// give up, and has no way of looking the figure up.
		return esc_html(
			sprintf(
				/* translators: %s: the number of listings allowed in 24 hours, already formatted. */
				_n(
					'You can add %s listing every 24 hours, and you have reached that for today. You can still edit the listings you already have, and add more tomorrow.',
					'You can add %s listings every 24 hours, and you have reached that for today. You can still edit the listings you already have, and add more tomorrow.',
					$limit,
					'listing-moderation-for-hivepress'
				),
				number_format_i18n( $limit )
			)
		);
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

			if ( hpalm_is_listing_model( $listing ) && $listing->get_id() ) {
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
 * must be hooked for the hold to be reliable. HivePress seeds its settings
 * rows via Admin::init_settings on the activate AND update actions, but a
 * site that has never saved the Listings tab and never re-triggered either
 * action can still lack the row, so its presence is never assumed.
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

	// A flagged listing reaching publish from anywhere other than pending is
	// a path this plugin cannot see coming: the vendor's hide/unhide toggle
	// (drafts republish silently), a paid-package or membership handler
	// deciding the status inside a checkout webhook (where the request
	// context that powers the moderation-option hold does not exist), or any
	// programmatic publish. Re-asserting pending is what makes the hold
	// reliable on ALL of them. No recursion: the corrective update re-enters
	// this handler as publish-to-pending, which matches no branch.
	//
	// The exception is an administrator publishing it themselves in
	// wp-admin, which must always win: header note 9 exists precisely so a
	// flagged listing is never left unfixable by its own moderation. That is
	// NOT the same as "the current user is an admin", because an admin
	// completing a paid-listing order in wp-admin would then publish flagged
	// content without review. What distinguishes a deliberate human approval
	// is the shape of the request: the classic editor posts
	// action=editpost, Quick Edit posts action=inline-save, and Bulk Edit
	// posts bulk_edit. HivePress gates its own meta box saves on exactly the
	// first of those (Components\Admin, verified). The capability is the
	// authorisation; these markers only say a person pressed the button.
	if ( 'publish' === $new_status && 'pending' !== $old_status && get_post_meta( $listing_id, '_hpalm_flagged', true ) ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- not an authorisation decision: WordPress has already verified this edit's own nonce and capability before the status transition fires, and the capability check below is what authorises the exemption. These keys only identify which admin screen made the request.
		$action     = isset( $_POST['action'] ) && is_string( $_POST['action'] ) ? sanitize_key( wp_unslash( $_POST['action'] ) ) : '';
		$from_admin = in_array( $action, [ 'editpost', 'inline-save' ], true ) || isset( $_POST['bulk_edit'] );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( $from_admin && current_user_can( 'edit_others_posts' ) ) {

			// Treated as the approval it is: clear the marker so the
			// listing stops reporting itself as held.
			delete_post_meta( $listing_id, '_hpalm_flagged' );

			return;
		}
	}

	if ( 'publish' === $new_status && 'pending' !== $old_status && get_post_meta( $listing_id, '_hpalm_flagged', true ) ) {
		wp_update_post(
			[
				'ID'          => $listing_id,
				'post_status' => 'pending',
			]
		);

		return;
	}

	if ( 'pending' === $old_status && in_array( $new_status, [ 'publish', 'trash' ], true ) && get_post_meta( $listing_id, '_hpalm_flagged', true ) ) {
		hpalm_moderation_override( true );

		// The override covers both branches because core's moderation-gated
		// handler (Components\Listing::update_status, verified) sends the
		// native approval email on publish AND the rejection email on trash.
		// The marker clears on approval only: a rejected listing keeps it, so
		// the Moderation column still explains a trashed row, and restoring
		// the listing returns it to the pending queue (see
		// hpalm_untrash_status below, which is what makes that true).
		if ( 'publish' === $new_status ) {
			delete_post_meta( $listing_id, '_hpalm_flagged' );
		}
	}
}
add_action( 'hivepress/v1/models/listing/update_status', 'hpalm_handle_status_change', 9, 4 );

/**
 * Returns a restored held listing to the pending queue.
 *
 * Core's wp_untrash_post() restores every non-attachment to `draft`, not its
 * pre-trash status (wp-includes/post.php, verified against WP 7.0.2; core
 * ships wp_untrash_post_set_previous_status() as an opt-in callback only).
 * Without this filter, a rejected listing that an admin restores would land
 * in Drafts still carrying the held marker: gone from the pending queue and
 * badge, yet still labelled as held. Restoring to pending keeps the marker
 * truthful and the listing back under review.
 *
 * @param string $new_status      Status the post is about to be restored to.
 * @param int    $post_id         Post ID.
 * @param string $previous_status Status at the point it was trashed.
 * @return string
 */
function hpalm_untrash_status( $new_status, $post_id, $previous_status ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- signature fixed by the WordPress hook.
	if ( 'hp_listing' === get_post_type( $post_id ) && get_post_meta( $post_id, '_hpalm_flagged', true ) ) {
		return 'pending';
	}

	return $new_status;
}
add_filter( 'wp_untrash_post_status', 'hpalm_untrash_status', 10, 3 );

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
 * value) and then save()s, persisting the pending status. For auto-drafts
 * mid-submission, only the marker is set; the moderation-option filter
 * above turns it into a pending status through the native submit-complete
 * flow, complete with the native admin notification email. For anything
 * else (a vendor-hidden draft being edited, an expired draft) the marker
 * alone is enough, because the publish-transition guard in
 * hpalm_handle_status_change() forces any flagged listing back to pending
 * the moment something other than an admin approval tries to publish it.
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
	$listing_id = ( hpalm_is_listing_model( $listing ) && $listing->get_id() ) ? (int) $listing->get_id() : 0;

	// Gate 1: verified vendors skip every check. Any stale hold marker is
	// cleared too, so a listing held before its vendor was verified cannot
	// stay stuck in the pending flow.
	if ( $listing_id && get_option( 'hp_alm_bypass_verified_vendors' ) && hpalm_is_vendor_verified( $listing ) ) {
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
	$keywords  = hpalm_get_lines( 'hp_alm_blocked_keywords', true );
	$patterns  = hpalm_get_lines( 'hp_alm_blocked_patterns' );
	$evasion   = (bool) get_option( 'hp_alm_block_evasion' );
	$threshold = hpalm_absint( get_option( 'hp_alm_risk_threshold' ) );

	$modes = [
		'phone'     => hpalm_get_mode( 'hp_alm_block_phones' ),
		'email'     => hpalm_get_mode( 'hp_alm_block_emails' ),
		'url'       => hpalm_get_mode( 'hp_alm_block_urls' ),
		'dup_title' => hpalm_get_mode( 'hp_alm_check_duplicate_titles' ),
		'dup_desc'  => hpalm_get_mode( 'hp_alm_check_duplicate_descriptions' ),
		'ai'        => hpalm_get_mode( 'hp_alm_block_ai' ),
		'ai_image'  => hpalm_get_mode( 'hp_alm_block_ai_images' ),
	];

	$scoring_on = $threshold >= 1;
	$caps_on    = $scoring_on && get_option( 'hp_alm_score_caps' );

	// A detector on "Add to risk score" is inert while no Risk Threshold is
	// set, so it is treated as OFF here rather than merely discarded later:
	// otherwise every submission would still spend the duplicate queries and
	// the OpenAI calls (sending listing text to a third party) for a result
	// that cannot affect anything.
	if ( ! $scoring_on ) {
		foreach ( $modes as $type => $mode ) {
			if ( 'score' === $mode ) {
				$modes[ $type ] = '';
			}
		}
	}

	if ( ! $keywords && ! $patterns && ! array_filter( $modes ) && ! $caps_on ) {

		// Even with every check switched off, the stored record must keep
		// describing the listing's current content, so a submission made in
		// this state clears any earlier score and hold like a clean
		// resubmission would. Without this, the admin screens would keep
		// reporting signals from content that may no longer exist.
		if ( $listing_id ) {
			hpalm_delete_audit_trail( $listing_id );

			if ( get_post_meta( $listing_id, '_hpalm_flagged', true ) ) {
				delete_post_meta( $listing_id, '_hpalm_flagged' );
			}
		}

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

		// Readonly fields hold system-generated values the vendor never
		// typed. The known case is Bookings' "Booking Export URL", a
		// readonly url field carrying a calendar access key, added to the
		// listing edit form whenever calendar sync is on
		// (components/class-booking.php, gated on hp_booking_allow_sync,
		// verified). It is _separate, so the form keeps its default value
		// rather than the model's: scanning it would refuse every edit of
		// every bookable listing the moment the website detector was set
		// to Block. Only vendor-entered text is moderated.
		if ( $field->get_arg( 'readonly' ) ) {
			continue;
		}

		$value = $field->get_value();

		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			continue;
		}

		$label = $field->get_label( esc_html__( 'a listing field', 'listing-moderation-for-hivepress' ) );

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

	// Deliberately NO early return when $entries is empty. A listing can
	// legitimately carry no scannable text at all: core removes the title
	// field whenever a title format is configured (Components\Listing::
	// alter_forms, gated on hp_listing_title_format, verified) and the
	// description is optional in the model, so photos plus select/number
	// attributes is a real shape on attribute-driven sites. Photo review
	// must still run for it. Every text check below is a no-op on an empty
	// list, and hpalm_ai_flagged() refuses an empty string before spending a
	// call, so nothing else changes.
	$detector_messages = [
		/* translators: 1: the detected text, 2: the field label (e.g. Description). */
		'phone' => esc_html__( 'Phone numbers are not allowed in listings ("%1$s" found in %2$s). Please remove it to continue.', 'listing-moderation-for-hivepress' ),
		/* translators: 1: the detected text, 2: the field label (e.g. Description). */
		'email' => esc_html__( 'Email addresses are not allowed in listings ("%1$s" found in %2$s). Please remove it to continue.', 'listing-moderation-for-hivepress' ),
		/* translators: 1: the detected text, 2: the field label (e.g. Description). */
		'url'   => esc_html__( 'Website addresses are not allowed in listings ("%1$s" found in %2$s). Please remove it to continue.', 'listing-moderation-for-hivepress' ),
	];

	// ---- Phase 1: blocking checks. ----

	$signals = [];

	foreach ( $entries as $entry ) {
		$found = hpalm_find_blocked_text_deep( $entry['value'], $keywords, $patterns, $evasion );

		if ( null !== $found ) {

			// Avoids "submitting your listing": the same message is shown on
			// the edit form, where the vendor is changing a listing they
			// already have rather than submitting a new one.
			$errors[] = sprintf(
				/* translators: 1: the blocked word or phrase, 2: the field label (e.g. Description). */
				esc_html__( 'Please remove or change "%1$s" in the %2$s, then try again. This site does not allow that wording in listings.', 'listing-moderation-for-hivepress' ),
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
		'dup_title' => [ $title, '_hpalm_title_hash', esc_html__( 'A listing with this exact title already exists. Please choose a different title.', 'listing-moderation-for-hivepress' ) ],
		'dup_desc'  => [ $description, '_hpalm_desc_hash', esc_html__( 'A listing with this exact description already exists. Please write a unique description.', 'listing-moderation-for-hivepress' ) ],
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
				// The AI check runs on every text field joined together, so no
				// single field can honestly be named here.
				$errors[] = esc_html__( 'This listing was refused by an automatic content check, which looks for hateful, harassing, violent, sexual or self-harm wording. Please read back through the title, description and other details, change anything of that kind, and try again. If you think this is a mistake, please contact the site owner.', 'listing-moderation-for-hivepress' );
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
				// Photos already on the listing are checked too, not only ones
				// added in this edit, so a vendor who changed nothing but the
				// price can still land here and needs telling why.
				$errors[] = esc_html__( 'One of your photos was refused by an automatic check, which looks for sexual, violent or self-harm imagery. Please remove or replace it and try again. This can be a photo that was already on the listing, not only one you have just added.', 'listing-moderation-for-hivepress' );
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
 * Plain __(), not esc_html__(): the meta box escapes these at the point of
 * output, and escaping twice turns any apostrophe or quote a TRANSLATION
 * contains into a literal HTML entity on screen. Invisible in English, which
 * is exactly why it needs the comment.
 *
 * @return array
 */
function hpalm_get_signal_labels() {
	return [
		'phone'     => __( 'A phone number was found', 'listing-moderation-for-hivepress' ),
		'email'     => __( 'An email address was found', 'listing-moderation-for-hivepress' ),
		'url'       => __( 'A website address was found', 'listing-moderation-for-hivepress' ),
		'caps'      => __( 'Mostly written in capital letters', 'listing-moderation-for-hivepress' ),
		'dup_title' => __( 'Another listing has the same title', 'listing-moderation-for-hivepress' ),
		'dup_desc'  => __( 'Another listing has the same description', 'listing-moderation-for-hivepress' ),
		'ai'        => __( 'The AI check flagged the wording', 'listing-moderation-for-hivepress' ),
		'ai_image'  => __( 'The AI check flagged a photo', 'listing-moderation-for-hivepress' ),
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

	$label   = esc_html__( 'Moderation', 'listing-moderation-for-hivepress' );
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

		// Not a dash. A dash could equally mean "checked and clean", "never
		// checked" or "checked before this plugin existed", and the house
		// rules forbid an em-dash in anything a reader sees.
		echo '<span class="hpalm-none">' . esc_html__( 'No score recorded', 'listing-moderation-for-hivepress' ) . '</span>';

		return;
	}

	$score = hpalm_absint( $score );

	echo '<strong>' . esc_html(
		sprintf(
			/* translators: %s: the number of risk points, already formatted. */
			_n( '%s point', '%s points', $score, 'listing-moderation-for-hivepress' ),
			number_format_i18n( $score )
		)
	) . '</strong>';

	// hp-status is HivePress's own status pill; common.min.css ships it to
	// every admin screen (backend scope in configs/styles.php, verified). The
	// pill sits on its own line because core styles it white-space: nowrap,
	// so inline it would widen the column on every row.
	$pill = hpalm_get_state_pill( $listing_id, $score );

	if ( $pill ) {
		echo '<br /><span class="hp-status hp-status--' . esc_attr( $pill['modifier'] ) . '"><span>' . esc_html( $pill['label'] ) . '</span></span>';
	}
}

/**
 * Describes what actually became of a scored listing.
 *
 * Every scored row used to show a bare number, with a pill only while the
 * listing was pending. That left four of the five states showing a score and
 * nothing else, so a binned listing and a published one looked identical and
 * the reader could not tell whether the score had done anything. The state is
 * derived rather than stored, because an admin can publish, bin or restore a
 * listing at any time without the plugin being involved.
 *
 * @param int $listing_id Listing ID.
 * @param int $score      Recorded risk score.
 * @return array|null Pill label and hp-status modifier, or null when a pill would add nothing.
 */
function hpalm_get_state_pill( $listing_id, $score ) {
	$status    = get_post_status( $listing_id );
	$threshold = hpalm_absint( get_post_meta( $listing_id, '_hpalm_threshold', true ) );

	// Derived from the score, NOT from the _hpalm_flagged marker. Approving
	// or rejecting a held listing deletes that marker on purpose (it is what
	// stops the publish guard reversing the admin's own decision), so a pill
	// gated on it could never say "Approved" or "Rejected" - the two states
	// the reader most wants confirmed.
	$was_held = ( $threshold >= 1 && $score >= $threshold );

	if ( ! $was_held ) {
		return 'publish' === $status
			? [
				'label'    => esc_html__( 'Published as normal', 'listing-moderation-for-hivepress' ),
				'modifier' => 'publish',
			]
			: null;
	}

	switch ( $status ) {
		case 'pending':
			return [
				'label'    => esc_html__( 'Held for review', 'listing-moderation-for-hivepress' ),
				'modifier' => 'pending',
			];

		case 'publish':
			return [
				'label'    => esc_html__( 'Held, then approved', 'listing-moderation-for-hivepress' ),
				'modifier' => 'publish',
			];

		case 'trash':
			return [
				'label'    => esc_html__( 'Held, then rejected', 'listing-moderation-for-hivepress' ),
				'modifier' => 'draft',
			];

		// A held listing that reaches its expiry date becomes a draft, so it
		// leaves the queue without anyone having decided anything.
		case 'draft':
			return [
				'label'    => esc_html__( 'Held, now a draft', 'listing-moderation-for-hivepress' ),
				'modifier' => 'draft',
			];
	}

	return null;
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

	// post_type can be a string or an array on a WP_Query, so both are handled
	// rather than assuming the admin list table's string form.
	if ( ! in_array( 'hp_listing', (array) $query->get( 'post_type' ), true ) || 'hpalm_score' !== $query->get( 'orderby' ) ) {
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
		esc_html__( 'Moderation', 'listing-moderation-for-hivepress' ),
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
	$status     = $listing_id ? get_post_status( $listing_id ) : '';

	// Add New opens on an auto-draft, where "nothing was found" would be a
	// statement about a listing that does not exist yet.
	if ( ! $listing_id || 'auto-draft' === $status ) {
		echo '<p>' . esc_html__( 'Listings are checked when they are submitted or edited on the site. Nothing has been checked here yet.', 'listing-moderation-for-hivepress' ) . '</p>';

		return;
	}

	$score = get_post_meta( $listing_id, '_hpalm_score', true );

	if ( '' === $score || false === $score ) {

		// This used to read "No risk signals were recorded", which sounds
		// like a clean bill of health and is untrue for a listing added in
		// the dashboard or by an importer, neither of which is ever checked.
		echo '<p>' . esc_html__( 'No risk score has been recorded for this listing.', 'listing-moderation-for-hivepress' ) . '</p>';
		echo '<p>' . esc_html__( 'That can mean any of these: it was submitted on the site and nothing was found, risk scoring was switched off at the time because no Risk Threshold had been set, it was added here in the dashboard or by an import (neither of which is ever checked), or it was last submitted before this plugin was installed.', 'listing-moderation-for-hivepress' ) . '</p>';

		return;
	}

	$score     = hpalm_absint( $score );
	$threshold = hpalm_absint( get_post_meta( $listing_id, '_hpalm_threshold', true ) );
	$current   = hpalm_absint( get_option( 'hp_alm_risk_threshold' ) );
	$signals   = get_post_meta( $listing_id, '_hpalm_signals', true );
	$flagged   = (bool) get_post_meta( $listing_id, '_hpalm_flagged', true );
	$labels    = hpalm_get_signal_labels();

	echo '<p><strong>' . esc_html(
		sprintf(
			/* translators: %s: the number of risk points, already formatted. */
			_n( 'Risk score: %s point', 'Risk score: %s points', $score, 'listing-moderation-for-hivepress' ),
			number_format_i18n( $score )
		)
	) . '</strong></p>';

	$pill = hpalm_get_state_pill( $listing_id, $score );

	if ( $pill ) {
		echo '<p><span class="hp-status hp-status--' . esc_attr( $pill['modifier'] ) . '"><span>' . esc_html( $pill['label'] ) . '</span></span></p>';
	}

	// The outcome in a sentence. The old screen printed "65 of 21", which
	// reads as a fraction with a bigger top than bottom; the second number is
	// not a maximum but the point at which a listing stops being published.
	if ( $threshold >= 1 ) {
		if ( $score >= $threshold ) {

			// Past tense and no claim about where the listing is now: the
			// pill above already says that, and it may since have been
			// approved, rejected or left to expire.
			echo '<p>' . esc_html(
				sprintf(
					/* translators: %s: the risk threshold that applied, e.g. "20 points". */
					__( 'That reached your Risk Threshold of %s, which is why this listing was held for review rather than going straight live.', 'listing-moderation-for-hivepress' ),
					sprintf(
						/* translators: %s: number of points, already formatted. */
						_n( '%s point', '%s points', $threshold, 'listing-moderation-for-hivepress' ),
						number_format_i18n( $threshold )
					)
				)
			) . '</p>';
		} else {
			echo '<p>' . esc_html(
				sprintf(
					/* translators: %s: the risk threshold, e.g. "20 points". */
					__( 'That is below your Risk Threshold of %s, so the listing was published as normal.', 'listing-moderation-for-hivepress' ),
					sprintf(
						/* translators: %s: number of points, already formatted. */
						_n( '%s point', '%s points', $threshold, 'listing-moderation-for-hivepress' ),
						number_format_i18n( $threshold )
					)
				)
			) . '</p>';
		}

		// The stored threshold is the one that applied at the time. Saying
		// "your limit" about a number the owner has since changed would be a
		// lie, so say both.
		if ( $current >= 1 && $current !== $threshold ) {
			echo '<p>' . esc_html(
				sprintf(
					/* translators: %s: the current risk threshold, e.g. "30 points". */
					__( 'Your Risk Threshold has changed since this was checked. It is now %s, and this listing will be measured against the new figure the next time it is submitted on the site.', 'listing-moderation-for-hivepress' ),
					sprintf(
						/* translators: %s: number of points, already formatted. */
						_n( '%s point', '%s points', $current, 'listing-moderation-for-hivepress' ),
						number_format_i18n( $current )
					)
				)
			) . '</p>';
		}
	}

	if ( is_array( $signals ) && $signals ) {
		echo '<p><strong>' . esc_html(
			$flagged && 'pending' === $status
				? esc_html__( 'Why it was held', 'listing-moderation-for-hivepress' )
				: esc_html__( 'What was found', 'listing-moderation-for-hivepress' )
		) . '</strong></p>';

		echo '<ul>';

		foreach ( $signals as $signal => $points ) {
			$points = hpalm_absint( $points );

			$label = isset( $labels[ $signal ] )
				? $labels[ $signal ]
				: sprintf(
					/* translators: %s: the internal name of a check added by other code. */
					__( 'Another check (%s)', 'listing-moderation-for-hivepress' ),
					$signal
				);

			echo '<li>' . esc_html(
				sprintf(
					/* translators: 1: what was found, 2: the number of points it added, already formatted. */
					_n( '%1$s: %2$s point', '%1$s: %2$s points', $points, 'listing-moderation-for-hivepress' ),
					$label,
					number_format_i18n( $points )
				)
			) . '</li>';
		}

		echo '</ul>';
	} else {
		echo '<p>' . esc_html__( 'The breakdown behind this score was not recorded. It will be filled in the next time the listing is submitted or edited on the site.', 'listing-moderation-for-hivepress' ) . '</p>';
	}

	if ( $flagged && 'pending' === $status ) {
		// Quick Edit is named deliberately: a listing whose category requires
		// an attribute the vendor has not filled in cannot be saved from this
		// screen at all, and the row actions are the documented way round it.
		echo '<p>' . esc_html__( 'You decide what happens next. To approve it, set the status to Published; to reject it, move it to the Bin. HivePress emails the vendor either way. If a required listing field stops you saving from here, use Quick Edit on the Listings screen instead.', 'listing-moderation-for-hivepress' ) . '</p>';
	}

	echo '<p>' . esc_html__( 'These results are from the last time the listing was submitted or edited on the site. Changes you make here in the dashboard are not checked.', 'listing-moderation-for-hivepress' ) . '</p>';
}

/**
 * Makes the held pill readable on the admin's white background.
 *
 * The hp-status class is HivePress's own status pill and the right thing to
 * reuse for shape, spacing and typography, but its pending modifier paints
 * the text
 * #ffcb00, which against the white list-table background measures roughly
 * 1.7:1 where WCAG asks for 4.5:1. That amber is legible on HivePress's own
 * darker front-end surfaces and genuinely hard to read here. Only the
 * colours are overridden, and only inside this plugin's own column and meta
 * box, so HivePress's pills everywhere else in wp-admin are untouched.
 *
 * Registered as an inline-only stylesheet (a false src is the documented
 * way) so the plugin still ships no asset files and nothing has to be added
 * to the release packaging.
 *
 * @param string $hook Current admin page.
 */
function hpalm_admin_pill_styles( $hook ) {
	if ( ! in_array( $hook, [ 'edit.php', 'post.php', 'post-new.php' ], true ) ) {
		return;
	}

	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

	if ( ! $screen || 'hp_listing' !== $screen->post_type ) {
		return;
	}

	wp_register_style( 'hpalm-admin', false, [], hpalm_get_version() ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- version IS supplied; the sniff misreads the inline-only false src.
	wp_enqueue_style( 'hpalm-admin' );
	wp_add_inline_style(
		'hpalm-admin',
		// The amber pending pill, made readable on white. Only the "held"
		// modifier needs it; the approved and rejected pills use HivePress's
		// own colours, which already pass on this background.
		'.column-hpalm_score .hp-status--pending span,#hpalm_moderation .hp-status--pending span{color:#8a6100;border-color:#dba617;background:#fcf9e8;}'
		// The pill sits under the score rather than beside it, so it needs a
		// little air; core styles it for a front-end card, not a table cell.
		. '.column-hpalm_score .hp-status{margin-top:.35rem;display:inline-block;}'
		// "No score recorded" is context, not data, so it is quietened to
		// WordPress's own secondary text colour rather than shouting.
		. '.column-hpalm_score .hpalm-none{color:#646970;}'
	);
}

if ( is_admin() ) {
	add_action( 'admin_enqueue_scripts', 'hpalm_admin_pill_styles' );
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
 * Returns the starter blocklists, grouped by subject.
 *
 * Grouped rather than offered as one button because the right list depends
 * entirely on what the site sells. A shop selling adult products must not
 * import the sexual-content list, and a food marketplace that lists faggots
 * in gravy, or a car-parts site with transmissions, needs to make that
 * choice knowingly rather than have it made for it.
 *
 * Within each group the split between the two fields is what keeps the
 * lists safe. Substring-safe words go into Blocked Keywords, because
 * keywords match INSIDE longer words. Anything that appears inside an
 * innocent word goes into Blocked Patterns with \b boundaries: the classic
 * example is the c-word inside the town name "Scunthorpe", but the same
 * applies to "ass" in class/glass/passenger, "crap" in scrap, "anal" in
 * analysis, "coon" in raccoon, "spic" in spice, "retard" in fire retardant
 * and "paki" in Pakistan. Every entry below has been checked against that
 * failure, and the ones that stay risky even with boundaries are called out
 * in the button's hover text so an owner can remove them.
 *
 * Filterable, so a site can ship its own lists:
 *
 *     add_filter( 'hpalm_starter_lists', function( $lists ) {
 *         $lists['profanity']['keywords'][] = 'some phrase';
 *         return $lists;
 *     } );
 *
 * @return array Groups keyed by slug, each with label, note, keywords and patterns.
 */
function hpalm_get_starter_lists() {
	$lists = [
		'profanity' => [
			'label'    => __( 'Profanity', 'listing-moderation-for-hivepress' ),
			'note'     => __( 'General swearing and insults. The safest list to start with on most sites.', 'listing-moderation-for-hivepress' ),
			'keywords' => [
				'fuck',
				'motherfucker',
				'shit',
				'bullshit',
				'horseshit',
				'tosser',
				'bollocks',
				'bellend',
				'knobhead',
				'dickhead',
				'arsehole',
				'asshole',
				'dumbass',
				'jackass',
				'smartass',
				'bastard',
				'douchebag',
				'pillock',
				'plonker',
				'wanker',
				'numpty',
			],
			'patterns' => [
				// twat and bitch look substring-safe and are not: Lightwater
				// in Surrey and Bitchfield in Lincolnshire are real places a
				// UK marketplace will genuinely list. Both shipped as plain
				// keywords in earlier builds and blocked those listings.
				// bugger is the same trap: as a keyword it sat inside
				// "debugger", refusing perfectly ordinary tech listings.
				'\btwats?\b',
				'\bbitch(?:es|ed|ing|y)?\b',
				'\bbugger(?:s|ed|ing|y)?\b',
				'\bass\b',
				'\basses\b',
				'\bcrap\w*',
				'\bpiss\w*',
				'\bdamn\w*',
				'\bfeck\w*',
				'\bcunt\w*',
				'\bwank\w*',
				'\bprick\b',
				'\btits\b',
				'\bshag(?:ged|ging|s)?\b',
			],
		],

		'sexual'    => [
			'label'    => __( 'Sexual content', 'listing-moderation-for-hivepress' ),
			'note'     => __( 'Explicit and adult material. Do not import this on a site that legitimately sells adult products.', 'listing-moderation-for-hivepress' ),
			'keywords' => [
				'porn',
				'pornographic',
				'hardcore porn',
				'blowjob',
				'handjob',
				'cocksucker',
				'jizz',
				'cumshot',
				'creampie',
				'gangbang',
				'bukkake',
				'masturbat',
				'orgasm',
				'ejaculat',
				'genitalia',
				'onlyfans',
				'camgirl',
				'hentai',
				'escort service',
				'escort services',
				'sexual services',
				'adult services',
				'nude photos',
				'naked photos',
				'anal sex',
				'oral sex',
				'dildo',
				'buttplug',
				'fetish',
				'bdsm',
				'slut',
				'whore',
			],
			'patterns' => [
				// milf as a keyword sits inside Milford, and Milford Haven,
				// Milford on Sea and two more Milfords are real British
				// places a property or holiday site will genuinely list.
				'\bmilfs?\b',
				'\banal\b',
				'\bxxx\b',
				'\bpussy\b',
				'\bcocks?\b',
				'\btit\b',
				'\bnude\b',
			],
		],

		'slurs'     => [
			'label'    => __( 'Slurs and hate speech', 'listing-moderation-for-hivepress' ),
			'note'     => __( 'Racist, homophobic and ableist slurs. Every entry uses whole-word matching so ordinary words are not caught.', 'listing-moderation-for-hivepress' ),
			'keywords' => [],
			'patterns' => [
				// Whole-word throughout, so the note above stays true. These
				// four have no innocent superstring today, but keywords match
				// inside longer words and this is the one list where a future
				// coincidence must not decide who gets refused.
				'\btowelheads?\b',
				'\bwetbacks?\b',
				'\bhalf-?castes?\b',
				'\bn[i1]gg[ae]r?s?\b',
				'\bcoons?\b',
				'\bpaki(?:s)?\b',
				'\bchinks?\b',
				'\bspics?\b',
				'\bwogs?\b',
				'\byids?\b',
				'\bkikes?\b',
				'\bgooks?\b',
				'\bfaggots?\b',
				'\bfags?\b',
				'\bdykes?\b',
				'\btrannys?\b',
				'\btrannies\b',
				'\bretard(?:ed|s)?\b',
				'\bspastics?\b',
				'\bspazz?\b',
				'\bmongs?\b',
				'\bmongoloid\b',
			],
		],

		'scams'     => [
			'label'    => __( 'Scams and off-platform selling', 'listing-moderation-for-hivepress' ),
			'note'     => __( 'Phrases used to move a deal off your site or to take payment you cannot protect. Review these against how your own sellers legitimately trade.', 'listing-moderation-for-hivepress' ),
			'keywords' => [
				'western union',
				'moneygram',
				'wire transfer',
				'bank transfer only',
				'cash app',
				'cashapp',
				'gift card only',
				'gift cards only',
				'bitcoin only',
				'crypto only',
				'send money',
				'pay outside',
				'off platform',
				'off-platform',
				'deal outside',
				'contact me directly',
				'text me directly',
				'whatsapp me',
				'telegram me',
				'no paypal',
				'avoid fees',
				'avoid the fees',
				'cash in hand',
				'cash only',
				'advance fee',
				'shipping agent',
			],
			'patterns' => [
				// zelle as a keyword sits inside gazelle, which is a bicycle
				// brand and an animal a marketplace will genuinely list.
				'\bzelle\b',
			],
		],
	];

	/**
	 * Filters the starter blocklists offered on the settings screen.
	 *
	 * @hook hpalm_starter_lists
	 * @param {array} $lists Groups keyed by slug, each with label, note, keywords and patterns.
	 * @return {array} Filtered groups.
	 */
	return apply_filters( 'hpalm_starter_lists', $lists );
}

/**
 * Adds the "Import starter blocklist" button below the Blocked Patterns
 * field on the HivePress settings screen (slug hp_settings, verified).
 *
 * Restricted to the Listings tab, the only tab holding the two textareas it
 * fills. The tab defaults to the first one when absent or unrecognised
 * (Admin::get_settings_tab, verified), and Listings is that first tab, so an
 * absent tab argument counts as the Listings tab here too.
 *
 * Client-side only: the button merges the starter list into the two
 * textareas (deduplicated) and the admin still reviews and clicks Save
 * Changes, so no AJAX endpoint or nonce surface exists beyond the
 * manage_options requirement of the page itself.
 */
function hpalm_render_import_button() {
	// $_GET values can be arrays (?page[]=x). sanitize_key() itself survives
	// that (it returns '' for non-scalars), but the guard keeps the intent
	// explicit and the value provably a string before comparison: a
	// non-string page can never be the settings screen.
	$page = ( isset( $_GET['page'] ) && is_string( $_GET['page'] ) ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only check of which admin screen is shown; no state is changed.
	$tab  = ( isset( $_GET['tab'] ) && is_string( $_GET['tab'] ) ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'listings'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- see above.

	if ( 'hp_settings' !== $page || 'listings' !== $tab || ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$lists = hpalm_get_starter_lists();
	?>
	<script>
	( function() {
		var kw = document.querySelector( 'textarea[name="hp_alm_blocked_keywords"]' );
		var pt = document.querySelector( 'textarea[name="hp_alm_blocked_patterns"]' );

		if ( ! kw || ! pt ) {
			return;
		}

		var lists = <?php echo wp_json_encode( array_values( $lists ) ); ?>;
		var added = [];

		function mergeInto( textarea, items, splitCommas ) {
			var existing = textarea.value.split( splitCommas ? /[\n,]+/ : /\n/ )
				.map( function( s ) { return s.trim(); } )
				.filter( function( s ) { return s.length; } );

			var count = 0;

			items.forEach( function( item ) {
				if ( existing.indexOf( item ) === -1 ) {
					existing.push( item );
					count++;
				}
			} );

			textarea.value = existing.join( '\n' );

			return count;
		}

		var wrap = document.createElement( 'p' );
		var note = document.createElement( 'span' );

		// WordPress admin's own muted-hint class, rather than bespoke inline
		// colours; the margin keeps the note off the buttons at any root size.
		note.className        = 'description';
		note.style.marginLeft = '0.5rem';
		note.textContent      = <?php echo wp_json_encode( __( 'Add a starter list, then review it and save. Nothing is saved until you do.', 'listing-moderation-for-hivepress' ) ); ?>;

		lists.forEach( function( list ) {
			var btn = document.createElement( 'button' );

			btn.type            = 'button';
			btn.className       = 'button';
			btn.style.marginRight = '0.25rem';
			btn.textContent     = list.label;
			btn.title           = list.note;

			btn.addEventListener( 'click', function() {
				var n = mergeInto( kw, list.keywords, true ) + mergeInto( pt, list.patterns, false );

				if ( added.indexOf( list.label ) === -1 ) {
					added.push( list.label );
				}

				// Built with textContent, never innerHTML: these strings are
				// translatable and a translation is not trusted markup.
				note.textContent = <?php echo wp_json_encode( __( 'Added:', 'listing-moderation-for-hivepress' ) ); ?>
					+ ' ' + added.join( ', ' ) + '. '
					+ ( n
						? n + ' ' + <?php echo wp_json_encode( __( 'new entries. Review them, then click Save Changes.', 'listing-moderation-for-hivepress' ) ); ?>
						: <?php echo wp_json_encode( __( 'Everything in that list was already there.', 'listing-moderation-for-hivepress' ) ); ?> );
			} );

			wrap.appendChild( btn );
		} );

		wrap.appendChild( note );
		pt.parentNode.appendChild( wrap );
	} )();
	</script>
	<?php
}
add_action( 'admin_footer', 'hpalm_render_import_button' );

/*
 * Translations load through WordPress's just-in-time textdomain loading
 * from wp-content/languages/plugins/ (the location Loco Translate calls
 * "System", which survives plugin updates); there is deliberately no
 * load_plugin_textdomain() call. Note WHY, precisely: this plugin is NOT a
 * registered HivePress extension, so nothing loads a bundled .mo for it.
 * The official extensions also skip the call, but only because HivePress
 * core calls load_plugin_textdomain() for every registered extension path
 * at boot (Core::load_textdomains, verified) - do not copy this pattern
 * into an extension-registered plugin expecting bundled translations to
 * work the same way. A .mo inside this plugin's own /languages/ folder is
 * never auto-loaded (the textdomain registry only scans WP_LANG_DIR); the
 * shipped .pot is a translation template, not a translation. If we ever
 * bundle finished translations, add load_plugin_textdomain() on init.
 */

/**
 * Warns, on the settings screen only, when AI review is switched on but the
 * calls to OpenAI are failing.
 *
 * The plugin deliberately fails open, so a broken AI connection never stops
 * vendors submitting; the cost of that is an owner who thinks AI review is
 * protecting them while it quietly does nothing. This is the only honest
 * place to say so. Deliberately narrow so it can never become admin noise:
 * it appears on the HivePress settings screen alone, only for users who can
 * change these settings, only while at least one AI detector is switched
 * on, and only while a failure is actually on record; it clears itself the
 * moment a call succeeds.
 */
function hpalm_ai_health_notice() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$page = ( isset( $_GET['page'] ) && is_string( $_GET['page'] ) ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only check of which admin screen is shown; no state is changed.

	if ( 'hp_settings' !== $page ) {
		return;
	}

	// "Switched on" has to mean what the validator means, not just what is
	// stored: a detector left on "Add to risk score" with no Risk Threshold
	// never runs, so warning that AI review is on and failing would be its
	// own small lie.
	$scoring_on = hpalm_absint( get_option( 'hp_alm_risk_threshold' ) ) >= 1;
	$ai_on      = false;

	foreach ( [ 'hp_alm_block_ai', 'hp_alm_block_ai_images' ] as $option ) {
		$mode = hpalm_get_mode( $option );

		if ( 'block' === $mode || ( 'score' === $mode && $scoring_on ) ) {
			$ai_on = true;
		}
	}

	if ( ! $ai_on ) {
		return;
	}

	$reason = get_transient( 'hpalm_ai_error' );

	if ( ! $reason ) {
		return;
	}

	// A recorded "no key" is stale the moment a key is entered, and the next
	// successful call may be days away, so re-check the live value rather
	// than telling the owner to do something they have already done.
	if ( 'no_key' === $reason ) {
		$key = get_option( 'hp_openai_api_key' );

		if ( is_string( $key ) && '' !== trim( $key ) ) {
			delete_transient( 'hpalm_ai_error' );

			return;
		}
	}

	// Translated raw here and escaped once at output below, rather than
	// escaped into the array: escaping late is the house rule, and
	// pre-escaping would double-encode the apostrophes when the whole
	// sentence is escaped.
	$reasons = [
		'no_key'            => __( 'no API key has been entered on the Integrations tab', 'listing-moderation-for-hivepress' ),
		'key_rejected'      => __( 'OpenAI rejected the API key', 'listing-moderation-for-hivepress' ),
		'no_quota'          => __( 'OpenAI refused the request, usually because the account has no credit available. Moderation calls themselves cost nothing, but OpenAI still requires a payment method or purchased credit on the account before any request is allowed', 'listing-moderation-for-hivepress' ),
		'rate_limited'      => __( 'OpenAI is rate limiting this site because too many requests were sent in a short time. This usually settles by itself', 'listing-moderation-for-hivepress' ),
		'image_unreachable' => __( 'OpenAI could not download one of the listing photos. This affects photos stored outside this site, such as on cloud storage, when a firewall or CDN rule blocks requests that do not come from a browser', 'listing-moderation-for-hivepress' ),
		'image_format'      => __( 'OpenAI could not read the format of one of the listing photos. It accepts JPEG, PNG, WebP and non-animated GIF', 'listing-moderation-for-hivepress' ),
		'image_timeout'     => __( 'checking the listing photos took too long, so some were not reviewed. This usually means OpenAI is responding slowly', 'listing-moderation-for-hivepress' ),
		'unreachable'       => __( 'OpenAI could not be reached from this site', 'listing-moderation-for-hivepress' ),
		'http_error'        => __( 'OpenAI returned an unexpected error', 'listing-moderation-for-hivepress' ),
		'bad_response'      => __( 'OpenAI returned a response this plugin could not read', 'listing-moderation-for-hivepress' ),
	];

	$explanation = isset( $reasons[ $reason ] ) ? $reasons[ $reason ] : $reasons['http_error'];

	$message = sprintf(
		/* translators: %s: the reason the connection failed, e.g. "OpenAI rejected the API key". */
		__( 'Automated Listing Moderation: AI review is switched on, but the last check could not be completed because %s. Listings are still being accepted, without AI review, until this is resolved. Every other moderation check is unaffected.', 'listing-moderation-for-hivepress' ),
		$explanation
	);

	printf( '<div class="notice notice-warning is-dismissible"><p>%s</p></div>', esc_html( $message ) );
}
add_action( 'admin_notices', 'hpalm_ai_health_notice' );

/**
 * Forgets a recorded connection failure whenever the API key is changed.
 *
 * Someone correcting a rejected key should not keep seeing the old
 * complaint until the next submission happens to succeed.
 */
function hpalm_clear_ai_health_on_key_change() {
	delete_transient( 'hpalm_ai_error' );
}
add_action( 'update_option_hp_openai_api_key', 'hpalm_clear_ai_health_on_key_change' );
add_action( 'add_option_hp_openai_api_key', 'hpalm_clear_ai_health_on_key_change' );

/**
 * Shows an admin notice if HivePress is not active.
 */
function hpalm_admin_notice() {
	if ( ! class_exists( '\HivePress\Core' ) && current_user_can( 'activate_plugins' ) ) {
		printf(
			'<div class="notice notice-warning"><p>%s</p></div>',
			esc_html__( 'Automated Listing Moderation for HivePress requires the HivePress plugin to be installed and activated.', 'listing-moderation-for-hivepress' )
		);
	}
}
add_action( 'admin_notices', 'hpalm_admin_notice' );

/*
 * -------------------------------------------------------------------------
 * Updates.
 *
 * The plugin is distributed via GitHub releases rather than wordpress.org,
 * so update checks go through the native `update_plugins_{$hostname}` API
 * introduced in WordPress 5.8, keyed off the Update URI header above. The
 * update package is the release asset named `*.zip`, which must contain a
 * single `listing-moderation-for-hivepress` directory. No third-party
 * library is used, and every network call fails open.
 * -------------------------------------------------------------------------
 */

const HPALM_UPDATE_REPO      = 'irapidchris-del/listing-moderation-for-hivepress';
const HPALM_UPDATE_SLUG      = 'listing-moderation-for-hivepress';
const HPALM_UPDATE_CACHE_KEY = 'hpalm_github_release';

/**
 * Gets the installed plugin version from the header.
 *
 * @return string
 */
function hpalm_get_version() {
	static $version = null;

	if ( null === $version ) {
		$data    = get_file_data( __FILE__, [ 'Version' => 'Version' ] );
		$version = $data['Version'];
	}

	return $version;
}

/**
 * Gets the latest GitHub release details, cached in a site transient.
 *
 * Successes are cached for 6 hours; failures for 1 hour, so an outage or
 * rate-limit never turns into a request-per-pageload storm.
 *
 * @param bool $force Bypass the cache.
 * @return array<string, string>|null
 */
function hpalm_get_latest_release( $force = false ) {
	$release = $force ? false : get_site_transient( HPALM_UPDATE_CACHE_KEY );

	if ( ! is_array( $release ) ) {
		$release = hpalm_fetch_latest_release();

		set_site_transient( HPALM_UPDATE_CACHE_KEY, $release, $release ? 6 * HOUR_IN_SECONDS : HOUR_IN_SECONDS );
	}

	return $release ? $release : null;
}

/**
 * Fetches the latest release details from the GitHub API.
 *
 * Draft and pre-release entries are excluded by the endpoint itself, so
 * publishing a pre-release never triggers an update notice. FAILS OPEN:
 * returns an empty array on any error, which is treated as "no update".
 *
 * @return array<string, string>
 */
function hpalm_fetch_latest_release() {
	$response = wp_remote_get(
		'https://api.github.com/repos/' . HPALM_UPDATE_REPO . '/releases/latest',
		[
			'timeout'    => 10,

			// Same privacy rule as the OpenAI call: an explicit user-agent
			// stops WordPress advertising the site URL and version to GitHub.
			'user-agent' => 'listing-moderation-for-hivepress/' . hpalm_get_version(),

			'headers'    => [ 'Accept' => 'application/vnd.github+json' ],
		]
	);

	if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
		return [];
	}

	$data = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( ! is_array( $data ) ) {
		return [];
	}

	// The version is read from the release tag, with or without a "v" prefix.
	$version = ltrim( (string) ( isset( $data['tag_name'] ) ? $data['tag_name'] : '' ), 'vV' );

	if ( ! $version ) {
		return [];
	}

	// The update package is the first release asset named `*.zip`.
	$package = '';

	foreach ( (array) ( isset( $data['assets'] ) ? $data['assets'] : [] ) as $asset ) {
		$name = strtolower( (string) ( isset( $asset['name'] ) ? $asset['name'] : '' ) );

		if ( '.zip' === substr( $name, -4 ) && ! empty( $asset['browser_download_url'] ) ) {
			$package = (string) $asset['browser_download_url'];

			break;
		}
	}

	if ( ! $package ) {
		return [];
	}

	return [
		'version'   => $version,
		'package'   => $package,
		'url'       => (string) ( isset( $data['html_url'] ) ? $data['html_url'] : 'https://github.com/' . HPALM_UPDATE_REPO ),
		'notes'     => (string) ( isset( $data['body'] ) ? $data['body'] : '' ),
		'published' => (string) ( isset( $data['published_at'] ) ? $data['published_at'] : '' ),
	];
}

/**
 * Provides the update details to the WordPress update system.
 *
 * WordPress matches the plugin to this filter via the Update URI header
 * hostname and compares the versions itself, filing the result under either
 * the available updates or the up-to-date list.
 *
 * @param array<string, mixed>|false $update Update data.
 * @param array<string, string>      $plugin_data Plugin headers.
 * @param string                     $plugin_file Plugin basename.
 * @return array<string, mixed>|false
 */
function hpalm_check_for_update( $update, $plugin_data, $plugin_file ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- signature fixed by the WordPress hook.
	if ( plugin_basename( __FILE__ ) !== $plugin_file ) {
		return $update;
	}

	$release = hpalm_get_latest_release();

	if ( ! $release ) {
		return $update;
	}

	return [
		'id'      => 'https://github.com/' . HPALM_UPDATE_REPO,
		'slug'    => HPALM_UPDATE_SLUG,
		'plugin'  => $plugin_file,
		'version' => $release['version'],
		'url'     => $release['url'],
		'package' => $release['package'],
	];
}
add_filter( 'update_plugins_github.com', 'hpalm_check_for_update', 10, 3 );

/**
 * Provides the plugin details for the "View version details" popup.
 *
 * Without this the details link on the Plugins screen would open an empty
 * modal, since the plugin is not on wordpress.org. The release body is shown
 * as the changelog, escaped.
 *
 * @param object|array|false $result Result object.
 * @param string             $action API action.
 * @param object             $args   API arguments.
 * @return object|array|false
 */
function hpalm_get_plugin_information( $result, $action, $args ) {
	if ( 'plugin_information' !== $action || ! is_object( $args ) || HPALM_UPDATE_SLUG !== ( isset( $args->slug ) ? $args->slug : '' ) ) {
		return $result;
	}

	$release = hpalm_get_latest_release();

	if ( ! $release ) {
		return $result;
	}

	$plugin_data = get_file_data(
		__FILE__,
		[
			'Name'        => 'Plugin Name',
			'Description' => 'Description',
			'Author'      => 'Author',
			'AuthorURI'   => 'Author URI',
			'RequiresWP'  => 'Requires at least',
			'RequiresPHP' => 'Requires PHP',
		]
	);

	return (object) [
		'name'          => $plugin_data['Name'],
		'slug'          => HPALM_UPDATE_SLUG,
		'version'       => $release['version'],
		'author'        => '<a href="' . esc_url( $plugin_data['AuthorURI'] ) . '">' . esc_html( $plugin_data['Author'] ) . '</a>',
		'homepage'      => 'https://github.com/' . HPALM_UPDATE_REPO,
		'requires'      => $plugin_data['RequiresWP'],
		'requires_php'  => $plugin_data['RequiresPHP'],
		'last_updated'  => $release['published'],
		'download_link' => $release['package'],

		// WordPress renders this by itself as "Donate to this plugin" in the
		// View details popup, so the third placement costs one line.
		'donate_link'   => hpalm_get_support_url(),
		'sections'      => [
			'description' => wpautop( esc_html( $plugin_data['Description'] ) ),
			'changelog'   => $release['notes'] ? wpautop( esc_html( $release['notes'] ) ) : '<p>' . esc_html__( 'See the GitHub releases page for the changelog.', 'listing-moderation-for-hivepress' ) . '</p>',
		],
	];
}
add_filter( 'plugins_api', 'hpalm_get_plugin_information', 10, 3 );

/**
 * Adds the Settings and "Check for updates" links to the plugin row.
 *
 * The Settings link deep-links to the Listings tab of the HivePress
 * settings screen, where the Automated Moderation section lives, and is
 * only added when HivePress is active (the screen does not exist without
 * it) and outside the network admin (HivePress settings are per site).
 *
 * @param array<string> $links Plugin action links.
 * @return array<string>
 */
function hpalm_add_action_links( $links ) {
	if ( ! is_network_admin() && class_exists( '\HivePress\Core' ) && current_user_can( 'manage_options' ) ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=hp_settings&tab=listings' ) ) . '">' . esc_html__( 'Settings', 'listing-moderation-for-hivepress' ) . '</a>' );
	}

	if ( current_user_can( 'update_plugins' ) ) {
		$links[] = '<a href="' . esc_url( wp_nonce_url( self_admin_url( 'plugins.php?hpalm_check_updates=1' ), 'hpalm_check_updates' ) ) . '">' . esc_html__( 'Check for updates', 'listing-moderation-for-hivepress' ) . '</a>';
	}

	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'hpalm_add_action_links' );
add_filter( 'network_admin_plugin_action_links_' . plugin_basename( __FILE__ ), 'hpalm_add_action_links' );

/**
 * The author's support page.
 *
 * One place, so the Plugins row and the View details popup can never drift
 * apart.
 *
 * @return string
 */
function hpalm_get_support_url() {
	return 'https://ko-fi.com/chrisbathivepresscommunity';
}

/**
 * Adds the house "Donate" link to this plugin's row on the Plugins screen.
 *
 * WordPress fires plugin_row_meta for EVERY plugin on the screen, so without the basename
 * test the link would appear on every row on the site. The markup is copied verbatim from
 * the house spec in `releasing.md` rather than composed here: every plugin's row has to look
 * identical and sessions have drifted before. The label is exactly "Donate", matching the
 * wording WordPress itself uses in the details popup, and the icon is a Dashicon rather than
 * Font Awesome because Dashicons is the admin's own font and is always loaded there.
 * WordPress joins row-meta items with " | " itself, so this returns a bare anchor.
 *
 * @param array<string> $meta        Row meta links.
 * @param string        $plugin_file Plugin file the row belongs to.
 * @return array<string>
 */
function hpalm_add_row_meta( $meta, $plugin_file ) {
	if ( plugin_basename( __FILE__ ) === $plugin_file ) {
		$meta[] = '<a href="' . esc_url( hpalm_get_support_url() ) . '" target="_blank" rel="noopener noreferrer">'
			. '<span class="dashicons dashicons-star-filled" style="font-size:14px;line-height:1.3;"></span> '
			. esc_html__( 'Donate', 'listing-moderation-for-hivepress' )
			. '</a>';
	}

	return $meta;
}

add_filter( 'plugin_row_meta', 'hpalm_add_row_meta', 10, 2 );

/**
 * Handles the manual "Check for updates" click.
 *
 * Refreshes the cached release, re-runs the update check and redirects back
 * to the Plugins screen with the result.
 */
function hpalm_handle_update_check() {
	if ( ! isset( $_GET['hpalm_check_updates'] ) || ! current_user_can( 'update_plugins' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce is verified on the next line before anything happens.
		return;
	}

	check_admin_referer( 'hpalm_check_updates' );

	$release = hpalm_get_latest_release( true );

	wp_clean_plugins_cache();
	wp_update_plugins();

	$status = 'none';

	if ( ! $release ) {
		$status = 'error';
	} elseif ( version_compare( $release['version'], hpalm_get_version(), '>' ) ) {
		$status = 'available';
	}

	wp_safe_redirect( add_query_arg( 'hpalm_checked', $status, self_admin_url( 'plugins.php' ) ) );

	exit;
}
add_action( 'admin_init', 'hpalm_handle_update_check' );

/**
 * Shows the manual update-check result as an admin notice.
 */
function hpalm_show_update_check_notice() {
	if ( ! isset( $_GET['hpalm_checked'] ) || ! current_user_can( 'update_plugins' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only status flag set by our own post-check redirect; no action is taken on it.
		return;
	}

	// Same string guard as the settings-screen check: $_GET values can be
	// arrays, and only a plain string can match a known status.
	if ( ! is_string( $_GET['hpalm_checked'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- see above.
		return;
	}

	$status = sanitize_key( wp_unslash( $_GET['hpalm_checked'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- see above.

	if ( 'available' === $status ) {
		$release = hpalm_get_latest_release();

		/* translators: %s: the new version number. */
		$message = sprintf( esc_html__( 'A new version of Automated Listing Moderation for HivePress (%s) is available.', 'listing-moderation-for-hivepress' ), esc_html( $release ? $release['version'] : '' ) );
		$class   = 'notice-success';
	} elseif ( 'none' === $status ) {
		$message = esc_html__( 'Automated Listing Moderation for HivePress is up to date.', 'listing-moderation-for-hivepress' );
		$class   = 'notice-success';
	} elseif ( 'error' === $status ) {
		$message = esc_html__( 'Could not check for updates: GitHub did not return a published release for this plugin. If this keeps happening, check the plugin has been released on GitHub, or try again later.', 'listing-moderation-for-hivepress' );
		$class   = 'notice-error';
	} else {
		return;
	}

	echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
}
add_action( 'admin_notices', 'hpalm_show_update_check_notice' );
add_action( 'network_admin_notices', 'hpalm_show_update_check_notice' );

/**
 * Keeps updates installing into the current plugin directory.
 *
 * The extracted release folder is renamed to match the directory the plugin
 * is installed in, so an update can never land in a differently named folder
 * even if the release zip is packaged unexpectedly.
 *
 * @param string               $source        Extracted update source.
 * @param string               $remote_source Remote source directory.
 * @param object               $upgrader      Upgrader instance.
 * @param array<string, mixed> $hook_extra    Extra hook arguments.
 * @return string|WP_Error
 */
function hpalm_fix_update_directory( $source, $remote_source, $upgrader, $hook_extra = [] ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- signature fixed by the WordPress hook.
	global $wp_filesystem;

	if ( plugin_basename( __FILE__ ) !== ( isset( $hook_extra['plugin'] ) ? $hook_extra['plugin'] : '' ) || ! $wp_filesystem ) {
		return $source;
	}

	$directory = dirname( plugin_basename( __FILE__ ) );

	if ( '.' === $directory ) {
		return $source;
	}

	$target = trailingslashit( $remote_source ) . $directory . '/';

	if ( trailingslashit( $source ) === $target ) {
		return $source;
	}

	if ( ! $wp_filesystem->move( untrailingslashit( $source ), untrailingslashit( $target ) ) ) {
		return new WP_Error( 'hpalm_rename_failed', esc_html__( 'Could not rename the update directory.', 'listing-moderation-for-hivepress' ) );
	}

	return $target;
}
add_filter( 'upgrader_source_selection', 'hpalm_fix_update_directory', 10, 4 );
