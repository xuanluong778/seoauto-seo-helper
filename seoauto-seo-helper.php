<?php
/**
 * Plugin Name:       SEOAuto SEO Helper
 * Plugin URI:        https://seoauto.vn
 * Description:       Káº¿t ná»‘i WordPress vá»›i SEOAuto â€” tá»‘i Æ°u Open Graph, Schema, Ä‘á»“ng bá»™ Rank Math/Yoast khi Ä‘Äƒng bÃ i tá»« SEOAuto.
 * Version:           1.1.0-beta.2
 * Requires at least: 6.0
 * Requires PHP:      8.1
 * Author:            SEOAuto
 * Author URI:        https://seoauto.vn
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       seoauto-seo-helper
 * Domain Path:       /languages
 * Update URI:        https://seoauto.vn/plugin/seoauto-seo-helper
 *
 * @package SEOAuto\SEOHelper
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SEOAUTO_HELPER_VERSION', '1.1.0-beta.2' );
define( 'SEOAUTO_HELPER_FILE', __FILE__ );
define( 'SEOAUTO_HELPER_PATH', plugin_dir_path( __FILE__ ) );
define( 'SEOAUTO_HELPER_URL', plugin_dir_url( __FILE__ ) );
define( 'SEOAUTO_HELPER_BASENAME', plugin_basename( __FILE__ ) );
define( 'SEOAUTO_HELPER_PREFIX', 'seoauto_helper_' );

if ( ! is_readable( SEOAUTO_HELPER_PATH . 'includes/Plugin.php' ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			echo '<div class="notice notice-error"><p>';
			echo esc_html__(
				'SEOAuto SEO Helper: thiáº¿u thÆ° má»¥c includes/. HÃ£y xÃ³a plugin vÃ  upload láº¡i file seoauto-seo-helper.zip chuáº©n (khÃ´ng nÃ©n thá»§ cÃ´ng).',
				'seoauto-seo-helper'
			);
			echo '</p></div>';
		}
	);
	return;
}

/**
 * PSR-4 autoload for SEOAuto\SEOHelper\* (includes/ only).
 */
spl_autoload_register(
	static function ( string $class ): void {
		$prefix = 'SEOAuto\\SEOHelper\\';
		if ( strncmp( $prefix, $class, strlen( $prefix ) ) !== 0 ) {
			return;
		}
		$relative = substr( $class, strlen( $prefix ) );
		$relative = str_replace( '\\', DIRECTORY_SEPARATOR, $relative );
		$file     = SEOAUTO_HELPER_PATH . 'includes' . DIRECTORY_SEPARATOR . $relative . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

register_activation_hook( __FILE__, array( SEOAuto\SEOHelper\Activator::class, 'activate_safe' ) );
register_deactivation_hook( __FILE__, array( SEOAuto\SEOHelper\Deactivator::class, 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function (): void {
		if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
			add_action(
				'admin_notices',
				static function (): void {
					echo '<div class="notice notice-error"><p>';
					echo esc_html__( 'SEOAuto SEO Helper yÃªu cáº§u PHP 8.1 trá»Ÿ lÃªn.', 'seoauto-seo-helper' );
					echo '</p></div>';
				}
			);
			return;
		}

		if ( ! extension_loaded( 'openssl' ) ) {
			add_action(
				'admin_notices',
				static function (): void {
					echo '<div class="notice notice-error"><p>';
					echo esc_html__( 'SEOAuto SEO Helper yÃªu cáº§u PHP extension OpenSSL.', 'seoauto-seo-helper' );
					echo '</p></div>';
				}
			);
			return;
		}

		if ( ! class_exists( SEOAuto\SEOHelper\Plugin::class, false ) && ! is_readable( SEOAUTO_HELPER_PATH . 'includes/Plugin.php' ) ) {
			return;
		}

		try {
			SEOAuto\SEOHelper\Plugin::instance()->boot();
		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'SEOAuto SEO Helper boot failed: ' . $e->getMessage() );
			}
			add_action(
				'admin_notices',
				static function (): void {
					echo '<div class="notice notice-error"><p>';
					echo esc_html__(
						'SEOAuto SEO Helper khÃ´ng khá»Ÿi Ä‘á»™ng Ä‘Æ°á»£c. Vui lÃ²ng xÃ³a plugin, upload láº¡i ZIP chuáº©n, hoáº·c xem debug.log.',
						'seoauto-seo-helper'
					);
					echo '</p></div>';
				}
			);
		}
	},
	5
);
