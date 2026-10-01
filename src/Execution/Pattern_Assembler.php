<?php
/* SmartCloud Agent Composer execution contract. */

namespace SmartCloud\AgentComposer\Execution;

final class Pattern_Assembler {
	private Config_Repository $config;
	private Pattern_Repository $patterns;
	private Semantic_Slot_Materializer $slots;
	private Structure_Editor_Projector $editor;
	private Synced_Structural_Pattern_Service $synced_patterns;

	public function __construct( Config_Repository $config, Pattern_Repository $patterns, Semantic_Slot_Materializer $slots, Structure_Editor_Projector $editor, Synced_Structural_Pattern_Service $synced_patterns ) {
		$this->config   = $config;
		$this->patterns = $patterns;
		$this->slots    = $slots;
		$this->editor   = $editor;
		$this->synced_patterns = $synced_patterns;
	}

	/**
	 * Assemble approved registered patterns with context-aware placeholders.
	 * Supported tokens: {{wpsuite:text:key}}, {{wpsuite:attr:key}},
	 * {{wpsuite:url:key}}, and {{wpsuite:json:key}}.
	 */
	public function assemble( string $page_type, array $sections, array $context = array() ): array {
		$blueprint = $this->config->get_blueprint( $page_type );
		if ( 'structured-record' === $blueprint['composition_mode'] ) {
			if ( ! empty( $sections ) ) {
				throw new Execution_Exception( 'structured_record_sections_forbidden', 'Structured-record Blueprints require zero Gutenberg sections.' );
			}
			return array(
				'content'          => '',
				'sequence'         => array(),
				'composition_mode' => 'structured-record',
				'content_language' => $blueprint['content_language'],
			);
		}
		if ( empty( $sections ) || count( $sections ) > 50 ) {
			throw new Execution_Exception( 'invalid_sections', 'Between 1 and 50 sections are required.' );
		}

		$sequence = array();
		$content  = '';
		foreach ( $sections as $index => $section ) {
			if ( ! is_array( $section ) ) {
				throw new Execution_Exception( 'invalid_section', 'Every section must be an object.' );
			}

			$pattern = strtolower( trim( (string) ( $section['pattern'] ?? '' ) ) );
			if ( ! in_array( $pattern, $blueprint['allowed_patterns'], true ) ) {
				throw new Execution_Exception( 'pattern_not_allowed', 'A requested pattern is not allowed by this blueprint.' );
			}
			$this->assert_namespace_allowed( $pattern );
			$fields = isset( $section['fields'] ) && is_array( $section['fields'] ) ? $section['fields'] : array();
			if ( null !== $this->synced_patterns->definition( $pattern, $blueprint ) ) {
				$instance   = $this->synced_patterns->materialize_instance(
					$pattern,
					$fields,
					$blueprint,
					true === ( $context['allow_pattern_defaults'] ?? false ),
					(string) ( $section['pattern_instance_id'] ?? '' )
				);
				$sequence[] = $pattern;
				$content   .= serialize_block( $instance['block'] );
				continue;
			}

			$registered = $this->patterns->resolve_approved( $pattern, $blueprint );
			if ( ! is_array( $registered ) || empty( $registered['content'] ) ) {
				throw new Execution_Exception( 'pattern_not_registered', 'An approved pattern is not registered by the active theme or a plugin.' );
			}

			$source = preg_replace(
				'/<!--\s*wpsuite-agent-section:[a-z0-9-]+\/[a-z0-9-]+\s*-->/',
				'',
				(string) $registered['content']
			);
			$slot_contract = $this->slots->slots_for_pattern( $pattern );
			$rendered = $this->replace_placeholders(
				is_string( $source ) ? $source : (string) $registered['content'],
				$fields,
				array_keys( $slot_contract )
			);
			$this->assert_no_unresolved_placeholders( $rendered );
			$rendered = $this->slots->materialize( $pattern, $rendered, $fields );
			$sequence[] = $pattern;
			$content   .= $this->add_pattern_metadata( $rendered, $pattern );
		}

		$this->assert_required_sequence( $blueprint['required_sequence'], $sequence );
		$content = trim( $content );
		if ( 'enforced' === (string) ( $blueprint['structure_contract_mode'] ?? '' ) ) {
			$content = $this->editor->project_content( (array) $blueprint['resolved_structure_contract'], $content );
		}
		return array(
			'content'          => $content,
			'sequence'         => $sequence,
			'composition_mode' => 'document',
			'content_language' => $blueprint['content_language'],
		);
	}

	/** Build the canonical native-editor starting document from the Blueprint minimum sequence. */
	public function assemble_admin_default( string $page_type ): array {
		$blueprint = $this->config->get_blueprint( $page_type );
		if ( 'structured-record' === (string) $blueprint['composition_mode'] ) {
			return $this->assemble( $page_type, array() );
		}
		$sections = array_map(
			static fn( string $pattern ): array => array( 'pattern' => $pattern, 'fields' => array() ),
			(array) $blueprint['required_sequence']
		);
		$assembled = $this->assemble( $page_type, $sections, array( 'allow_pattern_defaults' => true ) );
		$blocks    = parse_blocks( (string) $assembled['content'] );
		$blocks    = $this->seed_admin_minimum_slots( $blocks, $blueprint );
		$assembled['content'] = trim( serialize_blocks( $blocks ) );
		return $assembled;
	}

	/**
	 * Give a native-editor starting document the minimum valid slot children.
	 *
	 * Required user-authored text starts as an empty paragraph, so Composer can
	 * create the governed structure without publishing instructional copy. A
	 * required repeatable-pattern slot receives its declared minimum pattern
	 * instances, and those children are seeded recursively before attachment.
	 */
	private function seed_admin_minimum_slots( array $blocks, array $blueprint, int $depth = 0 ): array {
		if ( $depth > 12 ) {
			throw new Execution_Exception( 'admin_creation_slot_nesting_too_deep', 'The native-editor starting document contains excessively nested required slots.' );
		}
		$contract = is_array( $blueprint['resolved_structure_contract'] ?? null )
			? $blueprint['resolved_structure_contract']
			: array();
		foreach ( (array) ( $contract['nodes'] ?? array() ) as $node ) {
			if ( ! is_array( $node ) || 'slot' !== (string) ( $node['mode'] ?? '' ) ) {
				continue;
			}
			$slot_id = trim( (string) ( $node['id'] ?? '' ) );
			$minimum = max( 0, (int) ( $node['min_blocks'] ?? 0 ) );
			if ( '' === $slot_id || 0 === $minimum ) {
				continue;
			}

			$owners = $this->synced_patterns->find_slot_owners( $blocks, $blueprint, $slot_id );
			foreach ( $owners as $owner ) {
				$children = array_values( (array) ( $owner['children'] ?? array() ) );
				$children = $this->admin_slot_minimum_children( $children, $node, $blueprint, $depth );
				$updated  = $this->synced_patterns->with_slot_children( (array) $owner['block'], $slot_id, $children );
				$blocks   = $this->replace_block_at_path( $blocks, (array) ( $owner['path'] ?? array() ), $updated );
			}
		}
		return $blocks;
	}

	/** Add the declared minimum direct blocks or synced-pattern occurrences. */
	private function admin_slot_minimum_children( array $children, array $node, array $blueprint, int $depth ): array {
		$minimum          = max( 0, (int) ( $node['min_blocks'] ?? 0 ) );
		$maximum          = isset( $node['max_blocks'] ) ? max( 0, (int) $node['max_blocks'] ) : null;
		$allowed_patterns = array_values( array_filter( array_map( 'strval', (array) ( $node['allowed_patterns'] ?? array() ) ) ) );
		$occurrences      = is_array( $node['pattern_occurrences'] ?? null ) ? $node['pattern_occurrences'] : array();
		$counts           = array_fill_keys( $allowed_patterns, 0 );
		foreach ( $children as $child ) {
			$pattern = strtolower( trim( (string) ( $child['attrs']['metadata']['wpsuiteAgentComposer']['patternName'] ?? '' ) ) );
			if ( isset( $counts[ $pattern ] ) ) {
				++$counts[ $pattern ];
			}
		}

		// Plan the complete addition before materializing any pattern instances.
		// An impossible contract must not leave partially generated defaults.
		$additions = array();
		$limits    = array();
		if ( null !== $maximum && max( $minimum, count( $children ) ) > $maximum ) {
			throw new Execution_Exception( 'admin_creation_slot_default_missing', 'The native-editor slot minimum cannot fit within its maximum.' );
		}
		foreach ( $allowed_patterns as $pattern ) {
			$required = max( 0, (int) ( $occurrences[ $pattern ]['min'] ?? 0 ) );
			$limits[ $pattern ] = isset( $occurrences[ $pattern ]['max'] ) ? max( 0, (int) $occurrences[ $pattern ]['max'] ) : null;
			if ( null !== $limits[ $pattern ] && max( $required, $counts[ $pattern ] ) > $limits[ $pattern ] ) {
				throw new Execution_Exception( 'admin_creation_slot_default_missing', 'A native-editor pattern minimum cannot fit within its occurrence maximum.' );
			}
			while ( ( $counts[ $pattern ] ?? 0 ) < $required ) {
				$additions[] = $pattern;
				++$counts[ $pattern ];
			}
		}
		if ( null !== $maximum && count( $children ) + count( $additions ) > $maximum ) {
			throw new Execution_Exception( 'admin_creation_slot_default_missing', 'Required native-editor patterns exceed the slot maximum.' );
		}

		$allowed_blocks = array_values( array_filter( array_map( 'strval', (array) ( $node['allowed_blocks'] ?? array() ) ) ) );
		while ( count( $children ) + count( $additions ) < $minimum ) {
			$next_pattern = null;
			foreach ( $allowed_patterns as $pattern ) {
				if ( null === $limits[ $pattern ] || $counts[ $pattern ] < $limits[ $pattern ] ) {
					$next_pattern = $pattern;
					++$counts[ $pattern ];
					break;
				}
			}
			if ( null === $next_pattern && ! in_array( 'core/paragraph', $allowed_blocks, true ) ) {
				throw new Execution_Exception( 'admin_creation_slot_default_missing', 'A required native-editor slot needs remaining approved-pattern capacity or an allowed paragraph block.' );
			}
			$additions[] = $next_pattern;
		}
		foreach ( $additions as $pattern ) {
			if ( null !== $pattern ) {
				$children[] = $this->admin_default_pattern_instance( $pattern, $blueprint, $depth + 1 );
				continue;
			}
			$children[] = array(
				'blockName'    => 'core/paragraph',
				'attrs'        => array(),
				'innerBlocks'  => array(),
				'innerHTML'    => '<p></p>',
				'innerContent' => array( '<p></p>' ),
			);
		}
		return $children;
	}

	/** Materialize one valid repeatable child pattern for a required slot. */
	private function admin_default_pattern_instance( string $pattern, array $blueprint, int $depth ): array {
		$instance = $this->synced_patterns->materialize_instance( $pattern, array(), $blueprint, true );
		$seeded   = $this->seed_admin_minimum_slots( array( $instance['block'] ), $blueprint, $depth );
		if ( 1 !== count( $seeded ) || ! is_array( $seeded[0] ?? null ) ) {
			throw new Execution_Exception( 'admin_creation_pattern_default_invalid', 'A required native-editor child pattern could not be materialized.' );
		}
		return $seeded[0];
	}

	/** Build a validated, per-instance starting block for the native slot editor. */
	public function admin_slot_pattern_instance( string $pattern, array $blueprint ): array {
		return $this->admin_default_pattern_instance( $pattern, $blueprint, 0 );
	}

	/** Replace one raw document block addressed by its zero-based block path. */
	private function replace_block_at_path( array $blocks, array $path, array $replacement ): array {
		$index = array_shift( $path );
		if ( ! is_int( $index ) && ! ctype_digit( (string) $index ) ) {
			throw new Execution_Exception( 'admin_creation_slot_owner_invalid', 'A required native-editor slot has no stable owner path.' );
		}
		$index = (int) $index;
		if ( ! isset( $blocks[ $index ] ) || ! is_array( $blocks[ $index ] ) ) {
			throw new Execution_Exception( 'admin_creation_slot_owner_invalid', 'A required native-editor slot owner no longer exists.' );
		}
		if ( empty( $path ) ) {
			$blocks[ $index ] = $replacement;
			return $blocks;
		}
		$children = is_array( $blocks[ $index ]['innerBlocks'] ?? null ) ? $blocks[ $index ]['innerBlocks'] : array();
		$blocks[ $index ]['innerBlocks'] = $this->replace_block_at_path( $children, $path, $replacement );
		return $blocks;
	}

	/**
	 * Store the approved pattern identity on its native root block.
	 *
	 * Raw HTML marker comments become separate Classic blocks in Gutenberg.
	 * Legacy patterns use metadata.name. A pattern that already uses that field
	 * for a Structure Contract node keeps it and stores the source identity in
	 * Composer's namespace. WordPress reserves metadata.patternName for native
	 * pattern editing; setting it on a materialized extension-slot wrapper makes
	 * Gutenberg treat the wrapper as an indivisible pattern and swallow nested
	 * slot interaction.
	 */
	private function add_pattern_metadata( string $content, string $pattern ): string {
		$blocks     = parse_blocks( trim( $content ) );
		$root_index = null;

		foreach ( $blocks as $index => $block ) {
			$name = $block['blockName'] ?? null;
			if ( null === $name ) {
				if ( '' !== trim( (string) ( $block['innerHTML'] ?? '' ) ) ) {
					throw new Execution_Exception( 'invalid_pattern_root', 'An approved pattern contains top-level freeform content.' );
				}
				continue;
			}

			if ( null !== $root_index ) {
				throw new Execution_Exception( 'invalid_pattern_root', 'An approved pattern must contain exactly one root block.' );
			}
			$root_index = $index;
		}

		if ( null === $root_index ) {
			throw new Execution_Exception( 'invalid_pattern_root', 'An approved pattern must contain one native Gutenberg root block.' );
		}

		$root     = $blocks[ $root_index ];
		$attrs    = isset( $root['attrs'] ) && is_array( $root['attrs'] ) ? $root['attrs'] : array();
		$metadata = isset( $attrs['metadata'] ) && is_array( $attrs['metadata'] ) ? $attrs['metadata'] : array();

		$existing_name = isset( $metadata['name'] ) ? trim( (string) $metadata['name'] ) : '';
		if ( '' === $existing_name || $pattern === $existing_name ) {
			$metadata['name'] = $pattern;
		} else {
			$namespaced = isset( $metadata['wpsuiteAgentComposer'] ) && is_array( $metadata['wpsuiteAgentComposer'] )
				? $metadata['wpsuiteAgentComposer']
				: array();
			$namespaced['patternName'] = $pattern;
			$metadata['wpsuiteAgentComposer'] = $namespaced;
			unset( $metadata['patternName'] );
		}
		$attrs['metadata'] = $metadata;
		$root['attrs']      = $attrs;

		$serialized  = serialize_block( $root );
		$round_trip  = parse_blocks( $serialized );
		$stored_name = isset( $round_trip[0]['attrs']['metadata']['name'] )
			? (string) $round_trip[0]['attrs']['metadata']['name']
			: '';
		$stored_namespace = isset( $round_trip[0]['attrs']['metadata']['wpsuiteAgentComposer'] ) && is_array( $round_trip[0]['attrs']['metadata']['wpsuiteAgentComposer'] )
			? $round_trip[0]['attrs']['metadata']['wpsuiteAgentComposer']
			: array();
		$stored_pattern = isset( $stored_namespace['patternName'] )
			? (string) $stored_namespace['patternName']
			: $stored_name;
		if (
			1 !== count( $round_trip )
			|| null === ( $round_trip[0]['blockName'] ?? null )
			|| ! hash_equals( $pattern, $stored_pattern )
		) {
			throw new Execution_Exception( 'invalid_pattern_metadata', 'The approved pattern identity did not survive native block serialization.' );
		}

		return $serialized;
	}

	private function replace_placeholders( string $content, array $fields, array $semantic_keys ): string {
		if ( count( $fields ) > 100 ) {
			throw new Execution_Exception( 'too_many_fields', 'A section cannot contain more than 100 fields.' );
		}

		$replacements = array();
		foreach ( $fields as $key => $value ) {
			$original_key = (string) $key;
			$key          = sanitize_key( $original_key );
			if ( '' === $key || $original_key !== $key ) {
				throw new Execution_Exception( 'invalid_pattern_field', 'Pattern fields must use slug keys.' );
			}
			if ( ! is_scalar( $value ) ) {
				if ( ! in_array( $key, $semantic_keys, true ) || ! is_array( $value ) ) {
					throw new Execution_Exception( 'invalid_pattern_field', 'Structured pattern fields must match a declared semantic slot.' );
				}
				continue;
			}
			$value = (string) $value;
			if ( strlen( $value ) > 20000 ) {
				throw new Execution_Exception( 'pattern_field_too_long', 'A pattern field exceeds the 20,000 byte limit.' );
			}

			$replacements[ '{{wpsuite:text:' . $key . '}}' ] = esc_html( $value );
			$replacements[ '{{wpsuite:attr:' . $key . '}}' ] = esc_attr( $value );
			$replacements[ '{{wpsuite:url:' . $key . '}}' ]  = esc_url( $value, array( 'https', 'http', 'mailto', 'tel' ) );
			$replacements[ '{{wpsuite:json:' . $key . '}}' ] = $this->json_string_fragment( $value );
		}

		return strtr( $content, $replacements );
	}

	private function json_string_fragment( string $value ): string {
		$json = wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $json ) || strlen( $json ) < 2 ) {
			throw new Execution_Exception( 'json_encoding_failed', 'A pattern field could not be encoded safely.' );
		}
		return substr( $json, 1, -1 );
	}

	private function assert_no_unresolved_placeholders( string $content ): void {
		if ( preg_match( '/\{\{wpsuite:(text|attr|url|json):[a-z0-9_-]+\}\}/', $content ) ) {
			throw new Execution_Exception( 'missing_pattern_field', 'A required pattern field was not supplied.' );
		}
	}

	private function assert_required_sequence( array $required, array $actual ): void {
		if ( empty( $required ) ) {
			return;
		}
		$position = 0;
		foreach ( $actual as $pattern ) {
			if ( isset( $required[ $position ] ) && $required[ $position ] === $pattern ) {
				++$position;
			}
		}
		if ( $position !== count( $required ) ) {
			throw new Execution_Exception( 'required_sequence_missing', 'The page does not contain the blueprint required pattern sequence.' );
		}
	}

	private function assert_namespace_allowed( string $pattern ): void {
		$namespace = strstr( $pattern, '/', true );
		$policy    = $this->config->get_design_policy();
		if ( ! in_array( $namespace, $policy['allowed_pattern_namespaces'], true ) ) {
			throw new Execution_Exception( 'pattern_namespace_not_allowed', 'The pattern namespace is not allowed by the design policy.' );
		}
	}
}
