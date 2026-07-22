<?php
/**
 * Admin-controlled publishing settings (allowed post types).
 *
 * @package SEOAuto\SEOHelper
 */

declare(strict_types=1);

namespace SEOAuto\SEOHelper\Post;

final class Publishing_Settings {

	public const OPTION_KEY = 'allowed_post_types';

	/**
	 * Default: only built-in posts.
	 *
	 * @return list<string>
	 */
	public function defaults(): array {
		return array( 'post' );
	}

	/**
	 * @return list<string>
	 */
	public function allowed_post_types(): array {
		$raw = get_option( SEOAUTO_HELPER_PREFIX . self::OPTION_KEY, $this->defaults() );
		if ( ! is_array( $raw ) ) {
			return $this->defaults();
		}

		$out = array();
		foreach ( $raw as $type ) {
			$type = sanitize_key( (string) $type );
			if ( $type !== '' && post_type_exists( $type ) ) {
				$out[] = $type;
			}
		}

		$out = array_values( array_unique( $out ) );
		return $out !== array() ? $out : $this->defaults();
	}

	public function is_post_type_allowed( string $post_type ): bool {
		$post_type = sanitize_key( $post_type );
		return in_array( $post_type, $this->allowed_post_types(), true );
	}

	/**
	 * Persist admin selection. Always keeps `post` if list would otherwise be empty.
	 *
	 * @param list<string>|array<int,mixed> $types
	 * @return list<string>
	 */
	public function save_allowed_post_types( array $types ): array {
		$allowed = array();
		foreach ( $types as $type ) {
			$type = sanitize_key( (string) $type );
			if ( $type === '' || ! post_type_exists( $type ) ) {
				continue;
			}
			$obj = get_post_type_object( $type );
			if ( ! $obj || empty( $obj->public ) ) {
				continue;
			}
			// Never allow attachments / revisions / nav menus.
			if ( in_array( $type, array( 'attachment', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache', 'user_request', 'wp_block', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation' ), true ) ) {
				continue;
			}
			$allowed[] = $type;
		}
		$allowed = array_values( array_unique( $allowed ) );
		if ( $allowed === array() ) {
			$allowed = $this->defaults();
		}

		$name = SEOAUTO_HELPER_PREFIX . self::OPTION_KEY;
		if ( false === get_option( $name, false ) ) {
			add_option( $name, $allowed, '', false );
		} else {
			update_option( $name, $allowed, false );
		}

		return $allowed;
	}

	/**
	 * Public post types eligible for admin toggle (excluding internals).
	 *
	 * @return array<string,string> slug => label
	 */
	public function selectable_post_types(): array {
		$types = get_post_types(
			array(
				'public' => true,
			),
			'objects'
		);
		$out = array();
		foreach ( $types as $slug => $obj ) {
			$slug = sanitize_key( (string) $slug );
			if ( in_array( $slug, array( 'attachment' ), true ) ) {
				continue;
			}
			$out[ $slug ] = (string) ( $obj->labels->singular_name ?? $slug );
		}
		return $out;
	}
}
