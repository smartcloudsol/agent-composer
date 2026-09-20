<?php

declare(strict_types=1);

use SmartCloud\AgentComposer\Execution\Structure_Editor_Projector;

require_once dirname( __DIR__ ) . '/src/Execution/Structure_Editor_Projector.php';

$failures = array();
$assert   = static function ( bool $condition, string $message ) use ( &$failures ): void {
	if ( ! $condition ) {
		$failures[] = $message;
	}
};

$node = static function ( string $id, string $block, string $ownership, string $mode, ?string $parent = null, array $extra = array() ): array {
	return array_merge(
		array(
			'id'             => $id,
			'block'          => $block,
			'ownership'      => $ownership,
			'mode'           => $mode,
			'parent'         => $parent,
			'allowed_blocks' => array(),
			'min_blocks'     => 0,
			'max_blocks'     => null,
		),
		$extra
	);
};
$block = static function ( string $name, ?string $id, array $children = array(), array $attrs = array() ): array {
	if ( null !== $id ) {
		$attrs['metadata']['name'] = $id;
	}
	return array(
		'blockName'    => $name,
		'attrs'        => $attrs,
		'innerBlocks'  => $children,
		'innerHTML'    => '',
		'innerContent' => empty( $children ) ? array( '' ) : array_merge( array( '<div>' ), array_fill( 0, count( $children ), null ), array( '</div>' ) ),
	);
};

$contract = array(
	'id'      => 'solution-editor',
	'version' => 3,
	'nodes'   => array(
		$node( 'hero', 'core/group', 'BLUEPRINT', 'structure' ),
		$node( 'hero.title', 'core/heading', 'INSTANCE_CONTENT', 'content', 'hero' ),
		$node(
			'additional-content',
			'smartcloud-agent-composer/extension-slot',
			'BLUEPRINT',
			'slot',
			null,
			array( 'allowed_blocks' => array( 'core/paragraph' ), 'max_blocks' => 2 )
		),
		$node( 'mixed', 'core/group', 'BLUEPRINT', 'structure' ),
		$node(
			'mixed.slot',
			'smartcloud-agent-composer/extension-slot',
			'BLUEPRINT',
			'slot',
			'mixed',
			array( 'allowed_blocks' => array( 'core/paragraph' ) )
		),
	),
);

$title = $block( 'core/heading', 'hero.title' );
$hero  = $block( 'core/group', 'hero', array( $title ) );
$user  = $block( 'core/paragraph', null, array(), array( 'lock' => array( 'move' => true, 'remove' => true ) ) );
$slot  = $block( 'smartcloud-agent-composer/extension-slot', 'additional-content', array( $user ) );
$mixed_slot = $block( 'smartcloud-agent-composer/extension-slot', 'mixed.slot' );
$mixed = $block(
	'core/group',
	'mixed',
	array( $mixed_slot ),
	array( 'metadata' => array( 'wpsuiteAgentComposer' => array( 'patternName' => 'theme/mixed-pattern' ) ) )
);

$projector = new Structure_Editor_Projector();
$projected = $projector->project_blocks( $contract, array( $hero, $slot, $mixed ) );

$assert( true === ( $projected[0]['attrs']['lock']['move'] ?? null ), 'A Blueprint-owned block must receive a native move lock.' );
$assert( true === ( $projected[0]['attrs']['lock']['remove'] ?? null ), 'A Blueprint-owned block must receive a native remove lock.' );
$assert( 'contentOnly' === ( $projected[0]['attrs']['templateLock'] ?? null ), 'A protected subtree containing only governed content must use contentOnly editing.' );
$assert( true === ( $projected[0]['innerBlocks'][0]['attrs']['lock']['move'] ?? null ), 'Instance-content fields must retain their semantic position.' );
$assert( ! isset( $projected[0]['innerBlocks'][0]['attrs']['templateLock'] ), 'Leaf content fields must not receive a container template lock.' );

$slot_attrs = $projected[1]['attrs'];
$assert( true === ( $slot_attrs['lock']['remove'] ?? null ), 'The extension-slot definition itself must remain Blueprint-owned.' );
$assert( false === ( $slot_attrs['templateLock'] ?? null ), 'An extension slot must keep child insertion enabled.' );
$assert( 'additional-content' === ( $slot_attrs['slotId'] ?? '' ), 'The slot block must receive its stable semantic slot ID.' );
$assert( array( 'core/paragraph' ) === ( $slot_attrs['allowedBlocks'] ?? null ), 'The slot block must receive its contract allowlist.' );
$assert( 2 === ( $slot_attrs['maxBlocks'] ?? null ), 'The slot block must receive its maximum cardinality.' );
$assert( ! isset( $projected[1]['innerBlocks'][0]['attrs']['lock'] ), 'User-owned blocks inside a slot must remain movable and removable.' );
$user_metadata = $projected[1]['innerBlocks'][0]['attrs']['metadata']['wpsuiteAgentComposer'] ?? array();
$assert( 'USER' === ( $user_metadata['ownership'] ?? '' ), 'A slot descendant must receive explicit USER ownership.' );
$assert( 'additional-content' === ( $user_metadata['slotId'] ?? '' ), 'A user-owned block must record its current extension slot.' );
$assert( 1 === preg_match( '/^user-[a-f0-9]{32}$/', (string) ( $user_metadata['userBlockId'] ?? '' ) ), 'A materialized user-owned block must receive a stable opaque identity.' );

$metadata = $projected[0]['attrs']['metadata']['wpsuiteAgentComposer'] ?? array();
$assert( 'solution-editor' === ( $metadata['contractId'] ?? '' ), 'Projected metadata must identify the exact Structure Contract.' );
$assert( 3 === ( $metadata['contractVersion'] ?? 0 ), 'Projected metadata must identify the exact Structure Contract version.' );
$assert( 'hero' === ( $metadata['nodeId'] ?? '' ), 'Projected metadata must retain a stable semantic node ID.' );
$assert( 'BLUEPRINT' === ( $metadata['ownership'] ?? '' ), 'Projected metadata must expose node ownership.' );

$assert( ! isset( $projected[2]['attrs']['templateLock'] ), 'A structure ancestor must not use contentOnly when it contains an extension slot.' );
$assert( 'theme/mixed-pattern' === ( $projected[2]['attrs']['metadata']['wpsuiteAgentComposer']['patternName'] ?? '' ), 'Structure projection must retain the Composer-namespaced source pattern without activating WordPress pattern editing.' );
$assert( ! isset( $projected[2]['attrs']['metadata']['patternName'] ), 'A slot ancestor must not expose WordPress native pattern identity metadata.' );
$assert( $projected === $projector->project_blocks( $contract, $projected ), 'Editor protection projection must be deterministic and idempotent.' );

$assembler_source = (string) file_get_contents( dirname( __DIR__ ) . '/src/Execution/Pattern_Assembler.php' );
$runtime_source = (string) file_get_contents( dirname( __DIR__ ) . '/src/Application/Execution/ExecutionRuntime.php' );
$assert( str_contains( $assembler_source, '$this->editor->project_content' ), 'Every newly assembled enforced document must receive editor protection.' );
$assert( str_contains( $runtime_source, 'new Structure_Editor_Projector()' ), 'The execution runtime must wire the Structure Contract editor projector.' );

if ( $failures ) {
	fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
	exit( 1 );
}

echo "structure-editor-projector: ok\n";
