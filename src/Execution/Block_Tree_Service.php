<?php
/* SmartCloud Agent Composer execution contract. */

namespace SmartCloud\AgentComposer\Execution;

/**
 * Generic canonical Gutenberg block validation and composition.
 *
 * Product-specific schemas, domain validation, and saved-markup materializers
 * belong to registered product ability providers. This service never invents
 * a wrapper for a non-core block.
 */
final class Block_Tree_Service {
	private const MAX_BLOCKS = 500;
	private const MAX_DEPTH = 24;
	private const MAX_CONTENT_BYTES = 1000000;

	private Block_Catalog $catalog;
	private Config_Repository $config;
	private Ability_Provider_Registry $providers;

	public function __construct(
		Block_Catalog $catalog,
		Config_Repository $config,
		Ability_Provider_Registry $providers
	) {
		$this->catalog   = $catalog;
		$this->config    = $config;
		$this->providers = $providers;
	}

	public function validate( array $blocks, ?string $page_type = null ): array {
		$errors   = array();
		$warnings = array();
		$count    = 0;
		$names    = array();
		$prepared = array();
		$content  = '';

		try {
			$normalized = $this->normalize_blocks( $blocks );
			$blueprint  = null;
			if ( null !== $page_type && '' !== $page_type ) {
				$blueprint = $this->config->get_blueprint( sanitize_key( $page_type ) );
			}
			$this->validate_nodes( $normalized, null, array(), $blueprint, $errors, $count, $names, 0 );

			if ( empty( $errors ) ) {
				$prepared           = $this->prepare_nodes( $normalized );
				$provider_validation = $this->providers->validate_block_trees( $prepared );
				$errors              = array_merge( $errors, $provider_validation['errors'] );
				$warnings            = array_merge( $warnings, $provider_validation['warnings'] );
			}

			if ( empty( $errors ) ) {
				$content = serialize_blocks( $prepared );
				if ( strlen( $content ) > self::MAX_CONTENT_BYTES ) {
					$errors[] = $this->issue( 'block_tree_too_large', 'The serialized block tree exceeds the 1 MB limit.' );
				} else {
					$this->validate_round_trip( $prepared, $content, $errors );
				}
			}
		} catch ( Execution_Exception $error ) {
			$errors[] = $this->issue( $error->get_execution_code(), $error->getMessage() );
		}

		return array(
			'valid'      => empty( $errors ),
			'errors'     => array_values( $errors ),
			'warnings'   => array_values( $warnings ),
			'blocks'     => $prepared,
			'content'    => $content,
			'statistics' => array(
				'block_count' => $count,
				'block_names' => array_values( array_unique( $names ) ),
			),
		);
	}

	public function compile( array $blocks, ?string $page_type = null ): array {
		$result = $this->validate( $blocks, $page_type );
		if ( ! $result['valid'] ) {
			$first = $result['errors'][0] ?? array(
				'code'    => 'block_tree_invalid',
				'message' => 'The block tree is invalid.',
			);
			throw new Execution_Exception(
				(string) $first['code'], // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data is returned by an Ability and is not rendered as HTML.
				(string) $first['message'] // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data is returned by an Ability and is not rendered as HTML.
			);
		}
		return $result;
	}

	public function apply_to_content(
		string $existing_content,
		array $new_blocks,
		string $mode,
		array $path,
		string $page_type
	): array {
		$current = $this->canonicalize_parsed_blocks( parse_blocks( $existing_content ) );
		$mode     = strtolower( trim( $mode ) );
		foreach ( $path as $index ) {
			if ( ! is_int( $index ) || $index < 0 ) {
				throw new Execution_Exception( 'invalid_block_path', 'Every target block path segment must be a non-negative integer.' );
			}
		}
		$path = array_values( $path );

		if ( ! in_array( $mode, array( 'append', 'prepend', 'insert-before', 'insert-after', 'replace' ), true ) ) {
			throw new Execution_Exception( 'invalid_block_update_mode', 'The mode must be append, prepend, insert-before, insert-after, or replace.' );
		}
		if ( empty( $new_blocks ) && 'replace' !== $mode ) {
			throw new Execution_Exception( 'empty_block_replacement_required', 'An empty blocks array may be used only with replace to remove the targeted block.' );
		}

		$compiled = $this->compile( $new_blocks, $page_type );
		if ( empty( $path ) ) {
			if ( 'append' === $mode ) {
				$current = array_merge( $current, $compiled['blocks'] );
			} elseif ( 'prepend' === $mode ) {
				$current = array_merge( $compiled['blocks'], $current );
			} else {
				throw new Execution_Exception( 'block_path_required', 'This update mode requires a target block path.' );
			}
		} else {
			$this->apply_at_path( $current, $compiled['blocks'], $mode, $path );
		}

		$validation = $this->validate( $current, $page_type );
		if ( ! $validation['valid'] ) {
			$first = $validation['errors'][0] ?? array(
				'code'    => 'block_tree_invalid',
				'message' => 'The updated block tree is invalid.',
			);
			throw new Execution_Exception(
				(string) $first['code'], // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data is returned by an Ability and is not rendered as HTML.
				(string) $first['message'] // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data is returned by an Ability and is not rendered as HTML.
			);
		}
		return $validation;
	}

	/**
	 * Convert WordPress parser output to the explicit block shape used by the
	 * Execution validator.
	 *
	 * Classic content saved without a wp:freeform delimiter is returned by
	 * parse_blocks() with a null blockName. It is existing passive content, not
	 * a malformed MCP block, so canonicalize it before validating a targeted
	 * update. New MCP input still passes through normalize_blocks() unchanged
	 * and therefore still requires an explicit valid blockName or name.
	 */
	private function canonicalize_parsed_blocks( array $blocks ): array {
		$canonical = array();
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}

			$name = $block['blockName'] ?? null;
			if ( null === $name ) {
				$inner_html = (string) ( $block['innerHTML'] ?? '' );
				if ( '' === trim( $inner_html ) ) {
					continue;
				}

				$canonical[] = array(
					'blockName'    => 'core/freeform',
					'attrs'        => array(),
					'innerBlocks'  => array(),
					'innerHTML'    => $inner_html,
					'innerContent' => array( $inner_html ),
				);
				continue;
			}

			$children             = isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] )
				? $block['innerBlocks']
				: array();
			$block['innerBlocks'] = $this->canonicalize_parsed_blocks( $children );
			$canonical[]          = $block;
		}

		return $canonical;
	}

	public function normalize_blocks( array $blocks ): array {
		$count = 0;
		return $this->normalize_block_list( $blocks, 0, $count );
	}

	private function normalize_block_list( array $blocks, int $depth, int &$count ): array {
		if ( ! array_is_list( $blocks ) ) {
			throw new Execution_Exception( 'invalid_block_tree', 'The blocks input must be a JSON array.' );
		}
		if ( $depth > self::MAX_DEPTH ) {
			throw new Execution_Exception( 'block_tree_too_deep', 'The block tree exceeds the maximum nesting depth.' );
		}
		$result = array();
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				throw new Execution_Exception( 'invalid_block_node', 'Every block node must be an object.' );
			}
			if ( ++$count > self::MAX_BLOCKS ) {
				throw new Execution_Exception( 'too_many_blocks', 'The block tree exceeds the 500 block limit.' );
			}
			$result[] = $this->normalize_node( $block, $depth, $count );
		}
		return $result;
	}

	private function normalize_node( array $block, int $depth, int &$count ): array {
		$allowed_keys = array( 'name', 'blockName', 'attributes', 'attrs', 'innerBlocks', 'innerHTML', 'innerContent' );
		if ( array_diff( array_keys( $block ), $allowed_keys ) ) {
			throw new Execution_Exception( 'unknown_block_node_property', 'A block node contains an unknown top-level property.' );
		}
		if (
			array_key_exists( 'name', $block )
			&& array_key_exists( 'blockName', $block )
			&& $block['name'] !== $block['blockName']
		) {
			throw new Execution_Exception( 'conflicting_block_name', 'A block node contains conflicting name and blockName values.' );
		}
		if (
			array_key_exists( 'attributes', $block )
			&& array_key_exists( 'attrs', $block )
			&& $block['attributes'] !== $block['attrs']
		) {
			throw new Execution_Exception( 'conflicting_block_attributes', 'A block node contains conflicting attributes and attrs values.' );
		}

		$canonical_shape = array_key_exists( 'blockName', $block )
			&& is_string( $block['blockName'] )
			&& array_key_exists( 'attrs', $block )
			&& is_array( $block['attrs'] )
			&& ( empty( $block['attrs'] ) || ! array_is_list( $block['attrs'] ) )
			&& array_key_exists( 'innerBlocks', $block )
			&& is_array( $block['innerBlocks'] )
			&& array_is_list( $block['innerBlocks'] )
			&& array_key_exists( 'innerHTML', $block )
			&& is_string( $block['innerHTML'] )
			&& array_key_exists( 'innerContent', $block )
			&& is_array( $block['innerContent'] )
			&& array_is_list( $block['innerContent'] );
		$name = $block['blockName'] ?? $block['name'] ?? '';
		$name = strtolower( trim( (string) $name ) );
		if ( ! preg_match( '#^[a-z0-9-]+/[a-z0-9-]+$#', $name ) ) {
			throw new Execution_Exception( 'invalid_block_name', 'Every block requires a valid blockName or name.' );
		}

		$attrs = $block['attrs'] ?? $block['attributes'] ?? array();
		if ( ! is_array( $attrs ) || ( ! empty( $attrs ) && array_is_list( $attrs ) ) ) {
			throw new Execution_Exception( 'invalid_block_attributes', 'Block attrs or attributes must be a JSON object.' );
		}

		$children = $block['innerBlocks'] ?? array();
		if ( ! is_array( $children ) || ! array_is_list( $children ) ) {
			throw new Execution_Exception( 'invalid_inner_blocks', 'innerBlocks must be a JSON array.' );
		}

		$inner_content = $block['innerContent'] ?? null;
		if ( null !== $inner_content ) {
			if ( ! is_array( $inner_content ) || ! array_is_list( $inner_content ) ) {
				throw new Execution_Exception( 'invalid_inner_content', 'innerContent must be an array of strings and null child placeholders.' );
			}
			foreach ( $inner_content as $part ) {
				if ( null !== $part && ! is_string( $part ) ) {
					throw new Execution_Exception( 'invalid_inner_content', 'innerContent may contain only strings and null child placeholders.' );
				}
			}
			if ( count( array_filter( $inner_content, static fn( mixed $part ): bool => null === $part ) ) !== count( $children ) ) {
				throw new Execution_Exception( 'inner_content_child_mismatch', 'innerContent must contain exactly one null placeholder for every inner block.' );
			}
		}

		$inner_html = $block['innerHTML'] ?? '';
		if ( ! is_string( $inner_html ) ) {
			throw new Execution_Exception( 'invalid_inner_html', 'innerHTML must be a string.' );
		}

		return array(
			'blockName'       => $name,
			'attrs'           => $attrs,
			'innerBlocks'     => $this->normalize_block_list( $children, $depth + 1, $count ),
			'innerHTML'       => $inner_html,
			'innerContent'    => $inner_content,
			'_canonical_shape' => $canonical_shape,
		);
	}

	private function validate_nodes(
		array $nodes,
		?array $parent,
		array $ancestors,
		?array $blueprint,
		array &$errors,
		int &$count,
		array &$names,
		int $depth
	): void {
		if ( $depth > self::MAX_DEPTH ) {
			$errors[] = $this->issue( 'block_tree_too_deep', 'The block tree exceeds the maximum nesting depth.' );
			return;
		}

		foreach ( $nodes as $node ) {
			++$count;
			$name    = $node['blockName'];
			$names[] = $name;
			if ( $count > self::MAX_BLOCKS ) {
				$errors[] = $this->issue( 'too_many_blocks', 'The block tree exceeds the 500 block limit.' );
				return;
			}

			try {
				if ( ! $this->catalog->is_allowed( $name, $blueprint ) ) {
					$errors[] = $this->issue(
						'block_not_allowed',
						'The active theme, selected blueprint, or registered ability providers do not allow this block.',
						array( 'block' => $name )
					);
					continue;
				}
				$schema = $this->catalog->get( $name );
			} catch ( Execution_Exception $error ) {
				$errors[] = $this->issue( $error->get_execution_code(), $error->getMessage(), array( 'block' => $name ) );
				continue;
			}

			$this->validate_attributes( $name, $node['attrs'], $schema['attributes'], $errors );
			$this->validate_registered_block_contract( $name, $node['attrs'], $schema['composer_contract'] ?? array(), $errors );
			$this->validate_relationships( $name, $schema, $parent, $ancestors, $errors );
			$this->validate_raw_html( $name, (string) $node['innerHTML'], $errors );
			if ( 'core/image' === $name ) {
				$this->validate_core_image_markup( $node, $errors );
			}

			$next_ancestors   = $ancestors;
			$next_ancestors[] = $node;
			$this->validate_nodes(
				$node['innerBlocks'],
				$node,
				$next_ancestors,
				$blueprint,
				$errors,
				$count,
				$names,
				$depth + 1
			);
		}
	}

	private function validate_attributes( string $name, array $attrs, array $definitions, array &$errors ): void {
		$encoded = wp_json_encode( $attrs );
		if ( ! is_string( $encoded ) || strlen( $encoded ) > 250000 ) {
			$errors[] = $this->issue( 'block_attributes_too_large', 'A block attribute object exceeds the 250 KB limit.', array( 'block' => $name ) );
			return;
		}

		$standard = array( 'anchor', 'className', 'classNames', 'lock', 'metadata', 'style', 'align' );
		foreach ( $attrs as $attribute => $value ) {
			if ( ! is_string( $attribute ) || ! preg_match( '/^[A-Za-z][A-Za-z0-9_-]{0,127}$/', $attribute ) ) {
				$errors[] = $this->issue( 'invalid_attribute_name', 'A block contains an invalid attribute name.', array( 'block' => $name ) );
				continue;
			}
			if ( ! isset( $definitions[ $attribute ] ) && ! in_array( $attribute, $standard, true ) ) {
				$errors[] = $this->issue(
					'unknown_block_attribute',
					'The block contains an attribute not declared by its registered schema.',
					array( 'block' => $name, 'attribute' => $attribute )
				);
				continue;
			}
			if ( isset( $definitions[ $attribute ] ) && is_array( $definitions[ $attribute ] ) ) {
				$this->validate_schema_value( $value, $definitions[ $attribute ], $name . '.' . $attribute, $errors );
			}
		}
	}

	private function validate_schema_value( mixed $value, array $schema, string $path, array &$errors ): void {
		$type       = (string) ( $schema['type'] ?? '' );
		$valid_type = match ( $type ) {
			'string'  => is_string( $value ),
			'boolean' => is_bool( $value ),
			'number'  => is_int( $value ) || is_float( $value ),
			'integer' => is_int( $value ),
			'array'   => is_array( $value ) && array_is_list( $value ),
			'object'  => is_array( $value ) && ( empty( $value ) || ! array_is_list( $value ) ),
			'null'    => null === $value,
			default   => true,
		};
		if ( ! $valid_type ) {
			$errors[] = $this->issue(
				'invalid_attribute_type',
				'A block attribute does not match its registered type.',
				array( 'attribute' => $path, 'expected' => $type )
			);
			return;
		}
		if ( isset( $schema['enum'] ) && is_array( $schema['enum'] ) && ! in_array( $value, $schema['enum'], true ) ) {
			$errors[] = $this->issue( 'invalid_attribute_enum', 'A block attribute is outside its registered enum.', array( 'attribute' => $path ) );
		}
		if ( array_key_exists( 'const', $schema ) && $value !== $schema['const'] ) {
			$errors[] = $this->issue( 'invalid_attribute_constant', 'A block attribute does not match its fixed Composer contract value.', array( 'attribute' => $path ) );
		}
		if ( ( is_int( $value ) || is_float( $value ) ) && isset( $schema['minimum'] ) && $value < $schema['minimum'] ) {
			$errors[] = $this->issue( 'attribute_below_minimum', 'A block attribute is below its Composer contract minimum.', array( 'attribute' => $path ) );
		}
		if ( ( is_int( $value ) || is_float( $value ) ) && isset( $schema['maximum'] ) && $value > $schema['maximum'] ) {
			$errors[] = $this->issue( 'attribute_above_maximum', 'A block attribute exceeds its Composer contract maximum.', array( 'attribute' => $path ) );
		}
		if ( 'array' === $type && isset( $schema['items'] ) && is_array( $schema['items'] ) ) {
			foreach ( $value as $index => $item ) {
				$this->validate_schema_value( $item, $schema['items'], $path . '[' . $index . ']', $errors );
			}
		}
		if ( 'object' === $type && isset( $schema['properties'] ) && is_array( $schema['properties'] ) ) {
			foreach ( $value as $key => $item ) {
				if ( isset( $schema['properties'][ $key ] ) && is_array( $schema['properties'][ $key ] ) ) {
					$this->validate_schema_value( $item, $schema['properties'][ $key ], $path . '.' . $key, $errors );
				}
			}
		}
	}

	private function validate_registered_block_contract( string $name, array $attrs, mixed $contract, array &$errors ): void {
		if ( ! is_array( $contract ) || empty( $contract ) ) {
			return;
		}
		foreach ( (array) ( $contract['attributes'] ?? array() ) as $attribute => $schema ) {
			if ( ! is_array( $schema ) ) {
				continue;
			}
			if ( ! array_key_exists( $attribute, $attrs ) ) {
				if ( ! empty( $schema['required'] ) ) {
					$errors[] = $this->issue( 'required_component_attribute_missing', 'A fixed registered-block contract attribute is missing.', array( 'block' => $name, 'attribute' => $attribute ) );
				}
				continue;
			}
			$this->validate_schema_value( $attrs[ $attribute ], $schema, $name . '.' . $attribute, $errors );
		}
	}

	private function validate_relationships(
		string $name,
		array $schema,
		?array $parent,
		array $ancestors,
		array &$errors
	): void {
		$parent_name = null === $parent ? '' : (string) $parent['blockName'];
		if ( ! empty( $schema['parent'] ) && ! in_array( $parent_name, $schema['parent'], true ) ) {
			$errors[] = $this->issue(
				'invalid_block_parent',
				'The block is not under one of its registered direct parents.',
				array( 'block' => $name, 'parent' => $parent_name )
			);
		}
		if ( ! empty( $schema['ancestor'] ) ) {
			$ancestor_names = array_map( static fn( array $item ): string => (string) $item['blockName'], $ancestors );
			if ( empty( array_intersect( $schema['ancestor'], $ancestor_names ) ) ) {
				$errors[] = $this->issue( 'required_block_ancestor_missing', 'The block is missing a registered ancestor.', array( 'block' => $name ) );
			}
		}
		if ( null !== $parent ) {
			try {
				$parent_schema = $this->catalog->get( (string) $parent['blockName'] );
				if ( ! empty( $parent_schema['allowed_blocks'] ) && ! in_array( $name, $parent_schema['allowed_blocks'], true ) ) {
					$errors[] = $this->issue(
						'parent_disallows_block',
						'The parent block does not allow this direct child.',
						array( 'block' => $name, 'parent' => $parent['blockName'] )
					);
				}
			} catch ( Execution_Exception $error ) {
				// The parent itself receives a stable registry error elsewhere.
			}
		}
	}

	private function validate_raw_html( string $name, string $html, array &$errors ): void {
		if ( preg_match( '/<\?(?:php|=)/i', $html ) ) {
			$errors[] = $this->issue( 'php_content_forbidden', 'PHP code is forbidden in every block.', array( 'block' => $name ) );
		}
		if ( 'core/html' === $name ) {
			$errors[] = $this->issue( 'custom_html_forbidden', 'Custom HTML blocks are not accepted by Agent Composer.', array( 'block' => $name ) );
			return;
		}
		if ( 'core/freeform' === $name ) {
			if ( preg_match( '/<(?:script|iframe|object|embed|form|link|meta|base)\b|<[^>]+\son[a-z]+\s*=|(?:href|src)\s*=\s*["\']?\s*javascript\s*:/i', $html ) ) {
				$errors[] = $this->issue( 'active_freeform_content_forbidden', 'Classic/Text Editor blocks may contain passive comparison-table HTML, but not scripts, frames, forms, event handlers, or javascript URLs.' );
			}
			return;
		}
		if ( preg_match( '/<script\b|<[^>]+\son[a-z]+\s*=|(?:href|src)\s*=\s*["\']?\s*javascript\s*:/i', $html ) ) {
			$errors[] = $this->issue( 'active_content_forbidden', 'JavaScript and event handlers are forbidden in Agent Composer content.', array( 'block' => $name ) );
		}
	}

	private function validate_core_image_markup( array $node, array &$errors ): void {
		$html  = trim( (string) ( $node['innerHTML'] ?? '' ) );
		$attrs = isset( $node['attrs'] ) && is_array( $node['attrs'] ) ? $node['attrs'] : array();
		if (
			! preg_match( '/^<figure\b[^>]*>[\s\S]*<\/figure>$/i', $html )
			|| 1 !== preg_match_all( '/<img\b[^>]*>/i', $html )
		) {
			$errors[] = $this->issue( 'core_image_markup_invalid', 'A materialized core/image block must contain one Image inside one Figure wrapper.' );
			return;
		}

		preg_match( '/^<figure\b[^>]*>/i', $html, $figure_match );
		preg_match( '/<img\b[^>]*>/i', $html, $image_match );
		$figure_tag     = (string) ( $figure_match[0] ?? '' );
		$image_tag      = (string) ( $image_match[0] ?? '' );
		$figure_classes = $this->html_class_tokens( $this->html_tag_attribute( $figure_tag, 'class' ) );
		$image_classes  = $this->html_class_tokens( $this->html_tag_attribute( $image_tag, 'class' ) );
		if ( ! in_array( 'wp-block-image', $figure_classes, true ) ) {
			$errors[] = $this->issue( 'core_image_wrapper_class_missing', 'The core/image Figure is missing wp-block-image.' );
		}

		$attachment_id = isset( $attrs['id'] ) ? (int) $attrs['id'] : 0;
		if ( $attachment_id > 0 && ! in_array( 'wp-image-' . $attachment_id, $image_classes, true ) ) {
			$errors[] = $this->issue( 'core_image_attachment_class_mismatch', 'The core/image attachment ID does not match the saved Image class.' );
		}
		$size_slug = isset( $attrs['sizeSlug'] ) ? (string) $attrs['sizeSlug'] : '';
		if ( '' !== $size_slug && ! in_array( 'size-' . $size_slug, $figure_classes, true ) ) {
			$errors[] = $this->issue( 'core_image_size_class_mismatch', 'The core/image sizeSlug does not match the saved Figure class.' );
		}

		$custom_classes = $this->html_class_tokens( isset( $attrs['className'] ) ? (string) $attrs['className'] : '' );
		if ( array_diff( $custom_classes, $figure_classes ) ) {
			$errors[] = $this->issue( 'core_image_custom_class_mismatch', 'The core/image className tokens must also exist on the saved Figure.' );
		}

		foreach ( array( 'width', 'height' ) as $dimension ) {
			if ( null !== $this->html_tag_attribute( $image_tag, $dimension ) && ! array_key_exists( $dimension, $attrs ) ) {
				$errors[] = $this->issue(
					'core_image_dimension_attribute_mismatch',
					'The core/image saved HTML contains a dimension missing from the block attributes.',
					array( 'dimension' => $dimension )
				);
			}
		}

		$link_destination = isset( $attrs['linkDestination'] ) ? (string) $attrs['linkDestination'] : 'none';
		$has_direct_link  = 1 === preg_match( '/^<figure\b[^>]*>\s*<a\b/i', $html );
		if ( 'none' === $link_destination && $has_direct_link ) {
			$errors[] = $this->issue( 'core_image_link_destination_mismatch', 'A core/image with linkDestination none cannot wrap its Image in a link.' );
		} elseif ( in_array( $link_destination, array( 'media', 'attachment' ), true ) && ! $has_direct_link ) {
			$errors[] = $this->issue( 'core_image_link_destination_mismatch', 'A linked core/image must contain a direct Figure link.' );
		}

		if ( preg_match( '/(<figcaption\b[^>]*>)/i', $html, $caption_match ) ) {
			$caption_classes = $this->html_class_tokens( $this->html_tag_attribute( (string) $caption_match[1], 'class' ) );
			if ( ! in_array( 'wp-element-caption', $caption_classes, true ) ) {
				$errors[] = $this->issue( 'core_image_caption_class_missing', 'A core/image caption must use the canonical wp-element-caption class.' );
			}
		}
	}

	private function html_tag_attribute( string $tag, string $attribute ): ?string {
		$attribute = preg_quote( $attribute, '/' );
		if ( ! preg_match( '/\s' . $attribute . '\s*=\s*(["\'])(.*?)\1/is', $tag, $match ) ) {
			return null;
		}

		return html_entity_decode( (string) $match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	private function html_class_tokens( ?string $classes ): array {
		if ( null === $classes || '' === trim( $classes ) ) {
			return array();
		}

		return array_values( array_filter( preg_split( '/\s+/', trim( $classes ) ) ?: array() ) );
	}

	private function prepare_nodes( array $nodes ): array {
		$prepared = array();
		foreach ( $nodes as $node ) {
			$prepared[] = $this->prepare_node( $node );
		}
		return $prepared;
	}

	private function prepare_node( array $node ): array {
		$name          = (string) $node['blockName'];
		$children      = $this->prepare_nodes( $node['innerBlocks'] );
		$inner_html    = (string) $node['innerHTML'];
		$inner_content = $node['innerContent'];
		$provider      = $this->providers->provider_for_block( $name );

		if (
			is_array( $provider )
			&& ( empty( $node['_canonical_shape'] ) || ! is_array( $inner_content ) )
		) {
			throw new Execution_Exception(
				'provider_canonical_block_required',
				'Product blocks must come from their registered materialize-component ability with every canonical WordPress parser key.'
			);
		}
		if ( ! is_array( $inner_content ) ) {
			if ( empty( $children ) ) {
				$inner_content = '' === $inner_html ? array() : array( $inner_html );
			} else {
				throw new Execution_Exception(
					'core_inner_content_required',
					'Core blocks with children require canonical innerContent so the saved wrapper is preserved.'
				);
			}
		}

		return array(
			'blockName'    => $name,
			'attrs'        => $node['attrs'],
			'innerBlocks'  => $children,
			'innerHTML'    => $inner_html,
			'innerContent' => $inner_content,
		);
	}

	private function validate_round_trip( array $prepared, string $content, array &$errors ): void {
		$parsed = parse_blocks( $content );
		if ( $this->shape( $prepared ) !== $this->shape( $parsed ) ) {
			$errors[] = $this->issue( 'block_round_trip_failed', 'The block tree did not survive WordPress parse/serialize round-trip.' );
		}
	}

	private function shape( array $nodes ): array {
		$result = array();
		foreach ( $nodes as $node ) {
			if ( null === ( $node['blockName'] ?? null ) ) {
				if ( '' !== trim( (string) ( $node['innerHTML'] ?? '' ) ) ) {
					$result[] = array( null, array() );
				}
				continue;
			}
			$result[] = array( $node['blockName'], $this->shape( $node['innerBlocks'] ?? array() ) );
		}
		return $result;
	}

	private function apply_at_path( array &$nodes, array $insert, string $mode, array $path ): void {
		if ( in_array( $mode, array( 'append', 'prepend' ), true ) ) {
			$target = &$this->node_at_path( $nodes, $path );
			$this->insert_children( $target, $insert, $mode );
			return;
		}

		$target_index = array_pop( $path );
		if ( ! is_int( $target_index ) ) {
			throw new Execution_Exception( 'invalid_block_path', 'The target block path is invalid.' );
		}
		if ( empty( $path ) ) {
			if ( ! isset( $nodes[ $target_index ] ) ) {
				throw new Execution_Exception( 'block_path_not_found', 'The target block path does not exist.' );
			}
			$offset = 'insert-after' === $mode ? $target_index + 1 : $target_index;
			$length = 'replace' === $mode ? 1 : 0;
			array_splice( $nodes, $offset, $length, $insert );
			return;
		}

		$parent = &$this->node_at_path( $nodes, $path );
		$this->splice_children( $parent, $target_index, $insert, $mode );
	}

	private function &node_at_path( array &$nodes, array $path ): array {
		$index = array_shift( $path );
		if ( ! is_int( $index ) || ! isset( $nodes[ $index ] ) ) {
			throw new Execution_Exception( 'block_path_not_found', 'The target block path does not exist.' );
		}
		if ( empty( $path ) ) {
			return $nodes[ $index ];
		}
		$node = &$this->node_at_path( $nodes[ $index ]['innerBlocks'], $path );
		return $node;
	}

	private function insert_children( array &$parent, array $insert, string $mode ): void {
		$positions = $this->child_placeholder_positions( $parent );
		if ( empty( $positions ) ) {
			throw new Execution_Exception(
				'canonical_child_insertion_unavailable',
				'The empty target block does not expose a canonical child insertion point. Replace the complete block instead.'
			);
		}

		$position = 'prepend' === $mode
			? $positions[0]
			: $positions[ count( $positions ) - 1 ] + 1;
		array_splice( $parent['innerContent'], $position, 0, array_fill( 0, count( $insert ), null ) );
		$parent['innerBlocks'] = 'prepend' === $mode
			? array_merge( $insert, $parent['innerBlocks'] )
			: array_merge( $parent['innerBlocks'], $insert );
	}

	private function splice_children( array &$parent, int $target_index, array $insert, string $mode ): void {
		if ( ! isset( $parent['innerBlocks'][ $target_index ] ) ) {
			throw new Execution_Exception( 'block_path_not_found', 'The target block path does not exist.' );
		}
		$positions = $this->child_placeholder_positions( $parent );
		$position  = $positions[ $target_index ] ?? null;
		if ( ! is_int( $position ) ) {
			throw new Execution_Exception( 'inner_content_child_mismatch', 'The target parent does not contain the canonical child placeholder.' );
		}

		$offset = 'insert-after' === $mode ? $target_index + 1 : $target_index;
		$length = 'replace' === $mode ? 1 : 0;
		array_splice( $parent['innerBlocks'], $offset, $length, $insert );

		$content_offset = 'insert-after' === $mode ? $position + 1 : $position;
		$content_length = 'replace' === $mode ? 1 : 0;
		array_splice( $parent['innerContent'], $content_offset, $content_length, array_fill( 0, count( $insert ), null ) );
	}

	private function child_placeholder_positions( array $parent ): array {
		$inner_content = $parent['innerContent'] ?? null;
		$inner_blocks  = $parent['innerBlocks'] ?? array();
		if ( ! is_array( $inner_content ) || ! is_array( $inner_blocks ) ) {
			throw new Execution_Exception( 'canonical_parent_required', 'The target parent requires canonical innerBlocks and innerContent.' );
		}

		$positions = array();
		foreach ( $inner_content as $position => $part ) {
			if ( null === $part ) {
				$positions[] = $position;
			}
		}
		if ( count( $positions ) !== count( $inner_blocks ) ) {
			throw new Execution_Exception( 'inner_content_child_mismatch', 'The target parent child placeholders do not match its inner blocks.' );
		}
		return $positions;
	}

	private function issue( string $code, string $message, array $context = array() ): array {
		return array(
			'code'    => $code,
			'message' => $message,
			'context' => $context,
		);
	}
}
