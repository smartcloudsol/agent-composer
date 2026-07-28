<?php

namespace SmartCloud\AgentComposer\Integration\Mcp;

use SmartCloud\AgentComposer\Execution\Abilities;
use SmartCloud\AgentComposer\Execution\Ability_Provider_Registry;
use SmartCloud\AgentComposer\Integration\Abilities\ExecutionAbilityAliases;

final class ComposerMcpServer {
	public const SERVER_ID = 'smartcloud-agent-composer';
	public const HTTP_ENDPOINT = '/wp-json/mcp/smartcloud-agent-composer';

	private array $registered_adapters = array();

	public function __construct( private readonly Ability_Provider_Registry $providers ) {}

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
		$names = array_merge( Abilities::names(), ExecutionAbilityAliases::canonical_names(), $this->providers->mcp_ability_names() );
		$names = array_values( array_filter( array_unique( $names ), static fn( string $name ): bool => wp_has_ability( $name ) ) );
		if ( empty( $names ) ) {
			return;
		}

		$this->registered_adapters[ $id ] = true;
		$this->create_server( $adapter, self::SERVER_ID, 'SmartCloud Agent Composer', $names );
	}

	private function create_server( object $adapter, string $id, string $label, array $names ): void {
		$adapter->create_server(
			$id,
			'mcp',
			$id,
			$label,
			'Governed discovery and draft-only Gutenberg execution through active Composer configuration.',
			SMARTCLOUD_COMPOSER_VERSION,
			array( \WP\MCP\Transport\HttpTransport::class ),
			\WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler::class,
			\WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler::class,
			$names,
			array(),
			array()
		);
	}
}
