<?php
/**
 * Audit log redaction and retention tests.
 *
 * Run: php tests/test_audit_logger.php
 *
 * @package SEOAuto\SEOHelper
 */

declare(strict_types=1);

define( 'SEOAUTO_HELPER_PREFIX', 'seoauto_helper_' );

/** @var array<string,mixed> */
$GLOBALS['seoauto_test_options'] = array();

function get_option( string $name, $default = false ) {
	$opts = $GLOBALS['seoauto_test_options'];
	return array_key_exists( $name, $opts ) ? $opts[ $name ] : $default;
}

function update_option( string $name, $value, bool $autoload = null ): bool {
	$GLOBALS['seoauto_test_options'][ $name ] = $value;
	return true;
}

function add_option( string $name, $value, string $deprecated = '', bool $autoload = true ): bool {
	return update_option( $name, $value );
}

function sanitize_key( string $key ): string {
	return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', $key ) ?? '' );
}

function sanitize_text_field( $str ): string {
	return is_scalar( $str ) ? trim( (string) $str ) : '';
}

function get_current_user_id(): int {
	return 0;
}

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

require_once dirname( __DIR__ ) . '/includes/Audit/Audit_Logger.php';

use SEOAuto\SEOHelper\Audit\Audit_Logger;

$failed = 0;

function check( string $msg, callable $fn ): void {
	global $failed;
	$ok = (bool) $fn();
	if ( $ok ) {
		echo "PASS  {$msg}\n";
		return;
	}
	++$failed;
	echo "FAIL  {$msg}\n";
}

$audit = new Audit_Logger();

$audit->log(
	'post_create',
	array(
		'request_id'  => 'req-abc-123',
		'post_id'     => 99,
		'status'      => 'publish',
		'site_secret' => 'must-not-show',
		'content'     => str_repeat( 'X', 500 ),
		'signature'   => 'sig-value',
	)
);

$rows = $audit->recent( 1 );
$row  = $rows[0] ?? array();

check(
	'structured columns extracted',
	static fn(): bool => ( $row['request_id'] ?? '' ) === 'req-abc-123'
		&& (int) ( $row['post_id'] ?? 0 ) === 99
		&& ( $row['status'] ?? '' ) === 'publish'
);

check(
	'secrets redacted in context',
	static fn(): bool => ( $row['context']['site_secret'] ?? '' ) === '[redacted]'
		&& ( $row['context']['signature'] ?? '' ) === '[redacted]'
);

check(
	'post content omitted not full',
	static fn(): bool => ( $row['context']['content'] ?? '' ) === '[omitted]'
);

$audit->log_error( 'post_create', 'seoauto_invalid_post', array( 'request_id' => 'req-fail' ) );
$latest = $audit->latest_error();

check(
	'latest_error finds error_code',
	static fn(): bool => null !== $latest && ( $latest['error_code'] ?? '' ) === 'seoauto_invalid_post'
);

$GLOBALS['seoauto_test_options']['seoauto_helper_audit_log'] = array(
	array(
		'at'     => gmdate( 'c', time() - ( 100 * DAY_IN_SECONDS ) ),
		'action' => 'old',
	),
	array(
		'at'         => gmdate( 'c' ),
		'action'     => 'new',
		'request_id' => 'fresh',
		'status'     => 'ok',
	),
);
$audit->set_retention_days( Audit_Logger::RETENTION_30 );
$purged = $audit->purge_expired();
$after  = $audit->recent( 10 );

check(
	'purge removes entries older than retention',
	static fn(): bool => $purged >= 1 && count( $after ) === 1 && ( $after[0]['action'] ?? '' ) === 'new'
);

echo "\n" . ( 0 === $failed ? 'ALL PASS' : "{$failed} FAILED" ) . "\n";
exit( $failed > 0 ? 1 : 0 );
