<?php
/**
 * Admin menu — Tổng quan, Kết nối, Nhật ký.
 *
 * @package SEOAuto\SEOHelper
 */

declare(strict_types=1);

namespace SEOAuto\SEOHelper\Admin;

use SEOAuto\SEOHelper\Audit\Audit_Logger;
use SEOAuto\SEOHelper\Connection\Connection_Manager;
use SEOAuto\SEOHelper\Entitlement\Entitlement_Manager;
use SEOAuto\SEOHelper\Post\Publishing_Settings;
use SEOAuto\SEOHelper\Seo\Seo_Facade;
use SEOAuto\SEOHelper\SeoAudit\Audit_Job_Runner;
use SEOAuto\SEOHelper\SeoAudit\Object_Context;

final class Admin_Menu {

	public const SLUG_OVERVIEW = 'seoauto-helper';
	public const SLUG_CONNECT  = 'seoauto-helper-connect';
	public const SLUG_AUDIT    = 'seoauto-helper-audit';
	public const SLUG_JOBS     = 'seoauto-helper-jobs';
	public const SLUG_LOGS     = 'seoauto-helper-logs';

	public function __construct(
		private Connection_Manager $connection,
		private Entitlement_Manager $entitlement,
		private Audit_Logger $audit,
		private Publishing_Settings $publishing,
		private Seo_Facade $seo,
		private Audit_Job_Runner $audit_jobs
	) {}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'handle_actions' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	public function add_menu(): void {
		add_menu_page(
			__( 'SEOAuto Helper', 'seoauto-seo-helper' ),
			__( 'SEOAuto Helper', 'seoauto-seo-helper' ),
			'manage_options',
			self::SLUG_OVERVIEW,
			array( $this, 'render_overview' ),
			'dashicons-admin-site-alt3',
			58
		);

		add_submenu_page(
			self::SLUG_OVERVIEW,
			__( 'Tổng quan', 'seoauto-seo-helper' ),
			__( 'Tổng quan', 'seoauto-seo-helper' ),
			'manage_options',
			self::SLUG_OVERVIEW,
			array( $this, 'render_overview' )
		);

		add_submenu_page(
			self::SLUG_OVERVIEW,
			__( 'Kết nối', 'seoauto-seo-helper' ),
			__( 'Kết nối', 'seoauto-seo-helper' ),
			'manage_options',
			self::SLUG_CONNECT,
			array( $this, 'render_connect' )
		);

		add_submenu_page(
			self::SLUG_OVERVIEW,
			__( 'SEO Audit', 'seoauto-seo-helper' ),
			__( 'SEO Audit', 'seoauto-seo-helper' ),
			'manage_options',
			self::SLUG_AUDIT,
			array( $this, 'render_audit' )
		);

		add_submenu_page(
			self::SLUG_OVERVIEW,
			__( 'Jobs', 'seoauto-seo-helper' ),
			__( 'Jobs', 'seoauto-seo-helper' ),
			'manage_options',
			self::SLUG_JOBS,
			array( $this, 'render_jobs' )
		);

		add_submenu_page(
			self::SLUG_OVERVIEW,
			__( 'Nhật ký', 'seoauto-seo-helper' ),
			__( 'Nhật ký', 'seoauto-seo-helper' ),
			'manage_options',
			self::SLUG_LOGS,
			array( $this, 'render_logs' )
		);
	}

	public function enqueue( string $hook ): void {
		if ( ! str_contains( $hook, 'seoauto-helper' ) ) {
			return;
		}
		wp_enqueue_style(
			'seoauto-helper-admin',
			SEOAUTO_HELPER_URL . 'assets/css/admin.css',
			array(),
			SEOAUTO_HELPER_VERSION
		);
	}

	public function handle_actions(): void {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( empty( $_POST['seoauto_helper_action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}
		check_admin_referer( 'seoauto_helper_admin' );

		$action = sanitize_key( (string) wp_unslash( $_POST['seoauto_helper_action'] ) );

		if ( $action === 'pair' ) {
			$this->handle_pair();
		}
		if ( $action === 'refresh_entitlement' ) {
			$this->handle_refresh_entitlement();
		}
		if ( $action === 'test' ) {
			$this->handle_test();
		}
		if ( $action === 'disconnect' ) {
			$this->handle_disconnect();
		}
		if ( $action === 'save_publishing' ) {
			$this->handle_save_publishing();
		}
		if ( $action === 'save_log_retention' ) {
			$this->handle_save_log_retention();
		}
		if ( $action === 'audit_start_scan' ) {
			$this->handle_audit_start_scan();
		}
		if ( $action === 'audit_cancel_job' ) {
			$this->handle_audit_cancel_job();
		}
		if ( $action === 'audit_resume_job' ) {
			$this->handle_audit_resume_job();
		}
	}

	public function render_overview(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( $this->connection->has_credentials() ) {
			$this->entitlement->refresh_check( 'admin_overview' );
		}
		( new Overview_Page(
			$this->connection,
			$this->entitlement,
			$this->audit,
			new Site_Info( $this->connection, $this->seo )
		) )->render();
	}

	public function render_connect(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( $this->connection->has_credentials() ) {
			$this->entitlement->refresh_check( 'admin_page' );
		}
		( new Connect_Page(
			$this->connection,
			$this->entitlement,
			$this->publishing
		) )->render();
	}

	public function render_logs(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		( new Logs_Page( $this->audit ) )->render();
	}

	public function render_audit(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		( new Audit_Page( $this->audit_jobs, $this->entitlement ) )->render();
	}

	public function render_jobs(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		( new Jobs_Page( $this->audit_jobs, $this->entitlement ) )->render();
	}

	private function handle_audit_start_scan(): void {
		$result = $this->audit_jobs->enqueue_scan(
			array(
				'post_types' => Object_Context::audit_post_types(),
				'mode'       => 'scan_only',
				'batch_size' => 20,
			)
		);
		if ( is_wp_error( $result ) ) {
			add_settings_error( 'seoauto_helper', 'audit_denied', $result->get_error_message(), 'error' );
			return;
		}
		add_settings_error(
			'seoauto_helper',
			'audit_queued',
			sprintf(
				/* translators: 1: job id 2: run id */
				__( 'Đã xếp hàng scan — Job #%1$d / Run #%2$d.', 'seoauto-seo-helper' ),
				(int) $result['job_id'],
				(int) $result['run_id']
			),
			'updated'
		);
	}

	private function handle_audit_cancel_job(): void {
		$job_id = isset( $_POST['job_id'] ) ? absint( $_POST['job_id'] ) : 0;
		$ok     = $job_id > 0 && $this->audit_jobs->cancel_job( $job_id );
		add_settings_error(
			'seoauto_helper',
			$ok ? 'job_cancelled' : 'job_cancel_fail',
			$ok ? __( 'Đã hủy job.', 'seoauto-seo-helper' ) : __( 'Không hủy được job.', 'seoauto-seo-helper' ),
			$ok ? 'updated' : 'error'
		);
	}

	private function handle_audit_resume_job(): void {
		$job_id = isset( $_POST['job_id'] ) ? absint( $_POST['job_id'] ) : 0;
		$result = $job_id > 0 ? $this->audit_jobs->resume_job( $job_id ) : false;
		if ( is_wp_error( $result ) ) {
			add_settings_error( 'seoauto_helper', 'job_resume_denied', $result->get_error_message(), 'error' );
			return;
		}
		add_settings_error(
			'seoauto_helper',
			$result ? 'job_resumed' : 'job_resume_fail',
			$result ? __( 'Đã tiếp tục job.', 'seoauto-seo-helper' ) : __( 'Không tiếp tục được job.', 'seoauto-seo-helper' ),
			$result ? 'updated' : 'error'
		);
	}

	private function handle_pair(): void {
		$code   = isset( $_POST['pairing_code'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['pairing_code'] ) ) : '';
		$base   = isset( $_POST['api_base'] ) ? esc_url_raw( wp_unslash( (string) $_POST['api_base'] ) ) : '';
		$result = $this->connection->pair_with_code( $code, $base );
		if ( $result['ok'] ) {
			$raw = (string) $this->connection->option( 'entitlement_json', '' );
			$ent = json_decode( $raw, true );
			if ( is_array( $ent ) ) {
				$this->entitlement->store( $ent );
			}
			$lock = $this->entitlement->refresh_check( 'admin_pair' );
			if ( ! empty( $lock['locked'] ) ) {
				add_settings_error(
					'seoauto_helper',
					'paired_locked',
					(string) ( $lock['message'] ?? __( 'Đã ghép nối nhưng plugin đang LOCKED.', 'seoauto-seo-helper' ) ),
					'warning'
				);
			}
		}
		$this->audit->log(
			'admin_pair',
			array(
				'ok'          => $result['ok'],
				'code_prefix' => substr( strtoupper( preg_replace( '/\s+/', '', $code ) ?? '' ), 0, 7 ),
			)
		);
		add_settings_error(
			'seoauto_helper',
			$result['ok'] ? 'paired' : 'pair_fail',
			$result['message'],
			$result['ok'] ? 'updated' : 'error'
		);
	}

	private function handle_refresh_entitlement(): void {
		if ( ! $this->connection->has_credentials() ) {
			add_settings_error(
				'seoauto_helper',
				'entitlement_not_paired',
				__( 'Chưa ghép nối — không thể kiểm tra gói.', 'seoauto-seo-helper' ),
				'error'
			);
			return;
		}
		$result = $this->entitlement->refresh_check( 'admin_button' );
		$this->audit->log(
			'admin_entitlement_refresh',
			array(
				'allowed'     => $result['allowed'],
				'locked'      => $result['locked'],
				'lock_reason' => $result['reason'],
			)
		);
		add_settings_error(
			'seoauto_helper',
			! empty( $result['network_grace'] ) ? 'entitlement_grace' : ( $result['allowed'] ? 'entitlement_ok' : 'entitlement_locked' ),
			$result['message'],
			! empty( $result['network_grace'] ) ? 'warning' : ( $result['allowed'] ? 'updated' : 'warning' )
		);
	}

	private function handle_test(): void {
		$result = $this->connection->test_connection();
		$context = array(
			'ok'     => $result['ok'],
			'status' => $result['ok'] ? 'ok' : 'error',
		);
		if ( ! empty( $result['firewall_blocked'] ) && ! empty( $result['endpoint'] ) ) {
			$block = array(
				'source'      => 'wordfence',
				'http_code'   => (int) ( $result['http_code'] ?? 403 ),
				'endpoint'    => (string) $result['endpoint'],
				'method'      => (string) ( $result['method'] ?? 'POST' ),
				'detected_at' => gmdate( 'c' ),
			);
			$context = array_merge(
				$context,
				\SEOAuto\SEOHelper\Security\Firewall_Guidance::audit_context( $block, 'admin_test' )
			);
			$this->audit->log_error(
				'firewall_blocked',
				\SEOAuto\SEOHelper\Security\Firewall_Guidance::ERROR_CODE,
				$context
			);
		} else {
			$this->audit->log( 'admin_test', $context );
		}
		add_settings_error(
			'seoauto_helper',
			$result['ok'] ? 'test_ok' : 'test_fail',
			$result['message'],
			$result['ok'] ? 'updated' : 'error'
		);
	}

	private function handle_disconnect(): void {
		$this->connection->disconnect();
		$this->audit->log( 'admin_disconnect', array( 'status' => 'ok' ) );
		add_settings_error( 'seoauto_helper', 'disconnected', __( 'Đã ngắt kết nối SEOAuto.', 'seoauto-seo-helper' ), 'updated' );
	}

	private function handle_save_publishing(): void {
		$types = array();
		if ( isset( $_POST['allowed_post_types'] ) && is_array( $_POST['allowed_post_types'] ) ) {
			foreach ( wp_unslash( $_POST['allowed_post_types'] ) as $type ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				$types[] = sanitize_key( (string) $type );
			}
		}
		$saved = $this->publishing->save_allowed_post_types( $types );
		$this->audit->log( 'admin_publishing_settings', array( 'types' => $saved, 'status' => 'ok' ) );
		add_settings_error(
			'seoauto_helper',
			'publishing_saved',
			__( 'Đã lưu post type được phép đăng từ SEOAuto.', 'seoauto-seo-helper' ),
			'updated'
		);
	}

	private function handle_save_log_retention(): void {
		$days = isset( $_POST['audit_log_retention_days'] ) ? (int) wp_unslash( $_POST['audit_log_retention_days'] ) : Audit_Logger::RETENTION_90;
		$saved = $this->audit->set_retention_days( $days );
		$purged = $this->audit->purge_expired();
		$this->audit->log(
			'admin_log_retention',
			array(
				'retention_days' => $saved,
				'purged'         => $purged,
				'status'         => 'ok',
			)
		);
		add_settings_error(
			'seoauto_helper',
			'log_retention_saved',
			sprintf(
				/* translators: 1: retention days 2: purged count */
				__( 'Đã lưu giữ nhật ký %1$d ngày. Đã xóa %2$d mục cũ.', 'seoauto-seo-helper' ),
				$saved,
				$purged
			),
			'updated'
		);
	}
}
