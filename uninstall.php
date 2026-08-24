<?php
/**
 * Uninstall routine for Automated Listing Moderation for HivePress.
 *
 * RETAINS the owner's settings and moderation records by default: a plugin
 * deleted by accident, or removed to reinstall a clean copy, must give the
 * owner everything back. Destruction is opt-in through the "Delete all data
 * when this plugin is deleted" checkbox on the settings screen (a delete-time
 * prompt is not possible: the wp-admin delete-confirmation form is hard-coded
 * with no hooks, verified against WP 7.0.2). WordPress's own delete-screen
 * warning claims data will be removed whenever an uninstall.php exists; the
 * setting's description tells owners that warning does not apply here.
 *
 * What retain still cleans: regenerable runtime values only - the cached
 * GitHub release lookup and the recorded AI-connection status. Everything
 * the owner configured or the plugin recorded about their listings
 * survives.
 *
 * @package Automated_Listing_Moderation_For_HivePress
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Regenerable runtime cache: goes on every uninstall, kept on none.
delete_site_transient( 'hpalm_github_release' );

/*
 * The updater's other two site transients and its background job, which used to be left behind.
 *
 * All three are regenerable runtime state belonging to the update check, not the owner's
 * configuration, so they go unconditionally alongside the release cache above. Core's daily sweep
 * clears expired site transients within about a day on single-site, which is why this read as
 * harmless; on multisite they live in wp_sitemeta and are only purged when something asks for
 * them, so on a network they simply stay. The scheduled refresh is worse than debris: it is a job
 * whose callback no longer exists.
 *
 * Unscheduled from both places it can be, because the refresh is queued through HivePress's
 * scheduler (Action Scheduler) when HivePress is present and through WP-Cron when it is not.
 */
delete_site_transient( 'hpalm_github_release_reason' );
delete_site_transient( 'hpalm_github_release_rate_limit' );

if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( 'hpalm_github_release_refresh', [], 'hivepress' );
	as_unschedule_all_actions( 'hpalm_github_release_refresh' );
}

wp_clear_scheduled_hook( 'hpalm_github_release_refresh' );
delete_transient( 'hpalm_ai_error' );

if ( ! get_option( 'hp_alm_delete_data' ) ) {
	return;
}

/*
 * The owner ticked "Delete all data": remove everything the plugin ever
 * created, under both the 1.5.0+ option names and the 1.4.x generation
 * (hp_listing_*), so an install that never migrated is cleaned too.
 *
 * The hp_alm_delete_data flag itself is deleted LAST: if this file fails
 * part-way, the flag is still set, so deleting the plugin again finishes
 * the job instead of silently reverting to "retain" with half the data
 * gone.
 */
$hpalm_option_names = [
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

foreach ( $hpalm_option_names as $hpalm_name ) {
	delete_option( 'hp_alm_' . $hpalm_name );
	delete_option( 'hp_listing_' . $hpalm_name );
}

delete_option( 'hpalm_hashes_backfilled' );
delete_option( 'hpalm_options_migrated' );

/*
 * The OpenAI key (hp_openai_api_key) is NOT deleted even here: the option
 * name is deliberately generic so one key can be shared with any other
 * OpenAI integration on the site, and deleting a credential another plugin
 * may rely on would be a destructive side effect beyond this plugin's own
 * data. The setting's description says so. Clear the field on the
 * Integrations tab to remove the key itself.
 */

delete_post_meta_by_key( '_hpalm_title_hash' );
delete_post_meta_by_key( '_hpalm_desc_hash' );
delete_post_meta_by_key( '_hpalm_flagged' );
delete_post_meta_by_key( '_hpalm_score' );
delete_post_meta_by_key( '_hpalm_signals' );
delete_post_meta_by_key( '_hpalm_threshold' );
delete_post_meta_by_key( '_hpalm_block_signal' );

// Last, deliberately - see the note above.
delete_option( 'hp_alm_delete_data' );
