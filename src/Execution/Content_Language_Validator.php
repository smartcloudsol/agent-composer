<?php
/* SmartCloud Agent Composer execution contract. */

namespace SmartCloud\AgentComposer\Execution;

final class Content_Language_Validator {
	public function __construct( private Config_Repository $config ) {}

	public static function is_allowed_language( array $allowlist, string $content_language ): bool {
		$allowed = array_map( static fn( mixed $language ): string => strtolower( trim( (string) $language ) ), $allowlist );
		return in_array( '*', $allowed, true ) || in_array( strtolower( trim( $content_language ) ), $allowed, true );
	}

	public function assert_request_language( string $page_type, array $input ): void {
		$this->request_language( $page_type, $input );
	}

	public function request_language( string $page_type, array $input ): string {
		$blueprint  = $this->config->get_blueprint( $page_type );
		$required   = trim( (string) ( $blueprint['content_language'] ?? '' ) );
		$allowed    = array_map( 'strtolower', (array) ( $blueprint['allowed_content_languages'] ?? array( $required ) ) );
		$enforcement = (string) ( $blueprint['content_language_enforcement'] ?? 'advisory' );
		$submitted  = trim( (string) ( $input['content_language'] ?? '' ) );
		$valid = 1 === preg_match( '/^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$/', $submitted );
		$permitted = self::is_allowed_language( $allowed, $submitted );
		if ( ! $valid || ( ! empty( $allowed ) && ! $permitted ) || ( 'strict' === $enforcement && '' === $submitted ) ) {
			throw new Execution_Exception( 'content_language_mismatch', 'The request must explicitly use a Site Contract-approved Blueprint content language.' );
		}
		foreach ( (array) ( $blueprint['allowed_content_languages'] ?? array() ) as $language ) {
			if ( is_string( $language ) && '*' !== $language && 0 === strcasecmp( $language, $submitted ) ) {
				return $language;
			}
		}
		$parts = explode( '-', $submitted );
		$parts[0] = strtolower( $parts[0] );
		for ( $index = 1; $index < count( $parts ); ++$index ) {
			$parts[ $index ] = 2 === strlen( $parts[ $index ] ) ? strtoupper( $parts[ $index ] ) : $parts[ $index ];
		}
		return implode( '-', $parts );
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
		$allowed = array_unique( (array) ( $blueprint['allowed_content_languages'] ?? array() ) );
		if ( in_array( '*', $allowed, true ) || count( $allowed ) > 1 ) {
			// One global signal list cannot identify the intended language in a
			// multilingual Blueprint. The explicit request allowlist remains strict.
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
