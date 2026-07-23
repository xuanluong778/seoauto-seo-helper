<?php
/**
 * Activation routines.
 *
 * @package SEOAuto\SEOHelper
 */

declare(strict_types=1);

namespace SEOAuto\SEOHelper;

use SEOAuto\SEOHelper\Post\Schema;

final class Activator {

	/**
	 * Runs on plugin activation. Does not create users or edit wp-config.php.
	 */
	public static function activate(): void {
		if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
			deactivate_plugins( SEOAUTO_HELPER_BASENAME );
			wp_die(
				esc_html__( 'SEOAuto SEO Helper yêu cầu PHP 8.1 trở lên.', 'seoauto-seo-helper' ),
				esc_html__( 'Plugin activation failed', 'seoauto-seo-helper' ),
				array( 'back_link' => true )
			);
		}

		$defaults = array(
			'api_base'            => 'https://seoauto.vn',
			'connection_id'       => 0,
			'site_id'             => '',
			'site_secret'         => '',
			'organization_id'     => 0,
			'domain'              => '',
			'paired_at'           => '',
			'status'              => 'disconnected',
			'entitlement_json'    => '',
			'entitlement_sig'     => '',
			'last_sync_at'        => '',
			'last_error'          => '',
			'last_check_at'       => '',
			'last_check_ok'       => false,
			'last_check_message'  => '',
			'last_entitlement_check_at' => '',
			'last_entitlement_check_source' => '',
			'lock_reason'         => '',
			'network_grace_until' => '',
			'connectivity_state'  => '',
			'last_entitlement_was_active' => '',
			'last_api_error'      => '',
			'last_firewall_block' => '',
			'allowed_post_types'  => array( 'post' ),
			'audit_log_retention_days' => 90,
		);

		foreach ( $defaults as $key => $value ) {
			$option = SEOAUTO_HELPER_PREFIX . $key;
			if ( false === get_option( $option, false ) ) {
				add_option( $option, $value, '', false );
			}
		}

		if ( ! wp_next_scheduled( 'seoauto_helper_sync_entitlement' ) ) {
			self::register_cron_schedules();
			wp_schedule_event( time() + \HOUR_IN_SECONDS, 'seoauto_six_hours', 'seoauto_helper_sync_entitlement' );
		}

		if ( ! wp_next_scheduled( 'seoauto_helper_process_audit_jobs' ) ) {
			self::register_cron_schedules();
			wp_schedule_event( time() + 60, 'seoauto_every_minute', 'seoauto_helper_process_audit_jobs' );
		}

		if ( ! wp_next_scheduled( 'seoauto_helper_content_ops_purge' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'seoauto_helper_content_ops_purge' );
		}

		if ( ! function_exists( 'dbDelta' ) ) {
			require_once \ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		Schema::install();
		update_option( SEOAUTO_HELPER_PREFIX . Schema::OPTION_DB_VERSION, Schema::DB_VERSION, false );

		flush_rewrite_rules( false );
	}

	public static function activate_safe(): void {
		try {
			self::activate();
		} catch ( \Throwable $e ) {
			if ( function_exists( 'deactivate_plugins' ) && defined( 'SEOAUTO_HELPER_BASENAME' ) ) {
				deactivate_plugins( SEOAUTO_HELPER_BASENAME );
			}
			if ( function_exists( 'wp_die' ) ) {
				wp_die(
					esc_html__(
						'SEOAuto SEO Helper không kích hoạt được. Kiểm tra PHP 8.1+, OpenSSL và upload lại file ZIP chuẩn.',
						'seoauto-seo-helper'
					) . ' [' . esc_html( $e->getMessage() ) . ']',
					esc_html__( 'Plugin activation failed', 'seoauto-seo-helper' ),
					array( 'back_link' => true )
				);
			}
			throw $e;
		}
	}

	/** Register custom cron interval before wp_schedule_event on activation. */
	private static function register_cron_schedules(): void {
		\add_filter(
			'cron_schedules',
			static function ( array $schedules ): array {
				if ( ! isset( $schedules['seoauto_six_hours'] ) ) {
					$schedules['seoauto_six_hours'] = array(
						'interval' => 6 * \HOUR_IN_SECONDS,
						'display'  => 'Mỗi 6 giờ (SEOAuto)',
					);
				}
				if ( ! isset( $schedules['seoauto_every_minute'] ) ) {
					$schedules['seoauto_every_minute'] = array(
						'interval' => 60,
						'display'  => 'Mỗi phút (SEOAuto Audit)',
					);
				}
				return $schedules;
			}
		);
	}
}
