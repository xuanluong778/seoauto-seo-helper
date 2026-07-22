<?php
/**
 * Deduplicate media by source_image_id and file hash.
 *
 * @package SEOAuto\SEOHelper
 */

declare(strict_types=1);

namespace SEOAuto\SEOHelper\Media;

use SEOAuto\SEOHelper\Post\Schema;

final class Media_Map {

	public function find_by_source( int $connection_id, string $source_image_id ): int {
		global $wpdb;
		$source_image_id = $this->normalize_source_id( $source_image_id );
		if ( $source_image_id === '' ) {
			return 0;
		}
		$table = Schema::media_map_table();
		$id    = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT attachment_id FROM {$table} WHERE connection_id = %d AND source_image_id = %s LIMIT 1",
				$connection_id,
				$source_image_id
			)
		);
		return $this->living_attachment_id( (int) $id );
	}

	public function find_by_hash( int $connection_id, string $file_hash ): int {
		global $wpdb;
		$file_hash = $this->normalize_hash( $file_hash );
		if ( $file_hash === '' ) {
			return 0;
		}
		$table = Schema::media_map_table();
		$id    = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT attachment_id FROM {$table} WHERE connection_id = %d AND file_hash = %s LIMIT 1",
				$connection_id,
				$file_hash
			)
		);
		return $this->living_attachment_id( (int) $id );
	}

	public function remember( int $connection_id, int $attachment_id, string $file_hash, string $source_image_id = '' ): void {
		global $wpdb;
		$file_hash       = $this->normalize_hash( $file_hash );
		$source_image_id = $this->normalize_source_id( $source_image_id );
		if ( $attachment_id <= 0 || $file_hash === '' ) {
			return;
		}

		$now   = gmdate( 'Y-m-d H:i:s' );
		$table = Schema::media_map_table();

		// Prefer update existing hash row.
		$existing_hash = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE connection_id = %d AND file_hash = %s LIMIT 1",
				$connection_id,
				$file_hash
			)
		);
		if ( $existing_hash ) {
			$wpdb->update(
				$table,
				array(
					'attachment_id'   => $attachment_id,
					'source_image_id' => $source_image_id !== '' ? $source_image_id : null,
					'updated_gmt'     => $now,
				),
				array( 'id' => (int) $existing_hash ),
				array( '%d', '%s', '%s' ),
				array( '%d' )
			);
			return;
		}

		if ( $source_image_id !== '' ) {
			$existing_src = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$table} WHERE connection_id = %d AND source_image_id = %s LIMIT 1",
					$connection_id,
					$source_image_id
				)
			);
			if ( $existing_src ) {
				$wpdb->update(
					$table,
					array(
						'attachment_id' => $attachment_id,
						'file_hash'     => $file_hash,
						'updated_gmt'   => $now,
					),
					array( 'id' => (int) $existing_src ),
					array( '%d', '%s', '%s' ),
					array( '%d' )
				);
				return;
			}
		}

		$wpdb->insert(
			$table,
			array(
				'connection_id'   => $connection_id,
				'source_image_id' => $source_image_id !== '' ? $source_image_id : null,
				'file_hash'       => $file_hash,
				'attachment_id'   => $attachment_id,
				'created_gmt'     => $now,
				'updated_gmt'     => $now,
			),
			array( '%d', '%s', '%s', '%d', '%s', '%s' )
		);
	}

	public function normalize_source_id( string $id ): string {
		$id = sanitize_text_field( trim( $id ) );
		if ( $id === '' || strlen( $id ) > 191 ) {
			return '';
		}
		return $id;
	}

	public function normalize_hash( string $hash ): string {
		$hash = strtolower( trim( $hash ) );
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $hash ) ) {
			return '';
		}
		return $hash;
	}

	private function living_attachment_id( int $attachment_id ): int {
		if ( $attachment_id <= 0 ) {
			return 0;
		}
		$post = get_post( $attachment_id );
		if ( ! $post || $post->post_type !== 'attachment' ) {
			return 0;
		}
		return $attachment_id;
	}
}
