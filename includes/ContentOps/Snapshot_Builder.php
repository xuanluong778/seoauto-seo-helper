<?php
/**
 * Capture full post snapshot for ContentOps backup/rollback.
 *
 * @package SEOAuto\SEOHelper
 */

declare(strict_types=1);

namespace SEOAuto\SEOHelper\ContentOps;

use SEOAuto\SEOHelper\Seo\Seo_Facade;
use SEOAuto\SEOHelper\SeoAudit\Seo_Meta_Reader;
use WP_Error;
use WP_Post;

final class Snapshot_Builder {

	/** @var list<string> */
	private const SKIP_META_PREFIXES = array(
		'_edit_',
		'_wp_old_',
		'_wp_trash_',
		'_wp_desired_',
		'_oembed_',
		'_enclos',
		'_pingme',
		'_thumbnail_id', // captured separately as featured_image_id
	);

	/** @var list<string> */
	private const SKIP_META_KEYS = array(
		'_edit_lock',
		'_edit_last',
		'_wp_page_template',
	);

	/** @var list<string> */
	private const SECRET_META_FRAGMENTS = array(
		'secret',
		'token',
		'password',
		'api_key',
		'apikey',
		'auth',
		'nonce',
		'signature',
		'credential',
	);

	public function __construct( private Seo_Facade $seo ) {}

	/**
	 * @return array<string,mixed>|WP_Error
	 */
	public function capture( int $post_id ): array|WP_Error {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return new WP_Error( 'seoauto_post_not_found', __( 'Bài viết không tồn tại.', 'seoauto-seo-helper' ), array( 'status' => 404 ) );
		}

		$reader = new Seo_Meta_Reader( $this->seo );
		$seo    = $reader->read( $post_id );
		$seo    = array_merge( $seo, $this->read_social_meta( $post_id, (string) ( $seo['adapter'] ?? '' ) ) );

		$payload = array(
			'post_id'            => $post_id,
			'title'              => (string) $post->post_title,
			'content'            => (string) $post->post_content,
			'excerpt'            => (string) $post->post_excerpt,
			'slug'               => (string) $post->post_name,
			'status'             => (string) $post->post_status,
			'post_type'          => (string) $post->post_type,
			'taxonomies'         => $this->capture_taxonomies( $post_id ),
			'featured_image_id'  => (int) get_post_thumbnail_id( $post_id ),
			'custom_fields'      => $this->capture_custom_fields( $post_id ),
			'seo'                => $seo,
			'captured_gmt'       => gmdate( 'c' ),
		);
		$payload['checksum'] = self::checksum( $payload );
		return $payload;
	}

	/**
	 * Canonical checksum — excludes volatile captured_gmt.
	 *
	 * @param array<string,mixed> $payload
	 */
	public static function checksum( array $payload ): string {
		$copy = $payload;
		unset( $copy['checksum'], $copy['captured_gmt'] );
		$json = wp_json_encode( $copy, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		return hash( 'sha256', is_string( $json ) ? $json : '' );
	}

	/**
	 * @return array<string,list<array{term_id:int,slug:string,name:string}>>
	 */
	private function capture_taxonomies( int $post_id ): array {
		$taxonomies = get_object_taxonomies( get_post_type( $post_id ), 'names' );
		$out        = array();
		if ( ! is_array( $taxonomies ) ) {
			return $out;
		}
		foreach ( $taxonomies as $tax ) {
			$terms = wp_get_object_terms( $post_id, $tax, array( 'fields' => 'all' ) );
			if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
				continue;
			}
			$rows = array();
			foreach ( $terms as $term ) {
				$rows[] = array(
					'term_id' => (int) $term->term_id,
					'slug'    => (string) $term->slug,
					'name'    => (string) $term->name,
				);
			}
			$out[ (string) $tax ] = $rows;
		}
		return $out;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function capture_custom_fields( int $post_id ): array {
		$all = get_post_meta( $post_id );
		if ( ! is_array( $all ) ) {
			return array();
		}
		$out = array();
		foreach ( $all as $key => $values ) {
			$key = (string) $key;
			if ( $this->should_skip_meta_key( $key ) ) {
				continue;
			}
			if ( ! is_array( $values ) || $values === array() ) {
				continue;
			}
			// Single-value meta stored as scalar; multi as list.
			$decoded = array_map(
				static function ( $v ) {
					if ( is_string( $v ) ) {
						$maybe = maybe_unserialize( $v );
						return $maybe;
					}
					return $v;
				},
				$values
			);
			$out[ $key ] = count( $decoded ) === 1 ? $decoded[0] : $decoded;
		}
		return $out;
	}

	private function should_skip_meta_key( string $key ): bool {
		if ( in_array( $key, self::SKIP_META_KEYS, true ) ) {
			return true;
		}
		foreach ( self::SKIP_META_PREFIXES as $prefix ) {
			if ( str_starts_with( $key, $prefix ) ) {
				return true;
			}
		}
		// SEO keys captured in seo blob — still keep in custom_fields for exact restore,
		// except secret-looking keys.
		$lower = strtolower( $key );
		foreach ( self::SECRET_META_FRAGMENTS as $frag ) {
			if ( str_contains( $lower, $frag ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @return array<string,string>
	 */
	private function read_social_meta( int $post_id, string $adapter ): array {
		return match ( $adapter ) {
			'rankmath' => array(
				'social_title'       => (string) get_post_meta( $post_id, 'rank_math_facebook_title', true ),
				'social_description' => (string) get_post_meta( $post_id, 'rank_math_facebook_description', true ),
				'social_image'       => (string) get_post_meta( $post_id, 'rank_math_facebook_image', true ),
			),
			'yoast'    => array(
				'social_title'       => (string) get_post_meta( $post_id, '_yoast_wpseo_opengraph-title', true ),
				'social_description' => (string) get_post_meta( $post_id, '_yoast_wpseo_opengraph-description', true ),
				'social_image'       => (string) get_post_meta( $post_id, '_yoast_wpseo_opengraph-image', true ),
			),
			'aioseo'   => array(
				'social_title'       => (string) get_post_meta( $post_id, '_aioseo_og_title', true ),
				'social_description' => (string) get_post_meta( $post_id, '_aioseo_og_description', true ),
				'social_image'       => (string) get_post_meta( $post_id, '_aioseo_og_image_url', true ),
			),
			default    => array(
				'social_title'       => (string) get_post_meta( $post_id, '_seoauto_og_title', true ),
				'social_description' => (string) get_post_meta( $post_id, '_seoauto_og_description', true ),
				'social_image'       => (string) get_post_meta( $post_id, '_seoauto_og_image', true ),
			),
		};
	}
}
