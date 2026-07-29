<?php
/* SmartCloud Agent Composer execution contract. */

namespace SmartCloud\AgentComposer\Execution;

final class Pattern_Assembler {
	private Config_Repository $config;
	private Pattern_Repository $patterns;

	public function __construct( Config_Repository $config, Pattern_Repository $patterns ) {
		$this->config   = $config;
		$this->patterns = $patterns;
	}

	/**
	 * Assemble approved registered patterns with context-aware placeholders.
	 * Supported tokens: {{wpsuite:text:key}}, {{wpsuite:attr:key}},
	 * {{wpsuite:url:key}}, and {{wpsuite:json:key}}.
	 */
	public function assemble( string $page_type, array $sections ): array {
		$blueprint = $this->config->get_blueprint( $page_type );
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
			$registered = $this->patterns->resolve_approved( $pattern, $blueprint );
			if ( ! is_array( $registered ) || empty( $registered['content'] ) ) {
				throw new Execution_Exception( 'pattern_not_registered', 'An approved pattern is not registered by the active theme or a plugin.' );
			}

			$fields = isset( $section['fields'] ) && is_array( $section['fields'] ) ? $section['fields'] : array();
			$source = preg_replace(
				'/<!--\s*wpsuite-agent-section:[a-z0-9-]+\/[a-z0-9-]+\s*-->/',
				'',
				(string) $registered['content']
			);
			$rendered = $this->replace_placeholders( is_string( $source ) ? $source : (string) $registered['content'], $fields );
			$this->assert_no_unresolved_placeholders( $rendered );
			$sequence[] = $pattern;
			$content   .= $this->add_pattern_metadata( $rendered, $pattern );
		}

		$this->assert_required_sequence( $blueprint['required_sequence'], $sequence );
		return array(
			'content'  => trim( $content ),
			'sequence' => $sequence,
		);
	}

	/**
	 * Store the approved pattern identity on its native root block.
	 *
	 * Raw HTML marker comments become separate Classic blocks in Gutenberg.
	 * The supported block metadata name is non-rendering, survives editor saves,
	 * and gives the root block a useful label in List View.
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

		$metadata['name']  = $pattern;
		$attrs['metadata'] = $metadata;
		$root['attrs']      = $attrs;

		$serialized  = serialize_block( $root );
		$round_trip  = parse_blocks( $serialized );
		$stored_name = isset( $round_trip[0]['attrs']['metadata']['name'] )
			? (string) $round_trip[0]['attrs']['metadata']['name']
			: '';
		if (
			1 !== count( $round_trip )
			|| null === ( $round_trip[0]['blockName'] ?? null )
			|| ! hash_equals( $pattern, $stored_name )
		) {
			throw new Execution_Exception( 'invalid_pattern_metadata', 'The approved pattern identity did not survive native block serialization.' );
		}

		return $serialized;
	}

	private function replace_placeholders( string $content, array $fields ): string {
		if ( count( $fields ) > 100 ) {
			throw new Execution_Exception( 'too_many_fields', 'A section cannot contain more than 100 fields.' );
		}

		$replacements = array();
		foreach ( $fields as $key => $value ) {
			$original_key = (string) $key;
			$key          = sanitize_key( $original_key );
			if ( '' === $key || $original_key !== $key || ! is_scalar( $value ) ) {
				throw new Execution_Exception( 'invalid_pattern_field', 'Pattern fields must use slug keys and scalar values.' );
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
