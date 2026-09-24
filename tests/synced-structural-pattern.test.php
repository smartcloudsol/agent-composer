<?php

declare(strict_types=1);

use SmartCloud\AgentComposer\Execution\Synced_Structural_Pattern_Service;
use SmartCloud\AgentComposer\Execution\Page_Validator;

function wp_kses_post( string $value ): string {
	return $value;
}

if ( ! class_exists( 'WP_Block' ) ) {
	class WP_Block {
		public array $parsed_block = array();
		public int $context_refreshes = 0;
		public array $render_options = array();

		public function refresh_context_dependents(): void {
			++$this->context_refreshes;
		}

		public function render( array $options = array() ): string {
			$this->render_options[] = $options;
			return self::render_blocks( (array) ( $this->parsed_block['innerBlocks'] ?? array() ) );
		}

		private static function render_blocks( array $blocks ): string {
			$html = '';
			foreach ( $blocks as $block ) {
				if ( ! is_array( $block ) ) {
					continue;
				}
				$inner_html = (string) ( $block['innerHTML'] ?? '' );
				$children   = self::render_blocks( (array) ( $block['innerBlocks'] ?? array() ) );
				if ( '' !== $children && str_contains( $inner_html, '</div>' ) ) {
					$inner_html = preg_replace( '/<\/div>\s*$/', $children . '</div>', $inner_html, 1 ) ?? $inner_html;
				}
				$html .= $inner_html;
			}
			return $html;
		}
	}
}

require_once dirname( __DIR__ ) . '/src/Execution/Synced_Structural_Pattern_Service.php';
require_once dirname( __DIR__ ) . '/src/Execution/Page_Validator.php';

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
		'metadata' => array( 'wpsuiteAgentComposer' => array( 'patternInstanceId' => 'pattern-12345678' ) ),
	),
	'innerBlocks' => array(),
	'innerHTML' => '',
	'innerContent' => array(),
);
$updated = $service->with_override( $instance, 'hero.title', 'content', 'New title' );
$assert( 'New title' === ( $updated['attrs']['content']['hero.title']['content'] ?? '' ), 'Pattern Override values must stay in the native core/block content attribute.' );
$assert( 42 === ( $updated['attrs']['ref'] ?? 0 ), 'Updating a Pattern Override must preserve the synced wp_block reference.' );
$assert( 'pattern-12345678' === ( $updated['attrs']['metadata']['wpsuiteAgentComposer']['patternInstanceId'] ?? '' ), 'Updating one Pattern Override must preserve its stable pattern instance ID.' );
$legacy_instance = $instance;
unset( $legacy_instance['attrs']['metadata']['wpsuiteAgentComposer']['patternInstanceId'] );
$upgraded_instance = $service->with_override( $legacy_instance, 'hero.title', 'content', 'Upgraded title', 'pattern-87654321' );
$assert( 'pattern-87654321' === ( $upgraded_instance['attrs']['metadata']['wpsuiteAgentComposer']['patternInstanceId'] ?? '' ), 'The first mutation of a legacy reference must persist its compatibility pattern instance ID.' );

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

$second_slot_child = $slot_child;
$second_slot_child['innerHTML'] = '<p>Second instance detail</p>';
$second_slot_child['innerContent'] = array( '<p>Second instance detail</p>' );
$second_slot_instance = $service->with_slot_children( $instance, 'body.additional', array( $second_slot_child ), 'pattern-87654321' );
$first_frontend_block = new WP_Block();
$first_frontend_block->parsed_block = array(
	'blockName'    => 'core/block',
	'attrs'        => $slot_instance['attrs'],
	'innerBlocks'  => $slot_pattern,
	'innerContent' => array( null ),
);
$second_frontend_block = new WP_Block();
$second_frontend_block->parsed_block = array(
	'blockName'    => 'core/block',
	'attrs'        => $second_slot_instance['attrs'],
	'innerBlocks'  => $slot_pattern,
	'innerContent' => array( null ),
);
$first_frontend_html = $service->render_instance_slots( '<div></div>', $slot_instance, $first_frontend_block );
$second_frontend_html = $service->render_instance_slots( '<div></div>', $second_slot_instance, $second_frontend_block );
$assert( str_contains( $first_frontend_html, 'Instance detail' ) && ! str_contains( $first_frontend_html, 'Second instance detail' ), 'Frontend rendering must resolve the first synced-pattern instance slot independently.' );
$assert( str_contains( $second_frontend_html, 'Second instance detail' ) && ! str_contains( $second_frontend_html, '>Instance detail<' ), 'Frontend rendering must resolve the second synced-pattern instance slot independently.' );
$assert( 1 === $first_frontend_block->context_refreshes && array( 'dynamic' => false ) === ( $first_frontend_block->render_options[0] ?? null ), 'Frontend slot resolution must refresh the native Pattern Overrides context and reuse WordPress static inner-block rendering.' );
$plain_frontend_block = new WP_Block();
$plain_frontend_block->parsed_block = $slot_pattern[0];
$assert( '<div>Native output</div>' === $service->render_instance_slots( '<div>Native output</div>', $instance, $plain_frontend_block ), 'A synced pattern without instance slots must retain WordPress native rendering unchanged.' );

$collect = new ReflectionMethod( Synced_Structural_Pattern_Service::class, 'collect_bound_fields' );
$collect->setAccessible( true );
$found = array();
$collect->invokeArgs( $service, array( $pattern_blocks, &$found ) );
$assert( in_array( '__default', (array) ( $found['hero.title']['attributes'] ?? array() ), true ), 'Composer must recognize WordPress native __default Pattern Override bindings.' );

$page_validator = ( new ReflectionClass( Page_Validator::class ) )->newInstanceWithoutConstructor();
$validate_slot_patterns = new ReflectionMethod( Page_Validator::class, 'validate_slot_patterns' );
$validate_slot_patterns->setAccessible( true );
$slot_contract = array(
	'nodes' => array(
		array(
			'id' => 'body.additional',
			'mode' => 'slot',
			'allowed_patterns' => array( 'wpsuite/repeatable-card' ),
			'pattern_occurrences' => array( 'wpsuite/repeatable-card' => array( 'min' => 1, 'max' => 2 ) ),
		),
	),
);
$valid_inventory = array(
	array( 'slot_id' => 'body.additional', 'pattern' => 'wpsuite/repeatable-card' ),
	array( 'slot_id' => 'body.additional', 'pattern' => 'wpsuite/repeatable-card' ),
);
$assert( array() === $validate_slot_patterns->invoke( $page_validator, $slot_contract, $valid_inventory ), 'A slot must accept an allowed synced pattern within its occurrence bounds.' );
$overflow_inventory = array_merge( $valid_inventory, array( $valid_inventory[0] ) );
$overflow_errors = $validate_slot_patterns->invoke( $page_validator, $slot_contract, $overflow_inventory );
$assert( in_array( 'slot_pattern_cardinality', array_column( $overflow_errors, 'code' ), true ), 'A slot must reject a synced pattern above its maximum occurrence count.' );
$wrong_pattern_errors = $validate_slot_patterns->invoke( $page_validator, $slot_contract, array( array( 'slot_id' => 'body.additional', 'pattern' => 'wpsuite/other-card' ) ) );
$assert( in_array( 'slot_pattern_not_allowed', array_column( $wrong_pattern_errors, 'code' ), true ), 'A slot must reject a synced pattern outside its explicit allow-list.' );

$source = (string) file_get_contents( dirname( __DIR__ ) . '/src/Execution/Synced_Structural_Pattern_Service.php' );
$assembler = (string) file_get_contents( dirname( __DIR__ ) . '/src/Execution/Pattern_Assembler.php' );
$validator = (string) file_get_contents( dirname( __DIR__ ) . '/src/Execution/Page_Validator.php' );
$assert( str_contains( $source, "'core/pattern-overrides'" ), 'Synced structural patterns must validate native WordPress Pattern Override bindings.' );
$assert( str_contains( $source, "in_array( '__default', \$bound_attributes, true )" ), 'A native __default Pattern Override binding must satisfy declared attribute contracts.' );
$assert( str_contains( $source, "'blockName'    => 'core/block'" ), 'Synced structural pattern instances must use native core/block references.' );
$assert( str_contains( $source, "'patternName'    => \$pattern" ), 'Composer must retain the stable synced-pattern identity in its namespaced metadata.' );
$assert( str_contains( $source, "'patternInstanceId' => \$pattern_instance_id" ), 'Composer must assign every synced reference a stable pattern instance ID.' );
$assert( str_contains( $source, 'synced_pattern_version_mismatch' ) && str_contains( $source, 'synced_pattern_hash_mismatch' ), 'Recursive pattern expansion must fail closed on stale version or content-hash metadata.' );
$assert( str_contains( $source, 'synced_pattern_cycle' ) && str_contains( $source, 'MAX_NESTING_DEPTH' ), 'Recursive synced patterns must be protected against cycles and excessive nesting.' );
$assert( ! str_contains( $source, "\t\t\t\t'patternName' => \$pattern" ), 'A native core/block reference must not be marked as an unsynced WordPress pattern through top-level metadata.patternName.' );
$assert( str_contains( $source, "'lock'     => array( 'move' => true, 'remove' => true )" ), 'The synced core/block wrapper must protect structural movement and removal while native Pattern Override fields remain editable.' );
$assert( str_contains( $source, "INSTANCE_SLOTS_KEY = 'instanceSlots'" ), 'Synced references must retain instance-owned extension-slot content outside the shared wp_block record.' );
$assert( str_contains( $source, "'render_block_core/block'" ) && str_contains( $source, "array( 'dynamic' => false )" ), 'Frontend rendering must resolve instance slots through the native synced-pattern block instance.' );
$assert( str_contains( $assembler, 'materialize_instance' ), 'Pattern assembly must emit synced structural pattern instances.' );
$assert( str_contains( $validator, 'expand_blocks' ), 'Page validation must inspect the current synced pattern structure with instance overrides applied.' );

if ( $failures ) {
	fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
	exit( 1 );
}

echo "synced-structural-pattern: ok\n";
