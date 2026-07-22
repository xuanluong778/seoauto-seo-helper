<?php
/**
 * Entitlement lock / unlock tests.
 *
 * Run: php tests/test_entitlement_lock.php
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

require_once dirname( __DIR__ ) . '/includes/Security/Secret_Store.php';
require_once dirname( __DIR__ ) . '/includes/Entitlement/Entitlement_Client.php';
require_once dirname( __DIR__ ) . '/includes/Connection/Connection_Manager.php';
require_once dirname( __DIR__ ) . '/includes/Entitlement/Entitlement_Manager.php';
require_once dirname( __DIR__ ) . '/includes/Entitlement/Entitlement_Verifier.php';
require_once __DIR__ . '/security_helpers.php';

use SEOAuto\SEOHelper\Connection\Connection_Manager;
use SEOAuto\SEOHelper\Entitlement\Entitlement_Client;
use SEOAuto\SEOHelper\Entitlement\Entitlement_Manager;

$failed = 0;

/**
 * Entitlement checks without remote fetch (local cache evaluate only).
 */
function manager_local( Connection_Manager $connection ): Entitlement_Manager {
	$client = new class( $connection ) extends Entitlement_Client {
		public function fetch(): array {
			return array(
				'ok'            => false,
				'skipped'       => true,
				'network_error' => false,
				'hard_deny'     => false,
				'http_code'     => 404,
				'message'       => 'endpoint_not_found',
			);
		}
	};
	return new Entitlement_Manager( $connection, $client );
}

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
function pair_site( array $entitlement, string $status = Connection_Manager::STATUS_CONNECTED ): void {
	seoauto_test_pair_options( $entitlement, $status );
}

$connection  = new Connection_Manager();
$entitlement = manager_local( $connection );

pair_site(
	array(
		'allowed'             => true,
		'reason'              => 'active',
		'plan_code'           => 'pro',
		'subscription_status' => 'active',
		'enabled_features'    => array( 'seo_helper' ),
	)
);

check(
	'active entitlement is not locked',
	static fn(): bool => ! $entitlement->is_locked() && $entitlement->can_mutate()
);

check(
	'refresh_check keeps connected status',
	static function () use ( $entitlement, $connection ): bool {
		$result = $entitlement->refresh_check( 'test' );
		return $result['allowed'] && ! $result['locked']
			&& Connection_Manager::STATUS_CONNECTED === (string) $connection->option( 'status', '' );
	}
);

pair_site(
	array(
		'allowed'             => true,
		'reason'              => 'active',
		'plan_code'           => 'pro',
		'subscription_status' => 'active',
		'enabled_features'    => array( 'seo_helper' ),
		'expires_at'          => gmdate( 'c', time() - 86400 ),
	)
);

check(
	'expired entitlement locks plugin',
	static function () use ( $entitlement, $connection ): bool {
		$result = $entitlement->refresh_check( 'test' );
		return ! $result['allowed'] && $result['locked']
			&& $result['reason'] === Entitlement_Manager::REASON_EXPIRED
			&& Connection_Manager::STATUS_LOCKED === (string) $connection->option( 'status', '' );
	}
);

check(
	'locked site cannot mutate',
	static fn(): bool => $entitlement->is_locked() && ! $entitlement->can_mutate()
);

pair_site(
	array(
		'allowed'             => false,
		'reason'              => 'canceled',
		'plan_code'           => 'starter',
		'subscription_status' => 'canceled',
		'enabled_features'    => array(),
	)
);

check(
	'canceled entitlement locks plugin',
	static function () use ( $entitlement ): bool {
		$result = $entitlement->refresh_check( 'test' );
		return $result['locked'] && $result['reason'] === Entitlement_Manager::REASON_CANCELED;
	}
);

pair_site(
	array(
		'allowed'             => true,
		'reason'              => 'downgraded',
		'plan_code'           => 'free',
		'subscription_status' => 'active',
		'enabled_features'    => array(),
	)
);

check(
	'downgraded plan locks plugin',
	static function () use ( $entitlement ): bool {
		$result = $entitlement->refresh_check( 'test' );
		return $result['locked'] && $result['reason'] === Entitlement_Manager::REASON_DOWNGRADED;
	}
);

pair_site(
	array(
		'allowed'             => true,
		'reason'              => 'plan_not_supported',
		'plan_code'           => 'legacy',
		'subscription_status' => 'active',
		'enabled_features'    => array( 'seo_helper' ),
	)
);

check(
	'unsupported plan locks plugin',
	static function () use ( $entitlement ): bool {
		$result = $entitlement->refresh_check( 'test' );
		return $result['locked'] && $result['reason'] === Entitlement_Manager::REASON_NOT_SUPPORTED;
	}
);

pair_site(
	array(
		'allowed'             => true,
		'reason'              => 'active',
		'plan_code'           => 'pro',
		'subscription_status' => 'active',
		'enabled_features'    => array( 'seo_helper' ),
	),
	Connection_Manager::STATUS_LOCKED
);

check(
	'store() auto-unlocks without reinstall',
	static function () use ( $entitlement, $connection ): bool {
		$entitlement->store(
			seoauto_test_signed_entitlement(
				array(
					'allowed'             => true,
					'reason'              => 'active',
					'plan_code'           => 'pro',
					'subscription_status' => 'active',
					'enabled_features'    => array( 'seo_helper' ),
				)
			)
		);
		return ! $entitlement->is_locked()
			&& $entitlement->can_mutate()
			&& Connection_Manager::STATUS_CONNECTED === (string) $connection->option( 'status', '' );
	}
);

pair_site(
	array(
		'allowed'             => true,
		'reason'              => 'active',
		'plan_code'           => 'pro',
		'subscription_status' => 'active',
		'enabled_features'    => array( 'seo_helper' ),
		'expires_at'          => gmdate( 'c', time() - 86400 ),
	),
	Connection_Manager::STATUS_LOCKED
);
$entitlement->refresh_check( 'test' );

check(
	'audit cron_sync blocked while locked',
	static fn(): bool => ! $entitlement->should_log_audit( 'cron_sync' )
);

check(
	'audit admin_entitlement_refresh allowed while locked',
	static fn(): bool => $entitlement->should_log_audit( 'admin_entitlement_refresh' )
);

echo "\n" . ( 0 === $failed ? 'ALL PASS' : "{$failed} FAILED" ) . "\n";
exit( $failed > 0 ? 1 : 0 );
