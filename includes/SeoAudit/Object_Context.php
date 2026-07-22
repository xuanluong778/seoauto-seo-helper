<?php
/**
 * Normalized object context for checkers.
 *
 * @package SEOAuto\SEOHelper
 */

declare(strict_types=1);

namespace SEOAuto\SEOHelper\SeoAudit;

final class Object_Context {

	/**
	 * @param array<string,mixed> $seo
	 * @param list<array{src:string,alt:string}> $images
	 * @param list<string> $links
	 * @param list<array{tag:string,text:string}> $headings
	 */
	public function __construct(
		public int $object_id,
		public string $object_type,
		public string $post_status,
		public string $title,
		public string $content_html,
		public string $content_text,
		public int $word_count,
		public string $permalink,
		public bool $has_featured_image,
		public array $seo,
		public array $images,
		public array $links,
		public array $headings,
		public string $seo_adapter
	) {}

	/**
	 * @return list<string>
	 */
	public static function audit_post_types(): array {
		$types = array( 'post', 'page' );
		if ( class_exists( '\\WooCommerce' ) || post_type_exists( 'product' ) ) {
			$types[] = 'product';
		}
		/** @var list<string> $types */
		$types = apply_filters( 'seoauto_helper_audit_post_types', $types );
		return array_values( array_unique( array_map( 'sanitize_key', $types ) ) );
	}

	/**
	 * @param \WP_Post|object $post Post-like object with ID, post_type, post_status, post_title, post_content.
	 */
	public static function from_post( object $post, Seo_Meta_Reader $reader ): self {
		$id      = (int) ( $post->ID ?? 0 );
		$html    = (string) ( $post->post_content ?? '' );
		$text    = wp_strip_all_tags( strip_shortcodes( $html ) );
		$text    = preg_replace( '/\s+/u', ' ', $text ?? '' ) ?? '';
		$text    = trim( $text );
		$words   = $text === '' ? 0 : count( preg_split( '/\s+/u', $text ) ?: array() );
		$seo     = $reader->read( $id );

		$images = array();
		if ( preg_match_all( '/<img\b[^>]*>/i', $html, $img_tags ) ) {
			foreach ( $img_tags[0] as $tag ) {
				$src = '';
				$alt = '';
				if ( preg_match( '/\bsrc=["\']([^"\']+)["\']/i', $tag, $m ) ) {
					$src = html_entity_decode( $m[1], ENT_QUOTES | ENT_HTML5 );
				}
				if ( preg_match( '/\balt=["\']([^"\']*)["\']/i', $tag, $m ) ) {
					$alt = html_entity_decode( $m[1], ENT_QUOTES | ENT_HTML5 );
				} elseif ( ! preg_match( '/\balt=/i', $tag ) ) {
					$alt = '';
				}
				if ( $src !== '' ) {
					$images[] = array(
						'src' => $src,
						'alt' => $alt,
					);
				}
			}
		}

		$links = array();
		if ( preg_match_all( '/<a\b[^>]*\bhref=["\']([^"\']+)["\']/i', $html, $hrefs ) ) {
			foreach ( $hrefs[1] as $href ) {
				$href = html_entity_decode( (string) $href, ENT_QUOTES | ENT_HTML5 );
				if ( $href !== '' && ! str_starts_with( $href, '#' ) && ! str_starts_with( strtolower( $href ), 'mailto:' ) && ! str_starts_with( strtolower( $href ), 'tel:' ) ) {
					$links[] = $href;
				}
			}
		}
		$links = array_values( array_unique( $links ) );

		$headings = array();
		if ( preg_match_all( '/<(h[1-6])\b[^>]*>(.*?)<\/\1>/is', $html, $hs, PREG_SET_ORDER ) ) {
			foreach ( $hs as $h ) {
				$headings[] = array(
					'tag'  => strtolower( $h[1] ),
					'text' => trim( wp_strip_all_tags( $h[2] ) ),
				);
			}
		}

		return new self(
			$id,
			(string) ( $post->post_type ?? 'post' ),
			(string) ( $post->post_status ?? 'publish' ),
			(string) ( $post->post_title ?? '' ),
			$html,
			$text,
			$words,
			(string) get_permalink( $post ),
			has_post_thumbnail( $post ),
			$seo,
			$images,
			$links,
			$headings,
			(string) ( $seo['adapter'] ?? 'native' )
		);
	}
}
