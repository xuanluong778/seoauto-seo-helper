<?php
/**
 * Audit run persistence.
 *
 * @package SEOAuto\SEOHelper
 */

declare(strict_types=1);

namespace SEOAuto\SEOHelper\SeoAudit;

use SEOAuto\SEOHelper\Post\Schema;

final class Audit_Run_Store {

	public const STATUS_QUEUED     = 'queued';
	public const STATUS_RUNNING    = 'running';
	public const STATUS_COMPLETED  = 'completed';
	public const STATUS_FAILED     = 'failed';
	public const STATUS_CANCELLED  = 'cancelled';
	public const STATUS_PAUSED     = 'paused';

	public function table(): string {
		return Schema::audit_runs_table();
	}

	/**
	 * @param array<string,mixed> $data
	 */
	public function create( array $data ): int {
		global $wpdb;
		$now              = gmdate( 'Y-m-d H:i:s' );
		$wpdb->last_error = '';
		$result           = $wpdb->insert(
			$this->table(),
			array(
				'request_id'         => (string) ( $data['request_id'] ?? '' ),
				'job_id'             => (int) ( $data['job_id'] ?? 0 ),
				'status'             => (string) ( $data['status'] ?? self::STATUS_QUEUED ),
				'mode'               => (string) ( $data['mode'] ?? 'scan_only' ),
				'post_types'         => wp_json_encode( $data['post_types'] ?? array( 'post', 'page' ) ),
				'total_objects'      => (int) ( $data['total_objects'] ?? 0 ),
				'processed_objects'  => 0,
				'issues_found'       => 0,
				'cursor_id'          => 0,
				'seo_adapter'        => (string) ( $data['seo_adapter'] ?? '' ),
				'meta_json'          => wp_json_encode( $data['meta'] ?? array() ),
				'created_gmt'        => $now,
				'updated_gmt'        => $now,
			),
			array( '%s', '%d', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s' )
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
	 * @param array<string,mixed> $fields
	 */
	public function update( int $id, array $fields ): bool {
		global $wpdb;
		$fields['updated_gmt'] = gmdate( 'Y-m-d H:i:s' );
		$formats = array();
		foreach ( $fields as $key => $value ) {
			$formats[] = is_int( $value ) ? '%d' : '%s';
		}
		return false !== $wpdb->update( $this->table(), $fields, array( 'id' => $id ), $formats, array( '%d' ) );
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
		$types = json_decode( (string) ( $row['post_types'] ?? '[]' ), true );
		$meta  = json_decode( (string) ( $row['meta_json'] ?? '{}' ), true );
		$row['post_types'] = is_array( $types ) ? $types : array();
		$row['meta']       = is_array( $meta ) ? $meta : array();
		unset( $row['meta_json'] );
		$row['id']                 = (int) ( $row['id'] ?? 0 );
		$row['job_id']             = (int) ( $row['job_id'] ?? 0 );
		$row['total_objects']      = (int) ( $row['total_objects'] ?? 0 );
		$row['processed_objects']  = (int) ( $row['processed_objects'] ?? 0 );
		$row['issues_found']       = (int) ( $row['issues_found'] ?? 0 );
		$row['cursor_id']          = (int) ( $row['cursor_id'] ?? 0 );
		return $row;
	}
}
