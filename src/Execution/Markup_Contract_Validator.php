<?php
/* SmartCloud Agent Composer execution contract. */

namespace SmartCloud\AgentComposer\Execution;

final class Markup_Contract_Validator {
	private const ALWAYS_FORBIDDEN_BLOCKS = array(
		'core/spacer',
		'core/html',
		'core/shortcode',
		'core/freeform',
		'core/legacy-widget',
		'core/widget-group',
		'core/embed',
	);

	private Config_Repository $config;

	public function __construct( Config_Repository $config ) {
		$this->config = $config;
	}

	/**
	 * Validate one approved pattern against the active theme-owned contract.
	 *
	 * Missing contracts retain the 0.3.0 behavior. Once a contract is present,
	 * malformed contracts and non-conforming patterns fail closed.
	 */
	public function validate( string $pattern_name, string $content, array $blueprint ): array {
		$contract = $this->config->get_markup_contract();
		if ( null === $contract ) {
			return array( 'active' => false );
		}

		$blocks = parse_blocks( trim( $content ) );
		$root   = $this->single_native_root( $blocks, $pattern_name );

		if ( 'core/group' !== ( $root['blockName'] ?? null ) ) {
			$this->fail( 'invalid_pattern_root', $pattern_name, 'must use core/group as its native root block.' );
		}

		$root_attributes = $this->attributes( $root );
		if ( 'full' !== (string) ( $root_attributes['align'] ?? '' ) ) {
			$this->fail( 'invalid_pattern_root', $pattern_name, 'must keep its native root block alignfull.' );
		}

		$root_classes = $this->classes( $root );
		if ( empty( array_intersect( $root_classes, $contract['scope_classes'] ) ) ) {
			$this->fail( 'pattern_scope_class_missing', $pattern_name, 'is missing an allowed root scope class.' );
		}

		if ( preg_match( '/<[a-z][^>]*\sstyle\s*=/i', $content ) ) {
			$this->fail( 'pattern_layout_style_forbidden', $pattern_name, 'contains a raw inline style attribute.' );
		}

		$statistics = array(
			'h1'          => 0,
			'block_names' => array(),
		);
		$policy     = $this->config->get_design_policy();
		$forbidden  = array_values(
			array_unique(
				array_merge(
					self::ALWAYS_FORBIDDEN_BLOCKS,
					isset( $policy['disallowed_blocks'] ) && is_array( $policy['disallowed_blocks'] )
						? $policy['disallowed_blocks']
						: array()
				)
			)
		);
		$allowed    = isset( $blueprint['allowed_blocks'] ) && is_array( $blueprint['allowed_blocks'] )
			? $blueprint['allowed_blocks']
			: array();
		$this->validate_block_tree( array( $root ), $pattern_name, $forbidden, $allowed, $statistics );
		$this->validate_split_hero_intro( $root, $pattern_name, $contract );
		$this->validate_card_grids( $root, $pattern_name, $contract['role_classes']['card'] );
		$this->validate_intro_order(
			$root,
			$pattern_name,
			$contract['role_classes']['eyebrow_or_label'],
			$contract['role_classes']['lead']
		);
		$this->validate_post_pattern( $root, $pattern_name, $blueprint, $contract, $statistics );

		return array(
			'active'      => true,
			'root_block'  => 'core/group',
			'root_scope'  => array_values( array_intersect( $root_classes, $contract['scope_classes'] ) ),
			'block_names' => array_values( array_unique( $statistics['block_names'] ) ),
		);
	}

	private function single_native_root( array $blocks, string $pattern_name ): array {
		$root = null;

		foreach ( $blocks as $block ) {
			if ( null === ( $block['blockName'] ?? null ) ) {
				if ( '' !== trim( (string) ( $block['innerHTML'] ?? '' ) ) ) {
					$this->fail( 'invalid_pattern_root', $pattern_name, 'contains top-level freeform content.' );
				}
				continue;
			}

			if ( null !== $root ) {
				$this->fail( 'invalid_pattern_root', $pattern_name, 'must contain exactly one native root block.' );
			}
			$root = $block;
		}

		if ( ! is_array( $root ) ) {
			$this->fail( 'invalid_pattern_root', $pattern_name, 'must contain one native Gutenberg root block.' );
		}

		return $root;
	}

	private function validate_block_tree(
		array $blocks,
		string $pattern_name,
		array $forbidden,
		array $allowed_blocks,
		array &$statistics
	): void {
		foreach ( $blocks as $block ) {
			$name = $block['blockName'] ?? null;
			if ( null === $name ) {
				if ( '' !== trim( (string) ( $block['innerHTML'] ?? '' ) ) ) {
					$this->fail( 'pattern_block_forbidden', $pattern_name, 'contains Classic or freeform content.' );
				}
				continue;
			}

			$statistics['block_names'][] = $name;
			if ( 'core/heading' === $name && 1 === (int) ( $block['attrs']['level'] ?? 2 ) ) {
				++$statistics['h1'];
			}

			if ( in_array( $name, $forbidden, true ) || ( ! empty( $allowed_blocks ) && ! in_array( $name, $allowed_blocks, true ) ) ) {
				$this->fail(
					'pattern_block_forbidden',
					$pattern_name,
					sprintf( 'contains the disallowed block "%s".', sanitize_key( (string) $name ) )
				);
			}

			$attributes = $this->attributes( $block );
			if ( array_key_exists( 'textAlign', $attributes ) || array_key_exists( 'text-align', $attributes ) ) {
				$this->fail( 'pattern_layout_style_forbidden', $pattern_name, 'contains block-level text alignment.' );
			}
			if ( isset( $attributes['style'] ) && $this->contains_forbidden_layout_style( $attributes['style'] ) ) {
				$this->fail( 'pattern_layout_style_forbidden', $pattern_name, 'contains a forbidden layout or motion style.' );
			}

			if ( ! empty( $block['innerBlocks'] ) ) {
				$this->validate_block_tree( $block['innerBlocks'], $pattern_name, $forbidden, $allowed_blocks, $statistics );
			}
		}
	}

	private function contains_forbidden_layout_style( mixed $value, string $path = '' ): bool {
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $child ) {
				$key       = strtolower( (string) $key );
				$next_path = '' === $path ? $key : $path . '.' . $key;
				if ( preg_match( '/(?:transform|translate|transition|animation|motion|gap|text-?align)/', $next_path ) ) {
					return true;
				}
				if ( $this->contains_forbidden_layout_style( $child, $next_path ) ) {
					return true;
				}
			}
			return false;
		}

		if ( ! is_scalar( $value ) ) {
			return true;
		}

		return 1 === preg_match(
			'/(?:transform|translate|transition|animation|motion|(?:row-|column-|block-)?gap\s*:|text-align\s*:)/i',
			(string) $value
		);
	}

	private function validate_split_hero_intro( array $root, string $pattern_name, array $contract ): void {
		foreach ( $this->native_children( $root ) as $candidate ) {
			if ( ! $this->has_class_fragment( $candidate, 'hero' ) ) {
				continue;
			}

			$columns = array_values(
				array_filter(
					$this->native_children( $candidate ),
					static fn( array $block ): bool => in_array( $block['blockName'] ?? null, array( 'core/group', 'core/column' ), true )
				)
			);
			if ( count( $columns ) < 2 ) {
				continue;
			}

			$expected = $this->intro_classes_for_root_scope(
				$this->classes( $root ),
				$contract['scope_classes'],
				$contract['intro_classes']
			);
			$actual   = $this->classes( $columns[0] );

			if (
				( ! empty( $expected ) && empty( array_intersect( $actual, $expected ) ) )
				|| ( empty( $expected ) && empty( array_intersect( $actual, $contract['intro_classes'] ) ) )
			) {
				$this->fail( 'pattern_intro_class_missing', $pattern_name, 'is missing the declared intro class on its split-hero copy column.' );
			}
		}
	}

	private function intro_classes_for_root_scope( array $root_classes, array $scope_classes, array $intro_classes ): array {
		$result = array();
		foreach ( array_intersect( $root_classes, $scope_classes ) as $scope_class ) {
			$base = preg_replace( '/-page$/', '', $scope_class );
			if ( ! is_string( $base ) || '' === $base ) {
				continue;
			}
			foreach ( $intro_classes as $intro_class ) {
				if ( str_starts_with( $intro_class, $base . '-' ) ) {
					$result[] = $intro_class;
				}
			}
		}
		return array_values( array_unique( $result ) );
	}

	private function validate_card_grids( array $block, string $pattern_name, array $card_classes ): void {
		$children       = $this->native_children( $block );
		$card_candidates = array_values(
			array_filter(
				$children,
				static fn( array $child ): bool => in_array( $child['blockName'] ?? null, array( 'core/group', 'core/column' ), true )
			)
		);
		$declares_grid   = $this->has_class_fragment( $block, 'grid' );
		$declared_cards  = array_values(
			array_filter(
				$card_candidates,
				fn( array $child ): bool => ! empty( array_intersect( $this->classes( $child ), $card_classes ) )
			)
		);

		if (
			count( $card_candidates ) >= 2
			&& ( $declares_grid || count( $declared_cards ) >= 2 )
		) {
			foreach ( $card_candidates as $candidate ) {
				if ( empty( array_intersect( $this->classes( $candidate ), $card_classes ) ) ) {
					$this->fail( 'pattern_card_class_missing', $pattern_name, 'contains a declared card-grid child without an allowed card role class.' );
				}
			}
		}

		foreach ( $children as $child ) {
			$this->validate_card_grids( $child, $pattern_name, $card_classes );
		}
	}

	private function validate_intro_order( array $block, string $pattern_name, array $label_classes, array $lead_classes ): void {
		$children        = $this->native_children( $block );
		$label_indexes   = $this->child_indexes_with_classes( $children, $label_classes );
		$lead_indexes    = $this->child_indexes_with_classes( $children, $lead_classes );
		$heading_indexes = array_keys(
			array_filter(
				$children,
				static fn( array $child ): bool => 'core/heading' === ( $child['blockName'] ?? null )
			)
		);

		if ( ! empty( $heading_indexes ) && ( ! empty( $label_indexes ) || ! empty( $lead_indexes ) ) ) {
			$heading_index = $heading_indexes[0];
			$valid         = count( $heading_indexes ) === 1
				&& count( $label_indexes ) <= 1
				&& count( $lead_indexes ) <= 1;

			if ( $valid && ! empty( $label_indexes ) ) {
				$valid = 0 === $label_indexes[0] && $heading_index === $label_indexes[0] + 1;
			} elseif ( $valid ) {
				$valid = 0 === $heading_index;
			}

			if ( $valid && ! empty( $lead_indexes ) ) {
				$valid = $lead_indexes[0] === $heading_index + 1;
			}

			if ( ! $valid ) {
				$this->fail( 'pattern_intro_order_invalid', $pattern_name, 'violates the declared label, heading, lead, and structural-content order.' );
			}
		}

		foreach ( $children as $child ) {
			$this->validate_intro_order( $child, $pattern_name, $label_classes, $lead_classes );
		}
	}

	private function validate_post_pattern(
		array $root,
		string $pattern_name,
		array $blueprint,
		array $contract,
		array $statistics
	): void {
		if ( 'post' !== (string) ( $blueprint['target_post_type'] ?? '' ) ) {
			return;
		}

		$post_scope = '';
		foreach ( $contract['scope_classes'] as $scope_class ) {
			if ( str_ends_with( $scope_class, '-post-page' ) ) {
				$post_scope = $scope_class;
				break;
			}
		}
		if ( '' === $post_scope ) {
			$this->fail( 'pattern_markup_contract_missing', $pattern_name, 'cannot resolve the post scope from the active markup contract.' );
		}
		if ( ! in_array( $post_scope, $this->classes( $root ), true ) ) {
			$this->fail( 'pattern_scope_class_missing', $pattern_name, 'must use the declared post scope class.' );
		}

		if ( in_array( 'core/post-featured-image', $statistics['block_names'], true ) ) {
			$this->fail( 'pattern_post_featured_image_forbidden', $pattern_name, 'contains a featured-image block forbidden by the post contract.' );
		}

		$required_sequence = isset( $blueprint['required_sequence'] ) && is_array( $blueprint['required_sequence'] )
			? $blueprint['required_sequence']
			: array();
		$is_hero           = isset( $required_sequence[0] ) && $pattern_name === $required_sequence[0];

		if ( $is_hero && 1 !== $statistics['h1'] ) {
			$this->fail( 'pattern_post_h1_invalid', $pattern_name, 'must own exactly one H1 for the post blueprint.' );
		}
		if ( ! $is_hero && 0 !== $statistics['h1'] ) {
			$this->fail( 'pattern_post_h1_invalid', $pattern_name, 'must not introduce an additional H1 for the post blueprint.' );
		}

		if ( $is_hero ) {
			$required_metadata = array( 'core/post-date', 'core/post-author-name', 'core/post-terms' );
			$missing           = array_diff( $required_metadata, $statistics['block_names'] );
			if ( ! empty( $missing ) ) {
				$this->fail( 'pattern_post_metadata_missing', $pattern_name, 'is missing required native post metadata blocks.' );
			}
		}
	}

	private function child_indexes_with_classes( array $children, array $classes ): array {
		$result = array();
		foreach ( $children as $index => $child ) {
			if ( ! empty( array_intersect( $this->classes( $child ), $classes ) ) ) {
				$result[] = $index;
			}
		}
		return $result;
	}

	private function native_children( array $block ): array {
		return array_values(
			array_filter(
				isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ? $block['innerBlocks'] : array(),
				static fn( array $child ): bool => null !== ( $child['blockName'] ?? null )
			)
		);
	}

	private function attributes( array $block ): array {
		return isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
	}

	private function classes( array $block ): array {
		$attributes = $this->attributes( $block );
		$raw        = trim( (string) ( $attributes['className'] ?? '' ) );
		if ( '' === $raw ) {
			return array();
		}

		$classes = preg_split( '/\s+/', $raw );
		return array_values(
			array_unique(
				array_filter(
					is_array( $classes ) ? $classes : array(),
					static fn( string $class ): bool => 1 === preg_match( '/^[a-z][a-z0-9_-]{0,127}$/i', $class )
				)
			)
		);
	}

	private function has_class_fragment( array $block, string $fragment ): bool {
		foreach ( $this->classes( $block ) as $class ) {
			if ( str_contains( strtolower( $class ), strtolower( $fragment ) ) ) {
				return true;
			}
		}
		return false;
	}

	private function fail( string $code, string $pattern_name, string $detail ): never {
		throw new Execution_Exception(
			$code, // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data is returned by an Ability and is not rendered as HTML.
			sprintf( 'Approved pattern "%s" %s', $pattern_name, $detail ) // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data is returned by an Ability and is not rendered as HTML.
		);
	}
}
