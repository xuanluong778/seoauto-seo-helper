<?php
/**
 * Tổng quan — kết nối, phiên bản và cập nhật (giao diện đơn giản).
 *
 * @package SEOAuto\SEOHelper
 */

declare(strict_types=1);

namespace SEOAuto\SEOHelper\Admin;

use SEOAuto\SEOHelper\Connection\Connection_Manager;
use SEOAuto\SEOHelper\Entitlement\Entitlement_Manager;
use SEOAuto\SEOHelper\Updater\Update_Admin;
use SEOAuto\SEOHelper\Updater\Update_Manager;
use SEOAuto\SEOHelper\Updater\Update_Response;

final class Overview_Page {

	public function __construct(
		private Connection_Manager $connection,
		private Entitlement_Manager $entitlement,
		private ?Update_Manager $updater = null
	) {}

	public function render(): void {
		$caps          = $this->entitlement->capabilities();
		$connected     = $this->connection->is_connected();
		$paired        = $this->connection->has_credentials();
		$locked        = $this->entitlement->is_locked();
		$network_grace = ! empty( $caps['network_grace_active'] );
		$upgrade       = (string) ( $caps['upgrade_url'] ?? $this->entitlement->upgrade_url() );
		$cached        = $this->updater instanceof Update_Manager ? $this->updater->read_cache() : null;
		$has_update    = $cached instanceof Update_Response && $cached->update_available && $cached->version !== '';

		Admin_View::wrap_start(
			__( 'SEOAuto Helper', 'seoauto-seo-helper' ),
			__( 'Kết nối website với SEOAuto và cập nhật plugin khi có bản mới.', 'seoauto-seo-helper' )
		);
		Admin_View::nav_tabs( 'overview', $this->entitlement );
		Admin_View::render_status_notices( $locked, $network_grace, $caps, $upgrade );
		?>
		<div class="seoauto-helper-dashboard seoauto-helper-dashboard-simple">
			<div class="seoauto-helper-card seoauto-helper-card-primary">
				<h2><?php echo esc_html__( 'Trạng thái', 'seoauto-seo-helper' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php echo esc_html__( 'Kết nối SEOAuto', 'seoauto-seo-helper' ); ?></th>
						<td>
							<?php Admin_View::status_badge( $network_grace, $locked, $connected ); ?>
							<?php if ( ! $paired ) : ?>
								<p class="description">
									<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . Admin_Menu::SLUG_CONNECT ) ); ?>">
										<?php echo esc_html__( 'Ghép nối website với SEOAuto', 'seoauto-seo-helper' ); ?>
									</a>
								</p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Phiên bản plugin', 'seoauto-seo-helper' ); ?></th>
						<td>
							<strong><?php echo esc_html( SEOAUTO_HELPER_VERSION ); ?></strong>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Trạng thái cập nhật', 'seoauto-seo-helper' ); ?></th>
						<td>
							<?php if ( $has_update ) : ?>
								<span class="seoauto-helper-badge is-warn">
									<?php
									echo esc_html(
										sprintf(
											/* translators: %s: new version */
											__( 'Có bản mới: %s', 'seoauto-seo-helper' ),
											$cached->version
										)
									);
									?>
								</span>
							<?php else : ?>
								<span class="seoauto-helper-badge is-ok">
									<?php echo esc_html__( 'Đang dùng bản mới nhất', 'seoauto-seo-helper' ); ?>
								</span>
							<?php endif; ?>
							<p class="description">
								<?php echo esc_html__( 'Bạn cũng có thể cập nhật tại Plugins → Plugin đã cài đặt hoặc Bảng tin → Cập nhật.', 'seoauto-seo-helper' ); ?>
							</p>
						</td>
					</tr>
				</table>

				<div class="seoauto-helper-actions seoauto-helper-update-actions">
					<?php Update_Admin::render_check_form(); ?>
					<?php if ( $has_update ) : ?>
						<?php Update_Admin::render_upgrade_form( $cached->version ); ?>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
		Admin_View::wrap_end();
	}
}
