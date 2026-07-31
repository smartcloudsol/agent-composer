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
			$fields[] = array(
				'key'         => $meta_key,
				'type'        => (string) $registration['type'],
				'description' => sanitize_text_field( (string) ( $registration['description'] ?? '' ) ),
				'read'        => true,
				'write'       => ! empty( $rule['write'] ),
				'rest_schema' => $this->public_rest_schema( $registration ),
			);
		}

		return array(
			'page_type'        => $page_type,
			'target_post_type' => $post_type,
			'fields'            => $fields,
			'write_boundary'    => 'composer-owned-assigned-draft',
			'delete_supported'  => false,
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
			$sanitized = sanitize_meta( $meta_key, $value, 'post', $post_type );
			$this->assert_value( $sanitized, $allowed[ $meta_key ], $meta_key );
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
				throw new Execution_Exception( 'content_field_schema_mismatch', $validation->get_error_message() );
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
}
