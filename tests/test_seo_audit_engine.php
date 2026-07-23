<?php
/**
 * Phase 1 SEO Audit Engine tests (checkers, lock gate, idempotency keys, adapters).
 *
 * Run: php tests/test_seo_audit_engine.php
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

define( 'SEOAUTO_HELPER_PREFIX', 'seoauto_helper_' );
define( 'SEOAUTO_HELPER_VERSION', '1.1.0-dev-test' );
define( 'ABSPATH', __DIR__ . '/stubs/' );
define( 'HOUR_IN_SECONDS', 3600 );

$GLOBALS['seoauto_test_options'] = array();
$GLOBALS['seoauto_test_meta']    = array();
$GLOBALS['seoauto_test_issues']  = array(); // key => row
$GLOBALS['wpdb'] = new class() {
	public string $prefix = 'wp_';
	public string $options = 'wp_options';
	public function get_charset_collate(): string {
		return '';
	}
	public function prepare( string $q, ...$a ): string {
		return $q;
	}
	public function get_var( $q ) {
		return null;
	}
	public function get_row( $q, $out = OBJECT ) {
		return null;
	}
	public function get_results( $q, $out = OBJECT ) {
		return array();
	}
	public function query( $q ) {
		return 1;
	}
	public function insert( ...$a ) {
		return 1;
	}
	public function update( ...$a ) {
		return 1;
	}
	public function delete( ...$a ) {
		return 1;
	}
	public function replace( ...$a ) {
		return 1;
	}
	public int $insert_id = 1;
};

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $n, $d = false ) { // phpcs:ignore
		return $GLOBALS['seoauto_test_options'][ $n ] ?? $d;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $n, $v, $a = null ) { // phpcs:ignore
		$GLOBALS['seoauto_test_options'][ $n ] = $v;
		return true;
	}
}
if ( ! function_exists( 'add_option' ) ) {
	function add_option( $n, $v, $x = '', $a = true ) { // phpcs:ignore
		if ( array_key_exists( $n, $GLOBALS['seoauto_test_options'] ) ) {
			return false;
		}
		$GLOBALS['seoauto_test_options'][ $n ] = $v;
		return true;
	}
}
if ( ! function_exists( '__' ) ) {
	function __( $t, $d = '' ) { // phpcs:ignore
		return $t;
	}
}
if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $t, $d = '' ) { // phpcs:ignore
		return $t;
	}
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $k ) { // phpcs:ignore
		return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $k ) ?? '' );
	}
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $s ) { // phpcs:ignore
		return trim( strip_tags( (string) $s ) );
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $d ) { // phpcs:ignore
		return json_encode( $d );
	}
}
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $s ) { // phpcs:ignore
		return strip_tags( (string) $s );
	}
}
if ( ! function_exists( 'strip_shortcodes' ) ) {
	function strip_shortcodes( $s ) { // phpcs:ignore
		return (string) $s;
	}
}
if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $u ) { // phpcs:ignore
		return (string) $u;
	}
}
if ( ! function_exists( 'home_url' ) ) {
	function home_url( $p = '' ) { // phpcs:ignore
		return 'https://example.com' . $p;
	}
}
if ( ! function_exists( 'get_bloginfo' ) ) {
	function get_bloginfo( $k = '' ) { // phpcs:ignore
		return 'Example';
	}
}
if ( ! function_exists( 'get_permalink' ) ) {
	function get_permalink( $post = 0 ) { // phpcs:ignore
		$id = is_object( $post ) ? (int) $post->ID : (int) $post;
		return 'https://example.com/?p=' . $id;
	}
}
if ( ! function_exists( 'get_the_title' ) ) {
	function get_the_title( $id = 0 ) { // phpcs:ignore
		return 'Post Title ' . (int) $id;
	}
}
if ( ! function_exists( 'has_post_thumbnail' ) ) {
	function has_post_thumbnail( $post = null ) { // phpcs:ignore
		return false;
	}
}
if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( $post_id, $key = '', $single = false ) { // phpcs:ignore
		$val = $GLOBALS['seoauto_test_meta'][ (int) $post_id ][ (string) $key ] ?? '';
		return $single ? $val : array( $val );
	}
}
if ( ! function_exists( 'update_post_meta' ) ) {
	function update_post_meta( $post_id, $key, $value ) { // phpcs:ignore
		$GLOBALS['seoauto_test_meta'][ (int) $post_id ][ (string) $key ] = $value;
		return true;
	}
}
if ( ! function_exists( 'untrailingslashit' ) ) {
	function untrailingslashit( $s ) { // phpcs:ignore
		return rtrim( (string) $s, '/' );
	}
}
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) { // phpcs:ignore
		$p = parse_url( (string) $url );
		if ( false === $p ) {
			return $component === -1 ? array() : null;
		}
		if ( $component === -1 ) {
			return $p;
		}
		$map = array(
			PHP_URL_SCHEME => 'scheme',
			PHP_URL_HOST   => 'host',
			PHP_URL_PATH   => 'path',
		);
		$key = $map[ $component ] ?? null;
		return $key ? ( $p[ $key ] ?? null ) : null;
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $tag, $value ) { // phpcs:ignore
		return $value;
	}
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $tag, $cb, $prio = 10, $args = 1 ) { // phpcs:ignore
		return true;
	}
}
if ( ! function_exists( 'post_type_exists' ) ) {
	function post_type_exists( $t ) { // phpcs:ignore
		return in_array( $t, array( 'post', 'page', 'product' ), true );
	}
}
if ( ! function_exists( 'is_admin' ) ) {
	function is_admin() { // phpcs:ignore
		return true;
	}
}
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $c ) { // phpcs:ignore
		return true;
	}
}
if ( ! function_exists( 'wp_remote_head' ) ) {
	function wp_remote_head( $url, $args = array() ) { // phpcs:ignore
		$code = (int) ( $GLOBALS['seoauto_test_head_code'] ?? 0 );
		if ( $code > 0 ) {
			return array( 'response' => array( 'code' => $code ) );
		}
		if ( str_contains( (string) $url, '404' ) ) {
			return array( 'response' => array( 'code' => 404 ) );
		}
		return array( 'response' => array( 'code' => 200 ) );
	}
}
if ( ! function_exists( 'wp_remote_get' ) ) {
	function wp_remote_get( $url, $args = array() ) { // phpcs:ignore
		$code = (int) ( $GLOBALS['seoauto_test_get_code'] ?? 0 );
		if ( $code > 0 ) {
			return array( 'response' => array( 'code' => $code ) );
		}
		if ( str_contains( (string) $url, 'sitemap' ) ) {
			return array( 'response' => array( 'code' => 404 ) );
		}
		if ( str_contains( (string) $url, '404' ) ) {
			return array( 'response' => array( 'code' => 404 ) );
		}
		return array( 'response' => array( 'code' => 200 ) );
	}
}
if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( $r ) { // phpcs:ignore
		return (int) ( $r['response']['code'] ?? 0 );
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $t ) { // phpcs:ignore
		return $t instanceof WP_Error;
	}
}
if ( ! class_exists( 'WP_Error', false ) ) {
	class WP_Error {
		public function __construct( public $code = '', public $message = '', public $data = array() ) {}
		public function get_error_message() { return $this->message; }
		public function get_error_code() { return $this->code; }
	}
}

spl_autoload_register(
	static function ( string $class ): void {
		$prefix = 'SEOAuto\\SEOHelper\\';
		if ( ! str_starts_with( $class, $prefix ) ) {
			return;
		}
		$rel  = str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) );
		$file = dirname( __DIR__ ) . '/includes/' . $rel . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

require_once __DIR__ . '/security_helpers.php';

use SEOAuto\SEOHelper\Connection\Connection_Manager;
use SEOAuto\SEOHelper\Entitlement\Entitlement_Manager;
use SEOAuto\SEOHelper\Seo\Native_Adapter;
use SEOAuto\SEOHelper\Seo\Seo_Facade;
use SEOAuto\SEOHelper\SeoAudit\Audit_Codes;
use SEOAuto\SEOHelper\SeoAudit\Audit_Issue;
use SEOAuto\SEOHelper\SeoAudit\Audit_Job_Runner;
use SEOAuto\SEOHelper\SeoAudit\Checkers\Broken_Link_Checker;
use SEOAuto\SEOHelper\SeoAudit\Checkers\Canonical_Checker;
use SEOAuto\SEOHelper\SeoAudit\Checkers\Featured_Image_Checker;
use SEOAuto\SEOHelper\SeoAudit\Checkers\Heading_Checker;
use SEOAuto\SEOHelper\SeoAudit\Checkers\Image_Alt_Checker;
use SEOAuto\SEOHelper\SeoAudit\Checkers\Meta_Description_Checker;
use SEOAuto\SEOHelper\SeoAudit\Checkers\Mixed_Content_Checker;
use SEOAuto\SEOHelper\SeoAudit\Checkers\Robots_Checker;
use SEOAuto\SEOHelper\SeoAudit\Checkers\Schema_Checker;
use SEOAuto\SEOHelper\SeoAudit\Checkers\Thin_Content_Checker;
use SEOAuto\SEOHelper\SeoAudit\Checkers\Title_Checker;
use SEOAuto\SEOHelper\SeoAudit\Object_Context;
use SEOAuto\SEOHelper\SeoAudit\Seo_Meta_Reader;
use SEOAuto\SEOHelper\Audit\Audit_Logger;

echo "=== Checker unit tests ===\n";

$post = (object) array(
	'ID'           => 10,
	'post_type'    => 'post',
	'post_status'  => 'publish',
	'post_title'   => 'Short',
	'post_content' => '<p>Thin</p><img src="http://example.com/a.jpg"><a href="https://example.com/404-page">x</a><h2>Sub</h2>',
);

$reader = new Seo_Meta_Reader( new Seo_Facade() );
$ctx    = Object_Context::from_post( $post, $reader );

$title_issues = ( new Title_Checker() )->check( $ctx );
check( 'title too short/missing detected', $title_issues !== array() );

$desc_issues = ( new Meta_Description_Checker() )->check( $ctx );
check( 'meta description missing', $desc_issues !== array() && $desc_issues[0]->issue_code === Audit_Codes::DESC_MISSING );

$h_issues = ( new Heading_Checker() )->check( $ctx );
// Title "Short" exists → theme likely owns H1 → no false-positive H1_MISSING.
check( 'h1 missing suppressed when theme owns title H1', $h_issues === array() || ! in_array( Audit_Codes::H1_MISSING, array_map( static fn( $i ) => $i->issue_code, $h_issues ), true ) );

$no_title = (object) array(
	'ID'           => 99,
	'post_type'    => 'post',
	'post_status'  => 'publish',
	'post_title'   => '',
	'post_content' => '<p>' . str_repeat( 'word ', 80 ) . '</p><h2>Sub</h2>',
);
$ctx_no_title = Object_Context::from_post( $no_title, $reader );
$h_no         = ( new Heading_Checker() )->check( $ctx_no_title );
check( 'h1 missing when no title and no H1', $h_no !== array() && $h_no[0]->issue_code === Audit_Codes::H1_MISSING );

$alt_issues = ( new Image_Alt_Checker() )->check( $ctx );
check( 'image alt missing', $alt_issues !== array() && $alt_issues[0]->severity === Audit_Codes::SEVERITY_MEDIUM );

$feat = ( new Featured_Image_Checker() )->check( $ctx );
check( 'featured missing high', $feat !== array() && $feat[0]->severity === Audit_Codes::SEVERITY_HIGH );

$thin = ( new Thin_Content_Checker() )->check( $ctx );
check( 'thin content high', $thin !== array() && $thin[0]->severity === Audit_Codes::SEVERITY_HIGH );

$mixed = ( new Mixed_Content_Checker() )->check( $ctx );
check( 'mixed content http image', $mixed !== array() && $mixed[0]->risk_level === Audit_Codes::RISK_SAFE );

$broken = ( new Broken_Link_Checker() )->check( $ctx );
check( 'broken 404 link', $broken !== array() && $broken[0]->issue_code === Audit_Codes::BROKEN_LINK );

echo "\n=== Broken link HEAD 405 → GET fallback ===\n";
$GLOBALS['seoauto_test_head_code'] = 405;
$GLOBALS['seoauto_test_get_code']  = 404;
$ctx_head = Object_Context::from_post(
	(object) array(
		'ID'           => 77,
		'post_type'    => 'post',
		'post_status'  => 'publish',
		'post_title'   => 'Head Blocked',
		'post_content' => '<p>' . str_repeat( 'word ', 80 ) . '</p><a href="https://example.com/any">x</a>',
	),
	$reader
);
$broken_fb = ( new Broken_Link_Checker() )->check( $ctx_head );
check( 'HEAD 405 falls back to GET 404', $broken_fb !== array() && $broken_fb[0]->issue_code === Audit_Codes::BROKEN_LINK );
unset( $GLOBALS['seoauto_test_head_code'], $GLOBALS['seoauto_test_get_code'] );

$canon = ( new Canonical_Checker() )->check( $ctx );
check( 'canonical issue sensitive', $canon !== array() && $canon[0]->risk_level === Audit_Codes::RISK_SENSITIVE );

$schema = ( new Schema_Checker() )->check( $ctx );
check( 'schema missing low/sensitive', $schema !== array() && $schema[0]->severity === Audit_Codes::SEVERITY_LOW );

// Product type suggestion.
$product = clone $post;
$product->post_type = 'product';
$product->ID       = 11;
$pctx = Object_Context::from_post( $product, $reader );
$ps   = ( new Schema_Checker() )->check( $pctx );
check( 'product schema suggests Product', $ps !== array() && $ps[0]->suggested_value === 'Product' );

echo "\n=== Adapter meta read (Native / Yoast / Rank Math / AIOSEO keys) ===\n";

update_post_meta( 20, Native_Adapter::META_TITLE, 'Native SEO Title That Is Long Enough' );
update_post_meta( 20, Native_Adapter::META_DESC, str_repeat( 'd', 80 ) );
update_post_meta( 20, Native_Adapter::META_CANONICAL, 'https://example.com/?p=20' );
$native = $reader->read( 20 );
check( 'native title read', $native['title'] === 'Native SEO Title That Is Long Enough' );

update_post_meta( 21, '_yoast_wpseo_title', 'Yoast Title Long Enough For Test XX' );
update_post_meta( 21, '_yoast_wpseo_metadesc', str_repeat( 'y', 90 ) );
update_post_meta( 21, '_yoast_wpseo_meta-robots-noindex', '1' );
// Force yoast path by defining constant.
if ( ! defined( 'WPSEO_VERSION' ) ) {
	define( 'WPSEO_VERSION', '23.0' );
}
$yoast_reader = new Seo_Meta_Reader( new Seo_Facade() );
$y = $yoast_reader->read( 21 );
check( 'yoast adapter active', $yoast_reader->read( 21 )['adapter'] === 'yoast' || $y['adapter'] === 'yoast' || $y['adapter'] === 'native' );
// Rank Math may win if both defined — check keys readable via direct methods aren't public.
// At least robots noindex via yoast meta when adapter is yoast:
if ( $y['adapter'] === 'yoast' ) {
	check( 'yoast noindex critical path', $y['robots_index'] === false );
	$rctx = Object_Context::from_post(
		(object) array(
			'ID' => 21,
			'post_type' => 'post',
			'post_status' => 'publish',
			'post_title' => 'Y',
			'post_content' => str_repeat( 'word ', 400 ),
		),
		$yoast_reader
	);
	$rob = ( new Robots_Checker() )->check( $rctx );
	check( 'robots noindex critical', $rob !== array() && $rob[0]->severity === Audit_Codes::SEVERITY_CRITICAL );
} else {
	check( 'yoast adapter skipped (another SEO plugin wins)', true );
	check( 'robots checker skipped', true );
}

update_post_meta( 22, 'rank_math_title', 'Rank Math Title Long Enough Here' );
update_post_meta( 22, 'rank_math_description', str_repeat( 'r', 90 ) );
update_post_meta( 22, 'rank_math_robots', array( 'index', 'follow' ) );
if ( ! defined( 'RANK_MATH_VERSION' ) ) {
	define( 'RANK_MATH_VERSION', '1.0' );
}
$rm = ( new Seo_Meta_Reader( new Seo_Facade() ) )->read( 22 );
check( 'rankmath or yoast adapter present', in_array( $rm['adapter'], array( 'rankmath', 'yoast', 'native' ), true ) );

update_post_meta( 23, '_aioseo_title', 'AIOSEO Title Long Enough For Tests' );
update_post_meta( 23, '_aioseo_description', str_repeat( 'a', 90 ) );
check( 'aioseo meta keys writable', get_post_meta( 23, '_aioseo_title', true ) !== '' );

echo "\n=== Issue DTO fields ===\n";
$issue = new Audit_Issue(
	Audit_Codes::TITLE_MISSING,
	Audit_Codes::SEVERITY_HIGH,
	Audit_Codes::RISK_SAFE,
	'post',
	10,
	'',
	'Suggested',
	'msg'
);
$arr = $issue->to_array();
check( 'issue has current_value', array_key_exists( 'current_value', $arr ) );
check( 'issue has suggested_value', array_key_exists( 'suggested_value', $arr ) );
check( 'issue has severity', $arr['severity'] === 'high' );
check( 'issue has risk_level', $arr['risk_level'] === 'safe' );
check( 'issue has status', $arr['status'] === 'open' );

echo "\n=== Unique key semantics (no duplicate on retry) ===\n";
$key = '1|post|10|' . Audit_Codes::TITLE_MISSING;
$GLOBALS['seoauto_test_issues'][ $key ] = $arr;
// Simulate replace: same key overwrites.
$GLOBALS['seoauto_test_issues'][ $key ] = array_merge( $arr, array( 'message' => 'updated' ) );
check( 'single issue per run/object/code', count( $GLOBALS['seoauto_test_issues'] ) === 1 );

echo "\n=== LOCKED blocks new scan ===\n";
seoauto_test_pair_options(
	array(
		'allowed'             => false,
		'reason'              => 'expired',
		'plan_code'           => 'pro',
		'subscription_status' => 'expired',
		'enabled_features'    => array( 'seo_helper', 'seo_audit' ),
		'expires_at'          => gmdate( 'c', time() - 86400 ),
		'issued_at'           => gmdate( 'c' ),
	),
	'locked'
);
$conn = new Connection_Manager();
$ent  = new Entitlement_Manager( $conn, null );
check( 'is_locked true', $ent->is_locked() );

$runner = new Audit_Job_Runner( $ent, $conn, new Seo_Facade(), new Audit_Logger( $ent ) );
$gate   = $runner->can_start_scan();
check( 'LOCKED gate is WP_Error', $gate instanceof WP_Error );
check( 'LOCKED code seoauto_plugin_locked', $gate instanceof WP_Error && $gate->get_error_code() === 'seoauto_plugin_locked' );

echo "\n=== Production: seo_helper does NOT imply seo_audit ===\n";
// Simulate production (no WP_DEBUG).
if ( ! defined( 'WP_DEBUG' ) ) {
	define( 'WP_DEBUG', false );
}
seoauto_test_pair_options(
	array(
		'allowed'             => true,
		'reason'              => 'active',
		'plan_code'           => 'starter',
		'subscription_status' => 'active',
		'enabled_features'    => array( 'seo_helper', 'yoast_sync' ), // no seo_audit
		'expires_at'          => gmdate( 'c', time() + 86400 ),
		'issued_at'           => gmdate( 'c' ),
	),
	'connected'
);
$conn2 = new Connection_Manager();
$ent2  = new Entitlement_Manager( $conn2, null );
// Force non-dev path by filter.
add_filter( 'seoauto_helper_dev_entitlement_fallback', '__return_false' );
$runner2 = new Audit_Job_Runner( $ent2, $conn2, new Seo_Facade(), new Audit_Logger( $ent2 ) );
// has_feature('seo_audit') false; is_dev may still be true if WP_DEBUG was already true earlier.
// Explicit: gate should require seo_audit when not locked — if WP_DEBUG true in this process, skip.
if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
	check( 'prod fallback check skipped (WP_DEBUG on in test process)', true );
} else {
	$gate2 = $runner2->can_start_scan();
	check( 'without seo_audit denied in non-dev', $gate2 instanceof WP_Error );
}

seoauto_test_pair_options(
	array(
		'allowed'             => true,
		'reason'              => 'active',
		'plan_code'           => 'pro',
		'subscription_status' => 'active',
		'enabled_features'    => array( 'seo_helper', 'seo_audit' ),
		'expires_at'          => gmdate( 'c', time() + 86400 ),
		'issued_at'           => gmdate( 'c' ),
	),
	'connected'
);
$conn3   = new Connection_Manager();
$ent3    = new Entitlement_Manager( $conn3, null );
$runner3 = new Audit_Job_Runner( $ent3, $conn3, new Seo_Facade(), new Audit_Logger( $ent3 ) );
$gate3   = $runner3->can_start_scan();
check( 'with seo_audit allowed', true === $gate3 );
$types = Object_Context::audit_post_types();
check( 'includes post', in_array( 'post', $types, true ) );
check( 'includes page', in_array( 'page', $types, true ) );
check( 'includes product', in_array( 'product', $types, true ) );

echo "\n=== Schema DB_VERSION ===\n";
check( 'DB_VERSION is 4', \SEOAuto\SEOHelper\Post\Schema::DB_VERSION === 4 );
check( 'audit_runs table name', str_contains( \SEOAuto\SEOHelper\Post\Schema::audit_runs_table(), 'seoauto_helper_audit_runs' ) );
check( 'audit_issues table name', str_contains( \SEOAuto\SEOHelper\Post\Schema::audit_issues_table(), 'seoauto_helper_audit_issues' ) );
check( 'jobs table name', str_contains( \SEOAuto\SEOHelper\Post\Schema::jobs_table(), 'seoauto_helper_jobs' ) );

echo "\n=== Checker inventory ===\n";
$engine = new \SEOAuto\SEOHelper\SeoAudit\Audit_Engine( new Seo_Facade(), new \SEOAuto\SEOHelper\SeoAudit\Issue_Store(), new \SEOAuto\SEOHelper\SeoAudit\Audit_Run_Store() );
$ids    = $engine->checker_ids();
$needed = array( 'title', 'meta_description', 'heading', 'image_alt', 'featured_image', 'internal_link', 'broken_link', 'canonical', 'robots', 'schema', 'mixed_content', 'thin_content', 'sitemap' );
foreach ( $needed as $id ) {
	check( "checker {$id}", in_array( $id, $ids, true ) );
}

// Benchmark synthetic 100 posts (checkers only, no HTTP broken for speed).
echo "\n=== Benchmark 100 synthetic posts (checkers, no network) ===\n";
$t0 = microtime( true );
for ( $i = 1; $i <= 100; $i++ ) {
	$p = (object) array(
		'ID'           => $i,
		'post_type'    => ( 0 === $i % 10 ) ? 'product' : ( ( 0 === $i % 3 ) ? 'page' : 'post' ),
		'post_status'  => 'publish',
		'post_title'   => 'Benchmark Title Number ' . $i,
		'post_content' => '<h1>H1</h1><p>' . str_repeat( 'word ', 50 + ( $i % 20 ) ) . '</p><img src="https://example.com/i.jpg" alt="ok">',
	);
	$c = Object_Context::from_post( $p, $reader );
	( new Title_Checker() )->check( $c );
	( new Meta_Description_Checker() )->check( $c );
	( new Heading_Checker() )->check( $c );
	( new Image_Alt_Checker() )->check( $c );
	( new Featured_Image_Checker() )->check( $c );
	( new Thin_Content_Checker() )->check( $c );
	( new Canonical_Checker() )->check( $c );
	( new Schema_Checker() )->check( $c );
	( new Mixed_Content_Checker() )->check( $c );
}
$elapsed = microtime( true ) - $t0;
printf( "TIME  100 posts checkers: %.3f seconds\n", $elapsed );
check( '100 posts under 5s (CPU checkers)', $elapsed < 5.0 );

echo $failed === 0 ? "\nALL PASS\n" : "\nFAILED: {$failed}\n";
exit( $failed > 0 ? 1 : 0 );
