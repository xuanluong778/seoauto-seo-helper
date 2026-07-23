<?php
/**
 * Restore post core fields + taxonomies + featured + custom fields + SEO from snapshot.
 *
 * @package SEOAuto\SEOHelper
 */

declare(strict_types=1);

namespace SEOAuto\SEOHelper\ContentOps;

use SEOAuto\SEOHelper\Seo\Seo_Facade;
use WP_Error;

final class Restore_Service {

	public function __construct(
		private Snapshot_Builder $snapshots,
		private Seo_Facade $seo
	) {}

	/**
	 * @param array<string,mixed> $proposed
	 * @return bool|WP_Error
	 */
	public function apply_proposed( int $post_id, array $proposed ): bool|WP_Error {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'seoauto_post_not_found', __( 'Bài viết không tồn tại.', 'seoauto-seo-helper' ), array( 'status' => 404 ) );
		}

		$update = array( 'ID' => $post_id );
		if ( isset( $proposed['title'] ) ) {
			$update['post_title'] = sanitize_text_field( (string) $proposed['title'] );
		}
		if ( isset( $proposed['content'] ) ) {
			$update['post_content'] = (string) $proposed['content'];
		}
		if ( isset( $proposed['excerpt'] ) ) {
			$update['post_excerpt'] = sanitize_textarea_field( (string) $proposed['excerpt'] );
		}
		if ( isset( $proposed['slug'] ) ) {
			$update['post_name'] = sanitize_title( (string) $proposed['slug'] );
		}
		if ( isset( $proposed['status'] ) ) {
			$update['post_status'] = sanitize_key( (string) $proposed['status'] );
		}

		if ( count( $update ) > 1 ) {
			$result = wp_update_post( $update, true );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		if ( isset( $proposed['taxonomies'] ) && is_array( $proposed['taxonomies'] ) ) {
			$tax_err = $this->restore_taxonomies( $post_id, $proposed['taxonomies'] );
			if ( $tax_err instanceof WP_Error ) {
				return $tax_err;
			}
		}

		if ( array_key_exists( 'featured_image_id', $proposed ) ) {
			$fid = (int) $proposed['featured_image_id'];
			if ( $fid > 0 ) {
				set_post_thumbnail( $post_id, $fid );
			} else {
				delete_post_thumbnail( $post_id );
			}
		}

		if ( isset( $proposed['custom_fields'] ) && is_array( $proposed['custom_fields'] ) ) {
			$this->restore_custom_fields( $post_id, $proposed['custom_fields'], false );
		}

		if ( isset( $proposed['seo'] ) && is_array( $proposed['seo'] ) ) {
			$this->seo->sync_post_meta( $post_id, $proposed['seo'] );
		}

		return true;
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return bool|WP_Error
	 */
	public function restore_full( int $post_id, array $payload ): bool|WP_Error {
		$proposed = array(
			'title'             => $payload['title'] ?? '',
			'content'           => $payload['content'] ?? '',
			'excerpt'           => $payload['excerpt'] ?? '',
			'slug'              => $payload['slug'] ?? '',
			'status'            => $payload['status'] ?? 'draft',
			'taxonomies'        => $payload['taxonomies'] ?? array(),
			'featured_image_id' => (int) ( $payload['featured_image_id'] ?? 0 ),
			'seo'               => is_array( $payload['seo'] ?? null ) ? $payload['seo'] : array(),
		);
		$ok = $this->apply_proposed( $post_id, $proposed );
		if ( $ok instanceof WP_Error ) {
			return $ok;
		}
		if ( isset( $payload['custom_fields'] ) && is_array( $payload['custom_fields'] ) ) {
			$this->restore_custom_fields( $post_id, $payload['custom_fields'], true );
		}
		return true;
	}

	/**
	 * @param array<string,mixed> $taxonomies
	 * @return true|WP_Error
	 */
	private function restore_taxonomies( int $post_id, array $taxonomies ): bool|WP_Error {
		foreach ( $taxonomies as $tax => $terms ) {
			$tax = sanitize_key( (string) $tax );
			if ( $tax === '' || ! taxonomy_exists( $tax ) ) {
				continue;
			}
			$ids = array();
			if ( is_array( $terms ) ) {
				foreach ( $terms as $term ) {
					if ( is_array( $term ) && isset( $term['term_id'] ) ) {
						$ids[] = (int) $term['term_id'];
					} elseif ( is_numeric( $term ) ) {
						$ids[] = (int) $term;
					}
				}
			}
			$result = wp_set_object_terms( $post_id, $ids, $tax, false );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}
		return true;
	}

	/**
	 * @param array<string,mixed> $fields
	 */
	private function restore_custom_fields( int $post_id, array $fields, bool $replace_all_backed ): void {
		foreach ( $fields as $key => $value ) {
			$key = (string) $key;
			if ( $key === '' || str_starts_with( $key, '_edit_' ) ) {
				continue;
			}
			$lower = strtolower( $key );
			foreach ( array( 'secret', 'token', 'password', 'api_key', 'nonce', 'signature' ) as $frag ) {
				if ( str_contains( $lower, $frag ) ) {
					continue 2;
				}
			}
			delete_post_meta( $post_id, $key );
			if ( is_array( $value ) && array_is_list( $value ) ) {
				foreach ( $value as $v ) {
					add_post_meta( $post_id, $key, $v, false );
				}
			} else {
				update_post_meta( $post_id, $key, $value );
			}
		}
		unset( $replace_all_backed );
	}

	/**
	 * @return array<string,mixed>|WP_Error
	 */
	public function capture_now( int $post_id ): array|WP_Error {
		return $this->snapshots->capture( $post_id );
	}
}
