<?php
/**
 * Nhật ký — audit log an toàn, không lộ secret.
 *
 * @package SEOAuto\SEOHelper
 */

declare(strict_types=1);

namespace SEOAuto\SEOHelper\Admin;

use SEOAuto\SEOHelper\Audit\Audit_Logger;
use SEOAuto\SEOHelper\Entitlement\Entitlement_Manager;

final class Logs_Page {

	public function __construct(
		private Audit_Logger $audit,
		private ?Entitlement_Manager $entitlement = null
	) {}

	public function render(): void {
		$retention = $this->audit->retention_days();
		$logs      = $this->audit->all_for_display( 200 );

		Admin_View::wrap_start(
			__( 'Nhật ký', 'seoauto-seo-helper' ),
			__( 'Lịch sử hoạt động an toàn — không hiển thị mật khẩu hay thông tin nhạy cảm.', 'seoauto-seo-helper' )
		);
		Admin_View::nav_tabs( 'logs', $this->entitlement );
		?>
		<div class="seoauto-helper-card seoauto-helper-card-wide">
			<h2><?php echo esc_html__( 'Giữ nhật ký', 'seoauto-seo-helper' ); ?></h2>
			<form method="post" class="seoauto-helper-retention-form">
				<?php wp_nonce_field( 'seoauto_helper_admin' ); ?>
				<input type="hidden" name="seoauto_helper_action" value="save_log_retention" />
				<label for="audit_log_retention_days">
					<?php echo esc_html__( 'Tự xóa mục cũ hơn', 'seoauto-seo-helper' ); ?>
				</label>
				<select name="audit_log_retention_days" id="audit_log_retention_days">
					<option value="<?php echo esc_attr( (string) Audit_Logger::RETENTION_30 ); ?>" <?php selected( $retention, Audit_Logger::RETENTION_30 ); ?>>
						<?php echo esc_html__( '30 ngày', 'seoauto-seo-helper' ); ?>
					</option>
					<option value="<?php echo esc_attr( (string) Audit_Logger::RETENTION_90 ); ?>" <?php selected( $retention, Audit_Logger::RETENTION_90 ); ?>>
						<?php echo esc_html__( '90 ngày', 'seoauto-seo-helper' ); ?>
					</option>
				</select>
				<?php submit_button( __( 'Lưu', 'seoauto-seo-helper' ), 'secondary', 'submit', false ); ?>
			</form>
		</div>

		<div class="seoauto-helper-card seoauto-helper-card-wide">
			<h2><?php echo esc_html__( 'Nhật ký hoạt động', 'seoauto-seo-helper' ); ?></h2>
			<?php if ( $logs === array() ) : ?>
				<p><em><?php echo esc_html__( 'Chưa có mục nào.', 'seoauto-seo-helper' ); ?></em></p>
			<?php else : ?>
				<table class="widefat striped seoauto-helper-log-table">
					<thead>
						<tr>
							<th><?php echo esc_html__( 'Thời gian (UTC)', 'seoauto-seo-helper' ); ?></th>
							<th><?php echo esc_html__( 'request_id', 'seoauto-seo-helper' ); ?></th>
							<th><?php echo esc_html__( 'action', 'seoauto-seo-helper' ); ?></th>
							<th><?php echo esc_html__( 'post_id', 'seoauto-seo-helper' ); ?></th>
							<th><?php echo esc_html__( 'status', 'seoauto-seo-helper' ); ?></th>
							<th><?php echo esc_html__( 'error_code', 'seoauto-seo-helper' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $logs as $row ) : ?>
							<tr>
								<td><code><?php echo esc_html( (string) ( $row['at'] ?? '' ) ); ?></code></td>
								<td>
									<?php
									$rid = (string) ( $row['request_id'] ?? '' );
									echo $rid !== '' ? '<code>' . esc_html( $rid ) . '</code>' : '—';
									?>
								</td>
								<td><?php echo esc_html( (string) ( $row['action'] ?? '' ) ); ?></td>
								<td>
									<?php
									$post_id = (int) ( $row['post_id'] ?? 0 );
									if ( $post_id > 0 ) {
										echo '<a href="' . esc_url( get_edit_post_link( $post_id, 'raw' ) ?: admin_url( 'post.php?post=' . $post_id . '&action=edit' ) ) . '">';
										echo esc_html( (string) $post_id ) . '</a>';
									} else {
										echo '—';
									}
									?>
								</td>
								<td>
									<?php
									$status = (string) ( $row['status'] ?? '' );
									$class  = $status === 'error' ? 'is-locked' : ( $status === 'ok' ? 'is-ok' : 'is-off' );
									echo '<span class="seoauto-helper-badge ' . esc_attr( $class ) . '">' . esc_html( $status ?: '—' ) . '</span>';
									?>
								</td>
								<td>
									<?php
									$code = (string) ( $row['error_code'] ?? '' );
									echo $code !== '' ? '<code>' . esc_html( $code ) . '</code>' : '—';
									?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
		Admin_View::wrap_end();
	}
}
