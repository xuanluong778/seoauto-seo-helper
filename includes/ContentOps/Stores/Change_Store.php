<?php
/**
 * ContentOps per-item change persistence.
 *
 * @package SEOAuto\SEOHelper
 */

declare(strict_types=1);

namespace SEOAuto\SEOHelper\ContentOps\Stores;

use SEOAuto\SEOHelper\Post\Schema;

final class Change_Store {

	/**
	 * @param array<string,mixed> $row
	 */
	public function insert( array $row ): int {
		global $wpdb;
		$now = gmdate( 'Y-m-d H:i:s' );
		$wpdb->insert(
			Schema::content_changes_table(),
			array(
				'batch_id'         => (int) ( $row['batch_id'] ?? 0 ),
				'connection_id'    => (int) ( $row['connection_id'] ?? 0 ),
				'post_id'          => (int) ( $row['post_id'] ?? 0 ),
				'idempotency_key'  => (string) ( $row['idempotency_key'] ?? '' ),
				'status'           => (string) ( $row['status'] ?? 'pending' ),
				'risk_level'       => (string) ( $row['risk_level'] ?? 'safe' ),
				'reason'           => $row['reason'] ?? null,
				'proposed_json'    => isset( $row['proposed'] ) ? wp_json_encode( $row['proposed'] ) : null,
				'preview_json'     => isset( $row['preview'] ) ? wp_json_encode( $row['preview'] ) : null,
				'backup_id'        => (int) ( $row['backup_id'] ?? 0 ),
				'before_checksum'  => (string) ( $row['before_checksum'] ?? '' ),
				'after_checksum'   => (string) ( $row['after_checksum'] ?? '' ),
				'recheck_json'     => isset( $row['recheck'] ) ? wp_json_encode( $row['recheck'] ) : null,
				'attempts'         => (int) ( $row['attempts'] ?? 0 ),
				'max_attempts'     => (int) ( $row['max_attempts'] ?? 3 ),
				'error_code'       => $row['error_code'] ?? null,
				'error_message'    => $row['error_message'] ?? null,
				'locked_until_gmt' => $row['locked_until_gmt'] ?? null,
				'created_gmt'      => $now,
				'updated_gmt'      => $now,
			)
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public function get( int $id ): ?array {
		global $wpdb;
		$table = Schema::content_changes_table();
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", $id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public function get_by_idempotency( int $batch_id, string $key ): ?array {
		global $wpdb;
		$table = Schema::content_changes_table();
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE batch_id = %d AND idempotency_key = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$batch_id,
				$key
			),
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	public function list_for_batch( int $batch_id ): array {
		global $wpdb;
		$table = Schema::content_changes_table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE batch_id = %d ORDER BY id ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$batch_id
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * @param array<string,mixed> $fields
	 */
	public function update( int $id, array $fields ): void {
		global $wpdb;
		$data = array( 'updated_gmt' => gmdate( 'Y-m-d H:i:s' ) );
		$fmt  = array( '%s' );
		$map  = array(
			'status'           => '%s',
			'risk_level'       => '%s',
			'reason'           => '%s',
			'backup_id'        => '%d',
			'before_checksum'  => '%s',
			'after_checksum'   => '%s',
			'attempts'         => '%d',
			'max_attempts'     => '%d',
			'error_code'       => '%s',
			'error_message'    => '%s',
			'locked_until_gmt' => '%s',
		);
		foreach ( $map as $key => $f ) {
			if ( array_key_exists( $key, $fields ) ) {
				$data[ $key ] = $fields[ $key ];
				$fmt[]        = $f;
			}
		}
		if ( isset( $fields['proposed'] ) ) {
			$data['proposed_json'] = wp_json_encode( $fields['proposed'] );
			$fmt[]                 = '%s';
		}
		if ( isset( $fields['preview'] ) ) {
			$data['preview_json'] = wp_json_encode( $fields['preview'] );
			$fmt[]                = '%s';
		}
		if ( isset( $fields['recheck'] ) ) {
			$data['recheck_json'] = wp_json_encode( $fields['recheck'] );
			$fmt[]                = '%s';
		}
		$wpdb->update( Schema::content_changes_table(), $data, array( 'id' => $id ), $fmt, array( '%d' ) );
	}
}
