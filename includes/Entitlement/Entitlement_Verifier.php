<?php
/**
 * Verify SaaS entitlement snapshots bound to site_secret (tamper detection).
 *
 * @package SEOAuto\SEOHelper
 */

declare(strict_types=1);

namespace SEOAuto\SEOHelper\Entitlement;

final class Entitlement_Verifier {

	/**
	 * Sign payload the same way SaaS does when site_secret is provided.
	 *
	 * @param array<string,mixed> $payload
	 */
	public static function sign( array $payload, string $site_secret ): string {
		$data = $payload;
		unset( $data['signature'] );
		$body = wp_json_encode( self::canonicalize( $data ) );
		if ( ! is_string( $body ) ) {
			$body = json_encode( self::canonicalize( $data ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		}
		return hash_hmac( 'sha256', $body, $site_secret );
	}

	/**
	 * @param array<string,mixed> $payload Includes optional signature key.
	 */
	public static function verify( array $payload, string $site_secret ): bool {
		$site_secret = trim( $site_secret );
		if ( $site_secret === '' ) {
			return false;
		}
		$sig = (string) ( $payload['signature'] ?? '' );
		if ( $sig === '' ) {
			return false;
		}
		$expected = self::sign( $payload, $site_secret );
		return $expected !== '' && hash_equals( $expected, $sig );
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return array<string,mixed>
	 */
	private static function canonicalize( array $payload ): array {
		ksort( $payload );
		foreach ( $payload as $key => $value ) {
			if ( is_array( $value ) ) {
				$payload[ $key ] = self::canonicalize_list( $value );
			}
		}
		return $payload;
	}

	/**
	 * @param array<mixed> $list
	 * @return array<mixed>
	 */
	private static function canonicalize_list( array $list ): array {
		if ( self::is_list( $list ) ) {
			return array_values( $list );
		}
		ksort( $list );
		foreach ( $list as $key => $value ) {
			if ( is_array( $value ) ) {
				$list[ $key ] = self::canonicalize_list( $value );
			}
		}
		return $list;
	}

	/**
	 * @param array<mixed> $array
	 */
	private static function is_list( array $array ): bool {
		if ( $array === array() ) {
			return true;
		}
		return array_keys( $array ) === range( 0, count( $array ) - 1 );
	}
}
