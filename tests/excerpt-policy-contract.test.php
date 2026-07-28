<?php

declare(strict_types=1);

namespace SmartCloud\AgentComposer\Execution {
	use RuntimeException;

	function wp_strip_all_tags( string $value, bool $remove_breaks = false ): string {
		return strip_tags( $value );
	}

	function sanitize_text_field( string $value ): string {
		return trim( $value );
	}

	require_once dirname( __DIR__ ) . '/src/Execution/Execution_Exception.php';
	require_once dirname( __DIR__ ) . '/src/Execution/Excerpt_Policy.php';

	function assert_same( mixed $expected, mixed $actual, string $message ): void {
		if ( $expected !== $actual ) {
			throw new RuntimeException(
				$message . sprintf( ' Expected %s, got %s.', var_export( $expected, true ), var_export( $actual, true ) )
			);
		}
	}

	function assert_excerpt_error( string $expected_code, callable $operation, string $message ): void {
		try {
			$operation();
		} catch ( Execution_Exception $error ) {
			assert_same( $expected_code, $error->get_execution_code(), $message );
			return;
		}
		throw new RuntimeException( $message . ' No exception was thrown.' );
	}

	$valid_excerpt = str_repeat( 'a', 80 );

	assert_excerpt_error(
		'invalid_excerpt_length',
		static fn() => Excerpt_Policy::sanitize_input( '', false, Excerpt_Policy::REQUIRED ),
		'A required excerpt must not be omitted.'
	);
	assert_same(
		$valid_excerpt,
		Excerpt_Policy::sanitize_input( $valid_excerpt, true, Excerpt_Policy::REQUIRED ),
		'A required excerpt within the contract must be accepted.'
	);

	assert_same( '', Excerpt_Policy::sanitize_input( '', false, Excerpt_Policy::OPTIONAL ), 'An optional excerpt may be omitted.' );
	assert_same( '', Excerpt_Policy::sanitize_input( '', true, Excerpt_Policy::OPTIONAL ), 'An optional excerpt may be explicitly empty.' );
	assert_same(
		$valid_excerpt,
		Excerpt_Policy::sanitize_input( $valid_excerpt, true, Excerpt_Policy::OPTIONAL ),
		'An optional excerpt within the contract must be accepted.'
	);
	assert_excerpt_error(
		'invalid_excerpt_length',
		static fn() => Excerpt_Policy::sanitize_input( str_repeat( 'a', 79 ), true, Excerpt_Policy::OPTIONAL ),
		'A non-empty optional excerpt below the minimum must be rejected.'
	);

	assert_same( '', Excerpt_Policy::sanitize_input( '', false, Excerpt_Policy::DISABLED ), 'A disabled excerpt may be omitted.' );
	assert_same( '', Excerpt_Policy::sanitize_input( '', true, Excerpt_Policy::DISABLED ), 'A disabled excerpt may be explicitly empty.' );
	assert_excerpt_error(
		'excerpt_disabled',
		static fn() => Excerpt_Policy::sanitize_input( $valid_excerpt, true, Excerpt_Policy::DISABLED ),
		'A disabled excerpt must reject submitted content.'
	);

	assert_same( array(), Excerpt_Policy::stored_errors( '', Excerpt_Policy::OPTIONAL ), 'Stored optional empty excerpts must remain valid.' );
	assert_same( 'excerpt_disabled', Excerpt_Policy::stored_errors( $valid_excerpt, Excerpt_Policy::DISABLED )[0]['code'], 'Stored disabled excerpts must be detected.' );

	echo "excerpt-policy-contract: ok\n";
}
