<?php
/**
 * HMAC auth tests — wrong signature, stale timestamp, nonce replay, tampered body.
 *
 * Run: php tests/test_hmac_auth.php
 *
 * @package SEOAuto\SEOHelper
 */

declare(strict_types=1);

define( 'SEOAUTO_HELPER_PREFIX', 'seoauto_helper_' );

require_once dirname( __DIR__ ) . '/includes/Auth/Hmac_Signer.php';

use SEOAuto\SEOHelper\Auth\Hmac_Signer;

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

$secret = 'test-site-secret-value';
$method = 'POST';
$path   = '/wp-json/seoauto/v1/posts';
$ts     = (string) time();
$nonce  = 'nonce-abc-001';
$req_id = 'req-uuid-001';
$body   = '{"title":"Hello","content":"<p>Hi</p>"}';

$sig = Hmac_Signer::sign( $secret, $method, $path, $ts, $nonce, $req_id, $body );

check(
	'valid signature verifies with hash_equals path',
	static fn(): bool => Hmac_Signer::verify( $secret, $sig, $method, $path, $ts, $nonce, $req_id, $body )
);

check(
	'rejects wrong signature',
	static fn(): bool => ! Hmac_Signer::verify( $secret, '00' . substr( $sig, 2 ), $method, $path, $ts, $nonce, $req_id, $body )
);

$tampered = '{"title":"HACKED","content":"<p>Hi</p>"}';
check(
	'rejects tampered body',
	static fn(): bool => ! Hmac_Signer::verify( $secret, $sig, $method, $path, $ts, $nonce, $req_id, $tampered )
);

check(
	'path trailing slash normalized',
	static fn(): bool => Hmac_Signer::normalize_path( '/wp-json/seoauto/v1/posts/' ) === '/wp-json/seoauto/v1/posts'
);

check(
	'path query stripped',
	static fn(): bool => Hmac_Signer::normalize_path( '/wp-json/seoauto/v1/posts?x=1' ) === '/wp-json/seoauto/v1/posts'
);

$old_ts = (string) ( time() - 600 );
$skew   = abs( time() - (int) $old_ts );
check(
	'old timestamp exceeds 5 minute skew policy',
	static fn(): bool => $skew > Hmac_Signer::MAX_SKEW_SECONDS
);

// Nonce replay (in-memory mirror of Nonce_Store::claim).
$used = array();
$claim = static function ( string $n ) use ( &$used ): bool {
	if ( isset( $used[ $n ] ) ) {
		return false;
	}
	$used[ $n ] = time();
	return true;
};
check( 'first nonce claim ok', static function () use ( $claim, $nonce ): bool { return $claim( $nonce ); } );
check( 'nonce replay rejected', static function () use ( $claim, $nonce ): bool { return ! $claim( $nonce ); } );

$headers = Hmac_Signer::build_headers( 'wps_abc', 42, $secret, $method, $path, $body );
foreach (
	array(
		'X-SEOAuto-Site-ID',
		'X-SEOAuto-Connection-ID',
		'X-SEOAuto-Timestamp',
		'X-SEOAuto-Nonce',
		'X-SEOAuto-Request-ID',
		'X-SEOAuto-Signature',
	) as $h
) {
	check(
		"required header present: {$h}",
		static function () use ( $headers, $h ): bool {
			return isset( $headers[ $h ] ) && $headers[ $h ] !== '';
		}
	);
}

check(
	'build_headers signature verifies',
	static function () use ( $headers, $secret, $method, $path, $body ): bool {
		return Hmac_Signer::verify(
			$secret,
			$headers['X-SEOAuto-Signature'],
			$method,
			$path,
			$headers['X-SEOAuto-Timestamp'],
			$headers['X-SEOAuto-Nonce'],
			$headers['X-SEOAuto-Request-ID'],
			$body
		);
	}
);

// Authenticator policy simulation (same checks as Request_Authenticator).
$simulate_auth = static function (
	string $secret_in,
	string $sig_in,
	string $ts_in,
	string $nonce_in,
	string $body_in,
	array &$nonce_bag
): string {
	if ( abs( time() - (int) $ts_in ) > Hmac_Signer::MAX_SKEW_SECONDS ) {
		return 'seoauto_timestamp_expired';
	}
	if ( isset( $nonce_bag[ $nonce_in ] ) ) {
		return 'seoauto_nonce_replay';
	}
	$nonce_bag[ $nonce_in ] = time();
	if ( ! Hmac_Signer::verify( $secret_in, $sig_in, 'POST', '/wp-json/seoauto/v1/posts', $ts_in, $nonce_in, 'rid-1', $body_in ) ) {
		return 'seoauto_bad_signature';
	}
	return 'ok';
};

$bag = array();
$good_sig = Hmac_Signer::sign( $secret, 'POST', '/wp-json/seoauto/v1/posts', $ts, 'n-1', 'rid-1', $body );
check(
	'simulated auth accepts valid request',
	static function () use ( $simulate_auth, $secret, $good_sig, $ts, $body, &$bag ): bool {
		return $simulate_auth( $secret, $good_sig, $ts, 'n-1', $body, $bag ) === 'ok';
	}
);
check(
	'simulated auth rejects wrong signature',
	static function () use ( $simulate_auth, $secret, $ts, $body, &$bag ): bool {
		return $simulate_auth( $secret, 'bad', $ts, 'n-2', $body, $bag ) === 'seoauto_bad_signature';
	}
);
check(
	'simulated auth rejects old timestamp',
	static function () use ( $simulate_auth, $secret, $old_ts, $body, &$bag ): bool {
		return $simulate_auth(
			$secret,
			Hmac_Signer::sign( $secret, 'POST', '/wp-json/seoauto/v1/posts', $old_ts, 'n-3', 'rid-1', $body ),
			$old_ts,
			'n-3',
			$body,
			$bag
		) === 'seoauto_timestamp_expired';
	}
);
$bag2 = array();
$sig2 = Hmac_Signer::sign( $secret, 'POST', '/wp-json/seoauto/v1/posts', $ts, 'n-replay', 'rid-1', $body );
$simulate_auth( $secret, $sig2, $ts, 'n-replay', $body, $bag2 );
check(
	'simulated auth rejects nonce replay',
	static function () use ( $simulate_auth, $secret, $sig2, $ts, $body, &$bag2 ): bool {
		return $simulate_auth( $secret, $sig2, $ts, 'n-replay', $body, $bag2 ) === 'seoauto_nonce_replay';
	}
);
check(
	'simulated auth rejects tampered body',
	static function () use ( $simulate_auth, $secret, $good_sig, $ts, $tampered, &$bag ): bool {
		return $simulate_auth( $secret, $good_sig, $ts, 'n-4', $tampered, $bag ) === 'seoauto_bad_signature';
	}
);

echo $failed === 0 ? "\nAll tests passed.\n" : "\n{$failed} test(s) failed.\n";
exit( $failed === 0 ? 0 : 1 );
