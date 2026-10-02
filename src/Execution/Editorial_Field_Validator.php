<?php
namespace SmartCloud\AgentComposer\Execution;

/** Identical public-copy rules for native editors and draft execution. */
final class Editorial_Field_Validator {
	public function __construct( private Config_Repository $config, private Content_Language_Validator $language ) {}

	public static function sanitize_title( mixed $title ): string {
		$title = sanitize_text_field( wp_strip_all_tags( (string) $title, true ) );
		if ( '' === $title || self::length( $title ) > 200 ) {
			throw new Execution_Exception( 'invalid_title', 'The title must contain between 1 and 200 characters.' );
		}
		return $title;
	}

	public function sanitize_fields( array $input, string $page_type ): array {
		$blueprint = $this->config->get_blueprint( $page_type );
		$policy = Excerpt_Policy::normalize( $blueprint['excerpt_policy'] ?? Excerpt_Policy::OPTIONAL );
		$bounds = self::description_bounds( $blueprint );
		return array(
			'excerpt' => Excerpt_Policy::sanitize_input( $input['excerpt'] ?? '', array_key_exists( 'excerpt', $input ), $policy ),
			'excerpt_policy' => $policy,
			'meta_description' => self::sanitize_text( $input['meta_description'] ?? '', 'meta description', $bounds[0], $bounds[1] ),
		);
	}

	public static function sanitize_text( mixed $value, string $label, int $minimum, int $maximum ): string {
		$value = sanitize_text_field( wp_strip_all_tags( (string) $value, true ) );
		$value = trim( preg_replace( '/\s+/u', ' ', $value ) ?? $value );
		if ( self::length( $value ) < $minimum || self::length( $value ) > $maximum ) {
			throw new Execution_Exception( 'invalid_seo_metadata', sprintf( 'The %s must contain between %d and %d characters.', $label, $minimum, $maximum ) );
		}
		return $value;
	}

	public function add_language_issues( array $validation, string $page_type, array $input, array $editorial ): array {
		$text = implode( "\n", array_filter( array( isset( $input['title'] ) ? (string) $input['title'] : '', (string) ( $editorial['excerpt'] ?? '' ), (string) ( $editorial['meta_description'] ?? '' ) ) ) );
		foreach ( $this->language->issues( $page_type, $text ) as $issue ) {
			$validation['errors'][] = array(
				'code' => (string) ( $issue['code'] ?? 'content_language_mismatch' ),
				'message' => (string) ( $issue['message'] ?? 'Public editorial fields conflict with the strict content language policy.' ),
				'context' => array( 'surface' => 'title-excerpt-seo' ),
			);
		}
		$validation['errors'] = array_values( (array) ( $validation['errors'] ?? array() ) );
		$validation['valid'] = empty( $validation['errors'] );
		return $validation;
	}

	public static function describe( string $excerpt, string $description, string $policy ): array {
		return array(
			'excerpt' => $excerpt, 'excerpt_characters' => self::length( $excerpt ), 'excerpt_policy' => $policy,
			'meta_description' => $description, 'meta_description_characters' => self::length( $description ), 'meta_description_provider' => 'Yoast SEO',
		);
	}

	public function stored_errors( string $excerpt, string $description, string $page_type ): array {
		$blueprint = $this->config->get_blueprint( $page_type );
		$policy = Excerpt_Policy::normalize( $blueprint['excerpt_policy'] ?? Excerpt_Policy::OPTIONAL );
		$bounds = self::description_bounds( $blueprint );
		$summary = self::describe( $excerpt, $description, $policy );
		$errors = Excerpt_Policy::stored_errors( $excerpt, $policy );
		if ( $summary['meta_description_characters'] < $bounds[0] || $summary['meta_description_characters'] > $bounds[1] ) {
			$errors[] = array( 'code' => 'invalid_meta_description_length', 'message' => sprintf( 'The Yoast SEO meta description must contain between %d and %d characters.', $bounds[0], $bounds[1] ), 'context' => array( 'found' => $summary['meta_description_characters'], 'minimum' => $bounds[0], 'maximum' => $bounds[1] ) );
		}
		$summary['valid'] = empty( $errors );
		$summary['errors'] = $errors;
		return $summary;
	}

	private static function description_bounds( array $blueprint ): array {
		$rule = (array) ( $blueprint['seo_contract']['meta_description'] ?? array() );
		$minimum = max( 0, (int) ( $rule['minimum_characters'] ?? 120 ) );
		return array( $minimum, max( $minimum, (int) ( $rule['maximum_characters'] ?? 160 ) ) );
	}

	private static function length( string $value ): int {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $value ) : strlen( $value );
	}
}
