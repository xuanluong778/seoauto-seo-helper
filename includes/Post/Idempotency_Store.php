<?php
/**
 * Persistent idempotency + article map with unique constraints / locks.
 *
 * @package SEOAuto\SEOHelper
 */

declare(strict_types=1);

namespace SEOAuto\SEOHelper\Post;

use WP_Error;

final class Idempotency_Store {

	public const STATUS_PENDING   = 'pending';
	public const STATUS_COMPLETED = 'completed';
	public const STATUS_FAILED    = 'failed';

	/**
	 * @return array<string,mixed>|null
	 */
	public function get_by_request_id( string $request_id ): ?array {
		global $wpdb;
		$request_id = $this->normalize_request_id( $request_id );
		if ( $request_id === '' ) {
			return null;
		}
		$table = Schema::idempotency_table();
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE request_id = %s LIMIT 1", $request_id ),
			\ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Atomically claim a request_id. Returns:
	 * - 'claimed' => we own the pending row
	 * - 'replay'  => completed response array
	 * - 'pending' => another worker owns it
	 * - WP_Error
	 *
	 * @return array{state:'claimed'}|array{state:'replay',response:array<string,mixed>}|array{state:'pending'}|WP_Error
	 */
	public function claim_request( string $request_id, string $source_article_id, int $connection_id ): array|WP_Error {
		global $wpdb;

		$request_id = $this->normalize_request_id( $request_id );
		$article_id = $this->normalize_article_id( $source_article_id );
		if ( $request_id === '' ) {
			return new WP_Error( 'seoauto_missing_request_id', __( 'Thiếu request_id.', 'seoauto-seo-helper' ), array( 'status' => 400, 'code' => 'seoauto_missing_request_id' ) );
		}
		if ( $article_id === '' ) {
			return new WP_Error( 'seoauto_missing_source_article_id', __( 'Thiếu source_article_id.', 'seoauto-seo-helper' ), array( 'status' => 400, 'code' => 'seoauto_missing_source_article_id' ) );
		}

		$existing = $this->get_by_request_id( $request_id );
		if ( is_array( $existing ) ) {
			if ( (string) ( $existing['status'] ?? '' ) === self::STATUS_PENDING ) {
				$updated = strtotime( (string) ( $existing['updated_gmt'] ?? '' ) . ' UTC' );
				// Stale pending (worker crashed) — allow reclaim after 5 minutes.
				if ( false !== $updated && ( time() - $updated ) > 300 ) {
					$wpdb->delete( Schema::idempotency_table(), array( 'request_id' => $request_id ), array( '%s' ) );
				} else {
					return $this->state_from_row( $existing );
				}
			} else {
				return $this->state_from_row( $existing );
			}
		}

		$now   = gmdate( 'Y-m-d H:i:s' );
		$table = Schema::idempotency_table();
		$ok    = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (request_id, connection_id, source_article_id, post_id, status, response_json, error_code, created_gmt, updated_gmt)
				VALUES (%s, %d, %s, 0, %s, NULL, NULL, %s, %s)",
				$request_id,
				$connection_id,
				$article_id,
				self::STATUS_PENDING,
				$now,
				$now
			)
		);

		if ( false !== $ok && (int) $ok > 0 ) {
			return array( 'state' => 'claimed' );
		}

		// Unique race — re-read winner.
		$existing = $this->get_by_request_id( $request_id );
		if ( is_array( $existing ) ) {
			return $this->state_from_row( $existing );
		}

		return new WP_Error(
			'seoauto_idempotency_claim_failed',
			__( 'Không claim được request_id.', 'seoauto-seo-helper' ),
			array( 'status' => 500, 'code' => 'seoauto_idempotency_claim_failed' )
		);
	}

	/**
	 * @param array<string,mixed> $response
	 */
	public function complete( string $request_id, int $post_id, array $response ): void {
		global $wpdb;
		$request_id = $this->normalize_request_id( $request_id );
		$table      = Schema::idempotency_table();
		$wpdb->update(
			$table,
			array(
				'status'        => self::STATUS_COMPLETED,
				'post_id'       => $post_id,
				'response_json' => wp_json_encode( $response ),
				'error_code'    => null,
				'updated_gmt'   => gmdate( 'Y-m-d H:i:s' ),
			),
			array( 'request_id' => $request_id ),
			array( '%s', '%d', '%s', '%s', '%s' ),
			array( '%s' )
		);
	}

	public function fail( string $request_id, string $error_code, ?array $response = null ): void {
		global $wpdb;
		$request_id = $this->normalize_request_id( $request_id );
		$table      = Schema::idempotency_table();
		$wpdb->update(
			$table,
			array(
				'status'        => self::STATUS_FAILED,
				'error_code'    => substr( $error_code, 0, 64 ),
				'response_json' => null !== $response ? wp_json_encode( $response ) : null,
				'updated_gmt'   => gmdate( 'Y-m-d H:i:s' ),
			),
			array( 'request_id' => $request_id ),
			array( '%s', '%s', '%s', '%s' ),
			array( '%s' )
		);
	}

	/**
	 * Wait briefly for a pending sibling request to finish (Celery double-delivery).
	 *
	 * @return array<string,mixed>|null Completed response or null.
	 */
	public function wait_for_completion( string $request_id, int $timeout_ms = 3000 ): ?array {
		$deadline = microtime( true ) + ( $timeout_ms / 1000 );
		while ( microtime( true ) < $deadline ) {
			$row = $this->get_by_request_id( $request_id );
			if ( ! is_array( $row ) ) {
				return null;
			}
			if ( (string) $row['status'] === self::STATUS_COMPLETED ) {
				$decoded = json_decode( (string) ( $row['response_json'] ?? '' ), true );
				return is_array( $decoded ) ? $decoded : null;
			}
			if ( (string) $row['status'] === self::STATUS_FAILED ) {
				return null;
			}
			usleep( 50_000 );
		}
		return null;
	}

	public function find_post_id( int $connection_id, string $source_article_id ): int {
		global $wpdb;
		$article_id = $this->normalize_article_id( $source_article_id );
		if ( $article_id === '' ) {
			return 0;
		}
		$table = Schema::article_map_table();
		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$table} WHERE connection_id = %d AND source_article_id = %s LIMIT 1",
				$connection_id,
				$article_id
			)
		);
		return (int) $found;
	}

	/**
	 * Insert mapping atomically. Returns true on insert, false if unique conflict.
	 */
	public function insert_article_map( int $connection_id, string $source_article_id, int $post_id ): bool {
		global $wpdb;
		$article_id = $this->normalize_article_id( $source_article_id );
		if ( $article_id === '' || $post_id <= 0 ) {
			return false;
		}
		$now   = gmdate( 'Y-m-d H:i:s' );
		$table = Schema::article_map_table();
		$ok    = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (connection_id, source_article_id, post_id, created_gmt, updated_gmt)
				VALUES (%d, %s, %d, %s, %s)",
				$connection_id,
				$article_id,
				$post_id,
				$now,
				$now
			)
		);
		return false !== $ok && (int) $ok > 0;
	}

	/**
	 * Update mapping to a new post_id (force_create).
	 */
	public function upsert_article_map( int $connection_id, string $source_article_id, int $post_id ): void {
		global $wpdb;
		$article_id = $this->normalize_article_id( $source_article_id );
		if ( $article_id === '' || $post_id <= 0 ) {
			return;
		}
		if ( $this->insert_article_map( $connection_id, $article_id, $post_id ) ) {
			return;
		}
		$table = Schema::article_map_table();
		$wpdb->update(
			$table,
			array(
				'post_id'     => $post_id,
				'updated_gmt' => gmdate( 'Y-m-d H:i:s' ),
			),
			array(
				'connection_id'      => $connection_id,
				'source_article_id'  => $article_id,
			),
			array( '%d', '%s' ),
			array( '%d', '%s' )
		);
	}

	/**
	 * MySQL named lock for source_article_id (race-safe across PHP workers).
	 */
	public function acquire_article_lock( int $connection_id, string $source_article_id, int $timeout_seconds = 10 ): bool {
		global $wpdb;
		$name = $this->lock_name( $connection_id, $source_article_id );
		$got  = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, $timeout_seconds ) );
		return (string) $got === '1';
	}

	public function release_article_lock( int $connection_id, string $source_article_id ): void {
		global $wpdb;
		$name = $this->lock_name( $connection_id, $source_article_id );
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
	}

	public function normalize_request_id( string $request_id ): string {
		$request_id = trim( $request_id );
		if ( $request_id === '' || strlen( $request_id ) > 128 ) {
			return '';
		}
		return $request_id;
	}

	public function normalize_article_id( string $article_id ): string {
		$article_id = sanitize_text_field( trim( $article_id ) );
		if ( $article_id === '' || strlen( $article_id ) > 191 ) {
			return '';
		}
		return $article_id;
	}

	/**
	 * @param array<string,mixed> $row
	 * @return array{state:'claimed'}|array{state:'replay',response:array<string,mixed>}|array{state:'pending'}|array{state:'failed',error_code:string,response?:array<string,mixed>}
	 */
	private function state_from_row( array $row ): array {
		$status = (string) ( $row['status'] ?? '' );
		if ( $status === self::STATUS_COMPLETED ) {
			$decoded = json_decode( (string) ( $row['response_json'] ?? '' ), true );
			$response = is_array( $decoded ) ? $decoded : array(
				'post_id' => (int) ( $row['post_id'] ?? 0 ),
			);
			$response['idempotent_replay'] = true;
			return array(
				'state'    => 'replay',
				'response' => $response,
			);
		}
		if ( $status === self::STATUS_FAILED ) {
			$decoded = json_decode( (string) ( $row['response_json'] ?? '' ), true );
			$out     = array(
				'state'      => 'failed',
				'error_code' => (string) ( $row['error_code'] ?? 'seoauto_idempotency_failed' ),
			);
			if ( is_array( $decoded ) ) {
				$out['response'] = $decoded;
			}
			return $out;
		}
		return array( 'state' => 'pending' );
	}

	private function lock_name( int $connection_id, string $source_article_id ): string {
		$hash = md5( $connection_id . '|' . $this->normalize_article_id( $source_article_id ) );
		// MySQL GET_LOCK name max 64 chars on older versions.
		return 'sa_art_' . $hash;
	}
}
