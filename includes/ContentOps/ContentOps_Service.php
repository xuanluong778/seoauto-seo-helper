<?php
/**
 * ContentOps orchestrator: Preview → Backup → Apply → Recheck → Rollback.
 *
 * @package SEOAuto\SEOHelper
 */

declare(strict_types=1);

namespace SEOAuto\SEOHelper\ContentOps;

use SEOAuto\SEOHelper\Audit\Audit_Logger;
use SEOAuto\SEOHelper\Connection\Connection_Manager;
use SEOAuto\SEOHelper\ContentOps\Stores\Backup_Store;
use SEOAuto\SEOHelper\ContentOps\Stores\Batch_Store;
use SEOAuto\SEOHelper\ContentOps\Stores\Change_Store;
use SEOAuto\SEOHelper\Entitlement\Entitlement_Manager;
use SEOAuto\SEOHelper\Seo\Seo_Facade;
use WP_Error;

final class ContentOps_Service {

	public const FEATURE         = 'content_ops';
	public const RETENTION_DAYS  = 30;
	public const LOCK_OWNER_PREF = 'co_';

	private Batch_Store $batches;
	private Change_Store $changes;
	private Backup_Store $backups;
	private Snapshot_Builder $snapshots;
	private Preview_Service $preview;
	private Restore_Service $restore;
	private Content_Lock $locks;

	public function __construct(
		private Connection_Manager $connection,
		private Entitlement_Manager $entitlement,
		private Seo_Facade $seo,
		private Audit_Logger $audit
	) {
		$this->batches   = new Batch_Store();
		$this->changes   = new Change_Store();
		$this->backups   = new Backup_Store();
		$this->snapshots = new Snapshot_Builder( $this->seo );
		$this->preview   = new Preview_Service( $this->snapshots );
		$this->restore   = new Restore_Service( $this->snapshots, $this->seo );
		$this->locks     = new Content_Lock();
	}

	/**
	 * Read-only preview — never mutates.
	 *
	 * @param array<string,mixed> $body
	 * @return array<string,mixed>|WP_Error
	 */
	public function preview( array $body ): array|WP_Error {
		$gate = $this->assert_can_use();
		if ( $gate instanceof WP_Error ) {
			return $gate;
		}
		$items = $this->normalize_items( $body );
		if ( $items instanceof WP_Error ) {
			return $items;
		}
		$result = $this->preview->preview_batch( $items );
		$this->audit->log(
			'content_preview',
			array(
				'request_id' => (string) ( $body['request_id'] ?? '' ),
				'status'     => 'ok',
				'total'      => $result['summary']['total'] ?? 0,
			)
		);
		return $result;
	}

	/**
	 * Create/reuse batch, run preview, create backups. Backup failure blocks apply.
	 *
	 * @param array<string,mixed> $body
	 * @return array<string,mixed>|WP_Error
	 */
	public function backup( array $body ): array|WP_Error {
		$gate = $this->assert_can_mutate();
		if ( $gate instanceof WP_Error ) {
			return $gate;
		}

		$request_id = $this->require_request_id( $body );
		if ( $request_id instanceof WP_Error ) {
			return $request_id;
		}

		$existing = $this->batches->get_by_request_id( $request_id );
		if ( null !== $existing ) {
			return $this->public_batch( (int) $existing['id'] );
		}

		$items = $this->normalize_items( $body );
		if ( $items instanceof WP_Error ) {
			return $items;
		}

		$conn_id = (int) $this->connection->option( 'connection_id', 0 );
		$org_id  = (int) $this->connection->option( 'organization_id', 0 );
		$user_sc = sanitize_text_field( (string) ( $body['user_scope'] ?? '' ) );

		$batch_id = $this->batches->insert(
			array(
				'request_id'      => $request_id,
				'connection_id'   => $conn_id,
				'organization_id' => $org_id,
				'user_scope'      => $user_sc,
				'status'          => 'backing_up',
				'total_items'     => count( $items ),
				'payload'         => array( 'item_count' => count( $items ) ),
			)
		);

		$failed = 0;
		foreach ( $items as $item ) {
			$post_id  = (int) $item['post_id'];
			$proposed = is_array( $item['proposed'] ?? null ) ? $item['proposed'] : array();
			$reason   = (string) ( $item['reason'] ?? '' );
			$idem     = (string) ( $item['idempotency_key'] ?? ( 'post_' . $post_id ) );

			$prev = $this->preview->preview_item( $post_id, $proposed, $reason );
			if ( $prev instanceof WP_Error ) {
				++$failed;
				$this->changes->insert(
					array(
						'batch_id'        => $batch_id,
						'connection_id'   => $conn_id,
						'post_id'         => $post_id,
						'idempotency_key' => $idem,
						'status'          => 'failed',
						'reason'          => $reason,
						'proposed'        => $proposed,
						'error_code'      => $prev->get_error_code(),
						'error_message'   => $prev->get_error_message(),
					)
				);
				continue;
			}

			$snap = $this->snapshots->capture( $post_id );
			if ( $snap instanceof WP_Error ) {
				++$failed;
				$this->changes->insert(
					array(
						'batch_id'        => $batch_id,
						'connection_id'   => $conn_id,
						'post_id'         => $post_id,
						'idempotency_key' => $idem,
						'status'          => 'backup_failed',
						'risk_level'      => (string) ( $prev['risk_level'] ?? 'safe' ),
						'reason'          => $reason,
						'proposed'        => $proposed,
						'preview'         => $prev,
						'error_code'      => $snap->get_error_code(),
						'error_message'   => $snap->get_error_message(),
					)
				);
				continue;
			}

			$change_id = $this->changes->insert(
				array(
					'batch_id'        => $batch_id,
					'connection_id'   => $conn_id,
					'post_id'         => $post_id,
					'idempotency_key' => $idem,
					'status'          => 'pending_backup',
					'risk_level'      => (string) ( $prev['risk_level'] ?? 'safe' ),
					'reason'          => $reason,
					'proposed'        => $proposed,
					'preview'         => $prev,
					'before_checksum' => (string) ( $snap['checksum'] ?? '' ),
				)
			);

			$backup_id = $this->backups->insert(
				$batch_id,
				$change_id,
				$conn_id,
				$post_id,
				(string) ( $snap['checksum'] ?? '' ),
				$snap,
				(string) ( $snap['seo']['adapter'] ?? '' ),
				self::RETENTION_DAYS
			);

			if ( $backup_id <= 0 ) {
				++$failed;
				$this->changes->update(
					$change_id,
					array(
						'status'        => 'backup_failed',
						'error_code'    => 'seoauto_backup_insert_failed',
						'error_message' => __( 'Không lưu được backup.', 'seoauto-seo-helper' ),
					)
				);
				continue;
			}

			$this->changes->update(
				$change_id,
				array(
					'status'    => 'backed_up',
					'backup_id' => $backup_id,
				)
			);
		}

		$status = $failed > 0 ? 'backup_failed' : 'backed_up';
		$this->batches->update(
			$batch_id,
			array(
				'status'       => $status,
				'failed_items' => $failed,
				'result'       => array(
					'backup_ok'     => count( $items ) - $failed,
					'backup_failed' => $failed,
					'apply_blocked' => $failed > 0,
				),
			)
		);

		$this->audit->log(
			'content_backup',
			array(
				'request_id' => $request_id,
				'batch_id'   => $batch_id,
				'status'     => $status,
				'failed'     => $failed,
			)
		);

		return $this->public_batch( $batch_id );
	}

	/**
	 * Apply confirmed changes. Requires successful backups for every item.
	 *
	 * @param array<string,mixed> $body
	 * @return array<string,mixed>|WP_Error
	 */
	public function apply( array $body ): array|WP_Error {
		$gate = $this->assert_can_mutate();
		if ( $gate instanceof WP_Error ) {
			return $gate;
		}

		$batch = $this->load_owned_batch( $body );
		if ( $batch instanceof WP_Error ) {
			return $batch;
		}
		$batch_id = (int) $batch['id'];

		if ( in_array( (string) $batch['status'], array( 'applied', 'rechecked', 'rolled_back' ), true ) ) {
			// Idempotent replay.
			return $this->public_batch( $batch_id );
		}

		if ( (string) $batch['status'] === 'backup_failed' || (int) ( $batch['failed_items'] ?? 0 ) > 0 ) {
			return new WP_Error(
				'seoauto_apply_blocked_backup',
				__( 'Backup thất bại — Apply bị chặn.', 'seoauto-seo-helper' ),
				array( 'status' => 409 )
			);
		}

		$change_rows = $this->changes->list_for_batch( $batch_id );
		foreach ( $change_rows as $row ) {
			if ( (string) ( $row['status'] ?? '' ) !== 'backed_up' || (int) ( $row['backup_id'] ?? 0 ) <= 0 ) {
				return new WP_Error(
					'seoauto_apply_blocked_backup',
					__( 'Mọi thay đổi phải có backup thành công trước khi Apply.', 'seoauto-seo-helper' ),
					array( 'status' => 409 )
				);
			}
		}

		$only_ids = array();
		if ( isset( $body['change_ids'] ) && is_array( $body['change_ids'] ) ) {
			$only_ids = array_map( 'intval', $body['change_ids'] );
		}

		$this->batches->update( $batch_id, array( 'status' => 'applying' ) );
		$processed = 0;
		$failed    = 0;

		foreach ( $change_rows as $row ) {
			$cid = (int) $row['id'];
			if ( $only_ids !== array() && ! in_array( $cid, $only_ids, true ) ) {
				continue;
			}
			if ( in_array( (string) $row['status'], array( 'applied', 'rechecked', 'rolled_back' ), true ) ) {
				++$processed;
				continue; // idempotent skip
			}

			$result = $this->apply_one( $row );
			if ( $result instanceof WP_Error ) {
				++$failed;
				$this->changes->update(
					$cid,
					array(
						'status'        => 'failed',
						'error_code'    => $result->get_error_code(),
						'error_message' => $result->get_error_message(),
						'attempts'      => (int) $row['attempts'] + 1,
					)
				);
			} else {
				++$processed;
			}
		}

		$status = $failed > 0 ? ( $processed > 0 ? 'partial' : 'failed' ) : 'applied';
		$this->batches->update(
			$batch_id,
			array(
				'status'          => $status,
				'processed_items' => $processed,
				'failed_items'    => $failed,
			)
		);

		$this->audit->log(
			'content_apply',
			array(
				'batch_id'  => $batch_id,
				'status'    => $status,
				'processed' => $processed,
				'failed'    => $failed,
			)
		);

		return $this->public_batch( $batch_id );
	}

	/**
	 * Recheck actual post state vs expected after apply.
	 *
	 * @param array<string,mixed> $body
	 * @return array<string,mixed>|WP_Error
	 */
	public function recheck( array $body ): array|WP_Error {
		$gate = $this->assert_can_use();
		if ( $gate instanceof WP_Error ) {
			return $gate;
		}

		$batch = $this->load_owned_batch( $body );
		if ( $batch instanceof WP_Error ) {
			return $batch;
		}
		$batch_id = (int) $batch['id'];
		$rows     = $this->changes->list_for_batch( $batch_id );
		$ok       = 0;
		$bad      = 0;

		foreach ( $rows as $row ) {
			if ( ! in_array( (string) $row['status'], array( 'applied', 'rechecked', 'recheck_failed' ), true ) ) {
				continue;
			}
			$post_id  = (int) $row['post_id'];
			$proposed = json_decode( (string) ( $row['proposed_json'] ?? '' ), true );
			if ( ! is_array( $proposed ) ) {
				$proposed = array();
			}
			$now = $this->snapshots->capture( $post_id );
			if ( $now instanceof WP_Error ) {
				++$bad;
				$this->changes->update(
					(int) $row['id'],
					array(
						'status'        => 'recheck_failed',
						'error_code'    => $now->get_error_code(),
						'error_message' => $now->get_error_message(),
					)
				);
				continue;
			}

			$mismatches = $this->compare_expected( $now, $proposed );
			$pass       = $mismatches === array()
				&& (string) ( $row['after_checksum'] ?? '' ) !== ''
				&& hash_equals( (string) $row['after_checksum'], (string) ( $now['checksum'] ?? '' ) );

			// Soft pass: if after_checksum matches current, ignore field-level noise.
			if ( ! $pass && (string) ( $row['after_checksum'] ?? '' ) !== ''
				&& hash_equals( (string) $row['after_checksum'], (string) ( $now['checksum'] ?? '' ) ) ) {
				$pass = true;
			}

			// Field-level verification when checksum drifted due to WP side effects.
			if ( ! $pass ) {
				$pass = $mismatches === array();
			}

			if ( $pass ) {
				++$ok;
				$this->changes->update(
					(int) $row['id'],
					array(
						'status'  => 'rechecked',
						'recheck' => array(
							'ok'       => true,
							'checksum' => $now['checksum'],
						),
					)
				);
			} else {
				++$bad;
				$this->changes->update(
					(int) $row['id'],
					array(
						'status'  => 'recheck_failed',
						'recheck' => array(
							'ok'          => false,
							'checksum'    => $now['checksum'],
							'mismatches'  => $mismatches,
						),
						'error_code'    => 'seoauto_recheck_mismatch',
						'error_message' => __( 'Recheck thất bại — dữ liệu thực tế không khớp.', 'seoauto-seo-helper' ),
					)
				);
			}
		}

		$status = $bad > 0 ? 'recheck_failed' : 'rechecked';
		$this->batches->update( $batch_id, array( 'status' => $status ) );
		$this->audit->log(
			'content_recheck',
			array(
				'batch_id' => $batch_id,
				'status'   => $status,
				'ok'       => $ok,
				'failed'   => $bad,
			)
		);
		return $this->public_batch( $batch_id );
	}

	/**
	 * Rollback one change, list of changes, or whole batch.
	 *
	 * @param array<string,mixed> $body
	 * @return array<string,mixed>|WP_Error
	 */
	public function rollback( array $body ): array|WP_Error {
		$gate = $this->assert_can_mutate();
		if ( $gate instanceof WP_Error ) {
			return $gate;
		}

		$batch = $this->load_owned_batch( $body );
		if ( $batch instanceof WP_Error ) {
			return $batch;
		}
		$batch_id = (int) $batch['id'];
		$rows     = $this->changes->list_for_batch( $batch_id );

		$only_ids = array();
		if ( isset( $body['change_ids'] ) && is_array( $body['change_ids'] ) ) {
			$only_ids = array_map( 'intval', $body['change_ids'] );
		} elseif ( isset( $body['change_id'] ) ) {
			$only_ids = array( (int) $body['change_id'] );
		}

		$dry_run = ! empty( $body['preview_only'] );
		$preview = array();
		$ok      = 0;
		$failed  = 0;
		$conflict = 0;

		$this->batches->update( $batch_id, array( 'status' => $dry_run ? (string) $batch['status'] : 'rolling_back' ) );

		foreach ( $rows as $row ) {
			$cid = (int) $row['id'];
			if ( $only_ids !== array() && ! in_array( $cid, $only_ids, true ) ) {
				continue;
			}
			if ( ! in_array( (string) $row['status'], array( 'applied', 'rechecked', 'recheck_failed', 'partial', 'failed' ), true )
				&& (int) ( $row['backup_id'] ?? 0 ) <= 0 ) {
				continue;
			}
			if ( (string) $row['status'] === 'rolled_back' ) {
				++$ok;
				continue;
			}

			$backup_id = (int) ( $row['backup_id'] ?? 0 );
			$payload   = $this->backups->payload( $backup_id );
			if ( null === $payload ) {
				++$failed;
				if ( ! $dry_run ) {
					$this->changes->update(
						$cid,
						array(
							'status'        => 'rollback_failed',
							'error_code'    => 'seoauto_backup_missing',
							'error_message' => __( 'Không tìm thấy backup để rollback.', 'seoauto-seo-helper' ),
						)
					);
				}
				continue;
			}

			$post_id = (int) $row['post_id'];
			$now     = $this->snapshots->capture( $post_id );
			if ( $now instanceof WP_Error ) {
				++$failed;
				continue;
			}

			$after = (string) ( $row['after_checksum'] ?? '' );
			$conflict_detected = $after !== '' && ! hash_equals( $after, (string) ( $now['checksum'] ?? '' ) );
			$force = ! empty( $body['force'] );

			$item_preview = array(
				'change_id'          => $cid,
				'post_id'            => $post_id,
				'backup_id'          => $backup_id,
				'backup_checksum'    => (string) ( $payload['checksum'] ?? '' ),
				'current_checksum'   => (string) ( $now['checksum'] ?? '' ),
				'after_apply_checksum'=> $after,
				'conflict'           => $conflict_detected,
				'title'              => (string) ( $now['title'] ?? '' ),
			);

			if ( $dry_run ) {
				$preview[] = $item_preview;
				continue;
			}

			if ( $conflict_detected && ! $force ) {
				++$conflict;
				$this->changes->update(
					$cid,
					array(
						'status'        => 'conflict',
						'error_code'    => 'seoauto_rollback_conflict',
						'error_message' => __( 'Bài đã thay đổi sau Apply — rollback bị chặn (dùng force=true nếu chắc chắn).', 'seoauto-seo-helper' ),
						'recheck'       => $item_preview,
					)
				);
				continue;
			}

			$owner = self::LOCK_OWNER_PREF . $cid . '_' . wp_generate_password( 8, false );
			$lock  = $this->locks->acquire( $post_id, (int) $row['connection_id'], $batch_id, $cid, $owner );
			if ( $lock instanceof WP_Error ) {
				++$failed;
				$this->changes->update(
					$cid,
					array(
						'status'        => 'rollback_failed',
						'error_code'    => $lock->get_error_code(),
						'error_message' => $lock->get_error_message(),
					)
				);
				continue;
			}

			$restored = $this->restore->restore_full( $post_id, $payload );
			$this->locks->release( $post_id, $owner );

			if ( $restored instanceof WP_Error ) {
				++$failed;
				$this->changes->update(
					$cid,
					array(
						'status'        => 'rollback_failed',
						'error_code'    => $restored->get_error_code(),
						'error_message' => $restored->get_error_message(),
					)
				);
				continue;
			}

			++$ok;
			$this->changes->update(
				$cid,
				array(
					'status'  => 'rolled_back',
					'recheck' => $item_preview,
				)
			);
		}

		if ( $dry_run ) {
			return array(
				'batch_id'      => $batch_id,
				'preview_only'  => true,
				'items'         => $preview,
			);
		}

		$status = $conflict > 0 ? 'conflict' : ( $failed > 0 ? 'rollback_failed' : 'rolled_back' );
		$this->batches->update(
			$batch_id,
			array(
				'status'       => $status,
				'failed_items' => $failed + $conflict,
			)
		);
		$this->audit->log(
			'content_rollback',
			array(
				'batch_id'  => $batch_id,
				'status'    => $status,
				'ok'        => $ok,
				'failed'    => $failed,
				'conflict'  => $conflict,
			)
		);
		return $this->public_batch( $batch_id );
	}

	/**
	 * @return array<string,mixed>|WP_Error
	 */
	public function get_batch( int $batch_id ): array|WP_Error {
		$batch = $this->batches->get( $batch_id );
		if ( null === $batch ) {
			return new WP_Error( 'seoauto_batch_not_found', __( 'Batch không tồn tại.', 'seoauto-seo-helper' ), array( 'status' => 404 ) );
		}
		if ( ! $this->owns_batch( $batch ) ) {
			return new WP_Error( 'seoauto_forbidden', __( 'Không có quyền truy cập batch này.', 'seoauto-seo-helper' ), array( 'status' => 403 ) );
		}
		return $this->public_batch( $batch_id );
	}

	public function purge_expired(): array {
		$backups = $this->backups->purge_expired();
		$locks   = $this->locks->purge_expired();
		$this->audit->log(
			'content_retention_purge',
			array(
				'status'          => 'ok',
				'backups_deleted' => $backups,
				'locks_deleted'   => $locks,
			)
		);
		return array(
			'backups_deleted' => $backups,
			'locks_deleted'   => $locks,
		);
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	public function recent_batches( int $limit = 20 ): array {
		$conn = (int) $this->connection->option( 'connection_id', 0 );
		$rows = $this->batches->recent_for_connection( $conn, $limit );
		$out  = array();
		foreach ( $rows as $row ) {
			$out[] = array(
				'id'              => (int) $row['id'],
				'request_id'      => (string) $row['request_id'],
				'status'          => (string) $row['status'],
				'total_items'     => (int) $row['total_items'],
				'processed_items' => (int) $row['processed_items'],
				'failed_items'    => (int) $row['failed_items'],
				'created_gmt'     => (string) $row['created_gmt'],
				'updated_gmt'     => (string) $row['updated_gmt'],
			);
		}
		return $out;
	}

	/**
	 * @param array<string,mixed> $row
	 * @return true|WP_Error
	 */
	private function apply_one( array $row ): bool|WP_Error {
		$cid     = (int) $row['id'];
		$post_id = (int) $row['post_id'];
		$batch_id = (int) $row['batch_id'];
		$proposed = json_decode( (string) ( $row['proposed_json'] ?? '' ), true );
		if ( ! is_array( $proposed ) ) {
			return new WP_Error( 'seoauto_invalid_proposed', __( 'Proposed payload không hợp lệ.', 'seoauto-seo-helper' ) );
		}

		// Conflict if post changed since backup.
		$now = $this->snapshots->capture( $post_id );
		if ( $now instanceof WP_Error ) {
			return $now;
		}
		$before = (string) ( $row['before_checksum'] ?? '' );
		if ( $before !== '' && ! hash_equals( $before, (string) ( $now['checksum'] ?? '' ) ) ) {
			return new WP_Error(
				'seoauto_apply_conflict',
				__( 'Bài đã thay đổi từ lúc backup — Apply bị chặn.', 'seoauto-seo-helper' ),
				array( 'status' => 409 )
			);
		}

		$owner = self::LOCK_OWNER_PREF . $cid . '_' . wp_generate_password( 8, false );
		$lock  = $this->locks->acquire( $post_id, (int) $row['connection_id'], $batch_id, $cid, $owner );
		if ( $lock instanceof WP_Error ) {
			return $lock;
		}

		$applied = $this->restore->apply_proposed( $post_id, $proposed );
		if ( $applied instanceof WP_Error ) {
			$this->locks->release( $post_id, $owner );
			return $applied;
		}

		$after = $this->snapshots->capture( $post_id );
		$this->locks->release( $post_id, $owner );
		if ( $after instanceof WP_Error ) {
			return $after;
		}

		$this->changes->update(
			$cid,
			array(
				'status'         => 'applied',
				'after_checksum' => (string) ( $after['checksum'] ?? '' ),
				'attempts'       => (int) $row['attempts'] + 1,
				'error_code'     => null,
				'error_message'  => null,
			)
		);
		return true;
	}

	/**
	 * @param array<string,mixed> $now
	 * @param array<string,mixed> $proposed
	 * @return list<string>
	 */
	private function compare_expected( array $now, array $proposed ): array {
		$mismatches = array();
		$map        = array(
			'title'   => 'title',
			'content' => 'content',
			'excerpt' => 'excerpt',
			'slug'    => 'slug',
			'status'  => 'status',
		);
		foreach ( $map as $pkey => $ckey ) {
			if ( ! array_key_exists( $pkey, $proposed ) ) {
				continue;
			}
			if ( (string) ( $now[ $ckey ] ?? '' ) !== (string) $proposed[ $pkey ] ) {
				$mismatches[] = $pkey;
			}
		}
		if ( isset( $proposed['featured_image_id'] )
			&& (int) ( $now['featured_image_id'] ?? 0 ) !== (int) $proposed['featured_image_id'] ) {
			$mismatches[] = 'featured_image_id';
		}
		if ( isset( $proposed['seo'] ) && is_array( $proposed['seo'] ) ) {
			$seo_now = is_array( $now['seo'] ?? null ) ? $now['seo'] : array();
			foreach ( array( 'title', 'description', 'focus_keyword', 'canonical' ) as $sk ) {
				if ( ! array_key_exists( $sk, $proposed['seo'] ) ) {
					continue;
				}
				if ( (string) ( $seo_now[ $sk ] ?? '' ) !== (string) $proposed['seo'][ $sk ] ) {
					$mismatches[] = 'seo.' . $sk;
				}
			}
		}
		return $mismatches;
	}

	/**
	 * @param array<string,mixed> $body
	 * @return array<string,mixed>|WP_Error
	 */
	private function load_owned_batch( array $body ): array|WP_Error {
		$batch_id = (int) ( $body['batch_id'] ?? 0 );
		if ( $batch_id <= 0 && ! empty( $body['request_id'] ) ) {
			$found = $this->batches->get_by_request_id( (string) $body['request_id'] );
			if ( null !== $found ) {
				$batch_id = (int) $found['id'];
			}
		}
		if ( $batch_id <= 0 ) {
			return new WP_Error( 'seoauto_batch_required', __( 'Thiếu batch_id hoặc request_id.', 'seoauto-seo-helper' ), array( 'status' => 400 ) );
		}
		$batch = $this->batches->get( $batch_id );
		if ( null === $batch ) {
			return new WP_Error( 'seoauto_batch_not_found', __( 'Batch không tồn tại.', 'seoauto-seo-helper' ), array( 'status' => 404 ) );
		}
		if ( ! $this->owns_batch( $batch ) ) {
			return new WP_Error( 'seoauto_forbidden', __( 'Không có quyền truy cập batch này.', 'seoauto-seo-helper' ), array( 'status' => 403 ) );
		}
		return $batch;
	}

	/**
	 * @param array<string,mixed> $batch
	 */
	private function owns_batch( array $batch ): bool {
		$conn = (int) $this->connection->option( 'connection_id', 0 );
		return $conn > 0 && (int) ( $batch['connection_id'] ?? 0 ) === $conn;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function public_batch( int $batch_id ): array {
		$batch = $this->batches->get( $batch_id );
		$rows  = $this->changes->list_for_batch( $batch_id );
		$items = array();
		foreach ( $rows as $row ) {
			$items[] = array(
				'change_id'       => (int) $row['id'],
				'post_id'         => (int) $row['post_id'],
				'status'          => (string) $row['status'],
				'risk_level'      => (string) $row['risk_level'],
				'reason'          => (string) ( $row['reason'] ?? '' ),
				'backup_id'       => (int) $row['backup_id'],
				'before_checksum' => (string) $row['before_checksum'],
				'after_checksum'  => (string) $row['after_checksum'],
				'attempts'        => (int) $row['attempts'],
				'error_code'      => $row['error_code'],
				'error_message'   => $row['error_message'],
			);
		}
		$result = json_decode( (string) ( $batch['result_json'] ?? '' ), true );
		return array(
			'batch_id'        => (int) ( $batch['id'] ?? $batch_id ),
			'request_id'      => (string) ( $batch['request_id'] ?? '' ),
			'status'          => (string) ( $batch['status'] ?? '' ),
			'total_items'     => (int) ( $batch['total_items'] ?? 0 ),
			'processed_items' => (int) ( $batch['processed_items'] ?? 0 ),
			'failed_items'    => (int) ( $batch['failed_items'] ?? 0 ),
			'apply_blocked'   => (string) ( $batch['status'] ?? '' ) === 'backup_failed'
				|| ( is_array( $result ) && ! empty( $result['apply_blocked'] ) ),
			'result'          => is_array( $result ) ? $result : null,
			'items'           => $items,
			'created_gmt'     => (string) ( $batch['created_gmt'] ?? '' ),
			'updated_gmt'     => (string) ( $batch['updated_gmt'] ?? '' ),
			'expires_gmt'     => (string) ( $batch['expires_gmt'] ?? '' ),
		);
	}

	/**
	 * @param array<string,mixed> $body
	 * @return list<array<string,mixed>>|WP_Error
	 */
	private function normalize_items( array $body ): array|WP_Error {
		$items = $body['items'] ?? null;
		if ( ! is_array( $items ) || $items === array() ) {
			// Single-item shorthand.
			if ( isset( $body['post_id'] ) ) {
				$items = array(
					array(
						'post_id'         => (int) $body['post_id'],
						'proposed'        => is_array( $body['proposed'] ?? null ) ? $body['proposed'] : array(),
						'reason'          => (string) ( $body['reason'] ?? '' ),
						'idempotency_key' => (string) ( $body['idempotency_key'] ?? '' ),
					),
				);
			} else {
				return new WP_Error( 'seoauto_items_required', __( 'Thiếu danh sách items.', 'seoauto-seo-helper' ), array( 'status' => 400 ) );
			}
		}
		if ( count( $items ) > 100 ) {
			return new WP_Error( 'seoauto_batch_too_large', __( 'Tối đa 100 bài mỗi batch.', 'seoauto-seo-helper' ), array( 'status' => 400 ) );
		}
		$out = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$pid = (int) ( $item['post_id'] ?? 0 );
			if ( $pid <= 0 ) {
				continue;
			}
			$out[] = $item;
		}
		if ( $out === array() ) {
			return new WP_Error( 'seoauto_items_required', __( 'Không có post_id hợp lệ.', 'seoauto-seo-helper' ), array( 'status' => 400 ) );
		}
		return $out;
	}

	/**
	 * @param array<string,mixed> $body
	 */
	private function require_request_id( array $body ): string|WP_Error {
		$id = sanitize_text_field( (string) ( $body['request_id'] ?? '' ) );
		if ( $id === '' || strlen( $id ) > 128 ) {
			return new WP_Error( 'seoauto_request_id_required', __( 'Thiếu request_id (idempotency).', 'seoauto-seo-helper' ), array( 'status' => 400 ) );
		}
		return $id;
	}

	private function assert_can_use(): bool|WP_Error {
		if ( ! $this->connection->has_credentials() ) {
			return new WP_Error( 'seoauto_not_connected', __( 'Plugin chưa kết nối SEOAuto.', 'seoauto-seo-helper' ), array( 'status' => 401 ) );
		}
		if ( $this->entitlement->is_locked() ) {
			return new WP_Error( 'seoauto_locked', __( 'Plugin đang LOCKED.', 'seoauto-seo-helper' ), array( 'status' => 403 ) );
		}
		if ( ! $this->entitlement->has_feature( self::FEATURE ) ) {
			return new WP_Error(
				'seoauto_feature_denied',
				__( 'Thiếu feature: content_ops (cấp từ SEOAuto entitlement).', 'seoauto-seo-helper' ),
				array( 'status' => 403 )
			);
		}
		return true;
	}

	private function assert_can_mutate(): bool|WP_Error {
		$use = $this->assert_can_use();
		if ( $use instanceof WP_Error ) {
			return $use;
		}
		if ( ! $this->entitlement->can_mutate() ) {
			return new WP_Error( 'seoauto_locked', __( 'Không được phép thay đổi dữ liệu (LOCKED).', 'seoauto-seo-helper' ), array( 'status' => 403 ) );
		}
		return true;
	}
}
