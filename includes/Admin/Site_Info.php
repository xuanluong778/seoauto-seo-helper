<?php
/**
 * Site environment info for admin dashboard.
 *
 * @package SEOAuto\SEOHelper
 */

declare(strict_types=1);

namespace SEOAuto\SEOHelper\Admin;

use SEOAuto\SEOHelper\Connection\Connection_Manager;
use SEOAuto\SEOHelper\Post\Schema;
use SEOAuto\SEOHelper\Seo\Seo_Facade;

final class Site_Info {

	public function __construct(
		private Connection_Manager $connection,
		private Seo_Facade $seo
	) {}

	/**
	 * @return array{installed:bool,active:bool,waf:bool,label:string}
	 */
	public function wordfence(): array {
		$installed = defined( 'WORDFENCE_VERSION' )
			|| class_exists( 'wordfence', false )
			|| class_exists( '\\wordfence', false );

		if ( ! $installed && ! function_exists( 'is_plugin_active' ) ) {
			require_once \ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$active = $installed && function_exists( 'is_plugin_active' )
			&& is_plugin_active( 'wordfence/wordfence.php' );

		$waf = $active && (bool) get_option( 'wordfenceActivated', false );

		$label = __( 'Không cài', 'seoauto-seo-helper' );
		if ( $installed && $active ) {
			$label = $waf
				? __( 'Đang bật (WAF)', 'seoauto-seo-helper' )
				: __( 'Đang bật', 'seoauto-seo-helper' );
		} elseif ( $installed ) {
			$label = __( 'Đã cài — chưa kích hoạt', 'seoauto-seo-helper' );
		}

		return array(
			'installed' => $installed,
			'active'    => $active,
			'waf'       => $waf,
			'label'     => $label,
		);
	}

	/**
	 * LiteSpeed Cache — informational only (does not affect HMAC auth).
	 *
	 * @return array{installed:bool,active:bool,label:string}
	 */
	public function litespeed_cache(): array {
		$installed = defined( 'LSCWP_V' )
			|| class_exists( 'LiteSpeed_Cache', false )
			|| class_exists( '\\LiteSpeed\\Core', false );

		if ( ! $installed && ! function_exists( 'is_plugin_active' ) ) {
			require_once \ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$active = $installed && function_exists( 'is_plugin_active' )
			&& is_plugin_active( 'litespeed-cache/litespeed-cache.php' );

		$label = __( 'Không cài', 'seoauto-seo-helper' );
		if ( $installed && $active ) {
			$label = __( 'Đang hoạt động', 'seoauto-seo-helper' );
		} elseif ( $installed ) {
			$label = __( 'Đã cài — chưa kích hoạt', 'seoauto-seo-helper' );
		}

		return array(
			'installed' => $installed,
			'active'    => $active,
			'label'     => $label,
		);
	}

	public function seo_plugin_label(): string {
		$id = $this->seo->active_id();
		return match ( $id ) {
			'rankmath' => 'Rank Math',
			'yoast'    => 'Yoast SEO',
			'aioseo'   => 'AIOSEO',
			default    => __( 'Native (plugin SEOAuto)', 'seoauto-seo-helper' ),
		};
	}

	public function published_post_count(): int {
		global $wpdb;
		if ( ! isset( $wpdb ) ) {
			return 0;
		}
		$table = Schema::article_map_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		return max( 0, (int) $count );
	}

	public function format_iso_local( string $iso ): string {
		if ( $iso === '' ) {
			return '—';
		}
		$ts = strtotime( $iso );
		if ( false === $ts ) {
			return $iso;
		}
		return wp_date( 'Y-m-d H:i:s', $ts );
	}
}
