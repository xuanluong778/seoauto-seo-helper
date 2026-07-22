<?php
/**
 * SEO adapter tests — sync keys + native frontend output (no duplicate head).
 *
 * Run: php tests/test_seo_adapters.php
 *
 * @package SEOAuto\SEOHelper
 */

declare(strict_types=1);

$failed = 0;
/** @var array<string,mixed> */
$GLOBALS['seoauto_test_meta'] = array();
/** @var array<int,array{tag:string,cb:callable,prio:int}> */
$GLOBALS['seoauto_test_hooks'] = array();

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
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $tag, $cb, $prio = 10, $args = 1 ) { // phpcs:ignore
		$GLOBALS['seoauto_test_hooks'][] = array( 'tag' => (string) $tag, 'cb' => $cb, 'prio' => (int) $prio );
		return true;
	}
}
if ( ! function_exists( 'add_action' ) ) {
	function add_action( $tag, $cb, $prio = 10, $args = 1 ) { // phpcs:ignore
		return add_filter( $tag, $cb, $prio, $args );
	}
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $s ) { // phpcs:ignore
		return trim( strip_tags( (string) $s ) );
	}
}
if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	function sanitize_textarea_field( $s ) { // phpcs:ignore
		return trim( strip_tags( (string) $s ) );
	}
}
if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $u ) { // phpcs:ignore
		return filter_var( (string) $u, FILTER_SANITIZE_URL ) ?: '';
	}
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $s ) { // phpcs:ignore
		return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $u ) { // phpcs:ignore
		return htmlspecialchars( (string) $u, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta( $post_id, $key, $value ) { // phpcs:ignore
		$GLOBALS['seoauto_test_meta'][ (int) $post_id ][ (string) $key ] = $value;
		return true;
	}
}
if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( $post_id, $key = '', $single = false ) { // phpcs:ignore
		$val = $GLOBALS['seoauto_test_meta'][ (int) $post_id ][ (string) $key ] ?? '';
		return $single ? $val : array( $val );
	}
}
if ( ! function_exists( 'is_singular' ) ) {
	function is_singular( $post_types = '' ) { // phpcs:ignore
		return true;
	}
}
if ( ! function_exists( 'get_queried_object_id' ) ) {
	function get_queried_object_id() {
		return 42;
	}
}
if ( ! function_exists( 'get_the_title' ) ) {
	function get_the_title( $id = 0 ) { // phpcs:ignore
		return 'Fallback Title';
	}
}
if ( ! function_exists( 'get_permalink' ) ) {
	function get_permalink( $id = 0 ) { // phpcs:ignore
		return 'https://example.com/post-42/';
	}
}
if ( ! function_exists( 'get_the_post_thumbnail_url' ) ) {
	function get_the_post_thumbnail_url( $id = 0, $size = '' ) { // phpcs:ignore
		return '';
	}
}
if ( ! function_exists( 'get_the_date' ) ) {
	function get_the_date( $f = '', $id = 0 ) { // phpcs:ignore
		return '2026-01-01T00:00:00+00:00';
	}
}
if ( ! function_exists( 'get_the_modified_date' ) ) {
	function get_the_modified_date( $f = '', $id = 0 ) { // phpcs:ignore
		return '2026-01-02T00:00:00+00:00';
	}
}
if ( ! function_exists( 'get_post_field' ) ) {
	function get_post_field( $field, $id ) { // phpcs:ignore
		return 1;
	}
}
if ( ! function_exists( 'get_the_author_meta' ) ) {
	function get_the_author_meta( $field, $id = false ) { // phpcs:ignore
		return 'Author';
	}
}
if ( ! function_exists( 'get_bloginfo' ) ) {
	function get_bloginfo( $show = '' ) { // phpcs:ignore
		return 'Example Blog';
	}
}
if ( ! function_exists( 'home_url' ) ) {
	function home_url( $path = '' ) { // phpcs:ignore
		return 'https://example.com/' . ltrim( (string) $path, '/' );
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $options = 0 ) { // phpcs:ignore
		return json_encode( $data, $options ); // phpcs:ignore
	}
}

require_once dirname( __DIR__ ) . '/includes/Seo/Seo_Adapter_Interface.php';
require_once dirname( __DIR__ ) . '/includes/Seo/Seo_Payload.php';
require_once dirname( __DIR__ ) . '/includes/Seo/RankMath_Adapter.php';
require_once dirname( __DIR__ ) . '/includes/Seo/Yoast_Adapter.php';
require_once dirname( __DIR__ ) . '/includes/Seo/AIOSEO_Adapter.php';
require_once dirname( __DIR__ ) . '/includes/Seo/Native_Adapter.php';
require_once dirname( __DIR__ ) . '/includes/Seo/Seo_Facade.php';

use SEOAuto\SEOHelper\Seo\AIOSEO_Adapter;
use SEOAuto\SEOHelper\Seo\Native_Adapter;
use SEOAuto\SEOHelper\Seo\RankMath_Adapter;
use SEOAuto\SEOHelper\Seo\Seo_Facade;
use SEOAuto\SEOHelper\Seo\Seo_Payload;
use SEOAuto\SEOHelper\Seo\Yoast_Adapter;

$sample = Seo_Payload::from_array(
	array(
		'title'              => 'SEO Title Real',
		'description'        => 'Meta description real text',
		'focus_keyword'      => 'seoauto keyword',
		'canonical'          => 'https://example.com/canonical/',
		'robots'             => array( 'index' => false, 'follow' => true ),
		'schema_type'        => 'Article',
		'social_title'       => 'OG Title Real',
		'social_description' => 'OG Desc Real',
		'social_image'       => 'https://cdn.example.com/og.jpg',
	)
);

// --- Rank Math keys ---
$GLOBALS['seoauto_test_meta'] = array();
( new RankMath_Adapter() )->sync( 10, $sample );
$rm = $GLOBALS['seoauto_test_meta'][10];
check( 'Rank Math title', ( $rm['rank_math_title'] ?? '' ) === 'SEO Title Real' );
check( 'Rank Math description', ( $rm['rank_math_description'] ?? '' ) === 'Meta description real text' );
check( 'Rank Math focus', ( $rm['rank_math_focus_keyword'] ?? '' ) === 'seoauto keyword' );
check( 'Rank Math canonical', ( $rm['rank_math_canonical_url'] ?? '' ) === 'https://example.com/canonical/' );
check( 'Rank Math robots noindex', in_array( 'noindex', (array) ( $rm['rank_math_robots'] ?? array() ), true ) );
check( 'Rank Math facebook title', ( $rm['rank_math_facebook_title'] ?? '' ) === 'OG Title Real' );
check( 'Rank Math facebook image', ( $rm['rank_math_facebook_image'] ?? '' ) === 'https://cdn.example.com/og.jpg' );

// --- Yoast keys ---
$GLOBALS['seoauto_test_meta'] = array();
( new Yoast_Adapter() )->sync( 11, $sample );
$yo = $GLOBALS['seoauto_test_meta'][11];
check( 'Yoast title', ( $yo['_yoast_wpseo_title'] ?? '' ) === 'SEO Title Real' );
check( 'Yoast metadesc', ( $yo['_yoast_wpseo_metadesc'] ?? '' ) === 'Meta description real text' );
check( 'Yoast focuskw', ( $yo['_yoast_wpseo_focuskw'] ?? '' ) === 'seoauto keyword' );
check( 'Yoast canonical', ( $yo['_yoast_wpseo_canonical'] ?? '' ) === 'https://example.com/canonical/' );
check( 'Yoast noindex flag', ( $yo['_yoast_wpseo_meta-robots-noindex'] ?? '' ) === '1' );
check( 'Yoast og title', ( $yo['_yoast_wpseo_opengraph-title'] ?? '' ) === 'OG Title Real' );
check( 'Yoast og image', ( $yo['_yoast_wpseo_opengraph-image'] ?? '' ) === 'https://cdn.example.com/og.jpg' );

// --- AIOSEO meta fallback ---
$GLOBALS['seoauto_test_meta'] = array();
( new AIOSEO_Adapter() )->sync( 12, $sample );
$ai = $GLOBALS['seoauto_test_meta'][12];
check( 'AIOSEO title meta', ( $ai['_aioseo_title'] ?? '' ) === 'SEO Title Real' );
check( 'AIOSEO description meta', ( $ai['_aioseo_description'] ?? '' ) === 'Meta description real text' );
check( 'AIOSEO keywords', ( $ai['_aioseo_keywords'] ?? '' ) === 'seoauto keyword' );
check( 'AIOSEO canonical', ( $ai['_aioseo_canonical_url'] ?? '' ) === 'https://example.com/canonical/' );
check( 'AIOSEO og image', ( $ai['_aioseo_og_image'] ?? '' ) === 'https://cdn.example.com/og.jpg' );

// --- Native sync + frontend head ---
$GLOBALS['seoauto_test_meta'] = array();
$native = new Native_Adapter();
$native->sync( 42, $sample );
$nv = $GLOBALS['seoauto_test_meta'][42];
check( 'Native stores title', ( $nv[ Native_Adapter::META_TITLE ] ?? '' ) === 'SEO Title Real' );
check( 'Native stores desc', ( $nv[ Native_Adapter::META_DESC ] ?? '' ) === 'Meta description real text' );
check( 'Native stores canonical', ( $nv[ Native_Adapter::META_CANONICAL ] ?? '' ) === 'https://example.com/canonical/' );
check( 'Native robots index 0', ( $nv[ Native_Adapter::META_ROBOTS_I ] ?? '' ) === '0' );

ob_start();
$native->render_head();
$html = (string) ob_get_clean();
check( 'Frontend has meta description', str_contains( $html, 'name="description"' ) && str_contains( $html, 'Meta description real text' ) );
check( 'Frontend has og:title', str_contains( $html, 'property="og:title"' ) && str_contains( $html, 'OG Title Real' ) );
check( 'Frontend has og:description', str_contains( $html, 'property="og:description"' ) && str_contains( $html, 'OG Desc Real' ) );
check( 'Frontend has og:image', str_contains( $html, 'property="og:image"' ) && str_contains( $html, 'https://cdn.example.com/og.jpg' ) );
check( 'Frontend has JSON-LD', str_contains( $html, 'application/ld+json' ) && str_contains( $html, '"@type":"Article"' ) );
check( 'Frontend JSON-LD headline', str_contains( $html, 'SEO Title Real' ) );

$robots = $native->filter_robots( array( 'index' => true ) );
check( 'Frontend robots noindex applied', ! empty( $robots['noindex'] ) );
$canon = $native->filter_canonical( 'https://example.com/default/', null );
check( 'Frontend canonical overridden', $canon === 'https://example.com/canonical/' );
$doc = $native->filter_document_title( 'Default' );
check( 'Frontend document title', $doc === 'SEO Title Real' );

// --- Facade: native when no plugin ---
$facade = new Seo_Facade();
check( 'Facade active is native without plugins', $facade->active_id() === 'native' );
$GLOBALS['seoauto_test_hooks'] = array();
$facade->register_hooks();
$tags = array_column( $GLOBALS['seoauto_test_hooks'], 'tag' );
check( 'Native registers wp_head', in_array( 'wp_head', $tags, true ) );
check( 'Native registers wp_robots', in_array( 'wp_robots', $tags, true ) );
check( 'Native registers canonical filter', in_array( 'get_canonical_url', $tags, true ) );

// --- Facade: Rank Math wins — no native head hooks ---
if ( ! defined( 'RANK_MATH_VERSION' ) ) {
	define( 'RANK_MATH_VERSION', '1.0.0-test' );
}
$facade2 = new Seo_Facade();
check( 'Facade picks rankmath when defined', $facade2->active_id() === 'rankmath' );
check( 'Third party owns head', $facade2->third_party_owns_head() === true );
$GLOBALS['seoauto_test_hooks'] = array();
$facade2->register_hooks();
check( 'No native wp_head when Rank Math active', ! in_array( 'wp_head', array_column( $GLOBALS['seoauto_test_hooks'], 'tag' ), true ) );

$GLOBALS['seoauto_test_meta'] = array();
$facade2->sync_post_meta(
	99,
	array(
		'title'         => 'Via RM',
		'description'   => 'Desc RM',
		'focus_keyword' => 'kw',
	)
);
check( 'Sync writes Rank Math title only path', ( $GLOBALS['seoauto_test_meta'][99]['rank_math_title'] ?? '' ) === 'Via RM' );
check( 'Does not write Yoast keys under Rank Math', ! isset( $GLOBALS['seoauto_test_meta'][99]['_yoast_wpseo_title'] ) );

echo $failed === 0 ? "\nAll SEO adapter tests passed.\n" : "\n{$failed} test(s) failed.\n";
exit( $failed === 0 ? 0 : 1 );
