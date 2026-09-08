<?php
/* SmartCloud Agent Composer constrained core/query materializer. */

namespace SmartCloud\AgentComposer\Execution;

/**
 * Materialize a deliberately small, read-only subset of core/query.
 *
 * The public ability never accepts raw WP_Query arguments or an arbitrary
 * block tree. Site Contract policy selects the readable post types,
 * taxonomies, sort keys, limits, and post-template blocks. A blueprint must
 * additionally opt in to every generated core block.
 */
final class Query_Loop_Materializer {
	private const REQUIRED_BLOCKS = array(
		'core/query',
		'core/post-template',
		'core/post-title',
	);

	private const PAGINATION_BLOCKS = array(
		'core/query-pagination',
		'core/query-pagination-previous',
		'core/query-pagination-numbers',
		'core/query-pagination-next',
	);

	public function __construct( private Config_Repository $config ) {}

	public function materialize( array $input ): array {
		$page_type = sanitize_key( (string) ( $input['page_type'] ?? '' ) );
		$blueprint = $this->config->get_blueprint( $page_type );
		$extensions = $this->config->get_block_extensions();
		$policy     = $this->effective_policy(
			(array) ( $extensions['query_loop_materializer'] ?? array() ),
			(array) ( $blueprint['block_extensions']['query_loop_materializer'] ?? array() )
		);
		if ( true !== ( $policy['enabled'] ?? false ) ) {
			throw new Execution_Exception( 'query_loop_materializer_disabled', 'The active Site Contract has not enabled the constrained core/query materializer.' );
		}

		$post_type = sanitize_key( (string) ( $input['post_type'] ?? '' ) );
		if ( ! in_array( $post_type, (array) ( $policy['allowed_post_types'] ?? array() ), true ) ) {
			throw new Execution_Exception( 'query_post_type_not_allowed', 'The requested query post type is not allowed by the active Site Contract.' );
		}
		$access = $this->config->get_content_access( $post_type );
		if ( empty( $access['discover'] ) ) {
			throw new Execution_Exception( 'query_post_type_not_discoverable', 'The active Site Contract does not grant Composer discovery access for this post type.' );
		}
		$post_type_object = get_post_type_object( $post_type );
		if (
			! is_object( $post_type_object )
			|| ( empty( $post_type_object->public ) && empty( $post_type_object->publicly_queryable ) )
			|| empty( $post_type_object->show_in_rest )
		) {
			throw new Execution_Exception( 'query_post_type_not_public', 'The requested post type must be public and available to the block editor REST API.' );
		}

		$per_page = $this->bounded_integer( $input['per_page'] ?? 6, 1, (int) ( $policy['max_per_page'] ?? 12 ), 'query_per_page_out_of_range' );
		$offset = $this->bounded_integer( $input['offset'] ?? 0, 0, (int) ( $policy['max_offset'] ?? 100 ), 'query_offset_out_of_range' );
		$columns = $this->bounded_integer( $input['columns'] ?? 3, 1, 4, 'query_columns_out_of_range' );
		$order = strtoupper( (string) ( $input['order'] ?? 'DESC' ) );
		if ( ! in_array( $order, array( 'ASC', 'DESC' ), true ) ) {
			throw new Execution_Exception( 'query_order_not_allowed', 'Query order must be ASC or DESC.' );
		}
		$orderby = sanitize_key( (string) ( $input['orderby'] ?? 'date' ) );
		if ( ! in_array( $orderby, (array) ( $policy['allowed_orderby'] ?? array( 'date' ) ), true ) ) {
			throw new Execution_Exception( 'query_orderby_not_allowed', 'The requested query sort key is not allowed by the active Site Contract.' );
		}
		$sticky_mode = $this->requested_sticky_mode( $input['sticky_mode'] ?? 'include', $policy );

		$template_blocks = $this->requested_template_blocks( $input, $policy );
		$pagination = ! array_key_exists( 'pagination', $input ) || true === $input['pagination'];
		$generated_names = array_merge(
			self::REQUIRED_BLOCKS,
			$template_blocks,
			$pagination ? self::PAGINATION_BLOCKS : array()
		);
		$this->assert_blocks_allowed( array_values( array_unique( $generated_names ) ), $blueprint, $extensions );

		$query = $this->query_attributes( $post_type, $per_page, $offset, $order, $orderby, $sticky_mode );
		$tax_query = $this->taxonomy_query( $input['taxonomy_filters'] ?? array(), $post_type, $policy );
		if ( ! empty( $tax_query ) ) {
			$query['taxQuery'] = $tax_query;
		}

		$children = array(
			$this->post_template_block( $template_blocks, $columns ),
		);
		if ( $pagination ) {
			$children[] = $this->pagination_block();
		}
		$block = $this->container_block(
			'core/query',
			array(
				'queryId' => $this->query_id( $page_type, $query, $columns, $template_blocks ),
				'query'   => $query,
				'layout'  => array( 'type' => 'constrained' ),
			),
			$children,
			'<div class="wp-block-query">',
			'</div>'
		);

		return array(
			'page_type'       => $page_type,
			'post_type'       => $post_type,
			'query'           => $query,
			'template_blocks' => $template_blocks,
			'pagination'      => $pagination,
			'block'           => $block,
			'blocks'          => array( $block ),
		);
	}

	private function requested_sticky_mode( mixed $value, array $policy ): string {
		if ( ! is_string( $value ) || ! in_array( $value, array( 'include', 'only', 'exclude' ), true ) ) {
			throw new Execution_Exception( 'query_sticky_mode_not_allowed', 'Sticky-post mode must be exactly include, only, or exclude.' );
		}
		if ( ! in_array( $value, (array) ( $policy['allowed_sticky_modes'] ?? array( 'include' ) ), true ) ) {
			throw new Execution_Exception( 'query_sticky_mode_not_allowed', 'The requested sticky-post mode is not allowed by the active Site Contract and Blueprint.' );
		}

		return $value;
	}

	private function query_attributes( string $post_type, int $per_page, int $offset, string $order, string $orderby, string $sticky_mode ): array {
		return array(
			'perPage'  => $per_page,
			'pages'    => 0,
			'offset'   => $offset,
			'postType' => $post_type,
			'order'    => strtolower( $order ),
			'orderBy'  => $orderby,
			'author'   => '',
			'search'   => '',
			'sticky'   => 'include' === $sticky_mode ? '' : $sticky_mode,
			'inherit'  => false,
		);
	}

	private function requested_template_blocks( array $input, array $policy ): array {
		$allowed = (array) ( $policy['allowed_template_blocks'] ?? array( 'core/post-title' ) );
		$result = array( 'core/post-title' );
		$options = array(
			'show_featured_image' => 'core/post-featured-image',
			'show_date'           => 'core/post-date',
			'show_excerpt'        => 'core/post-excerpt',
			'show_author'         => 'core/post-author-name',
		);
		foreach ( $options as $option => $block ) {
			if ( true === ( $input[ $option ] ?? false ) ) {
				if ( ! in_array( $block, $allowed, true ) ) {
					throw new Execution_Exception( 'query_template_block_not_allowed', 'A requested Query Loop template block is not allowed by the active Site Contract.' );
				}
				$result[] = $block;
			}
		}

		return $result;
	}

	/**
	 * Intersect the site-wide ceiling with the selected Blueprint opt-in.
	 */
	private function effective_policy( array $global, array $blueprint ): array {
		$intersect = static fn( string $key ): array => array_values(
			array_intersect( (array) ( $global[ $key ] ?? array() ), (array) ( $blueprint[ $key ] ?? array() ) )
		);

		return array(
			'enabled'                 => true === ( $global['enabled'] ?? false ) && true === ( $blueprint['enabled'] ?? false ),
			'allowed_post_types'      => $intersect( 'allowed_post_types' ),
			'allowed_taxonomies'      => $intersect( 'allowed_taxonomies' ),
			'allowed_orderby'         => $intersect( 'allowed_orderby' ),
			'allowed_template_blocks' => $intersect( 'allowed_template_blocks' ),
			'allowed_sticky_modes'    => $intersect( 'allowed_sticky_modes' ),
			'max_per_page'            => min( (int) ( $global['max_per_page'] ?? 12 ), (int) ( $blueprint['max_per_page'] ?? 12 ) ),
			'max_offset'              => min( (int) ( $global['max_offset'] ?? 100 ), (int) ( $blueprint['max_offset'] ?? 100 ) ),
		);
	}

	private function taxonomy_query( mixed $filters, string $post_type, array $policy ): array {
		if ( ! is_array( $filters ) || ! array_is_list( $filters ) ) {
			throw new Execution_Exception( 'query_taxonomy_filters_invalid', 'taxonomy_filters must be a JSON array.' );
		}
		if ( count( $filters ) > 5 ) {
			throw new Execution_Exception( 'query_taxonomy_filters_too_many', 'At most five taxonomy filters may be materialized.' );
		}

		$result = array();
		$allowed = (array) ( $policy['allowed_taxonomies'] ?? array() );
		foreach ( $filters as $filter ) {
			if ( ! is_array( $filter ) ) {
				throw new Execution_Exception( 'query_taxonomy_filter_invalid', 'Every taxonomy filter must be an object.' );
			}
			$taxonomy = sanitize_key( (string) ( $filter['taxonomy'] ?? '' ) );
			if ( ! in_array( $taxonomy, $allowed, true ) || ! is_object_in_taxonomy( $post_type, $taxonomy ) ) {
				throw new Execution_Exception( 'query_taxonomy_not_allowed', 'The requested taxonomy is not allowed for this query post type.' );
			}
			$taxonomy_object = get_taxonomy( $taxonomy );
			if ( ! is_object( $taxonomy_object ) || ( empty( $taxonomy_object->public ) && empty( $taxonomy_object->publicly_queryable ) ) ) {
				throw new Execution_Exception( 'query_taxonomy_not_public', 'The requested taxonomy must be public.' );
			}
			$terms = $filter['term_ids'] ?? array();
			if ( ! is_array( $terms ) || ! array_is_list( $terms ) || empty( $terms ) || count( $terms ) > 20 ) {
				throw new Execution_Exception( 'query_taxonomy_terms_invalid', 'Every taxonomy filter requires one to twenty term IDs.' );
			}
			$term_ids = array();
			foreach ( $terms as $term_id ) {
				if ( ! is_int( $term_id ) || $term_id < 1 || ! term_exists( $term_id, $taxonomy ) ) {
					throw new Execution_Exception( 'query_taxonomy_term_not_found', 'A requested taxonomy term does not exist in the selected taxonomy.' );
				}
				$term_ids[] = $term_id;
			}
			$result[ $taxonomy ] = array_values( array_unique( $term_ids ) );
		}

		return $result;
	}

	private function assert_blocks_allowed( array $names, array $blueprint, array $extensions ): void {
		$global = (array) ( $extensions['allowed_core_blocks'] ?? array() );
		$blueprint_allowed = (array) ( $blueprint['allowed_blocks'] ?? array() );
		foreach ( $names as $name ) {
			if ( ! in_array( $name, $global, true ) || ! in_array( $name, $blueprint_allowed, true ) ) {
				throw new Execution_Exception(
					'query_block_not_allowed',
					'The active Site Contract and selected blueprint must both allow every generated Query Loop block.'
				);
			}
		}
	}

	private function post_template_block( array $blocks, int $columns ): array {
		$children = array();
		foreach ( $blocks as $name ) {
			$attrs = in_array( $name, array( 'core/post-title', 'core/post-featured-image' ), true )
				? array( 'isLink' => true )
				: array();
			$children[] = $this->leaf_block( $name, $attrs );
		}

		return $this->container_block(
			'core/post-template',
			array( 'layout' => array( 'type' => 'grid', 'columnCount' => $columns ) ),
			$children,
			'<ul class="wp-block-post-template is-layout-grid columns-' . $columns . '">',
			'</ul>'
		);
	}

	private function pagination_block(): array {
		return $this->container_block(
			'core/query-pagination',
			array( 'layout' => array( 'type' => 'flex', 'justifyContent' => 'space-between' ) ),
			array(
				$this->leaf_block( 'core/query-pagination-previous', array() ),
				$this->leaf_block( 'core/query-pagination-numbers', array() ),
				$this->leaf_block( 'core/query-pagination-next', array() ),
			),
			'<div class="wp-block-query-pagination is-layout-flex">',
			'</div>'
		);
	}

	private function container_block( string $name, array $attrs, array $children, string $open, string $close ): array {
		return array(
			'blockName'    => $name,
			'attrs'        => $attrs,
			'innerBlocks'  => $children,
			'innerHTML'    => $open . $close,
			'innerContent' => array_merge( array( $open ), array_fill( 0, count( $children ), null ), array( $close ) ),
		);
	}

	private function leaf_block( string $name, array $attrs ): array {
		return array(
			'blockName'    => $name,
			'attrs'        => $attrs,
			'innerBlocks'  => array(),
			'innerHTML'    => '',
			'innerContent' => array(),
		);
	}

	private function query_id( string $page_type, array $query, int $columns, array $template_blocks ): int {
		$encoded = wp_json_encode( array( $page_type, $query, $columns, $template_blocks ) );
		return 1 + ( abs( crc32( is_string( $encoded ) ? $encoded : $page_type ) ) % 2147483646 );
	}

	private function bounded_integer( mixed $value, int $minimum, int $maximum, string $code ): int {
		if ( ! is_int( $value ) || $value < $minimum || $value > $maximum ) {
			// Structured error codes are passed to exception handling and are never rendered as HTML.
			throw new Execution_Exception( $code, 'A Query Loop numeric option is outside its Site Contract limit.' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		return $value;
	}
}
