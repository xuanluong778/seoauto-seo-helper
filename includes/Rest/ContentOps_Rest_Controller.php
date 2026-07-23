<?php
/**
 * REST routes for ContentOps Preview → Backup → Apply → Recheck → Rollback.
 *
 * Does not alter HMAC, pairing, updater, or publish routes.
 *
 * @package SEOAuto\SEOHelper
 */

declare(strict_types=1);

namespace SEOAuto\SEOHelper\Rest;

use SEOAuto\SEOHelper\Auth\Request_Authenticator;
use SEOAuto\SEOHelper\ContentOps\ContentOps_Service;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class ContentOps_Rest_Controller {

	public function __construct(
		private Request_Authenticator $auth,
		private ContentOps_Service $ops
	) {}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		$ns   = Rest_Controller::REST_NAMESPACE;
		$perm = $this->auth->permission(
			array(
				'require_connected'   => true,
				'require_entitlement' => true,
				'feature'             => ContentOps_Service::FEATURE,
			)
		);

		$routes = array(
			'/content/preview'  => array( 'POST', 'preview' ),
			'/content/backup'   => array( 'POST', 'backup' ),
			'/content/apply'    => array( 'POST', 'apply' ),
			'/content/recheck'  => array( 'POST', 'recheck' ),
			'/content/rollback' => array( 'POST', 'rollback' ),
		);

		foreach ( $routes as $path => $cfg ) {
			register_rest_route(
				$ns,
				$path,
				array(
					'methods'             => $cfg[0],
					'callback'            => array( $this, $cfg[1] ),
					'permission_callback' => $perm,
				)
			);
		}

		register_rest_route(
			$ns,
			'/content/batches/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_batch' ),
				'permission_callback' => $perm,
				'args'                => array(
					'id' => array(
						'type'     => 'integer',
						'required' => true,
						'minimum'  => 1,
					),
				),
			)
		);

		register_rest_route(
			$ns,
			'/content/batches',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_batches' ),
				'permission_callback' => $perm,
			)
		);
	}

	public function preview( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$body = $this->body( $request );
		$out  = $this->ops->preview( $body );
		return $this->respond( $out );
	}

	public function backup( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$body = $this->body( $request );
		if ( empty( $body['request_id'] ) ) {
			$header = (string) $request->get_header( 'x-seoauto-request-id' );
			if ( $header !== '' ) {
				$body['request_id'] = $header;
			}
		}
		$out = $this->ops->backup( $body );
		return $this->respond( $out, 201 );
	}

	public function apply( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->respond( $this->ops->apply( $this->body( $request ) ) );
	}

	public function recheck( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->respond( $this->ops->recheck( $this->body( $request ) ) );
	}

	public function rollback( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->respond( $this->ops->rollback( $this->body( $request ) ) );
	}

	public function get_batch( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->respond( $this->ops->get_batch( (int) $request['id'] ) );
	}

	public function list_batches( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$limit = (int) $request->get_param( 'limit' );
		if ( $limit <= 0 ) {
			$limit = 20;
		}
		return new WP_REST_Response(
			array(
				'batches' => $this->ops->recent_batches( $limit ),
			),
			200
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function body( WP_REST_Request $request ): array {
		$params = $request->get_json_params();
		return is_array( $params ) ? $params : array();
	}

	/**
	 * @param array<string,mixed>|WP_Error $out
	 */
	private function respond( array|WP_Error $out, int $ok_status = 200 ): WP_REST_Response|WP_Error {
		if ( $out instanceof WP_Error ) {
			return $out;
		}
		return new WP_REST_Response( $out, $ok_status );
	}
}
