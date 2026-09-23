<?php

declare(strict_types=1);

use SmartCloud\AgentComposer\Domain\Structure\StructureContract;
use SmartCloud\AgentComposer\Domain\Structure\StructureDocumentValidator;

require_once dirname( __DIR__ ) . '/src/Domain/Structure/StructureContract.php';
require_once dirname( __DIR__ ) . '/src/Domain/Structure/StructureDocumentValidator.php';

$failures = array();
$assert   = static function ( bool $condition, string $message ) use ( &$failures ): void {
	if ( ! $condition ) {
		$failures[] = $message;
	}
};

$definition = array(
	'id'      => 'solution-editor',
	'version' => 3,
	'nodes'   => array(
		array(
			'id'                   => 'hero',
			'block'                => 'core/group',
			'ownership'            => 'BLUEPRINT',
			'mode'                 => 'structure',
			'parent'               => null,
			'position'             => 10,
			'protected_attributes' => array( 'layout' ),
		),
		array(
			'id'                  => 'hero.title',
			'block'               => 'core/heading',
			'ownership'           => 'INSTANCE_CONTENT',
			'mode'                => 'content',
			'parent'              => 'hero',
			'position'            => 10,
			'editable_attributes' => array( 'content' ),
		),
		array(
			'id'             => 'additional-content',
			'block'          => 'smartcloud-agent-composer/extension-slot',
			'ownership'      => 'BLUEPRINT',
			'mode'           => 'slot',
			'parent'         => null,
			'position'       => 20,
			'allowed_blocks' => array( 'core/group', 'core/heading', 'core/paragraph' ),
			'allowed_patterns' => array( 'wpsuite/repeatable-card' ),
			'pattern_occurrences' => array( 'wpsuite/repeatable-card' => array( 'min' => 0, 'max' => 2 ) ),
			'min_blocks'     => 0,
			'max_blocks'     => 2,
		),
		array(
			'id'        => 'repeatable-card',
			'block'     => 'core/group',
			'ownership' => 'BLUEPRINT',
			'mode'      => 'structure',
			'parent'    => 'additional-content',
			'position'  => null,
			'required'  => false,
		),
		array(
			'id'             => 'secondary-content',
			'block'          => 'smartcloud-agent-composer/extension-slot',
			'ownership'      => 'BLUEPRINT',
			'mode'           => 'slot',
			'parent'         => null,
			'position'       => 30,
			'allowed_blocks' => array( 'core/paragraph' ),
			'min_blocks'     => 0,
			'max_blocks'     => null,
		),
	),
);
$normalized = StructureContract::normalize_definition( $definition );
$assert( true === $normalized['valid'], 'The document-validator fixture contract must normalize.' );
$contract  = $normalized['value'];
$validator = new StructureDocumentValidator();

$block = static function ( string $name, ?string $id = null, array $attrs = array(), array $children = array(), string $html = '' ): array {
	if ( null !== $id ) {
		$attrs['metadata']['name'] = $id;
	}
	return array(
		'blockName'    => $name,
		'attrs'        => $attrs,
		'innerBlocks'  => $children,
		'innerHTML'    => $html,
		'innerContent' => empty( $children ) ? array( $html ) : array( '<div>', null, '</div>' ),
	);
};

$title = $block( 'core/heading', 'hero.title', array( 'level' => 1, 'content' => 'Original' ), array(), '<h1>Original</h1>' );
$hero  = $block( 'core/group', 'hero', array( 'layout' => array( 'type' => 'constrained' ) ), array( $title ), '<div></div>' );
$slot  = $block( 'smartcloud-agent-composer/extension-slot', 'additional-content', array(), array(), '<div></div>' );
$slot2 = $block( 'smartcloud-agent-composer/extension-slot', 'secondary-content', array(), array(), '<div></div>' );
$baseline = array( $hero, $slot, $slot2 );

$valid = $validator->validate( $contract, $baseline );
$assert( true === $valid['valid'], 'The canonical semantic block tree must satisfy its Structure Contract.' );
$assert( array( 'id' => 'solution-editor', 'version' => 3 ) === $valid['contract'], 'Validation must identify the exact independently versioned contract.' );

$stamp_pattern_instance = static function ( array $block, string $instance_id ) use ( &$stamp_pattern_instance ): array {
	$block['attrs']['metadata']['wpsuiteAgentComposer']['patternInstanceId'] = $instance_id;
	foreach ( (array) ( $block['innerBlocks'] ?? array() ) as $index => $child ) {
		$block['innerBlocks'][ $index ] = $stamp_pattern_instance( $child, $instance_id );
	}
	return $block;
};
$repeated_patterns = array(
	$stamp_pattern_instance( $hero, 'pattern-11111111' ),
	$stamp_pattern_instance( $hero, 'pattern-22222222' ),
	$slot,
	$slot2,
);
$result = $validator->validate( $contract, $repeated_patterns );
$assert( true === $result['valid'], 'The same semantic pattern structure may repeat when each occurrence has a distinct stable pattern instance ID.' );

$duplicate_pattern_identity = $repeated_patterns;
$duplicate_pattern_identity[1] = $stamp_pattern_instance( $hero, 'pattern-11111111' );
$result = $validator->validate( $contract, $duplicate_pattern_identity );
$assert( false === $result['valid'], 'Repeated pattern structure must fail closed when two instances reuse the same pattern instance ID.' );

$repeated_pattern_edit = $repeated_patterns;
$repeated_pattern_edit[1]['attrs']['layout']['type'] = 'flex';
$result = $validator->validate( $contract, $repeated_pattern_edit, $repeated_patterns );
$assert( false === $result['valid'], 'Protected structure must still be compared independently inside every repeated pattern instance.' );
$assert( in_array( 'pattern-22222222.hero.layout', array_column( $result['errors'], 'path' ), true ), 'Repeated-pattern violations must identify the exact pattern instance and semantic field.' );

$content_edit = $baseline;
$content_edit[0]['innerBlocks'][0]['attrs']['content'] = 'Changed';
$content_edit[0]['innerBlocks'][0]['innerHTML'] = '<h1>Changed</h1>';
$content_edit[0]['innerBlocks'][0]['innerContent'] = array( '<h1>Changed</h1>' );
$result = $validator->validate( $contract, $content_edit, $baseline );
$assert( true === $result['valid'], 'Declared instance-content attributes and rendered content must remain editable.' );
$assert( $valid['structural_fingerprint'] === $result['structural_fingerprint'], 'Instance content edits must not change the protected structural fingerprint.' );

$layout_edit = $baseline;
$layout_edit[0]['attrs']['layout']['type'] = 'flex';
$result = $validator->validate( $contract, $layout_edit, $baseline );
$assert( false === $result['valid'], 'A protected layout attribute change must fail.' );
$assert( $valid['structural_fingerprint'] !== $result['structural_fingerprint'], 'Protected attribute drift must change the structural fingerprint.' );
$assert( 'change_structure_attribute' === ( $result['errors'][0]['operation'] ?? '' ), 'Protected attribute failures must report a machine-readable operation.' );
$assert( 'hero.layout' === ( $result['errors'][0]['path'] ?? '' ), 'Protected attribute failures must use a stable semantic path.' );

$removed = array( $slot, $slot2 );
$result  = $validator->validate( $contract, $removed, $baseline );
$assert( false === $result['valid'], 'A required Blueprint node cannot be removed.' );
$assert( in_array( 'remove_structure', array_column( $result['errors'], 'operation' ), true ), 'Removal must be classified as a structural operation.' );

$reordered = array( $slot, $hero, $slot2 );
$result    = $validator->validate( $contract, $reordered, $baseline );
$assert( false === $result['valid'], 'Canonical Blueprint section order must be enforced.' );
$assert( in_array( 'move_structure', array_column( $result['errors'], 'operation' ), true ), 'Reordering must be classified as a structural move.' );

$slot_insert = $baseline;
$slot_insert[1]['innerBlocks'][] = $block( 'core/paragraph', null, array(), array(), '<p>Additional detail</p>' );
$slot_insert[1]['innerContent'] = array( '<div>', null, '</div>' );
$result = $validator->validate( $contract, $slot_insert, $baseline );
$assert( true === $result['valid'], 'An allow-listed user block may be inserted into an extension slot.' );
$assert( $valid['structural_fingerprint'] === $result['structural_fingerprint'], 'User-owned slot content must not alter the Blueprint structural fingerprint.' );

$pattern_slot_insert = $baseline;
$pattern_slot_insert[1]['innerBlocks'][] = $block(
	'core/group',
	'repeatable-card',
	array( 'metadata' => array( 'wpsuiteAgentComposer' => array( 'patternInstanceId' => 'pattern-33333333' ) ) ),
	array(),
	'<div class="wp-block-group"></div>'
);
$pattern_slot_insert[1]['innerContent'] = array( '<div>', null, '</div>' );
$result = $validator->validate( $contract, $pattern_slot_insert, $baseline );
$assert( true === $result['valid'], 'An allow-listed synced pattern instance may be inserted as one direct slot item.' );
$assert( $valid['structural_fingerprint'] === $result['structural_fingerprint'], 'A user-owned pattern instance inside a slot must not alter the protected structural fingerprint.' );

$nested_insert = $baseline;
$nested_insert[1]['innerBlocks'][] = $block(
	'core/group',
	null,
	array(),
	array( $block( 'core/paragraph', null, array(), array(), '<p>Nested detail</p>' ) ),
	'<div></div>'
);
$nested_insert[1]['innerContent'] = array( '<div>', null, '</div>' );
$result = $validator->validate( $contract, $nested_insert, $baseline );
$assert( true === $result['valid'], 'Nested allow-listed user blocks must count as one direct slot item.' );

$disallowed = $baseline;
$disallowed[1]['innerBlocks'][] = $block( 'core/image', null, array(), array(), '<figure></figure>' );
$disallowed[1]['innerContent'] = array( '<div>', null, '</div>' );
$result = $validator->validate( $contract, $disallowed, $baseline );
$assert( false === $result['valid'], 'A slot must reject a non-allow-listed user block.' );
$assert( in_array( 'slot-block-not-allowed', array_column( array_column( $result['errors'], 'context' ), 'rule' ), true ), 'Slot allow-list failures must identify the violated rule.' );

$too_many = $slot_insert;
$too_many[1]['innerBlocks'][] = $block( 'core/paragraph', null, array(), array(), '<p>Second</p>' );
$too_many[1]['innerBlocks'][] = $block( 'core/paragraph', null, array(), array(), '<p>Third</p>' );
$too_many[1]['innerContent'] = array( '<div>', null, null, null, '</div>' );
$result = $validator->validate( $contract, $too_many, $baseline );
$assert( false === $result['valid'], 'Extension-slot maximum cardinality must be enforced.' );

$paragraph = $block( 'core/paragraph', null, array(), array(), '<p>Move me</p>' );
$move_from = $baseline;
$move_from[1]['innerBlocks'] = array( $paragraph );
$move_from[1]['innerContent'] = array( '<div>', null, '</div>' );
$move_to = $baseline;
$move_to[2]['innerBlocks'] = array( $paragraph );
$move_to[2]['innerContent'] = array( '<div>', null, '</div>' );
$result = $validator->validate( $contract, $move_to, $move_from );
$assert( false === $result['valid'], 'Identical user-owned content cannot move across slots when cross-slot movement is disabled.' );
$assert( in_array( 'cross-slot-move-forbidden', array_column( array_column( $result['errors'], 'context' ), 'rule' ), true ), 'Cross-slot movement must report its explicit contract rule.' );

$identified = $block(
	'core/paragraph',
	null,
	array(
		'metadata' => array(
			'wpsuiteAgentComposer' => array(
				'ownership'   => 'USER',
				'slotId'      => 'additional-content',
				'userBlockId' => 'user-12345678-1234-1234-1234-123456789abc',
			),
		),
	),
	array(),
	'<p>Before move</p>'
);
$identified_from = $baseline;
$identified_from[1]['innerBlocks'] = array( $identified );
$identified_from[1]['innerContent'] = array( '<div>', null, '</div>' );
$identified['innerHTML'] = '<p>Changed while moving</p>';
$identified['innerContent'] = array( '<p>Changed while moving</p>' );
$identified['attrs']['metadata']['wpsuiteAgentComposer']['slotId'] = 'secondary-content';
$identified_to = $baseline;
$identified_to[2]['innerBlocks'] = array( $identified );
$identified_to[2]['innerContent'] = array( '<div>', null, '</div>' );
$result = $validator->validate( $contract, $identified_to, $identified_from );
$assert( false === $result['valid'], 'Stable user-block identity must detect a cross-slot move even when content changes simultaneously.' );
$assert( in_array( 'cross-slot-move-forbidden', array_column( array_column( $result['errors'], 'context' ), 'rule' ), true ), 'Identity-based cross-slot movement must report its contract rule.' );

$duplicate = $baseline;
$duplicate[1]['innerBlocks'] = array( $identified, $identified );
$duplicate[1]['innerBlocks'][0]['attrs']['metadata']['wpsuiteAgentComposer']['slotId'] = 'additional-content';
$duplicate[1]['innerBlocks'][1]['attrs']['metadata']['wpsuiteAgentComposer']['slotId'] = 'additional-content';
$duplicate[1]['innerContent'] = array( '<div>', null, null, '</div>' );
$result = $validator->validate( $contract, $duplicate, $baseline );
$assert( false === $result['valid'], 'A stable user-block identity cannot be duplicated within a managed document.' );
$assert( in_array( 'user-block-id-duplicate', array_column( array_column( $result['errors'], 'context' ), 'rule' ), true ), 'Duplicate identity failures must expose a machine-readable rule.' );

$privileged = $validator->validate( $contract, $layout_edit, $baseline, true );
$assert( true === $privileged['valid'], 'A privileged migration context may change structure when the proposed tree satisfies the target contract.' );

$draft_source = (string) file_get_contents( dirname( __DIR__ ) . '/src/Execution/Draft_Service.php' );
$proposal_source = (string) file_get_contents( dirname( __DIR__ ) . '/src/Execution/Content_Proposal_Service.php' );
$runtime_source = (string) file_get_contents( dirname( __DIR__ ) . '/src/Application/Execution/ExecutionRuntime.php' );
$guard_source = (string) file_get_contents( dirname( __DIR__ ) . '/src/Execution/Structure_Contract_Save_Guard.php' );
$abilities_source = (string) file_get_contents( dirname( __DIR__ ) . '/src/Execution/Abilities.php' );
$assembler_source = (string) file_get_contents( dirname( __DIR__ ) . '/src/Execution/Pattern_Assembler.php' );
$assert( substr_count( $draft_source, "validator->validate( \$page_type, \$assembled['content'], (string) \$post->post_content )" ) >= 1, 'Draft replacement must compare the previous and proposed Gutenberg ASTs.' );
$assert( str_contains( $draft_source, "validator->validate( \$page_type, \$tree['content'], (string) \$post->post_content )" ), 'Raw block mutation must pass the persisted document to the shared validator.' );
$assert( str_contains( $proposal_source, '$source instanceof \\WP_Post ? (string) $source->post_content : null' ), 'Proposal validation must compare against its published source.' );
$assert( str_contains( $runtime_source, "rest_api_init', array( \$this->structure_guard, 'register' )" ), 'Native Gutenberg REST saves must register the Structure Contract guard.' );
$assert( str_contains( $guard_source, "'rest_pre_insert_' . \$post_type" ), 'The save guard must cover every active governed post type.' );
$assert( str_contains( $guard_source, "'violations' =>" ), 'Rejected Gutenberg saves must return machine-readable contract violations.' );
$assert( str_contains( $abilities_source, '$error->get_execution_data()' ), 'MCP/Ability errors must retain machine-readable contract violations.' );
$assert( str_contains( $assembler_source, "['wpsuiteAgentComposer']" ) && str_contains( $assembler_source, "['patternName']" ), 'Pattern assembly must preserve source identity in Composer metadata.' );
$assert( str_contains( $assembler_source, "unset( \$metadata['patternName'] )" ), 'Materialized Structure Contract wrappers must not activate WordPress native pattern editing.' );

if ( $failures ) {
	fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
	exit( 1 );
}

echo "structure-document-validator: ok\n";
