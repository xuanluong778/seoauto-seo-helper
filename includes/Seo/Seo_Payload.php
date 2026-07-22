<?php
/**
 * Normalized SEO payload for adapters.
 *
 * @package SEOAuto\SEOHelper
 */

declare(strict_types=1);

namespace SEOAuto\SEOHelper\Seo;

final class Seo_Payload {

	public string $title = '';
	public string $description = '';
	public string $focus_keyword = '';
	public string $canonical = '';
	public ?bool $robots_index = null;
	public ?bool $robots_follow = null;
	public string $schema_type = '';
	public string $social_title = '';
	public string $social_description = '';
	public string $social_image = '';

	/**
	 * @param array<string,mixed> $raw
	 */
	public static function from_array( array $raw ): self {
		$p = new self();

		$p->title         = sanitize_text_field( (string) ( $raw['title'] ?? $raw['seo_title'] ?? '' ) );
		$p->description   = sanitize_textarea_field( (string) ( $raw['description'] ?? $raw['meta_description'] ?? '' ) );
		$p->focus_keyword = sanitize_text_field( (string) ( $raw['focus_keyword'] ?? $raw['focuskw'] ?? '' ) );
		$p->canonical     = esc_url_raw( (string) ( $raw['canonical'] ?? $raw['canonical_url'] ?? '' ) );
		$p->schema_type   = sanitize_text_field( (string) ( $raw['schema_type'] ?? '' ) );

		$p->social_title       = sanitize_text_field( (string) ( $raw['social_title'] ?? $raw['og_title'] ?? '' ) );
		$p->social_description = sanitize_textarea_field( (string) ( $raw['social_description'] ?? $raw['og_description'] ?? '' ) );
		$p->social_image       = esc_url_raw( (string) ( $raw['social_image'] ?? $raw['og_image'] ?? '' ) );

		$robots = $raw['robots'] ?? null;
		if ( is_string( $robots ) && $robots !== '' ) {
			$lower = strtolower( $robots );
			if ( str_contains( $lower, 'noindex' ) ) {
				$p->robots_index = false;
			} elseif ( str_contains( $lower, 'index' ) ) {
				$p->robots_index = true;
			}
			if ( str_contains( $lower, 'nofollow' ) ) {
				$p->robots_follow = false;
			} elseif ( str_contains( $lower, 'follow' ) ) {
				$p->robots_follow = true;
			}
		} elseif ( is_array( $robots ) ) {
			if ( array_key_exists( 'index', $robots ) ) {
				$p->robots_index = (bool) $robots['index'];
			}
			if ( array_key_exists( 'follow', $robots ) ) {
				$p->robots_follow = (bool) $robots['follow'];
			}
			if ( array_key_exists( 'noindex', $robots ) ) {
				$p->robots_index = ! (bool) $robots['noindex'];
			}
			if ( array_key_exists( 'nofollow', $robots ) ) {
				$p->robots_follow = ! (bool) $robots['nofollow'];
			}
		}

		if ( isset( $raw['robots_index'] ) ) {
			$p->robots_index = (bool) $raw['robots_index'];
		}
		if ( isset( $raw['robots_follow'] ) ) {
			$p->robots_follow = (bool) $raw['robots_follow'];
		}

		if ( $p->schema_type !== '' ) {
			$allowed = array(
				'Article',
				'NewsArticle',
				'BlogPosting',
				'WebPage',
				'AboutPage',
				'ContactPage',
				'FAQPage',
				'Product',
			);
			$filtered = apply_filters( 'seoauto_helper_schema_types', $allowed );
			if ( is_array( $filtered ) && ! in_array( $p->schema_type, $filtered, true ) ) {
				$p->schema_type = 'Article';
			}
		}

		return $p;
	}

	public function is_empty(): bool {
		return $this->title === ''
			&& $this->description === ''
			&& $this->focus_keyword === ''
			&& $this->canonical === ''
			&& null === $this->robots_index
			&& null === $this->robots_follow
			&& $this->schema_type === ''
			&& $this->social_title === ''
			&& $this->social_description === ''
			&& $this->social_image === '';
	}
}
