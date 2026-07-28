<?php

declare(strict_types=1);

require_once dirname( __DIR__ ) . '/src/Application/Configuration/SiteDiscoveryService.php';

use SmartCloud\AgentComposer\Application\Configuration\SiteDiscoveryService;

$reflection = new ReflectionClass( SiteDiscoveryService::class );
$service    = $reflection->newInstanceWithoutConstructor();
$method     = $reflection->getMethod( 'contains_forbidden_manifest_key' );

$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};

$assert(
	false === $method->invoke( $service, array( 'compatibility' => array( 'frontend_javascript' => false ) ) ),
	'The schema-defined boolean frontend_javascript capability must be accepted.'
);
$assert(
	false === $method->invoke( $service, array( 'compatibility' => array( 'frontend_javascript' => true ) ) ),
	'The schema-defined frontend_javascript capability remains descriptive when enabled.'
);
$assert(
	true === $method->invoke( $service, array( 'compatibility' => array( 'frontend_javascript' => 'alert(1)' ) ) ),
	'A non-boolean frontend_javascript value must remain forbidden.'
);
$assert(
	true === $method->invoke( $service, array( 'nested' => array( 'api_key' => 'example' ) ) ),
	'Sensitive nested manifest keys must remain forbidden.'
);

echo "Theme manifest security contract passed.\n";
