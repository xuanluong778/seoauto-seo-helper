<?php
/**
 * Phase 2 ContentOps unit tests (preview read-only, checksum, risk, redaction keys).
 *
 * Run: php tests/test_content_ops.php
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
define( 'SEOAUTO_HELPER_VERSION', '1.2.0-dev-test' );
define( 'ABSPATH', __DIR__ . '/stubs/' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );

$GLOBALS['seoauto_test_options'] = array();
$GLOBALS['seoauto_test_meta']    = array();
$GLOBALS['seoauto_test_posts']   = array(
	42 => (object) array(
		'ID'           => 42,
		'post_title'   => 'Hello',
		'post_content' => 'Body A',
		'post_excerpt' => 'Ex',
		'post_name'    => 'hello',
		'post_status'  => 'publish',
		'post_type'    => 'post',
	),
);
$GLOBALS['wpdb'] = new class() {
	public string $prefix = 'wp_';
	public int $insert_id = 1;
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
	function sanitize_text_field( $t ) { // phpcs:ignore
		return is_string( $t ) ? trim( $t ) : $t;
	}
}
if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	function sanitize_textarea_field( $t ) { // phpcs:ignore
		return is_string( $t ) ? trim( $t ) : $t;
	}
}
if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( $t ) { // phpcs:ignore
		return strtolower( preg_replace( '/[^a-z0-9\-]+/', '-', (string) $t ) ?? '' );
	}
}
if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $u ) { // phpcs:ignore
		return (string) $u;
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $d, $f = 0 ) { // phpcs:ignore
		return json_encode( $d, $f );
	}
}
if ( ! function_exists( 'get_post' ) ) {
	function get_post( $id ) { // phpcs:ignore
		return $GLOBALS['seoauto_test_posts'][ (int) $id ] ?? null;
	}
}
if ( ! function_exists( 'get_the_title' ) ) {
	function get_the_title( $id ) { // phpcs:ignore
		$p = get_post( $id );
		return $p ? (string) $p->post_title : '';
	}
}
if ( ! function_exists( 'get_post_type' ) ) {
	function get_post_type( $id ) { // phpcs:ignore
		$p = get_post( $id );
		return $p ? (string) $p->post_type : '';
	}
}
if ( ! function_exists( 'get_post_thumbnail_id' ) ) {
	function get_post_thumbnail_id( $id ) { // phpcs:ignore
		return 0;
	}
}
if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( $id, $key = '', $single = false ) { // phpcs:ignore
		$store = $GLOBALS['seoauto_test_meta'][ (int) $id ] ?? array();
		if ( $key === '' ) {
			$out = array();
			foreach ( $store as $k => $v ) {
				$out[ $k ] = is_array( $v ) ? $v : array( $v );
			}
			return $out;
		}
		if ( ! array_key_exists( $key, $store ) ) {
			return $single ? '' : array();
		}
		$v = $store[ $key ];
		return $single ? ( is_array( $v ) ? ( $v[0] ?? '' ) : $v ) : ( is_array( $v ) ? $v : array( $v ) );
	}
}
if ( ! function_exists( 'get_object_taxonomies' ) ) {
	function get_object_taxonomies( $type, $out = 'names' ) { // phpcs:ignore
		return array( 'category' );
	}
}
if ( ! function_exists( 'wp_get_object_terms' ) ) {
	function wp_get_object_terms( $id, $tax, $args = array() ) { // phpcs:ignore
		return array();
	}
}
if ( ! function_exists( 'maybe_unserialize' ) ) {
	function maybe_unserialize( $d ) { // phpcs:ignore
		return $d;
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $t ) { // phpcs:ignore
		return $t instanceof WP_Error;
	}
}
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private string $code;
		private string $message;
		private $data;
		public function __construct( $c = '', $m = '', $d = '' ) {
			$this->code    = (string) $c;
			$this->message = (string) $m;
			$this->data    = $d;
		}
		public function get_error_code() {
			return $this->code;
		}
		public function get_error_message() {
			return $this->message;
		}
	}
}
if ( ! class_exists( 'WP_Post' ) ) {
	class WP_Post {
	}
}
// Make test posts look like WP_Post for instanceof checks.
foreach ( $GLOBALS['seoauto_test_posts'] as $id => $p ) {
	$obj = new WP_Post();
	foreach ( (array) $p as $k => $v ) {
		$obj->$k = $v;
	}
	$GLOBALS['seoauto_test_posts'][ $id ] = $obj;
}

require_once dirname( __DIR__ ) . '/includes/Seo/Seo_Adapter_Interface.php';
require_once dirname( __DIR__ ) . '/includes/Seo/Native_Adapter.php';
require_once dirname( __DIR__ ) . '/includes/Seo/RankMath_Adapter.php';
require_once dirname( __DIR__ ) . '/includes/Seo/Yoast_Adapter.php';
require_once dirname( __DIR__ ) . '/includes/Seo/AIOSEO_Adapter.php';
require_once dirname( __DIR__ ) . '/includes/Seo/Seo_Payload.php';
require_once dirname( __DIR__ ) . '/includes/Seo/Seo_Facade.php';
require_once dirname( __DIR__ ) . '/includes/SeoAudit/Seo_Meta_Reader.php';
require_once dirname( __DIR__ ) . '/includes/ContentOps/Snapshot_Builder.php';
require_once dirname( __DIR__ ) . '/includes/ContentOps/Preview_Service.php';
require_once dirname( __DIR__ ) . '/includes/Audit/Audit_Logger.php';

use SEOAuto\SEOHelper\Audit\Audit_Logger;
use SEOAuto\SEOHelper\ContentOps\Preview_Service;
use SEOAuto\SEOHelper\ContentOps\Snapshot_Builder;
use SEOAuto\SEOHelper\Seo\Seo_Facade;

$seo     = new Seo_Facade();
$builder = new Snapshot_Builder( $seo );
$snap    = $builder->capture( 42 );
check( 'snapshot captures post', is_array( $snap ) && ( $snap['title'] ?? '' ) === 'Hello' );
check( 'snapshot has checksum', is_array( $snap ) && strlen( (string) ( $snap['checksum'] ?? '' ) ) === 64 );

$preview = new Preview_Service( $builder );
$p1      = $preview->preview_item( 42, array( 'title' => 'Hello' ), 'no change' );
check( 'preview no-op has_changes=false', is_array( $p1 ) && empty( $p1['has_changes'] ) && ! empty( $p1['read_only'] ) );

$p2 = $preview->preview_item( 42, array( 'title' => 'New Title', 'content' => 'Body B' ), 'fix title' );
check( 'preview detects changes', is_array( $p2 ) && ! empty( $p2['has_changes'] ) );
check( 'preview risk sensitive for content', is_array( $p2 ) && ( $p2['risk_level'] ?? '' ) === 'sensitive' );
check( 'preview does not mutate post', (string) get_post( 42 )->post_title === 'Hello' );

$batch = $preview->preview_batch(
	array(
		array( 'post_id' => 42, 'proposed' => array( 'title' => 'X' ), 'reason' => 'r' ),
		array( 'post_id' => 999, 'proposed' => array( 'title' => 'Y' ), 'reason' => 'r' ),
	)
);
check( 'preview batch summary mutates_data=false', ( $batch['summary']['mutates_data'] ?? true ) === false );
check( 'preview batch counts error for missing post', (int) ( $batch['summary']['errors'] ?? 0 ) === 1 );

$ref = new ReflectionClass( Audit_Logger::class );
$c   = $ref->getConstant( 'REDACT_KEYS' );
check( 'audit logger redacts signed_url', is_array( $c ) && in_array( 'signed_url', $c, true ) );
check( 'audit logger redacts presigned_url', is_array( $c ) && in_array( 'presigned_url', $c, true ) );

check( 'Schema DB_VERSION is 4', true ); // loaded below
require_once dirname( __DIR__ ) . '/includes/Post/Schema.php';
require_once dirname( __DIR__ ) . '/includes/ContentOps/ContentOps_Service.php';
check( 'Schema DB_VERSION is 4', \SEOAuto\SEOHelper\Post\Schema::DB_VERSION === 4 );
check( 'content_ops feature constant', \SEOAuto\SEOHelper\ContentOps\ContentOps_Service::FEATURE === 'content_ops' );

echo $failed === 0 ? "\nALL_CONTENT_OPS_PASS\n" : "\nCONTENT_OPS_FAIL count={$failed}\n";
exit( $failed === 0 ? 0 : 1 );
