<?php
/**
 * Real MIME / extension guards for inbound media.
 *
 * @package SEOAuto\SEOHelper
 */

declare(strict_types=1);

namespace SEOAuto\SEOHelper\Media;

use WP_Error;

final class Mime_Guard {

	/** Default max upload size (5 MiB). */
	public const DEFAULT_MAX_BYTES = 5_242_880;

	/**
	 * @return list<string>
	 */
	public function allowed_mimes(): array {
		$mimes = array(
			'image/jpeg',
			'image/png',
			'image/gif',
			'image/webp',
		);
		/**
		 * Allow SVG only when explicitly enabled (default blocked).
		 *
		 * @param bool $allow
		 */
		if ( (bool) apply_filters( 'seoauto_helper_allow_svg_upload', false ) ) {
			$mimes[] = 'image/svg+xml';
		}
		/** @var list<string> $mimes */
		$mimes = apply_filters( 'seoauto_helper_allowed_media_mimes', $mimes );
		return is_array( $mimes ) ? array_values( array_unique( array_map( 'strval', $mimes ) ) ) : array(
			'image/jpeg',
			'image/png',
			'image/gif',
			'image/webp',
		);
	}

	public function max_bytes(): int {
		$max = (int) apply_filters( 'seoauto_helper_max_media_bytes', self::DEFAULT_MAX_BYTES );
		return max( 1024, $max );
	}

	/**
	 * Validate temp file: size, real MIME, magic bytes, dangerous names.
	 *
	 * @return array{mime:string,ext:string,width:int,height:int}|WP_Error
	 */
	public function validate_file( string $tmp_path, string $original_name = '' ): array|WP_Error {
		if ( ! is_readable( $tmp_path ) ) {
			return $this->err( 'seoauto_media_unreadable', __( 'File media không đọc được.', 'seoauto-seo-helper' ), 400 );
		}

		$size = filesize( $tmp_path );
		if ( false === $size || $size <= 0 ) {
			return $this->err( 'seoauto_media_empty', __( 'File media trống.', 'seoauto-seo-helper' ), 400 );
		}
		if ( $size > $this->max_bytes() ) {
			return $this->err( 'seoauto_media_too_large', __( 'Ảnh vượt giới hạn dung lượng.', 'seoauto-seo-helper' ), 413 );
		}

		$name_check = $this->assert_safe_filename( $original_name !== '' ? $original_name : basename( $tmp_path ) );
		if ( $name_check instanceof WP_Error ) {
			return $name_check;
		}

		$head = (string) file_get_contents( $tmp_path, false, null, 0, 512 );
		if ( $this->looks_like_php_or_phar( $head ) || $this->looks_like_php_or_phar( (string) file_get_contents( $tmp_path, false, null, 0, 8192 ) ) ) {
			return $this->err( 'seoauto_media_executable', __( 'Phát hiện nội dung PHP/PHAR — bị chặn.', 'seoauto-seo-helper' ), 400 );
		}

		$mime = $this->detect_mime( $tmp_path );
		if ( $mime === '' ) {
			return $this->err( 'seoauto_media_mime', __( 'Không xác định được MIME thực tế.', 'seoauto-seo-helper' ), 400 );
		}

		$mime = strtolower( $mime );
		if ( $mime === 'image/svg+xml' && ! (bool) apply_filters( 'seoauto_helper_allow_svg_upload', false ) ) {
			return $this->err( 'seoauto_media_svg_blocked', __( 'SVG bị chặn mặc định.', 'seoauto-seo-helper' ), 400 );
		}

		if ( ! in_array( $mime, $this->allowed_mimes(), true ) ) {
			return $this->err(
				'seoauto_media_mime_denied',
				sprintf(
					/* translators: %s: mime type */
					__( 'MIME không được phép: %s', 'seoauto-seo-helper' ),
					$mime
				),
				400
			);
		}

		if ( $this->is_dangerous_mime( $mime ) ) {
			return $this->err( 'seoauto_media_executable', __( 'MIME thực thi bị chặn.', 'seoauto-seo-helper' ), 400 );
		}

		$width  = 0;
		$height = 0;
		if ( $mime !== 'image/svg+xml' ) {
			$info = @getimagesize( $tmp_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( ! is_array( $info ) || empty( $info[0] ) || empty( $info[1] ) ) {
				return $this->err( 'seoauto_media_not_image', __( 'File không phải ảnh hợp lệ.', 'seoauto-seo-helper' ), 400 );
			}
			$width  = (int) $info[0];
			$height = (int) $info[1];
			if ( ! empty( $info['mime'] ) && strtolower( (string) $info['mime'] ) !== $mime ) {
				// Prefer getimagesize mime when it disagrees with finfo for images.
				$gi_mime = strtolower( (string) $info['mime'] );
				if ( in_array( $gi_mime, $this->allowed_mimes(), true ) ) {
					$mime = $gi_mime;
				}
			}
		}

		$ext = $this->extension_for_mime( $mime );
		if ( $ext === '' ) {
			return $this->err( 'seoauto_media_mime', __( 'Không map được extension từ MIME.', 'seoauto-seo-helper' ), 400 );
		}

		return array(
			'mime'   => $mime,
			'ext'    => $ext,
			'width'  => $width,
			'height' => $height,
		);
	}

	/**
	 * @return true|WP_Error
	 */
	public function assert_safe_filename( string $name ): bool|WP_Error {
		$name = strtolower( basename( str_replace( '\\', '/', $name ) ) );
		if ( $name === '' || $name === '.' || $name === '..' ) {
			return $this->err( 'seoauto_media_filename', __( 'Tên file không hợp lệ.', 'seoauto-seo-helper' ), 400 );
		}

		$blocked_ext = array(
			'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar', 'pht',
			'exe', 'dll', 'so', 'bat', 'cmd', 'com', 'msi', 'scr', 'js', 'jar',
			'sh', 'bash', 'cgi', 'pl', 'py', 'rb', 'asp', 'aspx', 'jsp',
		);
		if ( ! (bool) apply_filters( 'seoauto_helper_allow_svg_upload', false ) ) {
			$blocked_ext[] = 'svg';
			$blocked_ext[] = 'svgz';
		}

		// Block double extensions like image.php.jpg
		$parts = explode( '.', $name );
		if ( count( $parts ) >= 2 ) {
			foreach ( array_slice( $parts, 0, -1 ) as $part ) {
				if ( in_array( $part, $blocked_ext, true ) ) {
					return $this->err( 'seoauto_media_executable', __( 'Tên file chứa extension nguy hiểm.', 'seoauto-seo-helper' ), 400 );
				}
			}
			$last = (string) end( $parts );
			if ( in_array( $last, $blocked_ext, true ) ) {
				return $this->err( 'seoauto_media_executable', __( 'Extension file bị chặn.', 'seoauto-seo-helper' ), 400 );
			}
		}

		return true;
	}

	public function detect_mime( string $path ): string {
		if ( function_exists( 'finfo_open' ) ) {
			$finfo = finfo_open( FILEINFO_MIME_TYPE );
			if ( false !== $finfo ) {
				$mime = finfo_file( $finfo, $path );
				finfo_close( $finfo );
				if ( is_string( $mime ) && $mime !== '' ) {
					return $mime;
				}
			}
		}
		if ( function_exists( 'mime_content_type' ) ) {
			$mime = mime_content_type( $path );
			if ( is_string( $mime ) && $mime !== '' ) {
				return $mime;
			}
		}
		$info = @getimagesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( is_array( $info ) && ! empty( $info['mime'] ) ) {
			return (string) $info['mime'];
		}
		return '';
	}

	public function extension_for_mime( string $mime ): string {
		$map = array(
			'image/jpeg'    => 'jpg',
			'image/png'     => 'png',
			'image/gif'     => 'gif',
			'image/webp'    => 'webp',
			'image/svg+xml' => 'svg',
		);
		return $map[ strtolower( $mime ) ] ?? '';
	}

	public function looks_like_php_or_phar( string $bytes ): bool {
		if ( $bytes === '' ) {
			return false;
		}
		if ( str_starts_with( $bytes, '<?php' ) || str_starts_with( $bytes, '<?=') ) {
			return true;
		}
		if ( str_contains( $bytes, '<?php' ) || str_contains( $bytes, '<?=') ) {
			return true;
		}
		// PHAR magic
		if ( str_starts_with( $bytes, 'phar' ) || str_contains( $bytes, '__HALT_COMPILER' ) ) {
			return true;
		}
		// PE / ELF executables
		if ( str_starts_with( $bytes, 'MZ' ) || str_starts_with( $bytes, "\x7fELF" ) ) {
			return true;
		}
		return false;
	}

	private function is_dangerous_mime( string $mime ): bool {
		$blocked = array(
			'application/x-php',
			'application/php',
			'text/x-php',
			'application/x-httpd-php',
			'application/x-phar',
			'application/x-executable',
			'application/x-msdownload',
			'application/x-sh',
			'application/javascript',
			'text/javascript',
			'application/x-javascript',
		);
		return in_array( strtolower( $mime ), $blocked, true );
	}

	private function err( string $code, string $message, int $status ): WP_Error {
		return new WP_Error( $code, $message, array( 'status' => $status, 'code' => $code ) );
	}
}
