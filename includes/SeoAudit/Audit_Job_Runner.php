<?php
/**
 * WP-Cron batch runner for SEO audit jobs.
 *
 * Never runs on frontend page render — only cron / admin-ajax spawn / REST enqueue.
 *
 * @package SEOAuto\SEOHelper
 */

declare(strict_types=1);

namespace SEOAuto\SEOHelper\SeoAudit;

use SEOAuto\SEOHelper\Audit\Audit_Logger;
use SEOAuto\SEOHelper\Connection\Connection_Manager;
use SEOAuto\SEOHelper\Entitlement\Entitlement_Manager;
use SEOAuto\SEOHelper\Seo\Seo_Facade;
use WP_Error;

final class Audit_Job_Runner {

	public const HOOK_PROCESS = 'seoauto_helper_process_audit_jobs';
	public const SCHEDULE     = 'seoauto_every_minute';
	public const FEATURE      = 'seo_audit';

	private Audit_Run_Store $runs;
	private Job_Store $jobs;
	private Issue_Store $issues;
	private Audit_Engine $engine;

	public function __construct(
		private Entitlement_Manager $entitlement,
		private Connection_Manager $connection,
		private Seo_Facade $seo,
		private Audit_Logger $audit,
		?Audit_Run_Store $runs = null,
		?Job_Store $jobs = null,
		?Issue_Store $issues = null,
		?Audit_Engine $engine = null
	) {
		$this->runs   = $runs ?? new Audit_Run_Store();
		$this->jobs   = $jobs ?? new Job_Store();
		$this->issues = $issues ?? new Issue_Store();
		$this->engine = $engine ?? new Audit_Engine( $this->seo, $this->issues, $this->runs );
	}

	public function register(): void {
		add_filter( 'cron_schedules', array( $this, 'add_schedules' ) );
		add_action( self::HOOK_PROCESS, array( $this, 'process_queue' ) );
		$this->ensure_scheduled();
	}

	/**
	 * @param array<string,array<string,mixed>> $schedules
	 * @return array<string,array<string,mixed>>
	 */
	public function add_schedules( array $schedules ): array {
		if ( ! isset( $schedules[ self::SCHEDULE ] ) ) {
			$schedules[ self::SCHEDULE ] = array(
				'interval' => 60,
				'display'  => __( 'Mỗi phút (SEOAuto Audit)', 'seoauto-seo-helper' ),
			);
		}
		return $schedules;
	}

	public function ensure_scheduled(): void {
		if ( ! wp_next_scheduled( self::HOOK_PROCESS ) ) {
			wp_schedule_event( time() + 30, self::SCHEDULE, self::HOOK_PROCESS );
		}
	}

	public function engine(): Audit_Engine {
		return $this->engine;
	}

	public function runs(): Audit_Run_Store {
		return $this->runs;
	}

	public function jobs(): Job_Store {
		return $this->jobs;
	}

	public function issues(): Issue_Store {
		return $this->issues;
	}

	/**
	 * Whether new scans are allowed (entitlement + not locked).
	 */
	public function can_start_scan(): bool|WP_Error {
		if ( $this->entitlement->is_locked() ) {
			return new WP_Error(
				'seoauto_plugin_locked',
				__( 'Plugin LOCKED — không thể tạo scan mới. Kết quả cũ vẫn được giữ.', 'seoauto-seo-helper' ),
				array( 'status' => 403 )
			);
		}
		if ( ! $this->feature_allowed() ) {
			return new WP_Error(
				'seoauto_feature_denied',
				__( 'Gói hiện tại chưa được cấp quyền seo_audit. Vui lòng nâng cấp gói hoặc liên hệ SEOAuto để kích hoạt SEO Audit.', 'seoauto-seo-helper' ),
				array( 'status' => 403 )
			);
		}
		return true;
	}

	/**
	 * Cron schedule health for admin UI (no secrets).
	 *
	 * @return array{scheduled:bool,next_ts:int,wp_cron_disabled:bool,hook:string,lock_ttl:int}
	 */
	public function cron_status(): array {
		$next = wp_next_scheduled( self::HOOK_PROCESS );
		return array(
			'scheduled'         => false !== $next && (int) $next > 0,
			'next_ts'           => false !== $next ? (int) $next : 0,
			'wp_cron_disabled'  => defined( 'DISABLE_WP_CRON' ) && \DISABLE_WP_CRON,
			'hook'              => self::HOOK_PROCESS,
			'lock_ttl'          => Job_Store::LOCK_TTL_SECONDS,
		);
	}

	private function feature_allowed(): bool {
		if ( $this->entitlement->has_feature( self::FEATURE ) ) {
			return true;
		}
		// Development only — never ship production with seo_helper implying seo_audit.
		if ( ! $this->is_dev_environment() ) {
			return false;
		}
		if ( $this->connection->has_credentials()
			&& $this->entitlement->is_allowed()
			&& $this->entitlement->has_feature( 'seo_helper' )
		) {
			return true;
		}
		// Local admin (not paired) for Phase 1 staging under WP_DEBUG.
		if ( ! $this->connection->has_credentials()
			&& is_admin()
			&& current_user_can( 'manage_options' )
		) {
			return true;
		}
		return false;
	}

	/**
	 * True only for local/dev. Production must rely on SaaS `seo_audit` feature.
	 */
	private function is_dev_environment(): bool {
		if ( defined( 'SEOAUTO_HELPER_DEV' ) && \SEOAUTO_HELPER_DEV ) {
			return true;
		}
		if ( defined( 'WP_DEBUG' ) && \WP_DEBUG ) {
			return true;
		}
		return (bool) apply_filters( 'seoauto_helper_dev_entitlement_fallback', false );
	}

	/**
	 * Enqueue scan job (idempotent by request_id).
	 *
	 * @param array{request_id?:string,post_types?:list<string>,mode?:string,batch_size?:int} $args
	 * @return array{ok:bool,job_id:int,run_id:int,request_id:string,idempotent_replay?:bool,message?:string}|WP_Error
	 */
	public function enqueue_scan( array $args = array() ): array|WP_Error {
		global $wpdb;

		$gate = $this->can_start_scan();
		if ( $gate instanceof WP_Error ) {
			return $gate;
		}

		$request_id = sanitize_text_field( (string) ( $args['request_id'] ?? '' ) );
		if ( $request_id === '' ) {
			$request_id = wp_generate_uuid4();
		}

		$existing_job = $this->jobs->get_by_request_id( $request_id );
		if ( null !== $existing_job ) {
			return array(
				'ok'                => true,
				'job_id'            => (int) $existing_job['id'],
				'run_id'            => (int) $existing_job['run_id'],
				'request_id'        => $request_id,
				'idempotent_replay' => true,
				'message'           => __( 'Job đã tồn tại (idempotent).', 'seoauto-seo-helper' ),
			);
		}

		// Prevent duplicate concurrent scans (queued / retrying / running).
		$active = $this->jobs->find_active( Job_Store::TYPE_AUDIT_SCAN );
		if ( null !== $active ) {
			return array(
				'ok'                => true,
				'job_id'            => (int) $active['id'],
				'run_id'            => (int) $active['run_id'],
				'request_id'        => (string) ( $active['request_id'] ?? $request_id ),
				'idempotent_replay' => true,
				'message'           => sprintf(
					/* translators: 1: job id 2: status */
					__( 'Đã có job #%1$d đang ở trạng thái %2$s — không tạo job mới. Làm mới trang để xem tiến độ.', 'seoauto-seo-helper' ),
					(int) $active['id'],
					(string) ( $active['status'] ?? '' )
				),
			);
		}

		$types = $args['post_types'] ?? Object_Context::audit_post_types();
		if ( ! is_array( $types ) || $types === array() ) {
			$types = Object_Context::audit_post_types();
		}
		$types = array_values( array_unique( array_map( 'sanitize_key', $types ) ) );
		$mode  = sanitize_key( (string) ( $args['mode'] ?? 'scan_only' ) );
		if ( $mode !== 'scan_only' ) {
			$mode = 'scan_only'; // Phase 1: scan only.
		}
		$batch = max( 1, min( 50, (int) ( $args['batch_size'] ?? Audit_Engine::BATCH_SIZE ) ) );

		$total  = $this->engine->count_objects( $types );
		$run_id = $this->runs->create(
			array(
				'request_id'    => $request_id,
				'status'        => Audit_Run_Store::STATUS_QUEUED,
				'mode'          => $mode,
				'post_types'    => $types,
				'total_objects' => $total,
				'seo_adapter'   => $this->seo->active_id(),
				'meta'          => array( 'site_checked' => false ),
			)
		);

		$run_db_error = isset( $wpdb ) ? (string) $wpdb->last_error : '';
		if ( $run_id <= 0 || '' !== $run_db_error ) {
			$this->audit->log_error(
				'audit_scan_enqueue_failed',
				'db_insert_run',
				array(
					'request_id' => $request_id,
					'run_id'     => $run_id,
					'message'    => $this->safe_db_message( $run_db_error ),
				)
			);
			return new WP_Error(
				'seoauto_audit_db_error',
				__( 'Không tạo được bản ghi audit run (lỗi cơ sở dữ liệu). Kiểm tra bảng seoauto_helper_audit_runs và thử lại.', 'seoauto-seo-helper' ),
				array( 'status' => 500 )
			);
		}

		$job_id = $this->jobs->create(
			array(
				'request_id'   => $request_id,
				'job_type'     => Job_Store::TYPE_AUDIT_SCAN,
				'status'       => Job_Store::STATUS_QUEUED,
				'run_id'       => $run_id,
				'batch_size'   => $batch,
				'payload'      => array(
					'post_types' => $types,
					'mode'       => $mode,
				),
			)
		);

		$job_db_error = isset( $wpdb ) ? (string) $wpdb->last_error : '';
		if ( $job_id <= 0 || '' !== $job_db_error ) {
			$this->runs->update(
				$run_id,
				array(
					'status'        => Audit_Run_Store::STATUS_FAILED,
					'error_code'    => 'db_insert_job',
					'error_message' => 'Failed to create job row',
					'finished_gmt'  => gmdate( 'Y-m-d H:i:s' ),
				)
			);
			$this->audit->log_error(
				'audit_scan_enqueue_failed',
				'db_insert_job',
				array(
					'request_id' => $request_id,
					'run_id'     => $run_id,
					'job_id'     => $job_id,
					'message'    => $this->safe_db_message( $job_db_error ),
				)
			);
			return new WP_Error(
				'seoauto_audit_db_error',
				__( 'Không tạo được job quét SEO (lỗi cơ sở dữ liệu). Kiểm tra bảng seoauto_helper_jobs và thử lại.', 'seoauto-seo-helper' ),
				array( 'status' => 500 )
			);
		}

		$this->runs->update( $run_id, array( 'job_id' => $job_id ) );

		$this->audit->log(
			'audit_scan_enqueued',
			array(
				'request_id' => $request_id,
				'job_id'     => $job_id,
				'run_id'     => $run_id,
				'total'      => $total,
				'status'     => 'ok',
			)
		);

		// Spawn cron soon without blocking the HTTP request.
		$this->ensure_scheduled();
		wp_schedule_single_event( time() + 5, self::HOOK_PROCESS );
		spawn_cron();

		return array(
			'ok'         => true,
			'job_id'     => $job_id,
			'run_id'     => $run_id,
			'request_id' => $request_id,
			'message'    => __( 'Đã xếp hàng scan (WP-Cron batch). Làm mới trang để theo dõi tiến độ nếu cron chậm.', 'seoauto-seo-helper' ),
		);
	}

	/**
	 * Strip anything that could look like secrets from DB error text.
	 */
	private function safe_db_message( string $raw ): string {
		$msg = wp_strip_all_tags( $raw );
		$msg = preg_replace( '/[A-Za-z0-9+\/=]{40,}/', '[redacted]', $msg ) ?? $msg;
		return mb_substr( $msg, 0, 200 );
	}

	/**
	 * Cron worker — process at most one job batch per tick.
	 */
	public function process_queue(): void {
		// Never slow frontend: bail if this somehow runs mid-request for visitors.
		if ( ! ( defined( 'DOING_CRON' ) && DOING_CRON ) && ! is_admin() && ! ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		$job = $this->jobs->claim_next( Job_Store::TYPE_AUDIT_SCAN );
		if ( null === $job ) {
			return;
		}

		$job_id = (int) $job['id'];
		$run_id = (int) $job['run_id'];
		$run    = $this->runs->get( $run_id );
		if ( null === $run ) {
			$this->jobs->update(
				$job_id,
				array(
					'status'        => Job_Store::STATUS_FAILED,
					'error_code'    => 'run_missing',
					'error_message' => 'Audit run not found',
					'finished_gmt'  => gmdate( 'Y-m-d H:i:s' ),
				)
			);
			return;
		}

		if ( Audit_Run_Store::STATUS_CANCELLED === ( $run['status'] ?? '' )
			|| Job_Store::STATUS_CANCELLED === ( $job['status'] ?? '' )
		) {
			$this->jobs->update(
				$job_id,
				array(
					'status'       => Job_Store::STATUS_CANCELLED,
					'finished_gmt' => gmdate( 'Y-m-d H:i:s' ),
				)
			);
			return;
		}

		// Mid-job lock: stop mutating but keep data; mark paused/retry later.
		if ( $this->entitlement->is_locked() ) {
			$this->jobs->update(
				$job_id,
				array(
					'status'          => Job_Store::STATUS_RETRYING,
					'error_code'      => 'seoauto_plugin_locked',
					'error_message'   => 'Locked — scan paused; data preserved',
					'locked_until_gmt'=> gmdate( 'Y-m-d H:i:s', time() + 300 ),
				)
			);
			$this->runs->update( $run_id, array( 'status' => Audit_Run_Store::STATUS_PAUSED ) );
			return;
		}

		try {
			$batch  = max( 1, min( 50, (int) ( $job['batch_size'] ?? Audit_Engine::BATCH_SIZE ) ) );
			$result = $this->engine->process_batch( $run, $batch );

			if ( ! empty( $result['cancelled'] ) ) {
				$this->jobs->update(
					$job_id,
					array(
						'status'       => Job_Store::STATUS_CANCELLED,
						'finished_gmt' => gmdate( 'Y-m-d H:i:s' ),
					)
				);
				return;
			}

			$this->jobs->update(
				$job_id,
				array(
					'cursor_id' => (int) $result['cursor'],
					'result_json' => wp_json_encode(
						array(
							'last_processed' => (int) $result['processed'],
							'issues_delta'   => (int) $result['issues_delta'],
						)
					),
				)
			);

			if ( ! empty( $result['done'] ) ) {
				$this->jobs->update(
					$job_id,
					array(
						'status'          => Job_Store::STATUS_COMPLETED,
						'finished_gmt'    => gmdate( 'Y-m-d H:i:s' ),
						'locked_until_gmt'=> null,
					)
				);
				$this->audit->log(
					'audit_scan_completed',
					array(
						'job_id' => $job_id,
						'run_id' => $run_id,
						'status' => 'ok',
					)
				);
				return;
			}

			// More work — re-queue.
			$this->jobs->update(
				$job_id,
				array(
					'status'          => Job_Store::STATUS_QUEUED,
					'locked_until_gmt'=> null,
				)
			);
			wp_schedule_single_event( time() + 5, self::HOOK_PROCESS );
		} catch ( \Throwable $e ) {
			$attempts = (int) ( $job['attempts'] ?? 0 );
			$max      = (int) ( $job['max_attempts'] ?? 5 );
			$safe_msg = mb_substr( wp_strip_all_tags( $e->getMessage() ), 0, 200 );
			if ( $attempts >= $max ) {
				$this->jobs->update(
					$job_id,
					array(
						'status'          => Job_Store::STATUS_FAILED,
						'error_code'      => 'exception',
						'error_message'   => $safe_msg,
						'finished_gmt'    => gmdate( 'Y-m-d H:i:s' ),
						'locked_until_gmt'=> null,
					)
				);
				$this->runs->update(
					$run_id,
					array(
						'status'        => Audit_Run_Store::STATUS_FAILED,
						'error_code'    => 'exception',
						'error_message' => $safe_msg,
						'finished_gmt'  => gmdate( 'Y-m-d H:i:s' ),
					)
				);
				$this->audit->log_error(
					'audit_scan_failed',
					'exception',
					array(
						'job_id'  => $job_id,
						'run_id'  => $run_id,
						'message' => $safe_msg,
					)
				);
			} else {
				$this->jobs->update(
					$job_id,
					array(
						'status'          => Job_Store::STATUS_RETRYING,
						'error_code'      => 'exception',
						'error_message'   => $safe_msg,
						'locked_until_gmt'=> gmdate( 'Y-m-d H:i:s', time() + ( 30 * max( 1, $attempts ) ) ),
					)
				);
			}
		}
	}

	public function cancel_job( int $job_id ): bool {
		$job = $this->jobs->get( $job_id );
		if ( null === $job ) {
			return false;
		}
		$ok = $this->jobs->cancel( $job_id );
		if ( $ok && ! empty( $job['run_id'] ) ) {
			$this->runs->update(
				(int) $job['run_id'],
				array(
					'status'       => Audit_Run_Store::STATUS_CANCELLED,
					'finished_gmt' => gmdate( 'Y-m-d H:i:s' ),
				)
			);
		}
		return $ok;
	}

	public function resume_job( int $job_id ): bool|WP_Error {
		$gate = $this->can_start_scan();
		if ( $gate instanceof WP_Error ) {
			return $gate;
		}
		$job = $this->jobs->get( $job_id );
		if ( null === $job ) {
			return false;
		}
		if ( ! in_array( $job['status'], array( Job_Store::STATUS_CANCELLED, Job_Store::STATUS_RETRYING, Job_Store::STATUS_FAILED, 'paused' ), true )
			&& Audit_Run_Store::STATUS_PAUSED !== (string) ( $this->runs->get( (int) $job['run_id'] )['status'] ?? '' )
		) {
			if ( in_array( $job['status'], array( Job_Store::STATUS_QUEUED, Job_Store::STATUS_RUNNING ), true ) ) {
				return true;
			}
		}
		$this->jobs->update(
			$job_id,
			array(
				'status'          => Job_Store::STATUS_QUEUED,
				'error_code'      => null,
				'error_message'   => null,
				'locked_until_gmt'=> null,
			)
		);
		if ( ! empty( $job['run_id'] ) ) {
			$this->runs->update(
				(int) $job['run_id'],
				array( 'status' => Audit_Run_Store::STATUS_QUEUED )
			);
		}
		wp_schedule_single_event( time() + 3, self::HOOK_PROCESS );
		spawn_cron();
		return true;
	}
}
