<?php
/**
 * At-rest encryption for site_secret using OpenSSL + WP salts.
 * Does not modify wp-config.php; derives key from existing AUTH_* constants.
 *
 * @package SEOAuto\SEOHelper
 */

declare(strict_types=1);

namespace SEOAuto\SEOHelper\Security;

final class Secret_Store {

	private const PREFIX = 'enc:v1:';

	/**
	 * Encrypt plaintext. Empty string stays empty.
	 */
	public static function encrypt( string $plain ): string {
		$plain = (string) $plain;
		if ( $plain === '' ) {
			return '';
		}
		if ( str_starts_with( $plain, self::PREFIX ) ) {
			return $plain;
		}

		$key = self::key_bytes();
		$iv  = random_bytes( 12 );
		$tag = '';
		$cipher = openssl_encrypt( $plain, 'aes-256-gcm', $key, \OPENSSL_RAW_DATA, $iv, $tag, '', 16 );
		if ( false === $cipher || $tag === '' ) {
			return '';
		}

		$blob = base64_encode( $iv . $tag . $cipher );
		return self::PREFIX . $blob;
	}

	/**
	 * Decrypt stored value. Legacy plaintext (no prefix) returned as-is for migration.
	 */
	public static function decrypt( string $stored ): string {
		$stored = (string) $stored;
		if ( $stored === '' ) {
			return '';
		}
		if ( ! str_starts_with( $stored, self::PREFIX ) ) {
			// Legacy plaintext — caller should re-encrypt on next save.
			return $stored;
		}

		$raw = base64_decode( substr( $stored, strlen( self::PREFIX ) ), true );
		if ( false === $raw || strlen( $raw ) < 28 ) {
			return '';
		}

		$iv     = substr( $raw, 0, 12 );
		$tag    = substr( $raw, 12, 16 );
		$cipher = substr( $raw, 28 );
		$key    = self::key_bytes();
		$plain  = openssl_decrypt( $cipher, 'aes-256-gcm', $key, \OPENSSL_RAW_DATA, $iv, $tag );
		return false === $plain ? '' : $plain;
	}

	public static function is_encrypted( string $stored ): bool {
		return str_starts_with( (string) $stored, self::PREFIX );
	}

	private static function key_bytes(): string {
		$material = implode(
			'|',
			array(
				defined( 'AUTH_KEY' ) ? \AUTH_KEY : '',
				defined( 'SECURE_AUTH_KEY' ) ? \SECURE_AUTH_KEY : '',
				defined( 'AUTH_SALT' ) ? \AUTH_SALT : '',
				defined( 'SECURE_AUTH_SALT' ) ? \SECURE_AUTH_SALT : '',
				'seoauto_helper_site_secret',
			)
		);
		return hash( 'sha256', $material, true );
	}
}
