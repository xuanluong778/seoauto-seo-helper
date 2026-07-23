<?php
/**
 * ContentOps batch persistence.
 *
 * @package SEOAuto\SEOHelper
 */

declare(strict_types=1);

namespace SEOAuto\SEOHelper\ContentOps\Stores;

use SEOAuto\SEOHelper\Post\Schema;

final class Batch_Store {

	/**
	 * @param array<string,mixed> $row
	 */
	public function insert( array $row ): int {
		global $wpdb;
		$now = gmdate( 'Y-m-d H:i:s' );
		$wpdb->insert(
			Schema::content_batches_table(),
			array(
				'request_id'      => (string) ( $row['request_id'] ?? '' ),
				'connection_id'   => (int) ( $row['connection_id'] ?? 0 ),
				'organization_id' => (int) ( $row['organization_id'] ?? 0 ),
				'user_scope'      => (string) ( $row['user_scope'] ?? '' ),
				'status'          => (string) ( $row['status'] ?? 'draft' ),
				'total_items'     => (int) ( $row['total_items'] ?? 0 ),
				'processed_items' => (int) ( $row['processed_items'] ?? 0 ),
				'failed_items'    => (int) ( $row['failed_items'] ?? 0 ),
				'payload_json'    => isset( $row['payload'] ) ? wp_json_encode( $row['payload'] ) : null,
				'result_json'     => isset( $row['result'] ) ? wp_json_encode( $row['result'] ) : null,
				'error_code'      => $row['error_code'] ?? null,
				'error_message'   => $row['error_message'] ?? null,
				'created_gmt'     => $now,
				'updated_gmt'     => $now,
				'expires_gmt'     => $row['expires_gmt'] ?? gmdate( 'Y-m-d H:i:s', time() + 30 * DAY_IN_SECONDS ),
			),
			array( '%s', '%d', '%d', '%s', '%s', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public function get( int $id ): ?array {
		global $wpdb;
		$table = Schema::content_batches_table();
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", $id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public function get_by_request_id( string $request_id ): ?array {
		global $wpdb;
		$table = Schema::content_batches_table();
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE request_id = %s LIMIT 1", $request_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	/**
	 * @param array<string,mixed> $fields
	 */
	public function update( int $id, array $fields ): void {
		global $wpdb;
		$data  = array( 'updated_gmt' => gmdate( 'Y-m-d H:i:s' ) );
		$fmt   = array( '%s' );
		$map   = array(
			'status'          => '%s',
			'total_items'     => '%d',
			'processed_items' => '%d',
			'failed_items'    => '%d',
			'error_code'      => '%s',
			'error_message'   => '%s',
			'expires_gmt'     => '%s',
		);
		foreach ( $map as $key => $f ) {
			if ( array_key_exists( $key, $fields ) ) {
				$data[ $key ] = $fields[ $key ];
				$fmt[]        = $f;
			}
		}
		if ( isset( $fields['payload'] ) ) {
			$data['payload_json'] = wp_json_encode( $fields['payload'] );
			$fmt[]                = '%s';
		}
		if ( isset( $fields['result'] ) ) {
			$data['result_json'] = wp_json_encode( $fields['result'] );
			$fmt[]               = '%s';
		}
		$wpdb->update( Schema::content_batches_table(), $data, array( 'id' => $id ), $fmt, array( '%d' ) );
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	public function recent_for_connection( int $connection_id, int $limit = 20 ): array {
		global $wpdb;
		$table = Schema::content_batches_table();
		$limit = max( 1, min( 100, $limit ) );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE connection_id = %d ORDER BY id DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$connection_id,
				$limit
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}
}
