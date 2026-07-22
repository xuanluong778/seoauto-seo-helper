<?php
/**
 * Sanitize inbound HTML — no PHP execution, strip dangerous shortcodes.
 *
 * @package SEOAuto\SEOHelper
 */

declare(strict_types=1);

namespace SEOAuto\SEOHelper\Security;

final class Content_Sanitizer {

	/**
	 * Shortcodes known to execute code or open RCE/XSS surfaces.
	 *
	 * @var list<string>
	 */
	private const DANGEROUS_SHORTCODES = array(
		'php',
		'eval',
		'exec',
		'system',
		'passthru',
		'shell',
		'shell_exec',
		'include',
		'require',
		'iframe',
		'script',
		'raw',
		'code',
		'insert_php',
		'run_php',
		'php_code',
		'execphp',
		'javascript',
	);

	/**
	 * Sanitize post body for storage. Never runs do_shortcode() / eval().
	 */
	public static function sanitize_content( string $content ): string {
		// Strip PHP / ASP-style tags before any HTML filtering.
		$content = (string) preg_replace( '/<\?(?:php|=)?[\s\S]*?\?>/i', '', $content );
		$content = (string) preg_replace( '/<%[\s\S]*?%>/', '', $content );
		$content = (string) preg_replace( '/\b(?:eval|assert|create_function|preg_replace\s*\(.+\/e)\s*\(/i', '', $content );

		$content = self::strip_dangerous_shortcodes( $content );

		// Allow safe HTML only — strips <script>, event handlers, etc.
		$content = wp_kses_post( $content );

		return $content;
	}

	public static function sanitize_excerpt( string $excerpt ): string {
		$excerpt = (string) preg_replace( '/<\?(?:php|=)?[\s\S]*?\?>/i', '', $excerpt );
		$excerpt = self::strip_dangerous_shortcodes( $excerpt );
		return sanitize_textarea_field( wp_strip_all_tags( $excerpt ) );
	}

	/**
	 * Remove shortcode tags that can execute code. Does not expand shortcodes.
	 */
	public static function strip_dangerous_shortcodes( string $content ): string {
		$names = apply_filters( 'seoauto_helper_dangerous_shortcodes', self::DANGEROUS_SHORTCODES );
		if ( ! is_array( $names ) ) {
			$names = self::DANGEROUS_SHORTCODES;
		}

		foreach ( $names as $name ) {
			$name = sanitize_key( (string) $name );
			if ( $name === '' ) {
				continue;
			}
			// Self-closing and paired forms: [name ...] [/name]
			$pattern = '/\[' . preg_quote( $name, '/' ) . '\b[^\]]*](?:[\s\S]*?\[\/' . preg_quote( $name, '/' ) . '\])?/i';
			$content = (string) preg_replace( $pattern, '', $content );
		}

		return $content;
	}
}
