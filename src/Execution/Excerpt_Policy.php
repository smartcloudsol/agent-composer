<?php

namespace SmartCloud\AgentComposer\Execution;

final class Excerpt_Policy {
	public const REQUIRED = 'required';
	public const OPTIONAL = 'optional';
	public const DISABLED = 'disabled';

	public static function normalize( mixed $policy ): string {
		$policy = strtolower( trim( (string) $policy ) );
		if ( ! in_array( $policy, array( self::REQUIRED, self::OPTIONAL, self::DISABLED ), true ) ) {
			throw new Execution_Exception( 'invalid_excerpt_policy', 'The blueprint excerpt policy must be required, optional, or disabled.' );
		}
		return $policy;
	}

	public static function sanitize_input( mixed $value, bool $provided, string $policy ): string {
		$policy  = self::normalize( $policy );
		$excerpt = sanitize_text_field( wp_strip_all_tags( (string) $value, true ) );
		$excerpt = trim( preg_replace( '/\s+/u', ' ', $excerpt ) ?? $excerpt );

		if ( self::DISABLED === $policy ) {
			if ( $provided && '' !== $excerpt ) {
				throw new Execution_Exception( 'excerpt_disabled', 'The selected blueprint does not permit a WordPress excerpt.' );
			}
			return '';
		}

		$length = self::length( $excerpt );
		if ( '' === $excerpt && self::OPTIONAL === $policy ) {
			return '';
		}
		if ( $length < 80 || $length > 300 ) {
			throw new Execution_Exception( 'invalid_excerpt_length', 'The WordPress excerpt must contain between 80 and 300 characters when present.' );
		}
		return $excerpt;
	}

	public static function stored_errors( string $excerpt, string $policy ): array {
		$policy = self::normalize( $policy );
		$length = self::length( $excerpt );
		if ( self::DISABLED === $policy && 0 !== $length ) {
			return array(
				array(
					'code'    => 'excerpt_disabled',
					'message' => 'The selected blueprint does not permit a WordPress excerpt.',
					'context' => array( 'found' => $length, 'policy' => $policy ),
				),
			);
		}
		if ( self::REQUIRED === $policy && ( $length < 80 || $length > 300 ) ) {
			return array(
				array(
					'code'    => 'invalid_excerpt_length',
					'message' => 'The WordPress excerpt must contain between 80 and 300 characters.',
					'context' => array( 'found' => $length, 'minimum' => 80, 'maximum' => 300, 'policy' => $policy ),
				),
			);
		}
		if ( self::OPTIONAL === $policy && 0 !== $length && ( $length < 80 || $length > 300 ) ) {
			return array(
				array(
					'code'    => 'invalid_excerpt_length',
					'message' => 'An optional WordPress excerpt must be empty or contain between 80 and 300 characters.',
					'context' => array( 'found' => $length, 'minimum' => 80, 'maximum' => 300, 'policy' => $policy ),
				),
			);
		}
		return array();
	}

	private static function length( string $value ): int {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $value ) : strlen( $value );
	}

	private function __construct() {}
}
