<?php
/**
 * ContentOps backup snapshot persistence.
 *
 * @package SEOAuto\SEOHelper
 */

declare(strict_types=1);

namespace SEOAuto\SEOHelper\ContentOps\Stores;

use SEOAuto\SEOHelper\Post\Schema;

final class Backup_Store {

	/**
	 * @param array<string,mixed> $payload
	 */
	public function insert(
		int $batch_id,
		int $change_id,
		int $connection_id,
		int $post_id,
		string $checksum,
		array $payload,
		string $seo_adapter,
		int $retention_days = 30
	): int {
		global $wpdb;
		$now = gmdate( 'Y-m-d H:i:s' );
		$wpdb->insert(
			Schema::content_backups_table(),
			array(
				'batch_id'      => $batch_id,
				'change_id'     => $change_id,
				'connection_id' => $connection_id,
				'post_id'       => $post_id,
				'checksum'      => $checksum,
				'payload_json'  => wp_json_encode( $payload ),
				'seo_adapter'   => $seo_adapter,
				'status'        => 'ready',
				'created_gmt'   => $now,
				'expires_gmt'   => gmdate( 'Y-m-d H:i:s', time() + max( 1, $retention_days ) * DAY_IN_SECONDS ),
			),
			array( '%d', '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public function get( int $id ): ?array {
		global $wpdb;
		$table = Schema::content_backups_table();
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", $id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public function payload( int $id ): ?array {
		$row = $this->get( $id );
		if ( null === $row ) {
			return null;
		}
		$decoded = json_decode( (string) ( $row['payload_json'] ?? '' ), true );
		return is_array( $decoded ) ? $decoded : null;
	}

	public function purge_expired(): int {
		global $wpdb;
		$table = Schema::content_backups_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE expires_gmt < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				gmdate( 'Y-m-d H:i:s' )
			)
		);
	}
}
