<?php
/**
 * Content sanitizer smoke tests (no WordPress bootstrap).
 *
 * Run: php tests/test_content_sanitizer.php
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

// Minimal stubs so Content_Sanitizer can load offline.
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value ) { // phpcs:ignore
		return $value;
	}
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) { // phpcs:ignore
		return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $key ) ?? '' );
	}
}
if ( ! function_exists( 'wp_kses_post' ) ) {
	function wp_kses_post( $content ) { // phpcs:ignore
		return strip_tags( (string) $content, '<p><a><strong><em><ul><ol><li><h1><h2><h3><h4><img><br>' );
	}
}
if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	function sanitize_textarea_field( $str ) { // phpcs:ignore
		return trim( strip_tags( (string) $str ) );
	}
}
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $str ) { // phpcs:ignore
		return strip_tags( (string) $str );
	}
}

require_once dirname( __DIR__ ) . '/includes/Security/Content_Sanitizer.php';

use SEOAuto\SEOHelper\Security\Content_Sanitizer;

$with_php = 'Hello <?php system("id"); ?> world <?= 1 ?>';
$clean    = Content_Sanitizer::sanitize_content( $with_php );
check( 'strips PHP open tags', ! str_contains( $clean, '<?php' ) && ! str_contains( $clean, 'system' ) );

$with_sc = 'Safe [php]evil()[/php] and [eval foo="1"]x[/eval] text';
$clean_sc = Content_Sanitizer::strip_dangerous_shortcodes( $with_sc );
check( 'strips php shortcode', ! str_contains( $clean_sc, '[php' ) );
check( 'strips eval shortcode', ! str_contains( $clean_sc, '[eval' ) );
check( 'keeps surrounding text', str_contains( $clean_sc, 'Safe' ) && str_contains( $clean_sc, 'text' ) );

$excerpt = Content_Sanitizer::sanitize_excerpt( "Hi <?php echo 1;?>\nLine" );
check( 'excerpt strips php and tags', ! str_contains( $excerpt, '<?' ) && str_contains( $excerpt, 'Hi' ) );

echo $failed === 0 ? "\nAll content sanitizer tests passed.\n" : "\n{$failed} test(s) failed.\n";
exit( $failed === 0 ? 0 : 1 );
