<?php

namespace SmartCloud\AgentComposer\Infrastructure\WordPress;

use SmartCloud\AgentComposer\Domain\Configuration\EntityType;
use WP_REST_Request;
use WP_REST_Response;

final class StatusController {
	public const NAMESPACE = 'smartcloud-agent-composer/v1';

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'status' ),
				'permission_callback' => static fn (): bool => current_user_can( Activation::CAP_VIEW_STATUS ),
			)
		);
	}

	public function status( WP_REST_Request $request ): WP_REST_Response {
		unset( $request );
		return new WP_REST_Response(
			array(
				'version'            => SMARTCLOUD_COMPOSER_VERSION,
				'contract_version'   => '1.0.0-rc.1',
				'active_config_set'  => (string) get_option( 'smartcloud_composer_active_config_set', '' ),
				'entity_types'       => EntityType::all(),
				'php_minimum'        => '8.1',
				'multisite_scope'    => 'per-site',
				'execution_boundary' => 'agent-owned-drafts-only',
				'mcp_endpoint'       => '/wp-json/mcp/smartcloud-agent-composer',
			),
			200
		);
	}
}
