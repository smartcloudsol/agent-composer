<?php
/* SmartCloud Agent Composer execution contract. */

namespace SmartCloud\AgentComposer\Execution;

final class Content_Language_Validator {
	public function __construct( private Config_Repository $config ) {}

	public function assert_request_language( string $page_type, array $input ): void {
		$blueprint  = $this->config->get_blueprint( $page_type );
		$required   = (string) ( $blueprint['content_language'] ?? '' );
		$enforcement = (string) ( $blueprint['content_language_enforcement'] ?? 'advisory' );
		$submitted  = trim( (string) ( $input['content_language'] ?? '' ) );
		if ( 'strict' === $enforcement && ( '' === $submitted || ! hash_equals( strtolower( $required ), strtolower( $submitted ) ) ) ) {
			throw new Execution_Exception( 'content_language_mismatch', 'The request must explicitly use the Blueprint effective content language: ' . $required ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Ability error, not HTML.
		}
	}

	public function issues( string $page_type, string $text ): array {
		$blueprint = $this->config->get_blueprint( $page_type );
		return $this->issues_for_policy( $blueprint, $text );
	}

	/** @param array<string,mixed> $blueprint */
	public function issues_for_policy( array $blueprint, string $text ): array {
		if ( 'strict' !== (string) ( $blueprint['content_language_enforcement'] ?? 'advisory' ) ) {
			return array();
		}
		$signals = array_values(
			array_filter(
				array_map(
					static fn( mixed $signal ): string => strtolower( trim( (string) $signal ) ),
					(array) ( $blueprint['content_language_mismatch_signals'] ?? array() )
				)
			)
		);
		if ( empty( $signals ) ) {
			return array();
		}
		$text = wp_strip_all_tags( $text );
		foreach ( (array) ( $blueprint['content_language_exceptions'] ?? array() ) as $exception ) {
			$text = str_ireplace( (string) $exception, ' ', $text );
		}
		preg_match_all( '/[\p{L}][\p{L}\p{M}\'-]*/u', strtolower( $text ), $matches );
		$words = $matches[0] ?? array();
		if ( count( $words ) < 8 ) {
			return array();
		}
		$mismatch_signals = array_flip( $signals );
		$hits = 0;
		foreach ( $words as $word ) {
			if ( isset( $mismatch_signals[ $word ] ) ) {
				++$hits;
			}
		}
		if ( $hits >= 3 && ( $hits / count( $words ) ) >= 0.12 ) {
			return array(
				array(
					'code'    => 'content_language_substantial_mismatch',
					'message' => 'Substantial public-copy signals conflict with the strict Blueprint content language policy.',
					'path'    => '',
				),
			);
		}
		return array();
	}
}
