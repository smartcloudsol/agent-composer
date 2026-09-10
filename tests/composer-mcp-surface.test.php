<?php

declare(strict_types=1);

namespace {
	define( 'SMARTCLOUD_COMPOSER_VERSION', 'test' );

	function wp_has_ability( string $name ): bool {
		unset( $name );
		return true;
	}

	function apply_filters( string $hook, mixed $value ): mixed {
		unset( $hook );
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

	$server = new ComposerMcpServer(
		new Ability_Provider_Registry(),
		new Localization_Provider_Registry( new Config_Repository() )
	);
	$server->register( $adapter );

	$names = $adapter->arguments[9] ?? array();
	foreach ( array(
		'smartcloud-agent-composer/attach-content-to-translation-group',
		'smartcloud-agent-composer/merge-content-translation-groups',
		'smartcloud-agent-composer/get-rendered-preview',
		'smartcloud-agent-composer/get-rendered-preview-asset',
	) as $name ) {
		if ( ! in_array( $name, $names, true ) ) {
			throw new RuntimeException( 'Composer MCP is missing governed wrapper: ' . $name );
		}
	}

	$resources = $adapter->arguments[10] ?? array();
	foreach ( array(
		'smartcloud-agent-composer/rendered-preview-app',
		'smartcloud-agent-composer/rendered-preview-app-v4',
		'smartcloud-agent-composer/rendered-preview-app-v3',
		'smartcloud-agent-composer/rendered-preview-app-v2',
		'smartcloud-agent-composer/rendered-preview-app-v1',
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
