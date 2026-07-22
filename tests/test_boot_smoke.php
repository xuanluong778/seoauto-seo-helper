<?php
/**
 * Smoke test: bootstrap Plugin like WordPress plugins_loaded.
 */
declare(strict_types=1);

define('ABSPATH', __DIR__ . '/stubs/');
define('SEOAUTO_HELPER_VERSION', '1.0.0-test');
define('SEOAUTO_HELPER_FILE', dirname(__DIR__) . '/seoauto-seo-helper.php');
define('SEOAUTO_HELPER_PATH', dirname(__DIR__) . '/');
define('SEOAUTO_HELPER_URL', 'http://example.com/wp-content/plugins/seoauto-seo-helper/');
define('SEOAUTO_HELPER_BASENAME', 'seoauto-seo-helper/seoauto-seo-helper.php');
define('HOUR_IN_SECONDS', 3600);
define('DAY_IN_SECONDS', 86400);
define('AUTH_KEY', 'test-key');
define('SECURE_AUTH_KEY', 'test-secure');
define('AUTH_SALT', 'test-salt');
define('SECURE_AUTH_SALT', 'test-secure-salt');

$GLOBALS['seoauto_test_options'] = array();
function get_option($n, $d = false) { return $GLOBALS['seoauto_test_options'][$n] ?? $d; }
function add_option($n, $v, $x = '', $a = true) { $GLOBALS['seoauto_test_options'][$n] = $v; return true; }
function update_option($n, $v, $a = null) { $GLOBALS['seoauto_test_options'][$n] = $v; return true; }
function delete_option($n) { unset($GLOBALS['seoauto_test_options'][$n]); return true; }
function load_plugin_textdomain() {}
function add_action($h, $c, $p = 10, $a = 1) {}
function add_filter($h, $c, $p = 10, $a = 1) {}
function register_rest_route() {}
function plugin_dir_path($f) { return dirname($f) . '/'; }
function plugin_dir_url($f) { return 'http://example.com/wp-content/plugins/seoauto-seo-helper/'; }
function plugin_basename($f) { return 'seoauto-seo-helper/seoauto-seo-helper.php'; }
function register_activation_hook($f, $c) {}
function register_deactivation_hook($f, $c) {}
function __( $t, $d = '' ) { return $t; }
function esc_html__( $t, $d = '' ) { return $t; }
function esc_html($t) { return $t; }
function esc_attr($t) { return $t; }
function esc_url($t) { return $t; }
function esc_url_raw($t) { return $t; }
function esc_js($t) { return $t; }
function sanitize_key($k) { return $k; }
function sanitize_text_field($s) { return (string)$s; }
function wp_json_encode($d) { return json_encode($d); }
function untrailingslashit($s) { return rtrim($s, '/'); }
function is_ssl() { return true; }
function is_admin() { return true; }
function current_user_can($c) { return true; }
function wp_next_scheduled() { return false; }
function wp_schedule_event() { return true; }
function wp_clear_scheduled_hook() {}
function get_bloginfo($w) { return '6.7'; }
function home_url($p = '') { return 'https://example.com' . $p; }
function get_site_url() { return 'https://example.com'; }
function flush_rewrite_rules() {}
function deactivate_plugins() {}
function wp_die() { throw new RuntimeException('wp_die'); }

$GLOBALS['wpdb'] = new class() {
	public string $prefix = 'wp_';
	public function get_charset_collate(): string { return ''; }
	public function get_var( $q ) { return null; }
	public function query( $q ) { return 1; }
};
function dbDelta( $sql ) {}

require_once dirname(__DIR__) . '/seoauto-seo-helper.php';

use SEOAuto\SEOHelper\Plugin;
use SEOAuto\SEOHelper\Activator;

echo "Activating...\n";
Activator::activate();
echo "Booting...\n";
Plugin::instance()->boot();
echo "OK\n";
