<?php
/**
 * Per-post lock to prevent concurrent ContentOps mutations.
 *
 * @package SEOAuto\SEOHelper
 */

declare(strict_types=1);

namespace SEOAuto\SEOHelper\ContentOps;

use SEOAuto\SEOHelper\Post\Schema;
use WP_Error;

final class Content_Lock {

	public const TTL_SECONDS = 120;

	/**
	 * @return bool|WP_Error
	 */
	public function acquire( int $post_id, int $connection_id, int $batch_id, int $change_id, string $owner_token ): bool|WP_Error {
		global $wpdb;
		$table   = Schema::content_locks_table();
		$now     = gmdate( 'Y-m-d H:i:s' );
		$until   = gmdate( 'Y-m-d H:i:s', time() + self::TTL_SECONDS );
		$existing = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE post_id = %d LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$post_id
			),
			ARRAY_A
		);

		if ( is_array( $existing ) ) {
			$exp = (string) ( $existing['locked_until_gmt'] ?? '' );
			$tok = (string) ( $existing['owner_token'] ?? '' );
			if ( $exp >= $now && $tok !== '' && ! hash_equals( $tok, $owner_token ) ) {
				return new WP_Error(
					'seoauto_content_locked',
					__( 'Bài viết đang bị khóa bởi thao tác ContentOps khác.', 'seoauto-seo-helper' ),
					array( 'status' => 409 )
				);
			}
			$wpdb->update(
				$table,
				array(
					'connection_id'    => $connection_id,
					'batch_id'         => $batch_id,
					'change_id'        => $change_id,
					'owner_token'      => $owner_token,
					'locked_until_gmt' => $until,
				),
				array( 'post_id' => $post_id ),
				array( '%d', '%d', '%d', '%s', '%s' ),
				array( '%d' )
			);
			return true;
		}

		$ok = $wpdb->insert(
			$table,
			array(
				'post_id'          => $post_id,
				'connection_id'    => $connection_id,
				'batch_id'         => $batch_id,
				'change_id'        => $change_id,
				'owner_token'      => $owner_token,
				'locked_until_gmt' => $until,
				'created_gmt'      => $now,
			),
			array( '%d', '%d', '%d', '%d', '%s', '%s', '%s' )
		);
		if ( false === $ok ) {
			return new WP_Error( 'seoauto_lock_failed', __( 'Không thể khóa bài viết.', 'seoauto-seo-helper' ), array( 'status' => 500 ) );
		}
		return true;
	}

	public function release( int $post_id, string $owner_token ): void {
		global $wpdb;
		$table = Schema::content_locks_table();
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE post_id = %d AND owner_token = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$post_id,
				$owner_token
			)
		);
	}

	public function purge_expired(): int {
		global $wpdb;
		$table = Schema::content_locks_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE locked_until_gmt < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				gmdate( 'Y-m-d H:i:s' )
			)
		);
	}
}
