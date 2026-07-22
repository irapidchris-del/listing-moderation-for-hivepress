<?php
/**
 * Uninstall routine for Automated Listing Moderation for HivePress.
 *
 * Removes all options and hidden fingerprint meta created by this plugin.
 * Settings-screen fields are persisted by HivePress as options prefixed
 * with hp_.
 *
 * @package Automated_Listing_Moderation_For_HivePress
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'hp_listing_blocked_keywords' );
delete_option( 'hp_listing_blocked_patterns' );
delete_option( 'hp_listing_block_evasion' );
delete_option( 'hp_listing_block_phones' );
delete_option( 'hp_listing_block_emails' );
delete_option( 'hp_listing_block_urls' );
delete_option( 'hp_listing_check_duplicate_titles' );
delete_option( 'hp_listing_check_duplicate_descriptions' );
delete_option( 'hp_listing_block_ai' );
delete_option( 'hp_listing_score_caps' );
delete_option( 'hp_listing_risk_threshold' );
delete_option( 'hp_listing_bypass_verified_vendors' );
delete_option( 'hp_listing_velocity_limit' );
delete_option( 'hp_listing_block_ai_images' );

/*
 * The OpenAI key (hp_openai_api_key) is deliberately NOT deleted. The option
 * name is generic so a single key can be shared with any other OpenAI
 * integration on the site, and deleting a credential another plugin may rely
 * on would be a destructive side effect. Clear the field on the Integrations
 * tab before uninstalling if you want the key removed.
 */
delete_option( 'hpalm_hashes_backfilled' );

delete_post_meta_by_key( '_hpalm_title_hash' );
delete_post_meta_by_key( '_hpalm_desc_hash' );
delete_post_meta_by_key( '_hpalm_flagged' );
delete_post_meta_by_key( '_hpalm_score' );
delete_post_meta_by_key( '_hpalm_signals' );
delete_post_meta_by_key( '_hpalm_threshold' );
