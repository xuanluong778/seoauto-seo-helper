<?php
/**
 * Media security smoke tests — SSRF IP blocks, MIME/filename guards.
 *
 * Run: php tests/test_media_security.php
 *
 * @package SEOAuto\SEOHelper
 */

declare(strict_types=1);

$failed = 0;

function check( string $msg, bool $ok ): void {
	global $failed;
	if ( $ok ) {
		echo "PASS  {$msg}\n";
		return;
	}
	++$failed;
	echo "FAIL  {$msg}\n";
}

if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value ) { // phpcs:ignore
		return $value;
	}
}
if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $url ) { // phpcs:ignore
		return filter_var( (string) $url, FILTER_SANITIZE_URL ) ?: '';
	}
}
if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = null ) { // phpcs:ignore
		return $text;
	}
}
if ( ! class_exists( 'WP_Error', false ) ) {
	class WP_Error {
		public function __construct( public $code = '', public $message = '', public $data = array() ) {}
		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->message; }
		public function get_error_data() { return $this->data; }
	}
}
if ( ! defined( 'SEOAUTO_HELPER_VERSION' ) ) {
	define( 'SEOAUTO_HELPER_VERSION', '1.0.0-test' );
}

require_once dirname( __DIR__ ) . '/includes/Media/Url_Safety.php';
require_once dirname( __DIR__ ) . '/includes/Media/Mime_Guard.php';

use SEOAuto\SEOHelper\Media\Mime_Guard;
use SEOAuto\SEOHelper\Media\Url_Safety;

$urls = new Url_Safety();
$mime = new Mime_Guard();

check( 'blocks 127.0.0.1', $urls->is_blocked_ip( '127.0.0.1' ) );
check( 'blocks 10.x', $urls->is_blocked_ip( '10.1.2.3' ) );
check( 'blocks 192.168.x', $urls->is_blocked_ip( '192.168.1.1' ) );
check( 'blocks 172.16.x', $urls->is_blocked_ip( '172.16.5.5' ) );
check( 'blocks link-local', $urls->is_blocked_ip( '169.254.10.10' ) );
check( 'blocks metadata IP', $urls->is_blocked_ip( '169.254.169.254' ) );
check( 'blocks CGNAT', $urls->is_blocked_ip( '100.64.1.1' ) );
check( 'allows public IP', ! $urls->is_blocked_ip( '8.8.8.8' ) );
check( 'blocks ::1', $urls->is_blocked_ip( '::1' ) );
check( 'blocks hostname localhost', $urls->is_blocked_hostname( 'localhost' ) );
check( 'blocks metadata hostname', $urls->is_blocked_hostname( 'metadata.google.internal' ) );

check( 'blocks .php filename', $mime->assert_safe_filename( 'shell.php' ) instanceof WP_Error );
check( 'blocks .phar filename', $mime->assert_safe_filename( 'x.phar' ) instanceof WP_Error );
check( 'blocks double ext php.jpg', $mime->assert_safe_filename( 'img.php.jpg' ) instanceof WP_Error );
check( 'blocks svg by default', $mime->assert_safe_filename( 'icon.svg' ) instanceof WP_Error );
check( 'allows jpg name', $mime->assert_safe_filename( 'photo.jpg' ) === true );

$tmp = tempnam( sys_get_temp_dir(), 'sa' );
file_put_contents( $tmp, '<?php system("id");' );
$php_check = $mime->validate_file( $tmp, 'x.jpg' );
check( 'blocks PHP payload posing as jpg', $php_check instanceof WP_Error );
@unlink( $tmp );

$tmp2 = tempnam( sys_get_temp_dir(), 'sa' );
// Minimal valid 1x1 PNG
$png = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true );
file_put_contents( $tmp2, $png );
$png_check = $mime->validate_file( $tmp2, 'dot.png' );
check( 'accepts real PNG', ! ( $png_check instanceof WP_Error ) && ( $png_check['mime'] ?? '' ) === 'image/png' );
check( 'PNG dimensions 1x1', ( $png_check['width'] ?? 0 ) === 1 && ( $png_check['height'] ?? 0 ) === 1 );
@unlink( $tmp2 );

check( 'detects php open tag', $mime->looks_like_php_or_phar( '<?php echo 1;' ) );
check( 'detects PE exe', $mime->looks_like_php_or_phar( "MZ\x90\x00" ) );
check( 'jpeg magic not php', ! $mime->looks_like_php_or_phar( "\xFF\xD8\xFF" ) );

echo $failed === 0 ? "\nAll media security tests passed.\n" : "\n{$failed} test(s) failed.\n";
exit( $failed === 0 ? 0 : 1 );
