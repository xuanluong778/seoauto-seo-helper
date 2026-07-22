<?php
/**
 * Lifecycle simulation: activate defaults, upgrade schema, deactivate, uninstall cleanup.
 *
 * Run: php tests/test_lifecycle.php
 *
 * @package SEOAuto\SEOHelper
 */

declare(strict_types=1);

define( 'SEOAUTO_HELPER_PREFIX', 'seoauto_helper_' );
define( 'SEOAUTO_HELPER_VERSION', '1.0.0-test' );
define( 'SEOAUTO_HELPER_BASENAME', 'seoauto-seo-helper/seoauto-seo-helper.php' );
define( 'ABSPATH', __DIR__ . '/stubs/' );
define( 'HOUR_IN_SECONDS', 3600 );

$GLOBALS['seoauto_test_options'] = array();
$GLOBALS['seoauto_test_cron']    = array();
$GLOBALS['seoauto_test_tables']  = array(
	'wp_seoauto_helper_idempotency' => true,
	'wp_seoauto_helper_article_map'  => true,
	'wp_seoauto_helper_media_map'    => true,
	'wp_seoauto_helper_audit_runs'   => true,
	'wp_seoauto_helper_audit_issues' => true,
	'wp_seoauto_helper_jobs'         => true,
);

function get_option( string $name, $default = false ) {
	return $GLOBALS['seoauto_test_options'][ $name ] ?? $default;
}
function add_option( string $name, $value, string $deprecated = '', bool $autoload = true ): bool {
	if ( isset( $GLOBALS['seoauto_test_options'][ $name ] ) ) {
		return false;
	}
	$GLOBALS['seoauto_test_options'][ $name ] = $value;
	return true;
}
function update_option( string $name, $value, bool $autoload = null ): bool {
	$GLOBALS['seoauto_test_options'][ $name ] = $value;
	return true;
}
function delete_option( string $name ): bool {
	unset( $GLOBALS['seoauto_test_options'][ $name ] );
	return true;
}
function wp_next_scheduled( string $hook ) {
	return $GLOBALS['seoauto_test_cron'][ $hook ] ?? false;
}
function wp_schedule_event( int $timestamp, string $recurrence, string $hook ): bool {
	$GLOBALS['seoauto_test_cron'][ $hook ] = $timestamp;
	return true;
}
function wp_unschedule_event( int $timestamp, string $hook ): void {
	unset( $GLOBALS['seoauto_test_cron'][ $hook ] );
}
function wp_clear_scheduled_hook( string $hook ): void {
	unset( $GLOBALS['seoauto_test_cron'][ $hook ] );
}
function flush_rewrite_rules( bool $hard = true ): void {}
function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool { return true; }
function esc_html__( string $text, string $domain = 'default' ): string { return $text; }
function deactivate_plugins( string $plugin ): void {}

$wpdb         = new class() {
	public string $prefix  = 'wp_';
	public string $options = 'wp_options';
	public function get_charset_collate(): string { return ''; }
	public function prepare( string $query, ...$args ): string { return $query; }
	public function get_var( string $query ) {
		return null;
	}
	public function query( string $sql ): int {
		foreach ( array_keys( $GLOBALS['seoauto_test_tables'] ) as $table ) {
			if ( str_contains( $sql, "DROP TABLE IF EXISTS {$table}" ) ) {
				unset( $GLOBALS['seoauto_test_tables'][ $table ] );
			}
		}
		return 1;
	}
};
global $wpdb;

require_once dirname( __DIR__ ) . '/includes/Post/Schema.php';
require_once dirname( __DIR__ ) . '/includes/Cron/Cron_Scheduler.php';
require_once dirname( __DIR__ ) . '/includes/Activator.php';
require_once dirname( __DIR__ ) . '/includes/Deactivator.php';

use SEOAuto\SEOHelper\Activator;
use SEOAuto\SEOHelper\Cron\Cron_Scheduler;
use SEOAuto\SEOHelper\Deactivator;
use SEOAuto\SEOHelper\Post\Schema;

$failed = 0;
function check( string $msg, callable $fn ): void {
	global $failed;
	$ok = (bool) $fn();
	echo ( $ok ? 'PASS' : 'FAIL' ) . "  {$msg}\n";
	if ( ! $ok ) {
		++$failed;
	}
}

check(
	'activate sets defaults and schedules cron',
	static function (): bool {
		Activator::activate();
		return get_option( 'seoauto_helper_api_base' ) === 'https://seoauto.vn'
			&& wp_next_scheduled( Cron_Scheduler::HOOK_SYNC ) !== false
			&& wp_next_scheduled( 'seoauto_helper_process_audit_jobs' ) !== false
			&& (int) get_option( 'seoauto_helper_db_version', 0 ) === Schema::DB_VERSION;
	}
);

check(
	'maybe_upgrade is idempotent',
	static function (): bool {
		Schema::maybe_upgrade();
		Schema::maybe_upgrade();
		return (int) get_option( 'seoauto_helper_db_version', 0 ) === Schema::DB_VERSION;
	}
);

check(
	'deactivate clears cron but keeps options',
	static function (): bool {
		update_option( 'seoauto_helper_site_id', 'keep-me' );
		Deactivator::deactivate();
		return wp_next_scheduled( Cron_Scheduler::HOOK_SYNC ) === false
			&& wp_next_scheduled( 'seoauto_helper_process_audit_jobs' ) === false
			&& get_option( 'seoauto_helper_site_id' ) === 'keep-me';
	}
);

// Simulate uninstall.php
$prefix  = 'seoauto_helper_';
$options = array( 'api_base', 'site_id', 'site_secret', 'status', 'entitlement_json', 'entitlement_sig', 'audit_log' );
foreach ( $options as $key ) {
	delete_option( $prefix . $key );
}
wp_clear_scheduled_hook( 'seoauto_helper_sync_entitlement' );
wp_clear_scheduled_hook( 'seoauto_helper_process_audit_jobs' );
global $wpdb;
$wpdb->query( 'DROP TABLE IF EXISTS wp_seoauto_helper_idempotency' );
$wpdb->query( 'DROP TABLE IF EXISTS wp_seoauto_helper_article_map' );
$wpdb->query( 'DROP TABLE IF EXISTS wp_seoauto_helper_media_map' );
$wpdb->query( 'DROP TABLE IF EXISTS wp_seoauto_helper_audit_runs' );
$wpdb->query( 'DROP TABLE IF EXISTS wp_seoauto_helper_audit_issues' );
$wpdb->query( 'DROP TABLE IF EXISTS wp_seoauto_helper_jobs' );

check(
	'uninstall removes options and tables',
	static function (): bool {
		return get_option( 'seoauto_helper_site_id', false ) === false
			&& empty( $GLOBALS['seoauto_test_tables'] );
	}
);

exit( $failed > 0 ? 1 : 0 );
