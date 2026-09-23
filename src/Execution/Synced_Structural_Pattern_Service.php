<?php
/* SmartCloud Agent Composer execution contract. */

namespace SmartCloud\AgentComposer\Execution;

/**
 * Resolve native synced wp_block records and their Pattern Override contract.
 *
 * Pattern definitions remain WordPress' structural source of truth. Composer
 * stores only stable pattern/field contracts in configuration and per-instance
 * values in the native core/block content attribute.
 */
final class Synced_Structural_Pattern_Service {
	private const INSTANCE_SLOTS_KEY = 'instanceSlots';
	private const MAX_NESTING_DEPTH = 12;
	private array $resolved = array();

	public function __construct( private Config_Repository $config ) {}

	public function definition( string $pattern, array $blueprint ): ?array {
		$pattern = strtolower( trim( $pattern ) );
		$enabled = array_values( array_filter( array_map( 'strval', (array) ( $blueprint['synced_patterns'] ?? array() ) ) ) );
		if ( ! in_array( $pattern, $enabled, true ) ) {
			return null;
		}
		$definitions = (array) ( $this->config->get_design_policy()['synced_structural_patterns'] ?? array() );
		$definition  = $definitions[ $pattern ] ?? null;
		if ( ! is_array( $definition ) ) {
			throw new Execution_Exception( 'synced_pattern_contract_missing', 'The Blueprint enables a synced pattern without a Site Contract definition.' );
		}
		return $definition;
	}

	/** Return one native core/block reference carrying validated override values. */
	public function materialize_instance( string $pattern, array $fields, array $blueprint, bool $allow_pattern_defaults = false, string $pattern_instance_id = '' ): array {
		$definition = $this->definition( $pattern, $blueprint );
		if ( null === $definition ) {
			throw new Execution_Exception( 'synced_pattern_not_enabled', 'The requested pattern is not enabled as a synced structural pattern for this Blueprint.' );
		}
		$resolved  = $this->resolve( $pattern, $definition );
		$overrides = $this->normalize_overrides( $fields, $definition, false, $allow_pattern_defaults );
		$pattern_instance_id = '' === trim( $pattern_instance_id ) ? $this->new_pattern_instance_id() : $this->valid_pattern_instance_id( $pattern_instance_id );
		$attrs = array(
			'ref'      => $resolved['post_id'],
			'content'  => $overrides,
			'lock'     => array( 'move' => true, 'remove' => true ),
			'metadata' => array(
				'wpsuiteAgentComposer' => array(
					'patternName'    => $pattern,
					'patternInstanceId' => $pattern_instance_id,
					'patternVersion' => (int) $definition['version'],
					'patternHash'    => $resolved['hash'],
				),
			),
		);
		return array(
			'block' => array(
				'blockName'    => 'core/block',
				'attrs'        => $attrs,
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			),
			'pattern' => array(
				'name'                => $pattern,
				'pattern_instance_id' => $pattern_instance_id,
				'version'             => (int) $definition['version'],
				'ref'                 => $resolved['post_id'],
				'hash'                => $resolved['hash'],
			),
		);
	}

	/** Expand only contract-governed synced references for AST validation/read projection. */
	public function expand_blocks( array $blocks, array $blueprint, array $ancestors = array(), int $depth = 0, string $parent_instance_id = '', array $path = array() ): array {
		if ( $depth > self::MAX_NESTING_DEPTH ) {
			throw new Execution_Exception( 'synced_pattern_nesting_too_deep', 'Synced structural pattern nesting exceeds the supported depth.' );
		}
		$result = array();
		$position = 0;
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) || null === ( $block['blockName'] ?? null ) ) {
				$result[] = $block;
				continue;
			}
			$block_path = array_merge( $path, array( $position ) );
			++$position;
			if ( 'core/block' === (string) $block['blockName'] ) {
				$pattern    = $this->pattern_name( $block );
				$definition = '' === $pattern ? null : $this->definition( $pattern, $blueprint );
				if ( null !== $definition ) {
					$resolved = $this->resolve( $pattern, $definition );
					$this->assert_instance_contract( $block, $pattern, $definition, $resolved );
					$cycle_key = $pattern . ':' . $resolved['post_id'];
					if ( in_array( $cycle_key, $ancestors, true ) ) {
						throw new Execution_Exception( 'synced_pattern_cycle', 'Synced structural patterns must not contain recursive references.' );
					}
					$local_instance_id = $this->instance_id( $block, $block_path );
					$effective_instance_id = '' === $parent_instance_id
						? $local_instance_id
						: 'pattern-' . substr( hash( 'sha256', $parent_instance_id . "\0" . $local_instance_id ), 0, 36 );
					$overrides = is_array( $block['attrs']['content'] ?? null ) ? $block['attrs']['content'] : array();
					$overrides = $this->normalize_overrides( $overrides, $definition, true );
					$expanded = $this->apply_overrides( parse_blocks( $resolved['content'] ), $overrides );
					$expanded = $this->apply_instance_slots( $expanded, $block );
					$expanded = $this->stamp_instance_scope( $expanded, $effective_instance_id, $pattern );
					$result = array_merge( $result, $this->expand_blocks( $expanded, $blueprint, array_merge( $ancestors, array( $cycle_key ) ), $depth + 1, $effective_instance_id, $block_path ) );
					continue;
				}
				throw new Execution_Exception( 'synced_pattern_identity_missing', 'Every core/block reference in a governed document must identify an enabled synced structural pattern.' );
			}
			$children = isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ? $block['innerBlocks'] : array();
			$block['innerBlocks'] = $this->expand_blocks( $children, $blueprint, $ancestors, $depth, $parent_instance_id, $block_path );
			$result[] = $block;
		}
		return $result;
	}

	/** Locate a synced core/block instance whose expanded pattern owns one slot. */
	public function find_slot_owners( array $blocks, array $blueprint, string $slot_id, string $pattern_instance_id = '', array $path = array() ): array {
		$result   = array();
		$position = 0;
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) || null === ( $block['blockName'] ?? null ) ) {
				continue;
			}
			$block_path = array_merge( $path, array( $position ) );
			++$position;
			if ( 'core/block' === (string) $block['blockName'] ) {
				$pattern    = $this->pattern_name( $block );
				$definition = '' === $pattern ? null : $this->definition( $pattern, $blueprint );
				$instance_id = $this->instance_id( $block, $block_path );
				if ( null !== $definition && ( '' === $pattern_instance_id || hash_equals( $pattern_instance_id, $instance_id ) ) ) {
					$expanded = $this->expand_blocks( array( $block ), $blueprint );
					$slots    = $this->find_slot_blocks( $expanded, $slot_id );
					if ( 1 === count( $slots ) ) {
						$result[] = array(
							'path'     => $block_path,
							'block'    => $block,
							'pattern'  => $pattern,
							'pattern_instance_id' => $instance_id,
							'slot'     => $slots[0],
							'children' => array_values( (array) ( $slots[0]['innerBlocks'] ?? array() ) ),
						);
					}
				}
			}
			$children = isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ? $block['innerBlocks'] : array();
			$result = array_merge( $result, $this->find_slot_owners( $children, $blueprint, $slot_id, $pattern_instance_id, $block_path ) );
		}
		return $result;
	}

	/** Persist instance-owned slot children without changing the shared wp_block. */
	public function with_slot_children( array $block, string $slot_id, array $children, string $pattern_instance_id = '' ): array {
		if ( 'core/block' !== (string) ( $block['blockName'] ?? '' ) ) {
			throw new Execution_Exception( 'synced_pattern_slot_owner_invalid', 'Instance slot content must belong to a synced core/block reference.' );
		}
		$slot_id = trim( $slot_id );
		if ( '' === $slot_id || strlen( $slot_id ) > 128 || 1 !== preg_match( '/^[a-z0-9][a-z0-9._-]*$/', $slot_id ) ) {
			throw new Execution_Exception( 'synced_pattern_slot_id_invalid', 'Instance slot content requires a valid semantic slot ID.' );
		}
		if ( count( $children ) > 100 ) {
			throw new Execution_Exception( 'synced_pattern_slot_content_invalid', 'A synced pattern slot cannot contain more than 100 direct blocks.' );
		}
		foreach ( $children as $child ) {
			if ( ! is_array( $child ) || ! is_string( $child['blockName'] ?? null ) || '' === trim( (string) $child['blockName'] ) ) {
				throw new Execution_Exception( 'synced_pattern_slot_content_invalid', 'Instance slot content must contain canonical parsed blocks.' );
			}
		}

		if ( '' !== $pattern_instance_id ) {
			$block = $this->with_instance_id( $block, $pattern_instance_id );
		}
		$attrs      = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
		$metadata   = isset( $attrs['metadata'] ) && is_array( $attrs['metadata'] ) ? $attrs['metadata'] : array();
		$namespaced = isset( $metadata['wpsuiteAgentComposer'] ) && is_array( $metadata['wpsuiteAgentComposer'] ) ? $metadata['wpsuiteAgentComposer'] : array();
		$slots      = isset( $namespaced[ self::INSTANCE_SLOTS_KEY ] ) && is_array( $namespaced[ self::INSTANCE_SLOTS_KEY ] )
			? $namespaced[ self::INSTANCE_SLOTS_KEY ]
			: array();
		if ( empty( $children ) ) {
			unset( $slots[ $slot_id ] );
		} else {
			$slots[ $slot_id ] = array_values( $children );
		}
		if ( empty( $slots ) ) {
			unset( $namespaced[ self::INSTANCE_SLOTS_KEY ] );
		} else {
			$namespaced[ self::INSTANCE_SLOTS_KEY ] = $slots;
		}
		$metadata['wpsuiteAgentComposer'] = $namespaced;
		$attrs['metadata'] = $metadata;
		$block['attrs'] = $attrs;
		return $block;
	}

	/** Locate the native core/block instance that owns a semantic override field. */
	public function find_override_owners( array $blocks, array $blueprint, string $field_id, string $pattern_instance_id = '', array $path = array() ): array {
		$result   = array();
		$position = 0;
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) || null === ( $block['blockName'] ?? null ) ) {
				continue;
			}
			$block_path = array_merge( $path, array( $position ) );
			++$position;
			if ( 'core/block' === (string) $block['blockName'] ) {
				$pattern    = $this->pattern_name( $block );
				$definition = '' === $pattern ? null : $this->definition( $pattern, $blueprint );
				$instance_id = $this->instance_id( $block, $block_path );
				if ( null !== $definition && isset( $definition['overrides'][ $field_id ] ) && ( '' === $pattern_instance_id || hash_equals( $pattern_instance_id, $instance_id ) ) ) {
					$result[] = array(
						'path'       => $block_path,
						'block'      => $block,
						'pattern'    => $pattern,
						'pattern_instance_id' => $instance_id,
						'definition' => $definition['overrides'][ $field_id ],
					);
				}
			}
			$children = isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ? $block['innerBlocks'] : array();
			$result = array_merge( $result, $this->find_override_owners( $children, $blueprint, $field_id, $pattern_instance_id, $block_path ) );
		}
		return $result;
	}

	public function with_override( array $block, string $field_id, string $attribute, mixed $value, string $pattern_instance_id = '' ): array {
		if ( '' !== $pattern_instance_id ) {
			$block = $this->with_instance_id( $block, $pattern_instance_id );
		}
		$attrs   = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
		$content = isset( $attrs['content'] ) && is_array( $attrs['content'] ) ? $attrs['content'] : array();
		$current = isset( $content[ $field_id ] ) && is_array( $content[ $field_id ] ) ? $content[ $field_id ] : array();
		$current[ $attribute ] = $value;
		$content[ $field_id ] = $current;
		$attrs['content'] = $content;
		$block['attrs'] = $attrs;
		return $block;
	}

	/** Inventory managed references without expanding away their instance identity. */
	public function inventory( array $blocks, array $blueprint, array $path = array(), ?string $slot_id = null, array &$seen = array() ): array {
		$result   = array();
		$position = 0;
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) || null === ( $block['blockName'] ?? null ) ) {
				continue;
			}
			$block_path = array_merge( $path, array( $position ) );
			++$position;
			$current_slot = $this->slot_identity( $block );
			if ( '' === $current_slot ) {
				$current_slot = $slot_id;
			}
			if ( 'core/block' === (string) $block['blockName'] ) {
				$pattern = $this->pattern_name( $block );
				$definition = '' === $pattern ? null : $this->definition( $pattern, $blueprint );
				if ( null === $definition ) {
					throw new Execution_Exception( 'synced_pattern_identity_missing', 'Every core/block reference in a governed document must identify an enabled synced structural pattern.' );
				}
				$resolved = $this->resolve( $pattern, $definition );
				$this->assert_instance_contract( $block, $pattern, $definition, $resolved );
				$instance_id = $this->instance_id( $block, $block_path );
				if ( isset( $seen[ $instance_id ] ) ) {
					throw new Execution_Exception( 'synced_pattern_instance_duplicate', 'A pattern_instance_id may occur only once in a managed document.' );
				}
				$seen[ $instance_id ] = true;
				$metadata = is_array( $block['attrs']['metadata'] ?? null ) ? $block['attrs']['metadata'] : array();
				$composer = is_array( $metadata['wpsuiteAgentComposer'] ?? null ) ? $metadata['wpsuiteAgentComposer'] : array();
				$result[] = array(
					'pattern'            => $pattern,
					'pattern_instance_id' => $instance_id,
					'pattern_version'    => (int) $definition['version'],
					'pattern_hash'       => (string) $resolved['hash'],
					'fields'             => is_array( $block['attrs']['content'] ?? null ) ? $block['attrs']['content'] : array(),
					'slot_id'            => $slot_id,
					'user_block_id'      => is_string( $composer['userBlockId'] ?? null ) ? trim( $composer['userBlockId'] ) : '',
					'path'               => $block_path,
				);
				foreach ( (array) ( $composer[ self::INSTANCE_SLOTS_KEY ] ?? array() ) as $owned_slot => $children ) {
					if ( is_string( $owned_slot ) && is_array( $children ) ) {
						$result = array_merge( $result, $this->inventory( $children, $blueprint, $block_path, $owned_slot, $seen ) );
					}
				}
			}
			$result = array_merge( $result, $this->inventory( (array) ( $block['innerBlocks'] ?? array() ), $blueprint, $block_path, '' !== $current_slot ? $current_slot : null, $seen ) );
		}
		return $result;
	}

	private function resolve( string $pattern, array $definition ): array {
		if ( isset( $this->resolved[ $pattern ] ) ) {
			return $this->resolved[ $pattern ];
		}
		$post_name = sanitize_title( (string) ( $definition['post_name'] ?? str_replace( '/', '-', $pattern ) ) );
		$post = get_page_by_path( $post_name, OBJECT, 'wp_block' );
		if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status ) {
			throw new Execution_Exception( 'synced_pattern_missing', 'The configured synced structural pattern is not available as a published wp_block record.' );
		}
		$content = (string) $post->post_content;
		$this->assert_pattern_contract( $pattern, $definition, $content );
		$hash = hash( 'sha256', $content );
		$expected_hash = strtolower( trim( (string) ( $definition['content_hash'] ?? '' ) ) );
		if ( '' !== $expected_hash && ! hash_equals( preg_replace( '/^sha256:/', '', $expected_hash ), $hash ) ) {
			throw new Execution_Exception( 'synced_pattern_content_hash_mismatch', 'The local synced pattern content does not match the configured content hash.' );
		}
		$this->resolved[ $pattern ] = array(
			'post_id' => (int) $post->ID,
			'content' => $content,
			'hash'    => $hash,
		);
		return $this->resolved[ $pattern ];
	}

	private function assert_instance_contract( array $block, string $pattern, array $definition, array $resolved ): void {
		if ( absint( $block['attrs']['ref'] ?? 0 ) !== (int) $resolved['post_id'] ) {
			throw new Execution_Exception( 'synced_pattern_reference_mismatch', 'A managed synced pattern reference does not point to the configured local wp_block record.' );
		}
		$metadata = is_array( $block['attrs']['metadata'] ?? null ) ? $block['attrs']['metadata'] : array();
		$composer = is_array( $metadata['wpsuiteAgentComposer'] ?? null ) ? $metadata['wpsuiteAgentComposer'] : array();
		if ( $pattern !== strtolower( trim( (string) ( $composer['patternName'] ?? '' ) ) ) ) {
			throw new Execution_Exception( 'synced_pattern_name_mismatch', 'A managed synced pattern reference has stale pattern identity metadata.' );
		}
		if ( (int) ( $composer['patternVersion'] ?? 0 ) !== (int) ( $definition['version'] ?? 0 ) ) {
			throw new Execution_Exception( 'synced_pattern_version_mismatch', 'A managed synced pattern reference was created for a different pattern contract version.' );
		}
		if ( ! hash_equals( (string) $resolved['hash'], strtolower( trim( (string) ( $composer['patternHash'] ?? '' ) ) ) ) ) {
			throw new Execution_Exception( 'synced_pattern_hash_mismatch', 'A managed synced pattern reference no longer matches the reviewed pattern content hash.' );
		}
	}

	private function stamp_instance_scope( array $blocks, string $instance_id, string $pattern ): array {
		foreach ( $blocks as $index => $block ) {
			if ( ! is_array( $block ) || null === ( $block['blockName'] ?? null ) ) {
				continue;
			}
			if ( 'core/block' !== (string) $block['blockName'] ) {
				$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
				$metadata = is_array( $attrs['metadata'] ?? null ) ? $attrs['metadata'] : array();
				$composer = is_array( $metadata['wpsuiteAgentComposer'] ?? null ) ? $metadata['wpsuiteAgentComposer'] : array();
				$composer['patternInstanceId'] = $instance_id;
				$composer['patternName'] = $pattern;
				$metadata['wpsuiteAgentComposer'] = $composer;
				$attrs['metadata'] = $metadata;
				$block['attrs'] = $attrs;
			}
			$block['innerBlocks'] = $this->stamp_instance_scope( (array) ( $block['innerBlocks'] ?? array() ), $instance_id, $pattern );
			$blocks[ $index ] = $block;
		}
		return $blocks;
	}

	private function new_pattern_instance_id(): string {
		$uuid = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : bin2hex( random_bytes( 16 ) );
		return 'pattern-' . strtolower( $uuid );
	}

	private function valid_pattern_instance_id( string $value ): string {
		$value = strtolower( trim( $value ) );
		if ( 1 !== preg_match( '/^pattern-[a-z0-9-]{8,58}$/', $value ) ) {
			throw new Execution_Exception( 'synced_pattern_instance_id_invalid', 'A valid stable pattern_instance_id is required.' );
		}
		return $value;
	}

	private function instance_id( array $block, array $path ): string {
		$metadata = is_array( $block['attrs']['metadata'] ?? null ) ? $block['attrs']['metadata'] : array();
		$composer = is_array( $metadata['wpsuiteAgentComposer'] ?? null ) ? $metadata['wpsuiteAgentComposer'] : array();
		$value = strtolower( trim( (string) ( $composer['patternInstanceId'] ?? '' ) ) );
		if ( '' !== $value ) {
			return $this->valid_pattern_instance_id( $value );
		}
		// Compatibility identity for drafts created before patternInstanceId was introduced.
		return 'pattern-' . substr( hash( 'sha256', implode( '.', $path ) . "\0" . $this->pattern_name( $block ) . "\0" . absint( $block['attrs']['ref'] ?? 0 ) ), 0, 36 );
	}

	private function with_instance_id( array $block, string $pattern_instance_id ): array {
		$pattern_instance_id = $this->valid_pattern_instance_id( $pattern_instance_id );
		$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
		$metadata = is_array( $attrs['metadata'] ?? null ) ? $attrs['metadata'] : array();
		$composer = is_array( $metadata['wpsuiteAgentComposer'] ?? null ) ? $metadata['wpsuiteAgentComposer'] : array();
		$composer['patternInstanceId'] = $pattern_instance_id;
		$metadata['wpsuiteAgentComposer'] = $composer;
		$attrs['metadata'] = $metadata;
		$block['attrs'] = $attrs;
		return $block;
	}

	private function assert_pattern_contract( string $pattern, array $definition, string $content ): void {
		$found = array();
		$this->collect_bound_fields( parse_blocks( $content ), $found );
		foreach ( (array) ( $definition['overrides'] ?? array() ) as $field_id => $field ) {
			$expected_block = (string) ( $field['block'] ?? '' );
			foreach ( (array) ( $field['attributes'] ?? array() ) as $attribute ) {
				$bound_attributes = (array) ( $found[ $field_id ]['attributes'] ?? array() );
				if (
					$expected_block !== (string) ( $found[ $field_id ]['block'] ?? '' )
					|| ( ! in_array( '__default', $bound_attributes, true ) && ! in_array( $attribute, $bound_attributes, true ) )
				) {
					throw new Execution_Exception( 'synced_pattern_override_contract_mismatch', 'The local synced pattern does not expose every configured Pattern Override binding.' );
				}
			}
		}
		if ( empty( $definition['overrides'] ) ) {
			throw new Execution_Exception( 'synced_pattern_overrides_missing', 'A synced structural pattern must declare at least one Pattern Override field.' );
		}
	}

	private function collect_bound_fields( array $blocks, array &$found ): void {
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) || null === ( $block['blockName'] ?? null ) ) {
				continue;
			}
			$metadata = is_array( $block['attrs']['metadata'] ?? null ) ? $block['attrs']['metadata'] : array();
			$field_id = is_string( $metadata['name'] ?? null ) ? trim( $metadata['name'] ) : '';
			$bindings = is_array( $metadata['bindings'] ?? null ) ? $metadata['bindings'] : array();
			if ( '' !== $field_id ) {
				$attributes = array();
				foreach ( $bindings as $attribute => $binding ) {
					if ( is_array( $binding ) && 'core/pattern-overrides' === (string) ( $binding['source'] ?? '' ) ) {
						$attributes[] = (string) $attribute;
					}
				}
				if ( ! empty( $attributes ) ) {
					$found[ $field_id ] = array( 'block' => (string) $block['blockName'], 'attributes' => $attributes );
				}
			}
			$this->collect_bound_fields( (array) ( $block['innerBlocks'] ?? array() ), $found );
		}
	}

	private function normalize_overrides( array $fields, array $definition, bool $stored = false, bool $allow_pattern_defaults = false ): array {
		if ( count( $fields ) > 100 ) {
			throw new Execution_Exception( 'too_many_pattern_overrides', 'A synced pattern instance cannot contain more than 100 override fields.' );
		}
		$result = array();
		$contracts = (array) ( $definition['overrides'] ?? array() );
		foreach ( $fields as $field_id => $value ) {
			$field_id = (string) $field_id;
			$contract = $contracts[ $field_id ] ?? null;
			if ( ! is_array( $contract ) ) {
				throw new Execution_Exception( 'pattern_override_not_declared', 'A Pattern Override value is not declared by the synced pattern contract.' );
			}
			$attributes = array_values( (array) ( $contract['attributes'] ?? array() ) );
			if ( ! is_array( $value ) ) {
				if ( 1 !== count( $attributes ) ) {
					throw new Execution_Exception( 'pattern_override_value_invalid', 'A multi-attribute Pattern Override field requires an attribute object.' );
				}
				$value = array( $attributes[0] => $value );
			}
			if ( array_diff( array_keys( $value ), $attributes ) ) {
				throw new Execution_Exception( 'pattern_override_attribute_forbidden', 'A Pattern Override contains an attribute not declared by the synced pattern contract.' );
			}
			if ( ! $stored && true === ( $contract['required'] ?? false ) && empty( $value ) ) {
				throw new Execution_Exception( 'pattern_override_required', 'A required Pattern Override field is empty.' );
			}
			$result[ $field_id ] = $value;
		}
		if ( ! $stored && ! $allow_pattern_defaults ) {
			foreach ( $contracts as $field_id => $contract ) {
				if ( true === ( $contract['required'] ?? false ) && ! array_key_exists( $field_id, $result ) ) {
					throw new Execution_Exception( 'pattern_override_required', 'A required Pattern Override field was not supplied.' );
				}
			}
		}
		return $result;
	}

	private function apply_overrides( array $blocks, array $overrides ): array {
		foreach ( $blocks as $index => $block ) {
			if ( ! is_array( $block ) || null === ( $block['blockName'] ?? null ) ) {
				continue;
			}
			$metadata = is_array( $block['attrs']['metadata'] ?? null ) ? $block['attrs']['metadata'] : array();
			$field_id = is_string( $metadata['name'] ?? null ) ? trim( $metadata['name'] ) : '';
			if ( '' !== $field_id && is_array( $overrides[ $field_id ] ?? null ) ) {
				foreach ( $overrides[ $field_id ] as $attribute => $value ) {
					$block['attrs'][ $attribute ] = $value;
					if ( 'content' === $attribute && is_string( $value ) ) {
						$block = $this->replace_rich_text( $block, $value );
					}
				}
			}
			$block['innerBlocks'] = $this->apply_overrides( (array) ( $block['innerBlocks'] ?? array() ), $overrides );
			$blocks[ $index ] = $block;
		}
		return $blocks;
	}

	private function apply_instance_slots( array $blocks, array $instance ): array {
		$metadata   = is_array( $instance['attrs']['metadata'] ?? null ) ? $instance['attrs']['metadata'] : array();
		$namespaced = is_array( $metadata['wpsuiteAgentComposer'] ?? null ) ? $metadata['wpsuiteAgentComposer'] : array();
		$slots      = is_array( $namespaced[ self::INSTANCE_SLOTS_KEY ] ?? null ) ? $namespaced[ self::INSTANCE_SLOTS_KEY ] : array();
		foreach ( $slots as $slot_id => $children ) {
			if ( ! is_string( $slot_id ) || ! is_array( $children ) ) {
				continue;
			}
			$matches = 0;
			$blocks  = $this->inject_slot_children( $blocks, $slot_id, array_values( $children ), $matches );
		}
		return $blocks;
	}

	private function inject_slot_children( array $blocks, string $slot_id, array $children, int &$matches ): array {
		foreach ( $blocks as $index => $block ) {
			if ( ! is_array( $block ) || null === ( $block['blockName'] ?? null ) ) {
				continue;
			}
			if ( $this->slot_identity( $block ) === $slot_id ) {
				++$matches;
				if ( 1 === $matches ) {
					$block['innerBlocks']  = $children;
					$block['innerContent'] = $this->slot_inner_content( (array) ( $block['innerContent'] ?? array() ), (string) ( $block['innerHTML'] ?? '' ), count( $children ) );
				}
			} else {
				$block['innerBlocks'] = $this->inject_slot_children( (array) ( $block['innerBlocks'] ?? array() ), $slot_id, $children, $matches );
			}
			$blocks[ $index ] = $block;
		}
		return $blocks;
	}

	private function find_slot_blocks( array $blocks, string $slot_id ): array {
		$result = array();
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) || null === ( $block['blockName'] ?? null ) ) {
				continue;
			}
			if ( $this->slot_identity( $block ) === $slot_id ) {
				$result[] = $block;
			}
			$result = array_merge( $result, $this->find_slot_blocks( (array) ( $block['innerBlocks'] ?? array() ), $slot_id ) );
		}
		return $result;
	}

	private function slot_identity( array $block ): string {
		if ( 'smartcloud-agent-composer/extension-slot' !== (string) ( $block['blockName'] ?? '' ) ) {
			return '';
		}
		$attrs      = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();
		$metadata   = is_array( $attrs['metadata'] ?? null ) ? $attrs['metadata'] : array();
		$namespaced = is_array( $metadata['wpsuiteAgentComposer'] ?? null ) ? $metadata['wpsuiteAgentComposer'] : array();
		foreach ( array( $namespaced['nodeId'] ?? null, $metadata['name'] ?? null, $attrs['slotId'] ?? null ) as $candidate ) {
			if ( is_string( $candidate ) && '' !== trim( $candidate ) ) {
				return trim( $candidate );
			}
		}
		return '';
	}

	private function slot_inner_content( array $inner_content, string $inner_html, int $children ): array {
		$first = array_search( null, $inner_content, true );
		$last  = null;
		foreach ( $inner_content as $index => $part ) {
			if ( null === $part ) {
				$last = $index;
			}
		}
		if ( false !== $first && null !== $last ) {
			$prefix = implode( '', array_filter( array_slice( $inner_content, 0, (int) $first ), 'is_string' ) );
			$suffix = implode( '', array_filter( array_slice( $inner_content, $last + 1 ), 'is_string' ) );
		} elseif ( 1 === preg_match( '#^(.*?>)(.*)(</[a-z0-9:-]+>\s*)$#is', $inner_html, $match ) ) {
			$prefix = (string) $match[1] . (string) $match[2];
			$suffix = (string) $match[3];
		} else {
			$prefix = '';
			$suffix = '';
		}
		return array_merge( array( $prefix ), array_fill( 0, $children, null ), array( $suffix ) );
	}

	private function replace_rich_text( array $block, string $value ): array {
		$html = (string) ( $block['innerHTML'] ?? '' );
		$pattern = 'core/button' === (string) ( $block['blockName'] ?? '' )
			? '#^(.*<a\b[^>]*>)(.*)(</a>.*)$#is'
			: '#^(\s*<([a-z][a-z0-9]*)\b[^>]*>)(.*)(</\2>\s*)$#is';
		if ( 1 === preg_match( $pattern, $html, $match ) ) {
			$html = 'core/button' === (string) ( $block['blockName'] ?? '' )
				? (string) $match[1] . wp_kses_post( $value ) . (string) $match[3]
				: (string) $match[1] . wp_kses_post( $value ) . (string) $match[4];
			$block['innerHTML'] = $html;
			$block['innerContent'] = array( $html );
		}
		return $block;
	}

	private function pattern_name( array $block ): string {
		$metadata   = is_array( $block['attrs']['metadata'] ?? null ) ? $block['attrs']['metadata'] : array();
		$namespaced = is_array( $metadata['wpsuiteAgentComposer'] ?? null ) ? $metadata['wpsuiteAgentComposer'] : array();
		$name       = strtolower( trim( (string) ( $namespaced['patternName'] ?? $metadata['patternName'] ?? '' ) ) );
		return preg_match( '#^[a-z0-9-]+/[a-z0-9-]+$#', $name ) ? $name : '';
	}
}
