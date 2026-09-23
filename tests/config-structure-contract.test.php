<?php

declare(strict_types=1);

use SmartCloud\AgentComposer\Application\Configuration\ConfigSetValidator;
use SmartCloud\AgentComposer\Domain\Structure\StructureContract;

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( string $value ): string {
		return strtolower( (string) preg_replace( '/[^a-z0-9_-]/i', '', $value ) );
	}
}

require_once dirname( __DIR__ ) . '/src/Domain/Structure/StructureContract.php';
require_once dirname( __DIR__ ) . '/src/Application/Configuration/ConfigSetValidator.php';

$failures = array();
$assert   = static function ( bool $condition, string $message ) use ( &$failures ): void {
	if ( ! $condition ) {
		$failures[] = $message;
	}
};

$definition = array(
	'id'      => 'solution-editor',
	'version' => 3,
	'label'   => 'Solution editor',
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
			'id'                    => 'additional-content',
			'block'                 => 'smartcloud-agent-composer/extension-slot',
			'ownership'             => 'BLUEPRINT',
			'mode'                  => 'slot',
			'parent'                => null,
			'position'              => 20,
			'allowed_blocks'        => array( 'core/heading', 'core/paragraph', 'core/image' ),
			'allowed_patterns'      => array( 'wpsuite/repeatable-card' ),
			'pattern_occurrences'   => array( 'wpsuite/repeatable-card' => array( 'min' => 0, 'max' => 2 ) ),
			'min_blocks'            => 0,
			'max_blocks'            => 6,
			'allow_cross_slot_move' => false,
		),
	),
);

$registry = StructureContract::normalize_registry( array( 'solution-editor' => $definition ) );
$assert( true === $registry['valid'], 'A valid independently versioned Structure Contract registry must normalize.' );
$assert( 3 === ( $registry['value']['solution-editor']['version'] ?? null ), 'The normalized registry must preserve the independent contract version.' );
$assert( true === ( $registry['value']['solution-editor']['nodes'][1]['editable_content'] ?? null ), 'Content nodes must default to editable content.' );
$assert( false === ( $registry['value']['solution-editor']['nodes'][0]['editable_content'] ?? null ), 'Structure nodes must default to protected content.' );
$assert( array( 'wpsuite/repeatable-card' ) === ( $registry['value']['solution-editor']['nodes'][2]['allowed_patterns'] ?? null ), 'Slots must retain an explicit synced-pattern allow-list.' );
$assert( array( 'min' => 0, 'max' => 2 ) === ( $registry['value']['solution-editor']['nodes'][2]['pattern_occurrences']['wpsuite/repeatable-card'] ?? null ), 'Slots must normalize per-pattern occurrence bounds.' );
$renormalized = StructureContract::normalize_registry( $registry['value'] );
$assert( true === $renormalized['valid'] && $registry['value'] === $renormalized['value'], 'Normalized Structure Contracts must be safe to validate again after a design-policy filter.' );

$reference = StructureContract::normalize_reference( array( 'id' => 'solution-editor', 'version' => 3 ) );
$assert( true === $reference['valid'], 'A Blueprint must be able to reference a Structure Contract by ID and version only.' );
$assert( array( 'id', 'version' ) === array_keys( $reference['value'] ), 'A normalized Blueprint reference must not embed the Structure Contract definition.' );

$duplicate              = $definition;
$duplicate['nodes'][]   = $duplicate['nodes'][0];
$duplicate_result       = StructureContract::normalize_definition( $duplicate );
$duplicate_error_codes  = array_column( $duplicate_result['errors'], 'code' );
$assert( in_array( 'structure-contract-node-duplicate', $duplicate_error_codes, true ), 'Duplicate semantic node IDs must fail closed.' );

$positional                           = $definition;
$positional['nodes'][1]['id']         = 'section-2';
$positional['nodes'][1]['parent']     = 'hero';
$positional_result                    = StructureContract::normalize_definition( $positional );
$positional_error_codes               = array_column( $positional_result['errors'], 'code' );
$assert( in_array( 'structure-contract-node-id-invalid', $positional_error_codes, true ), 'Array-position-derived semantic IDs must be rejected.' );

$missing_parent                       = $definition;
$missing_parent['nodes'][1]['parent'] = 'missing';
$missing_parent_result                = StructureContract::normalize_definition( $missing_parent );
$missing_parent_error_codes           = array_column( $missing_parent_result['errors'], 'code' );
$assert( in_array( 'structure-contract-parent-missing', $missing_parent_error_codes, true ), 'Every semantic parent must exist in the same contract.' );

$unsafe_slot                                      = $definition;
$unsafe_slot['nodes'][2]['allow_cross_slot_move'] = true;
$unsafe_slot_result                               = StructureContract::normalize_definition( $unsafe_slot );
$unsafe_slot_error_codes                          = array_column( $unsafe_slot_result['errors'], 'code' );
$assert( in_array( 'structure-contract-cross-slot-move-unsupported', $unsafe_slot_error_codes, true ), 'The initial contract must fail closed on unsupported cross-slot movement.' );

$reserved_attribute                                      = $definition;
$reserved_attribute['nodes'][1]['editable_attributes'][] = 'metadata';
$reserved_attribute_result                               = StructureContract::normalize_definition( $reserved_attribute );
$reserved_attribute_codes                                = array_column( $reserved_attribute_result['errors'], 'code' );
$assert( in_array( 'structure-contract-editable-attribute-reserved', $reserved_attribute_codes, true ), 'Composer identity and editor-control attributes must never be exposed as semantic editable values.' );

$validator = ( new ReflectionClass( ConfigSetValidator::class ) )->newInstanceWithoutConstructor();
$method    = new ReflectionMethod( ConfigSetValidator::class, 'validate_structure_contract_reference' );
$method->setAccessible( true );

$errors   = array();
$arguments = array(
	array( 'structure_contract' => array( 'id' => 'solution-editor', 'version' => 3 ), 'synced_patterns' => array( 'wpsuite/repeatable-card' ) ),
	'solution',
	'document',
	$registry['value'],
	&$errors,
);
$method->invokeArgs( $validator, $arguments );
$assert( array() === $errors, 'A Blueprint reference must validate against the exact registered Structure Contract version.' );

$errors = array();
$arguments = array(
	array( 'structure_contract' => array( 'id' => 'solution-editor', 'version' => 3 ), 'synced_patterns' => array() ),
	'solution',
	'document',
	$registry['value'],
	&$errors,
);
$method->invokeArgs( $validator, $arguments );
$assert( in_array( 'structure-slot-pattern-not-enabled', array_column( $errors, 'code' ), true ), 'A slot pattern must also be enabled by the Blueprint.' );

$invalid_occurrences = $definition;
$invalid_occurrences['nodes'][2]['pattern_occurrences']['wpsuite/repeatable-card'] = array( 'min' => 3, 'max' => 2 );
$invalid_occurrence_result = StructureContract::normalize_definition( $invalid_occurrences );
$assert( in_array( 'structure-contract-pattern-cardinality-invalid', array_column( $invalid_occurrence_result['errors'], 'code' ), true ), 'A per-pattern minimum cannot exceed its maximum.' );

$admin_creation = new ReflectionMethod( ConfigSetValidator::class, 'validate_admin_creation_policy' );
$admin_creation->setAccessible( true );
$blueprints = array(
	array( 'key' => 'examination', 'payload' => array( 'page_type' => 'examination', 'target_post_type' => 'vizsgalatok' ) ),
);
$errors = array();
$arguments = array(
	array( 'admin_creation' => array( 'vizsgalatok' => array( 'mode' => 'required', 'default_page_type' => 'examination' ) ) ),
	$blueprints,
	&$errors,
);
$admin_creation->invokeArgs( $validator, $arguments );
$assert( array() === $errors, 'A required native-editor rule may target the Blueprint assigned to the same CPT.' );

$errors = array();
$arguments = array(
	array( 'admin_creation' => array( 'orvosok' => array( 'mode' => 'required', 'default_page_type' => 'examination' ) ) ),
	$blueprints,
	&$errors,
);
$admin_creation->invokeArgs( $validator, $arguments );
$assert( in_array( 'admin-creation-blueprint-mismatch', array_column( $errors, 'code' ), true ), 'A native-editor rule must not bootstrap a Blueprint belonging to another CPT.' );

$errors    = array();
$arguments = array(
	array( 'structure_contract' => array( 'id' => 'solution-editor', 'version' => 2 ) ),
	'solution',
	'document',
	$registry['value'],
	&$errors,
);
$method->invokeArgs( $validator, $arguments );
$assert( 'structure-contract-version-mismatch' === ( $errors[0]['code'] ?? null ), 'A stale Blueprint contract reference must fail Config Set validation.' );

$errors    = array();
$arguments = array(
	array( 'structure_contract' => array( 'id' => 'solution-editor', 'version' => 3 ) ),
	'solution-record',
	'structured-record',
	$registry['value'],
	&$errors,
);
$method->invokeArgs( $validator, $arguments );
$assert( 'structure-contract-document-required' === ( $errors[0]['code'] ?? null ), 'Gutenberg Structure Contracts must not be attached to structured-record Blueprints.' );

$config_source = (string) file_get_contents( dirname( __DIR__ ) . '/src/Execution/Config_Repository.php' );
$assert( str_contains( $config_source, "'structure_contract_mode'] = 'legacy-document'" ), 'Legacy Blueprints must receive an explicit compatibility mode.' );
$assert( str_contains( $config_source, "'structure_contract_mode']     = 'enforced'" ), 'Resolved Structure Contracts must receive an explicit enforced mode.' );
$assert( str_contains( $config_source, "'resolved_structure_contract']" ), 'Runtime Blueprint output must expose the resolved deterministic contract.' );

if ( $failures ) {
	fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
	exit( 1 );
}

echo "structure-contract-config: ok\n";
