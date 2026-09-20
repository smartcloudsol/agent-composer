<?php

declare(strict_types=1);

use SmartCloud\AgentComposer\Execution\Blueprint_Migration_Service;

require_once dirname( __DIR__ ) . '/src/Execution/Blueprint_Migration_Service.php';

$failures = array();
$assert = static function ( bool $condition, string $message ) use ( &$failures ): void {
	if ( ! $condition ) {
		$failures[] = $message;
	}
};

$service = ( new ReflectionClass( Blueprint_Migration_Service::class ) )->newInstanceWithoutConstructor();
$rebase = new ReflectionMethod( Blueprint_Migration_Service::class, 'rebase_manifest' );
$rebase->setAccessible( true );
$contract = array(
	'nodes' => array(
		array( 'id' => 'hero.title' ),
		array( 'id' => 'hero.headline' ),
		array( 'id' => 'benefits' ),
		array( 'id' => 'architecture' ),
	),
);
$migration = array(
	'operations' => array( array( 'type' => 'map_field', 'from' => 'hero.title', 'to' => 'hero.headline' ) ),
	'override_rules' => array(
		array( 'type' => 'ORDER', 'path' => 'benefits', 'strategy' => 'accept_target' ),
		array( 'type' => 'ORDER', 'path' => 'architecture', 'strategy' => 'preserve_current' ),
	),
);
$manifest = array(
	'schema_version' => 1,
	'overrides' => array(
		array( 'type' => 'CONTENT', 'path' => 'hero.title' ),
		array( 'type' => 'VISIBILITY', 'path' => 'benefits' ),
		array( 'type' => 'ORDER', 'path' => 'benefits' ),
		array( 'type' => 'ORDER', 'path' => 'architecture' ),
	),
);
$result = $rebase->invoke( $service, $manifest, $migration, $contract );
$assert( $result['automatic'], 'Exact CONTENT mapping and explicit ORDER rules must be automatically proposal-eligible.' );
$assert( array( 'hero.headline', 'benefits', 'architecture' ) === array_column( $result['manifest']['overrides'], 'path' ), 'The target manifest must map content, preserve visibility, preserve explicit order, and drop accepted target order.' );
$assert( isset( $result['preserve_current_order']['architecture'] ), 'preserve_current must prevent the matching move operation.' );
$assert( 3 === count( $result['report']['rebased'] ), 'Mapped content and both explicit order strategies must be reported as rebased.' );
$assert( 1 === count( $result['report']['preserved'] ), 'Stable visibility must be reported as preserved.' );

$review_manifest = array(
	'schema_version' => 1,
	'overrides' => array(
		array( 'type' => 'STRUCTURE', 'path' => 'benefits' ),
		array( 'type' => 'DETACHED', 'path' => 'architecture' ),
		array( 'type' => 'CONTENT', 'path' => 'removed.field' ),
	),
);
$result = $rebase->invoke( $service, $review_manifest, array( 'operations' => array(), 'override_rules' => array() ), $contract );
$assert( ! $result['automatic'], 'Unruled STRUCTURE, DETACHED, and missing target paths must block automatic proposal generation.' );
$assert( 1 === count( $result['report']['review_required'] ), 'STRUCTURE must remain review-required without an exact safe rule.' );
$assert( 1 === count( $result['report']['detached'] ), 'DETACHED must remain a distinct non-automatic classification.' );
$assert( 1 === count( $result['report']['conflicting'] ), 'A removed content path must be reported as conflicting.' );

if ( $failures ) {
	fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
	exit( 1 );
}

echo "blueprint-migration-rebase: ok\n";
