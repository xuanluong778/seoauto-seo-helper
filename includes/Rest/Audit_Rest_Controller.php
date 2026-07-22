<?php
/**
 * REST routes for SEO audit (HMAC). Does not alter publish routes.
 *
 * @package SEOAuto\SEOHelper
 */

declare(strict_types=1);

namespace SEOAuto\SEOHelper\Rest;

use SEOAuto\SEOHelper\Auth\Request_Authenticator;
use SEOAuto\SEOHelper\SeoAudit\Audit_Job_Runner;
use SEOAuto\SEOHelper\SeoAudit\Audit_Run_Store;
use SEOAuto\SEOHelper\SeoAudit\Object_Context;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class Audit_Rest_Controller {

	public function __construct(
		private Request_Authenticator $auth,
		private Audit_Job_Runner $runner
	) {}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		$ns = Rest_Controller::REST_NAMESPACE;

		register_rest_route(
			$ns,
			'/audit/scan',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'start_scan' ),
				'permission_callback' => $this->auth->permission(
					array(
						'require_connected'   => true,
						'require_entitlement' => true,
						'feature'             => Audit_Job_Runner::FEATURE,
					)
				),
			)
		);

		register_rest_route(
			$ns,
			'/audit/runs/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_run' ),
				'permission_callback' => $this->auth->permission(
					array(
						'require_connected'   => true,
						'require_entitlement' => true,
						'feature'             => Audit_Job_Runner::FEATURE,
					)
				),
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
			'/audit/issues',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_issues' ),
				'permission_callback' => $this->auth->permission(
					array(
						'require_connected'   => true,
						'require_entitlement' => true,
						'feature'             => Audit_Job_Runner::FEATURE,
					)
				),
				'args'                => array(
					'run_id'      => array(
						'type'     => 'integer',
						'required' => false,
						'minimum'  => 1,
					),
					'severity'    => array(
						'type'     => 'string',
						'required' => false,
					),
					'status'      => array(
						'type'     => 'string',
						'required' => false,
					),
					'object_type' => array(
						'type'     => 'string',
						'required' => false,
					),
					'limit'       => array(
						'type'     => 'integer',
						'required' => false,
						'default'  => 50,
						'minimum'  => 1,
						'maximum'  => 200,
					),
					'offset'      => array(
						'type'     => 'integer',
						'required' => false,
						'default'  => 0,
						'minimum'  => 0,
					),
				),
			)
		);

		register_rest_route(
			$ns,
			'/jobs/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_job' ),
				'permission_callback' => $this->auth->permission(
					array(
						'require_connected'   => true,
						'require_entitlement' => false,
					)
				),
				'args'                => array(
					'id' => array(
						'type'     => 'integer',
						'required' => true,
						'minimum'  => 1,
					),
				),
			)
		);
	}

	public function start_scan( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = array();
		}

		$request_id = (string) ( $body['request_id'] ?? $request->get_header( 'x-seoauto-request-id' ) ?? '' );
		$types      = $body['post_types'] ?? Object_Context::audit_post_types();
		$batch      = (int) ( $body['batch_size'] ?? 20 );

		$result = $this->runner->enqueue_scan(
			array(
				'request_id' => $request_id,
				'post_types' => is_array( $types ) ? $types : Object_Context::audit_post_types(),
				'mode'       => 'scan_only',
				'batch_size' => $batch,
			)
		);

		if ( $result instanceof WP_Error ) {
			return $result;
		}

		$run = $this->runner->runs()->get( (int) $result['run_id'] );
		$job = $this->runner->jobs()->get( (int) $result['job_id'] );

		return new WP_REST_Response(
			array(
				'ok'                => true,
				'request_id'        => $result['request_id'],
				'job_id'            => $result['job_id'],
				'run_id'            => $result['run_id'],
				'idempotent_replay' => ! empty( $result['idempotent_replay'] ),
				'message'           => $result['message'] ?? '',
				'run'               => $run,
				'job'               => $job,
				'checkers'          => $this->runner->engine()->checker_ids(),
			),
			! empty( $result['idempotent_replay'] ) ? 200 : 202
		);
	}

	public function get_run( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id  = (int) $request['id'];
		$run = $this->runner->runs()->get( $id );
		if ( null === $run ) {
			return new WP_Error( 'seoauto_run_not_found', __( 'Audit run không tồn tại.', 'seoauto-seo-helper' ), array( 'status' => 404 ) );
		}
		return new WP_REST_Response(
			array(
				'ok'   => true,
				'run'  => $run,
				'done' => in_array(
					(string) ( $run['status'] ?? '' ),
					array(
						Audit_Run_Store::STATUS_COMPLETED,
						Audit_Run_Store::STATUS_FAILED,
						Audit_Run_Store::STATUS_CANCELLED,
					),
					true
				),
			),
			200
		);
	}

	public function list_issues( WP_REST_Request $request ): WP_REST_Response {
		$args = array(
			'run_id'      => (int) ( $request['run_id'] ?? 0 ),
			'severity'    => (string) ( $request['severity'] ?? '' ),
			'status'      => (string) ( $request['status'] ?? '' ),
			'object_type' => (string) ( $request['object_type'] ?? '' ),
			'limit'       => (int) ( $request['limit'] ?? 50 ),
			'offset'      => (int) ( $request['offset'] ?? 0 ),
		);
		if ( $args['run_id'] <= 0 ) {
			unset( $args['run_id'] );
		}
		if ( $args['severity'] === '' ) {
			unset( $args['severity'] );
		}
		if ( $args['status'] === '' ) {
			unset( $args['status'] );
		}
		if ( $args['object_type'] === '' ) {
			unset( $args['object_type'] );
		}

		$items = $this->runner->issues()->query( $args );
		return new WP_REST_Response(
			array(
				'ok'     => true,
				'count'  => count( $items ),
				'issues' => $items,
			),
			200
		);
	}

	public function get_job( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$id  = (int) $request['id'];
		$job = $this->runner->jobs()->get( $id );
		if ( null === $job ) {
			return new WP_Error( 'seoauto_job_not_found', __( 'Job không tồn tại.', 'seoauto-seo-helper' ), array( 'status' => 404 ) );
		}
		$run = ! empty( $job['run_id'] ) ? $this->runner->runs()->get( (int) $job['run_id'] ) : null;
		return new WP_REST_Response(
			array(
				'ok'  => true,
				'job' => $job,
				'run' => $run,
			),
			200
		);
	}
}
