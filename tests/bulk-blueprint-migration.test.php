<?php

declare(strict_types=1);

use SmartCloud\AgentComposer\Execution\Bulk_Blueprint_Migration_Service;

require_once dirname( __DIR__ ) . '/src/Execution/Managed_Document_State.php';
require_once dirname( __DIR__ ) . '/src/Execution/Bulk_Blueprint_Migration_Service.php';

$failures = array();
$assert = static function ( bool $condition, string $message ) use ( &$failures ): void {
	if ( ! $condition ) {
		$failures[] = $message;
	}
};

$service  = ( new ReflectionClass( Bulk_Blueprint_Migration_Service::class ) )->newInstanceWithoutConstructor();
$classify = new ReflectionMethod( Bulk_Blueprint_Migration_Service::class, 'classify_preview' );
$classify->setAccessible( true );

$assert( 'automatic' === $classify->invoke( $service, array( 'proposal_eligible' => true ) ), 'A proposal-eligible item must be automatic.' );
$assert(
	'review_required' === $classify->invoke( $service, array( 'proposal_eligible' => false, 'override_rebase' => array( 'review_required' => array( array( 'path' => 'hero' ) ) ) ) ),
	'An unresolved structural override must remain review-required.'
);
$assert(
	'review_required' === $classify->invoke( $service, array( 'proposal_eligible' => false, 'override_rebase' => array( 'detached' => array( array( 'path' => 'hero' ) ) ) ) ),
	'A detached item must remain review-required rather than automatic.'
);
$assert(
	'incompatible' === $classify->invoke( $service, array( 'proposal_eligible' => false, 'override_rebase' => array( 'conflicting' => array( array( 'path' => 'hero.title' ) ) ) ) ),
	'A conflicting item without a reviewable structural override must be incompatible.'
);

$meta_query = new ReflectionMethod( Bulk_Blueprint_Migration_Service::class, 'source_meta_query' );
$meta_query->setAccessible( true );
$query = $meta_query->invoke(
	$service,
	'solution',
	array( 'blueprint_version' => 3, 'structure_contract' => array( 'id' => 'solution-editor', 'version' => 3 ) )
);
$assert( 'AND' === ( $query['relation'] ?? '' ), 'Bulk discovery must require every exact source-baseline meta value.' );
$assert( 5 === count( $query ), 'Bulk discovery must bind Blueprint ID/version and Structure Contract ID/version.' );

if ( $failures ) {
	fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
	exit( 1 );
}

echo "bulk-blueprint-migration: ok\n";
