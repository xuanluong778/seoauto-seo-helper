<?php
/**
 * Kết nối — ghép nối, kiểm tra gói, cài đặt đăng bài.
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
			__( 'SEOAuto Helper → Kết nối', 'seoauto-seo-helper' ),
			__( 'Ghép nối bằng mã SA-XXXX-XXXX từ SEOAuto. Không dùng mật khẩu Administrator, Application Password, cookie wp-admin hoặc mã 2FA.', 'seoauto-seo-helper' )
		);
		Admin_View::nav_tabs( 'connect' );
		Admin_View::render_status_notices( $locked, $network_grace, $caps, $upgrade );
		?>
		<div class="seoauto-helper-card">
			<h2><?php echo esc_html__( 'Trạng thái', 'seoauto-seo-helper' ); ?></h2>
			<p><?php Admin_View::status_badge( $network_grace, $locked, $connected ); ?></p>
			<?php if ( $paired ) : ?>
				<table class="form-table" role="presentation">
					<tr>
						<th><?php echo esc_html__( 'connection_id', 'seoauto-seo-helper' ); ?></th>
						<td><code><?php echo esc_html( (string) $snap['connection_id'] ); ?></code></td>
					</tr>
					<tr>
						<th><?php echo esc_html__( 'site_id', 'seoauto-seo-helper' ); ?></th>
						<td><code><?php echo esc_html( (string) $snap['site_id'] ); ?></code></td>
					</tr>
					<tr>
						<th><?php echo esc_html__( 'site_secret', 'seoauto-seo-helper' ); ?></th>
						<td><em><?php echo esc_html__( 'Đã mã hóa — không hiển thị.', 'seoauto-seo-helper' ); ?></em>
							<?php if ( ! empty( $snap['secret_encrypted'] ) ) : ?>
								<span class="seoauto-helper-badge is-ok"><?php echo esc_html__( 'encrypted', 'seoauto-seo-helper' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th><?php echo esc_html__( 'Domain', 'seoauto-seo-helper' ); ?></th>
						<td><?php echo esc_html( (string) $snap['domain'] ); ?></td>
					</tr>
					<tr>
						<th><?php echo esc_html__( 'Kiểm tra kết nối gần nhất', 'seoauto-seo-helper' ); ?></th>
						<td>
							<?php
							echo esc_html( (string) ( $snap['last_check_at'] ?: '—' ) );
							if ( ! empty( $snap['last_check_message'] ) ) {
								echo ' — ' . esc_html( (string) $snap['last_check_message'] );
							}
							?>
						</td>
					</tr>
				</table>

				<form method="post" class="seoauto-helper-actions" style="display:inline-block;margin-right:8px;">
					<?php wp_nonce_field( 'seoauto_helper_admin' ); ?>
					<input type="hidden" name="seoauto_helper_action" value="refresh_entitlement" />
					<?php submit_button( __( 'Kiểm tra lại gói', 'seoauto-seo-helper' ), 'secondary', 'submit', false ); ?>
				</form>
				<form method="post" class="seoauto-helper-actions" style="display:inline-block;margin-right:8px;">
					<?php wp_nonce_field( 'seoauto_helper_admin' ); ?>
					<input type="hidden" name="seoauto_helper_action" value="test" />
					<?php submit_button( __( 'Kiểm tra kết nối', 'seoauto-seo-helper' ), 'secondary', 'submit', false ); ?>
				</form>
				<?php if ( $upgrade !== '' ) : ?>
					<a class="button button-secondary" href="<?php echo esc_url( $upgrade ); ?>" target="_blank" rel="noopener noreferrer" style="vertical-align:baseline;">
						<?php echo esc_html__( 'Nâng cấp gói', 'seoauto-seo-helper' ); ?>
					</a>
				<?php endif; ?>
				<form method="post" class="seoauto-helper-actions" style="display:inline-block;margin-left:8px;" onsubmit="return confirm('<?php echo esc_js( __( 'Ngắt kết nối SEOAuto? Secret sẽ bị xóa khỏi site này.', 'seoauto-seo-helper' ) ); ?>');">
					<?php wp_nonce_field( 'seoauto_helper_admin' ); ?>
					<input type="hidden" name="seoauto_helper_action" value="disconnect" />
					<?php submit_button( __( 'Ngắt kết nối', 'seoauto-seo-helper' ), 'delete', 'submit', false ); ?>
				</form>
			<?php endif; ?>
		</div>

		<div class="seoauto-helper-card">
			<h2><?php echo esc_html__( 'Post type được phép đăng', 'seoauto-seo-helper' ); ?></h2>
			<p class="description">
				<?php echo esc_html__( 'SEOAuto chỉ được tạo/cập nhật các post type đã bật. Mặc định chỉ "post".', 'seoauto-seo-helper' ); ?>
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
							<?php echo esc_html( $label . ' (' . $slug . ')' ); ?>
							<?php if ( $slug === 'post' ) : ?>
								<input type="hidden" name="allowed_post_types[]" value="post" />
							<?php endif; ?>
						</label>
					<?php endforeach; ?>
				</fieldset>
				<?php submit_button( __( 'Lưu cài đặt đăng bài', 'seoauto-seo-helper' ) ); ?>
			</form>
		</div>

		<div class="seoauto-helper-card">
			<h2><?php echo esc_html__( 'Nhập mã ghép nối', 'seoauto-seo-helper' ); ?></h2>
			<form method="post" autocomplete="off">
				<?php wp_nonce_field( 'seoauto_helper_admin' ); ?>
				<input type="hidden" name="seoauto_helper_action" value="pair" />
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="api_base"><?php echo esc_html__( 'SEOAuto API (HTTPS)', 'seoauto-seo-helper' ); ?></label></th>
						<td>
							<input name="api_base" id="api_base" type="url" class="regular-text" required
								value="<?php echo esc_attr( (string) $snap['api_base'] ); ?>"
								placeholder="https://seoauto.vn" />
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="pairing_code"><?php echo esc_html__( 'Mã ghép nối', 'seoauto-seo-helper' ); ?></label></th>
						<td>
							<input name="pairing_code" id="pairing_code" type="text" class="regular-text"
								placeholder="SA-XXXX-XXXX" autocomplete="off" autocapitalize="characters"
								spellcheck="false" inputmode="text" required />
						</td>
					</tr>
				</table>
				<?php submit_button( $paired ? __( 'Kết nối lại', 'seoauto-seo-helper' ) : __( 'Kết nối', 'seoauto-seo-helper' ) ); ?>
			</form>
		</div>
		<?php
		Admin_View::wrap_end();
	}
}
