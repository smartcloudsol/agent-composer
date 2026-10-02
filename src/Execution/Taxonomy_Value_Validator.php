<?php
namespace SmartCloud\AgentComposer\Execution;

/** Shared strict assignment values; an empty list intentionally clears terms. */
final class Taxonomy_Value_Validator {
	public static function assert_assignment( mixed $values, string $taxonomy, array $rule ): array {
		if ( ! is_array( $values ) || ! array_is_list( $values ) ) {
			throw new Execution_Exception( 'taxonomy_term_ids_invalid', 'term_ids must be a list of positive integer term IDs.' );
		}
		foreach ( $values as $id ) {
			if ( ! is_int( $id ) || $id < 1 ) {
				throw new Execution_Exception( 'taxonomy_term_ids_invalid', 'Every taxonomy term ID must be a positive integer.' );
			}
		}
		if ( count( $values ) !== count( array_unique( $values ) ) ) {
			throw new Execution_Exception( 'taxonomy_term_ids_duplicate', 'Taxonomy term IDs must be unique.' );
		}
		$maximum = min( 100, max( 1, (int) ( $rule['maximum_items'] ?? 20 ) ) );
		if ( count( $values ) > $maximum ) {
			throw new Execution_Exception( 'taxonomy_term_limit_exceeded', 'The assignment exceeds the Site Contract taxonomy-term limit.' );
		}
		foreach ( $values as $id ) {
			if ( ! get_term( $id, $taxonomy ) instanceof \WP_Term ) {
				throw new Execution_Exception( 'taxonomy_term_not_found', 'A requested term does not exist in the selected taxonomy.' );
			}
		}
		return $values;
	}
}
