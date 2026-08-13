<?php
/* SmartCloud Agent Composer governed taxonomy execution. */

namespace SmartCloud\AgentComposer\Execution;

/**
 * Exposes only Site Contract-approved public taxonomy discovery and writes.
 *
 * Taxonomy registration remains provider-owned. Composer may create terms but
 * never edits or deletes them, and relationships may be written only to the
 * current agent's assigned draft through Draft_Service's concurrency lock.
 */
final class Taxonomy_Term_Service {
	private const SEARCH_ABILITY  = 'smartcloud-agent-composer/search-taxonomy-terms';
	private const CREATE_ABILITY  = 'smartcloud-agent-composer/create-taxonomy-term';
	private const ASSIGN_ABILITY  = 'smartcloud-agent-composer/assign-taxonomy-terms';
	private const INSPECT_ABILITY = 'smartcloud-agent-composer/inspect-taxonomy-terms';

	public function __construct(
		private readonly Config_Repository $config,
		private readonly Draft_Service $drafts,
		private readonly Content_Language_Validator $language
	) {}

	public function contract( array $input ): array {
		$page_type = sanitize_key( (string) ( $input['page_type'] ?? '' ) );
		$blueprint = $this->config->get_blueprint( $page_type );
		$post_type = (string) $blueprint['target_post_type'];
		$items     = array();

		foreach ( $this->config->get_content_taxonomy_access( $post_type ) as $taxonomy => $rule ) {
			if ( ! is_array( $rule ) || empty( $rule['search'] ) ) {
				continue;
			}
			$object = $this->taxonomy_object( (string) $taxonomy, $post_type );
			$items[] = array(
				'taxonomy'     => (string) $object->name,
				'label'        => sanitize_text_field( (string) ( $object->labels->singular_name ?? $object->label ?? $object->name ) ),
				'plural_label' => sanitize_text_field( (string) ( $object->labels->name ?? $object->label ?? $object->name ) ),
				'description'  => sanitize_text_field( (string) ( $object->description ?? '' ) ),
				'hierarchical' => ! empty( $object->hierarchical ),
				'search'       => true,
				'create'       => ! empty( $rule['create'] ),
				'assign'       => ! empty( $rule['assign'] ),
				'maximum_items' => $this->maximum_terms( $rule ),
				'assignment_mode' => (string) ( $rule['assignment_mode'] ?? 'replace' ),
				'creation_parent_policy' => (string) ( $rule['creation_parent_policy'] ?? 'root-only' ),
				'creation_parent_slugs' => array_values( (array) ( $rule['creation_parent_slugs'] ?? array() ) ),
				'current_user_can_create' => ! empty( $rule['create'] ) && current_user_can( \SmartCloud\AgentComposer\Infrastructure\WordPress\Activation::CAP_CREATE_TERMS ),
				'current_user_can_assign' => ! empty( $rule['assign'] )
					&& current_user_can( \SmartCloud\AgentComposer\Infrastructure\WordPress\Activation::CAP_ASSIGN_TERMS )
					&& $this->current_user_can_assign( $object ),
			);
		}

		usort( $items, static fn( array $left, array $right ): int => $left['taxonomy'] <=> $right['taxonomy'] );
		return array(
			'page_type'        => $page_type,
			'target_post_type' => $post_type,
			'content_language' => (string) ( $blueprint['content_language'] ?? '' ),
			'taxonomies'       => $items,
			'write_boundary'   => 'composer-owned-assigned-draft',
			'term_edit_supported'   => false,
			'term_delete_supported' => false,
			'workflow' => array(
				'search_ability'   => self::SEARCH_ABILITY,
				'create_ability'   => self::CREATE_ABILITY,
				'assign_ability'   => self::ASSIGN_ABILITY,
				'inspect_ability'  => self::INSPECT_ABILITY,
				'result_id_path'   => 'matches[].term_id',
			),
		);
	}

	public function search( array $input ): array {
		$context  = $this->context( $input, 'read' );
		$query    = sanitize_text_field( (string) ( $input['query'] ?? '' ) );
		$limit    = min( 50, max( 1, absint( $input['limit'] ?? 20 ) ) );
		$offset   = min( 5000, max( 0, absint( $input['offset'] ?? 0 ) ) );
		$get_args = array(
			'taxonomy'   => $context['taxonomy'],
			'hide_empty' => false,
			'number'     => $limit + 1,
			'offset'     => $offset,
			'orderby'    => 'name',
			'order'      => 'ASC',
		);
		if ( '' !== $query ) {
			$get_args['search'] = $query;
		}
		$terms = get_terms( $get_args );
		if ( is_wp_error( $terms ) ) {
			throw new Execution_Exception( 'taxonomy_term_search_failed', $terms->get_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Ability error, not HTML.
		}

		$candidates = is_array( $terms ) ? $terms : array();
		if ( '' !== $query ) {
			$exact = get_term_by( 'slug', sanitize_title( $query ), $context['taxonomy'] );
			if ( $exact instanceof \WP_Term ) {
				array_unshift( $candidates, $exact );
			}
		}

		$matches = array();
		$seen    = array();
		foreach ( $candidates as $term ) {
			if ( ! $term instanceof \WP_Term || isset( $seen[ $term->term_id ] ) || $context['taxonomy'] !== $term->taxonomy ) {
				continue;
			}
			$seen[ $term->term_id ] = true;
			$item          = $this->describe_term( $term );
			$item['match'] = $this->match( $term, $query );
			$matches[]     = $item;
		}
		usort(
			$matches,
			static fn( array $left, array $right ): int => array( $left['match']['rank'], $left['name'], $left['term_id'] ) <=> array( $right['match']['rank'], $right['name'], $right['term_id'] )
		);
		$has_more = count( $matches ) > $limit;
		$matches  = array_slice( $matches, 0, $limit );

		return array(
			'purpose'        => 'taxonomy-term-resolution',
			'page_type'      => $context['page_type'],
			'target_post_type' => $context['post_type'],
			'taxonomy'       => $context['taxonomy'],
			'query'          => $query,
			'matches'        => $matches,
			'match_count'    => count( $matches ),
			'limit'          => $limit,
			'offset'         => $offset,
			'has_more'       => $has_more,
			'result_id_path' => 'matches[].term_id',
			'next_abilities' => array_values(
				array_filter(
					array(
						! empty( $context['rule']['create'] ) ? self::CREATE_ABILITY : '',
						! empty( $context['rule']['assign'] ) ? self::ASSIGN_ABILITY : '',
					)
				)
			),
		);
	}

	public function create( array $input ): array {
		if ( true !== ( $input['confirm_create'] ?? false ) ) {
			throw new Execution_Exception( 'taxonomy_term_confirmation_required', 'Taxonomy-term creation requires explicit confirmation.' );
		}
		if ( ! current_user_can( \SmartCloud\AgentComposer\Infrastructure\WordPress\Activation::CAP_CREATE_TERMS ) ) {
			throw new Execution_Exception( 'taxonomy_term_create_denied', 'The current WordPress user cannot create governed taxonomy terms.' );
		}
		$context = $this->context( $input, 'create' );
		$this->language->assert_request_language( $context['page_type'], $input );

		$name        = sanitize_text_field( (string) ( $input['name'] ?? '' ) );
		$slug_input  = trim( (string) ( $input['slug'] ?? '' ) );
		$slug        = sanitize_title( $slug_input );
		$description = sanitize_textarea_field( (string) ( $input['description'] ?? '' ) );
		$parent_slug = sanitize_title( (string) ( $input['parent_slug'] ?? '' ) );
		$parent_id   = 0;
		if ( '' === $name || $this->string_length( $name ) > 200 ) {
			throw new Execution_Exception( 'taxonomy_term_name_invalid', 'A bounded public taxonomy-term name is required.' );
		}
		if ( '' === $slug || $slug_input !== $slug || $this->string_length( $slug ) > 200 ) {
			throw new Execution_Exception( 'taxonomy_term_slug_invalid', 'A durable lowercase taxonomy-term slug is required.' );
		}
		if ( '' === $description || $this->string_length( $description ) > 2000 ) {
			throw new Execution_Exception( 'taxonomy_term_description_invalid', 'A bounded standalone archive description is required.' );
		}
		if ( ! empty( $this->language->issues( $context['page_type'], $name . "\n" . $description ) ) ) {
			throw new Execution_Exception( 'taxonomy_term_language_mismatch', 'The taxonomy-term copy conflicts with the strict Blueprint content language.' );
		}
		if ( '' !== $parent_slug ) {
			if ( empty( $context['object']->hierarchical ) || 'allowlist' !== ( $context['rule']['creation_parent_policy'] ?? 'root-only' ) ) {
				throw new Execution_Exception( 'taxonomy_term_parent_not_allowed', 'The active taxonomy policy requires new terms to remain at the taxonomy root.' );
			}
			if ( ! in_array( $parent_slug, (array) ( $context['rule']['creation_parent_slugs'] ?? array() ), true ) ) {
				throw new Execution_Exception( 'taxonomy_term_parent_not_allowed', 'The requested taxonomy parent slug is not allowed by the active Site Contract.' );
			}
			$parent = get_term_by( 'slug', $parent_slug, $context['taxonomy'] );
			if ( ! $parent instanceof \WP_Term ) {
				throw new Execution_Exception( 'taxonomy_term_parent_not_found', 'The allowed parent slug does not resolve to an existing term.' );
			}
			$parent_id = (int) $parent->term_id;
		}

		$existing = get_term_by( 'slug', $slug, $context['taxonomy'] );
		if ( $existing instanceof \WP_Term ) {
			return $this->existing_create_result( $existing, $name, $description, $parent_id );
		}

		$inserted = wp_insert_term(
			$name,
			$context['taxonomy'],
			array( 'slug' => $slug, 'description' => $description, 'parent' => $parent_id )
		);
		if ( is_wp_error( $inserted ) ) {
			if ( 'term_exists' === $inserted->get_error_code() ) {
				$term_id = absint( $inserted->get_error_data() );
				$term    = $term_id > 0 ? get_term( $term_id, $context['taxonomy'] ) : get_term_by( 'slug', $slug, $context['taxonomy'] );
				if ( $term instanceof \WP_Term ) {
					return $this->existing_create_result( $term, $name, $description, $parent_id );
				}
			}
			throw new Execution_Exception( 'taxonomy_term_create_failed', $inserted->get_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Ability error, not HTML.
		}
		$term = get_term( absint( $inserted['term_id'] ?? 0 ), $context['taxonomy'] );
		if ( ! $term instanceof \WP_Term ) {
			throw new Execution_Exception( 'taxonomy_term_read_after_create_failed', 'The taxonomy term was created but could not be read back.' );
		}

		$result                      = $this->describe_term( $term );
		$result['page_type']         = $context['page_type'];
		$result['target_post_type']  = $context['post_type'];
		$result['created']           = true;
		$result['idempotent_replay'] = false;
		$result['next_ability']      = self::ASSIGN_ABILITY;
		return $result;
	}

	public function assign( array $input ): array {
		if ( true !== ( $input['confirm_assignment'] ?? false ) ) {
			throw new Execution_Exception( 'taxonomy_assignment_confirmation_required', 'Taxonomy assignment requires explicit confirmation.' );
		}
		if ( ! current_user_can( \SmartCloud\AgentComposer\Infrastructure\WordPress\Activation::CAP_ASSIGN_TERMS ) ) {
			throw new Execution_Exception( 'taxonomy_assignment_denied', 'The current WordPress user cannot assign governed taxonomy terms.' );
		}
		$context = $this->context( $input, 'assign' );
		if ( ! $this->current_user_can_assign( $context['object'] ) ) {
			throw new Execution_Exception( 'taxonomy_assignment_denied', 'The current WordPress user lacks the registered taxonomy assignment capability.' );
		}
		$post_id = absint( $input['post_id'] ?? 0 );
		$post    = $this->drafts->get_owned_draft( $post_id );
		if (
			$post->post_type !== $context['post_type']
			|| $context['page_type'] !== sanitize_key( (string) get_post_meta( $post_id, Draft_Service::PAGE_TYPE_META, true ) )
		) {
			throw new Execution_Exception( 'taxonomy_draft_target_mismatch', 'The assigned draft does not match the selected Blueprint taxonomy target.' );
		}

		$submitted = $input['term_ids'] ?? null;
		if ( ! is_array( $submitted ) || ! array_is_list( $submitted ) || empty( $submitted ) ) {
			throw new Execution_Exception( 'taxonomy_term_ids_invalid', 'term_ids must be a non-empty list of positive integer term IDs.' );
		}
		$term_ids = array();
		foreach ( $submitted as $term_id ) {
			if ( ! is_int( $term_id ) || $term_id < 1 ) {
				throw new Execution_Exception( 'taxonomy_term_ids_invalid', 'Every taxonomy term ID must be a positive integer.' );
			}
			$term_ids[] = $term_id;
		}
		$term_ids = array_values( array_unique( $term_ids ) );
		if ( count( $term_ids ) !== count( $submitted ) ) {
			throw new Execution_Exception( 'taxonomy_term_ids_duplicate', 'Taxonomy term IDs must be unique.' );
		}

		$mode = (string) ( $input['mode'] ?? '' );
		if ( ! in_array( $mode, array( 'replace', 'append' ), true ) ) {
			throw new Execution_Exception( 'taxonomy_assignment_mode_invalid', 'Taxonomy assignment mode must be replace or append.' );
		}
		$policy_mode = (string) ( $context['rule']['assignment_mode'] ?? 'replace' );
		if ( $mode !== $policy_mode ) {
			throw new Execution_Exception( 'taxonomy_assignment_mode_denied', 'The requested assignment mode differs from the active Site Contract.' );
		}
		$maximum = $this->maximum_terms( $context['rule'] );
		if ( count( $term_ids ) > $maximum ) {
			throw new Execution_Exception( 'taxonomy_term_limit_exceeded', 'The assignment exceeds the Site Contract taxonomy-term limit.' );
		}
		foreach ( $term_ids as $term_id ) {
			$term = get_term( $term_id, $context['taxonomy'] );
			if ( ! $term instanceof \WP_Term ) {
				throw new Execution_Exception( 'taxonomy_term_not_found', 'A requested term does not exist in the selected taxonomy.' );
			}
		}

		$result = $this->drafts->update_owned_taxonomy_terms( $input, $context['taxonomy'], $term_ids, 'append' === $mode, $maximum );
		$result['taxonomy']       = $context['taxonomy'];
		$result['assignment_mode'] = $mode;
		$result['terms']          = $this->terms_for_post( $result['post_id'], $context['taxonomy'] );
		$result['verify_ability'] = self::INSPECT_ABILITY;
		return $result;
	}

	public function inspect( array $input ): array {
		$post_id   = absint( $input['post_id'] ?? 0 );
		$page_type = sanitize_key( (string) ( $input['page_type'] ?? '' ) );
		$post      = $this->drafts->get_owned_draft( $post_id );
		$blueprint = $this->config->get_blueprint( $page_type );
		if ( $page_type !== sanitize_key( (string) get_post_meta( $post_id, Draft_Service::PAGE_TYPE_META, true ) ) || $post->post_type !== (string) $blueprint['target_post_type'] ) {
			throw new Execution_Exception( 'taxonomy_draft_target_mismatch', 'The assigned draft does not match the selected Blueprint taxonomy target.' );
		}

		$requested = sanitize_key( (string) ( $input['taxonomy'] ?? '' ) );
		$items     = array();
		foreach ( $this->config->get_content_taxonomy_access( $post->post_type ) as $taxonomy => $rule ) {
			$taxonomy = sanitize_key( (string) $taxonomy );
			if ( ! is_array( $rule ) || empty( $rule['search'] ) || ( '' !== $requested && $requested !== $taxonomy ) ) {
				continue;
			}
			$this->taxonomy_object( $taxonomy, $post->post_type );
			$items[] = array( 'taxonomy' => $taxonomy, 'terms' => $this->terms_for_post( $post_id, $taxonomy ) );
		}
		if ( '' !== $requested && empty( $items ) ) {
			throw new Execution_Exception( 'taxonomy_not_allowed', 'The active Site Contract does not allow this taxonomy for the selected Blueprint.' );
		}

		$result = $this->drafts->get( $post_id );
		$result['taxonomies'] = $items;
		return $result;
	}

	/** @return array{page_type:string,post_type:string,taxonomy:string,rule:array,object:object} */
	private function context( array $input, string $operation ): array {
		$page_type = sanitize_key( (string) ( $input['page_type'] ?? '' ) );
		$blueprint = $this->config->get_blueprint( $page_type );
		$post_type = (string) $blueprint['target_post_type'];
		$taxonomy  = sanitize_key( (string) ( $input['taxonomy'] ?? '' ) );
		$rules     = $this->config->get_content_taxonomy_access( $post_type );
		$rule      = is_array( $rules[ $taxonomy ] ?? null ) ? $rules[ $taxonomy ] : array();
		if ( '' === $taxonomy || empty( $rule['search'] ) || ( 'read' !== $operation && empty( $rule[ $operation ] ) ) ) {
			throw new Execution_Exception( 'taxonomy_not_allowed', 'The active Site Contract does not allow this taxonomy operation for the selected Blueprint.' );
		}
		return array(
			'page_type' => $page_type,
			'post_type' => $post_type,
			'taxonomy'  => $taxonomy,
			'rule'      => $rule,
			'object'    => $this->taxonomy_object( $taxonomy, $post_type ),
		);
	}

	private function taxonomy_object( string $taxonomy, string $post_type ): object {
		$object = get_taxonomy( $taxonomy );
		if ( ! is_object( $object ) || empty( $object->show_in_rest ) || ( empty( $object->public ) && empty( $object->publicly_queryable ) ) ) {
			throw new Execution_Exception( 'taxonomy_unavailable', 'The selected taxonomy is not a registered public REST taxonomy.' );
		}
		if ( ! is_object_in_taxonomy( $post_type, $taxonomy ) ) {
			throw new Execution_Exception( 'taxonomy_post_type_mismatch', 'The selected taxonomy is not registered for the Blueprint target post type.' );
		}
		return $object;
	}

	private function current_user_can_assign( object $taxonomy ): bool {
		$capability = sanitize_key( (string) ( $taxonomy->cap->assign_terms ?? '' ) );
		return '' !== $capability && current_user_can( $capability );
	}

	private function maximum_terms( array $rule ): int {
		return min( 100, max( 1, absint( $rule['maximum_items'] ?? 20 ) ) );
	}

	private function terms_for_post( int $post_id, string $taxonomy ): array {
		$terms = wp_get_object_terms( $post_id, $taxonomy, array( 'orderby' => 'name', 'order' => 'ASC' ) );
		if ( is_wp_error( $terms ) ) {
			throw new Execution_Exception( 'taxonomy_term_inspection_failed', $terms->get_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Ability error, not HTML.
		}
		return array_values(
			array_map(
				fn( \WP_Term $term ): array => $this->describe_term( $term ),
				array_filter( (array) $terms, static fn( mixed $term ): bool => $term instanceof \WP_Term )
			)
		);
	}

	private function describe_term( \WP_Term $term ): array {
		$link = get_term_link( $term );
		return array(
			'term_id'     => (int) $term->term_id,
			'taxonomy'    => (string) $term->taxonomy,
			'name'        => (string) $term->name,
			'slug'        => (string) $term->slug,
			'description' => wp_strip_all_tags( (string) $term->description ),
			'parent_id'   => (int) $term->parent,
			'count'       => (int) $term->count,
			'archive_url' => is_wp_error( $link ) ? '' : (string) $link,
		);
	}

	private function existing_create_result( \WP_Term $term, string $name, string $description, int $parent_id ): array {
		if ( $name !== (string) $term->name || $description !== (string) $term->description || $parent_id !== (int) $term->parent ) {
			throw new Execution_Exception( 'taxonomy_term_conflict', 'The requested taxonomy-term slug already belongs to different public term content.' );
		}
		$result                      = $this->describe_term( $term );
		$result['created']           = false;
		$result['idempotent_replay'] = true;
		$result['next_ability']      = self::ASSIGN_ABILITY;
		return $result;
	}

	private function match( \WP_Term $term, string $query ): array {
		if ( '' === $query ) {
			return array( 'kind' => 'browse', 'rank' => 3 );
		}
		$needle = function_exists( 'mb_strtolower' ) ? mb_strtolower( $query ) : strtolower( $query );
		$name   = function_exists( 'mb_strtolower' ) ? mb_strtolower( $term->name ) : strtolower( $term->name );
		if ( sanitize_title( $query ) === $term->slug ) {
			return array( 'kind' => 'exact-slug', 'rank' => 0 );
		}
		if ( $needle === $name ) {
			return array( 'kind' => 'exact-name', 'rank' => 1 );
		}
		return array( 'kind' => 'name-search', 'rank' => 2 );
	}

	private function string_length( string $value ): int {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $value ) : strlen( $value );
	}
}
