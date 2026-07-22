<?php
/**
 * Admin — background jobs list (cancel / resume).
 *
 * @package SEOAuto\SEOHelper
 */

declare(strict_types=1);

namespace SEOAuto\SEOHelper\Admin;

use SEOAuto\SEOHelper\Entitlement\Entitlement_Manager;
use SEOAuto\SEOHelper\SeoAudit\Audit_Job_Runner;

final class Jobs_Page {

	public function __construct(
		private Audit_Job_Runner $runner,
		private Entitlement_Manager $entitlement
	) {}

	public function render(): void {
		Admin_View::wrap_start(
			__( 'Jobs', 'seoauto-seo-helper' ),
			__( 'Hàng đợi WP-Cron (audit scan). Có thể hủy hoặc tiếp tục.', 'seoauto-seo-helper' )
		);
		Admin_View::nav_tabs( 'jobs' );

		$caps = $this->entitlement->capabilities();
		Admin_View::render_status_notices(
			$this->entitlement->is_locked(),
			(bool) ( $caps['network_grace_active'] ?? false ),
			$caps,
			(string) ( $caps['upgrade_url'] ?? '' )
		);

		$jobs = $this->runner->jobs()->recent( 30 );
		echo '<div class="seoauto-helper-card">';
		if ( $jobs === array() ) {
			echo '<p>' . esc_html__( 'Chưa có job.', 'seoauto-seo-helper' ) . '</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr>';
			echo '<th>ID</th><th>' . esc_html__( 'Type', 'seoauto-seo-helper' ) . '</th>';
			echo '<th>' . esc_html__( 'Status', 'seoauto-seo-helper' ) . '</th>';
			echo '<th>Run</th><th>' . esc_html__( 'Cursor', 'seoauto-seo-helper' ) . '</th>';
			echo '<th>' . esc_html__( 'Attempts', 'seoauto-seo-helper' ) . '</th>';
			echo '<th>' . esc_html__( 'Thao tác', 'seoauto-seo-helper' ) . '</th>';
			echo '</tr></thead><tbody>';
			foreach ( $jobs as $job ) {
				echo '<tr>';
				echo '<td>' . (int) $job['id'] . '</td>';
				echo '<td>' . esc_html( (string) $job['job_type'] ) . '</td>';
				echo '<td>' . esc_html( (string) $job['status'] ) . '</td>';
				echo '<td>' . (int) $job['run_id'] . '</td>';
				echo '<td>' . (int) $job['cursor_id'] . '</td>';
				echo '<td>' . (int) $job['attempts'] . '/' . (int) $job['max_attempts'] . '</td>';
				echo '<td>';
				if ( ! in_array( $job['status'], array( 'completed', 'cancelled' ), true ) ) {
					echo '<form method="post" style="display:inline">';
					wp_nonce_field( 'seoauto_helper_admin' );
					echo '<input type="hidden" name="seoauto_helper_action" value="audit_cancel_job" />';
					echo '<input type="hidden" name="job_id" value="' . (int) $job['id'] . '" />';
					submit_button( __( 'Hủy', 'seoauto-seo-helper' ), 'small', 'submit', false );
					echo '</form> ';
				}
				if ( in_array( $job['status'], array( 'cancelled', 'retrying', 'failed', 'paused' ), true )
					|| $this->is_run_paused( (int) $job['run_id'] )
				) {
					echo '<form method="post" style="display:inline">';
					wp_nonce_field( 'seoauto_helper_admin' );
					echo '<input type="hidden" name="seoauto_helper_action" value="audit_resume_job" />';
					echo '<input type="hidden" name="job_id" value="' . (int) $job['id'] . '" />';
					submit_button( __( 'Tiếp tục', 'seoauto-seo-helper' ), 'small', 'submit', false );
					echo '</form>';
				}
				echo '</td></tr>';
			}
			echo '</tbody></table>';
		}
		echo '</div>';

		echo '<p class="description">' . esc_html__( 'Hook cron:', 'seoauto-seo-helper' ) . ' <code>';
		echo esc_html( Audit_Job_Runner::HOOK_PROCESS );
		echo '</code></p>';

		Admin_View::wrap_end();
	}

	private function is_run_paused( int $run_id ): bool {
		if ( $run_id <= 0 ) {
			return false;
		}
		$run = $this->runner->runs()->get( $run_id );
		return null !== $run && ( $run['status'] ?? '' ) === 'paused';
	}
}
