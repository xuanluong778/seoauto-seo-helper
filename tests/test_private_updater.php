<?php
/**
 * Private updater unit tests.
 *
 * Run: php tests/test_private_updater.php
 *
 * @package SEOAuto\SEOHelper
 */

declare(strict_types=1);

$failed = 0;
function check( string $msg, bool $ok ): void {
	global $failed;
	echo ( $ok ? 'PASS' : 'FAIL' ) . "  {$msg}\n";
	if ( ! $ok ) {
		++$failed;
	}
}

define( 'SEOAUTO_HELPER_VERSION', '1.0.4' );
define( 'SEOAUTO_HELPER_PREFIX', 'seoauto_helper_' );
define( 'SEOAUTO_HELPER_BASENAME', 'seoauto-seo-helper/seoauto-seo-helper.php' );
define( 'HOUR_IN_SECONDS', 3600 );

if ( ! function_exists( '__' ) ) {
	function __( $t, $d = '' ) { // phpcs:ignore
		return $t;
	}
}
if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $u ) { // phpcs:ignore
		return (string) $u;
	}
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $k ) { // phpcs:ignore
		return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $k ) ?? '' );
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $t, $v ) { // phpcs:ignore
		return $v;
	}
}
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) { // phpcs:ignore
		$p = parse_url( (string) $url );
		if ( $component === -1 ) {
			return $p ?: array();
		}
		$map = array( PHP_URL_SCHEME => 'scheme', PHP_URL_HOST => 'host', PHP_URL_PATH => 'path' );
		$key = $map[ $component ] ?? null;
		return $key ? ( $p[ $key ] ?? null ) : null;
	}
}
if ( ! class_exists( 'WP_Error', false ) ) {
	class WP_Error {
		public function __construct( public $code = '', public $message = '', public $data = array() ) {}
		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->message; }
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $t ) { // phpcs:ignore
		return $t instanceof WP_Error;
	}
}

spl_autoload_register(
	static function ( string $class ): void {
		$prefix = 'SEOAuto\\SEOHelper\\';
		if ( ! str_starts_with( $class, $prefix ) ) {
			return;
		}
		$file = dirname( __DIR__ ) . '/includes/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

use SEOAuto\SEOHelper\Updater\Package_Verifier;
use SEOAuto\SEOHelper\Updater\Update_Manager;
use SEOAuto\SEOHelper\Updater\Update_Response;

$v = new Package_Verifier();

echo "=== Version compare / anti-downgrade ===\n";
check( '1.1.0 newer than 1.0.4', true === $v->assert_newer_version( '1.0.4', '1.1.0' ) );
check( 'same version rejected', is_wp_error( $v->assert_newer_version( '1.1.0', '1.1.0' ) ) );
check( 'downgrade rejected', is_wp_error( $v->assert_newer_version( '1.1.0', '1.0.4' ) ) );

echo "\n=== Package URL safety ===\n";
check( 'https seoauto.vn ok', true === $v->assert_safe_package_url( 'https://seoauto.vn/api/wordpress-plugin/updates/download/abc' ) );
check( 'http rejected', is_wp_error( $v->assert_safe_package_url( 'http://seoauto.vn/x.zip' ) ) );
check( 'evil host rejected', is_wp_error( $v->assert_safe_package_url( 'https://evil.example/x.zip' ) ) );

echo "\n=== SHA-256 ===\n";
$tmp = tempnam( sys_get_temp_dir(), 'seoauto' );
file_put_contents( $tmp, 'hello-update' );
$sha = hash( 'sha256', 'hello-update' );
check( 'sha matches', true === $v->assert_sha256_file( $tmp, $sha ) );
check( 'sha mismatch', is_wp_error( $v->assert_sha256_file( $tmp, str_repeat( 'a', 64 ) ) ) );

echo "\n=== Release signature ===\n";
$secret  = 'site-secret-test';
$expires = gmdate( 'c', time() + 600 );
$sig     = hash_hmac( 'sha256', '1.1.0|' . $sha . '|' . $expires, $secret );
check( 'signature ok', true === $v->assert_release_signature( $sig, $secret, '1.1.0', $sha, $expires ) );
check( 'bad signature', is_wp_error( $v->assert_release_signature( 'deadbeef', $secret, '1.1.0', $sha, $expires ) ) );
$past = gmdate( 'c', time() - 10 );
$sig2 = hash_hmac( 'sha256', '1.1.0|' . $sha . '|' . $past, $secret );
check( 'expired url', is_wp_error( $v->assert_release_signature( $sig2, $secret, '1.1.0', $sha, $past ) ) );

echo "\n=== ZIP structure ===\n";
if ( class_exists( 'ZipArchive' ) ) {
	$zip_path = $tmp . '.zip';
	$zip      = new ZipArchive();
	$zip->open( $zip_path, ZipArchive::CREATE );
	$zip->addFromString( 'seoauto-seo-helper/seoauto-seo-helper.php', '<?php // plugin' );
	$zip->close();
	check( 'good zip structure', true === $v->assert_zip_structure( $zip_path ) );

	$bad = $tmp . '-bad.zip';
	$zip = new ZipArchive();
	$zip->open( $bad, ZipArchive::CREATE );
	$zip->addFromString( 'wrong-root/seoauto-seo-helper.php', 'x' );
	$zip->close();
	check( 'bad zip structure', is_wp_error( $v->assert_zip_structure( $bad ) ) );
	@unlink( $zip_path );
	@unlink( $bad );
} else {
	check( 'ZipArchive skipped', true );
}

echo "\n=== Update_Response WP object ===\n";
$none = Update_Response::from_array( array( 'update_available' => false ) );
check( 'no update → false', false === $none->to_wp_update_object() );

$yes = Update_Response::from_array(
	array(
		'update_available' => true,
		'version'          => '1.1.0',
		'package'          => 'https://seoauto.vn/api/wordpress-plugin/updates/download/t',
		'requires_wp'      => '6.0',
		'requires_php'     => '8.1',
		'tested'           => '6.7',
		'sha256'           => $sha,
		'channel'          => 'stable',
	)
);
$obj = $yes->to_wp_update_object();
check( 'update object new_version', is_object( $obj ) && $obj->new_version === '1.1.0' );
check( 'update object package set', is_object( $obj ) && $obj->package !== '' );

@unlink( $tmp );

echo "\n=== Update channel resolve ===\n";
check( 'explicit stable wins', 'stable' === Update_Manager::resolve_channel( 'stable', '1.2.0-rc.3' ) );
check( 'explicit beta wins', 'beta' === Update_Manager::resolve_channel( 'beta', '1.2.0' ) );
check( 'empty + rc → beta', 'beta' === Update_Manager::resolve_channel( '', '1.2.0-rc.3' ) );
check( 'empty + stable → stable', 'stable' === Update_Manager::resolve_channel( '', '1.2.0' ) );
check( 'is_prerelease rc.4', true === Update_Manager::is_prerelease_version( '1.2.0-rc.4' ) );
check( 'is_prerelease stable', false === Update_Manager::is_prerelease_version( '1.2.0' ) );

echo $failed === 0 ? "\nALL PASS\n" : "\nFAILED: {$failed}\n";
exit( $failed > 0 ? 1 : 0 );
