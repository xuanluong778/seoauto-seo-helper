<?php
/**
 * Local entitlement cache, lock state, network grace, and refresh checks.
 *
 * Does not deactivate the plugin or delete posts/media/meta.
 * Does not extend grace_until on network failures (backend-only deadline).
 *
 * @package SEOAuto\SEOHelper
 */

declare(strict_types=1);

namespace SEOAuto\SEOHelper\Entitlement;

use SEOAuto\SEOHelper\Connection\Connection_Manager;

final class Entitlement_Manager {

	public const REASON_ACTIVE              = 'active';
	public const REASON_EXPIRED             = 'expired';
	public const REASON_CANCELED            = 'canceled';
	public const REASON_SUSPENDED           = 'suspended';
	public const REASON_REVOKED             = 'revoked';
	public const REASON_DOWNGRADED          = 'downgraded';
	public const REASON_NOT_SUPPORTED       = 'plan_not_supported';
	public const REASON_SITE_LIMIT          = 'site_limit_exceeded';
	public const REASON_NOT_PAIRED          = 'not_paired';
	public const REASON_DENIED              = 'entitlement_denied';
	public const REASON_NETWORK_GRACE       = 'network_grace';
	public const REASON_CONNECTIVITY_LOST   = 'connectivity_lost';

	/** Max offline tolerance when backend omits explicit network_grace_until (cap only at store). */
	public const MAX_NETWORK_GRACE_SECONDS = 172800; // 48 hours.

	/** Actions blocked while LOCKED (automatic / mutating). */
	private const BLOCKED_AUDIT_ACTIONS = array(
		'cron_sync',
		'post_create',
		'post_update',
		'post_upsert',
		'post_schedule',
		'media_ingest',
		'media_sideload',
		'media_dedupe',
		'seo_meta',
	);

	public function __construct(
		private Connection_Manager $connection,
		private ?Entitlement_Client $client = null
	) {}

	/**
	 * @return array<string,mixed>
	 */
	public function get(): array {
		$raw = (string) $this->connection->option( 'entitlement_json', '' );
		if ( $raw === '' ) {
			return array(
				'allowed'              => false,
				'reason'               => self::REASON_NOT_PAIRED,
				'plan_code'            => null,
				'subscription_status'  => null,
				'expires_at'           => null,
				'grace_until'          => null,
				'enabled_features'     => array(),
				'max_sites'            => 0,
			);
		}
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			return array( 'allowed' => false, 'reason' => 'invalid_cache' );
		}
		if ( ! $this->verify_cached_entitlement( $data ) ) {
			return array(
				'allowed'          => false,
				'reason'           => 'invalid_entitlement_sig',
				'enabled_features' => array(),
				'max_sites'        => 0,
			);
		}
		return $data;
	}

	private function verify_cached_entitlement( array $data ): bool {
		if ( ! $this->connection->has_credentials() ) {
			return false;
		}
		$sig = (string) $this->connection->option( 'entitlement_sig', '' );
		if ( $sig === '' ) {
			return false;
		}
		$payload           = $data;
		$payload['signature'] = $sig;
		return Entitlement_Verifier::verify( $payload, $this->connection->site_secret() );
	}

	public function is_allowed(): bool {
		return $this->evaluate()['allowed'];
	}

	public function is_locked(): bool {
		if ( ! $this->connection->has_credentials() ) {
			return false;
		}
		if ( $this->is_network_grace_active() ) {
			return false;
		}
		return Connection_Manager::STATUS_LOCKED === (string) $this->connection->option( 'status', '' )
			|| ! $this->evaluate_cached()['allowed'];
	}

	/**
	 * Publishing / media / SEO mutations require unlocked entitlement.
	 */
	public function can_mutate(): bool {
		if ( ! $this->connection->has_credentials() ) {
			return false;
		}
		if ( $this->is_network_grace_active() ) {
			return true;
		}
		return ! $this->is_locked() && $this->is_allowed();
	}

	public function is_network_grace_active(): bool {
		return 'degraded' === (string) $this->connection->option( 'connectivity_state', '' )
			&& $this->within_network_grace()
			&& $this->connection->option( 'last_entitlement_was_active', '' ) === '1';
	}

	public function has_feature( string $feature ): bool {
		if ( $this->is_locked() ) {
			return false;
		}
		$ent = $this->get();
		$features = $ent['enabled_features'] ?? array();
		if ( ! is_array( $features ) ) {
			return false;
		}
		return in_array( $feature, $features, true );
	}

	/**
	 * Store entitlement snapshot from SaaS (signature kept separately).
	 *
	 * @param array<string,mixed> $entitlement
	 */
	public function store( array $entitlement ): bool {
		$sig = (string) ( $entitlement['signature'] ?? '' );
		$copy = $entitlement;
		if ( ! Entitlement_Verifier::verify( $copy, $this->connection->site_secret() ) ) {
			$this->connection->update_option( 'last_error', __( 'Entitlement signature không hợp lệ.', 'seoauto-seo-helper' ) );
			return false;
		}

		unset( $entitlement['signature'] );
		$this->connection->update_option( 'entitlement_json', wp_json_encode( $entitlement ) );
		$this->connection->update_option( 'entitlement_sig', $sig );
		$this->connection->update_option( 'last_sync_at', gmdate( 'c' ) );
		$this->persist_network_grace_from_payload( $entitlement );
		$this->persist_active_flag_from_payload( $entitlement );
		$this->connection->update_option( 'connectivity_state', 'ok' );
		$this->connection->update_option( 'last_api_error', '' );
		$this->apply_lock_state( 'store' );
		return true;
	}

	/**
	 * Pull from SEOAuto when possible; apply network grace on transient errors.
	 *
	 * @return array{allowed:bool,locked:bool,reason:string,message:string,checked_at:string,network_grace?:bool}
	 */
	public function refresh_check( string $source = 'manual' ): array {
		if ( ! $this->connection->has_credentials() ) {
			$result = $this->apply_lock_state( $source );
			$this->persist_check_meta( $source, $result );
			return $result;
		}

		$client = $this->client ?? new Entitlement_Client( $this->connection );
		$fetch  = $client->fetch();

		if ( ! empty( $fetch['hard_deny'] ) && ! empty( $fetch['entitlement'] ) && is_array( $fetch['entitlement'] ) ) {
			$this->store( $fetch['entitlement'] );
			$result = $this->build_result_from_eval( $this->evaluate_cached(), true );
			$this->persist_check_meta( $source, $result );
			return $result;
		}

		if ( ! empty( $fetch['ok'] ) && ! empty( $fetch['entitlement'] ) && is_array( $fetch['entitlement'] ) ) {
			$this->store( $fetch['entitlement'] );
			$result = $this->build_result_from_eval( $this->evaluate(), false );
			$this->persist_check_meta( $source, $result );
			return $result;
		}

		if ( ! empty( $fetch['network_error'] ) ) {
			$result = $this->handle_network_failure( $fetch, $source );
			$this->persist_check_meta( $source, $result );
			return $result;
		}

		if ( ! empty( $fetch['skipped'] ) ) {
			// Endpoint chưa có — chỉ đánh giá cache local (subscription grace_until).
			$result = $this->apply_lock_state( $source );
			$this->persist_check_meta( $source, $result );
			return $result;
		}

		$this->connection->update_option( 'last_api_error', (string) ( $fetch['message'] ?? '' ) );
		$result = $this->apply_lock_state( $source );
		$this->persist_check_meta( $source, $result );
		return $result;
	}

	/**
	 * @param array{message?:string,http_code?:int} $fetch
	 * @return array{allowed:bool,locked:bool,reason:string,message:string,checked_at:string,network_grace?:bool}
	 */
	private function handle_network_failure( array $fetch, string $source ): array {
		$checked_at = gmdate( 'c' );
		$msg        = (string) ( $fetch['message'] ?? __( 'Không kết nối được SEOAuto.', 'seoauto-seo-helper' ) );
		$this->connection->update_option( 'last_api_error', $msg );

		if ( $this->can_use_network_grace() ) {
			$this->connection->update_option( 'status', Connection_Manager::STATUS_CONNECTED );
			$this->connection->update_option( 'connectivity_state', 'degraded' );
			$this->connection->update_option( 'last_error', '' );
			$until = (string) $this->connection->option( 'network_grace_until', '' );
			return array(
				'allowed'        => true,
				'locked'         => false,
				'reason'         => self::REASON_NETWORK_GRACE,
				'message'        => $this->message_for_reason( self::REASON_NETWORK_GRACE, $until ),
				'checked_at'     => $checked_at,
				'network_grace'  => true,
			);
		}

		$this->connection->update_option( 'connectivity_state', 'lost' );
		$this->connection->update_option( 'status', Connection_Manager::STATUS_LOCKED );
		$this->connection->update_option( 'lock_reason', self::REASON_CONNECTIVITY_LOST );
		$this->connection->update_option(
			'last_error',
			__( 'Mất kết nối SEOAuto — grace period đã hết.', 'seoauto-seo-helper' )
		);

		return array(
			'allowed'    => false,
			'locked'     => true,
			'reason'     => self::REASON_CONNECTIVITY_LOST,
			'message'    => $this->message_for_reason( self::REASON_CONNECTIVITY_LOST ),
			'checked_at' => $checked_at,
		);
	}

	/**
	 * @return array{allowed:bool,locked:bool,reason:string,message:string,checked_at:string}
	 */
	private function apply_lock_state( string $source = 'manual' ): array {
		$eval = $this->evaluate();
		if ( $this->is_network_grace_active() ) {
			$until = (string) $this->connection->option( 'network_grace_until', '' );
			return array(
				'allowed'       => true,
				'locked'        => false,
				'reason'        => self::REASON_NETWORK_GRACE,
				'message'       => $this->message_for_reason( self::REASON_NETWORK_GRACE, $until ),
				'checked_at'    => gmdate( 'c' ),
				'network_grace' => true,
			);
		}
		return $this->build_result_from_eval( $eval, ! $eval['allowed'] );
	}

	/**
	 * @param array{allowed:bool,reason:string,message:string} $eval
	 * @return array{allowed:bool,locked:bool,reason:string,message:string,checked_at:string}
	 */
	private function build_result_from_eval( array $eval, bool $locked ): array {
		$checked_at = gmdate( 'c' );

		if ( ! $this->connection->has_credentials() ) {
			return array(
				'allowed'    => false,
				'locked'     => false,
				'reason'     => self::REASON_NOT_PAIRED,
				'message'    => $this->message_for_reason( self::REASON_NOT_PAIRED ),
				'checked_at' => $checked_at,
			);
		}

		if ( $locked ) {
			$this->connection->update_option( 'status', Connection_Manager::STATUS_LOCKED );
			$this->connection->update_option( 'lock_reason', $eval['reason'] );
			$this->connection->update_option(
				'last_error',
				sprintf(
					/* translators: 1: lock reason */
					__( 'Plugin bị khóa: %s', 'seoauto-seo-helper' ),
					$eval['reason']
				)
			);
			return array(
				'allowed'    => false,
				'locked'     => true,
				'reason'     => $eval['reason'],
				'message'    => $eval['message'],
				'checked_at' => $checked_at,
			);
		}

		$this->connection->update_option( 'status', Connection_Manager::STATUS_CONNECTED );
		$this->connection->update_option( 'lock_reason', '' );
		$this->connection->update_option( 'last_error', '' );

		return array(
			'allowed'    => true,
			'locked'     => false,
			'reason'     => $eval['reason'],
			'message'    => $eval['message'],
			'checked_at' => $checked_at,
		);
	}

	/**
	 * @param array{allowed:bool,locked:bool,reason:string,message:string,checked_at:string,network_grace?:bool} $result
	 */
	private function persist_check_meta( string $source, array $result ): void {
		$this->connection->update_option( 'last_entitlement_check_at', $result['checked_at'] );
		$this->connection->update_option( 'last_entitlement_check_source', sanitize_key( $source ) );
		$this->connection->update_option( 'lock_reason', $result['reason'] );
	}

	/**
	 * @return array{allowed:bool,reason:string,message:string}
	 */
	public function evaluate(): array {
		if ( $this->is_network_grace_active() ) {
			$until = (string) $this->connection->option( 'network_grace_until', '' );
			return array(
				'allowed' => true,
				'reason'  => self::REASON_NETWORK_GRACE,
				'message' => $this->message_for_reason( self::REASON_NETWORK_GRACE, $until ),
			);
		}
		return $this->evaluate_cached();
	}

	/**
	 * Evaluate cached entitlement only (no network grace).
	 *
	 * @return array{allowed:bool,reason:string,message:string}
	 */
	private function evaluate_cached(): array {
		if ( ! $this->connection->has_credentials() ) {
			return array(
				'allowed' => false,
				'reason'  => self::REASON_NOT_PAIRED,
				'message' => $this->message_for_reason( self::REASON_NOT_PAIRED ),
			);
		}

		$ent = $this->get();
		$reason = sanitize_key( (string) ( $ent['reason'] ?? '' ) );

		if ( empty( $ent['allowed'] ) ) {
			$mapped = $this->map_reason( $reason );
			return array(
				'allowed' => false,
				'reason'  => $mapped,
				'message' => $this->message_for_reason( $mapped ),
			);
		}

		$status = strtolower( (string) ( $ent['subscription_status'] ?? '' ) );
		if ( in_array( $status, array( 'canceled', 'cancelled' ), true ) ) {
			return array(
				'allowed' => false,
				'reason'  => self::REASON_CANCELED,
				'message' => $this->message_for_reason( self::REASON_CANCELED ),
			);
		}
		if ( in_array( $status, array( 'suspended', 'paused', 'revoked' ), true ) ) {
			$mapped = $status === 'revoked' ? self::REASON_REVOKED : self::REASON_SUSPENDED;
			return array(
				'allowed' => false,
				'reason'  => $mapped,
				'message' => $this->message_for_reason( $mapped ),
			);
		}
		if ( in_array( $status, array( 'expired' ), true ) || $this->is_past_expiry( $ent ) ) {
			return array(
				'allowed' => false,
				'reason'  => self::REASON_EXPIRED,
				'message' => $this->message_for_reason( self::REASON_EXPIRED ),
			);
		}

		if ( in_array( $reason, array( 'revoked' ), true ) ) {
			return array(
				'allowed' => false,
				'reason'  => self::REASON_REVOKED,
				'message' => $this->message_for_reason( self::REASON_REVOKED ),
			);
		}

		if ( in_array( $reason, array( 'downgraded', 'plan_downgraded' ), true ) ) {
			return array(
				'allowed' => false,
				'reason'  => self::REASON_DOWNGRADED,
				'message' => $this->message_for_reason( self::REASON_DOWNGRADED ),
			);
		}

		if ( in_array( $reason, array( 'plan_not_supported', 'feature_not_supported', 'subscription_inactive' ), true ) ) {
			return array(
				'allowed' => false,
				'reason'  => self::REASON_NOT_SUPPORTED,
				'message' => $this->message_for_reason( self::REASON_NOT_SUPPORTED ),
			);
		}

		if ( $reason === 'site_limit_exceeded' ) {
			return array(
				'allowed' => false,
				'reason'  => self::REASON_SITE_LIMIT,
				'message' => $this->message_for_reason( self::REASON_SITE_LIMIT ),
			);
		}

		if ( ! $this->has_core_feature( $ent ) ) {
			return array(
				'allowed' => false,
				'reason'  => self::REASON_NOT_SUPPORTED,
				'message' => $this->message_for_reason( self::REASON_NOT_SUPPORTED ),
			);
		}

		return array(
			'allowed' => true,
			'reason'  => self::REASON_ACTIVE,
			'message' => $this->message_for_reason( self::REASON_ACTIVE ),
		);
	}

	public function should_log_audit( string $action ): bool {
		if ( ! $this->is_locked() ) {
			return true;
		}
		return ! in_array( sanitize_key( $action ), self::BLOCKED_AUDIT_ACTIONS, true );
	}

	public function upgrade_url(): string {
		$base = $this->connection->api_base();
		return untrailingslashit( $base ) . '/pricing';
	}

	/**
	 * @return array<string,mixed>
	 */
	public function capabilities(): array {
		$ent  = $this->get();
		$eval = $this->evaluate();
		$network_grace = $this->is_network_grace_active();
		return array(
			'plugin'              => 'seoauto-seo-helper',
			'name'                => 'SEOAuto SEO Helper',
			'version'             => SEOAUTO_HELPER_VERSION,
			'connected'           => $this->connection->is_connected(),
			'paired'              => $this->connection->has_credentials(),
			'locked'              => $this->is_locked(),
			'allowed'             => $eval['allowed'],
			'can_mutate'          => $this->can_mutate(),
			'lock_reason'         => $eval['reason'],
			'lock_message'        => $eval['message'],
			'network_grace_active' => $network_grace,
			'network_grace_until' => (string) $this->connection->option( 'network_grace_until', '' ),
			'connectivity_state'  => (string) $this->connection->option( 'connectivity_state', '' ),
			'last_api_error'      => (string) $this->connection->option( 'last_api_error', '' ),
			'plan_code'           => $ent['plan_code'] ?? null,
			'subscription_status' => $ent['subscription_status'] ?? null,
			'expires_at'          => $ent['expires_at'] ?? null,
			'grace_until'         => $ent['grace_until'] ?? null,
			'enabled_features'    => $ent['enabled_features'] ?? array(),
			'upgrade_url'         => $this->upgrade_url(),
			'seo_plugin'          => $this->connection->detect_seo_plugin(),
			'capabilities'        => array(
				'open_graph'    => $this->has_feature( 'open_graph' ) || $eval['allowed'],
				'schema'        => $this->has_feature( 'schema' ) || $eval['allowed'],
				'yoast_sync'    => $this->has_feature( 'yoast_sync' ) || $eval['allowed'],
				'rankmath_sync' => $this->has_feature( 'rankmath_sync' ) || $eval['allowed'],
			),
		);
	}

	private function can_use_network_grace(): bool {
		if ( $this->connection->option( 'last_entitlement_was_active', '' ) !== '1' ) {
			return false;
		}
		if ( ! $this->within_network_grace() ) {
			return false;
		}
		$cached = $this->evaluate_cached();
		return $cached['allowed'] && self::REASON_ACTIVE === $cached['reason'];
	}

	private function within_network_grace(): bool {
		$until = (string) $this->connection->option( 'network_grace_until', '' );
		if ( $until === '' ) {
			return false;
		}
		$ts = strtotime( $until );
		return false !== $ts && time() <= $ts;
	}

	/**
	 * Persist network grace deadline from SaaS payload only (never on failure).
	 *
	 * @param array<string,mixed> $entitlement
	 */
	private function persist_network_grace_from_payload( array $entitlement ): void {
		$raw = (string) ( $entitlement['network_grace_until'] ?? $entitlement['grace_until'] ?? '' );
		if ( $raw === '' ) {
			return;
		}
		$gts = strtotime( $raw );
		if ( false === $gts ) {
			return;
		}

		$issued = (string) ( $entitlement['issued_at'] ?? $this->connection->option( 'last_sync_at', '' ) );
		$its    = $issued !== '' ? strtotime( $issued ) : time();
		if ( false === $its ) {
			$its = time();
		}

		$max_ts = $its + self::MAX_NETWORK_GRACE_SECONDS;
		$final  = min( $gts, $max_ts );

		// Backend sets deadline on each successful sync — never extend on network failure.
		$this->connection->update_option( 'network_grace_until', gmdate( 'c', $final ) );
	}

	/**
	 * @param array<string,mixed> $entitlement
	 */
	private function persist_active_flag_from_payload( array $entitlement ): void {
		$reason = sanitize_key( (string) ( $entitlement['reason'] ?? '' ) );
		$status = strtolower( (string) ( $entitlement['subscription_status'] ?? '' ) );
		$active = ! empty( $entitlement['allowed'] )
			&& ! in_array( $reason, array( 'expired', 'canceled', 'cancelled', 'suspended', 'revoked', 'downgraded' ), true )
			&& ! in_array( $status, array( 'expired', 'canceled', 'cancelled', 'suspended', 'revoked' ), true );
		$this->connection->update_option( 'last_entitlement_was_active', $active ? '1' : '0' );
	}

	/**
	 * @param array<string,mixed> $ent
	 */
	private function has_core_feature( array $ent ): bool {
		$features = $ent['enabled_features'] ?? array();
		if ( ! is_array( $features ) || $features === array() ) {
			return ! empty( $ent['allowed'] );
		}
		return in_array( 'seo_helper', $features, true );
	}

	/**
	 * @param array<string,mixed> $ent
	 */
	private function is_past_expiry( array $ent ): bool {
		$expires = (string) ( $ent['expires_at'] ?? '' );
		if ( $expires === '' ) {
			return false;
		}
		$ts = strtotime( $expires );
		if ( false === $ts || time() <= $ts ) {
			return false;
		}
		$grace = (string) ( $ent['grace_until'] ?? '' );
		if ( $grace !== '' ) {
			$gts = strtotime( $grace );
			if ( false !== $gts && time() <= $gts ) {
				return false;
			}
		}
		return true;
	}

	private function map_reason( string $reason ): string {
		return match ( $reason ) {
			'expired' => self::REASON_EXPIRED,
			'canceled', 'cancelled' => self::REASON_CANCELED,
			'suspended', 'paused' => self::REASON_SUSPENDED,
			'revoked' => self::REASON_REVOKED,
			'downgraded', 'plan_downgraded' => self::REASON_DOWNGRADED,
			'plan_not_supported', 'feature_not_supported', 'subscription_inactive' => self::REASON_NOT_SUPPORTED,
			'site_limit_exceeded' => self::REASON_SITE_LIMIT,
			'not_paired' => self::REASON_NOT_PAIRED,
			default => self::REASON_DENIED,
		};
	}

	private function message_for_reason( string $reason, string $until = '' ): string {
		return match ( $reason ) {
			self::REASON_ACTIVE => __( 'Gói SEOAuto đang hoạt động.', 'seoauto-seo-helper' ),
			self::REASON_EXPIRED => __( 'Gói đã hết hạn. Plugin LOCKED — không đăng bài hoặc upload. Đây là lỗi gói dịch vụ, không phải mất kết nối mạng.', 'seoauto-seo-helper' ),
			self::REASON_CANCELED => __( 'Gói đã hủy. Plugin LOCKED — nâng cấp trên SEOAuto để mở khóa.', 'seoauto-seo-helper' ),
			self::REASON_SUSPENDED => __( 'Gói bị tạm ngưng. Plugin LOCKED.', 'seoauto-seo-helper' ),
			self::REASON_REVOKED => __( 'Quyền truy cập bị thu hồi (revoked). Plugin LOCKED ngay lập tức.', 'seoauto-seo-helper' ),
			self::REASON_DOWNGRADED => __( 'Gói bị hạ cấp, không còn hỗ trợ SEO Helper. Plugin LOCKED.', 'seoauto-seo-helper' ),
			self::REASON_NOT_SUPPORTED => __( 'Gói hiện tại không hỗ trợ SEO Helper. Plugin LOCKED.', 'seoauto-seo-helper' ),
			self::REASON_SITE_LIMIT => __( 'Vượt giới hạn số website trên gói. Plugin LOCKED.', 'seoauto-seo-helper' ),
			self::REASON_NOT_PAIRED => __( 'Chưa ghép nối SEOAuto.', 'seoauto-seo-helper' ),
			self::REASON_NETWORK_GRACE => $until !== ''
				? sprintf(
					/* translators: %s: ISO8601 grace deadline */
					__( 'Mất kết nối SEOAuto tạm thời — vẫn dùng gói đã cache đến %s. Đây không phải gói hết hạn.', 'seoauto-seo-helper' ),
					$until
				)
				: __( 'Mất kết nối SEOAuto tạm thời — đang trong grace period (tối đa 48 giờ). Đây không phải gói hết hạn.', 'seoauto-seo-helper' ),
			self::REASON_CONNECTIVITY_LOST => __( 'Mất kết nối SEOAuto quá lâu (grace period đã hết). Plugin LOCKED — kiểm tra mạng hoặc liên hệ SEOAuto.', 'seoauto-seo-helper' ),
			default => __( 'Entitlement không cho phép. Plugin LOCKED.', 'seoauto-seo-helper' ),
		};
	}
}
