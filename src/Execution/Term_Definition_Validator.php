<?php
namespace SmartCloud\AgentComposer\Execution;

/** Generic public taxonomy-copy and governed parent rules for all transports. */
final class Term_Definition_Validator {
	public function __construct( private Content_Language_Validator $language ) {}

	public function validate( array $input, string $taxonomy, array $rule, array $blueprint, array $stored = array() ): array {
		$creating = empty( $stored );
		$name = sanitize_text_field( (string) ( $input['name'] ?? $stored['name'] ?? '' ) );
		$raw_slug = trim( (string) ( $input['slug'] ?? $stored['slug'] ?? '' ) );
		$slug = sanitize_title( $raw_slug );
		$description = sanitize_textarea_field( (string) ( $input['description'] ?? $stored['description'] ?? '' ) );
		foreach ( array( 'name', 'slug', 'description' ) as $field ) {
			if ( ! $creating && ( ! array_key_exists( $field, $input ) || (string) $input[ $field ] === (string) ( $stored[ $field ] ?? '' ) ) ) {
				continue; // An unrelated partial update does not rewrite legacy copy.
			}
			$value = 'name' === $field ? $name : ( 'slug' === $field ? $slug : $description );
			$maximum = 'description' === $field ? 2000 : 200;
			$length = function_exists( 'mb_strlen' ) ? mb_strlen( $value ) : strlen( $value );
			if ( '' === $value || $length > $maximum || ( 'slug' === $field && $slug !== $raw_slug ) ) {
				throw new Execution_Exception( 'taxonomy_term_' . $field . '_invalid', 'A bounded public taxonomy-term ' . $field . ' is required; slugs must be durable lowercase values.' );
			}
		}
		$copy = array();
		foreach ( array( 'name' => $name, 'description' => $description ) as $field => $value ) {
			if ( $creating || ( array_key_exists( $field, $input ) && (string) $input[ $field ] !== (string) ( $stored[ $field ] ?? '' ) ) ) {
				$copy[] = $value;
			}
		}
		if ( ! empty( $this->language->issues_for_policy( $blueprint, implode( "\n", $copy ) ) ) ) {
			throw new Execution_Exception( 'taxonomy_term_language_mismatch', 'The taxonomy-term copy conflicts with the strict Blueprint content language.' );
		}
		$parent_id = (int) ( $stored['parent'] ?? 0 );
		$parent_provided = array_key_exists( 'parent', $input ) || array_key_exists( 'parent_slug', $input );
		if ( ! $creating && ! array_key_exists( 'parent_slug', $input ) && isset( $input['parent'] ) && $input['parent'] === $parent_id ) {
			$parent_provided = false; // Keeping an existing parent is not a new parent selection.
		}
		if ( $creating || $parent_provided ) {
			$parent_id = 0;
			$parent_slug = trim( (string) ( $input['parent_slug'] ?? '' ) );
			if ( array_key_exists( 'parent', $input ) ) {
				if ( ! is_int( $input['parent'] ) || $input['parent'] < 0 ) {
					throw new Execution_Exception( 'taxonomy_term_parent_not_allowed', 'A taxonomy parent must be a nonnegative integer term ID.' );
				}
				if ( 0 === $input['parent'] && '' !== $parent_slug ) {
					throw new Execution_Exception( 'taxonomy_term_parent_not_allowed', 'Parent ID and slug must refer to the same term.' );
				}
				if ( $input['parent'] > 0 ) {
					$parent = get_term( $input['parent'], $taxonomy );
					if ( ! $parent instanceof \WP_Term ) {
						throw new Execution_Exception( 'taxonomy_term_parent_not_found', 'The requested taxonomy parent does not exist.' );
					}
					if ( '' !== $parent_slug && $parent_slug !== $parent->slug ) {
						throw new Execution_Exception( 'taxonomy_term_parent_not_allowed', 'Parent ID and slug must refer to the same term.' );
					}
					$parent_slug = $parent->slug;
				}
			}
			if ( '' !== $parent_slug ) {
				$object = get_taxonomy( $taxonomy );
				if ( empty( $object->hierarchical ) || 'allowlist' !== ( $rule['creation_parent_policy'] ?? 'root-only' ) || ! in_array( $parent_slug, (array) ( $rule['creation_parent_slugs'] ?? array() ), true ) ) {
					throw new Execution_Exception( 'taxonomy_term_parent_not_allowed', 'The active Site Contract does not allow this taxonomy parent.' );
				}
				$parent = get_term_by( 'slug', $parent_slug, $taxonomy );
				if ( ! $parent instanceof \WP_Term ) {
					throw new Execution_Exception( 'taxonomy_term_parent_not_found', 'The allowed parent slug does not resolve to an existing term.' );
				}
				$parent_id = (int) $parent->term_id;
				if ( isset( $stored['term_id'] ) && $parent_id === (int) $stored['term_id'] ) {
					throw new Execution_Exception( 'taxonomy_term_parent_not_allowed', 'A taxonomy term cannot be its own parent.' );
				}
			}
		}
		return array( 'name' => $name, 'slug' => $slug, 'description' => $description, 'parent' => $parent_id );
	}
}
