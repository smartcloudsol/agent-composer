<?php

declare(strict_types=1);

use SmartCloud\AgentComposer\Domain\Structure\StructureMigration;

require_once dirname( __DIR__ ) . '/src/Domain/Structure/StructureContract.php';
require_once dirname( __DIR__ ) . '/src/Domain/Structure/StructureMigration.php';

$failures = array();
$assert = static function ( bool $condition, string $message ) use ( &$failures ): void {
	if ( ! $condition ) {
		$failures[] = $message;
	}
};

$old_contract = array(
	'id' => 'solution-editor',
	'version' => 3,
	'label' => 'Solution editor v3',
	'nodes' => array(
		array( 'id' => 'hero', 'block' => 'core/group', 'ownership' => 'BLUEPRINT', 'mode' => 'structure', 'parent' => null, 'position' => 10, 'required' => true ),
		array( 'id' => 'hero.title', 'block' => 'core/heading', 'ownership' => 'INSTANCE_CONTENT', 'mode' => 'content', 'parent' => 'hero', 'position' => 10, 'required' => true, 'editable_attributes' => array( 'content' ), 'editable_content' => true ),
		array( 'id' => 'benefits', 'block' => 'core/group', 'ownership' => 'BLUEPRINT', 'mode' => 'structure', 'parent' => null, 'position' => 20, 'required' => true ),
		array( 'id' => 'architecture', 'block' => 'core/group', 'ownership' => 'BLUEPRINT', 'mode' => 'structure', 'parent' => null, 'position' => 30, 'required' => true ),
	),
);
$definition = array(
	'id' => 'solution-page-3-to-4',
	'blueprint' => 'solution',
	'from' => array( 'blueprint_version' => 3, 'structure_contract' => array( 'id' => 'solution-editor', 'version' => 3 ) ),
	'to' => array( 'blueprint_version' => 4, 'structure_contract' => array( 'id' => 'solution-editor', 'version' => 4 ) ),
	'from_contract' => $old_contract,
	'operations' => array(
		array( 'type' => 'add_field', 'id' => 'hero.eyebrow', 'default' => null ),
		array( 'type' => 'move_section', 'id' => 'benefits', 'before' => 'architecture' ),
		array( 'type' => 'add_section', 'id' => 'proof', 'pattern' => 'wpsuite/proof', 'fields' => array( 'proof.title' => array( 'content' => 'Evidence' ) ), 'after' => 'architecture' ),
		array( 'type' => 'map_field', 'from' => 'hero.title', 'to' => 'hero.headline' ),
	),
	'override_rules' => array(
		array( 'type' => 'CONTENT', 'path' => 'hero.title', 'strategy' => 'map', 'target_path' => 'hero.headline' ),
		array( 'type' => 'ORDER', 'path' => 'benefits', 'strategy' => 'accept_target' ),
	),
);

$normalized = StructureMigration::normalize_registry( array( 'solution-page-3-to-4' => $definition ) );
$assert( $normalized['valid'], 'A complete version-gated migration registry must normalize.' );
$assert( 4 === count( $normalized['value']['solution-page-3-to-4']['operations'] ?? array() ), 'Migration operation order must be preserved.' );
$assert( 3 === ( $normalized['value']['solution-page-3-to-4']['from_contract']['version'] ?? 0 ), 'The exact historical source contract must remain in the normalized migration.' );

$wrong_contract = $definition;
$wrong_contract['from_contract']['version'] = 2;
$invalid = StructureMigration::normalize_registry( array( 'solution-page-3-to-4' => $wrong_contract ) );
$assert( ! $invalid['valid'] && in_array( 'structure-migration-source-contract-mismatch', array_column( $invalid['errors'], 'code' ), true ), 'A historical contract mismatch must fail closed.' );

$duplicate = $definition;
$duplicate['id'] = 'solution-page-3-to-4-alternative';
$invalid = StructureMigration::normalize_registry( array( 'solution-page-3-to-4' => $definition, 'solution-page-3-to-4-alternative' => $duplicate ) );
$assert( ! $invalid['valid'] && in_array( 'structure-migration-route-duplicate', array_column( $invalid['errors'], 'code' ), true ), 'Two migrations from the same exact baseline must be rejected as ambiguous.' );

$unsafe = $definition;
$unsafe['override_rules'][] = array( 'type' => 'DETACHED', 'path' => 'hero', 'strategy' => 'accept_target' );
$invalid = StructureMigration::normalize_registry( array( 'solution-page-3-to-4' => $unsafe ) );
$assert( ! $invalid['valid'] && in_array( 'structure-migration-override-strategy-invalid', array_column( $invalid['errors'], 'code' ), true ), 'DETACHED state must never receive an automatic rebase strategy.' );

$bad_position = $definition;
$bad_position['operations'][1]['after'] = 'hero';
$invalid = StructureMigration::normalize_registry( array( 'solution-page-3-to-4' => $bad_position ) );
$assert( ! $invalid['valid'] && in_array( 'structure-migration-position-invalid', array_column( $invalid['errors'], 'code' ), true ), 'A section operation must declare exactly one position anchor.' );

$missing_default = $definition;
unset( $missing_default['operations'][0]['default'] );
$invalid = StructureMigration::normalize_registry( array( 'solution-page-3-to-4' => $missing_default ) );
$assert( ! $invalid['valid'] && in_array( 'structure-migration-field-default-missing', array_column( $invalid['errors'], 'code' ), true ), 'An added field must declare an explicit default, including null.' );

if ( $failures ) {
	fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
	exit( 1 );
}

echo "structure-migration-registry: ok\n";
