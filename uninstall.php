<?php
/**
 * Uninstall cleanup — only when plugin is deleted from WP admin.
 *
 * @package SEOAuto\SEOHelper
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$prefix  = 'seoauto_helper_';
$options = array(
	'api_base',
	'connection_id',
	'site_id',
	'site_secret',
	'organization_id',
	'domain',
	'paired_at',
	'status',
	'entitlement_json',
	'entitlement_sig',
	'last_sync_at',
	'last_error',
	'last_check_at',
	'last_check_ok',
	'last_check_message',
	'last_firewall_block',
	'last_entitlement_check_at',
	'last_entitlement_check_source',
	'lock_reason',
	'network_grace_until',
	'connectivity_state',
	'last_entitlement_was_active',
	'last_api_error',
	'audit_log_retention_days',
	'allowed_post_types',
	'update_check_cache',
	'update_channel',
);

foreach ( $options as $key ) {
	delete_option( $prefix . $key );
}

delete_option( $prefix . 'audit_log' );
delete_option( $prefix . 'used_nonces' );
delete_option( $prefix . 'db_version' );
wp_clear_scheduled_hook( 'seoauto_helper_sync_entitlement' );
wp_clear_scheduled_hook( 'seoauto_helper_process_audit_jobs' );

global $wpdb;
if ( isset( $wpdb ) && is_object( $wpdb ) ) {
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'seoauto_helper_idempotency' );
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'seoauto_helper_article_map' );
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'seoauto_helper_media_map' );
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'seoauto_helper_audit_runs' );
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'seoauto_helper_audit_issues' );
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'seoauto_helper_jobs' );
}
