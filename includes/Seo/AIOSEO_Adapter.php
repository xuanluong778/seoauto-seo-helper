<?php
/**
 * All in One SEO adapter — writes via AIOSEO model API when available.
 *
 * Does not modify AIOSEO plugin source.
 *
 * @package SEOAuto\SEOHelper
 */

declare(strict_types=1);

namespace SEOAuto\SEOHelper\Seo;

final class AIOSEO_Adapter implements Seo_Adapter_Interface {

	public function id(): string {
		return 'aioseo';
	}

	public function is_active(): bool {
		return defined( 'AIOSEO_VERSION' )
			|| function_exists( 'aioseo' )
			|| class_exists( '\\AIOSEO\\Plugin\\Common\\Models\\Post' );
	}

	public function sync( int $post_id, Seo_Payload $payload ): void {
		if ( $this->sync_via_model( $post_id, $payload ) ) {
			return;
		}
		$this->sync_via_meta( $post_id, $payload );
	}

	private function sync_via_model( int $post_id, Seo_Payload $payload ): bool {
		if ( ! class_exists( '\\AIOSEO\\Plugin\\Common\\Models\\Post' ) ) {
			return false;
		}

		try {
			/** @var object $post */
			$post = \AIOSEO\Plugin\Common\Models\Post::getPost( $post_id );
			if ( ! is_object( $post ) || ! method_exists( $post, 'save' ) ) {
				return false;
			}

			if ( $payload->title !== '' && property_exists( $post, 'title' ) ) {
				$post->title = $payload->title;
			}
			if ( $payload->description !== '' && property_exists( $post, 'description' ) ) {
				$post->description = $payload->description;
			}
			if ( $payload->focus_keyword !== '' && property_exists( $post, 'keywords' ) ) {
				$post->keywords = $payload->focus_keyword;
			}
			if ( $payload->canonical !== '' && property_exists( $post, 'canonical_url' ) ) {
				$post->canonical_url = $payload->canonical;
			}

			$og_title = $payload->social_title !== '' ? $payload->social_title : $payload->title;
			$og_desc  = $payload->social_description !== '' ? $payload->social_description : $payload->description;
			if ( $og_title !== '' && property_exists( $post, 'og_title' ) ) {
				$post->og_title = $og_title;
			}
			if ( $og_desc !== '' && property_exists( $post, 'og_description' ) ) {
				$post->og_description = $og_desc;
			}
			if ( $payload->social_image !== '' ) {
				if ( property_exists( $post, 'og_image_type' ) ) {
					$post->og_image_type = 'custom';
				}
				if ( property_exists( $post, 'og_image_url' ) ) {
					$post->og_image_url = $payload->social_image;
				}
				if ( property_exists( $post, 'twitter_image_url' ) ) {
					$post->twitter_image_url = $payload->social_image;
				}
			}
			if ( $og_title !== '' && property_exists( $post, 'twitter_title' ) ) {
				$post->twitter_title = $og_title;
			}
			if ( $og_desc !== '' && property_exists( $post, 'twitter_description' ) ) {
				$post->twitter_description = $og_desc;
			}

			if ( null !== $payload->robots_index || null !== $payload->robots_follow ) {
				if ( property_exists( $post, 'robots_default' ) ) {
					$post->robots_default = false;
				}
				if ( null !== $payload->robots_index && property_exists( $post, 'robots_noindex' ) ) {
					$post->robots_noindex = ! $payload->robots_index;
				}
				if ( null !== $payload->robots_follow && property_exists( $post, 'robots_nofollow' ) ) {
					$post->robots_nofollow = ! $payload->robots_follow;
				}
			}

			if ( $payload->schema_type !== '' && property_exists( $post, 'schema' ) ) {
				$schema = is_array( $post->schema ) ? $post->schema : array();
				if ( isset( $schema['graphs'] ) && is_array( $schema['graphs'] ) ) {
					// Leave complex graphs; set type hint meta separately below.
				}
				$post->schema = $schema;
			}

			$post->save();

			if ( $payload->schema_type !== '' ) {
				update_post_meta( $post_id, '_aioseo_schema_type', $payload->schema_type );
			}

			return true;
		} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			return false;
		}
	}

	/**
	 * Conservative meta fallback when AIOSEO model API is unavailable.
	 */
	private function sync_via_meta( int $post_id, Seo_Payload $payload ): void {
		if ( $payload->title !== '' ) {
			update_post_meta( $post_id, '_aioseo_title', $payload->title );
		}
		if ( $payload->description !== '' ) {
			update_post_meta( $post_id, '_aioseo_description', $payload->description );
		}
		if ( $payload->focus_keyword !== '' ) {
			update_post_meta( $post_id, '_aioseo_keywords', $payload->focus_keyword );
		}
		if ( $payload->canonical !== '' ) {
			update_post_meta( $post_id, '_aioseo_canonical_url', $payload->canonical );
		}
		if ( null !== $payload->robots_index ) {
			update_post_meta( $post_id, '_aioseo_noindex', $payload->robots_index ? '0' : '1' );
		}
		if ( null !== $payload->robots_follow ) {
			update_post_meta( $post_id, '_aioseo_nofollow', $payload->robots_follow ? '0' : '1' );
		}
		if ( $payload->schema_type !== '' ) {
			update_post_meta( $post_id, '_aioseo_schema_type', $payload->schema_type );
		}

		$og_title = $payload->social_title !== '' ? $payload->social_title : $payload->title;
		$og_desc  = $payload->social_description !== '' ? $payload->social_description : $payload->description;
		if ( $og_title !== '' ) {
			update_post_meta( $post_id, '_aioseo_og_title', $og_title );
		}
		if ( $og_desc !== '' ) {
			update_post_meta( $post_id, '_aioseo_og_description', $og_desc );
		}
		if ( $payload->social_image !== '' ) {
			update_post_meta( $post_id, '_aioseo_og_image', $payload->social_image );
		}
	}
}
