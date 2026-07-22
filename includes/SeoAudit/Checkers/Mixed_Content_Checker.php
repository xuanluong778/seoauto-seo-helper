<?php
/**
 * Mixed content (HTTP assets on HTTPS site) checker.
 *
 * @package SEOAuto\SEOHelper
 */

declare(strict_types=1);

namespace SEOAuto\SEOHelper\SeoAudit\Checkers;

use SEOAuto\SEOHelper\SeoAudit\Audit_Codes;
use SEOAuto\SEOHelper\SeoAudit\Audit_Issue;
use SEOAuto\SEOHelper\SeoAudit\Checker_Interface;
use SEOAuto\SEOHelper\SeoAudit\Object_Context;

final class Mixed_Content_Checker implements Checker_Interface {

	public function id(): string {
		return 'mixed_content';
	}

	public function check( Object_Context $ctx ): array {
		$home_scheme = strtolower( (string) ( wp_parse_url( home_url(), PHP_URL_SCHEME ) ?? '' ) );
		if ( $home_scheme !== 'https' ) {
			return array();
		}

		$http_assets = array();
		foreach ( $ctx->images as $img ) {
			$src = (string) ( $img['src'] ?? '' );
			if ( str_starts_with( strtolower( $src ), 'http://' ) ) {
				$http_assets[] = $src;
			}
		}
		foreach ( $ctx->links as $url ) {
			if ( str_starts_with( strtolower( $url ), 'http://' ) ) {
				$host = strtolower( (string) ( wp_parse_url( $url, PHP_URL_HOST ) ?? '' ) );
				$home = strtolower( (string) ( wp_parse_url( home_url(), PHP_URL_HOST ) ?? '' ) );
				if ( $host === $home ) {
					$http_assets[] = $url;
				}
			}
		}
		$http_assets = array_values( array_unique( $http_assets ) );
		if ( $http_assets === array() ) {
			return array();
		}

		$suggest = array_map(
			static function ( string $u ): string {
				return preg_replace( '#^http://#i', 'https://', $u ) ?? $u;
			},
			array_slice( $http_assets, 0, 5 )
		);

		return array(
			new Audit_Issue(
				Audit_Codes::MIXED_CONTENT,
				Audit_Codes::SEVERITY_MEDIUM,
				Audit_Codes::RISK_SAFE,
				$ctx->object_type,
				$ctx->object_id,
				(string) count( $http_assets ),
				wp_json_encode( $suggest ) ?: '',
				sprintf(
					/* translators: %d: count */
					__( '%d URL HTTP trên site HTTPS (mixed content).', 'seoauto-seo-helper' ),
					count( $http_assets )
				),
				Audit_Codes::STATUS_OPEN,
				array( 'samples' => array_slice( $http_assets, 0, 5 ) )
			),
		);
	}
}
