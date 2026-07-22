<?php
/**
 * Shared admin layout helpers.
 *
 * @package SEOAuto\SEOHelper
 */

declare(strict_types=1);

namespace SEOAuto\SEOHelper\Admin;

final class Admin_View {

	public static function wrap_start( string $title, string $description = '' ): void {
		echo '<div class="wrap seoauto-helper-wrap">';
		echo '<h1>' . esc_html( $title ) . '</h1>';
		if ( $description !== '' ) {
			echo '<p class="description">' . esc_html( $description ) . '</p>';
		}
		settings_errors( 'seoauto_helper' );
	}

	public static function wrap_end(): void {
		echo '</div>';
	}

	/**
	 * @param array<string,mixed> $caps
	 */
	public static function render_status_notices(
		bool $locked,
		bool $network_grace,
		array $caps,
		string $upgrade_url
	): void {
		$firewall_block = \SEOAuto\SEOHelper\Security\Firewall_Guidance::get_recorded_block();
		if ( null !== $firewall_block ) {
			\SEOAuto\SEOHelper\Security\Firewall_Guidance::render_notice( $firewall_block );
		}
		if ( $network_grace ) {
			echo '<div class="notice notice-info inline seoauto-helper-network-notice"><p>';
			echo '<strong>' . esc_html__( 'Mất kết nối SEOAuto tạm thời', 'seoauto-seo-helper' ) . '</strong>';
			echo ' — ' . esc_html( (string) ( $caps['lock_message'] ?? '' ) );
			if ( ! empty( $caps['last_api_error'] ) ) {
				echo '<br /><em>' . esc_html( (string) $caps['last_api_error'] ) . '</em>';
			}
			echo '</p></div>';
		}
		if ( $locked ) {
			echo '<div class="notice notice-error inline seoauto-helper-lock-notice"><p>';
			echo '<strong>' . esc_html__( 'Gói hết hạn / Plugin LOCKED', 'seoauto-seo-helper' ) . '</strong>';
			echo ' — ' . esc_html( (string) ( $caps['lock_message'] ?? '' ) );
			if ( $upgrade_url !== '' ) {
				echo ' <a href="' . esc_url( $upgrade_url ) . '" target="_blank" rel="noopener noreferrer">';
				echo esc_html__( 'Nâng cấp gói trên SEOAuto', 'seoauto-seo-helper' ) . '</a>';
			}
			echo '</p></div>';
		}
	}

	public static function status_badge(
		bool $network_grace,
		bool $locked,
		bool $connected
	): void {
		if ( $network_grace ) {
			echo '<span class="seoauto-helper-badge is-degraded">' . esc_html__( 'Mất kết nối (grace)', 'seoauto-seo-helper' ) . '</span>';
		} elseif ( $locked ) {
			echo '<span class="seoauto-helper-badge is-locked">' . esc_html__( 'LOCKED — gói hết hạn', 'seoauto-seo-helper' ) . '</span>';
		} elseif ( $connected ) {
			echo '<span class="seoauto-helper-badge is-ok">' . esc_html__( 'Đã kết nối', 'seoauto-seo-helper' ) . '</span>';
		} else {
			echo '<span class="seoauto-helper-badge is-off">' . esc_html__( 'Chưa kết nối', 'seoauto-seo-helper' ) . '</span>';
		}
	}

	public static function nav_tabs( string $active ): void {
		$pages = array(
			'overview' => array(
				'slug'  => Admin_Menu::SLUG_OVERVIEW,
				'label' => __( 'Tổng quan', 'seoauto-seo-helper' ),
			),
			'connect'  => array(
				'slug'  => Admin_Menu::SLUG_CONNECT,
				'label' => __( 'Kết nối', 'seoauto-seo-helper' ),
			),
			'logs'     => array(
				'slug'  => Admin_Menu::SLUG_LOGS,
				'label' => __( 'Nhật ký', 'seoauto-seo-helper' ),
			),
		);
		echo '<nav class="nav-tab-wrapper seoauto-helper-tabs">';
		foreach ( $pages as $key => $page ) {
			$class = $key === $active ? 'nav-tab nav-tab-active' : 'nav-tab';
			echo '<a class="' . esc_attr( $class ) . '" href="' . esc_url( admin_url( 'admin.php?page=' . $page['slug'] ) ) . '">';
			echo esc_html( $page['label'] ) . '</a>';
		}
		echo '</nav>';
	}
}
