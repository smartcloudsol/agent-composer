<?php

declare(strict_types=1);

namespace {
	$GLOBALS['managed_document_meta'] = array();

	function get_post_meta( int $post_id, string $key, bool $single = false ): mixed {
		return $GLOBALS['managed_document_meta'][ $post_id ][ $key ] ?? '';
	}

	function update_post_meta( int $post_id, string $key, mixed $value ): bool {
		$GLOBALS['managed_document_meta'][ $post_id ][ $key ] = $value;
		return true;
	}
}

namespace SmartCloud\AgentComposer\Execution {
	final class Config_Repository {
		public array $blueprint;

		public function __construct() {
			$this->blueprint = array(
				'page_type'     => 'solution',
				'schema_version' => 4,
				'structure_contract' => array( 'id' => 'solution-editor', 'version' => 3 ),
				'resolved_structure_contract' => array(
					'nodes' => array(
						array( 'id' => 'hero', 'ownership' => 'BLUEPRINT' ),
						array( 'id' => 'hero.title', 'ownership' => 'INSTANCE_CONTENT' ),
						array( 'id' => 'hero.image', 'ownership' => 'INSTANCE_CONTENT' ),
					),
				),
			);
		}

		public function get_blueprint( string $page_type ): array {
			return $this->blueprint;
		}
	}
}

namespace {
	use SmartCloud\AgentComposer\Execution\Config_Repository;
	use SmartCloud\AgentComposer\Execution\Managed_Document_State;

	require_once dirname( __DIR__ ) . '/src/Execution/Managed_Document_State.php';

	$failures = array();
	$assert = static function ( bool $condition, string $message ) use ( &$failures ): void {
		if ( ! $condition ) {
			$failures[] = $message;
		}
	};

	$config = new Config_Repository();
	$state  = new Managed_Document_State( $config );
	$validation = array(
		'valid' => true,
		'errors' => array(),
		'structure_contract' => array(
			'id' => 'solution-editor',
			'version' => 3,
			'contract_hash' => 'sha256:' . str_repeat( 'a', 64 ),
			'structural_fingerprint' => 'sha256:' . str_repeat( 'b', 64 ),
		),
	);

	$new = $state->metadata_for( 0, 'solution', $validation );
	$assert( '1' === $new[ Managed_Document_State::MANAGED_META ], 'Managed-document identity must be independent from agent draft ownership.' );
	$assert( 'solution' === $new[ Managed_Document_State::BLUEPRINT_META ], 'A managed document must store its stable Blueprint ID.' );
	$assert( 4 === $new[ Managed_Document_State::BLUEPRINT_VERSION_META ], 'A managed document must store its exact Blueprint version.' );
	$assert( 'solution-editor' === $new[ Managed_Document_State::STRUCTURE_CONTRACT_META ], 'A managed document must store its exact Structure Contract ID.' );
	$assert( 3 === $new[ Managed_Document_State::STRUCTURE_CONTRACT_VERSION_META ], 'A managed document must store its exact Structure Contract version.' );
	$assert( 'VALID' === $new[ Managed_Document_State::STATUS_META ], 'A newly validated baseline must start VALID.' );
	$assert(
		array( 'hero.image', 'hero.title' ) === array_column( $new[ Managed_Document_State::OVERRIDE_MANIFEST_META ]['overrides'], 'path' ),
		'The manifest must index instance content by stable semantic path without copying values.'
	);
	$migrated = $state->metadata_for_migration(
		'solution',
		$validation,
		array( 'schema_version' => 1, 'overrides' => array( array( 'type' => 'CONTENT', 'path' => 'hero.title' ) ) )
	);
	$assert( 4 === $migrated[ Managed_Document_State::BLUEPRINT_VERSION_META ], 'Migration metadata must explicitly advance to the active Blueprint baseline.' );
	$assert( 'VALID' === $migrated[ Managed_Document_State::STATUS_META ], 'A validated migration target must start in VALID state.' );
	$assert( array( 'hero.title' ) === array_column( $migrated[ Managed_Document_State::OVERRIDE_MANIFEST_META ]['overrides'], 'path' ), 'Migration metadata must persist the rebased target manifest rather than regenerating source overrides.' );

	foreach ( $new as $key => $value ) {
		update_post_meta( 12, $key, $value );
	}
	$assert( $state->is_managed( 12 ), 'A persisted managed-document marker must govern native editor saves.' );
	$current = $state->metadata_for( 12, 'solution', $validation );
	$assert( 'VALID' === $current[ Managed_Document_State::STATUS_META ], 'An unchanged current baseline must remain VALID.' );

	$drifted = $validation;
	$drifted['structure_contract']['structural_fingerprint'] = 'sha256:' . str_repeat( 'c', 64 );
	$drift = $state->metadata_for( 12, 'solution', $drifted );
	$assert( 'USER_DRIFT' === $drift[ Managed_Document_State::STATUS_META ], 'A changed protected structural fingerprint must become USER_DRIFT.' );
	$rematerialized = $state->metadata_for_rematerialized_document( 12, 'solution', $drifted );
	$assert( 'VALID' === $rematerialized[ Managed_Document_State::STATUS_META ], 'A complete canonical rematerialization may accept a new fingerprint under the same exact versioned contract.' );
	$assert( $drifted['structure_contract']['structural_fingerprint'] === $rematerialized[ Managed_Document_State::CANONICAL_BASELINE_META ]['structural_fingerprint'], 'Canonical rematerialization must store the newly validated structural fingerprint.' );

	$config->blueprint['schema_version'] = 5;
	$migration = $state->metadata_for( 12, 'solution', $validation );
	$assert( 'MIGRATION_REQUIRED' === $migration[ Managed_Document_State::STATUS_META ], 'A newer active Blueprint must require migration without rewriting the stored baseline.' );
	$assert( 4 === $migration[ Managed_Document_State::BLUEPRINT_VERSION_META ], 'Ordinary validation must not silently advance the stored baseline.' );
	$rematerialized_migration = $state->metadata_for_rematerialized_document( 12, 'solution', $validation );
	$assert( 'MIGRATION_REQUIRED' === $rematerialized_migration[ Managed_Document_State::STATUS_META ], 'Canonical rematerialization must not bypass a Blueprint or Structure Contract migration.' );

	update_post_meta( 12, Managed_Document_State::OVERRIDE_MANIFEST_META, array(
		'schema_version' => 1,
		'overrides' => array( array( 'type' => 'ORDER', 'path' => 'hero' ) ),
	) );
	$rebase = $state->metadata_for( 12, 'solution', $validation );
	$assert( 'REBASE_REQUIRED' === $rebase[ Managed_Document_State::STATUS_META ], 'An ORDER override must require an explicit rebase across Blueprint versions.' );

	update_post_meta( 12, Managed_Document_State::OVERRIDE_MANIFEST_META, array(
		'schema_version' => 1,
		'overrides' => array( array( 'type' => 'DETACHED', 'path' => 'hero' ) ),
	) );
	$detached = $state->metadata_for( 12, 'solution', $validation );
	$assert( 'DETACHED' === $detached[ Managed_Document_State::STATUS_META ], 'A DETACHED override must remain outside automatic migration.' );

	update_post_meta( 12, Managed_Document_State::OVERRIDE_MANIFEST_META, array(
		'schema_version' => 1,
		'overrides' => array( array( 'type' => 'UNKNOWN', 'path' => 'hero' ) ),
	) );
	$conflict = $state->metadata_for( 12, 'solution', $validation );
	$assert( 'OVERRIDE_CONFLICT' === $conflict[ Managed_Document_State::STATUS_META ], 'A malformed protected override manifest must fail closed.' );

	$config->blueprint['schema_version'] = 4;
	$invalid_validation = $validation;
	$invalid_validation['valid'] = false;
	$invalid_validation['errors'][] = array( 'code' => 'composer_contract_violation' );
	$invalid = $state->metadata_for( 0, 'solution', $invalid_validation );
	$assert( 'INVALID_STRUCTURE' === $invalid[ Managed_Document_State::STATUS_META ], 'An invalid document without an established baseline must report INVALID_STRUCTURE.' );

	$state->copy( 12, 99 );
	$assert(
		get_post_meta( 12, Managed_Document_State::CANONICAL_BASELINE_META, true ) === get_post_meta( 99, Managed_Document_State::CANONICAL_BASELINE_META, true ),
		'Merging or cloning managed state must copy the exact protected baseline.'
	);

	$root = dirname( __DIR__ );
	$guard_source = (string) file_get_contents( $root . '/src/Execution/Structure_Contract_Save_Guard.php' );
	$role_source = (string) file_get_contents( $root . '/src/Infrastructure/WordPress/Activation.php' );
	$runtime_source = (string) file_get_contents( $root . '/src/Application/Execution/ExecutionRuntime.php' );
	foreach ( array( 'canLockBlocks', 'CAP_MANAGE_STRUCTURE', 'allow_structure_change', 'structure-drift-created', "['templateLock'] = 'insert'", 'structure_contract_mode' ) as $required ) {
		$assert( str_contains( $guard_source, $required ), 'The Gutenberg structural authority boundary is missing: ' . $required );
	}
	$assert( str_contains( $runtime_source, "add_filter( 'block_editor_settings_all'" ), 'The editor lock policy must be registered during the normal WordPress request lifecycle.' );
	$assert( str_contains( $role_source, "CAP_MANAGE_STRUCTURE = 'manage_agent_composer_structure'" ), 'Structural authority must use the dedicated delegable capability.' );
	$assert( str_contains( $role_source, "CAP_RUN_MIGRATIONS = 'run_agent_composer_migrations'" ), 'Migration execution must use a separate delegable capability.' );
	$assert( str_contains( $role_source, "ROLE_SCHEMA_VERSION = '8'" ), 'Adding approval authority must advance the role schema.' );
	$agent_caps_start = strpos( $role_source, '$agent_caps = array(' );
	$agent_caps_end = strpos( $role_source, '$role = get_role', false === $agent_caps_start ? 0 : $agent_caps_start );
	$agent_caps_source = false === $agent_caps_start || false === $agent_caps_end ? $role_source : substr( $role_source, $agent_caps_start, $agent_caps_end - $agent_caps_start );
	$assert( ! str_contains( $agent_caps_source, 'CAP_MANAGE_STRUCTURE' ), 'The ordinary SmartCloud Agent role must not receive structural authority.' );
	$assert( ! str_contains( $agent_caps_source, 'CAP_RUN_MIGRATIONS' ), 'The ordinary SmartCloud Agent role must not receive migration authority.' );
	$assert( str_contains( $runtime_source, 'new Structure_Contract_Save_Guard( $config, $validator, $document_state, $audit_table )' ), 'The save guard must receive the append-only audit boundary.' );

	if ( $failures ) {
		fwrite( STDERR, implode( PHP_EOL, $failures ) . PHP_EOL );
		exit( 1 );
	}

	echo "managed-document-state: ok\n";
}
