<?php

declare(strict_types=1);

namespace {
	define( 'SMARTCLOUD_COMPOSER_VERSION', 'test' );

	function wp_has_ability( string $name ): bool {
		unset( $name );
		return true;
	}

	function wp_get_ability( string $name ): object {
		unset( $name );
		return new \stdClass();
	}

	function sanitize_text_field( string $value ): string {
		return trim( strip_tags( $value ) );
	}

	function did_action( string $hook ): int {
		return 'wp_abilities_api_init' === $hook && 'complete' === ( $GLOBALS['composer_mcp_abilities_action_state'] ?? 'complete' ) ? 1 : 0;
	}

	function doing_action( string $hook ): bool {
		return 'wp_abilities_api_init' === $hook && 'running' === ( $GLOBALS['composer_mcp_abilities_action_state'] ?? 'complete' );
	}

	function add_action( string $hook, callable $callback, int $priority = 10 ): void {
		$GLOBALS['composer_mcp_deferred_actions'][] = array( $hook, $callback, $priority );
	}

	function apply_filters( string $hook, mixed $value ): mixed {
		if ( 'smartcloud_composer_execution_providers' === $hook ) {
			return $GLOBALS['composer_mcp_provider_manifests'] ?? $value;
		}
		return $value;
	}
}

namespace WP\MCP\Transport {
	final class HttpTransport {}
}

namespace WP\MCP\Infrastructure\ErrorHandling {
	final class ErrorLogMcpErrorHandler {}
}

namespace WP\MCP\Infrastructure\Observability {
	final class NullMcpObservabilityHandler {}
}

namespace {
	use SmartCloud\AgentComposer\Execution\Ability_Provider_Registry;
	use SmartCloud\AgentComposer\Execution\Config_Repository;
	use SmartCloud\AgentComposer\Execution\Localization_Provider_Registry;
	use SmartCloud\AgentComposer\Integration\Mcp\ComposerMcpServer;

	require_once dirname( __DIR__ ) . '/src/Execution/Config_Repository.php';
	require_once dirname( __DIR__ ) . '/src/Execution/Ability_Provider_Registry.php';
	require_once dirname( __DIR__ ) . '/src/Execution/Localization_Provider_Registry.php';
	require_once dirname( __DIR__ ) . '/src/Execution/Abilities.php';
	require_once dirname( __DIR__ ) . '/src/Integration/Abilities/ExecutionAbilityAliases.php';
	require_once dirname( __DIR__ ) . '/src/Integration/Mcp/ComposerMcpServer.php';

	$adapter = new class() {
		public array $arguments = array();

		public function create_server( mixed ...$arguments ): void {
			$this->arguments = $arguments;
		}
	};

	$providers = new Ability_Provider_Registry();
	$provider_abilities = array(
		'late-provider/get-runtime-capabilities',
		'late-provider/list-components',
		'late-provider/get-component-schema',
		'late-provider/materialize-component',
		'late-provider/validate-block-tree',
	);
	$GLOBALS['composer_mcp_provider_manifests'] = array(
		'late-provider' => array(
			'id' => 'late-provider',
			'label' => 'Late provider',
			'contract_version' => '1.0.0',
			'plugin_version' => '1.0.0',
			'ability_names' => $provider_abilities,
			'mcp_ability_names' => $provider_abilities,
			'block_namespaces' => array( 'late-provider/' ),
		),
	);
	if ( ! isset( $providers->all()['late-provider'] ) ) {
		throw new RuntimeException( 'Late-provider test fixture is invalid.' );
	}
	$GLOBALS['composer_mcp_provider_manifests'] = array();
	$providers->reset();
	$providers->all(); // Deliberately cache the pre-provider snapshot.
	$GLOBALS['composer_mcp_provider_manifests']['late-provider'] = array(
		'id' => 'late-provider',
		'label' => 'Late provider',
		'contract_version' => '1.0.0',
		'plugin_version' => '1.0.0',
		'ability_names' => $provider_abilities,
		'mcp_ability_names' => $provider_abilities,
		'block_namespaces' => array( 'late-provider/' ),
	);

	$server = new ComposerMcpServer(
		$providers,
		new Localization_Provider_Registry( new Config_Repository() )
	);
	$server->register( $adapter );
	$transports = $adapter->arguments[6] ?? array();
	if ( array( 'SmartCloud\\AgentComposer\\Integration\\Mcp\\ComposerHttpTransport' ) !== $transports ) {
		throw new RuntimeException( 'Composer MCP must use its stateless external-principal HTTP transport.' );
	}

	$names = $adapter->arguments[9] ?? array();
	foreach ( array(
		'smartcloud-agent-composer/get-contract',
		'smartcloud-agent-composer/get-document',
		'smartcloud-agent-composer/set-field',
		'smartcloud-agent-composer/replace-media',
		'smartcloud-agent-composer/insert-slot-block',
		'smartcloud-agent-composer/update-slot-block',
		'smartcloud-agent-composer/move-slot-block',
		'smartcloud-agent-composer/remove-slot-block',
		'smartcloud-agent-composer/validate-proposal',
		'smartcloud-agent-composer/preview-blueprint-migration',
		'smartcloud-agent-composer/create-blueprint-migration-proposal',
		'smartcloud-agent-composer/plan-blueprint-migration',
		'smartcloud-agent-composer/create-blueprint-migration-proposals',
		'smartcloud-agent-composer/attach-content-to-translation-group',
		'smartcloud-agent-composer/merge-content-translation-groups',
		'smartcloud-agent-composer/get-rendered-preview',
		'smartcloud-agent-composer/get-rendered-preview-asset',
		'smartcloud-agent-composer/inspect-publishable-draft',
		'smartcloud-agent-composer/request-publish',
	) as $name ) {
		if ( ! in_array( $name, $names, true ) ) {
			throw new RuntimeException( 'Composer MCP is missing governed wrapper: ' . $name );
		}
	}
	if ( ! in_array( 'late-provider/materialize-component', $names, true ) ) {
		throw new RuntimeException( 'Composer MCP did not refresh providers registered after its first discovery pass.' );
	}
	$server_version = (string) ( $adapter->arguments[5] ?? '' );
	if ( ! str_contains( $server_version, '+surface.publisher-handoff.5' ) ) {
		throw new RuntimeException( 'Composer MCP server version is missing the Publisher handoff surface cachebuster.' );
	}

	$GLOBALS['composer_mcp_abilities_action_state'] = 'before';
	$GLOBALS['composer_mcp_deferred_actions'] = array();
	$deferred_adapter = new class() {
		public array $arguments = array();

		public function create_server( mixed ...$arguments ): void {
			$this->arguments = $arguments;
		}
	};
	$deferred_server = new ComposerMcpServer(
		new Ability_Provider_Registry(),
		new Localization_Provider_Registry( new Config_Repository() )
	);
	$deferred_server->register( $deferred_adapter );
	if ( ! empty( $deferred_adapter->arguments ) || 1 !== count( $GLOBALS['composer_mcp_deferred_actions'] ) ) {
		throw new RuntimeException( 'Composer MCP did not defer server creation until the Ability registration pass.' );
	}
	$GLOBALS['composer_mcp_abilities_action_state'] = 'running';
	( $GLOBALS['composer_mcp_deferred_actions'][0][1] )();
	if ( empty( $deferred_adapter->arguments ) || PHP_INT_MAX !== $GLOBALS['composer_mcp_deferred_actions'][0][2] ) {
		throw new RuntimeException( 'Composer MCP did not create the deferred server at the end of the Ability registration pass.' );
	}
	$GLOBALS['composer_mcp_abilities_action_state'] = 'complete';

	$resources = $adapter->arguments[10] ?? array();
	foreach ( array(
		'smartcloud-agent-composer/rendered-preview-app',
		'smartcloud-agent-composer/rendered-preview-app-v4',
		'smartcloud-agent-composer/rendered-preview-app-v3',
		'smartcloud-agent-composer/rendered-preview-app-v2',
		'smartcloud-agent-composer/rendered-preview-app-v1',
		'smartcloud-agent-composer/publish-approval-app',
		'smartcloud-agent-composer/publish-approval-app-v4',
		'smartcloud-agent-composer/publish-approval-app-v3',
		'smartcloud-agent-composer/publish-approval-app-v2',
		'smartcloud-agent-composer/publish-approval-app-v1',
	) as $resource_name ) {
		if ( ! in_array( $resource_name, $resources, true ) ) {
			throw new RuntimeException( 'Composer MCP is missing rendered preview UI resource: ' . $resource_name );
		}
	}

	foreach ( array(
		'smartcloud-agent-composer-polylang/attach-content-to-translation-group',
		'smartcloud-agent-composer-polylang/merge-translation-groups',
		'smartcloud-agent-composer-wpml/attach-content-to-translation-group',
		'smartcloud-agent-composer-wpml/merge-translation-groups',
	) as $name ) {
		if ( in_array( $name, $names, true ) ) {
			throw new RuntimeException( 'Composer MCP exposed a direct provider mutation: ' . $name );
		}
	}

	echo "composer-mcp-surface: ok\n";
}
