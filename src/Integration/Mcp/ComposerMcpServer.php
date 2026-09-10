<?php

namespace SmartCloud\AgentComposer\Integration\Mcp;

use SmartCloud\AgentComposer\Execution\Abilities;
use SmartCloud\AgentComposer\Execution\Ability_Provider_Registry;
use SmartCloud\AgentComposer\Execution\Localization_Provider_Registry;
use SmartCloud\AgentComposer\Integration\Abilities\ExecutionAbilityAliases;

final class ComposerMcpServer {
	public const SERVER_ID = 'smartcloud-agent-composer';
	public const HTTP_ENDPOINT = '/wp-json/mcp/smartcloud-agent-composer';
	public const PREVIEW_RESOURCE_URI = 'ui://smartcloud-agent-composer/rendered-preview/v5.html';
	public const PREVIEW_RESOURCE_URI_V4 = 'ui://smartcloud-agent-composer/rendered-preview/v4.html';
	public const PREVIEW_RESOURCE_URI_V3 = 'ui://smartcloud-agent-composer/rendered-preview/v3.html';
	public const PREVIEW_RESOURCE_URI_V2 = 'ui://smartcloud-agent-composer/rendered-preview/v2.html';
	public const PREVIEW_RESOURCE_URI_V1 = 'ui://smartcloud-agent-composer/rendered-preview/v1.html';

	private array $registered_adapters = array();

	public function __construct(
		private readonly Ability_Provider_Registry $providers,
		private readonly Localization_Provider_Registry $localization
	) {}

	public function register( object $adapter ): void {
		$id = spl_object_id( $adapter );
		if ( isset( $this->registered_adapters[ $id ] ) || ! method_exists( $adapter, 'create_server' ) ) {
			return;
		}
		if (
			! class_exists( '\\WP\\MCP\\Transport\\HttpTransport' )
			|| ! class_exists( '\\WP\\MCP\\Infrastructure\\ErrorHandling\\ErrorLogMcpErrorHandler' )
			|| ! class_exists( '\\WP\\MCP\\Infrastructure\\Observability\\NullMcpObservabilityHandler' )
		) {
			return;
		}
		$names = array_merge( Abilities::names(), ExecutionAbilityAliases::canonical_names(), $this->providers->mcp_ability_names(), $this->localization->mcp_ability_names() );
		$names = array_values( array_filter( array_unique( $names ), static fn( string $name ): bool => wp_has_ability( $name ) ) );
		if ( empty( $names ) ) {
			return;
		}

		$this->registered_adapters[ $id ] = true;
		$resources = array_values( array_filter( Abilities::resource_names(), static fn( string $name ): bool => wp_has_ability( $name ) ) );
		$this->create_server( $adapter, self::SERVER_ID, 'SmartCloud Agent Composer', $names, $resources );
	}

	private function create_server( object $adapter, string $id, string $label, array $names, array $resources ): void {
		$adapter->create_server(
			$id,
			'mcp',
			$id,
			$label,
			'Governed discovery, draft creation, and published-content proposal execution through active Composer configuration. After the final successful draft write, call get-rendered-preview with its fresh concurrency tokens and let the inline rendered HTML preview be delivered before reporting completion. For a published-content update proposal, the exact preview response supplies the rendered_preview_token required by submit-content-proposal, so previewing must happen after the last write and before submission.',
			SMARTCLOUD_COMPOSER_VERSION,
			array( \WP\MCP\Transport\HttpTransport::class ),
			\WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler::class,
			\WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler::class,
			$names,
			$resources,
			array()
		);
	}
}
