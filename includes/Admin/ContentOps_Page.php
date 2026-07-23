<?php
/**
 * Admin page: ContentOps batches (read + limited rollback).
 *
 * @package SEOAuto\SEOHelper
 */

declare(strict_types=1);

namespace SEOAuto\SEOHelper\Admin;

use SEOAuto\SEOHelper\ContentOps\ContentOps_Service;
use SEOAuto\SEOHelper\Entitlement\Entitlement_Manager;

final class ContentOps_Page {

	public function __construct(
		private ContentOps_Service $ops,
		private Entitlement_Manager $entitlement
	) {}

	public function render(): void {
		$batches = $this->ops->recent_batches( 25 );

		Admin_View::wrap_start( __( 'ContentOps', 'seoauto-seo-helper' ) );
		settings_errors( 'seoauto_helper' );

		echo '<p>' . esc_html__( 'Luồng Phase 2: Preview → Backup → Apply → Recheck → Rollback. Preview không ghi dữ liệu; backup thất bại chặn Apply.', 'seoauto-seo-helper' ) . '</p>';

		if ( ! $this->entitlement->has_feature( ContentOps_Service::FEATURE ) ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Feature content_ops chưa được cấp trong entitlement SaaS.', 'seoauto-seo-helper' ) . '</p></div>';
		}

		echo '<h2>' . esc_html__( 'Batch gần đây', 'seoauto-seo-helper' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>ID</th><th>request_id</th><th>status</th><th>items</th><th>processed</th><th>failed</th><th>updated</th><th></th>';
		echo '</tr></thead><tbody>';

		if ( $batches === array() ) {
			echo '<tr><td colspan="8">' . esc_html__( 'Chưa có batch.', 'seoauto-seo-helper' ) . '</td></tr>';
		}

		foreach ( $batches as $b ) {
			echo '<tr>';
			echo '<td>' . (int) $b['id'] . '</td>';
			echo '<td><code>' . esc_html( (string) $b['request_id'] ) . '</code></td>';
			echo '<td>' . esc_html( (string) $b['status'] ) . '</td>';
			echo '<td>' . (int) $b['total_items'] . '</td>';
			echo '<td>' . (int) $b['processed_items'] . '</td>';
			echo '<td>' . (int) $b['failed_items'] . '</td>';
			echo '<td>' . esc_html( (string) $b['updated_gmt'] ) . '</td>';
			echo '<td>';
			if ( current_user_can( 'manage_options' ) && in_array( (string) $b['status'], array( 'applied', 'rechecked', 'partial', 'recheck_failed' ), true ) ) {
				echo '<form method="post" style="display:inline">';
				wp_nonce_field( 'seoauto_helper_admin' );
				echo '<input type="hidden" name="seoauto_helper_action" value="content_ops_rollback" />';
				echo '<input type="hidden" name="batch_id" value="' . (int) $b['id'] . '" />';
				submit_button( __( 'Rollback batch', 'seoauto-seo-helper' ), 'secondary small', 'submit', false );
				echo '</form>';
			}
			echo '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';

		echo '<p class="description">' . esc_html__( 'Retention backup mặc định 30 ngày. Cron dọn dữ liệu hết hạn hàng ngày.', 'seoauto-seo-helper' ) . '</p>';
		Admin_View::wrap_end();
	}
}
