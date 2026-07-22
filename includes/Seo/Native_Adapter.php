<?php
/**
 * Native WordPress SEO fallback — only when no SEO plugin is active.
 *
 * Owns title, description, canonical, robots, Open Graph and Schema.
 * Never runs alongside Rank Math / Yoast / AIOSEO.
 *
 * @package SEOAuto\SEOHelper
 */

declare(strict_types=1);

namespace SEOAuto\SEOHelper\Seo;

final class Native_Adapter implements Seo_Adapter_Interface {

	public const META_TITLE       = '_seoauto_seo_title';
	public const META_DESC        = '_seoauto_meta_description';
	public const META_FOCUS       = '_seoauto_focus_kw';
	public const META_CANONICAL   = '_seoauto_canonical';
	public const META_ROBOTS_I    = '_seoauto_robots_index';
	public const META_ROBOTS_F    = '_seoauto_robots_follow';
	public const META_SCHEMA      = '_seoauto_schema_type';
	public const META_OG_TITLE    = '_seoauto_social_title';
	public const META_OG_DESC     = '_seoauto_social_description';
	public const META_OG_IMAGE    = '_seoauto_social_image';

	public function id(): string {
		return 'native';
	}

	public function is_active(): bool {
		// Native is the fallback — always "available", selected only when no plugin wins.
		return true;
	}

	public function sync( int $post_id, Seo_Payload $payload ): void {
		if ( $payload->title !== '' ) {
			update_post_meta( $post_id, self::META_TITLE, $payload->title );
		}
		if ( $payload->description !== '' ) {
			update_post_meta( $post_id, self::META_DESC, $payload->description );
		}
		if ( $payload->focus_keyword !== '' ) {
			update_post_meta( $post_id, self::META_FOCUS, $payload->focus_keyword );
		}
		if ( $payload->canonical !== '' ) {
			update_post_meta( $post_id, self::META_CANONICAL, $payload->canonical );
		}
		if ( null !== $payload->robots_index ) {
			update_post_meta( $post_id, self::META_ROBOTS_I, $payload->robots_index ? '1' : '0' );
		}
		if ( null !== $payload->robots_follow ) {
			update_post_meta( $post_id, self::META_ROBOTS_F, $payload->robots_follow ? '1' : '0' );
		}
		if ( $payload->schema_type !== '' ) {
			update_post_meta( $post_id, self::META_SCHEMA, $payload->schema_type );
		}

		$og_title = $payload->social_title !== '' ? $payload->social_title : $payload->title;
		$og_desc  = $payload->social_description !== '' ? $payload->social_description : $payload->description;
		if ( $og_title !== '' ) {
			update_post_meta( $post_id, self::META_OG_TITLE, $og_title );
		}
		if ( $og_desc !== '' ) {
			update_post_meta( $post_id, self::META_OG_DESC, $og_desc );
		}
		if ( $payload->social_image !== '' ) {
			update_post_meta( $post_id, self::META_OG_IMAGE, $payload->social_image );
		}
	}

	/**
	 * Frontend output — registered only when this adapter is the active one.
	 */
	public function register_frontend(): void {
		add_filter( 'pre_get_document_title', array( $this, 'filter_document_title' ), 20 );
		add_filter( 'document_title_parts', array( $this, 'filter_title_parts' ), 20 );
		add_filter( 'get_canonical_url', array( $this, 'filter_canonical' ), 20, 2 );
		add_filter( 'wp_robots', array( $this, 'filter_robots' ), 20 );
		add_action( 'wp_head', array( $this, 'render_head' ), 5 );
	}

	public function filter_document_title( string $title ): string {
		$post_id = $this->singular_post_id();
		if ( $post_id <= 0 ) {
			return $title;
		}
		$custom = (string) get_post_meta( $post_id, self::META_TITLE, true );
		return $custom !== '' ? $custom : $title;
	}

	/**
	 * @param array<string,string> $parts
	 * @return array<string,string>
	 */
	public function filter_title_parts( array $parts ): array {
		$post_id = $this->singular_post_id();
		if ( $post_id <= 0 ) {
			return $parts;
		}
		$custom = (string) get_post_meta( $post_id, self::META_TITLE, true );
		if ( $custom !== '' ) {
			$parts['title'] = $custom;
		}
		return $parts;
	}

	/**
	 * @param string|false $canonical
	 * @param \WP_Post|null $post
	 */
	public function filter_canonical( $canonical, $post = null ): string {
		$post_id = 0;
		if ( is_object( $post ) && isset( $post->ID ) ) {
			$post_id = (int) $post->ID;
		} else {
			$post_id = $this->singular_post_id();
		}
		if ( $post_id <= 0 ) {
			return is_string( $canonical ) ? $canonical : '';
		}
		$custom = (string) get_post_meta( $post_id, self::META_CANONICAL, true );
		if ( $custom !== '' ) {
			return $custom;
		}
		return is_string( $canonical ) ? $canonical : '';
	}

	/**
	 * @param array<string,bool|string> $robots
	 * @return array<string,bool|string>
	 */
	public function filter_robots( array $robots ): array {
		$post_id = $this->singular_post_id();
		if ( $post_id <= 0 ) {
			return $robots;
		}
		$index = get_post_meta( $post_id, self::META_ROBOTS_I, true );
		$follow = get_post_meta( $post_id, self::META_ROBOTS_F, true );
		if ( $index === '0' ) {
			$robots['noindex'] = true;
			unset( $robots['index'] );
		} elseif ( $index === '1' ) {
			unset( $robots['noindex'] );
		}
		if ( $follow === '0' ) {
			$robots['nofollow'] = true;
			unset( $robots['follow'] );
		} elseif ( $follow === '1' ) {
			unset( $robots['nofollow'] );
		}
		return $robots;
	}

	public function render_head(): void {
		$post_id = $this->singular_post_id();
		if ( $post_id <= 0 ) {
			return;
		}

		$desc = (string) get_post_meta( $post_id, self::META_DESC, true );
		if ( $desc !== '' ) {
			printf( '<meta name="description" content="%s" />' . "\n", esc_attr( $desc ) );
		}

		$this->render_open_graph( $post_id );
		$this->render_schema( $post_id );
	}

	private function render_open_graph( int $post_id ): void {
		$title = (string) get_post_meta( $post_id, self::META_OG_TITLE, true );
		if ( $title === '' ) {
			$title = (string) get_post_meta( $post_id, self::META_TITLE, true );
		}
		if ( $title === '' ) {
			$title = get_the_title( $post_id );
		}

		$desc = (string) get_post_meta( $post_id, self::META_OG_DESC, true );
		if ( $desc === '' ) {
			$desc = (string) get_post_meta( $post_id, self::META_DESC, true );
		}

		$image = (string) get_post_meta( $post_id, self::META_OG_IMAGE, true );
		if ( $image === '' ) {
			$thumb = get_the_post_thumbnail_url( $post_id, 'full' );
			$image = is_string( $thumb ) ? $thumb : '';
		}

		$url = get_permalink( $post_id );

		echo "\n<!-- SEOAuto SEO Helper Open Graph (native) -->\n";
		echo '<meta property="og:type" content="article" />' . "\n";
		printf( '<meta property="og:title" content="%s" />' . "\n", esc_attr( $title ) );
		if ( $desc !== '' ) {
			printf( '<meta property="og:description" content="%s" />' . "\n", esc_attr( $desc ) );
		}
		if ( is_string( $url ) && $url !== '' ) {
			printf( '<meta property="og:url" content="%s" />' . "\n", esc_url( $url ) );
		}
		if ( $image !== '' ) {
			printf( '<meta property="og:image" content="%s" />' . "\n", esc_url( $image ) );
		}
	}

	private function render_schema( int $post_id ): void {
		$type = (string) get_post_meta( $post_id, self::META_SCHEMA, true );
		if ( $type === '' ) {
			$type = 'Article';
		}

		$title = (string) get_post_meta( $post_id, self::META_TITLE, true );
		if ( $title === '' ) {
			$title = get_the_title( $post_id );
		}
		$desc = (string) get_post_meta( $post_id, self::META_DESC, true );
		$url  = get_permalink( $post_id );
		$img  = (string) get_post_meta( $post_id, self::META_OG_IMAGE, true );
		if ( $img === '' ) {
			$thumb = get_the_post_thumbnail_url( $post_id, 'full' );
			$img   = is_string( $thumb ) ? $thumb : '';
		}

		$data = array(
			'@context'      => 'https://schema.org',
			'@type'         => $type,
			'headline'      => $title,
			'description'   => $desc,
			'url'           => $url,
			'datePublished' => get_the_date( 'c', $post_id ),
			'dateModified'  => get_the_modified_date( 'c', $post_id ),
			'author'        => array(
				'@type' => 'Person',
				'name'  => get_the_author_meta( 'display_name', (int) get_post_field( 'post_author', $post_id ) ),
			),
			'publisher'     => array(
				'@type' => 'Organization',
				'name'  => get_bloginfo( 'name' ),
				'url'   => home_url( '/' ),
			),
		);
		if ( $img !== '' ) {
			$data['image'] = array( $img );
		}

		$json = wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $json ) ) {
			return;
		}
		echo "\n<!-- SEOAuto SEO Helper Schema (native) -->\n";
		echo '<script type="application/ld+json">' . $json . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	private function singular_post_id(): int {
		if ( ! is_singular() ) {
			return 0;
		}
		return (int) get_queried_object_id();
	}
}
