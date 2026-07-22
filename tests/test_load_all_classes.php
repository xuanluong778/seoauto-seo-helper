<?php
/**
 * Load every plugin class file to detect parse/fatal errors.
 */
declare(strict_types=1);

define('ABSPATH', __DIR__ . '/stubs/');
define('SEOAUTO_HELPER_VERSION', '1.0.3-test');
define('SEOAUTO_HELPER_FILE', dirname(__DIR__) . '/seoauto-seo-helper.php');
define('SEOAUTO_HELPER_PATH', dirname(__DIR__) . '/');
define('SEOAUTO_HELPER_URL', 'http://example.com/wp-content/plugins/seoauto-seo-helper/');
define('SEOAUTO_HELPER_BASENAME', 'seoauto-seo-helper/seoauto-seo-helper.php');
define('SEOAUTO_HELPER_PREFIX', 'seoauto_helper_');
define('HOUR_IN_SECONDS', 3600);
define('DAY_IN_SECONDS', 86400);
define('AUTH_KEY', 'k');
define('SECURE_AUTH_KEY', 'k');
define('AUTH_SALT', 's');
define('SECURE_AUTH_SALT', 's');

$GLOBALS['seoauto_test_options'] = array();
$GLOBALS['wpdb'] = new class() {
	public string $prefix = 'wp_';
	public string $options = 'wp_options';
	public function get_charset_collate(): string { return ''; }
	public function prepare( string $q, ...$a ): string { return $q; }
	public function get_var( $q ) { return null; }
	public function query( $q ) { return 1; }
	public function update( ...$a ) { return 1; }
};

function get_option( $n, $d = false ) { return $GLOBALS['seoauto_test_options'][ $n ] ?? $d; }
function add_option( $n, $v, $x = '', $a = true ) { $GLOBALS['seoauto_test_options'][ $n ] = $v; return true; }
function update_option( $n, $v, $a = null ) { $GLOBALS['seoauto_test_options'][ $n ] = $v; return true; }
function delete_option( $n ) { unset( $GLOBALS['seoauto_test_options'][ $n ] ); return true; }
function add_action( ...$a ) {}
function add_filter( ...$a ) {}
function register_rest_route( ...$a ) {}
function load_plugin_textdomain( ...$a ) {}
function wp_next_scheduled( ...$a ) { return false; }
function wp_schedule_event( ...$a ) { return true; }
function wp_clear_scheduled_hook( ...$a ) {}
function flush_rewrite_rules( ...$a ) {}
function deactivate_plugins( ...$a ) {}
function esc_html__( $t, $d = '' ) { return $t; }
function esc_html( $t ) { return $t; }
function __( $t, $d = '' ) { return $t; }
function sanitize_key( $k ) { return $k; }
function sanitize_text_field( $s ) { return (string) $s; }
function wp_json_encode( $d ) { return json_encode( $d ); }
function untrailingslashit( $s ) { return rtrim( $s, '/' ); }
function is_ssl() { return true; }
function is_admin() { return true; }
function current_user_can( $c ) { return true; }
function get_bloginfo( $w ) { return '6.7'; }
function home_url( $p = '' ) { return 'https://example.com' . $p; }
function plugin_dir_path( $f ) { return dirname( $f ) . '/'; }
function plugin_dir_url( $f ) { return 'http://example.com/wp-content/plugins/seoauto-seo-helper/'; }
function plugin_basename( $f ) { return 'seoauto-seo-helper/seoauto-seo-helper.php'; }
function dbDelta( $sql ) {}

require_once dirname(__DIR__) . '/tests/stubs/wp-admin/includes/upgrade.php';
function register_activation_hook( ...$a ) {}
function register_deactivation_hook( ...$a ) {}
function wp_die( ...$a ) { throw new RuntimeException( 'wp_die' ); }

require_once dirname(__DIR__) . '/seoauto-seo-helper.php';

$classes = array(
	'SEOAuto\\SEOHelper\\Activator',
	'SEOAuto\\SEOHelper\\Deactivator',
	'SEOAuto\\SEOHelper\\Plugin',
	'SEOAuto\\SEOHelper\\Connection\\Connection_Manager',
	'SEOAuto\\SEOHelper\\Entitlement\\Entitlement_Manager',
	'SEOAuto\\SEOHelper\\Entitlement\\Entitlement_Client',
	'SEOAuto\\SEOHelper\\Entitlement\\Entitlement_Verifier',
	'SEOAuto\\SEOHelper\\Audit\\Audit_Logger',
	'SEOAuto\\SEOHelper\\Auth\\Request_Authenticator',
	'SEOAuto\\SEOHelper\\Auth\\Hmac_Signer',
	'SEOAuto\\SEOHelper\\Post\\Post_Service',
	'SEOAuto\\SEOHelper\\Post\\Idempotency_Store',
	'SEOAuto\\SEOHelper\\Post\\Schema',
	'SEOAuto\\SEOHelper\\Rest\\Rest_Controller',
	'SEOAuto\\SEOHelper\\Seo\\Seo_Facade',
	'SEOAuto\\SEOHelper\\Cron\\Cron_Scheduler',
	'SEOAuto\\SEOHelper\\Admin\\Admin_Menu',
);

$failed = 0;
foreach ( $classes as $class ) {
	if ( ! class_exists( $class, true ) ) {
		echo "FAIL missing class: $class\n";
		++$failed;
		continue;
	}
	echo "OK   $class\n";
}

echo "\n--- activate ---\n";
SEOAuto\SEOHelper\Activator::activate_safe();

echo "--- boot ---\n";
SEOAuto\SEOHelper\Plugin::instance()->boot();

echo $failed === 0 ? "\nALL OK\n" : "\nFAILED: $failed\n";
exit( $failed > 0 ? 1 : 0 );
