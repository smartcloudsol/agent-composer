<?php
/* SmartCloud Agent Composer execution contract. */

namespace SmartCloud\AgentComposer\Execution;

/** Durable canonical baseline, typed override manifest, and drift state. */
final class Managed_Document_State {
	public const MANAGED_META                     = '_composer_managed_document';
	public const BLUEPRINT_META                   = '_composer_blueprint';
	public const BLUEPRINT_VERSION_META           = '_composer_blueprint_version';
	public const STRUCTURE_CONTRACT_META           = '_composer_structure_contract';
	public const STRUCTURE_CONTRACT_VERSION_META   = '_composer_structure_contract_version';
	public const CANONICAL_BASELINE_META           = '_composer_canonical_baseline';
	public const OVERRIDE_MANIFEST_META            = '_composer_override_manifest';
	public const CONTRACT_HASH_META                = '_composer_contract_hash';
	public const STATUS_META                       = '_composer_status';

	private const OVERRIDE_TYPES = array( 'CONTENT', 'VISIBILITY', 'ORDER', 'STRUCTURE', 'DETACHED' );

	public function __construct( private readonly Config_Repository $config ) {}

	/** Build protected meta for a new or existing managed document. */
	public function metadata_for( int $source_post_id, string $page_type, array $validation ): array {
		$blueprint = $this->config->get_blueprint( $page_type );
		$active    = $this->active_baseline( $blueprint, $validation );
		$stored    = $source_post_id > 0 ? $this->stored_baseline( $source_post_id ) : null;
		$baseline  = null === $stored ? $active : $stored;
		$manifest_result = $this->manifest_for( $source_post_id, $blueprint );
		$manifest        = $manifest_result['manifest'];
		$status          = $this->status( $baseline, $active, $manifest, $validation, null === $stored, $manifest_result['valid'] );
		$structure = is_array( $baseline['structure_contract'] ?? null ) ? $baseline['structure_contract'] : null;

		return array(
			self::MANAGED_META                   => '1',
			self::BLUEPRINT_META                 => (string) $baseline['blueprint']['id'],
			self::BLUEPRINT_VERSION_META         => (int) $baseline['blueprint']['version'],
			self::STRUCTURE_CONTRACT_META        => null === $structure ? '' : (string) $structure['id'],
			self::STRUCTURE_CONTRACT_VERSION_META => null === $structure ? 0 : (int) $structure['version'],
			self::CANONICAL_BASELINE_META         => $baseline,
			self::OVERRIDE_MANIFEST_META          => $manifest,
			self::CONTRACT_HASH_META              => (string) ( $baseline['contract_hash'] ?? '' ),
			self::STATUS_META                     => $status,
		);
	}

	/**
	 * Accept the active fingerprint after Composer rebuilt the complete document
	 * from the same versioned Blueprint and Structure Contract.
	 *
	 * This is deliberately narrower than ordinary validation: version or contract
	 * changes and structural/detached overrides still require an explicit migration.
	 */
	public function metadata_for_rematerialized_document( int $post_id, string $page_type, array $validation ): array {
		$meta      = $this->metadata_for( $post_id, $page_type, $validation );
		$blueprint = $this->config->get_blueprint( $page_type );
		$active    = $this->active_baseline( $blueprint, $validation );
		$stored    = $this->stored_baseline( $post_id );
		$manifest  = (array) ( $meta[ self::OVERRIDE_MANIFEST_META ] ?? array() );
		$types     = array_map( 'strval', array_column( (array) ( $manifest['overrides'] ?? array() ), 'type' ) );

		if (
			null === $stored
			|| true !== ( $validation['valid'] ?? false )
			|| $stored['blueprint'] !== $active['blueprint']
			|| $stored['structure_contract'] !== $active['structure_contract']
			|| ! hash_equals( (string) $stored['contract_hash'], (string) $active['contract_hash'] )
			|| array_intersect( array( 'ORDER', 'STRUCTURE', 'DETACHED' ), $types )
		) {
			return $meta;
		}

		$structure = is_array( $active['structure_contract'] ?? null ) ? $active['structure_contract'] : null;
		$meta[ self::BLUEPRINT_META ]                   = (string) $active['blueprint']['id'];
		$meta[ self::BLUEPRINT_VERSION_META ]           = (int) $active['blueprint']['version'];
		$meta[ self::STRUCTURE_CONTRACT_META ]           = null === $structure ? '' : (string) $structure['id'];
		$meta[ self::STRUCTURE_CONTRACT_VERSION_META ]   = null === $structure ? 0 : (int) $structure['version'];
		$meta[ self::CANONICAL_BASELINE_META ]           = $active;
		$meta[ self::CONTRACT_HASH_META ]                = (string) $active['contract_hash'];
		$meta[ self::STATUS_META ]                       = 'VALID';
		return $meta;
	}

	public function persist( int $post_id, string $page_type, array $validation, int $source_post_id = 0 ): array {
		$meta = $this->metadata_for( $source_post_id > 0 ? $source_post_id : $post_id, $page_type, $validation );
		foreach ( $meta as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
		}
		return $this->public_state( $post_id );
	}

	public function copy( int $source_post_id, int $target_post_id ): void {
		foreach ( $this->meta_keys() as $key ) {
			$value = get_post_meta( $source_post_id, $key, true );
			update_post_meta( $target_post_id, $key, $value );
		}
	}

	public function public_state( int $post_id ): array {
		$baseline = $this->value( get_post_meta( $post_id, self::CANONICAL_BASELINE_META, true ) );
		$status   = (string) get_post_meta( $post_id, self::STATUS_META, true );
		return array(
			'status'                => '' === $status ? ( null === $baseline ? 'LEGACY_VERSION' : 'OVERRIDE_CONFLICT' ) : $status,
			'canonical_baseline'    => $baseline,
			'override_manifest'     => $this->value( get_post_meta( $post_id, self::OVERRIDE_MANIFEST_META, true ) ),
			'contract_hash'         => (string) get_post_meta( $post_id, self::CONTRACT_HASH_META, true ),
		);
	}

	/** Managed state is independent from the narrower agent-owned draft marker. */
	public function is_managed( int $post_id ): bool {
		if ( '1' === (string) get_post_meta( $post_id, self::MANAGED_META, true ) ) {
			return true;
		}
		return null !== $this->stored_baseline( $post_id );
	}

	/** Read an exact stored baseline and typed manifest for migration planning. */
	public function migration_source_state( int $post_id ): array {
		$baseline = $this->stored_baseline( $post_id );
		$raw      = $this->value( get_post_meta( $post_id, self::OVERRIDE_MANIFEST_META, true ) );
		if ( null === $baseline || ! is_array( $raw ) || 1 !== (int) ( $raw['schema_version'] ?? 0 ) || ! is_array( $raw['overrides'] ?? null ) ) {
			throw new Execution_Exception( 'migration_source_state_invalid', 'The managed source does not contain a valid canonical baseline and override manifest.' );
		}
		$manifest = $this->normalize_manifest( $raw, null );
		return array(
			'baseline'      => $baseline,
			'manifest'      => $manifest,
			'contract_hash' => (string) get_post_meta( $post_id, self::CONTRACT_HASH_META, true ),
			'status'        => (string) get_post_meta( $post_id, self::STATUS_META, true ),
		);
	}

	/** Build explicit target metadata for a validated migration proposal. */
	public function metadata_for_migration( string $page_type, array $validation, array $manifest ): array {
		if ( true !== ( $validation['valid'] ?? false ) ) {
			throw new Execution_Exception( 'migration_target_invalid', 'Migration metadata can be created only for a valid target document.' );
		}
		$blueprint = $this->config->get_blueprint( $page_type );
		$active    = $this->active_baseline( $blueprint, $validation );
		$contract  = is_array( $blueprint['resolved_structure_contract'] ?? null ) ? $blueprint['resolved_structure_contract'] : array();
		$manifest  = $this->normalize_manifest( $manifest, $contract );
		$structure = (array) $active['structure_contract'];

		return array(
			self::MANAGED_META                     => '1',
			self::BLUEPRINT_META                   => (string) $active['blueprint']['id'],
			self::BLUEPRINT_VERSION_META           => (int) $active['blueprint']['version'],
			self::STRUCTURE_CONTRACT_META           => (string) ( $structure['id'] ?? '' ),
			self::STRUCTURE_CONTRACT_VERSION_META   => (int) ( $structure['version'] ?? 0 ),
			self::CANONICAL_BASELINE_META           => $active,
			self::OVERRIDE_MANIFEST_META            => $manifest,
			self::CONTRACT_HASH_META                => (string) ( $active['contract_hash'] ?? '' ),
			self::STATUS_META                       => 'VALID',
		);
	}

	private function active_baseline( array $blueprint, array $validation ): array {
		$reference = is_array( $blueprint['structure_contract'] ?? null ) ? $blueprint['structure_contract'] : null;
		$structure = is_array( $validation['structure_contract'] ?? null ) ? $validation['structure_contract'] : array();
		return array(
			'blueprint' => array(
				'id'      => (string) $blueprint['page_type'],
				'version' => max( 1, (int) ( $blueprint['schema_version'] ?? 1 ) ),
			),
			'structure_contract' => null === $reference ? null : array(
				'id'      => (string) $reference['id'],
				'version' => (int) $reference['version'],
			),
			'contract_hash'         => null === $reference ? '' : (string) ( $structure['contract_hash'] ?? '' ),
			'structural_fingerprint' => null === $reference ? '' : (string) ( $structure['structural_fingerprint'] ?? '' ),
		);
	}

	private function stored_baseline( int $post_id ): ?array {
		$value = $this->value( get_post_meta( $post_id, self::CANONICAL_BASELINE_META, true ) );
		if ( ! is_array( $value ) || ! is_array( $value['blueprint'] ?? null ) ) {
			return null;
		}
		$id      = trim( (string) ( $value['blueprint']['id'] ?? '' ) );
		$version = (int) ( $value['blueprint']['version'] ?? 0 );
		if ( '' === $id || $version < 1 ) {
			return null;
		}
		$structure = $value['structure_contract'] ?? null;
		if ( null !== $structure && ( ! is_array( $structure ) || '' === trim( (string) ( $structure['id'] ?? '' ) ) || (int) ( $structure['version'] ?? 0 ) < 1 ) ) {
			return null;
		}
		return array(
			'blueprint'              => array( 'id' => $id, 'version' => $version ),
			'structure_contract'      => null === $structure ? null : array( 'id' => (string) $structure['id'], 'version' => (int) $structure['version'] ),
			'contract_hash'           => (string) ( $value['contract_hash'] ?? '' ),
			'structural_fingerprint'  => (string) ( $value['structural_fingerprint'] ?? '' ),
		);
	}

	/** @return array{manifest: array, valid: bool} */
	private function manifest_for( int $post_id, array $blueprint ): array {
		$raw           = $post_id > 0 ? get_post_meta( $post_id, self::OVERRIDE_MANIFEST_META, true ) : '';
		$has_stored    = ! ( '' === $raw || null === $raw );
		$stored        = $has_stored ? $this->value( $raw ) : null;
		$manifest_valid = ! $has_stored || ( is_array( $stored ) && 1 === (int) ( $stored['schema_version'] ?? 0 ) && is_array( $stored['overrides'] ?? null ) );
		$overrides = array();
		if ( $manifest_valid && is_array( $stored ) ) {
			foreach ( $stored['overrides'] as $override ) {
				$type = is_array( $override ) ? strtoupper( trim( (string) ( $override['type'] ?? '' ) ) ) : '';
				$path = is_array( $override ) ? trim( (string) ( $override['path'] ?? '' ) ) : '';
				if ( in_array( $type, self::OVERRIDE_TYPES, true ) && preg_match( '/^[a-z][a-z0-9._-]{0,127}$/', $path ) ) {
					$overrides[ $type . ':' . $path ] = array( 'type' => $type, 'path' => $path );
				} else {
					$manifest_valid = false;
				}
			}
		}

		if ( ! $has_stored ) {
			foreach ( (array) ( $blueprint['resolved_structure_contract']['nodes'] ?? array() ) as $node ) {
				if ( 'INSTANCE_CONTENT' === (string) ( $node['ownership'] ?? '' ) ) {
					$path = (string) $node['id'];
					$overrides[ 'CONTENT:' . $path ] = array( 'type' => 'CONTENT', 'path' => $path );
				}
			}
		}

		ksort( $overrides, SORT_STRING );
		return array(
			'manifest' => array( 'schema_version' => 1, 'overrides' => array_values( $overrides ) ),
			'valid'    => $manifest_valid,
		);
	}

	private function normalize_manifest( array $manifest, ?array $contract ): array {
		if ( 1 !== (int) ( $manifest['schema_version'] ?? 0 ) || ! is_array( $manifest['overrides'] ?? null ) || count( $manifest['overrides'] ) > 500 ) {
			throw new Execution_Exception( 'migration_override_manifest_invalid', 'The migration override manifest is malformed.' );
		}
		$known = null;
		if ( is_array( $contract ) ) {
			$known = array_fill_keys( array_map( 'strval', array_column( (array) ( $contract['nodes'] ?? array() ), 'id' ) ), true );
		}
		$overrides = array();
		foreach ( $manifest['overrides'] as $override ) {
			$type = is_array( $override ) ? strtoupper( trim( (string) ( $override['type'] ?? '' ) ) ) : '';
			$path = is_array( $override ) ? trim( (string) ( $override['path'] ?? '' ) ) : '';
			if ( ! in_array( $type, self::OVERRIDE_TYPES, true ) || 1 !== preg_match( '/^[a-z][a-z0-9._-]{0,127}$/', $path ) || ( is_array( $known ) && ! isset( $known[ $path ] ) ) ) {
				throw new Execution_Exception( 'migration_override_manifest_invalid', 'Every migrated override must use a supported type and target semantic path.' );
			}
			$overrides[ $type . ':' . $path ] = array( 'type' => $type, 'path' => $path );
		}
		ksort( $overrides, SORT_STRING );
		return array( 'schema_version' => 1, 'overrides' => array_values( $overrides ) );
	}

	private function status( array $baseline, array $active, array $manifest, array $validation, bool $new_baseline, bool $manifest_valid ): string {
		if ( ! $manifest_valid ) {
			return 'OVERRIDE_CONFLICT';
		}
		$types = array_column( (array) ( $manifest['overrides'] ?? array() ), 'type' );
		if ( in_array( 'DETACHED', $types, true ) ) {
			return 'DETACHED';
		}
		if ( true !== ( $validation['valid'] ?? false ) ) {
			$structure_errors = array_filter(
				(array) ( $validation['errors'] ?? array() ),
				static fn( mixed $error ): bool => is_array( $error ) && 'composer_contract_violation' === ( $error['code'] ?? '' )
			);
			return empty( $structure_errors ) ? 'USER_DRIFT' : ( $new_baseline ? 'INVALID_STRUCTURE' : 'USER_DRIFT' );
		}
		if ( $baseline['blueprint'] !== $active['blueprint'] || $baseline['structure_contract'] !== $active['structure_contract'] || ! hash_equals( (string) $baseline['contract_hash'], (string) $active['contract_hash'] ) ) {
			return array_intersect( array( 'ORDER', 'STRUCTURE' ), $types ) ? 'REBASE_REQUIRED' : 'MIGRATION_REQUIRED';
		}
		if ( ! $new_baseline && ! hash_equals( (string) $baseline['structural_fingerprint'], (string) $active['structural_fingerprint'] ) ) {
			return 'USER_DRIFT';
		}
		return 'VALID';
	}

	private function value( mixed $value ): mixed {
		if ( is_array( $value ) ) {
			return $value;
		}
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return null;
		}
		$decoded = json_decode( $value, true );
		return is_array( $decoded ) ? $decoded : null;
	}

	private function meta_keys(): array {
		return array(
			self::MANAGED_META,
			self::BLUEPRINT_META,
			self::BLUEPRINT_VERSION_META,
			self::STRUCTURE_CONTRACT_META,
			self::STRUCTURE_CONTRACT_VERSION_META,
			self::CANONICAL_BASELINE_META,
			self::OVERRIDE_MANIFEST_META,
			self::CONTRACT_HASH_META,
			self::STATUS_META,
		);
	}
}
