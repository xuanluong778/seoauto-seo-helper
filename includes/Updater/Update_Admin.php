<?php
/**
 * Admin UI for private updates — check now + notice.
 *
 * @package SEOAuto\SEOHelper
 */

declare(strict_types=1);

namespace SEOAuto\SEOHelper\Updater;

final class Update_Admin {

	public function __construct( private Update_Manager $manager ) {}

	public function register(): void {
		add_action( 'admin_notices', array( $this, 'maybe_notice' ) );
		add_action( 'admin_init', array( $this, 'handle_check_now' ) );
		add_filter( 'plugin_row_meta', array( $this, 'row_meta' ), 10, 2 );
		// Core already renders the Plugins update row when update_plugins_* returns an object.
	}

	public function handle_check_now(): void {
		if ( ! is_admin() || ! current_user_can( 'update_plugins' ) ) {
			return;
		}
		if ( empty( $_POST['seoauto_helper_action'] ) || 'check_plugin_update' !== $_POST['seoauto_helper_action'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}
		check_admin_referer( 'seoauto_helper_admin' );

		$result = $this->manager->force_check();
		if ( is_wp_error( $result ) ) {
			add_settings_error( 'seoauto_helper', 'update_check_fail', $result->get_error_message(), 'error' );
			return;
		}
		if ( $result->update_available ) {
			add_settings_error(
				'seoauto_helper',
				'update_available',
				sprintf(
					/* translators: %s: new version */
					__( 'Có phiên bản mới của SEOAuto SEO Helper (%s). Vào Plugins để Cập nhật ngay.', 'seoauto-seo-helper' ),
					$result->version
				),
				'updated'
			);
		} else {
			add_settings_error(
				'seoauto_helper',
				'update_none',
				__( 'Bạn đang dùng bản mới nhất.', 'seoauto-seo-helper' ),
				'updated'
			);
		}
	}

	public function maybe_notice(): void {
		if ( ! current_user_can( 'update_plugins' ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && ! in_array( $screen->id, array( 'plugins', 'toplevel_page_seoauto-helper', 'seoauto-helper_page_seoauto-helper-connect' ), true ) ) {
			return;
		}

		$cached = $this->manager->read_cache();
		if ( ! $cached instanceof Update_Response || ! $cached->update_available ) {
			return;
		}

		$plugins_url = self_admin_url( 'plugins.php' );
		$details     = $cached->changelog_url !== '' ? $cached->changelog_url : $plugins_url;

		echo '<div class="notice notice-warning is-dismissible"><p>';
		echo '<strong>' . esc_html__( 'Có phiên bản mới của SEOAuto SEO Helper', 'seoauto-seo-helper' ) . '</strong>';
		echo ' — ';
		echo '<a href="' . esc_url( $details ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Xem chi tiết', 'seoauto-seo-helper' ) . '</a>';
		echo ' – ';
		echo '<a href="' . esc_url( $plugins_url ) . '">' . esc_html__( 'Cập nhật ngay', 'seoauto-seo-helper' ) . '</a>';
		echo ' <span class="description">(' . esc_html( $cached->version ) . ')</span>';
		echo '</p></div>';
	}

	/**
	 * @param list<string> $links
	 * @return list<string>
	 */
	public function row_meta( array $links, string $file ): array {
		if ( $file !== SEOAUTO_HELPER_BASENAME ) {
			return $links;
		}
		$links[] = '<a href="' . esc_url( admin_url( 'admin.php?page=seoauto-helper' ) ) . '">' . esc_html__( 'Tổng quan', 'seoauto-seo-helper' ) . '</a>';
		return $links;
	}

	/**
	 * Render check-now form (for Overview / Connect pages).
	 */
	public static function render_check_form(): void {
		if ( ! current_user_can( 'update_plugins' ) ) {
			return;
		}
		echo '<form method="post" style="display:inline-block;margin-top:8px">';
		wp_nonce_field( 'seoauto_helper_admin' );
		echo '<input type="hidden" name="seoauto_helper_action" value="check_plugin_update" />';
		submit_button( __( 'Kiểm tra cập nhật', 'seoauto-seo-helper' ), 'secondary', 'submit', false );
		echo '</form>';
	}
}
