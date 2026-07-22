<?php
/**
 * HMAC-SHA256 request signing (SEOAuto ↔ plugin).
 *
 * Canonical string (LF-separated):
 *   METHOD
 *   PATH
 *   TIMESTAMP
 *   NONCE
 *   REQUEST_ID
 *   SHA256_HEX(raw_body)
 *
 * Signature = hex(HMAC-SHA256(site_secret, canonical)).
 *
 * @package SEOAuto\SEOHelper
 */

declare(strict_types=1);

namespace SEOAuto\SEOHelper\Auth;

final class Hmac_Signer {

	public const MAX_SKEW_SECONDS = 300;

	/**
	 * Build canonical payload for signing.
	 */
	public static function canonical(
		string $method,
		string $path,
		string $timestamp,
		string $nonce,
		string $request_id,
		string $body
	): string {
		$method = strtoupper( trim( $method ) );
		$path   = self::normalize_path( $path );
		$body_hash = hash( 'sha256', $body );

		return implode(
			"\n",
			array(
				$method,
				$path,
				(string) $timestamp,
				(string) $nonce,
				(string) $request_id,
				$body_hash,
			)
		);
	}

	public static function normalize_path( string $path ): string {
		$path = trim( $path );
		if ( $path === '' ) {
			return '/';
		}
		if ( $path[0] !== '/' ) {
			$path = '/' . $path;
		}
		// Drop query/fragment if present.
		$q = strpos( $path, '?' );
		if ( false !== $q ) {
			$path = substr( $path, 0, $q );
		}
		if ( strlen( $path ) > 1 ) {
			$path = rtrim( $path, '/' );
		}
		return $path;
	}

	public static function sign(
		string $secret,
		string $method,
		string $path,
		string $timestamp,
		string $nonce,
		string $request_id,
		string $body
	): string {
		$canonical = self::canonical( $method, $path, $timestamp, $nonce, $request_id, $body );
		return hash_hmac( 'sha256', $canonical, $secret );
	}

	public static function verify(
		string $secret,
		string $signature,
		string $method,
		string $path,
		string $timestamp,
		string $nonce,
		string $request_id,
		string $body
	): bool {
		if ( $secret === '' || $signature === '' ) {
			return false;
		}
		$expected = self::sign( $secret, $method, $path, $timestamp, $nonce, $request_id, $body );
		return hash_equals( $expected, $signature );
	}

	/**
	 * @return array<string,string>
	 */
	public static function build_headers(
		string $site_id,
		int $connection_id,
		string $secret,
		string $method,
		string $path,
		string $body,
		?int $timestamp = null,
		?string $nonce = null,
		?string $request_id = null
	): array {
		$timestamp  = (string) ( $timestamp ?? time() );
		$nonce      = $nonce ?? bin2hex( random_bytes( 16 ) );
		$request_id = $request_id ?? self::uuid4();
		$signature  = self::sign( $secret, $method, $path, $timestamp, $nonce, $request_id, $body );

		return array(
			'X-SEOAuto-Site-ID'        => $site_id,
			'X-SEOAuto-Connection-ID'  => (string) $connection_id,
			'X-SEOAuto-Timestamp'      => $timestamp,
			'X-SEOAuto-Nonce'          => $nonce,
			'X-SEOAuto-Request-ID'     => $request_id,
			'X-SEOAuto-Signature'      => $signature,
			'Content-Type'             => 'application/json',
			'Accept'                   => 'application/json',
		);
	}

	private static function uuid4(): string {
		$data    = random_bytes( 16 );
		$data[6] = chr( ( ord( $data[6] ) & 0x0f ) | 0x40 );
		$data[8] = chr( ( ord( $data[8] ) & 0x3f ) | 0x80 );
		$hex     = bin2hex( $data );
		return sprintf(
			'%s-%s-%s-%s-%s',
			substr( $hex, 0, 8 ),
			substr( $hex, 8, 4 ),
			substr( $hex, 12, 4 ),
			substr( $hex, 16, 4 ),
			substr( $hex, 20, 12 )
		);
	}
}
