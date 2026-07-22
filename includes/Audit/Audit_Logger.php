<?php
/**
 * Append-only audit log with structured fields, redaction, and retention.
 *
 * @package SEOAuto\SEOHelper
 */

declare(strict_types=1);

namespace SEOAuto\SEOHelper\Audit;

use SEOAuto\SEOHelper\Entitlement\Entitlement_Manager;

final class Audit_Logger {

	public const RETENTION_30 = 30;
	public const RETENTION_90 = 90;

	private const MAX_ENTRIES = 500;

	/** @var list<string> */
	private const REDACT_KEYS = array(
		'site_secret',
		'password',
		'token',
		'authorization',
		'pairing_code',
		'code',
		'secret',
		'signature',
		'entitlement_sig',
		'access_token',
		'refresh_token',
		'api_key',
		'cookie',
		'nonce',
	);

	/** @var list<string> */
	private const STRIP_KEYS = array(
		'content',
		'post_content',
		'body',
		'html',
		'raw',
		'file_base64',
		'description',
	);

	public function __construct(
		private ?Entitlement_Manager $entitlement = null
	) {}

	public function retention_days(): int {
		$days = (int) get_option( SEOAUTO_HELPER_PREFIX . 'audit_log_retention_days', self::RETENTION_90 );
		return in_array( $days, array( self::RETENTION_30, self::RETENTION_90 ), true ) ? $days : self::RETENTION_90;
	}

	public function set_retention_days( int $days ): int {
		$days = in_array( $days, array( self::RETENTION_30, self::RETENTION_90 ), true ) ? $days : self::RETENTION_90;
		update_option( SEOAUTO_HELPER_PREFIX . 'audit_log_retention_days', $days, false );
		return $days;
	}

	/**
	 * @param array<string,mixed> $context
	 */
	public function log( string $action, array $context = array() ): void {
		if ( null !== $this->entitlement && ! $this->entitlement->should_log_audit( $action ) ) {
			return;
		}

		$context  = $this->sanitize_context( $context );
		$entry    = array(
			'at'          => gmdate( 'c' ),
			'action'      => sanitize_key( $action ),
			'request_id'  => $this->scalar( $context, 'request_id' ),
			'post_id'     => (int) $this->scalar( $context, 'post_id', 0 ),
			'status'      => $this->derive_status( $context ),
			'error_code'  => $this->scalar( $context, 'error_code' ),
			'context'     => $this->compact_context( $context ),
			'user_id'     => get_current_user_id(),
		);

		$log = get_option( $this->option_key(), array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}
		array_unshift( $log, $entry );
		$log = array_slice( $log, 0, self::MAX_ENTRIES );
		update_option( $this->option_key(), $log, false );
		$this->purge_expired();
	}

	/**
	 * @param array<string,mixed> $context
	 */
	public function log_error( string $action, string $error_code, array $context = array() ): void {
		$context['error_code'] = sanitize_key( $error_code );
		$context['status']     = 'error';
		if ( isset( $context['ok'] ) ) {
			$context['ok'] = false;
		}
		$this->log( $action, $context );
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	public function recent( int $limit = 20 ): array {
		$this->purge_expired();
		$log = get_option( $this->option_key(), array() );
		if ( ! is_array( $log ) ) {
			return array();
		}
		$rows = array();
		foreach ( array_slice( $log, 0, max( 1, $limit ) ) as $row ) {
			if ( is_array( $row ) ) {
				$rows[] = $this->normalize_row( $row );
			}
		}
		return $rows;
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	public function all_for_display( int $limit = 200 ): array {
		return $this->recent( $limit );
	}

	/**
	 * @return array{error_code:string,message:string,at:string,action:string}|null
	 */
	public function latest_error(): ?array {
		$this->purge_expired();
		$log = get_option( $this->option_key(), array() );
		if ( ! is_array( $log ) ) {
			return null;
		}
		foreach ( $log as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$normalized = $this->normalize_row( $row );
			$error_code = (string) ( $normalized['error_code'] ?? '' );
			$status     = (string) ( $normalized['status'] ?? '' );
			if ( $error_code !== '' || $status === 'error' ) {
				$message = $error_code;
				if ( isset( $normalized['context']['message'] ) && is_string( $normalized['context']['message'] ) ) {
					$message = (string) $normalized['context']['message'];
				}
				return array(
					'error_code' => $error_code !== '' ? $error_code : 'error',
					'message'    => $message,
					'at'         => (string) ( $normalized['at'] ?? '' ),
					'action'     => (string) ( $normalized['action'] ?? '' ),
				);
			}
		}
		return null;
	}

	public function purge_expired(): int {
		$days = $this->retention_days();
		$cut  = time() - ( $days * \DAY_IN_SECONDS );
		$log  = get_option( $this->option_key(), array() );
		if ( ! is_array( $log ) || $log === array() ) {
			return 0;
		}
		$before = count( $log );
		$kept   = array();
		foreach ( $log as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$at = (string) ( $row['at'] ?? '' );
			$ts = $at !== '' ? strtotime( $at ) : false;
			if ( false !== $ts && $ts < $cut ) {
				continue;
			}
			$kept[] = $row;
		}
		if ( count( $kept ) !== $before ) {
			update_option( $this->option_key(), $kept, false );
		}
		return $before - count( $kept );
	}

	private function option_key(): string {
		return SEOAUTO_HELPER_PREFIX . 'audit_log';
	}

	/**
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>
	 */
	private function normalize_row( array $row ): array {
		$context = is_array( $row['context'] ?? null ) ? $row['context'] : array();
		return array(
			'at'         => (string) ( $row['at'] ?? '' ),
			'action'     => (string) ( $row['action'] ?? '' ),
			'request_id' => (string) ( $row['request_id'] ?? $this->scalar( $context, 'request_id' ) ),
			'post_id'    => (int) ( $row['post_id'] ?? $this->scalar( $context, 'post_id', 0 ) ),
			'status'     => (string) ( $row['status'] ?? $this->derive_status( $context ) ),
			'error_code' => (string) ( $row['error_code'] ?? $this->scalar( $context, 'error_code' ) ),
			'context'    => $this->sanitize_context( $context ),
		);
	}

	/**
	 * @param array<string,mixed> $context
	 */
	private function derive_status( array $context ): string {
		if ( isset( $context['status'] ) && is_string( $context['status'] ) && $context['status'] !== '' ) {
			return sanitize_key( $context['status'] );
		}
		if ( array_key_exists( 'ok', $context ) ) {
			return ! empty( $context['ok'] ) ? 'ok' : 'error';
		}
		if ( ! empty( $context['error_code'] ) ) {
			return 'error';
		}
		return 'ok';
	}

	/**
	 * @param array<string,mixed> $context
	 */
	private function scalar( array $context, string $key, mixed $default = '' ): mixed {
		if ( ! array_key_exists( $key, $context ) ) {
			return $default;
		}
		$value = $context[ $key ];
		if ( is_scalar( $value ) || null === $value ) {
			return $value;
		}
		return $default;
	}

	/**
	 * Remove fields promoted to top-level columns.
	 *
	 * @param array<string,mixed> $context
	 * @return array<string,mixed>
	 */
	private function compact_context( array $context ): array {
		unset( $context['request_id'], $context['post_id'], $context['error_code'] );
		return $context;
	}

	/**
	 * @param array<string,mixed> $context
	 * @return array<string,mixed>
	 */
	private function sanitize_context( array $context ): array {
		$out = array();
		foreach ( $context as $key => $value ) {
			$k = sanitize_key( (string) $key );
			if ( in_array( $k, self::REDACT_KEYS, true ) ) {
				$out[ $k ] = '[redacted]';
				continue;
			}
			if ( in_array( $k, self::STRIP_KEYS, true ) ) {
				$out[ $k ] = '[omitted]';
				continue;
			}
			if ( is_scalar( $value ) || null === $value ) {
				if ( is_string( $value ) && strlen( $value ) > 160 ) {
					$out[ $k ] = substr( sanitize_text_field( $value ), 0, 160 ) . '…';
				} else {
					$out[ $k ] = is_string( $value ) ? sanitize_text_field( $value ) : $value;
				}
			} elseif ( is_array( $value ) ) {
				$out[ $k ] = $this->sanitize_context( $value );
			}
		}
		return $out;
	}
}
