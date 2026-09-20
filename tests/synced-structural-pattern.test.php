<?php

declare(strict_types=1);

use SmartCloud\AgentComposer\Execution\Synced_Structural_Pattern_Service;

function wp_kses_post( string $value ): string {
	return $value;
}

require_once dirname( __DIR__ ) . '/src/Execution/Synced_Structural_Pattern_Service.php';

$failures = array();
$assert = static function ( bool $condition, string $message ) use ( &$failures ): void {
	if ( ! $condition ) {
		$failures[] = $message;
	}
};

$service = ( new ReflectionClass( Synced_Structural_Pattern_Service::class ) )->newInstanceWithoutConstructor();
$instance = array(
	'blockName' => 'core/block',
	'attrs' => array(
		'ref' => 42,
		'content' => array( 'hero.title' => array( 'content' => 'Old title' ) ),
	),
	'innerBlocks' => array(),
	'innerHTML' => '',
	'innerContent' => array(),
);
$updated = $service->with_override( $instance, 'hero.title', 'content', 'New title' );
$assert( 'New title' === ( $updated['attrs']['content']['hero.title']['content'] ?? '' ), 'Pattern Override values must stay in the native core/block content attribute.' );
$assert( 42 === ( $updated['attrs']['ref'] ?? 0 ), 'Updating a Pattern Override must preserve the synced wp_block reference.' );

$pattern_blocks = array(
	array(
		'blockName' => 'core/group',
		'attrs' => array( 'metadata' => array( 'name' => 'hero' ) ),
		'innerBlocks' => array(
			array(
				'blockName' => 'core/heading',
				'attrs' => array(
					'metadata' => array(
						'name' => 'hero.title',
						'bindings' => array( '__default' => array( 'source' => 'core/pattern-overrides' ) ),
					),
				),
				'innerBlocks' => array(),
				'innerHTML' => '<h1 class="wp-block-heading">Old title</h1>',
				'innerContent' => array( '<h1 class="wp-block-heading">Old title</h1>' ),
			),
		),
		'innerHTML' => '<div class="wp-block-group"></div>',
		'innerContent' => array( '<div class="wp-block-group">', null, '</div>' ),
	),
);
$apply = new ReflectionMethod( Synced_Structural_Pattern_Service::class, 'apply_overrides' );
$apply->setAccessible( true );
$expanded = $apply->invoke( $service, $pattern_blocks, array( 'hero.title' => array( 'content' => 'Instance <em>title</em>' ) ) );
$heading = $expanded[0]['innerBlocks'][0] ?? array();
$assert( 'Instance <em>title</em>' === ( $heading['attrs']['content'] ?? '' ), 'Expanded validation AST must carry the instance override attribute.' );
$assert( str_contains( (string) ( $heading['innerHTML'] ?? '' ), 'Instance <em>title</em>' ), 'Expanded rich text must replace only the bound block content shell.' );
$assert( 'hero.title' === ( $heading['attrs']['metadata']['name'] ?? '' ), 'Pattern expansion must preserve the stable semantic metadata name.' );

$slot_child = array(
	'blockName'    => 'core/paragraph',
	'attrs'        => array( 'metadata' => array( 'wpsuiteAgentComposer' => array( 'userBlockId' => 'user-12345678', 'slotId' => 'body.additional' ) ) ),
	'innerBlocks'  => array(),
	'innerHTML'    => '<p>Instance detail</p>',
	'innerContent' => array( '<p>Instance detail</p>' ),
);
$slot_pattern = array(
	array(
		'blockName'    => 'smartcloud-agent-composer/extension-slot',
		'attrs'        => array( 'metadata' => array( 'name' => 'body.additional' ), 'slotId' => 'body.additional' ),
		'innerBlocks'  => array(),
		'innerHTML'    => '<div></div>',
		'innerContent' => array( '<div></div>' ),
	),
);
$slot_instance = $service->with_slot_children( $instance, 'body.additional', array( $slot_child ) );
$assert( $slot_child === ( $slot_instance['attrs']['metadata']['wpsuiteAgentComposer']['instanceSlots']['body.additional'][0] ?? null ), 'Instance slot content must stay on the core/block reference instead of modifying the shared wp_block.' );
$apply_slots = new ReflectionMethod( Synced_Structural_Pattern_Service::class, 'apply_instance_slots' );
$apply_slots->setAccessible( true );
$expanded_slots = $apply_slots->invoke( $service, $slot_pattern, $slot_instance );
$assert( $slot_child === ( $expanded_slots[0]['innerBlocks'][0] ?? null ), 'Pattern expansion must inject instance-owned slot content into the stable extension slot.' );
$cleared_slot_instance = $service->with_slot_children( $slot_instance, 'body.additional', array() );
$assert( ! isset( $cleared_slot_instance['attrs']['metadata']['wpsuiteAgentComposer']['instanceSlots'] ), 'Removing the last instance slot block must remove the empty slot payload.' );

$collect = new ReflectionMethod( Synced_Structural_Pattern_Service::class, 'collect_bound_fields' );
$collect->setAccessible( true );
$found = array();
$collect->invokeArgs( $service, array( $pattern_blocks, &$found ) );
$assert( in_array( '__default', (array) ( $found['hero.title']['attributes'] ?? array() ), true ), 'Composer must recognize WordPress native __default Pattern Override bindings.' );

$source = (string) file_get_contents( dirname( __DIR__ ) . '/src/Execution/Synced_Structural_Pattern_Service.php' );
$assembler = (string) file_get_contents( dirname( __DIR__ ) . '/src/Execution/Pattern_Assembler.php' );
$validator = (string) file_get_contents( dirname( __DIR__ ) . '/src/Execution/Page_Validator.php' );
$assert( str_contains( $source, "'core/pattern-overrides'" ), 'Synced structural patterns must validate native WordPress Pattern Override bindings.' );
$assert( str_contains( $source, "in_array( '__default', \$bound_attributes, true )" ), 'A native __default Pattern Override binding must satisfy declared attribute contracts.' );
$assert( str_contains( $source, "'blockName'    => 'core/block'" ), 'Synced structural pattern instances must use native core/block references.' );
$assert( str_contains( $source, "'patternName'    => \$pattern" ), 'Composer must retain the stable synced-pattern identity in its namespaced metadata.' );
$assert( ! str_contains( $source, "\t\t\t\t'patternName' => \$pattern" ), 'A native core/block reference must not be marked as an unsynced WordPress pattern through top-level metadata.patternName.' );
$assert( str_contains( $source, "'lock'     => array( 'move' => true, 'remove' => true )" ), 'The synced core/block wrapper must protect structural movement and removal while native Pattern Override fields remain editable.' );
$assert( str_contains( $source, "INSTANCE_SLOTS_KEY = 'instanceSlots'" ), 'Synced references must retain instance-owned extension-slot content outside the shared wp_block record.' );
$assert( str_contains( $assembler, 'materialize_instance' ), 'Pattern assembly must emit synced structural pattern instances.' );
$assert( str_contains( $validator, 'expand_blocks' ), 'Page validation must inspect the current synced pattern structure with instance overrides applied.' );

if ( $failures ) {
	fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
	exit( 1 );
}

echo "synced-structural-pattern: ok\n";
