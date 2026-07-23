<?php
/**
 * Kết nối — ghép nối và cài đặt đăng bài (giao diện thân thiện).
 *
 * @package SEOAuto\SEOHelper
 */

declare(strict_types=1);

namespace SEOAuto\SEOHelper\Admin;

use SEOAuto\SEOHelper\Connection\Connection_Manager;
use SEOAuto\SEOHelper\Entitlement\Entitlement_Manager;
use SEOAuto\SEOHelper\Post\Publishing_Settings;

final class Connect_Page {

	public function __construct(
		private Connection_Manager $connection,
		private Entitlement_Manager $entitlement,
		private Publishing_Settings $publishing
	) {}

	public function render(): void {
		$snap          = $this->connection->get_snapshot();
		$caps          = $this->entitlement->capabilities();
		$paired        = $this->connection->has_credentials();
		$connected     = $this->connection->is_connected();
		$locked        = $this->entitlement->is_locked();
		$network_grace = ! empty( $caps['network_grace_active'] );
		$allowed       = $this->publishing->allowed_post_types();
		$selectable    = $this->publishing->selectable_post_types();
		$upgrade       = (string) ( $caps['upgrade_url'] ?? $this->entitlement->upgrade_url() );

		Admin_View::wrap_start(
			__( 'Kết nối SEOAuto', 'seoauto-seo-helper' ),
			__( 'Nhập mã ghép nối từ SEOAuto. Không cần mật khẩu Administrator hay Application Password.', 'seoauto-seo-helper' )
		);
		Admin_View::nav_tabs( 'connect', $this->entitlement );
		Admin_View::render_status_notices( $locked, $network_grace, $caps, $upgrade );
		?>
		<div class="seoauto-helper-card">
			<h2><?php echo esc_html__( 'Trạng thái kết nối', 'seoauto-seo-helper' ); ?></h2>
			<p><?php Admin_View::status_badge( $network_grace, $locked, $connected ); ?></p>
			<?php if ( $paired ) : ?>
				<table class="form-table" role="presentation">
					<tr>
						<th><?php echo esc_html__( 'Website', 'seoauto-seo-helper' ); ?></th>
						<td><?php echo esc_html( (string) ( $snap['domain'] ?: home_url() ) ); ?></td>
					</tr>
					<tr>
						<th><?php echo esc_html__( 'Kiểm tra gần nhất', 'seoauto-seo-helper' ); ?></th>
						<td><?php echo esc_html( (string) ( $snap['last_check_at'] ?: '—' ) ); ?></td>
					</tr>
				</table>

				<div class="seoauto-helper-actions">
					<form method="post" class="seoauto-helper-inline-form">
						<?php wp_nonce_field( 'seoauto_helper_admin' ); ?>
						<input type="hidden" name="seoauto_helper_action" value="refresh_entitlement" />
						<?php submit_button( __( 'Kiểm tra lại gói', 'seoauto-seo-helper' ), 'secondary', 'submit', false ); ?>
					</form>
					<form method="post" class="seoauto-helper-inline-form">
						<?php wp_nonce_field( 'seoauto_helper_admin' ); ?>
						<input type="hidden" name="seoauto_helper_action" value="test" />
						<?php submit_button( __( 'Kiểm tra kết nối', 'seoauto-seo-helper' ), 'secondary', 'submit', false ); ?>
					</form>
					<?php if ( $upgrade !== '' ) : ?>
						<a class="button button-secondary" href="<?php echo esc_url( $upgrade ); ?>" target="_blank" rel="noopener noreferrer">
							<?php echo esc_html__( 'Nâng cấp gói', 'seoauto-seo-helper' ); ?>
						</a>
					<?php endif; ?>
					<form method="post" class="seoauto-helper-inline-form" onsubmit="return confirm('<?php echo esc_js( __( 'Ngắt kết nối SEOAuto trên website này?', 'seoauto-seo-helper' ) ); ?>');">
						<?php wp_nonce_field( 'seoauto_helper_admin' ); ?>
						<input type="hidden" name="seoauto_helper_action" value="disconnect" />
						<?php submit_button( __( 'Ngắt kết nối', 'seoauto-seo-helper' ), 'delete', 'submit', false ); ?>
					</form>
				</div>
			<?php endif; ?>
		</div>

		<div class="seoauto-helper-card">
			<h2><?php echo esc_html__( 'Loại nội dung được phép đăng', 'seoauto-seo-helper' ); ?></h2>
			<p class="description">
				<?php echo esc_html__( 'SEOAuto chỉ tạo hoặc cập nhật các loại nội dung bạn bật bên dưới. Mặc định chỉ Bài viết.', 'seoauto-seo-helper' ); ?>
			</p>
			<form method="post">
				<?php wp_nonce_field( 'seoauto_helper_admin' ); ?>
				<input type="hidden" name="seoauto_helper_action" value="save_publishing" />
				<fieldset>
					<?php foreach ( $selectable as $slug => $label ) : ?>
						<label style="display:block;margin:6px 0;">
							<input type="checkbox" name="allowed_post_types[]" value="<?php echo esc_attr( $slug ); ?>"
								<?php checked( in_array( $slug, $allowed, true ) ); ?>
								<?php disabled( $slug === 'post' ); ?> />
							<?php echo esc_html( $label ); ?>
							<?php if ( $slug === 'post' ) : ?>
								<input type="hidden" name="allowed_post_types[]" value="post" />
							<?php endif; ?>
						</label>
					<?php endforeach; ?>
				</fieldset>
				<?php submit_button( __( 'Lưu cài đặt', 'seoauto-seo-helper' ) ); ?>
			</form>
		</div>

		<?php if ( ! $paired ) : ?>
			<div class="seoauto-helper-card">
				<h2><?php echo esc_html__( 'Ghép nối website', 'seoauto-seo-helper' ); ?></h2>
				<p class="description">
					<?php echo esc_html__( 'Lấy mã ghép nối trong SEOAuto (dạng SA-XXXX-XXXX), rồi dán vào ô bên dưới.', 'seoauto-seo-helper' ); ?>
				</p>
				<form method="post">
					<?php wp_nonce_field( 'seoauto_helper_admin' ); ?>
					<input type="hidden" name="seoauto_helper_action" value="pair" />
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="seoauto_pairing_code"><?php echo esc_html__( 'Mã ghép nối', 'seoauto-seo-helper' ); ?></label></th>
							<td>
								<input type="text" class="regular-text" id="seoauto_pairing_code" name="pairing_code" autocomplete="off" placeholder="SA-XXXX-XXXX" />
							</td>
						</tr>
					</table>
					<?php submit_button( __( 'Ghép nối', 'seoauto-seo-helper' ), 'primary' ); ?>
				</form>
			</div>
		<?php endif; ?>
		<?php
		Admin_View::wrap_end();
	}
}
