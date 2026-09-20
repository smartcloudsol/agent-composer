<?php

namespace SmartCloud\AgentComposer\Domain\Structure;

/**
 * Pure normalization and validation for versioned Gutenberg structure contracts.
 *
 * The class intentionally has no WordPress dependencies so configuration-time
 * and execution-time callers use exactly the same deterministic rules.
 */
final class StructureContract {
	private const OWNERSHIP_MODES = array( 'BLUEPRINT', 'INSTANCE_CONTENT', 'USER' );
	private const EDITOR_MODES    = array( 'structure', 'content', 'slot', 'free' );

	/**
	 * @return array{valid:bool,value:array<string,array>,errors:list<array{code:string,message:string,path:string}>}
	 */
	public static function normalize_registry( mixed $value ): array {
		$errors    = array();
		$contracts = array();
		if ( null === $value ) {
			$value = array();
		}
		if ( ! is_array( $value ) || ( ! empty( $value ) && array_is_list( $value ) ) ) {
			return self::result(
				array(),
				array( self::issue( 'structure-contract-registry-invalid', 'Structure Contracts must be an object keyed by stable contract ID.', 'design_policy.structure_contracts' ) )
			);
		}

		foreach ( $value as $key => $definition ) {
			$path       = 'design_policy.structure_contracts.' . (string) $key;
			$normalized = self::normalize_definition( $definition, $path );
			$errors     = array_merge( $errors, $normalized['errors'] );
			if ( ! $normalized['valid'] ) {
				continue;
			}
			$contract = $normalized['value'];
			if ( (string) $key !== $contract['id'] ) {
				$errors[] = self::issue( 'structure-contract-key-mismatch', 'The Structure Contract registry key must match the contract ID.', $path . '.id' );
				continue;
			}
			$contracts[ $contract['id'] ] = $contract;
		}

		ksort( $contracts );
		return self::result( $contracts, $errors );
	}

	/**
	 * @return array{valid:bool,value:array{id:string,version:int}|array{},errors:list<array{code:string,message:string,path:string}>}
	 */
	public static function normalize_reference( mixed $value, string $path = 'structure_contract' ): array {
		$errors = array();
		if ( ! is_array( $value ) || array_is_list( $value ) ) {
			return self::result(
				array(),
				array( self::issue( 'structure-contract-reference-invalid', 'A Structure Contract reference must be an object containing only id and version.', $path ) )
			);
		}
		if ( array_diff( array_keys( $value ), array( 'id', 'version' ) ) ) {
			$errors[] = self::issue( 'structure-contract-reference-property-unknown', 'A Structure Contract reference may contain only id and version.', $path );
		}

		$id      = is_string( $value['id'] ?? null ) ? trim( $value['id'] ) : '';
		$version = $value['version'] ?? null;
		if ( ! self::valid_id( $id ) ) {
			$errors[] = self::issue( 'structure-contract-id-invalid', 'Structure Contract IDs must be stable lowercase slugs.', $path . '.id' );
		}
		if ( ! is_int( $version ) || $version < 1 ) {
			$errors[] = self::issue( 'structure-contract-version-invalid', 'Structure Contract versions must be positive integers.', $path . '.version' );
		}

		return self::result(
			empty( $errors ) ? array( 'id' => $id, 'version' => $version ) : array(),
			$errors
		);
	}

	/**
	 * @return array{valid:bool,value:array,errors:list<array{code:string,message:string,path:string}>}
	 */
	public static function normalize_definition( mixed $value, string $path = 'structure_contract' ): array {
		$errors = array();
		if ( ! is_array( $value ) || array_is_list( $value ) ) {
			return self::result(
				array(),
				array( self::issue( 'structure-contract-invalid', 'A Structure Contract must be an object.', $path ) )
			);
		}
		if ( array_diff( array_keys( $value ), array( 'id', 'version', 'label', 'nodes' ) ) ) {
			$errors[] = self::issue( 'structure-contract-property-unknown', 'A Structure Contract contains an unknown top-level property.', $path );
		}

		$reference = self::normalize_reference(
			array(
				'id'      => $value['id'] ?? null,
				'version' => $value['version'] ?? null,
			),
			$path
		);
		$errors    = array_merge( $errors, $reference['errors'] );
		$label     = isset( $value['label'] ) && is_string( $value['label'] ) ? trim( $value['label'] ) : '';
		if ( strlen( $label ) > 200 ) {
			$errors[] = self::issue( 'structure-contract-label-too-long', 'A Structure Contract label cannot exceed 200 bytes.', $path . '.label' );
		}

		$raw_nodes = $value['nodes'] ?? null;
		if ( ! is_array( $raw_nodes ) || ! array_is_list( $raw_nodes ) || empty( $raw_nodes ) ) {
			$errors[] = self::issue( 'structure-contract-nodes-invalid', 'A Structure Contract must contain a non-empty nodes list.', $path . '.nodes' );
			$raw_nodes = array();
		}

		$nodes    = array();
		$node_ids = array();
		foreach ( $raw_nodes as $index => $raw_node ) {
			$node_path  = $path . '.nodes.' . $index;
			$normalized = self::normalize_node( $raw_node, $node_path );
			$errors     = array_merge( $errors, $normalized['errors'] );
			if ( ! $normalized['valid'] ) {
				continue;
			}
			$node = $normalized['value'];
			if ( isset( $node_ids[ $node['id'] ] ) ) {
				$errors[] = self::issue( 'structure-contract-node-duplicate', 'Semantic node IDs must be unique within a Structure Contract.', $node_path . '.id' );
				continue;
			}
			$node_ids[ $node['id'] ] = true;
			$nodes[]                  = $node;
		}

		$node_modes = array_column( $nodes, 'mode', 'id' );
		$positions  = array();
		foreach ( $nodes as $index => $node ) {
			$node_path = $path . '.nodes.' . $index;
			$parent    = $node['parent'];
			if ( null !== $parent && ! isset( $node_ids[ $parent ] ) ) {
				$errors[] = self::issue( 'structure-contract-parent-missing', 'Every semantic parent must resolve to another node in the same Structure Contract.', $node_path . '.parent' );
			}
			if ( $node['id'] === $parent ) {
				$errors[] = self::issue( 'structure-contract-parent-cycle', 'A semantic node cannot be its own parent.', $node_path . '.parent' );
			}
			if ( 'free' === $node['mode'] && ( null === $parent || 'slot' !== ( $node_modes[ $parent ] ?? '' ) ) ) {
				$errors[] = self::issue( 'structure-contract-free-parent-invalid', 'A canonical USER-owned free node must belong directly to an extension slot.', $node_path . '.parent' );
			}
			if ( is_int( $node['position'] ) ) {
				$position_key = ( null === $parent ? '__root__' : $parent ) . ':' . $node['position'];
				if ( isset( $positions[ $position_key ] ) ) {
					$errors[] = self::issue( 'structure-contract-position-duplicate', 'Sibling semantic nodes cannot share the same canonical position.', $node_path . '.position' );
				}
				$positions[ $position_key ] = true;
			}
		}
		$errors = array_merge( $errors, self::cycle_errors( $nodes, $path ) );

		if ( ! $reference['valid'] ) {
			return self::result( array(), $errors );
		}
		return self::result(
			array(
				'id'      => $reference['value']['id'],
				'version' => $reference['value']['version'],
				'label'   => $label,
				'nodes'   => $nodes,
			),
			$errors
		);
	}

	/**
	 * @return array{valid:bool,value:array,errors:list<array{code:string,message:string,path:string}>}
	 */
	private static function normalize_node( mixed $value, string $path ): array {
		$errors = array();
		if ( ! is_array( $value ) || array_is_list( $value ) ) {
			return self::result( array(), array( self::issue( 'structure-contract-node-invalid', 'Every Structure Contract node must be an object.', $path ) ) );
		}
		$allowed_keys = array(
			'id', 'block', 'ownership', 'mode', 'parent', 'position', 'required',
			'editable_attributes', 'protected_attributes', 'editable_content',
			'allowed_blocks', 'min_blocks', 'max_blocks', 'allow_cross_slot_move',
		);
		if ( array_diff( array_keys( $value ), $allowed_keys ) ) {
			$errors[] = self::issue( 'structure-contract-node-property-unknown', 'A Structure Contract node contains an unknown property.', $path );
		}

		$id        = is_string( $value['id'] ?? null ) ? trim( $value['id'] ) : '';
		$block     = is_string( $value['block'] ?? null ) ? strtolower( trim( $value['block'] ) ) : '';
		$ownership = is_string( $value['ownership'] ?? null ) ? strtoupper( trim( $value['ownership'] ) ) : '';
		$mode      = is_string( $value['mode'] ?? null ) ? strtolower( trim( $value['mode'] ) ) : '';
		$parent    = $value['parent'] ?? null;
		$position  = $value['position'] ?? null;

		if ( ! self::valid_semantic_id( $id ) ) {
			$errors[] = self::issue( 'structure-contract-node-id-invalid', 'Semantic node IDs must be readable stable identifiers and must not be derived from array positions.', $path . '.id' );
		}
		if ( ! preg_match( '#^[a-z0-9-]+/[a-z0-9-]+$#', $block ) ) {
			$errors[] = self::issue( 'structure-contract-block-invalid', 'Every semantic node must declare one valid Gutenberg block name.', $path . '.block' );
		}
		if ( ! in_array( $ownership, self::OWNERSHIP_MODES, true ) ) {
			$errors[] = self::issue( 'structure-contract-ownership-invalid', 'Node ownership must be BLUEPRINT, INSTANCE_CONTENT, or USER.', $path . '.ownership' );
		}
		if ( ! in_array( $mode, self::EDITOR_MODES, true ) ) {
			$errors[] = self::issue( 'structure-contract-mode-invalid', 'Node mode must be structure, content, slot, or free.', $path . '.mode' );
		}
		if ( null !== $parent && ( ! is_string( $parent ) || ! self::valid_semantic_id( $parent ) ) ) {
			$errors[] = self::issue( 'structure-contract-parent-invalid', 'A semantic parent must be null or another stable semantic node ID.', $path . '.parent' );
		}
		if ( null !== $position && ( ! is_int( $position ) || $position < 0 ) ) {
			$errors[] = self::issue( 'structure-contract-position-invalid', 'A canonical node position must be a non-negative integer.', $path . '.position' );
		}

		$editable  = self::string_list( $value['editable_attributes'] ?? array(), $path . '.editable_attributes', $errors, 'structure-contract-editable-attributes-invalid' );
		$protected = self::string_list( $value['protected_attributes'] ?? array(), $path . '.protected_attributes', $errors, 'structure-contract-protected-attributes-invalid' );
		$reserved  = array( 'metadata', 'composer', 'lock', 'templateLock', 'slotId', 'allowedBlocks', 'minBlocks', 'maxBlocks' );
		if ( array_intersect( $editable, $reserved ) ) {
			$errors[] = self::issue( 'structure-contract-editable-attribute-reserved', 'Composer identity, lock, and extension-slot control attributes cannot be declared editable.', $path . '.editable_attributes' );
		}
		if ( array_intersect( $editable, $protected ) ) {
			$errors[] = self::issue( 'structure-contract-attribute-policy-conflict', 'A block attribute cannot be both editable and protected.', $path );
		}

		$required         = $value['required'] ?? true;
		$editable_content = $value['editable_content'] ?? ( 'content' === $mode || 'free' === $mode );
		if ( ! is_bool( $required ) ) {
			$errors[] = self::issue( 'structure-contract-required-invalid', 'The required flag must be a boolean.', $path . '.required' );
		}
		if ( ! is_bool( $editable_content ) ) {
			$errors[] = self::issue( 'structure-contract-editable-content-invalid', 'The editable_content flag must be a boolean.', $path . '.editable_content' );
		}

		$allowed_blocks = self::block_list( $value['allowed_blocks'] ?? array(), $path . '.allowed_blocks', $errors );
		$min_blocks     = $value['min_blocks'] ?? 0;
		$max_blocks     = $value['max_blocks'] ?? null;
		$cross_slot     = $value['allow_cross_slot_move'] ?? false;
		if ( 'slot' === $mode ) {
			if ( 'BLUEPRINT' !== $ownership ) {
				$errors[] = self::issue( 'structure-contract-slot-ownership-invalid', 'An extension slot is BLUEPRINT-owned; only its child blocks are USER-owned.', $path . '.ownership' );
			}
			if ( empty( $allowed_blocks ) ) {
				$errors[] = self::issue( 'structure-contract-slot-blocks-missing', 'An extension slot must allow at least one explicit block type.', $path . '.allowed_blocks' );
			}
			if ( ! is_int( $min_blocks ) || $min_blocks < 0 ) {
				$errors[] = self::issue( 'structure-contract-slot-min-invalid', 'Extension-slot min_blocks must be a non-negative integer.', $path . '.min_blocks' );
			}
			if ( null !== $max_blocks && ( ! is_int( $max_blocks ) || $max_blocks < 0 ) ) {
				$errors[] = self::issue( 'structure-contract-slot-max-invalid', 'Extension-slot max_blocks must be null or a non-negative integer.', $path . '.max_blocks' );
			}
			if ( is_int( $min_blocks ) && is_int( $max_blocks ) && $min_blocks > $max_blocks ) {
				$errors[] = self::issue( 'structure-contract-slot-cardinality-invalid', 'Extension-slot min_blocks cannot exceed max_blocks.', $path );
			}
			if ( true === $cross_slot ) {
				$errors[] = self::issue( 'structure-contract-cross-slot-move-unsupported', 'Cross-slot movement is not supported by the initial protected editing contract.', $path . '.allow_cross_slot_move' );
			} elseif ( false !== $cross_slot ) {
				$errors[] = self::issue( 'structure-contract-cross-slot-move-invalid', 'allow_cross_slot_move must be a boolean.', $path . '.allow_cross_slot_move' );
			}
		} elseif ( ! empty( $allowed_blocks ) || array_key_exists( 'min_blocks', $value ) || array_key_exists( 'max_blocks', $value ) || array_key_exists( 'allow_cross_slot_move', $value ) ) {
			$errors[] = self::issue( 'structure-contract-slot-policy-outside-slot', 'Block allowlists and slot cardinality may be declared only by slot nodes.', $path );
		}

		$expected_ownership = match ( $mode ) {
			'structure', 'slot' => 'BLUEPRINT',
			'content'           => 'INSTANCE_CONTENT',
			'free'              => 'USER',
			default             => '',
		};
		if ( '' !== $expected_ownership && $expected_ownership !== $ownership ) {
			$errors[] = self::issue( 'structure-contract-mode-ownership-conflict', 'Node ownership does not match its editor mode.', $path . '.ownership' );
		}

		if ( ! empty( $errors ) ) {
			return self::result( array(), $errors );
		}
		$normalized = array(
			'id'                   => $id,
			'block'                => $block,
			'ownership'            => $ownership,
			'mode'                 => $mode,
			'parent'               => $parent,
			'position'             => $position,
			'required'             => $required,
			'editable_attributes'  => $editable,
			'protected_attributes' => $protected,
			'editable_content'     => $editable_content,
		);
		if ( 'slot' === $mode ) {
			$normalized['allowed_blocks']        = $allowed_blocks;
			$normalized['min_blocks']            = $min_blocks;
			$normalized['max_blocks']            = $max_blocks;
			$normalized['allow_cross_slot_move'] = false;
		}
		return self::result( $normalized, array() );
	}

	private static function valid_id( string $value ): bool {
		return 1 === preg_match( '/^[a-z][a-z0-9-]{0,63}$/', $value );
	}

	private static function valid_semantic_id( string $value ): bool {
		return 1 === preg_match( '/^[a-z][a-z0-9._-]{0,127}$/', $value )
			&& 1 !== preg_match( '/(?:^|[._-])(?:block|group|section)-?[0-9]+(?:$|[._-])/', $value );
	}

	private static function string_list( mixed $value, string $path, array &$errors, string $code ): array {
		if ( ! is_array( $value ) || ! array_is_list( $value ) ) {
			$errors[] = self::issue( $code, 'The value must be a list of unique block attribute names.', $path );
			return array();
		}
		$result = array();
		foreach ( $value as $index => $item ) {
			if ( ! is_string( $item ) || 1 !== preg_match( '/^[A-Za-z][A-Za-z0-9_-]{0,127}$/', $item ) ) {
				$errors[] = self::issue( $code, 'Every block attribute name must be syntactically valid.', $path . '.' . $index );
				continue;
			}
			$result[] = $item;
		}
		return array_values( array_unique( $result ) );
	}

	private static function block_list( mixed $value, string $path, array &$errors ): array {
		if ( ! is_array( $value ) || ! array_is_list( $value ) ) {
			$errors[] = self::issue( 'structure-contract-slot-blocks-invalid', 'allowed_blocks must be a list of Gutenberg block names.', $path );
			return array();
		}
		$result = array();
		foreach ( $value as $index => $item ) {
			$item = is_string( $item ) ? strtolower( trim( $item ) ) : '';
			if ( 1 !== preg_match( '#^[a-z0-9-]+/[a-z0-9-]+$#', $item ) ) {
				$errors[] = self::issue( 'structure-contract-slot-block-invalid', 'Every allowed slot block must be a valid Gutenberg block name.', $path . '.' . $index );
				continue;
			}
			$result[] = $item;
		}
		return array_values( array_unique( $result ) );
	}

	private static function cycle_errors( array $nodes, string $path ): array {
		$parents = array();
		foreach ( $nodes as $node ) {
			$parents[ $node['id'] ] = $node['parent'];
		}
		$errors = array();
		foreach ( array_keys( $parents ) as $id ) {
			$seen    = array();
			$current = $id;
			while ( null !== $current && isset( $parents[ $current ] ) ) {
				if ( isset( $seen[ $current ] ) ) {
					$errors[] = self::issue( 'structure-contract-parent-cycle', 'Semantic node parents must form an acyclic tree.', $path . '.nodes' );
					break;
				}
				$seen[ $current ] = true;
				$current          = $parents[ $current ];
			}
		}
		return array_values( array_unique( $errors, SORT_REGULAR ) );
	}

	private static function issue( string $code, string $message, string $path ): array {
		return array( 'code' => $code, 'message' => $message, 'path' => $path );
	}

	private static function result( array $value, array $errors ): array {
		return array( 'valid' => empty( $errors ), 'value' => $value, 'errors' => array_values( $errors ) );
	}

	private function __construct() {}
}
