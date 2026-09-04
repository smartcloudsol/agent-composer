<?php

namespace SmartCloud\AgentComposer\Infrastructure\WordPress;

use SmartCloud\AgentComposer\Execution\Content_Proposal_Service;
use SmartCloud\AgentComposer\Execution\Execution_Exception;
use Throwable;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/** Human review surface. Merge, reject, and return-for-changes are deliberately not Abilities. */
final class ContentProposalController {
	public function __construct( private readonly Content_Proposal_Service $proposals ) {}

	public function register_routes(): void {
		register_rest_route( StatusController::NAMESPACE, '/content-proposals', array(
			'methods' => WP_REST_Server::READABLE,
			'callback' => array( $this, 'listing' ),
			'permission_callback' => $this->permission(),
			'args' => array(
				'state' => array( 'type' => 'string', 'enum' => array( 'any', 'working', 'ready-for-review', 'merged', 'rejected', 'superseded' ) ),
				'states' => array( 'type' => 'string', 'default' => 'ready-for-review', 'maxLength' => 160 ),
				'search' => array( 'type' => 'string', 'default' => '', 'maxLength' => 200 ),
				'page' => array( 'type' => 'integer', 'default' => 1, 'minimum' => 1 ),
				'per_page' => array( 'type' => 'integer', 'default' => 20, 'minimum' => 5, 'maximum' => 50 ),
			),
		) );
		register_rest_route( StatusController::NAMESPACE, '/content-proposals/(?P<id>\d+)', array(
			'methods' => WP_REST_Server::READABLE,
			'callback' => array( $this, 'get' ),
			'permission_callback' => $this->permission(),
		) );
		register_rest_route( StatusController::NAMESPACE, '/content-proposals/(?P<id>\d+)/merge', array(
			'methods' => WP_REST_Server::CREATABLE,
			'callback' => array( $this, 'merge' ),
			'permission_callback' => $this->permission( true ),
			'args' => array(
				'expected_modified_gmt' => array( 'type' => 'string', 'required' => true, 'format' => 'date-time' ),
				'expected_revision' => array( 'type' => 'string', 'required' => true, 'format' => 'uuid' ),
				'confirmation' => array( 'type' => 'string', 'required' => true, 'maxLength' => 80 ),
			),
		) );
		register_rest_route( StatusController::NAMESPACE, '/content-proposals/(?P<id>\d+)/reject', array(
			'methods' => WP_REST_Server::CREATABLE,
			'callback' => array( $this, 'reject' ),
			'permission_callback' => $this->permission( true ),
			'args' => array(
				'reason' => array( 'type' => 'string', 'required' => true, 'minLength' => 1, 'maxLength' => 1000 ),
				'expected_modified_gmt' => array( 'type' => 'string', 'required' => true, 'format' => 'date-time' ),
				'expected_revision' => array( 'type' => 'string', 'required' => true, 'format' => 'uuid' ),
			),
		) );
		register_rest_route( StatusController::NAMESPACE, '/content-proposals/(?P<id>\d+)/return-for-changes', array(
			'methods' => WP_REST_Server::CREATABLE,
			'callback' => array( $this, 'return_for_changes' ),
			'permission_callback' => $this->permission( true ),
			'args' => array(
				'reason' => array( 'type' => 'string', 'required' => true, 'minLength' => 1, 'maxLength' => 1000 ),
				'expected_modified_gmt' => array( 'type' => 'string', 'required' => true, 'format' => 'date-time' ),
				'expected_revision' => array( 'type' => 'string', 'required' => true, 'format' => 'uuid' ),
			),
		) );
	}

	public function listing( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$legacy_state = trim( (string) $request->get_param( 'state' ) );
		$raw_states = '' !== $legacy_state ? $legacy_state : trim( (string) $request->get_param( 'states' ) );
		$states = 'any' === $raw_states ? 'any' : array_filter( array_map( 'sanitize_key', explode( ',', $raw_states ) ) );
		return $this->respond( fn(): array => $this->proposals->list(
			$states,
			(string) $request->get_param( 'search' ),
			absint( $request->get_param( 'page' ) ),
			absint( $request->get_param( 'per_page' ) )
		) );
	}

	public function get( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->respond( fn(): array => $this->proposals->inspect( absint( $request['id'] ) ) );
	}

	public function merge( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->respond( fn(): array => $this->proposals->merge( absint( $request['id'] ), array(
			'expected_modified_gmt' => (string) $request->get_param( 'expected_modified_gmt' ),
			'expected_revision' => (string) $request->get_param( 'expected_revision' ),
			'confirmation' => (string) $request->get_param( 'confirmation' ),
		) ) );
	}

	public function reject( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->respond( fn(): array => $this->proposals->reject(
			absint( $request['id'] ),
			(string) $request->get_param( 'reason' ),
			array(
				'expected_modified_gmt' => (string) $request->get_param( 'expected_modified_gmt' ),
				'expected_revision' => (string) $request->get_param( 'expected_revision' ),
			)
		) );
	}

	public function return_for_changes( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->respond( fn(): array => $this->proposals->return_for_changes(
			absint( $request['id'] ),
			(string) $request->get_param( 'reason' ),
			array(
				'expected_modified_gmt' => (string) $request->get_param( 'expected_modified_gmt' ),
				'expected_revision' => (string) $request->get_param( 'expected_revision' ),
			)
		) );
	}

	private function permission( bool $mutation = false ): callable {
		return static function ( WP_REST_Request $request ) use ( $mutation ): bool|WP_Error {
			if ( ! current_user_can( Activation::CAP_MERGE_PROPOSALS ) ) {
				return new WP_Error( 'smartcloud_composer_proposal_forbidden', 'Only an authorized human reviewer can access content proposals.', array( 'status' => rest_authorization_required_code() ) );
			}
			if ( $mutation && ! wp_verify_nonce( (string) $request->get_header( 'x_wp_nonce' ), 'wp_rest' ) ) {
				return new WP_Error( 'smartcloud_composer_invalid_nonce', 'A valid WordPress REST nonce is required.', array( 'status' => 403 ) );
			}
			return true;
		};
	}

	private function respond( callable $callback ): WP_REST_Response|WP_Error {
		try {
			return new WP_REST_Response( $callback(), 200 );
		} catch ( Execution_Exception $error ) {
			$code = $error->get_execution_code();
			$status = str_contains( $code, 'conflict' ) || 'edit_conflict' === $code ? 409 : ( str_contains( $code, 'denied' ) ? 403 : 400 );
			return new WP_Error( 'smartcloud_composer_' . $code, $error->getMessage(), array( 'status' => $status ) );
		} catch ( Throwable $error ) {
			do_action( 'smartcloud_composer_internal_error', $error );
			return new WP_Error( 'smartcloud_composer_internal_error', 'Composer could not complete the proposal operation.', array( 'status' => 500 ) );
		}
	}
}
