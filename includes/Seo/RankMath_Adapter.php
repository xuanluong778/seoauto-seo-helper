<?php
/**
 * Rank Math SEO adapter — writes Rank Math post meta only.
 *
 * @package SEOAuto\SEOHelper
 */

declare(strict_types=1);

namespace SEOAuto\SEOHelper\Seo;

final class RankMath_Adapter implements Seo_Adapter_Interface {

	public function id(): string {
		return 'rankmath';
	}

	public function is_active(): bool {
		return defined( 'RANK_MATH_VERSION' ) || class_exists( '\\RankMath' );
	}

	public function sync( int $post_id, Seo_Payload $payload ): void {
		if ( $payload->title !== '' ) {
			update_post_meta( $post_id, 'rank_math_title', $payload->title );
		}
		if ( $payload->description !== '' ) {
			update_post_meta( $post_id, 'rank_math_description', $payload->description );
		}
		if ( $payload->focus_keyword !== '' ) {
			update_post_meta( $post_id, 'rank_math_focus_keyword', $payload->focus_keyword );
		}
		if ( $payload->canonical !== '' ) {
			update_post_meta( $post_id, 'rank_math_canonical_url', $payload->canonical );
		}

		if ( null !== $payload->robots_index || null !== $payload->robots_follow ) {
			$robots = array();
			$robots[] = ( false === $payload->robots_index ) ? 'noindex' : 'index';
			$robots[] = ( false === $payload->robots_follow ) ? 'nofollow' : 'follow';
			update_post_meta( $post_id, 'rank_math_robots', $robots );
		}

		if ( $payload->schema_type !== '' ) {
			// Rank Math primary snippet type for articles/pages.
			$snippet = strtolower( $payload->schema_type );
			if ( in_array( $payload->schema_type, array( 'Article', 'NewsArticle', 'BlogPosting' ), true ) ) {
				update_post_meta( $post_id, 'rank_math_rich_snippet', 'article' );
				update_post_meta( $post_id, 'rank_math_snippet_article_type', $payload->schema_type );
			} elseif ( $payload->schema_type === 'Product' ) {
				update_post_meta( $post_id, 'rank_math_rich_snippet', 'product' );
			} else {
				update_post_meta( $post_id, 'rank_math_rich_snippet', $snippet );
			}
		}

		$og_title = $payload->social_title !== '' ? $payload->social_title : $payload->title;
		$og_desc  = $payload->social_description !== '' ? $payload->social_description : $payload->description;
		if ( $og_title !== '' ) {
			update_post_meta( $post_id, 'rank_math_facebook_title', $og_title );
			update_post_meta( $post_id, 'rank_math_twitter_title', $og_title );
		}
		if ( $og_desc !== '' ) {
			update_post_meta( $post_id, 'rank_math_facebook_description', $og_desc );
			update_post_meta( $post_id, 'rank_math_twitter_description', $og_desc );
		}
		if ( $payload->social_image !== '' ) {
			update_post_meta( $post_id, 'rank_math_facebook_image', $payload->social_image );
			update_post_meta( $post_id, 'rank_math_twitter_image', $payload->social_image );
			update_post_meta( $post_id, 'rank_math_facebook_image_overlay', 'off' );
		}
	}
}
