<?php

namespace SmartCloud\AgentComposer\Infrastructure\Persistence {
	final class ActiveConfigurationSource {
		public function blueprint( string $page_type ): ?array { return null; }
		public function active_config_set(): string { return 'fixture-active-config'; }
		public function page_types(): array { return array( 'examination', 'physician-profile', 'specialty' ); }
	}
}

namespace {
	function sanitize_key( string $value ): string { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $value ) ); }
	require_once dirname( __DIR__ ) . '/src/Execution/Execution_Exception.php';
	require_once dirname( __DIR__ ) . '/src/Execution/Config_Repository.php';

	use SmartCloud\AgentComposer\Execution\Config_Repository;
	use SmartCloud\AgentComposer\Execution\Execution_Exception;
	use SmartCloud\AgentComposer\Infrastructure\Persistence\ActiveConfigurationSource;

	foreach ( array( new Config_Repository(), new Config_Repository( new ActiveConfigurationSource() ) ) as $index => $repository ) {
		try {
			$repository->get_blueprint( 'service' );
			throw new \RuntimeException( 'Missing blueprint must not silently fall back.' );
		} catch ( Execution_Exception $error ) {
			$expected = array(
				'requested_page_type' => 'service',
				'active_config_set' => $index ? 'fixture-active-config' : '',
				'available_page_types' => $index ? array( 'examination', 'physician-profile', 'specialty' ) : array(),
			);
			if ( 'blueprint_not_found' !== $error->get_execution_code() || $expected !== $error->get_execution_data() ) {
				throw new \RuntimeException( 'Missing blueprint diagnostics must identify the requested key and active configuration without dumping content.' );
			}
		}
	}
	echo "Blueprint missing diagnostics: PASS\n";
}
