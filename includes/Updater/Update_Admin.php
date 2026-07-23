<?php
/**
 * Admin UI for private updates — check now, upgrade now, friendly notices.
 *
 * Does not change Update_Manager verification logic.
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
		add_action( 'admin_init', array( $this, 'handle_upgrade_now' ) );
		add_filter( 'plugin_row_meta', array( $this, 'row_meta' ), 10, 2 );
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
			add_settings_error(
				'seoauto_helper',
				'update_check_fail',
				__( 'Không kiểm tra được cập nhật ngay lúc này. Vui lòng thử lại sau.', 'seoauto-seo-helper' ),
				'error'
			);
			return;
		}
		if ( $result->update_available ) {
			add_settings_error(
				'seoauto_helper',
				'update_available',
				sprintf(
					/* translators: %s: new version */
					__( 'Có phiên bản mới (%s). Bấm “Nâng cấp ngay” bên dưới hoặc cập nhật tại trang Plugins.', 'seoauto-seo-helper' ),
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

	public function handle_upgrade_now(): void {
		if ( ! is_admin() || ! current_user_can( 'update_plugins' ) ) {
			return;
		}
		if ( empty( $_POST['seoauto_helper_action'] ) || 'upgrade_plugin_now' !== $_POST['seoauto_helper_action'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}
		check_admin_referer( 'seoauto_helper_admin' );

		$result = $this->manager->force_check();
		if ( is_wp_error( $result ) ) {
			add_settings_error(
				'seoauto_helper',
				'upgrade_fail',
				__( 'Không nâng cấp được. Vui lòng thử lại hoặc cập nhật tại trang Plugins.', 'seoauto-seo-helper' ),
				'error'
			);
			return;
		}
		if ( ! $result->update_available || $result->package === '' ) {
			add_settings_error(
				'seoauto_helper',
				'upgrade_none',
				__( 'Không có bản mới để nâng cấp.', 'seoauto-seo-helper' ),
				'updated'
			);
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$skin     = new \Automatic_Upgrader_Skin();
		$upgrader = new \Plugin_Upgrader( $skin );
		$done     = $upgrader->run(
			array(
				'package'           => $result->package,
				'destination'       => WP_PLUGIN_DIR . '/seoauto-seo-helper',
				'clear_destination' => true,
				'clear_working'     => true,
				'hook_extra'        => array(
					'plugin' => SEOAUTO_HELPER_BASENAME,
					'type'   => 'plugin',
					'action' => 'update',
				),
			)
		);

		if ( is_wp_error( $done ) || false === $done ) {
			add_settings_error(
				'seoauto_helper',
				'upgrade_fail',
				__( 'Nâng cấp thất bại. Hãy thử lại tại Plugins → Plugin đã cài đặt.', 'seoauto-seo-helper' ),
				'error'
			);
			return;
		}

		activate_plugin( SEOAUTO_HELPER_BASENAME );
		add_settings_error(
			'seoauto_helper',
			'upgrade_ok',
			sprintf(
				/* translators: %s: new version */
				__( 'Đã nâng cấp thành công lên bản %s.', 'seoauto-seo-helper' ),
				$result->version
			),
			'updated'
		);
	}

	public function maybe_notice(): void {
		if ( ! current_user_can( 'update_plugins' ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && ! in_array( $screen->id, array( 'plugins', 'update-core', 'toplevel_page_seoauto-helper', 'seoauto-helper_page_seoauto-helper-connect' ), true ) ) {
			return;
		}

		$cached = $this->manager->read_cache();
		if ( ! $cached instanceof Update_Response || ! $cached->update_available ) {
			return;
		}

		$plugins_url = self_admin_url( 'plugins.php' );
		$helper_url  = admin_url( 'admin.php?page=seoauto-helper' );

		echo '<div class="notice notice-warning is-dismissible"><p>';
		echo '<strong>' . esc_html__( 'Có phiên bản mới của SEOAuto SEO Helper', 'seoauto-seo-helper' ) . '</strong>';
		echo ' — ';
		echo esc_html(
			sprintf(
				/* translators: %s: version */
				__( 'Bản %s đã sẵn sàng.', 'seoauto-seo-helper' ),
				$cached->version
			)
		);
		echo ' <a href="' . esc_url( $helper_url ) . '">' . esc_html__( 'Nâng cấp ngay', 'seoauto-seo-helper' ) . '</a>';
		echo ' · <a href="' . esc_url( $plugins_url ) . '">' . esc_html__( 'Trang Plugins', 'seoauto-seo-helper' ) . '</a>';
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

	public static function render_check_form(): void {
		if ( ! current_user_can( 'update_plugins' ) ) {
			return;
		}
		echo '<form method="post" class="seoauto-helper-inline-form">';
		wp_nonce_field( 'seoauto_helper_admin' );
		echo '<input type="hidden" name="seoauto_helper_action" value="check_plugin_update" />';
		submit_button( __( 'Kiểm tra cập nhật', 'seoauto-seo-helper' ), 'secondary', 'submit', false );
		echo '</form>';
	}

	public static function render_upgrade_form( string $version = '' ): void {
		if ( ! current_user_can( 'update_plugins' ) ) {
			return;
		}
		$label = $version !== ''
			? sprintf(
				/* translators: %s: version */
				__( 'Nâng cấp ngay (%s)', 'seoauto-seo-helper' ),
				$version
			)
			: __( 'Nâng cấp ngay', 'seoauto-seo-helper' );
		echo '<form method="post" class="seoauto-helper-inline-form">';
		wp_nonce_field( 'seoauto_helper_admin' );
		echo '<input type="hidden" name="seoauto_helper_action" value="upgrade_plugin_now" />';
		submit_button( $label, 'primary', 'submit', false );
		echo '</form>';
	}
}
