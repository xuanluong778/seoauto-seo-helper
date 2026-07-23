<?php
/**
 * Sửa SEO & Khôi phục — batch ContentOps (UI thân thiện).
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

		Admin_View::wrap_start(
			__( 'Sửa SEO & Khôi phục', 'seoauto-seo-helper' ),
			__( 'Xem các lần chỉnh sửa SEO từ SEOAuto và khôi phục khi cần.', 'seoauto-seo-helper' )
		);
		Admin_View::nav_tabs( 'content_ops', $this->entitlement );

		if ( ! $this->entitlement->has_feature( ContentOps_Service::FEATURE ) ) {
			echo '<div class="notice notice-warning"><p>';
			echo esc_html__( 'Tính năng này chưa được cấp cho website của bạn. Liên hệ SEOAuto để mở quyền Sửa SEO & Khôi phục.', 'seoauto-seo-helper' );
			echo '</p></div>';
			Admin_View::wrap_end();
			return;
		}

		echo '<div class="seoauto-helper-card seoauto-helper-card-wide">';
		echo '<h2>' . esc_html__( 'Lịch sử chỉnh sửa gần đây', 'seoauto-seo-helper' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Mã', 'seoauto-seo-helper' ) . '</th>';
		echo '<th>' . esc_html__( 'Trạng thái', 'seoauto-seo-helper' ) . '</th>';
		echo '<th>' . esc_html__( 'Số mục', 'seoauto-seo-helper' ) . '</th>';
		echo '<th>' . esc_html__( 'Đã xử lý', 'seoauto-seo-helper' ) . '</th>';
		echo '<th>' . esc_html__( 'Lỗi', 'seoauto-seo-helper' ) . '</th>';
		echo '<th>' . esc_html__( 'Cập nhật', 'seoauto-seo-helper' ) . '</th>';
		echo '<th></th>';
		echo '</tr></thead><tbody>';

		if ( $batches === array() ) {
			echo '<tr><td colspan="7">' . esc_html__( 'Chưa có chỉnh sửa nào.', 'seoauto-seo-helper' ) . '</td></tr>';
		}

		foreach ( $batches as $b ) {
			echo '<tr>';
			echo '<td>' . (int) $b['id'] . '</td>';
			echo '<td>' . esc_html( self::status_label( (string) $b['status'] ) ) . '</td>';
			echo '<td>' . (int) $b['total_items'] . '</td>';
			echo '<td>' . (int) $b['processed_items'] . '</td>';
			echo '<td>' . (int) $b['failed_items'] . '</td>';
			echo '<td>' . esc_html( (string) $b['updated_gmt'] ) . '</td>';
			echo '<td>';
			if ( current_user_can( 'manage_options' ) && in_array( (string) $b['status'], array( 'applied', 'rechecked', 'partial', 'recheck_failed' ), true ) ) {
				echo '<form method="post" style="display:inline" onsubmit="return confirm(\'' . esc_js( __( 'Khôi phục lại nội dung trước khi chỉnh sửa?', 'seoauto-seo-helper' ) ) . '\');">';
				wp_nonce_field( 'seoauto_helper_admin' );
				echo '<input type="hidden" name="seoauto_helper_action" value="content_ops_rollback" />';
				echo '<input type="hidden" name="batch_id" value="' . (int) $b['id'] . '" />';
				submit_button( __( 'Khôi phục', 'seoauto-seo-helper' ), 'secondary small', 'submit', false );
				echo '</form>';
			}
			echo '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__( 'Bản sao lưu được giữ khoảng 30 ngày, sau đó hệ thống tự dọn.', 'seoauto-seo-helper' ) . '</p>';
		echo '</div>';
		Admin_View::wrap_end();
	}

	private static function status_label( string $status ): string {
		$map = array(
			'previewed'      => __( 'Đã xem trước', 'seoauto-seo-helper' ),
			'backed_up'      => __( 'Đã sao lưu', 'seoauto-seo-helper' ),
			'applied'        => __( 'Đã áp dụng', 'seoauto-seo-helper' ),
			'rechecked'      => __( 'Đã kiểm tra lại', 'seoauto-seo-helper' ),
			'partial'        => __( 'Một phần', 'seoauto-seo-helper' ),
			'recheck_failed' => __( 'Kiểm tra lại lỗi', 'seoauto-seo-helper' ),
			'rolled_back'    => __( 'Đã khôi phục', 'seoauto-seo-helper' ),
			'failed'         => __( 'Thất bại', 'seoauto-seo-helper' ),
		);
		return $map[ $status ] ?? $status;
	}
}
