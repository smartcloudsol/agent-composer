<?php

namespace SmartCloud\AgentComposer\Domain\Structure;

/** Pure normalization for deterministic, version-gated Structure migrations. */
final class StructureMigration {
	private const OVERRIDE_TYPES = array( 'CONTENT', 'VISIBILITY', 'ORDER', 'STRUCTURE', 'DETACHED' );
	private const OPERATION_TYPES = array( 'move_section', 'add_section', 'add_field', 'map_field' );

	/** @return array{valid:bool,value:array<string,array>,errors:list<array{code:string,message:string,path:string}>} */
	public static function normalize_registry( mixed $value ): array {
		if ( null === $value ) {
			$value = array();
		}
		if ( ! is_array( $value ) || ( ! empty( $value ) && array_is_list( $value ) ) || count( $value ) > 200 ) {
			return self::result( array(), array( self::issue( 'structure-migration-registry-invalid', 'Structure migrations must be a bounded object keyed by stable migration ID.', 'design_policy.structure_migrations' ) ) );
		}

		$migrations = array();
		$errors     = array();
		$routes     = array();
		foreach ( $value as $key => $definition ) {
			$path       = 'design_policy.structure_migrations.' . (string) $key;
			$normalized = self::normalize_definition( $definition, $path );
			$errors     = array_merge( $errors, $normalized['errors'] );
			if ( ! $normalized['valid'] ) {
				continue;
			}
			$migration = $normalized['value'];
			if ( (string) $key !== $migration['id'] ) {
				$errors[] = self::issue( 'structure-migration-key-mismatch', 'The migration registry key must match the migration ID.', $path . '.id' );
				continue;
			}
			$route = implode( ':', array( $migration['blueprint'], $migration['from']['blueprint_version'], $migration['from']['structure_contract']['id'], $migration['from']['structure_contract']['version'] ) );
			if ( isset( $routes[ $route ] ) ) {
				$errors[] = self::issue( 'structure-migration-route-duplicate', 'Only one migration may start from an exact managed baseline.', $path );
				continue;
			}
			$routes[ $route ]              = true;
			$migrations[ $migration['id'] ] = $migration;
		}

		ksort( $migrations, SORT_STRING );
		return self::result( $migrations, $errors );
	}

	/** @return array{valid:bool,value:array,errors:list<array{code:string,message:string,path:string}>} */
	public static function normalize_definition( mixed $value, string $path = 'structure_migration' ): array {
		if ( ! is_array( $value ) || array_is_list( $value ) ) {
			return self::result( array(), array( self::issue( 'structure-migration-invalid', 'A Structure migration must be an object.', $path ) ) );
		}
		$errors = array();
		if ( array_diff( array_keys( $value ), array( 'id', 'blueprint', 'from', 'to', 'from_contract', 'operations', 'override_rules' ) ) ) {
			$errors[] = self::issue( 'structure-migration-property-unknown', 'A Structure migration contains an unknown top-level property.', $path );
		}
		$id        = is_string( $value['id'] ?? null ) ? trim( $value['id'] ) : '';
		$blueprint = is_string( $value['blueprint'] ?? null ) ? trim( $value['blueprint'] ) : '';
		if ( ! self::valid_slug( $id ) ) {
			$errors[] = self::issue( 'structure-migration-id-invalid', 'A migration requires a stable slug ID.', $path . '.id' );
		}
		if ( ! self::valid_slug( $blueprint ) ) {
			$errors[] = self::issue( 'structure-migration-blueprint-invalid', 'A migration requires one stable Blueprint ID.', $path . '.blueprint' );
		}

		$from = self::normalize_baseline( $value['from'] ?? null, $path . '.from', $errors );
		$to   = self::normalize_baseline( $value['to'] ?? null, $path . '.to', $errors );
		$from_contract_result = StructureContract::normalize_definition( $value['from_contract'] ?? null, $path . '.from_contract' );
		$errors = array_merge( $errors, $from_contract_result['errors'] );
		$from_contract = $from_contract_result['valid'] ? $from_contract_result['value'] : array();
		if ( ! empty( $from ) && ! empty( $to ) ) {
			if ( $from === $to ) {
				$errors[] = self::issue( 'structure-migration-noop-version', 'A migration must advance the Blueprint or Structure Contract version.', $path );
			}
			if ( $to['blueprint_version'] < $from['blueprint_version'] ) {
				$errors[] = self::issue( 'structure-migration-blueprint-downgrade', 'A migration cannot lower the Blueprint version.', $path . '.to.blueprint_version' );
			}
			if ( ! empty( $from_contract ) && ( $from_contract['id'] !== $from['structure_contract']['id'] || $from_contract['version'] !== $from['structure_contract']['version'] ) ) {
				$errors[] = self::issue( 'structure-migration-source-contract-mismatch', 'The historical source contract must exactly match the from baseline.', $path . '.from_contract' );
			}
		}

		$raw_operations = $value['operations'] ?? null;
		$operations     = array();
		if ( ! is_array( $raw_operations ) || ! array_is_list( $raw_operations ) || empty( $raw_operations ) || count( $raw_operations ) > 100 ) {
			$errors[] = self::issue( 'structure-migration-operations-invalid', 'A migration requires between 1 and 100 ordered operations.', $path . '.operations' );
		} else {
			foreach ( $raw_operations as $index => $operation ) {
				$normalized = self::normalize_operation( $operation, $path . '.operations.' . $index );
				$errors     = array_merge( $errors, $normalized['errors'] );
				if ( $normalized['valid'] ) {
					$operations[] = $normalized['value'];
				}
			}
		}

		$raw_rules = $value['override_rules'] ?? array();
		$rules     = array();
		if ( ! is_array( $raw_rules ) || ! array_is_list( $raw_rules ) || count( $raw_rules ) > 200 ) {
			$errors[] = self::issue( 'structure-migration-override-rules-invalid', 'Override rebase rules must be a bounded list.', $path . '.override_rules' );
		} else {
			$seen = array();
			foreach ( $raw_rules as $index => $rule ) {
				$normalized = self::normalize_rule( $rule, $path . '.override_rules.' . $index );
				$errors     = array_merge( $errors, $normalized['errors'] );
				if ( $normalized['valid'] ) {
					$key = $normalized['value']['type'] . ':' . $normalized['value']['path'];
					if ( isset( $seen[ $key ] ) ) {
						$errors[] = self::issue( 'structure-migration-override-rule-duplicate', 'An override may have only one exact rebase rule.', $path . '.override_rules.' . $index );
					} else {
						$seen[ $key ] = true;
						$rules[]       = $normalized['value'];
					}
				}
			}
		}

		return self::result(
			empty( $errors ) ? array( 'id' => $id, 'blueprint' => $blueprint, 'from' => $from, 'to' => $to, 'from_contract' => $from_contract, 'operations' => $operations, 'override_rules' => $rules ) : array(),
			$errors
		);
	}

	private static function normalize_baseline( mixed $value, string $path, array &$errors ): array {
		if ( ! is_array( $value ) || array_is_list( $value ) || array_diff( array_keys( $value ), array( 'blueprint_version', 'structure_contract' ) ) ) {
			$errors[] = self::issue( 'structure-migration-baseline-invalid', 'A migration baseline requires only blueprint_version and structure_contract.', $path );
			return array();
		}
		$version = $value['blueprint_version'] ?? null;
		if ( ! is_int( $version ) || $version < 1 ) {
			$errors[] = self::issue( 'structure-migration-blueprint-version-invalid', 'A migration baseline requires a positive integer Blueprint version.', $path . '.blueprint_version' );
		}
		$reference = StructureContract::normalize_reference( $value['structure_contract'] ?? null, $path . '.structure_contract' );
		$errors    = array_merge( $errors, $reference['errors'] );
		return is_int( $version ) && $version > 0 && $reference['valid']
			? array( 'blueprint_version' => $version, 'structure_contract' => $reference['value'] )
			: array();
	}

	/** @return array{valid:bool,value:array,errors:list<array{code:string,message:string,path:string}>} */
	private static function normalize_operation( mixed $value, string $path ): array {
		if ( ! is_array( $value ) || array_is_list( $value ) ) {
			return self::result( array(), array( self::issue( 'structure-migration-operation-invalid', 'Every migration operation must be an object.', $path ) ) );
		}
		$type = is_string( $value['type'] ?? null ) ? strtolower( trim( $value['type'] ) ) : '';
		if ( ! in_array( $type, self::OPERATION_TYPES, true ) ) {
			return self::result( array(), array( self::issue( 'structure-migration-operation-type-invalid', 'The migration operation type is unsupported.', $path . '.type' ) ) );
		}
		$allowed = match ( $type ) {
			'move_section' => array( 'type', 'id', 'before', 'after' ),
			'add_section'  => array( 'type', 'id', 'pattern', 'fields', 'before', 'after' ),
			'add_field'    => array( 'type', 'id', 'default' ),
			'map_field'    => array( 'type', 'from', 'to' ),
		};
		$errors = array();
		if ( array_diff( array_keys( $value ), $allowed ) ) {
			$errors[] = self::issue( 'structure-migration-operation-property-unknown', 'A migration operation contains an unsupported property.', $path );
		}
		if ( 'map_field' === $type ) {
			$from = is_string( $value['from'] ?? null ) ? trim( $value['from'] ) : '';
			$to   = is_string( $value['to'] ?? null ) ? trim( $value['to'] ) : '';
			if ( ! self::valid_semantic_id( $from ) || ! self::valid_semantic_id( $to ) || $from === $to ) {
				$errors[] = self::issue( 'structure-migration-field-map-invalid', 'A field map requires distinct stable source and target semantic IDs.', $path );
			}
			return self::result( empty( $errors ) ? array( 'type' => $type, 'from' => $from, 'to' => $to ) : array(), $errors );
		}

		$id = is_string( $value['id'] ?? null ) ? trim( $value['id'] ) : '';
		if ( ! self::valid_semantic_id( $id ) ) {
			$errors[] = self::issue( 'structure-migration-node-id-invalid', 'A migration operation requires a stable semantic node ID.', $path . '.id' );
		}
		if ( 'add_field' === $type ) {
			if ( ! array_key_exists( 'default', $value ) ) {
				$errors[] = self::issue( 'structure-migration-field-default-missing', 'An added field requires an explicit JSON default, including null when no value should be written.', $path . '.default' );
			}
			$default = $value['default'] ?? null;
			if ( ! self::data_only( $default, 0 ) ) {
				$errors[] = self::issue( 'structure-migration-field-default-invalid', 'A field default must be bounded data-only JSON.', $path . '.default' );
			}
			return self::result( empty( $errors ) ? array( 'type' => $type, 'id' => $id, 'default' => $default ) : array(), $errors );
		}

		$before = is_string( $value['before'] ?? null ) ? trim( $value['before'] ) : '';
		$after  = is_string( $value['after'] ?? null ) ? trim( $value['after'] ) : '';
		if ( ( '' === $before ) === ( '' === $after ) || ( '' !== $before && ! self::valid_semantic_id( $before ) ) || ( '' !== $after && ! self::valid_semantic_id( $after ) ) || $id === $before || $id === $after ) {
			$errors[] = self::issue( 'structure-migration-position-invalid', 'A section operation requires exactly one different before or after semantic node ID.', $path );
		}
		$normalized = array( 'type' => $type, 'id' => $id );
		if ( '' !== $before ) {
			$normalized['before'] = $before;
		} else {
			$normalized['after'] = $after;
		}
		if ( 'add_section' === $type ) {
			$pattern = is_string( $value['pattern'] ?? null ) ? strtolower( trim( $value['pattern'] ) ) : '';
			$fields  = $value['fields'] ?? array();
			if ( 1 !== preg_match( '#^[a-z0-9-]+/[a-z0-9-]+$#', $pattern ) || ! is_array( $fields ) || array_is_list( $fields ) || count( $fields ) > 100 || ! self::data_only( $fields, 0 ) ) {
				$errors[] = self::issue( 'structure-migration-section-invalid', 'An added section requires one namespaced synced pattern and bounded field defaults.', $path );
			}
			$normalized['pattern'] = $pattern;
			$normalized['fields']  = $fields;
		}
		return self::result( empty( $errors ) ? $normalized : array(), $errors );
	}

	/** @return array{valid:bool,value:array,errors:list<array{code:string,message:string,path:string}>} */
	private static function normalize_rule( mixed $value, string $path ): array {
		if ( ! is_array( $value ) || array_is_list( $value ) || array_diff( array_keys( $value ), array( 'type', 'path', 'strategy', 'target_path' ) ) ) {
			return self::result( array(), array( self::issue( 'structure-migration-override-rule-invalid', 'Every override rebase rule must be a closed object.', $path ) ) );
		}
		$type        = is_string( $value['type'] ?? null ) ? strtoupper( trim( $value['type'] ) ) : '';
		$semantic    = is_string( $value['path'] ?? null ) ? trim( $value['path'] ) : '';
		$strategy    = is_string( $value['strategy'] ?? null ) ? strtolower( trim( $value['strategy'] ) ) : '';
		$target_path = is_string( $value['target_path'] ?? null ) ? trim( $value['target_path'] ) : '';
		$errors      = array();
		if ( ! in_array( $type, self::OVERRIDE_TYPES, true ) || ! self::valid_semantic_id( $semantic ) ) {
			$errors[] = self::issue( 'structure-migration-override-rule-invalid', 'An override rule requires a supported type and stable semantic path.', $path );
		}
		$allowed = match ( $type ) {
			'CONTENT', 'VISIBILITY' => array( 'preserve', 'map' ),
			'ORDER'                 => array( 'preserve_current', 'accept_target' ),
			'STRUCTURE'             => array( 'accept_target' ),
			'DETACHED'              => array(),
			default                 => array(),
		};
		if ( ! in_array( $strategy, $allowed, true ) ) {
			$errors[] = self::issue( 'structure-migration-override-strategy-invalid', 'The override type does not support the requested automatic rebase strategy.', $path . '.strategy' );
		}
		if ( 'map' === $strategy ) {
			if ( ! self::valid_semantic_id( $target_path ) || $target_path === $semantic ) {
				$errors[] = self::issue( 'structure-migration-override-target-invalid', 'A mapped override requires a distinct stable target_path.', $path . '.target_path' );
			}
		} elseif ( '' !== $target_path ) {
			$errors[] = self::issue( 'structure-migration-override-target-unexpected', 'target_path is valid only for the map strategy.', $path . '.target_path' );
		}
		$normalized = array( 'type' => $type, 'path' => $semantic, 'strategy' => $strategy );
		if ( '' !== $target_path ) {
			$normalized['target_path'] = $target_path;
		}
		return self::result( empty( $errors ) ? $normalized : array(), $errors );
	}

	private static function data_only( mixed $value, int $depth ): bool {
		if ( $depth > 8 ) {
			return false;
		}
		if ( null === $value || is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
			return true;
		}
		if ( is_string( $value ) ) {
			return strlen( $value ) <= 20000;
		}
		if ( ! is_array( $value ) || count( $value ) > 100 ) {
			return false;
		}
		foreach ( $value as $item ) {
			if ( ! self::data_only( $item, $depth + 1 ) ) {
				return false;
			}
		}
		return true;
	}

	private static function valid_slug( string $value ): bool {
		return 1 === preg_match( '/^[a-z][a-z0-9-]{0,63}$/', $value );
	}

	private static function valid_semantic_id( string $value ): bool {
		return 1 === preg_match( '/^[a-z][a-z0-9._-]{0,127}$/', $value );
	}

	private static function issue( string $code, string $message, string $path ): array {
		return array( 'code' => $code, 'message' => $message, 'path' => $path );
	}

	private static function result( array $value, array $errors ): array {
		return array( 'valid' => empty( $errors ), 'value' => $value, 'errors' => array_values( $errors ) );
	}
}
