<?php

namespace SmartCloud\AgentComposer\Infrastructure\WordPress;

use SmartCloud\AgentComposer\Infrastructure\Persistence\AuditTable;
use SmartCloud\AgentComposer\Security\McpAccessGuard;
use SmartCloud\AgentComposer\Security\McpSecuritySettings;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class McpSecurityController {
	public function __construct(
		private readonly McpSecuritySettings $settings,
		private readonly McpAccessGuard $guard,
		private readonly AuditTable $audit
	) {}

	public function register_routes(): void {
		register_rest_route(
			StatusController::NAMESPACE,
			'/mcp-access',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get' ),
					'permission_callback' => static fn(): bool => current_user_can( Activation::CAP_EDIT_CONFIG ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'save' ),
					'permission_callback' => static fn(): bool => current_user_can( Activation::CAP_EDIT_CONFIG ),
				),
			)
		);
	}

	public function get( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );
		return new WP_REST_Response( $this->response(), 200 );
	}

	public function save( WP_REST_Request $request ): WP_REST_Response {
		$value = $request->get_json_params();
		$value = is_array( $value ) ? $value : array();
		$normalized = $this->settings->normalize( $value );
		$provider   = $this->settings->provider_configuration( $normalized );
		$manual     = $normalized['identity_provider']['manual'];
		$manual_complete = '' !== $manual['region'] && '' !== $manual['user_pool_id'];
		if ( $normalized['identity_provider']['prefer_manual'] && ! $manual_complete ) {
			return new WP_REST_Response(
				array( 'code' => 'smartcloud_composer_manual_identity_incomplete', 'message' => 'AWS Region and User Pool ID are required when manual identity configuration is selected.' ),
				400
			);
		}
		if ( 'none' === $provider['source'] && ! $manual_complete ) {
			return new WP_REST_Response(
				array( 'code' => 'smartcloud_composer_identity_unresolved', 'message' => 'No Cognito provider could be resolved. Enter AWS Region and User Pool ID before saving MCP access.' ),
				400
			);
		}
		if ( $normalized['enforce_scopes'] && '' === $normalized['oauth_resource_uri'] ) {
			return new WP_REST_Response(
				array( 'code' => 'smartcloud_composer_oauth_resource_required', 'message' => 'External MCP resource URI is required when Composer OAuth scope enforcement is enabled.' ),
				400
			);
		}
		$saved = $this->settings->save( $normalized );
		$this->audit->record(
			'mcp-security-settings-updated',
			'success',
			array(
				'group_role_count' => count( $saved['group_roles'] ),
				'client_count'     => count( $saved['clients'] ),
				'enforce_scopes'   => $saved['enforce_scopes'],
				'oauth_resource_configured' => '' !== $saved['oauth_resource_uri'],
				'oauth_resource_sha256' => '' !== $saved['oauth_resource_uri'] ? hash( 'sha256', $saved['oauth_resource_uri'] ) : '',
			)
		);
		return new WP_REST_Response( $this->response(), 200 );
	}

	private function response(): array {
		return array(
			'settings' => $this->settings->get(),
			'status'   => $this->guard->status(),
			'invariants' => array(
				'direct_agent_publish'       => false,
				'human_confirmation_required'=> true,
				'supported_roles'            => McpSecuritySettings::ROLES,
				'supported_scopes'           => array( 'composer.read', 'composer.draft', 'composer.propose', 'composer.publish.request' ),
			),
		);
	}
}
