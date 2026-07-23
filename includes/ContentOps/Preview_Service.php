<?php
/**
 * Read-only preview of proposed ContentOps changes.
 *
 * @package SEOAuto\SEOHelper
 */

declare(strict_types=1);

namespace SEOAuto\SEOHelper\ContentOps;

use WP_Error;

final class Preview_Service {

	public function __construct( private Snapshot_Builder $snapshots ) {}

	/**
	 * Build preview for one post. Never writes.
	 *
	 * @param array<string,mixed> $proposed
	 * @return array<string,mixed>|WP_Error
	 */
	public function preview_item( int $post_id, array $proposed, string $reason = '' ): array|WP_Error {
		$current = $this->snapshots->capture( $post_id );
		if ( $current instanceof WP_Error ) {
			return $current;
		}

		$fields   = $this->normalize_proposed( $proposed );
		$diff     = array();
		$risks    = array();
		$risk_max = 'safe';

		foreach ( $fields as $key => $new_val ) {
			$old_val = $this->current_field( $current, $key );
			if ( $this->values_equal( $old_val, $new_val ) ) {
				continue;
			}
			$risk = $this->field_risk( $key );
			$diff[ $key ] = array(
				'current'   => $this->preview_value( $old_val ),
				'proposed'  => $this->preview_value( $new_val ),
				'risk'      => $risk,
			);
			$risks[] = $risk;
			$risk_max = $this->max_risk( $risk_max, $risk );
		}

		return array(
			'post_id'         => $post_id,
			'title'           => (string) ( $current['title'] ?? '' ),
			'status'          => (string) ( $current['status'] ?? '' ),
			'post_type'       => (string) ( $current['post_type'] ?? '' ),
			'reason'          => sanitize_textarea_field( $reason ),
			'risk_level'      => $risk_max,
			'has_changes'     => $diff !== array(),
			'diff'            => $diff,
			'current_checksum'=> (string) ( $current['checksum'] ?? '' ),
			'seo_adapter'     => (string) ( ( $current['seo']['adapter'] ?? '' ) ),
			'read_only'       => true,
		);
	}

	/**
	 * @param list<array<string,mixed>> $items
	 * @return array{items:list<array<string,mixed>>,summary:array<string,mixed>}
	 */
	public function preview_batch( array $items ): array {
		$out     = array();
		$changed = 0;
		$errors  = 0;
		$risks   = array( 'safe' => 0, 'sensitive' => 0, 'dangerous' => 0 );

		foreach ( $items as $item ) {
			$post_id  = (int) ( $item['post_id'] ?? 0 );
			$proposed = is_array( $item['proposed'] ?? null ) ? $item['proposed'] : array();
			$reason   = (string) ( $item['reason'] ?? '' );
			$row      = $this->preview_item( $post_id, $proposed, $reason );
			if ( $row instanceof WP_Error ) {
				++$errors;
				$out[] = array(
					'post_id' => $post_id,
					'error'   => $row->get_error_code(),
					'message' => $row->get_error_message(),
				);
				continue;
			}
			if ( ! empty( $row['has_changes'] ) ) {
				++$changed;
			}
			$rl = (string) ( $row['risk_level'] ?? 'safe' );
			if ( isset( $risks[ $rl ] ) ) {
				++$risks[ $rl ];
			}
			$out[] = $row;
		}

		return array(
			'items'   => $out,
			'summary' => array(
				'total'           => count( $items ),
				'with_changes'    => $changed,
				'errors'          => $errors,
				'risk_counts'     => $risks,
				'mutates_data'    => false,
			),
		);
	}

	/**
	 * @param array<string,mixed> $proposed
	 * @return array<string,mixed>
	 */
	private function normalize_proposed( array $proposed ): array {
		$allowed = array(
			'title', 'content', 'excerpt', 'slug', 'status',
			'taxonomies', 'featured_image_id', 'custom_fields', 'seo',
		);
		$out = array();
		foreach ( $allowed as $key ) {
			if ( array_key_exists( $key, $proposed ) ) {
				$out[ $key ] = $proposed[ $key ];
			}
		}
		// Flat SEO convenience keys.
		foreach ( array( 'seo_title', 'meta_description', 'focus_keyword', 'canonical' ) as $flat ) {
			if ( isset( $proposed[ $flat ] ) ) {
				if ( ! isset( $out['seo'] ) || ! is_array( $out['seo'] ) ) {
					$out['seo'] = array();
				}
				$map = array(
					'seo_title'         => 'title',
					'meta_description'  => 'description',
					'focus_keyword'     => 'focus_keyword',
					'canonical'         => 'canonical',
				);
				$out['seo'][ $map[ $flat ] ] = $proposed[ $flat ];
			}
		}
		return $out;
	}

	/**
	 * @param array<string,mixed> $current
	 * @return mixed
	 */
	private function current_field( array $current, string $key ) {
		return match ( $key ) {
			'title'             => $current['title'] ?? '',
			'content'           => $current['content'] ?? '',
			'excerpt'           => $current['excerpt'] ?? '',
			'slug'              => $current['slug'] ?? '',
			'status'            => $current['status'] ?? '',
			'taxonomies'        => $current['taxonomies'] ?? array(),
			'featured_image_id' => (int) ( $current['featured_image_id'] ?? 0 ),
			'custom_fields'     => $current['custom_fields'] ?? array(),
			'seo'               => $current['seo'] ?? array(),
			default             => null,
		};
	}

	private function field_risk( string $key ): string {
		return match ( $key ) {
			'content', 'status', 'slug', 'taxonomies' => 'sensitive',
			'custom_fields' => 'dangerous',
			default         => 'safe',
		};
	}

	private function max_risk( string $a, string $b ): string {
		$order = array( 'safe' => 1, 'sensitive' => 2, 'dangerous' => 3 );
		return ( ( $order[ $b ] ?? 0 ) > ( $order[ $a ] ?? 0 ) ) ? $b : $a;
	}

	/**
	 * @param mixed $a
	 * @param mixed $b
	 */
	private function values_equal( $a, $b ): bool {
		return wp_json_encode( $a ) === wp_json_encode( $b );
	}

	/**
	 * Truncate large values for preview response (no full content dump unless short).
	 *
	 * @param mixed $value
	 * @return mixed
	 */
	private function preview_value( $value ) {
		if ( is_string( $value ) && strlen( $value ) > 500 ) {
			return array(
				'truncated' => true,
				'length'    => strlen( $value ),
				'preview'   => substr( $value, 0, 500 ),
				'checksum'  => hash( 'sha256', $value ),
			);
		}
		return $value;
	}
}
