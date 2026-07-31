<?php
/* SmartCloud Agent Composer execution contract. */

namespace SmartCloud\AgentComposer\Execution;

final class Page_Validator {
	private Config_Repository $config;
	private Block_Catalog $catalog;
	private Block_Tree_Service $trees;
	private Semantic_Slot_Materializer $slots;
	private Content_Language_Validator $language;

	public function __construct(
		Config_Repository $config,
		Block_Catalog $catalog,
		Block_Tree_Service $trees,
		Semantic_Slot_Materializer $slots,
		Content_Language_Validator $language
	) {
		$this->config  = $config;
		$this->catalog = $catalog;
		$this->trees   = $trees;
		$this->slots   = $slots;
		$this->language = $language;
	}

	public function validate( string $page_type, string $content ): array {
		$blueprint = $this->config->get_blueprint( $page_type );
		$policy    = $this->config->get_design_policy();
		$errors    = array();
		$warnings  = array();
		if ( 'structured-record' === $blueprint['composition_mode'] ) {
			if ( '' !== trim( $content ) ) {
				$errors[] = $this->issue( 'structured_record_body_forbidden', 'Structured-record content must keep the Gutenberg body empty.' );
			}
			return array(
				'valid'      => empty( $errors ),
				'errors'     => $errors,
				'warnings'   => array(),
				'statistics' => array( 'word_count' => 0, 'block_count' => 0, 'h1_count' => 0, 'patterns' => array(), 'block_names' => array() ),
				'composition_mode' => 'structured-record',
				'content_language' => $blueprint['content_language'],
			);
		}

		if ( strlen( $content ) > 1000000 ) {
			$errors[] = $this->issue( 'content_too_large', 'Page content exceeds the 1 MB limit.' );
		}

		if ( preg_match( '/<\?(?:php|=)/i', $content ) ) {
			$errors[] = $this->issue( 'php_content_forbidden', 'PHP code is forbidden in every block.' );
		}

		$blocks = parse_blocks( $content );
		$stats  = array( 'h1' => 0, 'blocks' => 0, 'block_names' => array() );
		$this->validate_blocks( $blocks, $blueprint, $policy, $errors, $stats );
		if ( $this->contains_extension_blocks( $stats['block_names'] ) ) {
			$tree_input = array_values(
				array_filter(
					$blocks,
					static fn( array $block ): bool => null !== ( $block['blockName'] ?? null )
						|| '' !== trim( (string) ( $block['innerHTML'] ?? '' ) )
				)
			);
			$tree_validation = $this->trees->validate( $tree_input, $page_type );
			$errors          = array_merge( $errors, $tree_validation['errors'] );
			$warnings        = array_merge( $warnings, $tree_validation['warnings'] );
		}

		if ( ! empty( $blueprint['constraints']['exactly_one_h1'] ) && 1 !== $stats['h1'] ) {
			$errors[] = $this->issue( 'invalid_h1_count', 'The page must contain exactly one H1 heading.', array( 'found' => $stats['h1'] ) );
		}

		$word_count = str_word_count( wp_strip_all_tags( strip_shortcodes( $content ) ) );
		$maximum    = (int) $blueprint['constraints']['maximum_words'];
		if ( $word_count > $maximum ) {
			$errors[] = $this->issue( 'maximum_words_exceeded', 'The page exceeds its maximum word count.', array( 'found' => $word_count, 'maximum' => $maximum ) );
		}

		$sequence = $this->extract_sequence( $content );
		foreach ( $sequence as $pattern ) {
			if ( ! in_array( $pattern, $blueprint['allowed_patterns'], true ) ) {
				$errors[] = $this->issue( 'pattern_not_allowed', 'Content contains an unapproved pattern marker.', array( 'pattern' => $pattern ) );
			}
		}
		if ( ! $this->contains_required_subsequence( $blueprint['required_sequence'], $sequence ) ) {
			$errors[] = $this->issue( 'required_sequence_missing', 'The required pattern sequence is missing.' );
		}
		foreach ( $sequence as $pattern ) {
			try {
				$this->slots->assert_no_registered_fallbacks( $pattern, $content );
			} catch ( Execution_Exception $error ) {
				$errors[] = $this->issue( $error->get_execution_code(), $error->getMessage(), array( 'pattern' => $pattern ) );
			}
		}
		foreach ( $this->language->issues( $page_type, $content ) as $issue ) {
			$errors[] = $this->issue( (string) $issue['code'], (string) $issue['message'] );
		}

		return array(
			'valid'       => empty( $errors ),
			'errors'      => array_values( $errors ),
			'warnings'    => array_values( $warnings ),
			'statistics'  => array(
				'word_count'   => $word_count,
				'block_count'  => $stats['blocks'],
				'h1_count'     => $stats['h1'],
				'patterns'     => $sequence,
				'block_names'  => array_values( array_unique( $stats['block_names'] ) ),
			),
			'composition_mode' => 'document',
			'content_language' => $blueprint['content_language'],
		);
	}

	private function validate_blocks( array $blocks, array $blueprint, array $policy, array &$errors, array &$stats ): void {
		foreach ( $blocks as $block ) {
			$name = $block['blockName'] ?? null;
			if ( null === $name ) {
				$freeform = trim( preg_replace( '/<!--\s*wpsuite-agent-section:[a-z0-9-]+\/[a-z0-9-]+\s*-->/', '', (string) ( $block['innerHTML'] ?? '' ) ) );
				if ( '' !== $freeform ) {
					$errors[] = $this->issue( 'freeform_content_forbidden', 'Unwrapped freeform content is forbidden.' );
				}
				continue;
			}

			++$stats['blocks'];
			$stats['block_names'][] = $name;
			try {
				if ( ! $this->catalog->is_allowed( $name, $blueprint ) ) {
					$errors[] = $this->issue( 'block_not_allowed', 'Content contains a block that is not allowed.', array( 'block' => $name ) );
				}
			} catch ( Execution_Exception $error ) {
				$errors[] = $this->issue( $error->get_execution_code(), $error->getMessage(), array( 'block' => $name ) );
			}

			$attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
			$html  = (string) ( $block['innerHTML'] ?? '' );
			if ( in_array( $name, array( 'core/html', 'core/freeform' ), true ) ) {
				preg_match_all( '/<h1\b/i', $html, $raw_h1_matches );
				$stats['h1'] += count( $raw_h1_matches[0] ?? array() );
			}
			if ( 'core/html' === $name ) {
				$errors[] = $this->issue( 'custom_html_forbidden', 'Custom HTML blocks are not accepted by Agent Composer.' );
			} elseif ( 'core/freeform' === $name ) {
				if ( preg_match( '/<(?:script|iframe|object|embed|form|link|meta|base)\b|<[^>]+\son[a-z]+\s*=|(?:href|src)\s*=\s*["\']?\s*javascript\s*:/i', $html ) ) {
					$errors[] = $this->issue( 'active_freeform_content_forbidden', 'Classic/Text Editor blocks are limited to passive HTML such as comparison tables.' );
				}
			} else {
				if ( preg_match( '/<script\b|<[^>]+\son[a-z]+\s*=|(?:href|src)\s*=\s*["\']?\s*javascript\s*:/i', $html ) ) {
					$errors[] = $this->issue( 'active_content_forbidden', 'JavaScript and event handlers are forbidden in Agent Composer content.', array( 'block' => $name ) );
				}
				if ( empty( $blueprint['constraints']['inline_css'] ) && preg_match( '/\sstyle\s*=/i', $html ) ) {
					$errors[] = $this->issue( 'inline_css_forbidden', 'Raw inline CSS is forbidden outside Classic/Text Editor and Custom HTML blocks.', array( 'block' => $name ) );
				}
			}
			if (
				empty( $blueprint['constraints']['shortcodes'] )
				&& 'core/html' !== $name
				&& preg_match( '/\[[a-z][a-z0-9_-]*(?:\s|\]|\/)/i', wp_strip_all_tags( $html ) )
			) {
				$errors[] = $this->issue( 'shortcode_forbidden', 'Shortcodes are forbidden.', array( 'block' => $name ) );
			}
			if ( 'core/embed' === $name && empty( $blueprint['constraints']['external_embeds'] ) ) {
				$errors[] = $this->issue( 'external_embed_forbidden', 'External embeds are forbidden.', array( 'block' => $name ) );
			}
			if ( 'core/heading' === $name && 1 === (int) ( $attrs['level'] ?? 2 ) ) {
				++$stats['h1'];
			}
			if (
				! empty( $blueprint['constraints']['theme_presets_only'] )
				&& isset( $attrs['style'] )
				&& ! $this->style_uses_only_presets( $attrs['style'] )
			) {
				$errors[] = $this->issue( 'non_preset_style_forbidden', 'Block styles must use theme presets only.', array( 'block' => $name ) );
			}

			if ( ! empty( $block['innerBlocks'] ) ) {
				$this->validate_blocks( $block['innerBlocks'], $blueprint, $policy, $errors, $stats );
			}
		}
	}

	private function contains_extension_blocks( array $names ): bool {
		foreach ( $names as $name ) {
			$name = (string) $name;
			if ( in_array( $name, array( 'core/html', 'core/freeform' ), true ) || ! str_starts_with( $name, 'core/' ) ) {
				return true;
			}
		}
		return false;
	}

	private function style_uses_only_presets( mixed $style ): bool {
		if ( ! is_array( $style ) ) {
			return false;
		}
		foreach ( $style as $value ) {
			if ( is_array( $value ) ) {
				if ( ! $this->style_uses_only_presets( $value ) ) {
					return false;
				}
				continue;
			}
			if ( ! is_string( $value ) || ! preg_match( '/^(?:var:preset\|[a-z0-9-]+\|[a-z0-9-]+|var\(--wp--preset--[a-z0-9-]+--[a-z0-9-]+\))$/i', $value ) ) {
				return false;
			}
		}
		return true;
	}

	private function extract_sequence( string $content ): array {
		$sequence = array();
		foreach ( parse_blocks( $content ) as $block ) {
			if ( null === ( $block['blockName'] ?? null ) ) {
				continue;
			}

			$metadata = isset( $block['attrs']['metadata'] ) && is_array( $block['attrs']['metadata'] )
				? $block['attrs']['metadata']
				: array();
			$name = isset( $metadata['name'] ) ? strtolower( trim( (string) $metadata['name'] ) ) : '';
			if ( preg_match( '/^[a-z0-9-]+\/[a-z0-9-]+$/', $name ) ) {
				$sequence[] = $name;
			}
		}

		if ( ! empty( $sequence ) ) {
			return $sequence;
		}

		// Backward compatibility for drafts created before 0.2.1.
		preg_match_all( '/<!--\s*wpsuite-agent-section:([a-z0-9-]+\/[a-z0-9-]+)\s*-->/', $content, $matches );
		return isset( $matches[1] ) ? array_values( $matches[1] ) : array();
	}

	private function contains_required_subsequence( array $required, array $actual ): bool {
		if ( empty( $required ) ) {
			return true;
		}
		$position = 0;
		foreach ( $actual as $pattern ) {
			if ( isset( $required[ $position ] ) && $required[ $position ] === $pattern ) {
				++$position;
			}
		}
		return $position === count( $required );
	}

	private function issue( string $code, string $message, array $context = array() ): array {
		return array(
			'code'    => $code,
			'message' => $message,
			'context' => $context,
		);
	}
}
