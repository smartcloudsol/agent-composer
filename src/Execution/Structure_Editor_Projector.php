<?php
/* SmartCloud Agent Composer execution contract. */

namespace SmartCloud\AgentComposer\Execution;

/** Project Structure Contract ownership into native Gutenberg editor attributes. */
final class Structure_Editor_Projector {
	public function project_content( array $contract, string $content ): string {
		$projected  = $this->project_blocks( $contract, parse_blocks( $content ) );
		$serialized = serialize_blocks( $projected );
		$round_trip = serialize_blocks( parse_blocks( $serialized ) );
		if ( ! hash_equals( hash( 'sha256', $serialized ), hash( 'sha256', $round_trip ) ) ) {
			throw new Execution_Exception( 'structure_editor_projection_failed', 'Structure Contract editor protection did not survive native Gutenberg serialization.' );
		}
		return $serialized;
	}

	/** Apply deterministic editor protection to a parsed Gutenberg AST. */
	public function project_blocks( array $contract, array $blocks ): array {
		$nodes = array();
		foreach ( (array) ( $contract['nodes'] ?? array() ) as $node ) {
			if ( is_array( $node ) && isset( $node['id'] ) ) {
				$nodes[ (string) $node['id'] ] = $node;
			}
		}
		if ( empty( $nodes ) ) {
			return $blocks;
		}

		$slot_ancestors = $this->slot_ancestors( $nodes );
		return $this->walk( $blocks, $contract, $nodes, $slot_ancestors, null, array() );
	}

	private function walk( array $blocks, array $contract, array $nodes, array $slot_ancestors, ?string $slot_id, array $path ): array {
		foreach ( $blocks as $index => $block ) {
			if ( ! is_array( $block ) || null === ( $block['blockName'] ?? null ) ) {
				continue;
			}
			$block_path = array_merge( $path, array( (int) $index ) );
			$node_id = $this->semantic_identity( $block, $nodes );
			if ( null !== $node_id ) {
				$block = $this->protect( $block, $contract, $nodes[ $node_id ], isset( $slot_ancestors[ $node_id ] ) );
				if ( 'USER' === (string) $nodes[ $node_id ]['ownership'] && null !== $slot_id ) {
					$block = $this->mark_user_block( $block, $contract, $slot_id, $block_path );
				}
			} elseif ( null !== $slot_id ) {
				$block = $this->mark_user_block( $block, $contract, $slot_id, $block_path );
			}
			$children = isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ? $block['innerBlocks'] : array();
			$child_slot_id = null !== $node_id && 'slot' === (string) $nodes[ $node_id ]['mode'] ? $node_id : $slot_id;
			$block['innerBlocks'] = $this->walk( $children, $contract, $nodes, $slot_ancestors, $child_slot_id, $block_path );
			$blocks[ $index ] = $block;
		}
		return $blocks;
	}

	private function protect( array $block, array $contract, array $node, bool $contains_slot ): array {
		$attrs    = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
		$metadata = isset( $attrs['metadata'] ) && is_array( $attrs['metadata'] ) ? $attrs['metadata'] : array();
		$existing_namespace = isset( $metadata['wpsuiteAgentComposer'] ) && is_array( $metadata['wpsuiteAgentComposer'] )
			? $metadata['wpsuiteAgentComposer']
			: array();
		$identity = array();
		if ( is_string( $existing_namespace['patternName'] ?? null ) && '' !== trim( $existing_namespace['patternName'] ) ) {
			$identity['patternName'] = trim( $existing_namespace['patternName'] );
		}
		$metadata['wpsuiteAgentComposer'] = array_merge( $identity, array(
			'contractId'      => (string) ( $contract['id'] ?? '' ),
			'contractVersion' => (int) ( $contract['version'] ?? 0 ),
			'nodeId'          => (string) $node['id'],
			'ownership'       => (string) $node['ownership'],
			'mode'            => (string) $node['mode'],
		) );
		$attrs['metadata'] = $metadata;

		if ( 'USER' !== (string) $node['ownership'] ) {
			$lock           = isset( $attrs['lock'] ) && is_array( $attrs['lock'] ) ? $attrs['lock'] : array();
			$lock['move']   = true;
			$lock['remove'] = true;
			$attrs['lock']  = $lock;
		} else {
			unset( $attrs['lock'] );
		}

		if ( 'structure' === (string) $node['mode'] && ! empty( $block['innerBlocks'] ) && ! $contains_slot ) {
			$attrs['templateLock'] = 'contentOnly';
		}
		if ( 'slot' === (string) $node['mode'] ) {
			$attrs['templateLock']  = false;
			$attrs['slotId']        = (string) $node['id'];
			$attrs['allowedBlocks'] = array_values( (array) ( $node['allowed_blocks'] ?? array() ) );
			$attrs['allowedPatterns'] = array_values( (array) ( $node['allowed_patterns'] ?? array() ) );
			$attrs['patternOccurrences'] = (array) ( $node['pattern_occurrences'] ?? array() );
			$attrs['minBlocks']     = (int) ( $node['min_blocks'] ?? 0 );
			if ( null === ( $node['max_blocks'] ?? null ) ) {
				unset( $attrs['maxBlocks'] );
			} else {
				$attrs['maxBlocks'] = (int) $node['max_blocks'];
			}
		}

		$block['attrs'] = $attrs;
		return $block;
	}

	private function mark_user_block( array $block, array $contract, string $slot_id, array $path ): array {
		$attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
		unset( $attrs['lock'] );
		$metadata   = isset( $attrs['metadata'] ) && is_array( $attrs['metadata'] ) ? $attrs['metadata'] : array();
		$namespaced = isset( $metadata['wpsuiteAgentComposer'] ) && is_array( $metadata['wpsuiteAgentComposer'] ) ? $metadata['wpsuiteAgentComposer'] : array();
		$user_id    = is_string( $namespaced['userBlockId'] ?? null ) ? trim( $namespaced['userBlockId'] ) : '';
		if ( '' === $user_id ) {
			$seed = array(
				'contract' => array( (string) ( $contract['id'] ?? '' ), (int) ( $contract['version'] ?? 0 ) ),
				'slot'     => $slot_id,
				'path'     => $path,
				'block'    => $this->canonicalize( $block ),
			);
			$user_id = 'user-' . substr( hash( 'sha256', serialize( $seed ) ), 0, 32 );
		}
		$namespaced['contractId']      = (string) ( $contract['id'] ?? '' );
		$namespaced['contractVersion'] = (int) ( $contract['version'] ?? 0 );
		$namespaced['ownership']       = 'USER';
		$namespaced['slotId']          = $slot_id;
		$namespaced['userBlockId']     = $user_id;
		$metadata['wpsuiteAgentComposer'] = $namespaced;
		$attrs['metadata'] = $metadata;
		$block['attrs'] = $attrs;
		return $block;
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

	private function semantic_identity( array $block, array $nodes ): ?string {
		$attrs      = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
		$metadata   = isset( $attrs['metadata'] ) && is_array( $attrs['metadata'] ) ? $attrs['metadata'] : array();
		$composer   = isset( $attrs['composer'] ) && is_array( $attrs['composer'] ) ? $attrs['composer'] : array();
		$namespaced = isset( $metadata['wpsuiteAgentComposer'] ) && is_array( $metadata['wpsuiteAgentComposer'] ) ? $metadata['wpsuiteAgentComposer'] : array();
		$candidates = array_values(
			array_unique(
				array_filter(
					array(
						is_string( $composer['nodeId'] ?? null ) ? trim( $composer['nodeId'] ) : '',
						is_string( $namespaced['nodeId'] ?? null ) ? trim( $namespaced['nodeId'] ) : '',
						is_string( $metadata['name'] ?? null ) ? trim( $metadata['name'] ) : '',
					),
					static fn( string $candidate ): bool => isset( $nodes[ $candidate ] )
				)
			)
		);
		return 1 === count( $candidates ) ? $candidates[0] : null;
	}

	/** Return structure nodes whose content-only projection would hide a nested extension slot. */
	private function slot_ancestors( array $nodes ): array {
		$ancestors = array();
		foreach ( $nodes as $node ) {
			if ( 'slot' !== (string) ( $node['mode'] ?? '' ) ) {
				continue;
			}
			$parent = $node['parent'] ?? null;
			while ( is_string( $parent ) && isset( $nodes[ $parent ] ) && ! isset( $ancestors[ $parent ] ) ) {
				$ancestors[ $parent ] = true;
				$parent = $nodes[ $parent ]['parent'] ?? null;
			}
		}
		return $ancestors;
	}
}
