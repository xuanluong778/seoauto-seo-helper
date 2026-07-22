<?php
/**
 * Network grace period tests (48h max, no self-extension).
 *
 * Run: php tests/test_network_grace.php
 *
 * @package SEOAuto\SEOHelper
 */

declare(strict_types=1);

define( 'SEOAUTO_HELPER_PREFIX', 'seoauto_helper_' );
define( 'SEOAUTO_HELPER_VERSION', '1.0.0-test' );

/** @var array<string,mixed> */
$GLOBALS['seoauto_test_options'] = array();

function get_option( string $name, $default = false ) {
	$opts = $GLOBALS['seoauto_test_options'];
	return array_key_exists( $name, $opts ) ? $opts[ $name ] : $default;
}

function add_option( string $name, $value, string $deprecated = '', bool $autoload = true ): bool {
	if ( array_key_exists( $name, $GLOBALS['seoauto_test_options'] ) ) {
		return false;
	}
	$GLOBALS['seoauto_test_options'][ $name ] = $value;
	return true;
}

function update_option( string $name, $value, bool $autoload = null ): bool {
	$GLOBALS['seoauto_test_options'][ $name ] = $value;
	return true;
}

function wp_json_encode( $data, int $options = 0, int $depth = 512 ) {
	return json_encode( $data, $options, $depth );
}

function sanitize_key( string $key ): string {
	return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', $key ) ?? '' );
}

function sanitize_text_field( $str ): string {
	return is_scalar( $str ) ? trim( (string) $str ) : '';
}

function __( string $text, string $domain = 'default' ): string {
	return $text;
}

function untrailingslashit( string $string ): string {
	return rtrim( $string, '/\\' );
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function __construct(
			private string $code,
			private string $message
		) {}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}
	}
}

require_once dirname( __DIR__ ) . '/includes/Security/Secret_Store.php';
require_once dirname( __DIR__ ) . '/includes/Connection/Connection_Manager.php';
require_once dirname( __DIR__ ) . '/includes/Entitlement/Entitlement_Client.php';
require_once dirname( __DIR__ ) . '/includes/Entitlement/Entitlement_Manager.php';
require_once dirname( __DIR__ ) . '/includes/Entitlement/Entitlement_Verifier.php';
require_once __DIR__ . '/security_helpers.php';

use SEOAuto\SEOHelper\Connection\Connection_Manager;
use SEOAuto\SEOHelper\Entitlement\Entitlement_Client;
use SEOAuto\SEOHelper\Entitlement\Entitlement_Manager;

$failed = 0;

/**
 * @param callable():bool $fn
 */
function check( string $msg, callable $fn ): void {
	global $failed;
	try {
		$ok = (bool) $fn();
	} catch ( Throwable $e ) {
		$ok = false;
		$msg .= ' [' . $e->getMessage() . ']';
	}
	if ( $ok ) {
		echo "PASS  {$msg}\n";
		return;
	}
	++$failed;
	echo "FAIL  {$msg}\n";
}

/**
 * @param array<string,mixed> $entitlement
 */
function pair_site( array $entitlement, array $extra = array() ): Connection_Manager {
	$signed = seoauto_test_signed_entitlement( $entitlement, 'plain-secret' );
	$sig    = (string) ( $signed['signature'] ?? '' );
	unset( $signed['signature'] );
	$GLOBALS['seoauto_test_options'] = array_merge(
		array(
			'seoauto_helper_status'                 => Connection_Manager::STATUS_CONNECTED,
			'seoauto_helper_site_id'                  => 'site-test-1',
			'seoauto_helper_site_secret'              => 'plain-secret',
			'seoauto_helper_connection_id'            => 42,
			'seoauto_helper_api_base'                 => 'https://seoauto.vn',
			'seoauto_helper_entitlement_json'         => wp_json_encode( $signed ),
			'seoauto_helper_entitlement_sig'          => $sig,
			'seoauto_helper_last_entitlement_was_active' => '1',
			'seoauto_helper_network_grace_until'      => gmdate( 'c', time() + 3600 ),
		),
		$extra
	);
	return new Connection_Manager();
}

/**
 * @param array<string,mixed> $response
 */
function manager_with_fetch( Connection_Manager $connection, array $response ): Entitlement_Manager {
	$client = new class( $connection, $response ) extends Entitlement_Client {
		/** @param array<string,mixed> $response */
		public function __construct(
			Connection_Manager $connection,
			private array $response
		) {
			parent::__construct( $connection );
		}

		public function fetch(): array {
			return $this->response;
		}
	};
	return new Entitlement_Manager( $connection, $client );
}

check(
	'is_wp_error_network detects timeout message',
	static fn(): bool => Entitlement_Client::is_wp_error_network(
		new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' )
	)
);

check(
	'is_wp_error_network rejects generic 401',
	static fn(): bool => ! Entitlement_Client::is_wp_error_network(
		new WP_Error( 'unauthorized', '401 Unauthorized' )
	)
);

check(
	'hard deny for revoked payload',
	static fn(): bool => Entitlement_Client::is_hard_deny_payload(
		array(
			'allowed' => false,
			'reason'  => 'revoked',
		)
	)
);

$connection = pair_site(
	array(
		'allowed'             => true,
		'reason'              => 'active',
		'plan_code'           => 'pro',
		'subscription_status' => 'active',
		'enabled_features'    => array( 'seo_helper' ),
	)
);
$entitlement = manager_with_fetch(
	$connection,
	array(
		'ok'            => false,
		'network_error' => true,
		'message'       => '502 Bad Gateway',
		'http_code'     => 502,
	)
);

check(
	'network error with valid grace keeps mutate',
	static function () use ( $entitlement ): bool {
		$result = $entitlement->refresh_check( 'test' );
		return $result['allowed'] && ! $result['locked']
			&& $result['reason'] === Entitlement_Manager::REASON_NETWORK_GRACE
			&& $entitlement->can_mutate();
	}
);

check(
	'network grace does not extend deadline on repeated failure',
	static function () use ( $entitlement, $connection ): bool {
		$before = (string) get_option( 'seoauto_helper_network_grace_until', '' );
		$entitlement->refresh_check( 'test2' );
		$after = (string) get_option( 'seoauto_helper_network_grace_until', '' );
		return $before === $after;
	}
);

$connection2 = pair_site(
	array(
		'allowed'             => true,
		'reason'              => 'active',
		'plan_code'           => 'pro',
		'subscription_status' => 'active',
		'enabled_features'    => array( 'seo_helper' ),
	),
	array(
		'seoauto_helper_network_grace_until' => gmdate( 'c', time() - 60 ),
	)
);
$entitlement2 = manager_with_fetch(
	$connection2,
	array(
		'ok'            => false,
		'network_error' => true,
		'message'       => '503 Service Unavailable',
	)
);

check(
	'expired network grace locks with connectivity_lost',
	static function () use ( $entitlement2 ): bool {
		$result = $entitlement2->refresh_check( 'test' );
		return ! $result['allowed'] && $result['locked']
			&& $result['reason'] === Entitlement_Manager::REASON_CONNECTIVITY_LOST;
	}
);

$connection3 = pair_site(
	array(
		'allowed'             => true,
		'reason'              => 'active',
		'plan_code'           => 'pro',
		'subscription_status' => 'active',
		'enabled_features'    => array( 'seo_helper' ),
	)
);
$entitlement3 = manager_with_fetch(
	$connection3,
	array(
		'ok'          => true,
		'hard_deny'   => true,
		'network_error' => false,
		'entitlement' => seoauto_test_signed_entitlement(
			array(
				'allowed'             => false,
				'reason'              => 'expired',
				'subscription_status' => 'expired',
				'enabled_features'    => array(),
			)
		),
	)
);

check(
	'backend expired locks immediately (no network grace)',
	static function () use ( $entitlement3, $connection3 ): bool {
		$result = $entitlement3->refresh_check( 'test' );
		return ! $result['allowed'] && $result['locked']
			&& $result['reason'] === Entitlement_Manager::REASON_EXPIRED
			&& Connection_Manager::STATUS_LOCKED === (string) $connection3->option( 'status', '' );
	}
);

$connection4 = pair_site(
	array(
		'allowed'             => true,
		'reason'              => 'active',
		'plan_code'           => 'pro',
		'subscription_status' => 'active',
		'enabled_features'    => array( 'seo_helper' ),
	)
);
$entitlement4 = new Entitlement_Manager( $connection4 );
$issued = gmdate( 'c', time() - 100 );
$entitlement4->store(
	seoauto_test_signed_entitlement(
		array(
			'allowed'             => true,
			'reason'              => 'active',
			'plan_code'           => 'pro',
			'subscription_status' => 'active',
			'enabled_features'    => array( 'seo_helper' ),
			'issued_at'           => $issued,
			'grace_until'         => gmdate( 'c', time() + 86400 * 5 ),
		)
	)
);
$stored_until = (string) get_option( 'seoauto_helper_network_grace_until', '' );
$stored_ts    = strtotime( $stored_until );
$max_ts       = strtotime( $issued ) + Entitlement_Manager::MAX_NETWORK_GRACE_SECONDS;

check(
	'store caps network grace at 48h from issued_at',
	static fn(): bool => false !== $stored_ts && false !== $max_ts && $stored_ts <= $max_ts + 1
);

echo "\n" . ( 0 === $failed ? 'ALL PASS' : "{$failed} FAILED" ) . "\n";
exit( $failed > 0 ? 1 : 0 );
