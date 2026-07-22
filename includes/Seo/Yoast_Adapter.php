<?php
/**
 * Yoast SEO adapter — writes Yoast post meta only.
 *
 * @package SEOAuto\SEOHelper
 */

declare(strict_types=1);

namespace SEOAuto\SEOHelper\Seo;

final class Yoast_Adapter implements Seo_Adapter_Interface {

	public function id(): string {
		return 'yoast';
	}

	public function is_active(): bool {
		return defined( 'WPSEO_VERSION' ) || class_exists( '\\WPSEO_Options' );
	}

	public function sync( int $post_id, Seo_Payload $payload ): void {
		if ( $payload->title !== '' ) {
			update_post_meta( $post_id, '_yoast_wpseo_title', $payload->title );
		}
		if ( $payload->description !== '' ) {
			update_post_meta( $post_id, '_yoast_wpseo_metadesc', $payload->description );
		}
		if ( $payload->focus_keyword !== '' ) {
			update_post_meta( $post_id, '_yoast_wpseo_focuskw', $payload->focus_keyword );
		}
		if ( $payload->canonical !== '' ) {
			update_post_meta( $post_id, '_yoast_wpseo_canonical', $payload->canonical );
		}

		if ( null !== $payload->robots_index ) {
			update_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', $payload->robots_index ? '0' : '1' );
		}
		if ( null !== $payload->robots_follow ) {
			update_post_meta( $post_id, '_yoast_wpseo_meta-robots-nofollow', $payload->robots_follow ? '0' : '1' );
		}

		if ( $payload->schema_type !== '' ) {
			if ( in_array( $payload->schema_type, array( 'Article', 'NewsArticle', 'BlogPosting' ), true ) ) {
				update_post_meta( $post_id, '_yoast_wpseo_schema_article_type', $payload->schema_type );
				update_post_meta( $post_id, '_yoast_wpseo_schema_page_type', 'WebPage' );
			} else {
				update_post_meta( $post_id, '_yoast_wpseo_schema_page_type', $payload->schema_type );
			}
		}

		$og_title = $payload->social_title !== '' ? $payload->social_title : $payload->title;
		$og_desc  = $payload->social_description !== '' ? $payload->social_description : $payload->description;
		if ( $og_title !== '' ) {
			update_post_meta( $post_id, '_yoast_wpseo_opengraph-title', $og_title );
			update_post_meta( $post_id, '_yoast_wpseo_twitter-title', $og_title );
		}
		if ( $og_desc !== '' ) {
			update_post_meta( $post_id, '_yoast_wpseo_opengraph-description', $og_desc );
			update_post_meta( $post_id, '_yoast_wpseo_twitter-description', $og_desc );
		}
		if ( $payload->social_image !== '' ) {
			update_post_meta( $post_id, '_yoast_wpseo_opengraph-image', $payload->social_image );
			update_post_meta( $post_id, '_yoast_wpseo_twitter-image', $payload->social_image );
		}
	}
}
