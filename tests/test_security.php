<?php
/**
 * Security regression suite — auth, tenant, entitlement, idempotency, SSRF, rate limit.
 *
 * Run: php tests/test_security.php
 *
 * @package SEOAuto\SEOHelper
 */

declare(strict_types=1);

define( 'SEOAUTO_HELPER_PREFIX', 'seoauto_helper_' );
define( 'SEOAUTO_HELPER_VERSION', '1.0.0-test' );

$GLOBALS['seoauto_test_options'] = array();
$GLOBALS['seoauto_test_transients'] = array();

require_once __DIR__ . '/security_helpers.php';
require_once dirname( __DIR__ ) . '/includes/Auth/Hmac_Signer.php';
require_once dirname( __DIR__ ) . '/includes/Auth/Nonce_Store.php';
require_once dirname( __DIR__ ) . '/includes/Auth/Rate_Limiter.php';
require_once dirname( __DIR__ ) . '/includes/Security/Secret_Store.php';
require_once dirname( __DIR__ ) . '/includes/Connection/Connection_Manager.php';
require_once dirname( __DIR__ ) . '/includes/Entitlement/Entitlement_Manager.php';
require_once dirname( __DIR__ ) . '/includes/Entitlement/Entitlement_Client.php';
require_once dirname( __DIR__ ) . '/includes/Auth/Request_Authenticator.php';
require_once dirname( __DIR__ ) . '/includes/Media/Url_Safety.php';
require_once dirname( __DIR__ ) . '/includes/Media/Mime_Guard.php';

use SEOAuto\SEOHelper\Auth\Hmac_Signer;
use SEOAuto\SEOHelper\Auth\Nonce_Store;
use SEOAuto\SEOHelper\Auth\Rate_Limiter;
use SEOAuto\SEOHelper\Auth\Request_Authenticator;
use SEOAuto\SEOHelper\Connection\Connection_Manager;
use SEOAuto\SEOHelper\Entitlement\Entitlement_Client;
use SEOAuto\SEOHelper\Entitlement\Entitlement_Manager;
use SEOAuto\SEOHelper\Entitlement\Entitlement_Verifier;
use SEOAuto\SEOHelper\Media\Mime_Guard;
use SEOAuto\SEOHelper\Media\Url_Safety;

// --- WP stubs ---
function get_option( string $name, $default = false ) {
	return $GLOBALS['seoauto_test_options'][ $name ] ?? $default;
}
function update_option( string $name, $value, $autoload = null ): bool {
	$GLOBALS['seoauto_test_options'][ $name ] = $value;
	return true;
}
function add_option( string $name, $value, string $deprecated = '', bool $autoload = true ): bool {
	if ( isset( $GLOBALS['seoauto_test_options'][ $name ] ) ) {
		return false;
	}
	$GLOBALS['seoauto_test_options'][ $name ] = $value;
	return true;
}
function delete_option( string $name ): bool {
	unset( $GLOBALS['seoauto_test_options'][ $name ] );
	return true;
}
function get_transient( string $key ) {
	return $GLOBALS['seoauto_test_transients'][ $key ] ?? false;
}
function set_transient( string $key, $value, int $expiration ): bool {
	$GLOBALS['seoauto_test_transients'][ $key ] = $value;
	return true;
}
function wp_json_encode( $data, int $options = 0, int $depth = 512 ) {
	return json_encode( $data, $options, $depth );
}
function __( string $text, string $domain = 'default' ): string { return $text; }
function is_ssl(): bool { return true; }
function home_url( string $path = '' ): string { return 'https://example.com' . $path; }
function wp_parse_url( string $url, int $component = -1 ) { return parse_url( $url, $component ); }
function esc_url_raw( string $url ): string { return $url; }
function apply_filters( string $tag, $value, ...$args ) { return $value; }
function sanitize_key( string $key ): string { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', $key ) ?? '' ); }
function sanitize_text_field( $str ): string { return is_scalar( $str ) ? trim( (string) $str ) : ''; }
function untrailingslashit( string $string ): string { return rtrim( $string, '/\\' ); }

if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request {
		private string $method;
		/** @var array<string,string> */
		private array $headers = array();
		private string $body = '';
		public function __construct( string $method = 'POST', string $route = '' ) {
			$this->method = $method;
		}
		public function get_method(): string { return $this->method; }
		public function get_header( string $name ): ?string {
			$key = strtolower( $name );
			return $this->headers[ $key ] ?? null;
		}
		public function set_header( string $name, string $value ): void {
			$this->headers[ strtolower( $name ) ] = $value;
		}
		public function get_body(): string { return $this->body; }
		public function set_body( string $body ): void { $this->body = $body; }
		public function get_route(): string { return '/seoauto/v1/posts'; }
		public function get_param( string $key ) { return null; }
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private string $code;
		private string $message;
		/** @var array<string,mixed> */
		private array $data;
		public function __construct( string $code, string $message, array $data = array() ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}
		public function get_error_code(): string { return $this->code; }
		public function get_error_message(): string { return $this->message; }
	}
}

$failed = 0;
function check( string $msg, callable $fn ): void {
	global $failed;
	try {
		$ok = (bool) $fn();
	} catch ( Throwable $e ) {
		$ok = false;
		$msg .= ' [' . $e->getMessage() . ']';
	}
	echo ( $ok ? 'PASS' : 'FAIL' ) . "  {$msg}\n";
	if ( ! $ok ) {
		++$failed;
	}
}

function signed_request( string $secret, string $method, string $path, string $body, string $req_id, string $nonce, string $ts ): array {
	return Hmac_Signer::build_headers( 'site-test-1', 42, $secret, $method, $path, $body, (int) $ts, $nonce, $req_id );
}

seoauto_test_pair_options(
	array(
		'allowed'          => true,
		'reason'           => 'active',
		'enabled_features' => array( 'seo_helper' ),
	)
);

$connection = new Connection_Manager();
$client     = new class( $connection ) extends Entitlement_Client {
	public function fetch(): array {
		return array( 'ok' => false, 'skipped' => true );
	}
};
$entitlement = new Entitlement_Manager( $connection, $client );
$auth        = new Request_Authenticator( $connection, $entitlement, new Nonce_Store(), new Rate_Limiter( 5, 60 ) );

check(
	'bad signature rejected',
	static function () use ( $auth ): bool {
		$req = new WP_REST_Request( 'POST' );
		$req->set_body( '{"title":"x","content":"y"}' );
		$headers = signed_request( 'secret-a', 'POST', '/wp-json/seoauto/v1/posts', $req->get_body(), 'r1', 'n1', (string) time() );
		foreach ( $headers as $k => $v ) {
			$req->set_header( $k, $v );
		}
		$req->set_header( 'X-SEOAuto-Signature', '00' . substr( $headers['X-SEOAuto-Signature'], 2 ) );
		$result = $auth->authenticate( $req, array( 'require_entitlement' => true, 'feature' => 'seo_helper' ) );
		return $result instanceof WP_Error && $result->get_error_code() === 'seoauto_bad_signature';
	}
);

check(
	'stale timestamp rejected',
	static function () use ( $auth ): bool {
		$req = new WP_REST_Request( 'POST' );
		$req->set_body( '{}' );
		$old = (string) ( time() - 600 );
		$headers = signed_request( 'plain-secret', 'POST', '/wp-json/seoauto/v1/posts', '{}', 'r2', 'n2', $old );
		foreach ( $headers as $k => $v ) {
			$req->set_header( $k, $v );
		}
		$result = $auth->authenticate( $req, array( 'require_entitlement' => false ) );
		return $result instanceof WP_Error && $result->get_error_code() === 'seoauto_timestamp_expired';
	}
);

check(
	'nonce replay rejected',
	static function () use ( $auth ): bool {
		$req = new WP_REST_Request( 'POST' );
		$req->set_body( '{}' );
		$headers = signed_request( 'plain-secret', 'POST', '/wp-json/seoauto/v1/posts', '{}', 'r3', 'nonce-replay-1', (string) time() );
		foreach ( $headers as $k => $v ) {
			$req->set_header( $k, $v );
		}
		$first = $auth->authenticate( $req, array( 'require_entitlement' => false ) );
		$second = $auth->authenticate( $req, array( 'require_entitlement' => false ) );
		return true === $first && $second instanceof WP_Error && $second->get_error_code() === 'seoauto_nonce_replay';
	}
);

check(
	'connection/site mismatch rejected',
	static function () use ( $auth ): bool {
		$req = new WP_REST_Request( 'POST' );
		$req->set_body( '{}' );
		$headers = signed_request( 'plain-secret', 'POST', '/wp-json/seoauto/v1/posts', '{}', 'r4', 'n4', (string) time() );
		foreach ( $headers as $k => $v ) {
			$req->set_header( $k, $v );
		}
		$req->set_header( 'X-SEOAuto-Site-ID', 'other-site' );
		$result = $auth->authenticate( $req, array( 'require_entitlement' => false ) );
		return $result instanceof WP_Error && $result->get_error_code() === 'seoauto_site_mismatch';
	}
);

check(
	'organization header mismatch rejected',
	static function () use ( $auth ): bool {
		$req = new WP_REST_Request( 'POST' );
		$req->set_body( '{}' );
		$headers = signed_request( 'plain-secret', 'POST', '/wp-json/seoauto/v1/posts', '{}', 'r5', 'n5', (string) time() );
		foreach ( $headers as $k => $v ) {
			$req->set_header( $k, $v );
		}
		$req->set_header( 'X-SEOAuto-Organization-ID', '999' );
		$result = $auth->authenticate( $req, array( 'require_entitlement' => false ) );
		return $result instanceof WP_Error && $result->get_error_code() === 'seoauto_organization_mismatch';
	}
);

check(
	'oversized body rejected',
	static function () use ( $auth ): bool {
		$req = new WP_REST_Request( 'POST' );
		$req->set_body( str_repeat( 'a', 3000000 ) );
		$headers = signed_request( 'plain-secret', 'POST', '/wp-json/seoauto/v1/posts', $req->get_body(), 'r6', 'n6', (string) time() );
		foreach ( $headers as $k => $v ) {
			$req->set_header( $k, $v );
		}
		$result = $auth->authenticate( $req, array( 'require_entitlement' => false ) );
		return $result instanceof WP_Error && $result->get_error_code() === 'seoauto_body_too_large';
	}
);

check(
	'rate limit enforced',
	static function () use ( $connection, $entitlement ): bool {
		$limiter = new Rate_Limiter( 2, 60 );
		$gate    = new Request_Authenticator( $connection, $entitlement, new Nonce_Store(), $limiter );
		$blocked = false;
		for ( $i = 0; $i < 4; $i++ ) {
			$req = new WP_REST_Request( 'POST' );
			$req->set_body( '{}' );
			$headers = signed_request( 'plain-secret', 'POST', '/wp-json/seoauto/v1/posts', '{}', 'r7-' . $i, 'n7-' . $i, (string) time() );
			foreach ( $headers as $k => $v ) {
				$req->set_header( $k, $v );
			}
			$result = $gate->authenticate( $req, array( 'require_entitlement' => false ) );
			if ( $result instanceof WP_Error && $result->get_error_code() === 'seoauto_rate_limited' ) {
				$blocked = true;
				break;
			}
		}
		return $blocked;
	}
);

check(
	'tampered entitlement rejected on store',
	static function () use ( $entitlement ): bool {
		return ! $entitlement->store(
			array(
				'allowed'   => true,
				'reason'    => 'active',
				'signature' => 'deadbeef',
			)
		);
	}
);

seoauto_test_pair_options(
	array(
		'allowed'          => false,
		'reason'           => 'expired',
		'enabled_features' => array(),
	),
	Connection_Manager::STATUS_LOCKED
);

check(
	'expired entitlement blocks mutation at auth layer',
	static function () use ( $auth ): bool {
		$req = new WP_REST_Request( 'POST' );
		$req->set_body( '{}' );
		$headers = signed_request( 'plain-secret', 'POST', '/wp-json/seoauto/v1/posts', '{}', 'r8', 'n8', (string) time() );
		foreach ( $headers as $k => $v ) {
			$req->set_header( $k, $v );
		}
		$result = $auth->authenticate( $req, array( 'require_entitlement' => true, 'feature' => 'seo_helper' ) );
		return $result instanceof WP_Error && $result->get_error_code() === 'seoauto_plugin_locked';
	}
);

check(
	'SSRF localhost blocked',
	static function (): bool {
		$guard = new Url_Safety();
		$result = $guard->assert_safe_url( 'https://127.0.0.1/image.jpg' );
		return $result instanceof WP_Error;
	}
);

check(
	'malicious PHP upload signature blocked',
	static function (): bool {
		$tmp = tempnam( sys_get_temp_dir(), 'seoauto' );
		if ( ! is_string( $tmp ) ) {
			return false;
		}
		file_put_contents( $tmp, "<?php echo 'x';" );
		$guard = new Mime_Guard();
		$result = $guard->validate_file( $tmp, 'evil.php' );
		@unlink( $tmp );
		return $result instanceof WP_Error;
	}
);

check(
	'entitlement verifier roundtrip',
	static function (): bool {
		$data = array( 'allowed' => true, 'reason' => 'active', 'enabled_features' => array( 'seo_helper' ) );
		$signed = seoauto_test_signed_entitlement( $data, 'plain-secret' );
		return Entitlement_Verifier::verify( $signed, 'plain-secret' )
			&& ! Entitlement_Verifier::verify( array_merge( $signed, array( 'allowed' => false ) ), 'plain-secret' );
	}
);

echo PHP_EOL;
exit( $failed > 0 ? 1 : 0 );
