<?php

namespace SmartCloud\AgentComposer\Integration\Mcp;

use SmartCloud\AgentComposer\Execution\Abilities;
use SmartCloud\AgentComposer\Execution\Ability_Provider_Registry;
use SmartCloud\AgentComposer\Execution\Config_Repository;
use SmartCloud\AgentComposer\Execution\Localization_Provider_Registry;
use SmartCloud\AgentComposer\Integration\Abilities\ExecutionAbilityAliases;
use SmartCloud\AgentComposer\Security\McpAccessGuard;

final class ComposerMcpServer {
	public const SERVER_ID = 'smartcloud-agent-composer';
	public const SURFACE_REVISION = 'publisher-handoff.5';
	public const HTTP_ENDPOINT = '/wp-json/mcp/smartcloud-agent-composer';
	public const PREVIEW_RESOURCE_URI = 'ui://smartcloud-agent-composer/rendered-preview/v5.html';
	public const PREVIEW_RESOURCE_URI_V4 = 'ui://smartcloud-agent-composer/rendered-preview/v4.html';
	public const PREVIEW_RESOURCE_URI_V3 = 'ui://smartcloud-agent-composer/rendered-preview/v3.html';
	public const PREVIEW_RESOURCE_URI_V2 = 'ui://smartcloud-agent-composer/rendered-preview/v2.html';
	public const PREVIEW_RESOURCE_URI_V1 = 'ui://smartcloud-agent-composer/rendered-preview/v1.html';
	public const PUBLISH_APPROVAL_RESOURCE_URI = 'ui://smartcloud-agent-composer/publish-approval/v5.html';
	public const PUBLISH_APPROVAL_RESOURCE_URI_V4 = 'ui://smartcloud-agent-composer/publish-approval/v4.html';
	public const PUBLISH_APPROVAL_RESOURCE_URI_V3 = 'ui://smartcloud-agent-composer/publish-approval/v3.html';
	public const PUBLISH_APPROVAL_RESOURCE_URI_V2 = 'ui://smartcloud-agent-composer/publish-approval/v2.html';
	public const PUBLISH_APPROVAL_RESOURCE_URI_V1 = 'ui://smartcloud-agent-composer/publish-approval/v1.html';

	private array $registered_adapters = array();
	private array $deferred_adapters = array();

	public function __construct(
		private readonly Ability_Provider_Registry $providers,
		private readonly Localization_Provider_Registry $localization,
		private readonly ?Config_Repository $config = null,
		private readonly ?McpAccessGuard $access_guard = null
	) {}

	public function register( object $adapter ): void {
		$id = spl_object_id( $adapter );
		if ( isset( $this->registered_adapters[ $id ] ) || isset( $this->deferred_adapters[ $id ] ) || ! method_exists( $adapter, 'create_server' ) ) {
			return;
		}
		if (
			function_exists( 'did_action' )
			&& function_exists( 'doing_action' )
			&& function_exists( 'add_action' )
			&& ( 0 === did_action( 'wp_abilities_api_init' ) || doing_action( 'wp_abilities_api_init' ) )
		) {
			$this->deferred_adapters[ $id ] = true;
			add_action(
				'wp_abilities_api_init',
				function () use ( $adapter, $id ): void {
					unset( $this->deferred_adapters[ $id ] );
					$this->register_after_abilities( $adapter );
				},
				PHP_INT_MAX
			);
			return;
		}
		$this->register_after_abilities( $adapter );
	}

	private function register_after_abilities( object $adapter ): void {
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
		// Provider plugins may register their Abilities later on the same
		// wp_abilities_api_init pass than Composer first inspects them. The MCP
		// adapter starts only after that pass, so discard any partial snapshot
		// before fixing the server's tool surface for this request.
		$this->providers->reset();
		$this->localization->reset();
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
		$rendered_preview_required = null === $this->config || 'required' === $this->config->get_rendered_preview_policy();
		$publisher_routing = ' For publication handoff, a Publisher MUST use inspect-publishable-draft rather than inspect-content-item when the ordinary Composer-owned draft may belong to another principal. request-publish may then use both returned concurrency tokens, or only post_id to atomically validate and lock the current revision. Neither operation transfers assignment or grants model publication.';
		$description = ( $rendered_preview_required
			? 'Governed discovery, draft creation, and published-content proposal execution through active Composer configuration. After the final successful draft write, call get-rendered-preview with its fresh concurrency tokens and let the inline rendered HTML preview be delivered before reporting completion. For a published-content update proposal, the exact preview response supplies the rendered_preview_token required by submit-content-proposal, so previewing must happen after the last write and before submission.'
			: 'Governed discovery, draft creation, and published-content proposal execution through active Composer configuration. Rendered HTML preview after the final successful draft write remains the recommended default. If the user explicitly asks to skip HTML preview, complete a normal draft without it or validate and submit a published-content update proposal with fresh concurrency tokens and no rendered_preview_token.' ) . $publisher_routing;
		$adapter->create_server(
			$id,
			'mcp',
			$id,
			$label,
			$description,
			SMARTCLOUD_COMPOSER_VERSION . '+surface.' . self::SURFACE_REVISION,
			array( ComposerHttpTransport::class ),
			\WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler::class,
			\WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler::class,
			$names,
			$resources,
			array(),
			null !== $this->access_guard ? array( $this->access_guard, 'transport_permission' ) : null
		);
	}
}
