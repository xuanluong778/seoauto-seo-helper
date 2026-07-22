<?php
/**
 * REST API under /wp-json/seoauto/v1/*
 *
 * Every route uses a real permission_callback (HMAC + rate limit + entitlement).
 * Never uses __return_true.
 *
 * @package SEOAuto\SEOHelper
 */

declare(strict_types=1);

namespace SEOAuto\SEOHelper\Rest;

use SEOAuto\SEOHelper\Audit\Audit_Logger;
use SEOAuto\SEOHelper\Auth\Request_Authenticator;
use SEOAuto\SEOHelper\Connection\Connection_Manager;
use SEOAuto\SEOHelper\Entitlement\Entitlement_Manager;
use SEOAuto\SEOHelper\Media\Media_Service;
use SEOAuto\SEOHelper\Post\Post_Service;
use SEOAuto\SEOHelper\Post\Publishing_Settings;
use SEOAuto\SEOHelper\Seo\Seo_Facade;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class Rest_Controller {

	public const REST_NAMESPACE = 'seoauto/v1';

	public function __construct(
		private Connection_Manager $connection,
		private Entitlement_Manager $entitlement,
		private Request_Authenticator $auth,
		private Post_Service $posts,
		private Media_Service $media,
		private Seo_Facade $seo,
		private Audit_Logger $audit,
		private Publishing_Settings $publishing
	) {}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		// Connection lifecycle — HMAC required; entitlement optional so revoke/disconnect still works.
		$this->route(
			'/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'status' ),
				'permission_callback' => $this->auth->permission(
					array(
						'require_connected'   => true,
						'require_entitlement' => false,
					)
				),
			)
		);

		$this->route(
			'/connect',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'connect' ),
				'permission_callback' => $this->auth->permission(
					array(
						'require_connected'   => true,
						'require_entitlement' => false,
					)
				),
				'args'                => array(
					'entitlement' => array(
						'type'              => 'object',
						'required'          => false,
						'validate_callback' => static function ( $value ): bool {
							return null === $value || is_array( $value );
						},
					),
				),
			)
		);

		$this->route(
			'/disconnect',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'disconnect' ),
				'permission_callback' => $this->auth->permission(
					array(
						'require_connected'   => true,
						'require_entitlement' => false,
					)
				),
			)
		);

		$this->route(
			'/entitlement/refresh',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'entitlement_refresh' ),
				'permission_callback' => $this->auth->permission(
					array(
						'require_connected'   => true,
						'require_entitlement' => false,
					)
				),
			)
		);

		// Publishing — HMAC + entitlement + feature.
		$this->route(
			'/posts',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create_post' ),
				'permission_callback' => $this->auth->permission(
					array(
						'require_connected'   => true,
						'require_entitlement' => true,
						'feature'             => 'seo_helper',
					)
				),
				'args'                => $this->post_body_args( true ),
			)
		);

		$this->route(
			'/posts/(?P<id>\d+)',
			array(
				'methods'             => 'PATCH',
				'callback'            => array( $this, 'update_post' ),
				'permission_callback' => $this->auth->permission(
					array(
						'require_connected'   => true,
						'require_entitlement' => true,
						'feature'             => 'seo_helper',
					)
				),
				'args'                => array_merge(
					array(
						'id' => array(
							'type'              => 'integer',
							'required'          => true,
							'minimum'           => 1,
							'validate_callback' => static function ( $value ): bool {
								return is_numeric( $value ) && (int) $value > 0;
							},
						),
					),
					$this->post_body_args( false )
				),
			)
		);

		$this->route(
			'/posts/(?P<id>\d+)/schedule',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'schedule_post' ),
				'permission_callback' => $this->auth->permission(
					array(
						'require_connected'   => true,
						'require_entitlement' => true,
						'feature'             => 'seo_helper',
					)
				),
				'args'                => array(
					'id'                 => array(
						'type'     => 'integer',
						'required' => true,
						'minimum'  => 1,
					),
					'scheduled_at'       => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'request_id'         => array(
						'type'     => 'string',
						'required' => true,
					),
					'source_article_id'  => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);

		$this->route(
			'/media',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'upload_media' ),
				'permission_callback' => $this->auth->permission(
					array(
						'require_connected'   => true,
						'require_entitlement' => true,
						'feature'             => 'seo_helper',
					)
				),
				'args'                => array(
					'url'              => array(
						'type'     => 'string',
						'required' => false,
					),
					'post_id'          => array(
						'type'     => 'integer',
						'required' => false,
						'default'  => 0,
						'minimum'  => 0,
					),
					'set_featured'     => array(
						'type'     => 'boolean',
						'required' => false,
						'default'  => false,
					),
					'alt'              => array(
						'type'     => 'string',
						'required' => false,
					),
					'title'            => array(
						'type'     => 'string',
						'required' => false,
					),
					'caption'          => array(
						'type'     => 'string',
						'required' => false,
					),
					'description'      => array(
						'type'     => 'string',
						'required' => false,
					),
					'source_image_id'  => array(
						'type'     => 'string',
						'required' => false,
					),
					'file_base64'      => array(
						'type'     => 'string',
						'required' => false,
					),
					'filename'         => array(
						'type'     => 'string',
						'required' => false,
					),
				),
			)
		);

		$this->route(
			'/seo-meta',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'seo_meta' ),
				'permission_callback' => $this->auth->permission(
					array(
						'require_connected'   => true,
						'require_entitlement' => true,
						'feature'             => 'yoast_sync',
					)
				),
				'args'                => array(
					'post_id' => array(
						'type'     => 'integer',
						'required' => true,
						'minimum'  => 1,
					),
					'seo'     => array(
						'type'     => 'object',
						'required' => false,
					),
				),
			)
		);

		$this->route(
			'/health-check',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'health_check' ),
				'permission_callback' => $this->auth->permission(
					array(
						'require_connected'   => true,
						'require_entitlement' => false,
					)
				),
			)
		);

		$this->route(
			'/logs',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'logs' ),
				'permission_callback' => $this->auth->permission(
					array(
						'require_connected'   => true,
						'require_entitlement' => false,
					)
				),
				'args'                => array(
					'limit' => array(
						'type'              => 'integer',
						'required'          => false,
						'default'           => 20,
						'minimum'           => 1,
						'maximum'           => 100,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	/**
	 * @param array<string,mixed> $args
	 */
	private function route( string $path, array $args ): void {
		register_rest_route( self::REST_NAMESPACE, $path, $args );
	}

	/**
	 * @return array<string,array<string,mixed>>
	 */
	private function post_body_args( bool $require_create_fields ): array {
		return array(
			'title'              => array(
				'type'              => 'string',
				'required'          => $require_create_fields,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'content'            => array(
				'type'     => 'string',
				'required' => $require_create_fields,
			),
			'status'             => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_key',
				'enum'              => array( 'draft', 'publish', 'future', 'pending', 'private' ),
			),
			'slug'               => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_title',
			),
			'excerpt'            => array(
				'type'     => 'string',
				'required' => false,
			),
			'author'             => array(
				'type'     => 'integer',
				'required' => false,
				'minimum'  => 1,
			),
			'post_type'          => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_key',
			),
			'categories'         => array(
				'type'     => 'array',
				'required' => false,
			),
			'tags'               => array(
				'type'     => 'array',
				'required' => false,
			),
			'featured_image'     => array(
				'type'     => 'string',
				'required' => false,
			),
			'featured_image_id'  => array(
				'type'     => 'integer',
				'required' => false,
				'minimum'  => 1,
			),
			'scheduled_at'       => array(
				'type'     => 'string',
				'required' => false,
			),
			'source_article_id'  => array(
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'request_id'         => array(
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'force_create'       => array(
				'type'     => 'boolean',
				'required' => false,
				'default'  => false,
			),
		);
	}

	public function status(): WP_REST_Response {
		$caps = $this->entitlement->capabilities();
		return new WP_REST_Response(
			array(
				'ok'            => true,
				'plugin'        => 'seoauto-seo-helper',
				'name'          => 'SEOAuto SEO Helper',
				'version'       => SEOAUTO_HELPER_VERSION,
				'php'           => PHP_VERSION,
				'wp'            => get_bloginfo( 'version' ),
				'connected'     => $this->connection->is_connected(),
				'paired'        => $this->connection->has_credentials(),
				'locked'        => (bool) ( $caps['locked'] ?? false ),
				'lock_reason'   => (string) ( $caps['lock_reason'] ?? '' ),
				'lock_message'  => (string) ( $caps['lock_message'] ?? '' ),
				'can_mutate'    => (bool) ( $caps['can_mutate'] ?? false ),
				'upgrade_url'   => (string) ( $caps['upgrade_url'] ?? '' ),
				'network_grace_active' => (bool) ( $caps['network_grace_active'] ?? false ),
				'network_grace_until'  => (string) ( $caps['network_grace_until'] ?? '' ),
				'connectivity_state'   => (string) ( $caps['connectivity_state'] ?? '' ),
				'site_id'       => $this->connection->site_id(),
				'connection_id' => (int) $this->connection->option( 'connection_id', 0 ),
				'allowed'       => $this->entitlement->is_allowed(),
				'plan_code'     => $caps['plan_code'] ?? null,
				'features'      => $caps['enabled_features'] ?? array(),
				'capabilities'  => $caps['capabilities'] ?? array(),
				'seo_plugin'          => $caps['seo_plugin'] ?? 'none',
				'seo_adapter'         => $this->seo->active_id(),
				'allowed_post_types'  => $this->publishing->allowed_post_types(),
				'snapshot'            => $this->connection->get_snapshot(),
			),
			200
		);
	}

	public function connect( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$payload = $this->json_body( $request );

		if ( isset( $payload['entitlement'] ) && is_array( $payload['entitlement'] ) ) {
			if ( ! $this->entitlement->store( $payload['entitlement'] ) ) {
				return $this->err( 'seoauto_invalid_entitlement', __( 'Entitlement signature không hợp lệ.', 'seoauto-seo-helper' ), 400 );
			}
		} elseif ( isset( $payload['allowed'] ) || isset( $payload['plan_code'] ) ) {
			if ( ! $this->entitlement->store( $payload ) ) {
				return $this->err( 'seoauto_invalid_entitlement', __( 'Entitlement signature không hợp lệ.', 'seoauto-seo-helper' ), 400 );
			}
		}

		if ( isset( $payload['organization_id'] ) ) {
			$claimed_org = (int) $payload['organization_id'];
			$stored_org  = (int) $this->connection->option( 'organization_id', 0 );
			if ( $stored_org > 0 && $claimed_org > 0 && $stored_org !== $claimed_org ) {
				return $this->err( 'seoauto_organization_mismatch', __( 'organization_id không khớp.', 'seoauto-seo-helper' ), 403 );
			}
			$this->connection->update_option( 'organization_id', $claimed_org );
		}
		if ( ! empty( $payload['domain'] ) ) {
			$this->connection->update_option( 'domain', sanitize_text_field( (string) $payload['domain'] ) );
		}

		$this->audit->log( 'rest_connect', array( 'site_id' => $this->connection->site_id() ) );
		$caps = $this->entitlement->capabilities();

		return new WP_REST_Response(
			array(
				'ok'            => true,
				'connected'     => $this->connection->is_connected(),
				'paired'        => $this->connection->has_credentials(),
				'locked'        => (bool) ( $caps['locked'] ?? false ),
				'lock_reason'   => (string) ( $caps['lock_reason'] ?? '' ),
				'can_mutate'    => (bool) ( $caps['can_mutate'] ?? false ),
				'site_id'       => $this->connection->site_id(),
				'connection_id' => (int) $this->connection->option( 'connection_id', 0 ),
				'allowed'       => $this->entitlement->is_allowed(),
			),
			200
		);
	}

	public function disconnect(): WP_REST_Response {
		$this->audit->log( 'rest_disconnect', array( 'site_id' => $this->connection->site_id() ) );
		$this->connection->disconnect();
		return new WP_REST_Response(
			array(
				'ok'        => true,
				'connected' => false,
			),
			200
		);
	}

	public function entitlement_refresh( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$payload = $this->json_body( $request );
		if ( $payload === array() ) {
			return $this->err( 'seoauto_invalid_body', __( 'Payload entitlement trống.', 'seoauto-seo-helper' ), 400 );
		}
		if ( ! array_key_exists( 'allowed', $payload ) ) {
			return $this->err( 'seoauto_invalid_entitlement', __( 'Thiếu trường allowed.', 'seoauto-seo-helper' ), 400 );
		}

		if ( ! $this->entitlement->store( $payload ) ) {
			return $this->err( 'seoauto_invalid_entitlement', __( 'Entitlement signature không hợp lệ.', 'seoauto-seo-helper' ), 400 );
		}
		$this->audit->log(
			'entitlement_refresh',
			array(
				'allowed'   => ! empty( $payload['allowed'] ),
				'plan_code' => (string) ( $payload['plan_code'] ?? '' ),
			)
		);
		$caps = $this->entitlement->capabilities();

		return new WP_REST_Response(
			array(
				'ok'           => true,
				'allowed'      => $this->entitlement->is_allowed(),
				'locked'       => (bool) ( $caps['locked'] ?? false ),
				'lock_reason'  => (string) ( $caps['lock_reason'] ?? '' ),
				'lock_message' => (string) ( $caps['lock_message'] ?? '' ),
				'can_mutate'   => (bool) ( $caps['can_mutate'] ?? false ),
				'connected'    => $this->connection->is_connected(),
				'cache'        => $this->entitlement->get(),
			),
			200
		);
	}

	public function create_post( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$payload = $this->json_body( $request );
		unset( $payload['meta'], $payload['post_meta'], $payload['meta_input'] );
		$payload = $this->with_idempotency_keys( $request, $payload );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		$title   = trim( (string) ( $payload['title'] ?? '' ) );
		$content = (string) ( $payload['content'] ?? '' );
		if ( $title === '' || $content === '' ) {
			return $this->err( 'seoauto_invalid_post', __( 'Thiếu title hoặc content.', 'seoauto-seo-helper' ), 400 );
		}

		$result = $this->posts->create( $payload );
		if ( is_wp_error( $result ) ) {
			$this->audit->log_error(
				'post_create',
				$result->get_error_code(),
				array(
					'request_id' => (string) ( $payload['request_id'] ?? '' ),
					'message'    => $result->get_error_message(),
				)
			);
			return $this->normalize_error( $result );
		}

		$this->apply_seo_meta( $result['post_id'], $payload );
		$status = ! empty( $result['idempotent_replay'] ) ? 200 : 201;
		return new WP_REST_Response( $result, $status );
	}

	public function update_post( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$post_id = (int) $request->get_param( 'id' );
		$payload = $this->json_body( $request );
		unset( $payload['meta'], $payload['post_meta'], $payload['meta_input'] );
		$payload = $this->with_idempotency_keys( $request, $payload );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		$result = $this->posts->update( $post_id, $payload );
		if ( is_wp_error( $result ) ) {
			$this->audit->log_error(
				'post_update',
				$result->get_error_code(),
				array(
					'post_id'    => $post_id,
					'request_id' => (string) ( $payload['request_id'] ?? '' ),
					'message'    => $result->get_error_message(),
				)
			);
			return $this->normalize_error( $result );
		}

		$this->apply_seo_meta( $result['post_id'], $payload );
		return new WP_REST_Response( $result, 200 );
	}

	public function schedule_post( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$post_id = (int) $request->get_param( 'id' );
		$payload = $this->json_body( $request );
		$payload = $this->with_idempotency_keys( $request, $payload );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}
		if ( empty( $payload['scheduled_at'] ) && $request->get_param( 'scheduled_at' ) ) {
			$payload['scheduled_at'] = (string) $request->get_param( 'scheduled_at' );
		}

		$result = $this->posts->schedule( $post_id, $payload );
		if ( is_wp_error( $result ) ) {
			$this->audit->log_error(
				'post_schedule',
				$result->get_error_code(),
				array(
					'post_id'    => $post_id,
					'request_id' => (string) ( $payload['request_id'] ?? '' ),
					'message'    => $result->get_error_message(),
				)
			);
			return $this->normalize_error( $result );
		}
		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Require request_id + source_article_id (body or HMAC header for request_id).
	 *
	 * @param array<string,mixed> $payload
	 * @return array<string,mixed>
	 */
	private function with_idempotency_keys( WP_REST_Request $request, array $payload ): array|WP_Error {
		$header_rid = (string) $request->get_header( 'x-seoauto-request-id' );
		$body_rid   = isset( $payload['request_id'] ) ? trim( (string) $payload['request_id'] ) : '';

		if ( $body_rid !== '' && $header_rid !== '' && ! hash_equals( $header_rid, $body_rid ) ) {
			return $this->err(
				'seoauto_request_id_mismatch',
				__( 'request_id trong body không khớp header HMAC.', 'seoauto-seo-helper' ),
				400
			);
		}

		if ( $header_rid !== '' ) {
			$payload['request_id'] = $header_rid;
		} elseif ( $body_rid === '' && $request->get_param( 'request_id' ) ) {
			$payload['request_id'] = (string) $request->get_param( 'request_id' );
		}
		if ( empty( $payload['source_article_id'] ) && $request->get_param( 'source_article_id' ) ) {
			$payload['source_article_id'] = (string) $request->get_param( 'source_article_id' );
		}
		return $payload;
	}

	public function upload_media( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$payload = $this->json_body( $request );

		// Multipart direct upload: field name "file".
		$files = $request->get_file_params();
		if ( is_array( $files ) && ! empty( $files['file'] ) && is_array( $files['file'] ) ) {
			$file = $files['file'];
			if ( ! empty( $file['error'] ) && (int) $file['error'] !== UPLOAD_ERR_OK ) {
				return $this->err( 'seoauto_media_upload_error', __( 'Upload file thất bại.', 'seoauto-seo-helper' ), 400 );
			}
			$payload['tmp_name'] = (string) ( $file['tmp_name'] ?? '' );
			$payload['name']     = (string) ( $file['name'] ?? 'seoauto-image.jpg' );
		}

		foreach ( array( 'url', 'post_id', 'set_featured', 'alt', 'title', 'caption', 'description', 'source_image_id', 'file_base64', 'filename', 'featured' ) as $key ) {
			if ( ! array_key_exists( $key, $payload ) && null !== $request->get_param( $key ) ) {
				$payload[ $key ] = $request->get_param( $key );
			}
		}

		$result = $this->media->ingest( $payload );
		if ( is_wp_error( $result ) ) {
			$this->audit->log_error(
				'media_ingest',
				$result->get_error_code(),
				array(
					'post_id'    => (int) ( $payload['post_id'] ?? 0 ),
					'request_id' => (string) ( $payload['request_id'] ?? '' ),
					'message'    => $result->get_error_message(),
				)
			);
			return $this->normalize_error( $result );
		}
		$status = ! empty( $result['deduplicated'] ) ? 200 : 201;
		return new WP_REST_Response( $result, $status );
	}

	public function seo_meta( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$payload = $this->json_body( $request );
		$post_id = (int) ( $payload['post_id'] ?? $request->get_param( 'post_id' ) ?? 0 );
		$seo     = isset( $payload['seo'] ) && is_array( $payload['seo'] ) ? $payload['seo'] : $payload;

		if ( $post_id <= 0 || ! get_post( $post_id ) ) {
			return $this->err( 'seoauto_invalid_post', __( 'post_id không hợp lệ.', 'seoauto-seo-helper' ), 400 );
		}

		$this->seo->sync_post_meta( $post_id, $seo );
		$this->audit->log(
			'seo_meta',
			array(
				'post_id'  => $post_id,
				'adapter'  => $this->seo->active_id(),
			)
		);

		return new WP_REST_Response(
			array(
				'ok'      => true,
				'post_id' => $post_id,
				'adapter' => $this->seo->active_id(),
			),
			200
		);
	}

	public function health_check(): WP_REST_Response {
		$secret_ok = $this->connection->site_secret() !== '';
		$result    = array(
			'ok'            => $this->connection->is_connected() && $secret_ok,
			'connected'     => $this->connection->is_connected(),
			'site_id'       => $this->connection->site_id(),
			'connection_id' => (int) $this->connection->option( 'connection_id', 0 ),
			'secret_ok'     => $secret_ok,
			'allowed'       => $this->entitlement->is_allowed(),
			'hmac'          => true,
			'php'           => PHP_VERSION,
			'wp'            => get_bloginfo( 'version' ),
			'plugin'        => SEOAUTO_HELPER_VERSION,
			'checked_at'    => gmdate( 'c' ),
		);

		$this->connection->update_option( 'last_check_ok', ! empty( $result['ok'] ) );
		$this->connection->update_option( 'last_check_message', $result['ok'] ? 'health-check ok' : 'health-check failed' );
		$this->connection->update_option( 'last_check_at', gmdate( 'c' ) );
		$this->audit->log( 'health_check', array( 'ok' => $result['ok'] ) );

		return new WP_REST_Response( $result, $result['ok'] ? 200 : 503 );
	}

	public function logs( WP_REST_Request $request ): WP_REST_Response {
		$limit = (int) $request->get_param( 'limit' );
		if ( $limit <= 0 ) {
			$limit = 20;
		}
		$limit = min( 100, $limit );

		return new WP_REST_Response(
			array(
				'ok'    => true,
				'limit' => $limit,
				'logs'  => $this->audit->recent( $limit ),
			),
			200
		);
	}

	/**
	 * Sync SEO fields through the active adapter only — never arbitrary post meta.
	 *
	 * @param array<string,mixed> $payload
	 */
	private function apply_seo_meta( int $post_id, array $payload ): void {
		if ( ! empty( $payload['seo'] ) && is_array( $payload['seo'] ) ) {
			$this->seo->sync_post_meta( $post_id, $payload['seo'] );
			return;
		}

		$keys = array(
			'seo_title',
			'meta_description',
			'focus_keyword',
			'canonical',
			'robots',
			'schema_type',
			'social_title',
			'social_description',
			'social_image',
			'og_title',
			'og_description',
			'og_image',
		);
		$has = false;
		foreach ( $keys as $key ) {
			if ( ! empty( $payload[ $key ] ) ) {
				$has = true;
				break;
			}
		}
		if ( ! $has ) {
			return;
		}

		$this->seo->sync_post_meta(
			$post_id,
			array(
				'title'               => $payload['seo_title'] ?? $payload['title'] ?? '',
				'description'         => $payload['meta_description'] ?? '',
				'focus_keyword'       => $payload['focus_keyword'] ?? '',
				'canonical'           => $payload['canonical'] ?? '',
				'robots'              => $payload['robots'] ?? null,
				'schema_type'         => $payload['schema_type'] ?? '',
				'social_title'        => $payload['social_title'] ?? $payload['og_title'] ?? '',
				'social_description'  => $payload['social_description'] ?? $payload['og_description'] ?? '',
				'social_image'        => $payload['social_image'] ?? $payload['og_image'] ?? '',
			)
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function json_body( WP_REST_Request $request ): array {
		$params = $request->get_json_params();
		if ( is_array( $params ) ) {
			return $params;
		}
		$body = $request->get_body_params();
		return is_array( $body ) ? $body : array();
	}

	private function err( string $code, string $message, int $status ): WP_Error {
		return new WP_Error(
			$code,
			$message,
			array(
				'status' => $status,
				'code'   => $code,
			)
		);
	}

	private function normalize_error( WP_Error $error ): WP_Error {
		$data = $error->get_error_data();
		if ( ! is_array( $data ) ) {
			$data = array();
		}
		if ( ! isset( $data['status'] ) ) {
			$data['status'] = 400;
		}
		$data['code'] = $error->get_error_code();
		return new WP_Error( $error->get_error_code(), $error->get_error_message(), $data );
	}
}
