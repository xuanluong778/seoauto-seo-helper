<?php
/**
 * Shared admin layout helpers.
 *
 * @package SEOAuto\SEOHelper
 */

declare(strict_types=1);

namespace SEOAuto\SEOHelper\Admin;

use SEOAuto\SEOHelper\ContentOps\ContentOps_Service;
use SEOAuto\SEOHelper\Entitlement\Entitlement_Manager;

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
			echo '<strong>' . esc_html__( 'Tạm thời mất kết nối SEOAuto', 'seoauto-seo-helper' ) . '</strong>';
			echo ' — ' . esc_html__( 'Plugin vẫn hoạt động trong thời gian chờ kết nối lại. Hãy thử “Kiểm tra kết nối” sau vài phút.', 'seoauto-seo-helper' );
			echo '</p></div>';
		}
		if ( $locked ) {
			echo '<div class="notice notice-error inline seoauto-helper-lock-notice"><p>';
			echo '<strong>' . esc_html__( 'Gói đã hết hạn', 'seoauto-seo-helper' ) . '</strong>';
			echo ' — ' . esc_html__( 'Một số tính năng bị tạm khóa. Gia hạn gói trên SEOAuto để tiếp tục dùng đầy đủ.', 'seoauto-seo-helper' );
			if ( $upgrade_url !== '' ) {
				echo ' <a href="' . esc_url( $upgrade_url ) . '" target="_blank" rel="noopener noreferrer">';
				echo esc_html__( 'Gia hạn / nâng cấp gói', 'seoauto-seo-helper' ) . '</a>';
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
			echo '<span class="seoauto-helper-badge is-degraded">' . esc_html__( 'Mất kết nối tạm thời', 'seoauto-seo-helper' ) . '</span>';
		} elseif ( $locked ) {
			echo '<span class="seoauto-helper-badge is-locked">' . esc_html__( 'Gói hết hạn', 'seoauto-seo-helper' ) . '</span>';
		} elseif ( $connected ) {
			echo '<span class="seoauto-helper-badge is-ok">' . esc_html__( 'Đã kết nối', 'seoauto-seo-helper' ) . '</span>';
		} else {
			echo '<span class="seoauto-helper-badge is-off">' . esc_html__( 'Chưa kết nối', 'seoauto-seo-helper' ) . '</span>';
		}
	}

	public static function nav_tabs( string $active, ?Entitlement_Manager $entitlement = null ): void {
		$pages = array(
			'overview' => array(
				'slug'  => Admin_Menu::SLUG_OVERVIEW,
				'label' => __( 'Tổng quan', 'seoauto-seo-helper' ),
			),
			'connect'  => array(
				'slug'  => Admin_Menu::SLUG_CONNECT,
				'label' => __( 'Kết nối', 'seoauto-seo-helper' ),
			),
			'audit'    => array(
				'slug'  => Admin_Menu::SLUG_AUDIT,
				'label' => __( 'SEO Audit', 'seoauto-seo-helper' ),
			),
			'jobs'     => array(
				'slug'  => Admin_Menu::SLUG_JOBS,
				'label' => __( 'Công việc quét', 'seoauto-seo-helper' ),
			),
			'logs'     => array(
				'slug'  => Admin_Menu::SLUG_LOGS,
				'label' => __( 'Nhật ký', 'seoauto-seo-helper' ),
			),
		);

		if ( $entitlement instanceof Entitlement_Manager
			&& $entitlement->has_feature( ContentOps_Service::FEATURE ) ) {
			$pages = array_merge(
				array_slice( $pages, 0, 2, true ),
				array(
					'content_ops' => array(
						'slug'  => Admin_Menu::SLUG_CONTENT_OPS,
						'label' => __( 'Sửa SEO & Khôi phục', 'seoauto-seo-helper' ),
					),
				),
				array_slice( $pages, 2, null, true )
			);
		}

		echo '<nav class="nav-tab-wrapper seoauto-helper-tabs">';
		foreach ( $pages as $key => $page ) {
			$class = $key === $active ? 'nav-tab nav-tab-active' : 'nav-tab';
			echo '<a class="' . esc_attr( $class ) . '" href="' . esc_url( admin_url( 'admin.php?page=' . $page['slug'] ) ) . '">';
			echo esc_html( $page['label'] ) . '</a>';
		}
		echo '</nav>';
	}
}
