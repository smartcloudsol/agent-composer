<?php
namespace SmartCloud\AgentComposer\Execution;

/** Transport-independent Site Contract relation-value validation. */
final class Relation_Value_Validator {
	public static function assert_value( mixed $value, array $contract, string $meta_key, array $excluded_ids = array() ): void {
		if ( 'relation' !== ( $contract['semantic_type'] ?? '' ) ) {
			return;
		}
		$cardinality = (string) ( $contract['cardinality'] ?? 'many' );
		$ids = 'one' === $cardinality ? array( $value ) : $value;
		if ( ! is_array( $ids ) || ( 'many' === $cardinality && ! array_is_list( $ids ) ) ) {
			throw new Execution_Exception( 'relation_cardinality_mismatch', 'A relation field does not match its declared cardinality: ' . $meta_key );
		}
		if ( count( $ids ) > (int) ( $contract['maximum_items'] ?? 1 ) ) {
			throw new Execution_Exception( 'relation_item_limit_exceeded', 'A relation field exceeds its declared item limit: ' . $meta_key );
		}
		if ( count( $ids ) !== count( array_unique( $ids, SORT_REGULAR ) ) ) {
			throw new Execution_Exception( 'relation_duplicate_target', 'A relation field cannot contain duplicate targets: ' . $meta_key );
		}
		$target_types = (array) ( $contract['target_post_types'] ?? array() );
		$target_statuses = (array) ( $contract['target_post_statuses'] ?? array( 'publish' ) );
		foreach ( $ids as $id ) {
			if ( ! is_int( $id ) || $id < 1 ) {
				throw new Execution_Exception( 'relation_target_id_invalid', 'A relation target must be a positive integer post ID: ' . $meta_key );
			}
			if ( in_array( $id, $excluded_ids, true ) ) {
				throw new Execution_Exception( 'relation_self_reference', 'A relation field cannot target its source or proposal source: ' . $meta_key );
			}
			$target = get_post( $id );
			if ( ! $target instanceof \WP_Post ) {
				throw new Execution_Exception( 'relation_target_missing', 'A relation target post does not exist: ' . $meta_key );
			}
			if ( ! in_array( $target->post_type, $target_types, true ) ) {
				throw new Execution_Exception( 'relation_target_type_mismatch', 'A relation target uses a forbidden post type: ' . $meta_key );
			}
			if ( ! in_array( $target->post_status, $target_statuses, true ) ) {
				throw new Execution_Exception( 'relation_target_status_mismatch', 'A relation target uses a forbidden post status: ' . $meta_key );
			}
		}
	}
}
