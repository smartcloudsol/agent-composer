<?php
/* SmartCloud Agent Composer execution contract. */

namespace SmartCloud\AgentComposer\Execution;

/**
 * Provides a fail-closed bridge between Composer and registered CPT fields.
 *
 * No provider or field name is hard-coded here. The active Site Contract must
 * name every field, WordPress must register it for the Blueprint target post
 * type, and the registration must be single-value and REST-visible.
 */
final class Content_Field_Materializer {
	private const MAX_SERIALIZED_BYTES = 262144;
	private const MAX_ARRAY_ITEMS      = 500;
	private const MAX_OBJECT_KEYS      = 100;
	private const MAX_STRING_LENGTH    = 100000;
	private const ALLOWED_TYPES        = array( 'string', 'integer', 'number', 'boolean', 'array', 'object' );
	private const RELATION_LOOKUP_ABILITY = 'smartcloud-agent-composer/search-relation-targets';
	private const FIELD_UPDATE_ABILITY    = 'smartcloud-agent-composer/update-content-fields';
	private const FIELD_INSPECT_ABILITY   = 'smartcloud-agent-composer/inspect-content-fields';
	private const EDITABLE_LIST_ABILITY   = 'smartcloud-agent-composer/list-content-drafts';

	public function __construct(
		private readonly Config_Repository $config,
		private readonly Draft_Service $drafts,
		private readonly Content_Language_Validator $language
	) {}

	public function contract( array $input ): array {
		$page_type = sanitize_key( (string) ( $input['page_type'] ?? '' ) );
		$blueprint = $this->config->get_blueprint( $page_type );
		$post_type = (string) $blueprint['target_post_type'];
		$fields    = array();
		$policy    = $this->config->get_content_field_access( $post_type );
		foreach ( $this->registered_fields( $post_type ) as $meta_key => $registration ) {
			$rule = $policy[ $meta_key ] ?? null;
			if ( ! is_array( $rule ) || empty( $rule['read'] ) ) {
				continue;
			}
			$field = array(
				'key'         => $meta_key,
				'type'        => (string) $registration['type'],
				'description' => sanitize_text_field( (string) ( $registration['description'] ?? '' ) ),
				'read'        => true,
				'write'       => ! empty( $rule['write'] ),
				'rest_schema' => $this->public_rest_schema( $registration ),
			);
			if ( 'relation' === ( $rule['semantic_type'] ?? '' ) ) {
				$field['semantic_type']       = 'relation';
				$field['cardinality']         = $rule['cardinality'];
				$field['ordered']             = $rule['ordered'];
				$field['target_post_types']   = $rule['target_post_types'];
				$field['target_post_statuses'] = $rule['target_post_statuses'];
				$field['maximum_items']       = $rule['maximum_items'];
				$field['storage']             = $rule['storage'];
				$field['execution_workflow']  = array(
					'lookup_ability'              => self::RELATION_LOOKUP_ABILITY,
					'lookup_result_id_path'       => 'matches[].id',
					'lookup_required_before_write' => true,
					'write_ability'               => self::FIELD_UPDATE_ABILITY,
					'verify_ability'              => self::FIELD_INSPECT_ABILITY,
					'never_use_for_lookup'        => array( self::EDITABLE_LIST_ABILITY ),
				);
			}
			$fields[] = $field;
		}

		return array(
			'page_type'        => $page_type,
			'target_post_type' => $post_type,
			'fields'            => $fields,
			'write_boundary'    => 'composer-owned-assigned-draft',
			'delete_supported'  => false,
			'relation_workflow' => array(
				'lookup_ability'        => self::RELATION_LOOKUP_ABILITY,
				'lookup_result_id_path' => 'matches[].id',
				'write_ability'         => self::FIELD_UPDATE_ABILITY,
				'verify_ability'        => self::FIELD_INSPECT_ABILITY,
				'never_use_for_lookup'  => array( self::EDITABLE_LIST_ABILITY ),
			),
		);
	}

	public function inspect( array $input ): array {
		$post_id   = absint( $input['post_id'] ?? 0 );
		$page_type = sanitize_key( (string) ( $input['page_type'] ?? '' ) );
		$blueprint = $this->config->get_blueprint( $page_type );
		$post      = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || $post->post_type !== (string) $blueprint['target_post_type'] ) {
			throw new Execution_Exception( 'content_field_target_mismatch', 'The content item does not match the selected Blueprint target.' );
		}

		$is_owned = 'draft' === $post->post_status
			&& '1' === (string) get_post_meta( $post_id, Draft_Service::OWNED_META, true );
		if ( $is_owned ) {
			$post = $this->drafts->get_owned_draft( $post_id );
		} elseif (
			! $this->config->get_content_access( $post->post_type )['read']
			|| ! current_user_can( 'read_post', $post_id )
			|| ! current_user_can( 'edit_post', $post_id )
		) {
			throw new Execution_Exception( 'content_field_read_denied', 'The active Site Contract or current WordPress user does not allow reading fields from this content item.' );
		}

		$values = array();
		foreach ( $this->allowed_fields( $post->post_type, false ) as $meta_key => $registration ) {
			if ( ! current_user_can( 'edit_post_meta', $post_id, $meta_key ) ) {
				continue;
			}
			$values[ $meta_key ] = get_post_meta( $post_id, $meta_key, true );
		}

		return array(
			'post_id'      => $post_id,
			'page_type'    => $page_type,
			'post_type'    => $post->post_type,
			'source_owned' => $is_owned,
			'fields'       => $values,
		);
	}

	/** Resolve human titles or stable slugs without exposing provider-specific logic. */
	public function search_relation_targets( array $input ): array {
		$page_type = sanitize_key( (string) ( $input['page_type'] ?? '' ) );
		$field_key = sanitize_key( (string) ( $input['relation_field'] ?? '' ) );
		$contract  = $this->contract( array( 'page_type' => $page_type ) );
		$field     = null;
		foreach ( $contract['fields'] as $candidate ) {
			if ( $field_key === ( $candidate['key'] ?? '' ) && 'relation' === ( $candidate['semantic_type'] ?? '' ) ) {
				$field = $candidate;
				break;
			}
		}
		if ( ! is_array( $field ) ) {
			throw new Execution_Exception( 'relation_field_not_allowed', 'The active Site Contract does not expose this relation field for the selected Blueprint.' );
		}

		$query         = sanitize_text_field( (string) ( $input['query'] ?? '' ) );
		$limit         = max( 1, min( 50, absint( $input['limit'] ?? 20 ) ) );
		$target_types  = array_values( (array) ( $field['target_post_types'] ?? array() ) );
		$target_states = array_values( (array) ( $field['target_post_statuses'] ?? array( 'publish' ) ) );
		$args          = array(
			'post_type'              => $target_types,
			'post_status'            => $target_states,
			'posts_per_page'         => min( 150, $limit * 3 ),
			'orderby'                => 'title',
			'order'                  => 'ASC',
			'no_found_rows'          => true,
			'suppress_filters'       => false,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		);
		if ( '' !== $query ) {
			$args['s'] = $query;
		}
		$candidates = get_posts( $args );
		if ( '' !== $query ) {
			$slug_match = get_page_by_path( sanitize_title( $query ), OBJECT, $target_types );
			if ( $slug_match instanceof \WP_Post ) {
				array_unshift( $candidates, $slug_match );
			}
		}

		$matches = array();
		$seen    = array();
		foreach ( $candidates as $candidate ) {
			if (
				! $candidate instanceof \WP_Post
				|| isset( $seen[ $candidate->ID ] )
				|| ! in_array( $candidate->post_type, $target_types, true )
				|| ! in_array( $candidate->post_status, $target_states, true )
				|| ! current_user_can( 'read_post', $candidate->ID )
			) {
				continue;
			}
			$seen[ $candidate->ID ] = true;
			$matches[] = array(
				'id'          => (int) $candidate->ID,
				'title'       => get_the_title( $candidate ),
				'slug'        => (string) $candidate->post_name,
				'post_type'   => (string) $candidate->post_type,
				'post_status' => (string) $candidate->post_status,
				'match'       => $this->relation_match( $candidate, $query ),
			);
		}
		usort(
			$matches,
			static fn( array $left, array $right ): int => array( $left['match']['rank'], $left['title'], $left['id'] ) <=> array( $right['match']['rank'], $right['title'], $right['id'] )
		);
		$matches = array_slice( $matches, 0, $limit );

		return array(
			'purpose'             => 'relation-target-resolution',
			'page_type'           => $page_type,
			'source_post_type'    => (string) $contract['target_post_type'],
			'relation_field'      => $field_key,
			'target_post_types'   => $target_types,
			'target_post_statuses' => $target_states,
			'maximum_items'       => (int) ( $field['maximum_items'] ?? 1 ),
			'ordered'             => ! empty( $field['ordered'] ),
			'query'               => $query,
			'matches'             => $matches,
			'match_count'         => count( $matches ),
			'result_id_path'      => 'matches[].id',
			'next_ability'        => self::FIELD_UPDATE_ABILITY,
		);
	}

	public function update( array $input ): array {
		if ( true !== ( $input['confirm_update'] ?? false ) ) {
			throw new Execution_Exception( 'content_field_confirmation_required', 'Content-field updates require explicit confirmation.' );
		}
		$submitted = $input['fields'] ?? null;
		if ( ! is_array( $submitted ) || array_is_list( $submitted ) || empty( $submitted ) ) {
			throw new Execution_Exception( 'invalid_content_fields', 'fields must be a non-empty object keyed by approved registered meta keys.' );
		}
		$values = $this->prepare_values( (string) ( $input['page_type'] ?? '' ), $submitted, absint( $input['post_id'] ?? 0 ) );

		$page_type = sanitize_key( (string) ( $input['page_type'] ?? '' ) );
		$result = $this->drafts->update_owned_meta_fields( $input, $values );
		$result['fields'] = $values;
		return $result;
	}

	/** @return array<string,mixed> */
	public function prepare_values( string $page_type, mixed $submitted, int $post_id = 0 ): array {
		if ( null === $submitted ) {
			return array();
		}
		if ( ! is_array( $submitted ) || array_is_list( $submitted ) ) {
			throw new Execution_Exception( 'invalid_content_fields', 'fields must be an object keyed by approved registered meta keys.' );
		}
		$page_type = sanitize_key( $page_type );
		$blueprint = $this->config->get_blueprint( $page_type );
		$post_type = (string) $blueprint['target_post_type'];
		$allowed   = $this->allowed_fields( $post_type, true );
		$values    = array();
		foreach ( $submitted as $meta_key => $value ) {
			$meta_key = (string) $meta_key;
			if ( ! isset( $allowed[ $meta_key ] ) ) {
				throw new Execution_Exception( 'content_field_write_denied', 'A submitted field is not explicitly writable in the active Site Contract: ' . $meta_key ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Ability error, not HTML.
			}
			if ( $post_id > 0 && ! current_user_can( 'edit_post_meta', $post_id, $meta_key ) ) {
				throw new Execution_Exception( 'content_field_capability_denied', 'The current WordPress user cannot edit an approved content field: ' . $meta_key ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Ability error, not HTML.
			}
			$this->assert_value( $value, $allowed[ $meta_key ], $meta_key );
			$this->assert_relation_value( $value, $allowed[ $meta_key ]['composer_contract'] ?? array(), $meta_key );
			$sanitized = sanitize_meta( $meta_key, $value, 'post', $post_type );
			$this->assert_value( $sanitized, $allowed[ $meta_key ], $meta_key );
			$this->assert_relation_value( $sanitized, $allowed[ $meta_key ]['composer_contract'] ?? array(), $meta_key );
			$values[ $meta_key ] = $sanitized;
		}
		$language_text = wp_json_encode( $values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( is_string( $language_text ) && ! empty( $this->language->issues( $page_type, $language_text ) ) ) {
			throw new Execution_Exception( 'content_field_language_mismatch', 'Structured fields conflict with the strict Blueprint content language.' );
		}
		return $values;
	}

	/** @return array<string,array<string,mixed>> */
	private function allowed_fields( string $post_type, bool $write ): array {
		$registered = $this->registered_fields( $post_type );
		$policy     = $this->config->get_content_field_access( $post_type );
		$result     = array();
		foreach ( $policy as $meta_key => $rule ) {
			if (
				! isset( $registered[ $meta_key ] )
				|| empty( $rule['read'] )
				|| ( $write && empty( $rule['write'] ) )
			) {
				continue;
			}
			$result[ $meta_key ] = $registered[ $meta_key ];
			$result[ $meta_key ]['composer_contract'] = $rule;
		}
		return $result;
	}

	/** @return array<string,array<string,mixed>> */
	private function registered_fields( string $post_type ): array {
		$result = array();
		foreach ( get_registered_meta_keys( 'post', $post_type ) as $meta_key => $registration ) {
			if ( ! is_array( $registration ) ) {
				continue;
			}
			$meta_key = (string) $meta_key;
			$type     = (string) ( $registration['type'] ?? '' );
			if (
				'' === $meta_key
				|| '_' === $meta_key[0]
				|| empty( $registration['single'] )
				|| empty( $registration['show_in_rest'] )
				|| ! in_array( $type, self::ALLOWED_TYPES, true )
			) {
				continue;
			}
			$result[ $meta_key ] = $registration;
		}
		ksort( $result );
		return $result;
	}

	private function assert_value( mixed $value, array $registration, string $meta_key ): void {
		if ( null === $value ) {
			throw new Execution_Exception( 'content_field_delete_not_supported', 'Null cannot be used to delete a content field.' );
		}
		$encoded = wp_json_encode( $value );
		if ( false === $encoded || strlen( $encoded ) > self::MAX_SERIALIZED_BYTES ) {
			throw new Execution_Exception( 'content_field_value_too_large', 'A content field exceeds the maximum serialized size: ' . $meta_key ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Ability error, not HTML.
		}

		$type = (string) $registration['type'];
		$valid = match ( $type ) {
			'string'  => is_string( $value ) && $this->string_length( $value ) <= self::MAX_STRING_LENGTH,
			'integer' => is_int( $value ),
			'number'  => is_int( $value ) || is_float( $value ),
			'boolean' => is_bool( $value ),
			'array'   => is_array( $value ) && array_is_list( $value ) && count( $value ) <= self::MAX_ARRAY_ITEMS,
			'object'  => is_array( $value ) && ! array_is_list( $value ) && count( $value ) <= self::MAX_OBJECT_KEYS,
			default   => false,
		};
		if ( ! $valid ) {
			throw new Execution_Exception( 'content_field_type_mismatch', 'A content field does not match its registered WordPress type: ' . $meta_key ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Ability error, not HTML.
		}

		$schema = $this->rest_schema( $registration );
		if ( ! empty( $schema ) && function_exists( 'rest_validate_value_from_schema' ) ) {
			$validation = rest_validate_value_from_schema( $value, $schema, 'fields.' . $meta_key );
			if ( is_wp_error( $validation ) ) {
				throw new Execution_Exception( 'content_field_schema_mismatch', $validation->get_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Structured ability error, not HTML output.
			}
		}
	}

	private function assert_relation_value( mixed $value, array $contract, string $meta_key ): void {
		if ( 'relation' !== ( $contract['semantic_type'] ?? '' ) ) {
			return;
		}

		$cardinality = (string) ( $contract['cardinality'] ?? 'many' );
		$ids = 'one' === $cardinality ? array( $value ) : $value;
		if ( ! is_array( $ids ) || ( 'many' === $cardinality && ! array_is_list( $ids ) ) ) {
			throw new Execution_Exception( 'relation_cardinality_mismatch', 'A relation field does not match its declared cardinality: ' . $meta_key ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		if ( count( $ids ) > (int) ( $contract['maximum_items'] ?? 1 ) ) {
			throw new Execution_Exception( 'relation_item_limit_exceeded', 'A relation field exceeds its declared item limit: ' . $meta_key ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		if ( count( $ids ) !== count( array_unique( $ids, SORT_REGULAR ) ) ) {
			throw new Execution_Exception( 'relation_duplicate_target', 'A relation field cannot contain duplicate targets: ' . $meta_key ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		$target_types    = (array) ( $contract['target_post_types'] ?? array() );
		$target_statuses = (array) ( $contract['target_post_statuses'] ?? array( 'publish' ) );
		foreach ( $ids as $id ) {
			if ( ! is_int( $id ) || $id < 1 ) {
				throw new Execution_Exception( 'relation_target_id_invalid', 'A relation target must be a positive integer post ID: ' . $meta_key ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}
			$target = get_post( $id );
			if ( ! $target instanceof \WP_Post ) {
				throw new Execution_Exception( 'relation_target_missing', 'A relation target post does not exist: ' . $meta_key ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}
			if ( ! in_array( $target->post_type, $target_types, true ) ) {
				throw new Execution_Exception( 'relation_target_type_mismatch', 'A relation target uses a forbidden post type: ' . $meta_key ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}
			if ( ! in_array( $target->post_status, $target_statuses, true ) ) {
				throw new Execution_Exception( 'relation_target_status_mismatch', 'A relation target uses a forbidden post status: ' . $meta_key ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			}
		}
	}

	private function rest_schema( array $registration ): array {
		$show = $registration['show_in_rest'] ?? true;
		if ( is_array( $show ) && is_array( $show['schema'] ?? null ) ) {
			return $show['schema'];
		}
		return array( 'type' => (string) $registration['type'] );
	}

	private function public_rest_schema( array $registration ): array {
		$schema = $this->rest_schema( $registration );
		unset( $schema['default'] );
		return $schema;
	}

	private function string_length( string $value ): int {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $value ) : strlen( $value );
	}

	private function relation_match( \WP_Post $post, string $query ): array {
		if ( '' === $query ) {
			return array( 'kind' => 'browse', 'rank' => 3 );
		}
		$needle = function_exists( 'mb_strtolower' ) ? mb_strtolower( $query ) : strtolower( $query );
		$title  = function_exists( 'mb_strtolower' ) ? mb_strtolower( get_the_title( $post ) ) : strtolower( get_the_title( $post ) );
		if ( sanitize_title( $query ) === $post->post_name ) {
			return array( 'kind' => 'exact-slug', 'rank' => 0 );
		}
		if ( $needle === $title ) {
			return array( 'kind' => 'exact-title', 'rank' => 1 );
		}
		return array( 'kind' => 'title-search', 'rank' => 2 );
	}
}
