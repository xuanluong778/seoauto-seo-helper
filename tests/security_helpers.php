<?php
/**
 * Shared helpers for security test suites.
 *
 * @package SEOAuto\SEOHelper
 */

declare(strict_types=1);

require_once dirname( __DIR__ ) . '/includes/Entitlement/Entitlement_Verifier.php';

use SEOAuto\SEOHelper\Entitlement\Entitlement_Verifier;

/**
 * @param array<string,mixed> $data
 * @return array<string,mixed>
 */
function seoauto_test_signed_entitlement( array $data, string $site_secret = 'plain-secret' ): array {
	$sig            = Entitlement_Verifier::sign( $data, $site_secret );
	$data['signature'] = $sig;
	return $data;
}

/**
 * @param array<string,mixed> $entitlement Unsigned entitlement fields.
 */
function seoauto_test_pair_options( array $entitlement, string $status = 'connected', string $secret = 'plain-secret' ): void {
	$signed = seoauto_test_signed_entitlement( $entitlement, $secret );
	$sig    = (string) ( $signed['signature'] ?? '' );
	unset( $signed['signature'] );
	$GLOBALS['seoauto_test_options'] = array(
		'seoauto_helper_status'             => $status,
		'seoauto_helper_site_id'            => 'site-test-1',
		'seoauto_helper_site_secret'        => $secret,
		'seoauto_helper_connection_id'      => 42,
		'seoauto_helper_organization_id'    => 7,
		'seoauto_helper_api_base'           => 'https://seoauto.vn',
		'seoauto_helper_entitlement_json'   => wp_json_encode( $signed ),
		'seoauto_helper_entitlement_sig'    => $sig,
	);
}
