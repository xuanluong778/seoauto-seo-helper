<?php
/**
 * Upload / sideload images into the Media Library (SSRF + MIME hardened).
 *
 * @package SEOAuto\SEOHelper
 */

declare(strict_types=1);

namespace SEOAuto\SEOHelper\Media;

use SEOAuto\SEOHelper\Audit\Audit_Logger;
use SEOAuto\SEOHelper\Connection\Connection_Manager;
use WP_Error;

final class Media_Service {

	public const META_SOURCE_IMAGE_ID = '_seoauto_source_image_id';
	public const META_FILE_HASH       = '_seoauto_file_hash';

	private Url_Safety $urls;
	private Mime_Guard $mime;
	private Media_Map $map;

	public function __construct(
		private Audit_Logger $audit,
		private Connection_Manager $connection,
		?Url_Safety $urls = null,
		?Mime_Guard $mime = null,
		?Media_Map $map = null
	) {
		$this->urls = $urls ?? new Url_Safety();
		$this->mime = $mime ?? new Mime_Guard();
		$this->map  = $map ?? new Media_Map();
	}

	/**
	 * Ingest from URL or direct upload payload.
	 *
	 * @param array<string,mixed> $payload
	 * @return array{
	 *   attachment_id:int,
	 *   url:string,
	 *   width:int,
	 *   height:int,
	 *   mime:string,
	 *   file_hash:string,
	 *   source_image_id:string,
	 *   deduplicated:bool
	 * }|WP_Error
	 */
	public function ingest( array $payload ): array|WP_Error {
		$connection_id   = (int) $this->connection->option( 'connection_id', 0 );
		$source_image_id = $this->map->normalize_source_id( (string) ( $payload['source_image_id'] ?? '' ) );
		$post_id         = (int) ( $payload['post_id'] ?? 0 );
		$set_featured    = ! empty( $payload['set_featured'] ) || ! empty( $payload['featured'] );

		if ( $source_image_id !== '' ) {
			$existing = $this->map->find_by_source( $connection_id, $source_image_id );
			if ( $existing > 0 ) {
				$result = $this->enrich_existing( $existing, $payload, true );
				if ( ! is_wp_error( $result ) && $set_featured && $post_id > 0 ) {
					set_post_thumbnail( $post_id, $existing );
				}
				return $result;
			}
		}

		$tmp      = '';
		$orig_name = 'seoauto-image.jpg';
		$cleanup   = false;

		if ( ! empty( $payload['tmp_name'] ) && is_string( $payload['tmp_name'] ) ) {
			$tmp       = (string) $payload['tmp_name'];
			$orig_name = sanitize_file_name( (string) ( $payload['name'] ?? 'seoauto-image.jpg' ) );
		} elseif ( ! empty( $payload['file_base64'] ) && is_string( $payload['file_base64'] ) ) {
			$decoded = $this->decode_base64_upload( (string) $payload['file_base64'], (string) ( $payload['filename'] ?? 'seoauto-image.jpg' ) );
			if ( is_wp_error( $decoded ) ) {
				return $decoded;
			}
			$tmp       = $decoded['tmp'];
			$orig_name = $decoded['name'];
			$cleanup   = true;
		} elseif ( ! empty( $payload['url'] ) ) {
			$url = (string) $payload['url'];
			$safe = $this->urls->assert_safe_url( $url );
			if ( $safe instanceof WP_Error ) {
				return $safe;
			}
			$downloaded = $this->urls->download_to_temp( $url, $this->mime->max_bytes() );
			if ( is_wp_error( $downloaded ) ) {
				return $downloaded;
			}
			$tmp       = $downloaded;
			$cleanup   = true;
			$path      = (string) ( wp_parse_url( $url, PHP_URL_PATH ) ?? '' );
			$basename  = $path !== '' ? basename( $path ) : 'seoauto-image.jpg';
			$orig_name = sanitize_file_name( $basename !== '' ? $basename : 'seoauto-image.jpg' );
		} else {
			return new WP_Error(
				'seoauto_media_missing_source',
				__( 'Cần url, file upload hoặc file_base64.', 'seoauto-seo-helper' ),
				array( 'status' => 400, 'code' => 'seoauto_media_missing_source' )
			);
		}

		try {
			$validated = $this->mime->validate_file( $tmp, $orig_name );
			if ( is_wp_error( $validated ) ) {
				return $validated;
			}

			$hash = hash_file( 'sha256', $tmp );
			if ( ! is_string( $hash ) || $hash === '' ) {
				return new WP_Error(
					'seoauto_media_hash',
					__( 'Không tính được file hash.', 'seoauto-seo-helper' ),
					array( 'status' => 500, 'code' => 'seoauto_media_hash' )
				);
			}

			$by_hash = $this->map->find_by_hash( $connection_id, $hash );
			if ( $by_hash > 0 ) {
				if ( $source_image_id !== '' ) {
					$this->map->remember( $connection_id, $by_hash, $hash, $source_image_id );
					update_post_meta( $by_hash, self::META_SOURCE_IMAGE_ID, $source_image_id );
				}
				$result = $this->enrich_existing( $by_hash, $payload, true );
				if ( ! is_wp_error( $result ) && $set_featured && $post_id > 0 ) {
					set_post_thumbnail( $post_id, $by_hash );
				}
				return $result;
			}

			require_once \ABSPATH . 'wp-admin/includes/file.php';
			require_once \ABSPATH . 'wp-admin/includes/media.php';
			require_once \ABSPATH . 'wp-admin/includes/image.php';

			$filename = $this->build_filename( $orig_name, $validated['ext'] );
			$file_array = array(
				'name'     => $filename,
				'tmp_name' => $tmp,
				'type'     => $validated['mime'],
				'error'    => 0,
				'size'     => (int) filesize( $tmp ),
			);

			// media_handle_sideload moves/unlinks tmp — disable our cleanup after success path.
			$attachment_id = media_handle_sideload(
				$file_array,
				$post_id > 0 ? $post_id : 0,
				$this->sideload_description( $payload )
			);
			$cleanup = false;

			if ( is_wp_error( $attachment_id ) ) {
				return $attachment_id;
			}

			$attachment_id = (int) $attachment_id;
			$this->apply_attachment_fields( $attachment_id, $payload );
			update_post_meta( $attachment_id, self::META_FILE_HASH, $hash );
			if ( $source_image_id !== '' ) {
				update_post_meta( $attachment_id, self::META_SOURCE_IMAGE_ID, $source_image_id );
			}

			$this->map->remember( $connection_id, $attachment_id, $hash, $source_image_id );

			if ( $set_featured && $post_id > 0 ) {
				set_post_thumbnail( $post_id, $attachment_id );
			}

			$this->audit->log(
				'media_ingest',
				array(
					'attachment_id'   => $attachment_id,
					'post_id'         => $post_id,
					'source_image_id' => $source_image_id,
					'mime'            => $validated['mime'],
					'deduplicated'    => false,
				)
			);

			return $this->format_response( $attachment_id, $validated['mime'], $validated['width'], $validated['height'], $hash, $source_image_id, false );
		} finally {
			if ( $cleanup && $tmp !== '' && is_string( $tmp ) && file_exists( $tmp ) ) {
				@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}
	}

	/**
	 * @return array{attachment_id:int,url:string,width:int,height:int,mime:string,file_hash:string,source_image_id:string,deduplicated:bool}|WP_Error
	 */
	public function sideload( string $url, int $post_id = 0, string $desc = '' ): array|WP_Error {
		return $this->ingest(
			array(
				'url'         => $url,
				'post_id'     => $post_id,
				'description' => $desc,
			)
		);
	}

	/**
	 * @return array{attachment_id:int,url:string,width:int,height:int,mime:string,file_hash:string,source_image_id:string,deduplicated:bool}|WP_Error
	 */
	public function set_featured_from_url( int $post_id, string $url ): array|WP_Error {
		return $this->ingest(
			array(
				'url'          => $url,
				'post_id'      => $post_id,
				'set_featured' => true,
				'title'        => get_the_title( $post_id ),
			)
		);
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return array{attachment_id:int,url:string,width:int,height:int,mime:string,file_hash:string,source_image_id:string,deduplicated:bool}|WP_Error
	 */
	private function enrich_existing( int $attachment_id, array $payload, bool $deduped ): array|WP_Error {
		$this->apply_attachment_fields( $attachment_id, $payload );
		$mime = (string) get_post_mime_type( $attachment_id );
		$meta = wp_get_attachment_metadata( $attachment_id );
		$w    = is_array( $meta ) ? (int) ( $meta['width'] ?? 0 ) : 0;
		$h    = is_array( $meta ) ? (int) ( $meta['height'] ?? 0 ) : 0;
		$hash = (string) get_post_meta( $attachment_id, self::META_FILE_HASH, true );
		$sid  = (string) ( $payload['source_image_id'] ?? get_post_meta( $attachment_id, self::META_SOURCE_IMAGE_ID, true ) );

		$this->audit->log(
			'media_dedupe',
			array(
				'attachment_id'   => $attachment_id,
				'source_image_id' => $this->map->normalize_source_id( $sid ),
				'deduplicated'    => true,
			)
		);

		return $this->format_response( $attachment_id, $mime, $w, $h, $hash, $this->map->normalize_source_id( $sid ), $deduped );
	}

	/**
	 * @param array<string,mixed> $payload
	 */
	private function apply_attachment_fields( int $attachment_id, array $payload ): void {
		$update = array( 'ID' => $attachment_id );

		if ( isset( $payload['title'] ) && is_string( $payload['title'] ) ) {
			$update['post_title'] = sanitize_text_field( $payload['title'] );
		}
		if ( isset( $payload['caption'] ) && is_string( $payload['caption'] ) ) {
			$update['post_excerpt'] = sanitize_textarea_field( $payload['caption'] );
		}
		if ( isset( $payload['description'] ) && is_string( $payload['description'] ) ) {
			$update['post_content'] = sanitize_textarea_field( $payload['description'] );
		}

		if ( count( $update ) > 1 ) {
			wp_update_post( $update );
		}

		if ( isset( $payload['alt'] ) && is_string( $payload['alt'] ) ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $payload['alt'] ) );
		}
	}

	/**
	 * @return array{attachment_id:int,url:string,width:int,height:int,mime:string,file_hash:string,source_image_id:string,deduplicated:bool}
	 */
	private function format_response(
		int $attachment_id,
		string $mime,
		int $width,
		int $height,
		string $hash,
		string $source_image_id,
		bool $deduplicated
	): array {
		if ( ( $width <= 0 || $height <= 0 ) && $mime !== 'image/svg+xml' ) {
			$file = get_attached_file( $attachment_id );
			if ( is_string( $file ) && $file !== '' && is_readable( $file ) ) {
				$info = @getimagesize( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				if ( is_array( $info ) ) {
					$width  = (int) ( $info[0] ?? 0 );
					$height = (int) ( $info[1] ?? 0 );
				}
			}
		}

		return array(
			'attachment_id'   => $attachment_id,
			'url'             => (string) wp_get_attachment_url( $attachment_id ),
			'width'           => $width,
			'height'          => $height,
			'mime'            => $mime,
			'file_hash'       => $hash,
			'source_image_id' => $source_image_id,
			'deduplicated'    => $deduplicated,
		);
	}

	/**
	 * @param array<string,mixed> $payload
	 */
	private function sideload_description( array $payload ): string {
		if ( ! empty( $payload['description'] ) ) {
			return sanitize_text_field( (string) $payload['description'] );
		}
		if ( ! empty( $payload['title'] ) ) {
			return sanitize_text_field( (string) $payload['title'] );
		}
		return '';
	}

	private function build_filename( string $original, string $ext ): string {
		$base = pathinfo( $original, PATHINFO_FILENAME );
		$base = sanitize_file_name( is_string( $base ) && $base !== '' ? $base : 'seoauto-image' );
		$base = $base !== '' ? $base : 'seoauto-image';
		return $base . '.' . $ext;
	}

	/**
	 * @return array{tmp:string,name:string}|WP_Error
	 */
	private function decode_base64_upload( string $raw, string $filename ): array|WP_Error {
		$raw = trim( $raw );
		if ( preg_match( '#^data:([^;]+);base64,(.+)$#s', $raw, $m ) ) {
			$raw = $m[2];
		}
		$bin = base64_decode( $raw, true );
		if ( false === $bin || $bin === '' ) {
			return new WP_Error(
				'seoauto_media_base64',
				__( 'file_base64 không hợp lệ.', 'seoauto-seo-helper' ),
				array( 'status' => 400, 'code' => 'seoauto_media_base64' )
			);
		}
		if ( strlen( $bin ) > $this->mime->max_bytes() ) {
			return new WP_Error(
				'seoauto_media_too_large',
				__( 'Ảnh vượt giới hạn dung lượng.', 'seoauto-seo-helper' ),
				array( 'status' => 413, 'code' => 'seoauto_media_too_large' )
			);
		}
		$tmp = wp_tempnam( 'seoauto-b64-' );
		if ( ! is_string( $tmp ) || $tmp === '' ) {
			return new WP_Error(
				'seoauto_media_temp',
				__( 'Không tạo được file tạm.', 'seoauto-seo-helper' ),
				array( 'status' => 500, 'code' => 'seoauto_media_temp' )
			);
		}
		file_put_contents( $tmp, $bin );
		return array(
			'tmp'  => $tmp,
			'name' => sanitize_file_name( $filename !== '' ? $filename : 'seoauto-image.jpg' ),
		);
	}
}
