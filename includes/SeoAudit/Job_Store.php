<?php
/**
 * Background job store (WP-Cron batches).
 *
 * @package SEOAuto\SEOHelper
 */

declare(strict_types=1);

namespace SEOAuto\SEOHelper\SeoAudit;

use SEOAuto\SEOHelper\Post\Schema;

final class Job_Store {

	public const STATUS_QUEUED    = 'queued';
	public const STATUS_RUNNING   = 'running';
	public const STATUS_COMPLETED = 'completed';
	public const STATUS_FAILED    = 'failed';
	public const STATUS_CANCELLED = 'cancelled';
	public const STATUS_RETRYING  = 'retrying';

	public const TYPE_AUDIT_SCAN = 'audit_scan';

	/** Claim lock TTL in seconds (stale running jobs become reclaimable after this). */
	public const LOCK_TTL_SECONDS = 120;

	public function table(): string {
		return Schema::jobs_table();
	}

	/**
	 * @param array<string,mixed> $data
	 */
	public function create( array $data ): int {
		global $wpdb;
		$now = gmdate( 'Y-m-d H:i:s' );
		$wpdb->last_error = '';
		$result           = $wpdb->insert(
			$this->table(),
			array(
				'request_id'    => (string) ( $data['request_id'] ?? '' ),
				'job_type'      => (string) ( $data['job_type'] ?? self::TYPE_AUDIT_SCAN ),
				'status'        => (string) ( $data['status'] ?? self::STATUS_QUEUED ),
				'run_id'        => (int) ( $data['run_id'] ?? 0 ),
				'batch_size'    => (int) ( $data['batch_size'] ?? 20 ),
				'cursor_id'     => (int) ( $data['cursor_id'] ?? 0 ),
				'attempts'      => 0,
				'max_attempts'  => (int) ( $data['max_attempts'] ?? 5 ),
				'payload_json'  => wp_json_encode( $data['payload'] ?? array() ),
				'created_gmt'   => $now,
				'updated_gmt'   => $now,
			),
			array( '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s' )
		);
		if ( false === $result || (int) $wpdb->insert_id <= 0 || '' !== (string) $wpdb->last_error ) {
			return 0;
		}
		return (int) $wpdb->insert_id;
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public function get( int $id ): ?array {
		global $wpdb;
		$table = $this->table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		return is_array( $row ) ? $this->normalize( $row ) : null;
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public function get_by_request_id( string $request_id ): ?array {
		global $wpdb;
		$table = $this->table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE request_id = %s LIMIT 1", $request_id ),
			ARRAY_A
		);
		return is_array( $row ) ? $this->normalize( $row ) : null;
	}

	/**
	 * Active scan job (queued / retrying / running) — used to prevent duplicates.
	 *
	 * @return array<string,mixed>|null
	 */
	public function find_active( string $job_type = self::TYPE_AUDIT_SCAN ): ?array {
		global $wpdb;
		$table = $this->table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				WHERE job_type = %s
				  AND status IN ('queued','retrying','running')
				ORDER BY id ASC
				LIMIT 1",
				$job_type
			),
			ARRAY_A
		);
		return is_array( $row ) ? $this->normalize( $row ) : null;
	}

	/**
	 * Claim next runnable job (queued/retrying, or stale running with expired lock).
	 * Atomic UPDATE prevents two workers from claiming the same row.
	 *
	 * @return array<string,mixed>|null
	 */
	public function claim_next( string $job_type = self::TYPE_AUDIT_SCAN ): ?array {
		global $wpdb;
		$table = $this->table();
		$now   = gmdate( 'Y-m-d H:i:s' );

		$this->fail_exhausted_stale( $job_type, $now );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				WHERE job_type = %s
				  AND (
				    ( status IN ('queued','retrying')
				      AND ( locked_until_gmt IS NULL OR locked_until_gmt < %s ) )
				    OR ( status = 'running'
				      AND locked_until_gmt IS NOT NULL
				      AND locked_until_gmt < %s )
				  )
				ORDER BY id ASC
				LIMIT 1",
				$job_type,
				$now,
				$now
			),
			ARRAY_A
		);
		if ( ! is_array( $row ) ) {
			return null;
		}

		$id         = (int) $row['id'];
		$attempts   = (int) ( $row['attempts'] ?? 0 ) + 1;
		$max        = (int) ( $row['max_attempts'] ?? 5 );
		$prev_status = (string) ( $row['status'] ?? '' );

		// Stale running reclaim that already exhausted retries → fail (do not loop forever).
		if ( self::STATUS_RUNNING === $prev_status && $attempts > $max ) {
			$this->mark_failed(
				$id,
				'max_attempts',
				'Job exceeded max attempts after stale lock reclaim'
			);
			return null;
		}

		$lock_until = gmdate( 'Y-m-d H:i:s', time() + self::LOCK_TTL_SECONDS );

		// Atomic claim: only one worker wins if status/lock still match.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				SET status = 'running', attempts = %d, locked_until_gmt = %s,
				    started_gmt = COALESCE(started_gmt, %s), updated_gmt = %s
				WHERE id = %d
				  AND job_type = %s
				  AND (
				    ( status IN ('queued','retrying')
				      AND ( locked_until_gmt IS NULL OR locked_until_gmt < %s ) )
				    OR ( status = 'running'
				      AND locked_until_gmt IS NOT NULL
				      AND locked_until_gmt < %s )
				  )",
				$attempts,
				$lock_until,
				$now,
				$now,
				$id,
				$job_type,
				$now,
				$now
			)
		);
		if ( ! $updated ) {
			return null;
		}
		return $this->get( $id );
	}

	/**
	 * Mark expired running jobs that already hit max_attempts as failed.
	 */
	private function fail_exhausted_stale( string $job_type, string $now ): void {
		global $wpdb;
		$table = $this->table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$stale = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, run_id FROM {$table}
				WHERE job_type = %s
				  AND status = 'running'
				  AND locked_until_gmt IS NOT NULL
				  AND locked_until_gmt < %s
				  AND attempts >= max_attempts",
				$job_type,
				$now
			),
			ARRAY_A
		);
		if ( ! is_array( $stale ) || $stale === array() ) {
			return;
		}
		$message = 'Job exceeded max attempts while stuck running';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				SET status = 'failed',
				    error_code = 'max_attempts',
				    error_message = %s,
				    finished_gmt = %s,
				    locked_until_gmt = NULL,
				    updated_gmt = %s
				WHERE job_type = %s
				  AND status = 'running'
				  AND locked_until_gmt IS NOT NULL
				  AND locked_until_gmt < %s
				  AND attempts >= max_attempts",
				$message,
				$now,
				$now,
				$job_type,
				$now
			)
		);
		foreach ( $stale as $row ) {
			$run_id = (int) ( $row['run_id'] ?? 0 );
			if ( $run_id > 0 ) {
				$this->fail_linked_run( $run_id, 'max_attempts', $message, $now );
			}
		}
	}

	private function mark_failed( int $id, string $code, string $message ): void {
		$now = gmdate( 'Y-m-d H:i:s' );
		$job = $this->get( $id );
		$this->update(
			$id,
			array(
				'status'          => self::STATUS_FAILED,
				'error_code'      => $code,
				'error_message'   => $message,
				'finished_gmt'    => $now,
				'locked_until_gmt'=> null,
			)
		);
		$run_id = (int) ( $job['run_id'] ?? 0 );
		if ( $run_id > 0 ) {
			$this->fail_linked_run( $run_id, $code, $message, $now );
		}
	}

	/**
	 * Keep audit run in sync when a job is force-failed (no secrets).
	 */
	private function fail_linked_run( int $run_id, string $code, string $message, string $now ): void {
		global $wpdb;
		$runs = Schema::audit_runs_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$runs}
				SET status = 'failed', error_code = %s, error_message = %s,
				    finished_gmt = %s, updated_gmt = %s
				WHERE id = %d AND status NOT IN ('completed','cancelled','failed')",
				$code,
				$message,
				$now,
				$now,
				$run_id
			)
		);
	}

	/**
	 * @param array<string,mixed> $fields
	 */
	public function update( int $id, array $fields ): bool {
		global $wpdb;
		$fields['updated_gmt'] = gmdate( 'Y-m-d H:i:s' );
		$formats = array();
		foreach ( $fields as $value ) {
			$formats[] = is_int( $value ) ? '%d' : '%s';
		}
		return false !== $wpdb->update( $this->table(), $fields, array( 'id' => $id ), $formats, array( '%d' ) );
	}

	public function cancel( int $id ): bool {
		$job = $this->get( $id );
		if ( null === $job ) {
			return false;
		}
		if ( in_array( $job['status'], array( self::STATUS_COMPLETED, self::STATUS_CANCELLED ), true ) ) {
			return false;
		}
		return $this->update(
			$id,
			array(
				'status'          => self::STATUS_CANCELLED,
				'finished_gmt'    => gmdate( 'Y-m-d H:i:s' ),
				'locked_until_gmt'=> null,
			)
		);
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	public function recent( int $limit = 20 ): array {
		global $wpdb;
		$table = $this->table();
		$limit = max( 1, min( 100, $limit ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit ),
			ARRAY_A
		);
		if ( ! is_array( $rows ) ) {
			return array();
		}
		return array_map( array( $this, 'normalize' ), $rows );
	}

	/**
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>
	 */
	private function normalize( array $row ): array {
		$payload = json_decode( (string) ( $row['payload_json'] ?? '{}' ), true );
		$result  = json_decode( (string) ( $row['result_json'] ?? '{}' ), true );
		$row['payload'] = is_array( $payload ) ? $payload : array();
		$row['result']  = is_array( $result ) ? $result : array();
		unset( $row['payload_json'], $row['result_json'] );
		foreach ( array( 'id', 'run_id', 'batch_size', 'cursor_id', 'attempts', 'max_attempts' ) as $k ) {
			$row[ $k ] = (int) ( $row[ $k ] ?? 0 );
		}
		return $row;
	}
}
