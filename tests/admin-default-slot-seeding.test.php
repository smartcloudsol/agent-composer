<?php

declare(strict_types=1);

namespace SmartCloud\AgentComposer\Execution {
	/** Read-only materializer double: no WordPress persistence is available. */
	final class Synced_Structural_Pattern_Service {
		public array $calls = array();
		public function materialize_instance( string $pattern, array $fields, array $blueprint, bool $defaults ): array {
			$this->calls[] = $pattern;
			return array( 'block' => array(
				'blockName' => 'core/block',
				'attrs' => array( 'metadata' => array( 'wpsuiteAgentComposer' => array(
					'patternName' => $pattern,
					'patternInstanceId' => 'fresh-' . count( $this->calls ),
				) ) ),
				'innerBlocks' => array(), 'innerHTML' => '', 'innerContent' => array(),
			) );
		}
	}
}

namespace {

use SmartCloud\AgentComposer\Execution\Pattern_Assembler;
use SmartCloud\AgentComposer\Execution\Execution_Exception;
use SmartCloud\AgentComposer\Execution\Synced_Structural_Pattern_Service;

require_once dirname( __DIR__ ) . '/src/Execution/Execution_Exception.php';
require_once dirname( __DIR__ ) . '/src/Execution/Pattern_Assembler.php';

$failures = array();
$assert   = static function ( bool $condition, string $message ) use ( &$failures ): void {
	if ( ! $condition ) {
		$failures[] = $message;
	}
};

$assembler = ( new ReflectionClass( Pattern_Assembler::class ) )->newInstanceWithoutConstructor();
$minimum   = new ReflectionMethod( Pattern_Assembler::class, 'admin_slot_minimum_children' );
$minimum->setAccessible( true );
$patterns = new Synced_Structural_Pattern_Service();
$pattern_property = new ReflectionProperty( Pattern_Assembler::class, 'synced_patterns' );
$pattern_property->setAccessible( true );
$pattern_property->setValue( $assembler, $patterns );

$paragraph = array(
	'blockName'    => 'core/paragraph',
	'attrs'        => array(),
	'innerBlocks'  => array(),
	'innerHTML'    => '<p>Meglévő tartalom</p>',
	'innerContent' => array( '<p>Meglévő tartalom</p>' ),
);
$node = array(
	'id'               => 'article.body',
	'mode'             => 'slot',
	'min_blocks'       => 2,
	'allowed_blocks'   => array( 'core/paragraph', 'core/list' ),
	'allowed_patterns' => array(),
);
$children = $minimum->invoke( $assembler, array( $paragraph ), $node, array(), 0 );
$assert( 2 === count( $children ), 'Admin defaults must fill a required slot to its declared minimum cardinality.' );
$assert( $paragraph === $children[0], 'Admin defaults must preserve existing instance-owned slot content.' );
$assert( 'core/paragraph' === ( $children[1]['blockName'] ?? '' ), 'A required authored-text slot must receive an empty paragraph block.' );
$assert( '<p></p>' === ( $children[1]['innerHTML'] ?? '' ), 'The generated minimum block must not publish instructional placeholder copy.' );

$pattern_node = array(
	'id' => 'article.sections', 'mode' => 'slot', 'min_blocks' => 2, 'max_blocks' => 2,
	'allowed_patterns' => array( 'test/a', 'test/b' ),
	'pattern_occurrences' => array( 'test/a' => array( 'min' => 1, 'max' => 1 ), 'test/b' => array( 'min' => 0, 'max' => 1 ) ),
	'allowed_blocks' => array(),
);
$children = $minimum->invoke( $assembler, array(), $pattern_node, array(), 0 );
$assert( array( 'test/a', 'test/b' ) === $patterns->calls, 'Remaining minimum capacity must use the next approved pattern after the first reaches its maximum.' );
$assert( 'fresh-1' === $children[0]['attrs']['metadata']['wpsuiteAgentComposer']['patternInstanceId'] &&
	'fresh-2' === $children[1]['attrs']['metadata']['wpsuiteAgentComposer']['patternInstanceId'], 'Each addition must materialize a fresh pattern instance.' );

$existing = $children[0];
$patterns->calls = array();
$children = $minimum->invoke( $assembler, array( $existing ), $pattern_node, array(), 0 );
$assert( $children[0] === $existing && array( 'test/b' ) === $patterns->calls, 'Existing instances must retain their identity and consume their occurrence capacity.' );

$fallback = array_replace( $pattern_node, array( 'min_blocks' => 3, 'max_blocks' => 3, 'allowed_blocks' => array( 'core/paragraph' ) ) );
$patterns->calls = array();
$children = $minimum->invoke( $assembler, array(), $fallback, array(), 0 );
$assert( array( 'test/a', 'test/b' ) === $patterns->calls && 3 === count( $children ) &&
	'core/paragraph' === $children[2]['blockName'] && '<p></p>' === $children[2]['innerHTML'], 'Exhausted pattern capacity must fall back to an explicitly allowed empty paragraph.' );

$required_second = $pattern_node;
$required_second['pattern_occurrences']['test/a'] = array( 'min' => 0, 'max' => 1 );
$required_second['pattern_occurrences']['test/b'] = array( 'min' => 1, 'max' => 1 );
$patterns->calls = array();
$minimum->invoke( $assembler, array(), $required_second, array(), 0 );
$assert( array( 'test/b', 'test/a' ) === $patterns->calls, 'Pattern minima must be fulfilled before remaining slot capacity in deterministic allowlist order.' );

$unbounded = $pattern_node;
$unbounded['allowed_patterns'] = array( 'test/a' );
$unbounded['pattern_occurrences'] = array( 'test/a' => array( 'min' => 0, 'max' => null ) );
$patterns->calls = array();
$children = $minimum->invoke( $assembler, array(), $unbounded, array(), 0 );
$assert( array( 'test/a', 'test/a' ) === $patterns->calls &&
	$children[0]['attrs']['metadata']['wpsuiteAgentComposer']['patternInstanceId'] !== $children[1]['attrs']['metadata']['wpsuiteAgentComposer']['patternInstanceId'], 'Unbounded repeated patterns must receive distinct new instance identities.' );

$bad_pattern = $pattern_node;
$bad_pattern['pattern_occurrences']['test/a']['min'] = 2;
$bad_total = $pattern_node;
$bad_total['min_blocks'] = 1;
$bad_total['max_blocks'] = 1;
$bad_total['pattern_occurrences']['test/b']['min'] = 1;
$zero_capacity = $pattern_node;
$zero_capacity['pattern_occurrences']['test/a'] = array( 'min' => 0, 'max' => 0 );
$zero_capacity['pattern_occurrences']['test/b'] = array( 'min' => 0, 'max' => 0 );
foreach ( array(
	array_replace( $pattern_node, array( 'min_blocks' => 3, 'max_blocks' => 3 ) ),
	array_replace( $pattern_node, array( 'max_blocks' => 1 ) ),
	$bad_pattern, $bad_total, $zero_capacity,
) as $impossible ) {
	$patterns->calls = array();
	$result = null;
	try {
		$result = $minimum->invoke( $assembler, array(), $impossible, array(), 0 );
		$assert( false, 'Impossible slot bounds must throw a controlled exception.' );
	} catch ( Execution_Exception $error ) {
		$assert( 'admin_creation_slot_default_missing' === $error->get_execution_code(), 'Impossible defaults must use the controlled missing-default error.' );
	}
	$assert( null === $result && array() === $patterns->calls, 'Impossible defaults must return no partial content and materialize no patterns.' );
}

$existing_blocks = array( $existing, $paragraph, $paragraph );
$patterns->calls = array();
try {
	$minimum->invoke( $assembler, $existing_blocks, $pattern_node, array(), 0 );
	$assert( false, 'Existing direct children over the slot maximum must be rejected without truncation.' );
} catch ( Execution_Exception $error ) {
	$assert( 'admin_creation_slot_default_missing' === $error->get_execution_code(), 'Overfull existing children must use the controlled missing-default error.' );
}
$assert( array() === $patterns->calls && array( $existing, $paragraph, $paragraph ) === $existing_blocks, 'An overfull slot must preserve its input and materialize no new patterns.' );

$source = (string) file_get_contents( dirname( __DIR__ ) . '/src/Execution/Pattern_Assembler.php' );
$assert( str_contains( $source, 'seed_admin_minimum_slots' ), 'Native-editor assembly must seed Structure Contract slot minima.' );
$assert( str_contains( $source, 'admin_default_pattern_instance' ), 'Required repeatable-pattern slots must receive valid synced pattern instances.' );
$assert( str_contains( $source, 'pattern_occurrences' ), 'Native-editor defaults must honor per-pattern minimum occurrence contracts.' );
$assert( str_contains( $source, 'find_slot_owners' ) && str_contains( $source, 'with_slot_children' ), 'Generated defaults must stay instance-owned instead of mutating shared wp_block records.' );

if ( $failures ) {
	fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
	exit( 1 );
}

echo "admin-default-slot-seeding: ok\n";
}
