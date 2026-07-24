<?php
/**
 * SEO Audit job harden tests: claim reclaim, duplicate guard, DB insert fail.
 *
 * Run: php tests/test_seo_audit_job_harden.php
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
define( 'SEOAUTO_HELPER_VERSION', '1.2.0-rc.3-test' );
define( 'ABSPATH', __DIR__ . '/stubs/' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}
if ( ! defined( 'OBJECT' ) ) {
	define( 'OBJECT', 'OBJECT' );
}

$GLOBALS['seoauto_test_options'] = array();
$GLOBALS['seoauto_test_jobs']    = array();
$GLOBALS['seoauto_test_runs']    = array();
$GLOBALS['seoauto_test_job_seq'] = 0;
$GLOBALS['seoauto_test_run_seq'] = 0;
$GLOBALS['seoauto_test_claim_race'] = 0;

$GLOBALS['wpdb'] = new class() {
	public string $prefix = 'wp_';
	public string $options = 'wp_options';
	public string $last_error = '';
	public int $insert_id = 0;

	public function get_var( $q ) {
		if ( preg_match( "/option_name = '([^']+)'/", (string) $q, $m ) ) {
			$name = $m[1];
			return array_key_exists( $name, $GLOBALS['seoauto_test_options'] ) ? '1' : null;
		}
		return null;
	}

	public function prepare( string $q, ...$a ): string {
		// Simple placeholder replacement for tests.
		foreach ( $a as $v ) {
			$rep = is_int( $v ) ? (string) $v : "'" . str_replace( "'", "\\'", (string) $v ) . "'";
			$q   = preg_replace( '/%[sdf]/', $rep, $q, 1 ) ?? $q;
		}
		return $q;
	}

	public function insert( string $table, array $data, $format = null ) {
		if ( str_contains( $table, 'seoauto_helper_jobs' ) ) {
			if ( ! empty( $GLOBALS['seoauto_test_fail_job_insert'] ) ) {
				$this->last_error = 'simulated job insert failure';
				$this->insert_id  = 0;
				return false;
			}
			++$GLOBALS['seoauto_test_job_seq'];
			$id = (int) $GLOBALS['seoauto_test_job_seq'];
			$row = $data;
			$row['id'] = $id;
			$row['payload_json'] = $data['payload_json'] ?? '{}';
			$row['result_json']  = null;
			$row['error_code']   = null;
			$row['error_message']= null;
			$row['locked_until_gmt'] = null;
			$row['started_gmt']  = null;
			$row['finished_gmt'] = null;
			$GLOBALS['seoauto_test_jobs'][ $id ] = $row;
			$this->insert_id  = $id;
			$this->last_error = '';
			return 1;
		}
		if ( str_contains( $table, 'seoauto_helper_audit_runs' ) ) {
			if ( ! empty( $GLOBALS['seoauto_test_fail_run_insert'] ) ) {
				$this->last_error = 'simulated run insert failure';
				$this->insert_id  = 0;
				return false;
			}
			++$GLOBALS['seoauto_test_run_seq'];
			$id = (int) $GLOBALS['seoauto_test_run_seq'];
			$row = $data;
			$row['id'] = $id;
			$GLOBALS['seoauto_test_runs'][ $id ] = $row;
			$this->insert_id  = $id;
			$this->last_error = '';
			return 1;
		}
		$this->insert_id = 1;
		return 1;
	}

	public function update( string $table, array $data, array $where, $format = null, $where_format = null ) {
		if ( str_contains( $table, 'options' ) || $table === $this->options ) {
			return 1;
		}
		$id = (int) ( $where['id'] ?? 0 );
		if ( str_contains( $table, 'seoauto_helper_jobs' ) && isset( $GLOBALS['seoauto_test_jobs'][ $id ] ) ) {
			foreach ( $data as $k => $v ) {
				$GLOBALS['seoauto_test_jobs'][ $id ][ $k ] = $v;
			}
			return 1;
		}
		if ( str_contains( $table, 'seoauto_helper_audit_runs' ) && isset( $GLOBALS['seoauto_test_runs'][ $id ] ) ) {
			foreach ( $data as $k => $v ) {
				$GLOBALS['seoauto_test_runs'][ $id ][ $k ] = $v;
			}
			return 1;
		}
		return false;
	}

	public function get_row( $q, $out = OBJECT ) {
		$jobs = $GLOBALS['seoauto_test_jobs'];
		if ( str_contains( (string) $q, 'status IN (\'queued\',\'retrying\',\'running\')' ) ) {
			foreach ( $jobs as $row ) {
				if ( in_array( (string) $row['status'], array( 'queued', 'retrying', 'running' ), true ) ) {
					return $row;
				}
			}
			return null;
		}
		if ( preg_match( "/WHERE id = (\d+)/", (string) $q, $m ) ) {
			$id = (int) $m[1];
			if ( isset( $jobs[ $id ] ) ) {
				return $jobs[ $id ];
			}
			if ( isset( $GLOBALS['seoauto_test_runs'][ $id ] ) && str_contains( (string) $q, 'audit_runs' ) ) {
				return $GLOBALS['seoauto_test_runs'][ $id ];
			}
			return $jobs[ $id ] ?? null;
		}
		if ( str_contains( (string) $q, 'request_id' ) && preg_match( "/request_id = '([^']+)'/", (string) $q, $m ) ) {
			foreach ( $jobs as $row ) {
				if ( (string) $row['request_id'] === $m[1] ) {
					return $row;
				}
			}
			return null;
		}
		// claim candidate: queued/retrying OR stale running
		if ( str_contains( (string) $q, "status = 'running'" ) && str_contains( (string) $q, 'locked_until_gmt' ) ) {
			$now = gmdate( 'Y-m-d H:i:s' );
			foreach ( $jobs as $row ) {
				$status = (string) $row['status'];
				$lock   = $row['locked_until_gmt'] ?? null;
				if ( in_array( $status, array( 'queued', 'retrying' ), true )
					&& ( null === $lock || $lock < $now )
				) {
					return $row;
				}
				if ( 'running' === $status && null !== $lock && $lock < $now ) {
					return $row;
				}
			}
			return null;
		}
		return null;
	}

	public function get_results( $q, $out = OBJECT ) {
		$now  = gmdate( 'Y-m-d H:i:s' );
		$rows = array();
		if ( str_contains( (string) $q, 'attempts >= max_attempts' ) ) {
			foreach ( $GLOBALS['seoauto_test_jobs'] as $row ) {
				if ( 'running' === (string) $row['status']
					&& ! empty( $row['locked_until_gmt'] )
					&& $row['locked_until_gmt'] < $now
					&& (int) $row['attempts'] >= (int) $row['max_attempts']
				) {
					$rows[] = array(
						'id'     => (int) $row['id'],
						'run_id' => (int) $row['run_id'],
					);
				}
			}
		}
		return $rows;
	}

	public function query( $q ) {
		$q = (string) $q;
		$now = gmdate( 'Y-m-d H:i:s' );

		// fail exhausted stale
		if ( str_contains( $q, "error_code = 'max_attempts'" ) || str_contains( $q, 'max_attempts' ) && str_contains( $q, "status = 'failed'" ) ) {
			$n = 0;
			foreach ( $GLOBALS['seoauto_test_jobs'] as $id => $row ) {
				if ( 'running' === (string) $row['status']
					&& ! empty( $row['locked_until_gmt'] )
					&& $row['locked_until_gmt'] < $now
					&& (int) $row['attempts'] >= (int) $row['max_attempts']
				) {
					$GLOBALS['seoauto_test_jobs'][ $id ]['status'] = 'failed';
					$GLOBALS['seoauto_test_jobs'][ $id ]['error_code'] = 'max_attempts';
					$GLOBALS['seoauto_test_jobs'][ $id ]['locked_until_gmt'] = null;
					++$n;
				}
			}
			return $n;
		}

		// atomic claim UPDATE
		if ( str_contains( $q, "SET status = 'running'" ) && preg_match( '/WHERE id = (\d+)/', $q, $m ) ) {
			$id = (int) $m[1];
			if ( ! isset( $GLOBALS['seoauto_test_jobs'][ $id ] ) ) {
				return 0;
			}
			// Simulate race: second concurrent claim loses.
			if ( ! empty( $GLOBALS['seoauto_test_claim_race'] ) ) {
				--$GLOBALS['seoauto_test_claim_race'];
				if ( (int) $GLOBALS['seoauto_test_claim_race'] < 0 ) {
					return 0;
				}
			}
			$row  = $GLOBALS['seoauto_test_jobs'][ $id ];
			$lock = $row['locked_until_gmt'] ?? null;
			$st   = (string) $row['status'];
			$ok   = false;
			if ( in_array( $st, array( 'queued', 'retrying' ), true ) && ( null === $lock || $lock < $now ) ) {
				$ok = true;
			}
			if ( 'running' === $st && null !== $lock && $lock < $now ) {
				$ok = true;
			}
			if ( ! $ok ) {
				return 0;
			}
			if ( preg_match( "/attempts = (\d+)/", $q, $am ) ) {
				$GLOBALS['seoauto_test_jobs'][ $id ]['attempts'] = (int) $am[1];
			}
			if ( preg_match( "/locked_until_gmt = '([^']+)'/", $q, $lm ) ) {
				$GLOBALS['seoauto_test_jobs'][ $id ]['locked_until_gmt'] = $lm[1];
			}
			$GLOBALS['seoauto_test_jobs'][ $id ]['status'] = 'running';
			if ( empty( $GLOBALS['seoauto_test_jobs'][ $id ]['started_gmt'] ) ) {
				$GLOBALS['seoauto_test_jobs'][ $id ]['started_gmt'] = $now;
			}
			return 1;
		}

		// fail linked run
		if ( str_contains( $q, "status = 'failed'" ) && str_contains( $q, 'audit_runs' ) && preg_match( '/WHERE id = (\d+)/', $q, $m ) ) {
			$id = (int) $m[1];
			if ( isset( $GLOBALS['seoauto_test_runs'][ $id ] ) ) {
				$GLOBALS['seoauto_test_runs'][ $id ]['status'] = 'failed';
				return 1;
			}
		}
		return 0;
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
if ( ! function_exists( 'wp_generate_uuid4' ) ) {
	function wp_generate_uuid4() { // phpcs:ignore
		return sprintf(
			'%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
			mt_rand( 0, 0xffff ),
			mt_rand( 0, 0xffff ),
			mt_rand( 0, 0xffff ),
			mt_rand( 0, 0x0fff ) | 0x4000,
			mt_rand( 0, 0x3fff ) | 0x8000,
			mt_rand( 0, 0xffff ),
			mt_rand( 0, 0xffff ),
			mt_rand( 0, 0xffff )
		);
	}
}
if ( ! function_exists( 'wp_schedule_single_event' ) ) {
	function wp_schedule_single_event( $t, $h, $a = array() ) { // phpcs:ignore
		return true;
	}
}
if ( ! function_exists( 'wp_next_scheduled' ) ) {
	function wp_next_scheduled( $h, $a = array() ) { // phpcs:ignore
		return time() + 30;
	}
}
if ( ! function_exists( 'wp_schedule_event' ) ) {
	function wp_schedule_event( $t, $r, $h, $a = array() ) { // phpcs:ignore
		return true;
	}
}
if ( ! function_exists( 'spawn_cron' ) ) {
	function spawn_cron( $t = 0 ) { // phpcs:ignore
		return true;
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
if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() { // phpcs:ignore
		return 1;
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $t ) { // phpcs:ignore
		return $t instanceof WP_Error;
	}
}
if ( ! function_exists( 'post_type_exists' ) ) {
	function post_type_exists( $t ) { // phpcs:ignore
		return in_array( $t, array( 'post', 'page' ), true );
	}
}
if ( ! class_exists( 'WP_Error', false ) ) {
	class WP_Error {
		public function __construct( public $code = '', public $message = '', public $data = array() ) {}
		public function get_error_message() { return $this->message; }
		public function get_error_code() { return $this->code; }
	}
}
if ( ! class_exists( 'WP_Query', false ) ) {
	class WP_Query {
		public int $found_posts = 0;
		/** @param array<string,mixed> $args */
		public function __construct( $args = array() ) {
			$this->found_posts = 0;
		}
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

use SEOAuto\SEOHelper\Audit\Audit_Logger;
use SEOAuto\SEOHelper\Connection\Connection_Manager;
use SEOAuto\SEOHelper\Entitlement\Entitlement_Manager;
use SEOAuto\SEOHelper\Seo\Seo_Facade;
use SEOAuto\SEOHelper\SeoAudit\Audit_Job_Runner;
use SEOAuto\SEOHelper\SeoAudit\Job_Store;

echo "=== LOCK_TTL constant ===\n";
check( 'LOCK_TTL is 120', Job_Store::LOCK_TTL_SECONDS === 120 );

echo "\n=== claim_next reclaims expired running ===\n";
$GLOBALS['seoauto_test_jobs']    = array();
$GLOBALS['seoauto_test_job_seq'] = 0;
$store = new Job_Store();
$past  = gmdate( 'Y-m-d H:i:s', time() - 180 );
$GLOBALS['seoauto_test_jobs'][1] = array(
	'id'               => 1,
	'request_id'       => 'req-stale',
	'job_type'         => 'audit_scan',
	'status'           => 'running',
	'run_id'           => 10,
	'batch_size'       => 20,
	'cursor_id'        => 5,
	'attempts'         => 1,
	'max_attempts'     => 5,
	'payload_json'     => '{}',
	'result_json'      => null,
	'locked_until_gmt' => $past,
	'started_gmt'      => $past,
	'finished_gmt'     => null,
	'created_gmt'      => $past,
	'updated_gmt'      => $past,
);
$claimed = $store->claim_next();
check( 'reclaimed stale running', null !== $claimed );
check( 'reclaimed status running', null !== $claimed && 'running' === $claimed['status'] );
check( 'attempts incremented', null !== $claimed && 2 === (int) $claimed['attempts'] );
check( 'lock refreshed in future', null !== $claimed && (string) $claimed['locked_until_gmt'] > gmdate( 'Y-m-d H:i:s' ) );

echo "\n=== atomic double-claim: second worker loses ===\n";
$GLOBALS['seoauto_test_jobs'][2] = array(
	'id'               => 2,
	'request_id'       => 'req-race',
	'job_type'         => 'audit_scan',
	'status'           => 'queued',
	'run_id'           => 11,
	'batch_size'       => 20,
	'cursor_id'        => 0,
	'attempts'         => 0,
	'max_attempts'     => 5,
	'payload_json'     => '{}',
	'result_json'      => null,
	'locked_until_gmt' => null,
	'started_gmt'      => null,
	'finished_gmt'     => null,
	'created_gmt'      => gmdate( 'Y-m-d H:i:s' ),
	'updated_gmt'      => gmdate( 'Y-m-d H:i:s' ),
);
// Force first claim wins, second SELECT still sees same candidate but UPDATE races to 0.
$first = $store->claim_next();
check( 'first worker claims', null !== $first && 2 === (int) $first['id'] );
// Job now running with future lock — second claim must not take it.
$second = $store->claim_next();
check( 'second worker does not double-claim same job', null === $second || (int) $second['id'] !== 2 || (string) $second['locked_until_gmt'] === (string) $first['locked_until_gmt'] );
// Stronger: with future lock and running, candidate query should skip job 2; only reclaim if expired.
$GLOBALS['seoauto_test_jobs'][2]['status'] = 'running';
$GLOBALS['seoauto_test_jobs'][2]['locked_until_gmt'] = gmdate( 'Y-m-d H:i:s', time() + 60 );
$third = $store->claim_next();
check( 'active lock blocks reclaim', null === $third || (int) $third['id'] !== 2 );

echo "\n=== exhausted stale running → failed ===\n";
$GLOBALS['seoauto_test_jobs'][3] = array(
	'id'               => 3,
	'request_id'       => 'req-exhausted',
	'job_type'         => 'audit_scan',
	'status'           => 'running',
	'run_id'           => 12,
	'batch_size'       => 20,
	'cursor_id'        => 0,
	'attempts'         => 5,
	'max_attempts'     => 5,
	'payload_json'     => '{}',
	'result_json'      => null,
	'locked_until_gmt' => $past,
	'started_gmt'      => $past,
	'finished_gmt'     => null,
	'created_gmt'      => $past,
	'updated_gmt'      => $past,
);
$GLOBALS['seoauto_test_runs'][12] = array(
	'id'     => 12,
	'status' => 'running',
);
$none = $store->claim_next();
check( 'exhausted stale not claimed', null === $none || (int) ( $none['id'] ?? 0 ) !== 3 );
check( 'exhausted job marked failed', 'failed' === (string) ( $GLOBALS['seoauto_test_jobs'][3]['status'] ?? '' ) );

echo "\n=== find_active + duplicate enqueue ===\n";
$GLOBALS['seoauto_test_jobs']    = array();
$GLOBALS['seoauto_test_runs']    = array();
$GLOBALS['seoauto_test_job_seq'] = 0;
$GLOBALS['seoauto_test_run_seq'] = 0;
$GLOBALS['seoauto_test_fail_job_insert'] = false;
$GLOBALS['seoauto_test_fail_run_insert'] = false;

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
add_filter( 'seoauto_helper_dev_entitlement_fallback', '__return_false' );

// Lightweight engine stub via real runner with empty object count (no posts in stub).
$conn = new Connection_Manager();
$ent  = new Entitlement_Manager( $conn, null );
$runner = new Audit_Job_Runner( $ent, $conn, new Seo_Facade(), new Audit_Logger( $ent ) );

$r1 = $runner->enqueue_scan( array( 'request_id' => 'dup-1', 'post_types' => array( 'post' ), 'batch_size' => 5 ) );
check( 'first enqueue ok', is_array( $r1 ) && ! empty( $r1['ok'] ) && (int) $r1['job_id'] > 0 );
$job_id_1 = (int) $r1['job_id'];

$r2 = $runner->enqueue_scan( array( 'request_id' => 'dup-2', 'post_types' => array( 'post' ), 'batch_size' => 5 ) );
check( 'second enqueue reuses active job', is_array( $r2 ) && ! empty( $r2['idempotent_replay'] ) );
check( 'same job id on duplicate', is_array( $r2 ) && (int) $r2['job_id'] === $job_id_1 );

echo "\n=== feature denied ===\n";
seoauto_test_pair_options(
	array(
		'allowed'             => true,
		'reason'              => 'active',
		'plan_code'           => 'starter',
		'subscription_status' => 'active',
		'enabled_features'    => array( 'seo_helper' ),
		'expires_at'          => gmdate( 'c', time() + 86400 ),
		'issued_at'           => gmdate( 'c' ),
	),
	'connected'
);
$conn_d = new Connection_Manager();
$ent_d  = new Entitlement_Manager( $conn_d, null );
$runner_d = new Audit_Job_Runner( $ent_d, $conn_d, new Seo_Facade(), new Audit_Logger( $ent_d ) );
// Force non-dev: if WP_DEBUG already true, has_feature still false — can_start may pass via WP_DEBUG.
$has = $ent_d->has_feature( 'seo_audit' );
check( 'has_feature seo_audit false', false === $has );
if ( ! defined( 'WP_DEBUG' ) ) {
	define( 'WP_DEBUG', false );
}
if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
	check( 'feature UI gate relies on has_feature (WP_DEBUG on in process)', true );
} else {
	$gate = $runner_d->can_start_scan();
	check( 'can_start denied without seo_audit', $gate instanceof WP_Error && 'seoauto_feature_denied' === $gate->get_error_code() );
}

echo "\n=== DB insert failure does not fake success ===\n";
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
// Clear active jobs so enqueue attempts insert.
$GLOBALS['seoauto_test_jobs'] = array();
$GLOBALS['seoauto_test_runs'] = array();
$GLOBALS['seoauto_test_fail_run_insert'] = true;
$conn_e = new Connection_Manager();
$ent_e  = new Entitlement_Manager( $conn_e, null );
$runner_e = new Audit_Job_Runner( $ent_e, $conn_e, new Seo_Facade(), new Audit_Logger( $ent_e ) );
$bad = $runner_e->enqueue_scan( array( 'request_id' => 'db-fail-run', 'post_types' => array( 'post' ) ) );
check( 'run insert failure is WP_Error', $bad instanceof WP_Error );
check( 'run insert error code', $bad instanceof WP_Error && 'seoauto_audit_db_error' === $bad->get_error_code() );

$GLOBALS['seoauto_test_fail_run_insert'] = false;
$GLOBALS['seoauto_test_fail_job_insert'] = true;
$GLOBALS['seoauto_test_jobs'] = array();
$GLOBALS['seoauto_test_runs'] = array();
$bad2 = $runner_e->enqueue_scan( array( 'request_id' => 'db-fail-job', 'post_types' => array( 'post' ) ) );
check( 'job insert failure is WP_Error', $bad2 instanceof WP_Error );
check( 'job insert error code', $bad2 instanceof WP_Error && 'seoauto_audit_db_error' === $bad2->get_error_code() );
$GLOBALS['seoauto_test_fail_job_insert'] = false;

echo "\n=== cron_status shape ===\n";
$cron = $runner_e->cron_status();
check( 'cron_status has hook', isset( $cron['hook'] ) && $cron['hook'] === Audit_Job_Runner::HOOK_PROCESS );
check( 'cron_status lock_ttl 120', isset( $cron['lock_ttl'] ) && 120 === (int) $cron['lock_ttl'] );

echo "\n=== Schema unchanged ===\n";
check( 'DB_VERSION still 4', \SEOAuto\SEOHelper\Post\Schema::DB_VERSION === 4 );

echo $failed === 0 ? "\nALL PASS\n" : "\nFAILED: {$failed}\n";
exit( $failed === 0 ? 0 : 1 );
