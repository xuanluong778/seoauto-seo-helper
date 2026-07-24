<?php
/**
 * Admin — SEO Audit runs & issues (Phase 1 scan-only).
 *
 * @package SEOAuto\SEOHelper
 */

declare(strict_types=1);

namespace SEOAuto\SEOHelper\Admin;

use SEOAuto\SEOHelper\Entitlement\Entitlement_Manager;
use SEOAuto\SEOHelper\SeoAudit\Audit_Job_Runner;
use SEOAuto\SEOHelper\SeoAudit\Job_Store;
use SEOAuto\SEOHelper\SeoAudit\Object_Context;
use WP_Error;

final class Audit_Page {

	public function __construct(
		private Audit_Job_Runner $runner,
		private Entitlement_Manager $entitlement
	) {}

	public function render(): void {
		Admin_View::wrap_start(
			__( 'SEO Audit', 'seoauto-seo-helper' ),
			__( 'Quét SEO hàng loạt (WP-Cron). Phase 1: chỉ quét — chưa Auto Fix.', 'seoauto-seo-helper' )
		);
		Admin_View::nav_tabs( 'audit', $this->entitlement );

		$locked = $this->entitlement->is_locked();
		$caps   = $this->entitlement->capabilities();
		Admin_View::render_status_notices(
			$locked,
			(bool) ( $caps['network_grace_active'] ?? false ),
			$caps,
			(string) ( $caps['upgrade_url'] ?? '' )
		);

		$run_id = isset( $_GET['run_id'] ) ? absint( $_GET['run_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $run_id > 0 ) {
			$this->render_run_detail( $run_id );
		} else {
			$this->render_list();
		}

		Admin_View::wrap_end();
	}

	private function render_list(): void {
		$types = Object_Context::audit_post_types();
		echo '<div class="seoauto-helper-card">';
		echo '<h2>' . esc_html__( 'Bắt đầu quét', 'seoauto-seo-helper' ) . '</h2>';
		echo '<p>' . esc_html(
			sprintf(
				/* translators: %s: post types */
				__( 'Đối tượng: %s. Batch 20 / phút qua WP-Cron.', 'seoauto-seo-helper' ),
				implode( ', ', $types )
			)
		) . '</p>';
		echo '<p><strong>' . esc_html__( 'Checkers:', 'seoauto-seo-helper' ) . '</strong> ';
		echo esc_html( implode( ', ', $this->runner->engine()->checker_ids() ) );
		echo '</p>';

		$this->render_active_job_notice();
		$this->render_start_controls();
		echo '</div>';

		$runs = $this->runner->runs()->recent( 15 );
		echo '<div class="seoauto-helper-card">';
		echo '<h2>' . esc_html__( 'Lần quét gần đây', 'seoauto-seo-helper' ) . '</h2>';
		if ( $runs === array() ) {
			echo '<p>' . esc_html__( 'Chưa có lần quét nào.', 'seoauto-seo-helper' ) . '</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr>';
			echo '<th>ID</th><th>' . esc_html__( 'Trạng thái', 'seoauto-seo-helper' ) . '</th>';
			echo '<th>' . esc_html__( 'Tiến độ', 'seoauto-seo-helper' ) . '</th>';
			echo '<th>' . esc_html__( 'Issues', 'seoauto-seo-helper' ) . '</th>';
			echo '<th>' . esc_html__( 'Adapter', 'seoauto-seo-helper' ) . '</th>';
			echo '<th></th></tr></thead><tbody>';
			foreach ( $runs as $run ) {
				$url = admin_url( 'admin.php?page=' . Admin_Menu::SLUG_AUDIT . '&run_id=' . (int) $run['id'] );
				echo '<tr>';
				echo '<td>' . (int) $run['id'] . '</td>';
				echo '<td>' . esc_html( (string) $run['status'] ) . '</td>';
				echo '<td>' . (int) $run['processed_objects'] . ' / ' . (int) $run['total_objects'] . '</td>';
				echo '<td>' . (int) $run['issues_found'] . '</td>';
				echo '<td>' . esc_html( (string) ( $run['seo_adapter'] ?? '' ) ) . '</td>';
				echo '<td><a href="' . esc_url( $url ) . '">' . esc_html__( 'Chi tiết', 'seoauto-seo-helper' ) . '</a></td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}
		echo '</div>';
	}

	private function render_start_controls(): void {
		$has_feature = $this->entitlement->has_feature( Audit_Job_Runner::FEATURE );
		$gate        = $this->runner->can_start_scan();
		$can_start   = $has_feature && true === $gate;

		if ( $can_start ) {
			echo '<form method="post">';
			wp_nonce_field( 'seoauto_helper_admin' );
			echo '<input type="hidden" name="seoauto_helper_action" value="audit_start_scan" />';
			submit_button( __( 'Xếp hàng quét SEO', 'seoauto-seo-helper' ), 'primary', 'submit', false );
			echo '</form>';
			return;
		}

		$reason = $this->deny_reason( $has_feature, $gate );
		echo '<div class="notice notice-warning inline" style="margin:12px 0;padding:8px 12px;">';
		echo '<p><strong>' . esc_html__( 'Không thể bắt đầu quét SEO.', 'seoauto-seo-helper' ) . '</strong></p>';
		echo '<p>' . esc_html( $reason ) . '</p>';
		echo '</div>';
		echo '<p>';
		submit_button(
			__( 'Xếp hàng quét SEO', 'seoauto-seo-helper' ),
			'primary',
			'submit',
			false,
			array(
				'disabled' => 'disabled',
				'id'       => 'seoauto-audit-start-disabled',
			)
		);
		echo '</p>';
	}

	/**
	 * @param bool|WP_Error $gate
	 */
	private function deny_reason( bool $has_feature, bool|WP_Error $gate ): string {
		if ( ! $has_feature ) {
			return __(
				'Gói hiện tại chưa được cấp quyền seo_audit. Nâng cấp gói hoặc liên hệ SEOAuto để kích hoạt SEO Audit (mã: seoauto_feature_denied).',
				'seoauto-seo-helper'
			);
		}
		if ( $gate instanceof WP_Error ) {
			$code = $gate->get_error_code();
			$msg  = $gate->get_error_message();
			if ( $code !== '' && ! str_contains( $msg, $code ) ) {
				return $msg . ' (' . $code . ')';
			}
			return $msg;
		}
		return __( 'Không thể bắt đầu quét lúc này.', 'seoauto-seo-helper' );
	}

	private function render_active_job_notice(): void {
		$active = $this->runner->jobs()->find_active( Job_Store::TYPE_AUDIT_SCAN );
		if ( null === $active ) {
			return;
		}

		$cron   = $this->runner->cron_status();
		$run_id = (int) ( $active['run_id'] ?? 0 );
		$run    = $run_id > 0 ? $this->runner->runs()->get( $run_id ) : null;
		$status = (string) ( $active['status'] ?? '' );

		echo '<div class="notice notice-info inline" style="margin:12px 0;padding:8px 12px;">';
		echo '<p><strong>' . esc_html__( 'Đang có job quét SEO', 'seoauto-seo-helper' ) . '</strong></p>';
		echo '<p>' . esc_html(
			sprintf(
				/* translators: 1: job id 2: status 3: run id */
				__( 'Job #%1$d — trạng thái: %2$s (Run #%3$d). Làm mới trang để cập nhật tiến độ.', 'seoauto-seo-helper' ),
				(int) $active['id'],
				$status,
				$run_id
			)
		) . '</p>';
		if ( null !== $run ) {
			echo '<p>' . esc_html(
				sprintf(
					/* translators: 1: processed 2: total */
					__( 'Tiến độ: %1$d / %2$d đối tượng.', 'seoauto-seo-helper' ),
					(int) $run['processed_objects'],
					(int) $run['total_objects']
				)
			) . '</p>';
		}
		if ( in_array( $status, array( Job_Store::STATUS_QUEUED, Job_Store::STATUS_RETRYING ), true ) ) {
			echo '<p>' . esc_html__(
				'Job đang chờ WP-Cron. Nếu tiến độ không đổi sau vài phút, kiểm tra DISABLE_WP_CRON, system cron, hoặc vào tab Công việc quét.',
				'seoauto-seo-helper'
			) . '</p>';
		}
		if ( ! empty( $cron['wp_cron_disabled'] ) ) {
			echo '<p>' . esc_html__(
				'Cảnh báo: DISABLE_WP_CRON đang bật — cần system cron gọi wp-cron.php, nếu không job sẽ đứng ở trạng thái chờ.',
				'seoauto-seo-helper'
			) . '</p>';
		} elseif ( empty( $cron['scheduled'] ) ) {
			echo '<p>' . esc_html__(
				'Hook cron audit chưa được lên lịch. Thử xếp hàng lại hoặc kích hoạt lại plugin để đăng ký cron.',
				'seoauto-seo-helper'
			) . '</p>';
		} else {
			echo '<p class="description">' . esc_html(
				sprintf(
					/* translators: %s: cron hook name */
					__( 'Cron hook: %s (mỗi phút).', 'seoauto-seo-helper' ),
					(string) $cron['hook']
				)
			) . '</p>';
		}
		echo '</div>';
	}

	private function render_run_detail( int $run_id ): void {
		$run = $this->runner->runs()->get( $run_id );
		if ( null === $run ) {
			echo '<p>' . esc_html__( 'Run không tồn tại.', 'seoauto-seo-helper' ) . '</p>';
			return;
		}

		echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=' . Admin_Menu::SLUG_AUDIT ) ) . '">&larr; ';
		echo esc_html__( 'Tất cả lần quét', 'seoauto-seo-helper' ) . '</a></p>';

		echo '<div class="seoauto-helper-card">';
		echo '<h2>' . esc_html( sprintf( /* translators: %d: run id */ __( 'Run #%d', 'seoauto-seo-helper' ), $run_id ) ) . '</h2>';
		echo '<ul>';
		echo '<li><strong>status:</strong> ' . esc_html( (string) $run['status'] ) . '</li>';
		echo '<li><strong>progress:</strong> ' . (int) $run['processed_objects'] . ' / ' . (int) $run['total_objects'] . '</li>';
		echo '<li><strong>cursor:</strong> ' . (int) $run['cursor_id'] . '</li>';
		echo '<li><strong>issues:</strong> ' . (int) $run['issues_found'] . '</li>';
		echo '<li><strong>adapter:</strong> ' . esc_html( (string) ( $run['seo_adapter'] ?? '' ) ) . '</li>';
		echo '<li><strong>request_id:</strong> <code>' . esc_html( (string) $run['request_id'] ) . '</code></li>';
		echo '</ul>';
		echo '</div>';

		$issues = $this->runner->issues()->query(
			array(
				'run_id' => $run_id,
				'limit'  => 100,
			)
		);
		echo '<div class="seoauto-helper-card">';
		echo '<h2>' . esc_html__( 'Issues', 'seoauto-seo-helper' ) . '</h2>';
		if ( $issues === array() ) {
			echo '<p>' . esc_html__( 'Chưa có issue (hoặc scan chưa chạy xong).', 'seoauto-seo-helper' ) . '</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr>';
			echo '<th>' . esc_html__( 'Severity', 'seoauto-seo-helper' ) . '</th>';
			echo '<th>' . esc_html__( 'Code', 'seoauto-seo-helper' ) . '</th>';
			echo '<th>' . esc_html__( 'Object', 'seoauto-seo-helper' ) . '</th>';
			echo '<th>' . esc_html__( 'Current', 'seoauto-seo-helper' ) . '</th>';
			echo '<th>' . esc_html__( 'Suggested', 'seoauto-seo-helper' ) . '</th>';
			echo '<th>' . esc_html__( 'Risk', 'seoauto-seo-helper' ) . '</th>';
			echo '<th>' . esc_html__( 'Status', 'seoauto-seo-helper' ) . '</th>';
			echo '</tr></thead><tbody>';
			foreach ( $issues as $issue ) {
				echo '<tr>';
				echo '<td>' . esc_html( (string) $issue['severity'] ) . '</td>';
				echo '<td><code>' . esc_html( (string) $issue['issue_code'] ) . '</code></td>';
				echo '<td>' . esc_html( (string) $issue['object_type'] . '#' . (int) $issue['object_id'] ) . '</td>';
				echo '<td>' . esc_html( mb_substr( (string) $issue['current_value'], 0, 80 ) ) . '</td>';
				echo '<td>' . esc_html( mb_substr( (string) $issue['suggested_value'], 0, 80 ) ) . '</td>';
				echo '<td>' . esc_html( (string) $issue['risk_level'] ) . '</td>';
				echo '<td>' . esc_html( (string) $issue['status'] ) . '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}
		echo '</div>';
	}
}
