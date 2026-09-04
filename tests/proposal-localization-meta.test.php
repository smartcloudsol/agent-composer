<?php

declare(strict_types=1);

namespace SmartCloud\AgentComposer\Execution {
	use RuntimeException;

	$GLOBALS['proposal_localization_meta_test_value'] = array();

	function get_post_meta( int $post_id, string $key, bool $single = false ): mixed {
		unset( $post_id, $key, $single );
		return $GLOBALS['proposal_localization_meta_test_value'];
	}

	function update_post_meta( int $post_id, string $key, mixed $value, mixed $previous_value = '' ): int|bool {
		unset( $post_id, $previous_value );
		if ( Content_Proposal_Service::LOCALIZATION_META === $key ) {
			$GLOBALS['proposal_localization_meta_test_value'] = $value;
		}
		return true;
	}

	function sanitize_key( string $value ): string {
		return strtolower( (string) preg_replace( '/[^a-z0-9_-]/i', '', $value ) );
	}

	function sanitize_text_field( string $value ): string {
		return trim( strip_tags( $value ) );
	}

	require_once dirname( __DIR__ ) . '/src/Execution/Content_Proposal_Service.php';

	$assert = static function ( bool $condition, string $message ): void {
		if ( ! $condition ) {
			throw new RuntimeException( $message );
		}
	};

	$context = array(
		'provider'             => 'polylang',
		'language_code'        => 'en',
		'content_language'     => 'en-US',
		'locale'               => 'en_US',
		'localization_group'   => 'wps_solution:' . str_repeat( 'a', 64 ),
		'element_type'         => 'post_wps_solution',
		'source_language_code' => '',
		'translations'         => array( 'en' => 14612 ),
	);

	$service = ( new \ReflectionClass( Content_Proposal_Service::class ) )->newInstanceWithoutConstructor();
	$method  = new \ReflectionMethod( Content_Proposal_Service::class, 'localization_context' );

	$GLOBALS['proposal_localization_meta_test_value'] = $context;
	$native = $method->invoke( $service, 100 );
	$assert( $context === $native, 'WordPress-native structured proposal localization meta must round-trip.' );

	$GLOBALS['proposal_localization_meta_test_value'] = json_encode( $context, JSON_UNESCAPED_SLASHES );
	$legacy = $method->invoke( $service, 101 );
	$assert( $context === $legacy, 'Legacy JSON proposal localization meta must remain readable.' );

	$GLOBALS['proposal_localization_meta_test_value'] = '';
	$persist = new \ReflectionMethod( Content_Proposal_Service::class, 'persist_localization_context' );
	$persist->invoke( $service, 102, $context );
	$assert( $context === $GLOBALS['proposal_localization_meta_test_value'], 'Proposal localization meta must be explicitly persisted and verified after post insertion.' );

	echo "proposal-localization-meta: ok\n";
}
