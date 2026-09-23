<?php

namespace SmartCloud\AgentComposer\Domain\Structure;

/**
 * Deterministic Structure Contract validation for parsed Gutenberg documents.
 *
 * This class deliberately has no WordPress dependencies. Callers provide the
 * previous and proposed parse_blocks() results so every transport can share the
 * same structural enforcement boundary.
 */
final class StructureDocumentValidator {
	/**
	 * @param array      $contract             Normalized Structure Contract.
	 * @param array      $proposed_blocks       Proposed Gutenberg AST.
	 * @param array|null $previous_blocks       Persisted Gutenberg AST, when updating.
	 * @param bool       $allow_structure_change Reserved for privileged migrations.
	 * @return array{valid:bool,errors:list<array>,operations:list<array>,contract:array{id:string,version:int},contract_hash:string,structural_fingerprint:string}
	 */
	public function validate(
		array $contract,
		array $proposed_blocks,
		?array $previous_blocks = null,
		bool $allow_structure_change = false
	): array {
		$nodes = array();
		foreach ( (array) ( $contract['nodes'] ?? array() ) as $node ) {
			if ( is_array( $node ) && isset( $node['id'] ) ) {
				$nodes[ (string) $node['id'] ] = $node;
			}
		}

		$proposed = $this->inspect( $proposed_blocks, $nodes );
		$previous = null === $previous_blocks ? null : $this->inspect( $previous_blocks, $nodes );
		$errors   = $proposed['errors'];

		foreach ( $nodes as $id => $node ) {
			$matches = $proposed['semantic'][ $id ] ?? array();
			if ( count( $matches ) > 1 ) {
				$instance_ids = array_values( array_filter( array_column( $matches, 'pattern_instance_id' ) ) );
				if ( count( $instance_ids ) !== count( $matches ) || count( array_unique( $instance_ids ) ) !== count( $matches ) ) {
					$errors[] = $this->violation( $id, 'duplicate', 'semantic-node-duplicate', 'A Structure Contract node may occur only once outside distinct synced pattern instances.' );
					continue;
				}
			}
			if ( empty( $matches ) ) {
				if ( ! empty( $node['required'] ) ) {
					$errors[] = $this->violation( $id, 'remove', 'required-node-missing', 'A required contract node is missing.' );
				}
				continue;
			}

			foreach ( $matches as $actual ) {
				$path = '' !== (string) ( $actual['pattern_instance_id'] ?? '' ) ? (string) $actual['pattern_instance_id'] . '.' . $id : $id;
				if ( (string) ( $node['block'] ?? '' ) !== $actual['block'] ) {
					$errors[] = $this->violation( $path, 'change_block_type', 'block-type-mismatch', 'The semantic node uses a block type that is not allowed by its Structure Contract.', array( 'expected' => $node['block'], 'found' => $actual['block'] ) );
				}
				if ( ( $node['parent'] ?? null ) !== $actual['parent'] ) {
					$errors[] = $this->violation( $path, 'reparent_structure', 'parent-mismatch', 'The semantic node is not attached to its declared parent.', array( 'expected' => $node['parent'] ?? null, 'found' => $actual['parent'] ) );
				}
			}
		}

		$errors = array_merge( $errors, $this->validate_canonical_order( $nodes, $proposed['semantic'] ) );
		$errors = array_merge( $errors, $this->validate_slots( $nodes, $proposed ) );

		if ( null !== $previous && ! $allow_structure_change ) {
			$errors = array_merge( $errors, $this->compare_documents( $nodes, $previous, $proposed ) );
		}

		$errors = $this->unique_violations( $errors );
		return array(
			'valid'      => empty( $errors ),
			'errors'     => $errors,
			'operations' => array_map(
				static fn( array $error ): array => array(
					'operation' => (string) ( $error['operation'] ?? '' ),
					'path'      => (string) ( $error['path'] ?? '' ),
					'rule'      => (string) ( $error['context']['rule'] ?? '' ),
				),
				$errors
			),
			'contract'   => array(
				'id'      => (string) ( $contract['id'] ?? '' ),
				'version' => (int) ( $contract['version'] ?? 0 ),
			),
			'contract_hash'         => $this->checksum( $contract ),
			'structural_fingerprint' => $this->fingerprint_from_inspection( $nodes, $proposed ),
		);
	}

	/** Return a content-insensitive structural fingerprint for a parsed document. */
	public function structural_fingerprint( array $contract, array $blocks ): string {
		$nodes = array();
		foreach ( (array) ( $contract['nodes'] ?? array() ) as $node ) {
			if ( is_array( $node ) && isset( $node['id'] ) ) {
				$nodes[ (string) $node['id'] ] = $node;
			}
		}
		return $this->fingerprint_from_inspection( $nodes, $this->inspect( $blocks, $nodes ) );
	}

	private function inspect( array $blocks, array $contract_nodes ): array {
		$result = array(
			'semantic' => array(),
			'slots'    => array(),
			'errors'   => array(),
		);
		$this->inspect_children( $blocks, $contract_nodes, null, null, 0, array(), $result );
		return $result;
	}

	private function inspect_children(
		array $blocks,
		array $contract_nodes,
		?string $semantic_parent,
		?string $slot_id,
		int $slot_depth,
		array $path,
		array &$result
	): void {
		$block_position = 0;
		foreach ( $blocks as $raw_index => $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			$name = $block['blockName'] ?? null;
			if ( null === $name ) {
				if ( '' !== trim( (string) ( $block['innerHTML'] ?? '' ) ) ) {
					$result['errors'][] = $this->violation( $this->display_path( $slot_id, array_merge( $path, array( $raw_index ) ) ), 'insert_structure', 'unwrapped-content', 'Unwrapped content is not part of the declared Structure Contract.' );
				}
				continue;
			}

			$name       = strtolower( trim( (string) $name ) );
			$block_path = array_merge( $path, array( $block_position ) );
			++$block_position;
			$identity = $this->semantic_identity( $block, $contract_nodes );
			if ( $identity['conflict'] ) {
				$result['errors'][] = $this->violation( $this->display_path( $slot_id, $block_path ), 'change_structure_attribute', 'semantic-identity-conflict', 'The block contains conflicting Composer semantic identities.' );
			}
			$id = $identity['id'];

			$current_slot = $slot_id;
			if ( null !== $id ) {
				$contract_node = $contract_nodes[ $id ];
				$entry = array(
					'id'               => $id,
					'block'            => $name,
					'parent'           => $semantic_parent,
					'slot'             => $slot_id,
					'path'             => $block_path,
					'sibling_position' => $block_position - 1,
					'attrs'             => isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array(),
					'inner_html'        => (string) ( $block['innerHTML'] ?? '' ),
					'inner_content'     => $this->structural_inner_content( $block['innerContent'] ?? array() ),
					'pattern_instance_id' => $this->pattern_instance_id( $block ),
				);
				$result['semantic'][ $id ][] = $entry;
				if ( 'slot' === (string) ( $contract_node['mode'] ?? '' ) ) {
					$current_slot = $id;
					$result['slots'][ $id ] ??= array( 'direct' => array(), 'all' => array() );
				} elseif (
					null !== $slot_id
					&& (
						'USER' === (string) ( $contract_node['ownership'] ?? '' )
						|| '' !== (string) $entry['pattern_instance_id']
					)
				) {
					$result['slots'][ $slot_id ] ??= array( 'direct' => array(), 'all' => array() );
					$result['slots'][ $slot_id ]['all'][] = $this->user_entry( $block, $name, $block_path );
					if ( 1 === $slot_depth ) {
						$result['slots'][ $slot_id ]['direct'][] = $this->user_entry( $block, $name, $block_path );
					}
				}
				$next_parent = $id;
			} elseif ( null !== $slot_id ) {
				$result['slots'][ $slot_id ] ??= array( 'direct' => array(), 'all' => array() );
				$result['slots'][ $slot_id ]['all'][] = $this->user_entry( $block, $name, $block_path );
				if ( 1 === $slot_depth ) {
					$result['slots'][ $slot_id ]['direct'][] = $this->user_entry( $block, $name, $block_path );
				}
				$next_parent = $semantic_parent;
			} else {
				$result['errors'][] = $this->violation( $this->display_path( null, $block_path ), 'insert_structure', 'undeclared-structural-node', 'Every block outside an extension slot must have a declared stable semantic identity.', array( 'block' => $name ) );
				$next_parent = $semantic_parent;
			}

			$children = isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ? $block['innerBlocks'] : array();
			$next_slot_depth = null === $current_slot ? 0 : ( $current_slot === $slot_id ? $slot_depth + 1 : 1 );
			$this->inspect_children( $children, $contract_nodes, $next_parent, $current_slot, $next_slot_depth, $block_path, $result );
		}
	}

	private function semantic_identity( array $block, array $contract_nodes ): array {
		$attrs      = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
		$metadata   = isset( $attrs['metadata'] ) && is_array( $attrs['metadata'] ) ? $attrs['metadata'] : array();
		$composer   = isset( $attrs['composer'] ) && is_array( $attrs['composer'] ) ? $attrs['composer'] : array();
		$namespaced = isset( $metadata['wpsuiteAgentComposer'] ) && is_array( $metadata['wpsuiteAgentComposer'] ) ? $metadata['wpsuiteAgentComposer'] : array();
		$candidates = array_filter(
			array(
				is_string( $composer['nodeId'] ?? null ) ? trim( $composer['nodeId'] ) : '',
				is_string( $namespaced['nodeId'] ?? null ) ? trim( $namespaced['nodeId'] ) : '',
				is_string( $metadata['name'] ?? null ) && isset( $contract_nodes[ trim( $metadata['name'] ) ] ) ? trim( $metadata['name'] ) : '',
			),
			static fn( string $value ): bool => '' !== $value
		);
		$candidates = array_values( array_unique( $candidates ) );
		$known      = array_values( array_filter( $candidates, static fn( string $id ): bool => isset( $contract_nodes[ $id ] ) ) );
		return array(
			'id'       => 1 === count( $known ) ? $known[0] : null,
			'conflict' => count( $candidates ) > 1 || ( ! empty( $candidates ) && empty( $known ) ),
		);
	}

	private function validate_canonical_order( array $nodes, array $actual_nodes ): array {
		$errors   = array();
		$families = array();
		foreach ( $nodes as $id => $node ) {
			if ( is_int( $node['position'] ?? null ) ) {
				$parent = null === ( $node['parent'] ?? null ) ? '__root__' : (string) $node['parent'];
				$families[ $parent ][ $id ] = (int) $node['position'];
			}
		}
		foreach ( $families as $expected ) {
			$scopes = array( '' );
			foreach ( array_keys( $expected ) as $id ) {
				foreach ( (array) ( $actual_nodes[ $id ] ?? array() ) as $actual ) {
					$scope = trim( (string) ( $actual['pattern_instance_id'] ?? '' ) );
					if ( '' !== $scope ) {
						$scopes[] = $scope;
					}
				}
			}
			foreach ( array_values( array_unique( $scopes ) ) as $scope ) {
				$present = array();
				foreach ( $expected as $id => $position ) {
					$matches = array_values(
						array_filter(
							(array) ( $actual_nodes[ $id ] ?? array() ),
							static fn( array $actual ): bool => $scope === trim( (string) ( $actual['pattern_instance_id'] ?? '' ) )
						)
					);
					if ( 1 === count( $matches ) ) {
						$present[] = array( 'id' => $id, 'expected' => $position, 'actual' => $matches[0]['sibling_position'] );
					}
				}
				$by_actual = $present;
				usort( $by_actual, static fn( array $left, array $right ): int => $left['actual'] <=> $right['actual'] );
				$by_expected = $present;
				usort( $by_expected, static fn( array $left, array $right ): int => $left['expected'] <=> $right['expected'] );
				if ( array_column( $by_actual, 'id' ) !== array_column( $by_expected, 'id' ) ) {
					foreach ( array_column( $by_actual, 'id' ) as $id ) {
						$path = '' === $scope ? $id : $scope . '.' . $id;
						$errors[] = $this->violation( $path, 'move_structure', 'canonical-order-mismatch', 'Blueprint-owned semantic nodes must retain their canonical relative order.' );
					}
				}
			}
		}
		return $errors;
	}

	private function validate_slots( array $nodes, array $document ): array {
		$errors   = array();
		$user_ids = array();
		foreach ( $nodes as $id => $node ) {
			if ( 'slot' !== (string) ( $node['mode'] ?? '' ) ) {
				continue;
			}
			$slot   = $document['slots'][ $id ] ?? array( 'direct' => array(), 'all' => array() );
			$direct = (array) $slot['direct'];
			$count  = count( $direct );
			$minimum = (int) ( $node['min_blocks'] ?? 0 );
			$maximum = $node['max_blocks'] ?? null;
			if ( $count < $minimum || ( is_int( $maximum ) && $count > $maximum ) ) {
				$errors[] = $this->violation( $id, 'update_slot', 'slot-cardinality', 'Extension-slot cardinality is outside the declared bounds.', array( 'found' => $count, 'minimum' => $minimum, 'maximum' => $maximum ) );
			}
			$allowed = (array) ( $node['allowed_blocks'] ?? array() );
			foreach ( (array) $slot['all'] as $entry ) {
				if ( '' === (string) ( $entry['pattern_instance_id'] ?? '' ) && ! in_array( $entry['block'], $allowed, true ) ) {
					$errors[] = $this->violation( $id . '.blocks.' . implode( '.', $entry['path'] ), 'insert_user_block', 'slot-block-not-allowed', 'The extension slot contains a block type that is not allow-listed.', array( 'block' => $entry['block'], 'slot' => $id ) );
				}
				$user_id = (string) ( $entry['user_id'] ?? '' );
				if ( '' !== $user_id && 1 !== preg_match( '/^user-[a-z0-9-]{8,58}$/', $user_id ) ) {
					$errors[] = $this->violation( $id . '.blocks.' . implode( '.', $entry['path'] ), 'update_user_block', 'user-block-id-invalid', 'A user-owned block contains an invalid stable identity.', array( 'userBlockId' => $user_id ) );
				}
				if ( '' !== $user_id ) {
					$user_ids[ $user_id ][] = array( 'slot' => $id, 'path' => $entry['path'] );
				}
				$declared_slot = (string) ( $entry['declared_slot'] ?? '' );
				if ( '' !== $declared_slot && $id !== $declared_slot ) {
					$errors[] = $this->violation( $id . '.blocks.' . implode( '.', $entry['path'] ), 'move_user_block', 'user-block-slot-mismatch', 'A user-owned block identity names a different extension slot than its physical parent.', array( 'declared' => $declared_slot, 'found' => $id ) );
				}
			}
		}
		foreach ( $user_ids as $user_id => $locations ) {
			if ( count( $locations ) > 1 ) {
				$errors[] = $this->violation( (string) $locations[0]['slot'], 'insert_user_block', 'user-block-id-duplicate', 'A stable user-block identity may occur only once in a managed document.', array( 'userBlockId' => $user_id, 'occurrences' => count( $locations ) ) );
			}
		}
		return $errors;
	}

	private function compare_documents( array $nodes, array $previous, array $proposed ): array {
		$errors = array();
		foreach ( $nodes as $id => $node ) {
			$before_by_scope = $this->matches_by_scope( (array) ( $previous['semantic'][ $id ] ?? array() ) );
			$after_by_scope  = $this->matches_by_scope( (array) ( $proposed['semantic'][ $id ] ?? array() ) );
			foreach ( array_unique( array_merge( array_keys( $before_by_scope ), array_keys( $after_by_scope ) ) ) as $scope ) {
				$before = $before_by_scope[ $scope ] ?? null;
				$after  = $after_by_scope[ $scope ] ?? null;
				$path   = '' === $scope ? $id : $scope . '.' . $id;
				$user_owned = 'USER' === (string) ( $node['ownership'] ?? '' );
				$pattern_slot_item = '' !== $scope && null !== ( $before['slot'] ?? $after['slot'] ?? null );
				if ( null !== $before && null === $after && ! $user_owned ) {
					if ( $pattern_slot_item ) {
						continue;
					}
					$errors[] = $this->violation( $path, 'remove_structure', 'protected-node-removed', 'A Blueprint-owned block cannot be removed.' );
					continue;
				}
				if ( null === $before && null !== $after && ! $user_owned ) {
					if ( $pattern_slot_item ) {
						continue;
					}
					$errors[] = $this->violation( $path, 'insert_structure', 'protected-node-inserted', 'A Blueprint-owned block cannot be inserted by an ordinary content operation.' );
					continue;
				}
				if ( null === $before || null === $after ) {
					continue;
				}
				if ( $before['block'] !== $after['block'] ) {
					$errors[] = $this->violation( $path, 'change_block_type', 'protected-block-type-changed', 'A protected semantic block cannot change type.' );
				}
				if ( $before['parent'] !== $after['parent'] ) {
					$errors[] = $this->violation( $path, 'reparent_structure', 'protected-node-reparented', 'A protected semantic block cannot be reparented.' );
				}
				if ( ! $user_owned && ! $pattern_slot_item && $before['sibling_position'] !== $after['sibling_position'] ) {
					$errors[] = $this->violation( $path, 'move_structure', 'protected-node-moved', 'A protected semantic block cannot be moved.' );
				}

				$editable = array_fill_keys( (array) ( $node['editable_attributes'] ?? array() ), true );
				$keys     = array_unique( array_merge( array_keys( $before['attrs'] ), array_keys( $after['attrs'] ) ) );
				foreach ( $keys as $attribute ) {
					if ( isset( $editable[ $attribute ] ) || $this->same( $before['attrs'][ $attribute ] ?? null, $after['attrs'][ $attribute ] ?? null ) ) {
						continue;
					}
					$errors[] = $this->violation( $path . '.' . $attribute, 'change_structure_attribute', 'attribute-not-editable', 'The block attribute is protected by the Structure Contract.', array( 'attribute' => $attribute ) );
				}
				if ( empty( $node['editable_content'] ) && ( $before['inner_html'] !== $after['inner_html'] || ! $this->same( $before['inner_content'], $after['inner_content'] ) ) ) {
					$errors[] = $this->violation( $path, 'set_content', 'content-not-editable', 'The block content shell is protected by the Structure Contract.' );
				}
			}
		}

		$errors = array_merge( $errors, $this->detect_cross_slot_moves( $nodes, $previous, $proposed ) );
		return $errors;
	}

	/** @return array<string,array> */
	private function matches_by_scope( array $matches ): array {
		$result = array();
		foreach ( $matches as $index => $match ) {
			$scope = trim( (string) ( $match['pattern_instance_id'] ?? '' ) );
			if ( '' === $scope && 1 === count( $matches ) ) {
				$result[''] = $match;
				continue;
			}
			$result[ '' !== $scope ? $scope : '__ambiguous-' . $index ] = $match;
		}
		return $result;
	}

	private function detect_cross_slot_moves( array $nodes, array $previous, array $proposed ): array {
		$removed = array();
		$added   = array();
		$previous_ids = $this->user_id_locations( $nodes, $previous );
		$proposed_ids = $this->user_id_locations( $nodes, $proposed );
		$errors       = array();
		foreach ( array_intersect( array_keys( $previous_ids ), array_keys( $proposed_ids ) ) as $user_id ) {
			$from = $previous_ids[ $user_id ];
			$to   = $proposed_ids[ $user_id ];
			if ( $from !== $to ) {
				$errors[] = $this->violation( $to, 'move_user_block', 'cross-slot-move-forbidden', 'A user-owned block cannot move between extension slots.', array( 'from' => $from, 'to' => $to, 'userBlockId' => $user_id ) );
			}
		}
		foreach ( $nodes as $slot_id => $node ) {
			if ( 'slot' !== (string) ( $node['mode'] ?? '' ) || ! empty( $node['allow_cross_slot_move'] ) ) {
				continue;
			}
			$before = $this->fingerprint_counts( (array) ( $previous['slots'][ $slot_id ]['direct'] ?? array() ) );
			$after  = $this->fingerprint_counts( (array) ( $proposed['slots'][ $slot_id ]['direct'] ?? array() ) );
			foreach ( array_unique( array_merge( array_keys( $before ), array_keys( $after ) ) ) as $fingerprint ) {
				$delta = ( $after[ $fingerprint ] ?? 0 ) - ( $before[ $fingerprint ] ?? 0 );
				if ( $delta < 0 ) {
					$removed[ $fingerprint ][ $slot_id ] = -$delta;
				} elseif ( $delta > 0 ) {
					$added[ $fingerprint ][ $slot_id ] = $delta;
				}
			}
		}

		foreach ( array_intersect( array_keys( $removed ), array_keys( $added ) ) as $fingerprint ) {
			foreach ( $removed[ $fingerprint ] as $from => $removed_count ) {
				foreach ( $added[ $fingerprint ] as $to => $added_count ) {
					if ( $from !== $to && min( $removed_count, $added_count ) > 0 ) {
						$errors[] = $this->violation( $to, 'move_user_block', 'cross-slot-move-forbidden', 'A user-owned block cannot move between extension slots.', array( 'from' => $from, 'to' => $to ) );
					}
				}
			}
		}
		return $errors;
	}

	private function user_id_locations( array $nodes, array $document ): array {
		$locations = array();
		foreach ( $nodes as $slot_id => $node ) {
			if ( 'slot' !== (string) ( $node['mode'] ?? '' ) || ! empty( $node['allow_cross_slot_move'] ) ) {
				continue;
			}
			foreach ( (array) ( $document['slots'][ $slot_id ]['direct'] ?? array() ) as $entry ) {
				$user_id = (string) ( $entry['user_id'] ?? '' );
				if ( '' !== $user_id && ! isset( $locations[ $user_id ] ) ) {
					$locations[ $user_id ] = $slot_id;
				}
			}
		}
		return $locations;
	}

	private function user_entry( array $block, string $name, array $path ): array {
		$attrs      = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
		$metadata   = isset( $attrs['metadata'] ) && is_array( $attrs['metadata'] ) ? $attrs['metadata'] : array();
		$namespaced = isset( $metadata['wpsuiteAgentComposer'] ) && is_array( $metadata['wpsuiteAgentComposer'] ) ? $metadata['wpsuiteAgentComposer'] : array();
		return array(
			'block'       => $name,
			'path'        => $path,
			'user_id'     => is_string( $namespaced['userBlockId'] ?? null ) ? trim( $namespaced['userBlockId'] ) : '',
			'declared_slot' => is_string( $namespaced['slotId'] ?? null ) ? trim( $namespaced['slotId'] ) : '',
			'pattern_instance_id' => is_string( $namespaced['patternInstanceId'] ?? null ) ? trim( $namespaced['patternInstanceId'] ) : '',
			'fingerprint' => hash( 'sha256', serialize( $this->canonicalize( $block ) ) ),
		);
	}

	private function pattern_instance_id( array $block ): string {
		$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
		$metadata = is_array( $attrs['metadata'] ?? null ) ? $attrs['metadata'] : array();
		$composer = is_array( $metadata['wpsuiteAgentComposer'] ?? null ) ? $metadata['wpsuiteAgentComposer'] : array();
		return is_string( $composer['patternInstanceId'] ?? null ) ? trim( $composer['patternInstanceId'] ) : '';
	}

	private function fingerprint_from_inspection( array $nodes, array $document ): string {
		$projection = array();
		foreach ( $nodes as $id => $node ) {
			if ( 'USER' === (string) ( $node['ownership'] ?? '' ) ) {
				continue;
			}
			$matches = (array) ( $document['semantic'][ $id ] ?? array() );
			foreach ( $this->matches_by_scope( $matches ) as $scope => $actual ) {
				if ( '' !== $scope && null !== ( $actual['slot'] ?? null ) ) {
					continue;
				}
				$attrs    = $actual['attrs'];
				$editable = array_fill_keys( (array) ( $node['editable_attributes'] ?? array() ), true );
				foreach ( array_keys( $attrs ) as $attribute ) {
					if ( isset( $editable[ $attribute ] ) ) {
						unset( $attrs[ $attribute ] );
					}
				}
				$key = '' === $scope ? $id : $scope . '.' . $id;
				$projection[ $key ] = array(
					'block'  => $actual['block'],
					'parent' => $actual['parent'],
					'path'   => $actual['path'],
					'attrs'  => $attrs,
				);
				if ( empty( $node['editable_content'] ) ) {
					$projection[ $key ]['content_shell'] = array(
						'inner_html'    => $actual['inner_html'],
						'inner_content' => $actual['inner_content'],
					);
				}
			}
		}
		return $this->checksum( $projection );
	}

	private function fingerprint_counts( array $entries ): array {
		$counts = array();
		foreach ( $entries as $entry ) {
			$fingerprint = (string) ( $entry['fingerprint'] ?? '' );
			if ( '' !== $fingerprint ) {
				$counts[ $fingerprint ] = ( $counts[ $fingerprint ] ?? 0 ) + 1;
			}
		}
		return $counts;
	}

	private function structural_inner_content( mixed $inner_content ): string {
		if ( ! is_array( $inner_content ) ) {
			return '';
		}
		return implode( '', array_map( static fn( mixed $part ): string => null === $part ? '' : (string) $part, $inner_content ) );
	}

	private function display_path( ?string $slot_id, array $path ): string {
		$physical = implode( '.', $path );
		return null === $slot_id ? 'document.blocks.' . $physical : $slot_id . '.blocks.' . $physical;
	}

	private function same( mixed $left, mixed $right ): bool {
		return $this->canonicalize( $left ) === $this->canonicalize( $right );
	}

	private function canonicalize( mixed $value ): mixed {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		if ( ! array_is_list( $value ) ) {
			ksort( $value );
		}
		foreach ( $value as $key => $item ) {
			$value[ $key ] = $this->canonicalize( $item );
		}
		return $value;
	}

	private function checksum( mixed $value ): string {
		return 'sha256:' . hash( 'sha256', serialize( $this->canonicalize( $value ) ) );
	}

	private function violation( string $path, string $operation, string $rule, string $message, array $context = array() ): array {
		return array(
			'code'      => 'composer_contract_violation',
			'path'      => $path,
			'operation' => $operation,
			'message'   => $message,
			'context'   => array_merge( array( 'rule' => $rule ), $context ),
		);
	}

	private function unique_violations( array $errors ): array {
		$unique = array();
		foreach ( $errors as $error ) {
			$key = serialize( array( $error['code'] ?? '', $error['path'] ?? '', $error['operation'] ?? '', $error['context']['rule'] ?? '' ) );
			$unique[ $key ] = $error;
		}
		return array_values( $unique );
	}
}
