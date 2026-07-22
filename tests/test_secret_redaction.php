<?php
/**
 * Ensure audit logs never persist secrets/tokens/signatures.
 *
 * Run: php tests/test_secret_redaction.php
 *
 * @package SEOAuto\SEOHelper
 */

declare(strict_types=1);

define( 'SEOAUTO_HELPER_PREFIX', 'seoauto_helper_' );

$GLOBALS['seoauto_test_options'] = array();

function get_option( string $name, $default = false ) {
	return $GLOBALS['seoauto_test_options'][ $name ] ?? $default;
}
function update_option( string $name, $value, bool $autoload = null ): bool {
	$GLOBALS['seoauto_test_options'][ $name ] = $value;
	return true;
}
function sanitize_key( string $key ): string {
	return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', $key ) ?? '' );
}
function sanitize_text_field( $str ): string {
	return is_scalar( $str ) ? trim( (string) $str ) : '';
}
function get_current_user_id(): int { return 1; }
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}
function wp_json_encode( $data, int $options = 0, int $depth = 512 ) {
	return json_encode( $data, $options, $depth );
}

require_once dirname( __DIR__ ) . '/includes/Audit/Audit_Logger.php';

use SEOAuto\SEOHelper\Audit\Audit_Logger;

$failed = 0;
function check( string $msg, callable $fn ): void {
	global $failed;
	$ok = (bool) $fn();
	echo ( $ok ? 'PASS' : 'FAIL' ) . "  {$msg}\n";
	if ( ! $ok ) {
		++$failed;
	}
}

$logger = new Audit_Logger();
$logger->log(
	'post_create',
	array(
		'request_id'    => 'req-1',
		'status'        => 'error',
		'error_code'    => 'test',
		'site_secret'   => 'super-secret-value',
		'pairing_code'  => 'SA-ABCD-EFGH',
		'signature'     => 'abc123sig',
		'authorization' => 'Bearer token-xyz',
		'password'      => 'admin-pass',
		'content'       => str_repeat( 'x', 5000 ),
	)
);

$rows = $logger->recent( 1 );
$row  = $rows[0] ?? array();
$blob = wp_json_encode( $row );

check( 'site_secret redacted from audit log', static fn(): bool => ! str_contains( $blob, 'super-secret-value' ) );
check( 'pairing_code redacted from audit log', static fn(): bool => ! str_contains( $blob, 'SA-ABCD-EFGH' ) );
check( 'signature redacted from audit log', static fn(): bool => ! str_contains( $blob, 'abc123sig' ) );
check( 'authorization redacted from audit log', static fn(): bool => ! str_contains( $blob, 'token-xyz' ) );
check( 'password redacted from audit log', static fn(): bool => ! str_contains( $blob, 'admin-pass' ) );
check( 'large content stripped from audit log', static fn(): bool => ! str_contains( $blob, str_repeat( 'x', 5000 ) ) );
check( 'request_id preserved in audit log', static function () use ( $row ): bool {
	return ( $row['request_id'] ?? '' ) === 'req-1';
} );

exit( $failed > 0 ? 1 : 0 );
