<?php

namespace SmartCloud\AgentComposer\Integration\Mcp;

use WP\MCP\Infrastructure\ErrorHandling\McpErrorFactory;
use WP\MCP\Transport\Contracts\McpRestTransportInterface;
use WP\MCP\Transport\Infrastructure\JsonRpcResponseBuilder;
use WP\MCP\Transport\Infrastructure\McpTransportContext;
use WP\MCP\Transport\Infrastructure\McpTransportHelperTrait;

/**
 * Stateless HTTP transport for Composer's external OAuth principals.
 *
 * The Adapter's stock HTTP transport stores sessions in WordPress user meta.
 * Composer principals intentionally are not WordPress users, so every request
 * is authenticated by the Composer transport callback and routed without the
 * Adapter's WordPress-user session layer.
 */
final class ComposerHttpTransport implements McpRestTransportInterface {
	use McpTransportHelperTrait;

	public function __construct( private readonly McpTransportContext $context ) {
		add_action( 'rest_api_init', array( $this, 'register_routes' ), 16 );
	}

	public function register_routes(): void {
		$server = $this->context->mcp_server;
		register_rest_route(
			$server->get_server_route_namespace(),
			$server->get_server_route(),
			array(
				'methods'             => array( 'POST', 'GET', 'DELETE' ),
				'callback'            => array( $this, 'handle_request' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);
	}

	public function check_permission( \WP_REST_Request $request ): bool|\WP_Error {
		$callback = $this->context->transport_permission_callback;
		if ( null === $callback ) {
			return is_user_logged_in();
		}

		try {
			$result = call_user_func( $callback, $request );
			return is_wp_error( $result ) ? $result : (bool) $result;
		} catch ( \Throwable $exception ) {
			$this->context->error_handler->log(
				'Composer HTTP transport permission callback failed: ' . $exception->getMessage(),
				array( 'ComposerHttpTransport::check_permission' )
			);
			return new \WP_Error(
				'smartcloud_composer_transport_authentication_failed',
				__( 'Composer transport authentication failed.', 'smartcloud-agent-composer' ),
				array( 'status' => 401 )
			);
		}
	}

	public function handle_request( \WP_REST_Request $request ): \WP_REST_Response {
		if ( 'GET' === $request->get_method() ) {
			return new \WP_REST_Response( null, 405 );
		}
		if ( 'DELETE' === $request->get_method() ) {
			return new \WP_REST_Response( null, 200 );
		}
		if ( 'POST' !== $request->get_method() ) {
			return $this->error_response( McpErrorFactory::invalid_request( null, 'Method not allowed' )->toArray(), 405 );
		}

		$body = $request->get_json_params();
		if ( null === $body ) {
			return $this->error_response( McpErrorFactory::parse_error( null, 'Invalid JSON in request body' )->toArray(), 400 );
		}

		try {
			$is_batch = JsonRpcResponseBuilder::is_batch_request( $body );
			$messages = JsonRpcResponseBuilder::normalize_messages( $body );
			$response = JsonRpcResponseBuilder::process_messages(
				$messages,
				$is_batch,
				fn( mixed $message ): ?array => $this->process_message( $message )
			);

			if ( null === $response ) {
				return new \WP_REST_Response( null, 202 );
			}

			$status = ! $is_batch && isset( $response['error'] )
				? McpErrorFactory::get_http_status_for_error( $response )
				: 200;
			return new \WP_REST_Response( $response, $status );
		} catch ( \Throwable $exception ) {
			$this->context->error_handler->log(
				'Unexpected Composer HTTP transport error: ' . $exception->getMessage(),
				array( 'ComposerHttpTransport::handle_request' )
			);
			return $this->error_response( McpErrorFactory::internal_error( null, 'Handler error occurred' )->toArray(), 500 );
		}
	}

	private function process_message( mixed $message ): ?array {
		if ( ! is_array( $message ) ) {
			return McpErrorFactory::invalid_request( null, 'JSON-RPC message must be an object' )->toArray();
		}

		$validation = McpErrorFactory::validate_jsonrpc_message( $message );
		if ( true !== $validation ) {
			return $validation->toArray();
		}

		if ( isset( $message['method'] ) && ! array_key_exists( 'id', $message ) ) {
			return null;
		}
		if ( ! isset( $message['method'] ) || ! array_key_exists( 'id', $message ) ) {
			return null;
		}

		$request_id = $message['id'];
		$params = isset( $message['params'] ) && is_array( $message['params'] ) ? $message['params'] : array();
		$result = $this->context->request_router->route_request(
			(string) $message['method'],
			$params,
			$request_id,
			'HTTP'
		);

		if ( isset( $result['error'] ) && is_array( $result['error'] ) ) {
			return JsonRpcResponseBuilder::create_error_response( $request_id, $result['error'] );
		}

		return JsonRpcResponseBuilder::create_success_response( $request_id, $result );
	}

	private function error_response( array $error, int $status ): \WP_REST_Response {
		return new \WP_REST_Response( $error, $status );
	}
}
