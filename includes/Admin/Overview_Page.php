<?php
/**
 * Tổng quan — dashboard trạng thái site.
 *
 * @package SEOAuto\SEOHelper
 */

declare(strict_types=1);

namespace SEOAuto\SEOHelper\Admin;

use SEOAuto\SEOHelper\Audit\Audit_Logger;
use SEOAuto\SEOHelper\Connection\Connection_Manager;
use SEOAuto\SEOHelper\Entitlement\Entitlement_Manager;

final class Overview_Page {

	public function __construct(
		private Connection_Manager $connection,
		private Entitlement_Manager $entitlement,
		private Audit_Logger $audit,
		private Site_Info $site_info
	) {}

	public function render(): void {
		$snap          = $this->connection->get_snapshot();
		$caps          = $this->entitlement->capabilities();
		$connected     = $this->connection->is_connected();
		$locked        = $this->entitlement->is_locked();
		$network_grace = ! empty( $caps['network_grace_active'] );
		$upgrade       = (string) ( $caps['upgrade_url'] ?? $this->entitlement->upgrade_url() );
		$wf            = $this->site_info->wordfence();
		$lsc           = $this->site_info->litespeed_cache();
		$published     = $this->site_info->published_post_count();
		$latest_error  = $this->audit->latest_error();
		$features      = is_array( $caps['enabled_features'] ?? null ) ? $caps['enabled_features'] : array();
		$expires       = (string) ( $caps['expires_at'] ?? '' );

		Admin_View::wrap_start(
			__( 'SEOAuto Helper → Tổng quan', 'seoauto-seo-helper' ),
			__( 'Trạng thái kết nối, gói dịch vụ và hoạt động đăng bài từ SEOAuto.', 'seoauto-seo-helper' )
		);
		Admin_View::nav_tabs( 'overview' );
		Admin_View::render_status_notices( $locked, $network_grace, $caps, $upgrade );
		?>
		<div class="seoauto-helper-dashboard">
			<div class="seoauto-helper-stat-grid">
				<div class="seoauto-helper-stat-card">
					<span class="seoauto-helper-stat-label"><?php echo esc_html__( 'Kết nối', 'seoauto-seo-helper' ); ?></span>
					<div class="seoauto-helper-stat-value">
						<?php Admin_View::status_badge( $network_grace, $locked, $connected ); ?>
					</div>
				</div>
				<div class="seoauto-helper-stat-card">
					<span class="seoauto-helper-stat-label"><?php echo esc_html__( 'Bài đã đăng', 'seoauto-seo-helper' ); ?></span>
					<strong class="seoauto-helper-stat-number"><?php echo esc_html( (string) $published ); ?></strong>
				</div>
				<div class="seoauto-helper-stat-card">
					<span class="seoauto-helper-stat-label"><?php echo esc_html__( 'Gói', 'seoauto-seo-helper' ); ?></span>
					<strong class="seoauto-helper-stat-number"><?php echo esc_html( (string) ( $caps['plan_code'] ?? '—' ) ); ?></strong>
				</div>
				<div class="seoauto-helper-stat-card">
					<span class="seoauto-helper-stat-label"><?php echo esc_html__( 'Hết hạn gói', 'seoauto-seo-helper' ); ?></span>
					<strong class="seoauto-helper-stat-text"><?php echo esc_html( $this->site_info->format_iso_local( $expires ) ); ?></strong>
				</div>
			</div>

			<div class="seoauto-helper-card seoauto-helper-card-wide">
				<h2><?php echo esc_html__( 'Chi tiết', 'seoauto-seo-helper' ); ?></h2>
				<table class="widefat striped seoauto-helper-info-table">
					<tbody>
						<tr>
							<th><?php echo esc_html__( 'Trạng thái kết nối', 'seoauto-seo-helper' ); ?></th>
							<td><?php Admin_View::status_badge( $network_grace, $locked, $connected ); ?></td>
						</tr>
						<tr>
							<th><?php echo esc_html__( 'Gói / subscription', 'seoauto-seo-helper' ); ?></th>
							<td>
								<code><?php echo esc_html( (string) ( $caps['plan_code'] ?? '—' ) ); ?></code>
								<?php if ( ! empty( $caps['subscription_status'] ) ) : ?>
									— <?php echo esc_html( (string) $caps['subscription_status'] ); ?>
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<th><?php echo esc_html__( 'Ngày hết hạn', 'seoauto-seo-helper' ); ?></th>
							<td><?php echo esc_html( $this->site_info->format_iso_local( $expires ) ); ?></td>
						</tr>
						<tr>
							<th><?php echo esc_html__( 'Enabled features', 'seoauto-seo-helper' ); ?></th>
							<td>
								<?php if ( $features === array() ) : ?>
									<em>—</em>
								<?php else : ?>
									<?php foreach ( $features as $feature ) : ?>
										<span class="seoauto-helper-feature-pill"><?php echo esc_html( (string) $feature ); ?></span>
									<?php endforeach; ?>
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<th><?php echo esc_html__( 'Plugin SEO', 'seoauto-seo-helper' ); ?></th>
							<td><?php echo esc_html( $this->site_info->seo_plugin_label() ); ?></td>
						</tr>
						<tr>
							<th><?php echo esc_html__( 'Wordfence', 'seoauto-seo-helper' ); ?></th>
							<td>
								<span class="seoauto-helper-badge <?php echo ! empty( $wf['active'] ) ? 'is-ok' : 'is-off'; ?>">
									<?php echo esc_html( (string) $wf['label'] ); ?>
								</span>
							</td>
						</tr>
						<tr>
							<th><?php echo esc_html__( 'LiteSpeed Cache', 'seoauto-seo-helper' ); ?></th>
							<td>
								<span class="seoauto-helper-badge <?php echo ! empty( $lsc['active'] ) ? 'is-ok' : 'is-off'; ?>">
									<?php echo esc_html( (string) $lsc['label'] ); ?>
								</span>
							</td>
						</tr>
						<tr>
							<th><?php echo esc_html__( 'Lần kết nối gần nhất', 'seoauto-seo-helper' ); ?></th>
							<td>
								<?php
								echo esc_html( $this->site_info->format_iso_local( (string) ( $snap['last_check_at'] ?? '' ) ) );
								if ( ! empty( $snap['last_check_message'] ) ) {
									echo '<br /><span class="description">' . esc_html( (string) $snap['last_check_message'] ) . '</span>';
								}
								?>
							</td>
						</tr>
						<tr>
							<th><?php echo esc_html__( 'Bài đã đăng từ SEOAuto', 'seoauto-seo-helper' ); ?></th>
							<td><strong><?php echo esc_html( (string) $published ); ?></strong></td>
						</tr>
						<tr>
							<th><?php echo esc_html__( 'Lỗi gần nhất', 'seoauto-seo-helper' ); ?></th>
							<td>
								<?php if ( null === $latest_error && (string) ( $snap['last_error'] ?? '' ) === '' ) : ?>
									<span class="seoauto-helper-badge is-ok"><?php echo esc_html__( 'Không có', 'seoauto-seo-helper' ); ?></span>
								<?php else : ?>
									<?php
									$code = $latest_error['error_code'] ?? '';
									$msg  = $latest_error['message'] ?? (string) ( $snap['last_error'] ?? '' );
									$at   = $latest_error['at'] ?? '';
									if ( $code !== '' ) {
										echo '<code>' . esc_html( $code ) . '</code> ';
									}
									echo esc_html( $msg );
									if ( $at !== '' ) {
										echo '<br /><span class="description">' . esc_html( $this->site_info->format_iso_local( $at ) ) . '</span>';
									}
									?>
								<?php endif; ?>
							</td>
						</tr>
					</tbody>
				</table>
				<p class="seoauto-helper-actions">
					<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=' . Admin_Menu::SLUG_CONNECT ) ); ?>">
						<?php echo esc_html__( 'Quản lý kết nối', 'seoauto-seo-helper' ); ?>
					</a>
					<a class="button button-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=' . Admin_Menu::SLUG_LOGS ) ); ?>">
						<?php echo esc_html__( 'Xem nhật ký', 'seoauto-seo-helper' ); ?>
					</a>
					<?php \SEOAuto\SEOHelper\Updater\Update_Admin::render_check_form(); ?>
				</p>
			</div>
		</div>
		<?php
		Admin_View::wrap_end();
	}
}
