<?php
/**
 * Create / update / schedule posts from SEOAuto payloads (with idempotency).
 *
 * @package SEOAuto\SEOHelper
 */

declare(strict_types=1);

namespace SEOAuto\SEOHelper\Post;

use SEOAuto\SEOHelper\Audit\Audit_Logger;
use SEOAuto\SEOHelper\Connection\Connection_Manager;
use SEOAuto\SEOHelper\Media\Media_Service;
use SEOAuto\SEOHelper\Security\Content_Sanitizer;
use WP_Error;
use WP_Post;
use WP_User;

final class Post_Service {

	public const META_SOURCE            = '_seoauto_source';
	public const META_SOURCE_ARTICLE_ID = '_seoauto_source_article_id';
	public const META_CONNECTION_ID     = '_seoauto_connection_id';
	public const META_FOCUS_KW          = '_seoauto_focus_kw';
	public const META_DESCRIPTION       = '_seoauto_meta_description';
	public const META_SEO_TITLE         = '_seoauto_seo_title';

	public function __construct(
		private Audit_Logger $audit,
		private Connection_Manager $connection,
		private Publishing_Settings $settings,
		private Media_Service $media,
		private Idempotency_Store $idempotency
	) {}

	/**
	 * @param array<string,mixed> $payload
	 * @return array{post_id:int,permalink:string,edit_url:string,status:string,scheduled_at?:string,idempotent_replay?:bool,force_create?:bool}|WP_Error
	 */
	public function create( array $payload ): array|WP_Error {
		return $this->run_idempotent( $payload, 'create', 0 );
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return array{post_id:int,permalink:string,edit_url:string,status:string,scheduled_at?:string,idempotent_replay?:bool}|WP_Error
	 */
	public function update( int $post_id, array $payload ): array|WP_Error {
		return $this->run_idempotent( $payload, 'update', $post_id );
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return array{post_id:int,permalink:string,edit_url:string,status:string,scheduled_at:string,idempotent_replay?:bool}|WP_Error
	 */
	public function schedule( int $post_id, array $payload ): array|WP_Error {
		return $this->run_idempotent( $payload, 'schedule', $post_id );
	}

	/**
	 * @param array<string,mixed> $payload
	 * @param 'create'|'update'|'schedule' $operation
	 * @return array<string,mixed>|WP_Error
	 */
	private function run_idempotent( array $payload, string $operation, int $route_post_id ): array|WP_Error {
		unset( $payload['meta'], $payload['post_meta'], $payload['meta_input'] );

		$ids = $this->require_ids( $payload );
		if ( $ids instanceof WP_Error ) {
			return $ids;
		}

		$request_id        = $ids['request_id'];
		$source_article_id = $ids['source_article_id'];
		$connection_id     = (int) $this->connection->option( 'connection_id', 0 );
		$payload['request_id']        = $request_id;
		$payload['source_article_id'] = $source_article_id;

		$claim = $this->idempotency->claim_request( $request_id, $source_article_id, $connection_id );
		if ( $claim instanceof WP_Error ) {
			return $claim;
		}

		if ( ( $claim['state'] ?? '' ) === 'replay' ) {
			return $claim['response'];
		}

		if ( ( $claim['state'] ?? '' ) === 'failed' ) {
			$code = (string) ( $claim['error_code'] ?? 'seoauto_idempotency_failed' );
			$data = is_array( $claim['response'] ?? null ) ? $claim['response'] : array();
			$data['status'] = (int) ( $data['status'] ?? 409 );
			$data['code']   = $code;
			return new WP_Error( $code, __( 'Request_id này đã thất bại trước đó.', 'seoauto-seo-helper' ), $data );
		}

		if ( ( $claim['state'] ?? '' ) === 'pending' ) {
			$waited = $this->idempotency->wait_for_completion( $request_id );
			if ( is_array( $waited ) ) {
				$waited['idempotent_replay'] = true;
				return $waited;
			}
			return $this->err(
				'seoauto_request_in_progress',
				__( 'Request đang được xử lý bởi worker khác. Thử lại sau.', 'seoauto-seo-helper' ),
				409
			);
		}

		// We own the claim — execute under article lock.
		if ( ! $this->idempotency->acquire_article_lock( $connection_id, $source_article_id, 10 ) ) {
			$this->idempotency->fail( $request_id, 'seoauto_lock_timeout' );
			return $this->err( 'seoauto_lock_timeout', __( 'Không lấy được khóa article (timeout).', 'seoauto-seo-helper' ), 409 );
		}

		try {
			$result = $this->execute_locked( $payload, $operation, $route_post_id, $connection_id, $source_article_id );
			if ( $result instanceof WP_Error ) {
				$code = $result->get_error_code();
				$data = $result->get_error_data();
				$payload_err = is_array( $data ) ? $data : array();
				$payload_err['message'] = $result->get_error_message();
				$this->idempotency->fail( $request_id, (string) $code, $payload_err );
				return $result;
			}

			$this->idempotency->complete( $request_id, (int) $result['post_id'], $result );
			return $result;
		} finally {
			$this->idempotency->release_article_lock( $connection_id, $source_article_id );
		}
	}

	/**
	 * @param array<string,mixed> $payload
	 * @param 'create'|'update'|'schedule' $operation
	 * @return array<string,mixed>|WP_Error
	 */
	private function execute_locked(
		array $payload,
		string $operation,
		int $route_post_id,
		int $connection_id,
		string $source_article_id
	): array|WP_Error {
		$mapped_post_id = $this->idempotency->find_post_id( $connection_id, $source_article_id );
		$force_create   = $this->is_force_create_allowed( $payload );

		if ( $operation === 'create' ) {
			if ( $mapped_post_id > 0 && ! $force_create ) {
				return new WP_Error(
					'seoauto_article_exists',
					__( 'source_article_id đã có bài WordPress. Dùng PATCH để cập nhật hoặc force_create có kiểm soát.', 'seoauto-seo-helper' ),
					array(
						'status'            => 409,
						'code'              => 'seoauto_article_exists',
						'post_id'           => $mapped_post_id,
						'source_article_id' => $source_article_id,
					)
				);
			}

			if ( $mapped_post_id > 0 && $force_create ) {
				$payload['post_id'] = 0;
				$result             = $this->upsert( $payload, false );
				if ( $result instanceof WP_Error ) {
					return $result;
				}
				$this->idempotency->upsert_article_map( $connection_id, $source_article_id, (int) $result['post_id'] );
				$result['force_create'] = true;
				return $result;
			}

			// New article — create then insert unique map (race-safe).
			$payload['post_id'] = 0;
			$result             = $this->upsert( $payload, false );
			if ( $result instanceof WP_Error ) {
				return $result;
			}

			$inserted = $this->idempotency->insert_article_map( $connection_id, $source_article_id, (int) $result['post_id'] );
			if ( ! $inserted ) {
				$orphan = (int) $result['post_id'];
				if ( $orphan > 0 ) {
					wp_delete_post( $orphan, true );
				}
				$winner = $this->idempotency->find_post_id( $connection_id, $source_article_id );
				return new WP_Error(
					'seoauto_article_exists',
					__( 'source_article_id vừa được worker khác tạo. Create bị từ chối (race).', 'seoauto-seo-helper' ),
					array(
						'status'            => 409,
						'code'              => 'seoauto_article_exists',
						'post_id'           => $winner,
						'source_article_id' => $source_article_id,
						'race'              => true,
					)
				);
			}
			return $result;
		}

		// update / schedule — always the mapped post when present.
		$target_id = $route_post_id;
		if ( $mapped_post_id > 0 ) {
			if ( $target_id > 0 && $target_id !== $mapped_post_id ) {
				return new WP_Error(
					'seoauto_article_post_mismatch',
					__( 'post_id không khớp mapping source_article_id.', 'seoauto-seo-helper' ),
					array(
						'status'            => 409,
						'code'              => 'seoauto_article_post_mismatch',
						'post_id'           => $mapped_post_id,
						'source_article_id' => $source_article_id,
					)
				);
			}
			$target_id = $mapped_post_id;
		}

		if ( $target_id <= 0 ) {
			return $this->err( 'seoauto_post_not_found', __( 'Bài viết không tồn tại / chưa map source_article_id.', 'seoauto-seo-helper' ), 404 );
		}

		if ( $operation === 'schedule' ) {
			$result = $this->schedule_post( $target_id, $payload );
		} else {
			$payload['post_id'] = $target_id;
			$result             = $this->upsert( $payload, true );
		}

		if ( $result instanceof WP_Error ) {
			return $result;
		}

		if ( $mapped_post_id <= 0 ) {
			$this->idempotency->upsert_article_map( $connection_id, $source_article_id, (int) $result['post_id'] );
		}

		return $result;
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return array{request_id:string,source_article_id:string}|WP_Error
	 */
	private function require_ids( array $payload ): array|WP_Error {
		$request_id = (string) ( $payload['request_id'] ?? '' );
		if ( $request_id === '' && isset( $payload['_hmac_request_id'] ) ) {
			$request_id = (string) $payload['_hmac_request_id'];
		}
		$request_id = $this->idempotency->normalize_request_id( $request_id );

		$article_id = (string) ( $payload['source_article_id'] ?? $payload['article_id'] ?? '' );
		$article_id = $this->idempotency->normalize_article_id( $article_id );

		if ( $request_id === '' ) {
			return $this->err( 'seoauto_missing_request_id', __( 'Thiếu request_id.', 'seoauto-seo-helper' ), 400 );
		}
		if ( $article_id === '' ) {
			return $this->err( 'seoauto_missing_source_article_id', __( 'Thiếu source_article_id.', 'seoauto-seo-helper' ), 400 );
		}

		return array(
			'request_id'        => $request_id,
			'source_article_id' => $article_id,
		);
	}

	/**
	 * force_create must be explicit boolean true and allowed by filter (controlled).
	 *
	 * @param array<string,mixed> $payload
	 */
	private function is_force_create_allowed( array $payload ): bool {
		$flag = $payload['force_create'] ?? false;
		if ( $flag !== true && $flag !== 1 && $flag !== '1' && $flag !== 'true' ) {
			return false;
		}
		/**
		 * Gate force_create. Default true when flag present; site owners may disable.
		 *
		 * @param bool               $allowed
		 * @param array<string,mixed> $payload
		 */
		return (bool) apply_filters( 'seoauto_helper_allow_force_create', true, $payload );
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return array{post_id:int,permalink:string,edit_url:string,status:string,scheduled_at:string}|WP_Error
	 */
	private function schedule_post( int $post_id, array $payload ): array|WP_Error {
		$post = get_post( $post_id );
		if ( ! ( $post instanceof WP_Post ) ) {
			return $this->err( 'seoauto_post_not_found', __( 'Bài viết không tồn tại.', 'seoauto-seo-helper' ), 404 );
		}
		if ( ! $this->settings->is_post_type_allowed( $post->post_type ) ) {
			return $this->err( 'seoauto_post_type_denied', __( 'Post type không được phép.', 'seoauto-seo-helper' ), 403 );
		}

		$schedule = $this->parse_schedule( $payload );
		if ( $schedule instanceof WP_Error ) {
			return $schedule;
		}

		$result = wp_update_post(
			array(
				'ID'            => $post_id,
				'post_status'   => 'future',
				'post_date'     => $schedule['local'],
				'post_date_gmt' => $schedule['gmt'],
			),
			true
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->write_tracking_meta( $post_id, $payload );
		$this->audit->log(
			'post_schedule',
			array(
				'post_id'           => $post_id,
				'scheduled_at'      => $schedule['gmt'],
				'source_article_id' => (string) ( $payload['source_article_id'] ?? '' ),
				'request_id'        => (string) ( $payload['request_id'] ?? '' ),
			)
		);

		$response                  = $this->response( $post_id );
		$response['scheduled_at']  = $schedule['gmt'];
		return $response;
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return array{post_id:int,permalink:string,edit_url:string,status:string,scheduled_at?:string}|WP_Error
	 */
	public function upsert( array $payload, bool $is_update_hint = false ): array|WP_Error {
		$post_id   = isset( $payload['post_id'] ) ? (int) $payload['post_id'] : 0;
		$existing  = $post_id > 0 ? get_post( $post_id ) : null;
		$is_update = $existing instanceof WP_Post;

		if ( $is_update_hint && ! $is_update ) {
			return $this->err( 'seoauto_post_not_found', __( 'Bài viết không tồn tại.', 'seoauto-seo-helper' ), 404 );
		}

		$post_type = sanitize_key( (string) ( $payload['post_type'] ?? ( $is_update ? $existing->post_type : 'post' ) ) );
		if ( $post_type === '' ) {
			$post_type = 'post';
		}
		if ( ! $this->settings->is_post_type_allowed( $post_type ) ) {
			return $this->err(
				'seoauto_post_type_denied',
				sprintf(
					/* translators: %s: post type slug */
					__( 'Post type "%s" chưa được admin bật.', 'seoauto-seo-helper' ),
					$post_type
				),
				403
			);
		}
		if ( $is_update && $existing->post_type !== $post_type ) {
			return $this->err( 'seoauto_post_type_mismatch', __( 'Không đổi post type của bài đã có.', 'seoauto-seo-helper' ), 400 );
		}

		$title   = sanitize_text_field( (string) ( $payload['title'] ?? '' ) );
		$content = array_key_exists( 'content', $payload )
			? Content_Sanitizer::sanitize_content( (string) $payload['content'] )
			: null;

		if ( ! $is_update && ( $title === '' || null === $content || $content === '' ) ) {
			return $this->err( 'seoauto_invalid_post', __( 'Thiếu title hoặc content.', 'seoauto-seo-helper' ), 400 );
		}

		$status = $this->resolve_status( $payload, $is_update );
		if ( $status instanceof WP_Error ) {
			return $status;
		}

		$args = array(
			'post_type' => $post_type,
		);
		if ( $title !== '' ) {
			$args['post_title'] = $title;
		}
		if ( null !== $content ) {
			$args['post_content'] = $content;
		}
		if ( isset( $payload['slug'] ) || ( ! $is_update && $title !== '' ) ) {
			$slug = sanitize_title( (string) ( $payload['slug'] ?? $title ) );
			if ( $slug !== '' ) {
				$args['post_name'] = $slug;
			}
		}
		if ( null !== $status ) {
			$args['post_status'] = $status;
		}
		if ( array_key_exists( 'excerpt', $payload ) ) {
			$args['post_excerpt'] = Content_Sanitizer::sanitize_excerpt( (string) $payload['excerpt'] );
		}

		$author = $this->resolve_author( $payload );
		if ( $author instanceof WP_Error ) {
			return $author;
		}
		if ( null !== $author ) {
			$args['post_author'] = $author;
		}

		$schedule = null;
		if ( ( $status === 'future' ) || ( isset( $payload['scheduled_at'] ) && (string) $payload['scheduled_at'] !== '' ) ) {
			$schedule = $this->parse_schedule( $payload );
			if ( $schedule instanceof WP_Error ) {
				return $schedule;
			}
			$args['post_status']   = 'future';
			$args['post_date']     = $schedule['local'];
			$args['post_date_gmt'] = $schedule['gmt'];
		}

		if ( $is_update ) {
			$args['ID'] = $post_id;
			$result     = wp_update_post( $args, true );
		} else {
			$result = wp_insert_post( $args, true );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$post_id = (int) $result;

		$this->write_tracking_meta( $post_id, $payload );
		$this->apply_taxonomies( $post_id, $post_type, $payload );
		$this->apply_featured_image( $post_id, $payload );

		if ( ! empty( $payload['focus_keyword'] ) ) {
			update_post_meta( $post_id, self::META_FOCUS_KW, sanitize_text_field( (string) $payload['focus_keyword'] ) );
		}
		if ( ! empty( $payload['meta_description'] ) ) {
			update_post_meta( $post_id, self::META_DESCRIPTION, sanitize_textarea_field( (string) $payload['meta_description'] ) );
		}
		if ( ! empty( $payload['seo_title'] ) ) {
			update_post_meta( $post_id, self::META_SEO_TITLE, sanitize_text_field( (string) $payload['seo_title'] ) );
		}

		$this->audit->log(
			$is_update ? 'post_update' : 'post_create',
			array(
				'post_id'           => $post_id,
				'status'            => (string) get_post_status( $post_id ),
				'post_type'         => $post_type,
				'source_article_id' => (string) ( $payload['source_article_id'] ?? '' ),
				'request_id'        => (string) ( $payload['request_id'] ?? '' ),
			)
		);

		$response = $this->response( $post_id );
		if ( is_array( $schedule ) ) {
			$response['scheduled_at'] = $schedule['gmt'];
		}
		return $response;
	}

	/**
	 * @return array{post_id:int,permalink:string,edit_url:string,status:string}
	 */
	public function response( int $post_id ): array {
		$permalink = (string) get_permalink( $post_id );
		$edit_url  = (string) get_edit_post_link( $post_id, 'raw' );
		if ( $edit_url === '' ) {
			$edit_url = admin_url( 'post.php?post=' . $post_id . '&action=edit' );
		}

		return array(
			'post_id'   => $post_id,
			'permalink' => $permalink,
			'edit_url'  => $edit_url,
			'status'    => (string) ( get_post_status( $post_id ) ?: 'draft' ),
		);
	}

	/**
	 * @param array<string,mixed> $payload
	 */
	private function write_tracking_meta( int $post_id, array $payload ): void {
		update_post_meta( $post_id, self::META_SOURCE, 'seoauto' );

		$connection_id = (int) $this->connection->option( 'connection_id', 0 );
		if ( $connection_id > 0 ) {
			update_post_meta( $post_id, self::META_CONNECTION_ID, $connection_id );
		}

		$article_id = $this->idempotency->normalize_article_id( (string) ( $payload['source_article_id'] ?? '' ) );
		if ( $article_id !== '' ) {
			update_post_meta( $post_id, self::META_SOURCE_ARTICLE_ID, $article_id );
		}
	}

	/**
	 * @param array<string,mixed> $payload
	 */
	private function apply_taxonomies( int $post_id, string $post_type, array $payload ): void {
		if ( isset( $payload['categories'] ) && is_array( $payload['categories'] ) && is_object_in_taxonomy( $post_type, 'category' ) ) {
			$cat_ids = $this->resolve_term_ids( $payload['categories'], 'category' );
			if ( $cat_ids !== array() ) {
				wp_set_post_terms( $post_id, $cat_ids, 'category', false );
			}
		}

		if ( isset( $payload['tags'] ) && is_array( $payload['tags'] ) && is_object_in_taxonomy( $post_type, 'post_tag' ) ) {
			$tag_ids = $this->resolve_term_ids( $payload['tags'], 'post_tag', true );
			if ( $tag_ids !== array() ) {
				wp_set_post_terms( $post_id, $tag_ids, 'post_tag', false );
			}
		}
	}

	/**
	 * @param list<mixed> $items
	 * @return list<int>
	 */
	private function resolve_term_ids( array $items, string $taxonomy, bool $create_missing = false ): array {
		$ids = array();
		foreach ( $items as $item ) {
			if ( is_numeric( $item ) ) {
				$term_id = (int) $item;
				$term    = get_term( $term_id, $taxonomy );
				if ( $term && ! is_wp_error( $term ) ) {
					$ids[] = $term_id;
				}
				continue;
			}
			$name = sanitize_text_field( (string) $item );
			if ( $name === '' ) {
				continue;
			}
			$existing = term_exists( $name, $taxonomy );
			if ( is_array( $existing ) && isset( $existing['term_id'] ) ) {
				$ids[] = (int) $existing['term_id'];
				continue;
			}
			if ( is_int( $existing ) ) {
				$ids[] = $existing;
				continue;
			}
			if ( $create_missing ) {
				$created = wp_insert_term( $name, $taxonomy );
				if ( ! is_wp_error( $created ) && isset( $created['term_id'] ) ) {
					$ids[] = (int) $created['term_id'];
				}
			}
		}
		return array_values( array_unique( array_filter( $ids ) ) );
	}

	/**
	 * @param array<string,mixed> $payload
	 */
	private function apply_featured_image( int $post_id, array $payload ): void {
		if ( isset( $payload['featured_media'] ) && is_numeric( $payload['featured_media'] ) ) {
			$attachment_id = (int) $payload['featured_media'];
			if ( $attachment_id > 0 && wp_attachment_is_image( $attachment_id ) ) {
				set_post_thumbnail( $post_id, $attachment_id );
			}
			return;
		}

		if ( isset( $payload['featured_image_id'] ) && is_numeric( $payload['featured_image_id'] ) ) {
			$attachment_id = (int) $payload['featured_image_id'];
			if ( $attachment_id > 0 && wp_attachment_is_image( $attachment_id ) ) {
				set_post_thumbnail( $post_id, $attachment_id );
			}
			return;
		}

		$url = '';
		if ( ! empty( $payload['featured_image'] ) && is_string( $payload['featured_image'] ) ) {
			$url = (string) $payload['featured_image'];
		} elseif ( ! empty( $payload['featured_image_url'] ) && is_string( $payload['featured_image_url'] ) ) {
			$url = (string) $payload['featured_image_url'];
		}
		if ( $url !== '' ) {
			$this->media->set_featured_from_url( $post_id, $url );
		}
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return string|null|WP_Error
	 */
	private function resolve_status( array $payload, bool $is_update ): string|null|WP_Error {
		if ( ! isset( $payload['status'] ) && $is_update ) {
			return null;
		}

		$status  = sanitize_key( (string) ( $payload['status'] ?? 'draft' ) );
		$allowed = array( 'draft', 'publish', 'future', 'pending', 'private' );
		if ( ! in_array( $status, $allowed, true ) ) {
			return $this->err(
				'seoauto_invalid_status',
				__( 'status phải là draft|publish|future|pending|private.', 'seoauto-seo-helper' ),
				400
			);
		}
		return $status;
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return int|null|WP_Error
	 */
	private function resolve_author( array $payload ): int|null|WP_Error {
		if ( ! isset( $payload['author'] ) && ! isset( $payload['author_id'] ) ) {
			return null;
		}
		$author_id = (int) ( $payload['author'] ?? $payload['author_id'] ?? 0 );
		if ( $author_id <= 0 ) {
			return $this->err( 'seoauto_invalid_author', __( 'author không hợp lệ.', 'seoauto-seo-helper' ), 400 );
		}
		$user = get_user_by( 'id', $author_id );
		if ( ! ( $user instanceof WP_User ) ) {
			return $this->err( 'seoauto_invalid_author', __( 'author không tồn tại.', 'seoauto-seo-helper' ), 400 );
		}
		if ( ! user_can( $user, 'edit_posts' ) ) {
			return $this->err( 'seoauto_invalid_author', __( 'author không có quyền edit_posts.', 'seoauto-seo-helper' ), 403 );
		}
		return $author_id;
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return array{gmt:string,local:string}|WP_Error
	 */
	private function parse_schedule( array $payload ): array|WP_Error {
		$when = trim( (string) ( $payload['scheduled_at'] ?? $payload['date_gmt'] ?? $payload['date'] ?? '' ) );
		if ( $when === '' ) {
			return $this->err( 'seoauto_invalid_schedule', __( 'Thiếu scheduled_at (ISO8601).', 'seoauto-seo-helper' ), 400 );
		}
		$ts = strtotime( $when );
		if ( false === $ts ) {
			return $this->err( 'seoauto_invalid_schedule', __( 'scheduled_at không hợp lệ.', 'seoauto-seo-helper' ), 400 );
		}
		if ( $ts < ( time() - 60 ) ) {
			return $this->err( 'seoauto_invalid_schedule', __( 'scheduled_at phải ở tương lai.', 'seoauto-seo-helper' ), 400 );
		}
		$gmt = gmdate( 'Y-m-d H:i:s', $ts );
		return array(
			'gmt'   => $gmt,
			'local' => get_date_from_gmt( $gmt ),
		);
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
}
