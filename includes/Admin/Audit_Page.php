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
use SEOAuto\SEOHelper\SeoAudit\Object_Context;

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

		if ( $this->entitlement->is_locked() ) {
			echo '<p class="description">' . esc_html__( 'LOCKED — không thể tạo scan mới.', 'seoauto-seo-helper' ) . '</p>';
		} else {
			echo '<form method="post">';
			wp_nonce_field( 'seoauto_helper_admin' );
			echo '<input type="hidden" name="seoauto_helper_action" value="audit_start_scan" />';
			submit_button( __( 'Xếp hàng quét SEO', 'seoauto-seo-helper' ), 'primary', 'submit', false );
			echo '</form>';
		}
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
