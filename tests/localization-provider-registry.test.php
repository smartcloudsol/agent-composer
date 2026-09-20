<?php

declare(strict_types=1);

namespace {
	class WP_Post {
		public string $post_type = 'page';
		public function __construct( public int $ID ) {}
	}
	function wp_get_ability( string $name ): object { return (object) array( 'name' => $name ); }
	function wp_has_ability( string $name ): bool {
		unset( $name );
		if ( empty( $GLOBALS['localization_registry_reset'] ) ) {
			$GLOBALS['localization_registry_reset'] = true;
			$GLOBALS['localization_registry']->reset();
		}
		return true;
	}
}

namespace SmartCloud\AgentComposer\Execution {
	use RuntimeException;

	final class Config_Repository {
		public function get_design_policy(): array {
			return array( 'localization' => array( 'provider' => 'none', 'allowed_content_languages' => $GLOBALS['localization_test_allowlist'] ) );
		}
		public function get_blueprint( string $page_type ): array {
			unset( $page_type );
			return array( 'content_language' => 'hu-HU', 'allowed_content_languages' => array( 'hu-HU' ) );
		}
	}

	final class Execution_Exception extends RuntimeException {
		public function __construct( private string $execution_code, string $message ) {
			parent::__construct( $message );
		}
	}

	function current_user_can( string $capability, int $post_id = 0 ): bool { return 'read_post' === $capability && 12 === $post_id; }
	function get_locale(): string { return $GLOBALS['localization_test_locale'] ?? 'hu_HU'; }
	function get_post_meta( int $post_id, string $key, bool $single = false ): mixed {
		unset( $single );
		if ( 99 === $post_id && '_wpsuite_agent_page_type' === $key ) {
			return 'physician-profile';
		}
		if ( 12 === $post_id && '_wpsuite_agent_content_language' === $key ) {
			return $GLOBALS['localization_test_stored_language'] ?? '';
		}
		return '';
	}
	function get_post( int $post_id ): ?\WP_Post { return 12 === $post_id ? new \WP_Post( 12 ) : null; }
	function sanitize_key( string $value ): string { return strtolower( (string) preg_replace( '/[^a-z0-9_-]/i', '', $value ) ); }
	function sanitize_text_field( string $value ): string { return trim( strip_tags( $value ) ); }
	function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
		unset( $args );
		if ( Localization_Provider_Registry::FILTER !== $hook ) {
			return $value;
		}
		$names = array_map(
			static fn( string $suffix ): string => 'test-localization/' . $suffix,
			array( 'get-localization-capabilities', 'list-content-languages', 'resolve-localized-content', 'preview-localized-proposal', 'validate-localized-proposal' )
		);
		return array(
			'test' => array(
				'ability_names' => $names,
				'ability_namespace' => 'test-localization',
				'active' => false,
				'contract_version' => '1.0.0',
				'label' => 'Test',
				'mcp_ability_names' => array_slice( $names, 0, 4 ),
				'plugin_version' => '1.0.0',
				'storage_model' => 'separate-posts',
			),
		);
	}

	require_once dirname( __DIR__ ) . '/src/Execution/Content_Language_Validator.php';
	require_once dirname( __DIR__ ) . '/src/Execution/Localization_Provider_Registry.php';

	$registry = new Localization_Provider_Registry( new Config_Repository() );
	$GLOBALS['localization_registry'] = $registry;
	$GLOBALS['localization_registry_reset'] = false;
	$GLOBALS['localization_test_allowlist'] = array( '*' );
	$providers = $registry->all();
	if ( array( 'test' ) !== array_keys( $providers ) ) {
		throw new RuntimeException( 'Ability initialization may reset the registry while it is being populated.' );
	}
	$resolved = $registry->resolve( 12, 'page' );
	if ( 'wordpress' !== $resolved['provider'] || 'hu-HU' !== $resolved['content_language'] || 'hu' !== $resolved['language_code'] ) {
		throw new RuntimeException( 'The monolingual provider must return a complete, stable language context.' );
	}
	$GLOBALS['localization_test_locale'] = 'en_US';
	$blueprint_resolved = $registry->resolve_for_blueprint(
		12,
		'page',
		array( 'content_language' => 'hu-HU', 'allowed_content_languages' => array( 'hu-HU' ) )
	);
	if ( 'hu-HU' !== $blueprint_resolved['content_language'] || 'hu' !== $blueprint_resolved['language_code'] || 'blueprint' !== $blueprint_resolved['language_source'] || 'en_US' !== $blueprint_resolved['locale'] ) {
		throw new RuntimeException( 'A concrete monolingual Blueprint must define authored content language independently from the WordPress UI locale.' );
	}
	$GLOBALS['localization_test_locale'] = 'hu_HU';
	$registry->validate_proposal( $resolved, 12, 99 );
	$supported = $registry->supported_languages();
	if ( 'any-language' !== $supported['authoring_mode'] || true !== in_array( '*', $supported['authorable_languages'], true ) || $supported['localization_available'] || $supported['language_switching'] || $supported['draft_linking'] || $supported['draft_group_attachment'] ) {
		throw new RuntimeException( 'Without an active localization provider, wildcard authoring must remain available without claiming language switching or draft linking.' );
	}
	$GLOBALS['localization_test_allowlist'] = array();
	$registry->reset();
	$supported = $registry->supported_languages();
	if ( 'any-language' !== $supported['authoring_mode'] || array( '*' ) !== $supported['authorable_languages'] ) {
		throw new RuntimeException( 'An omitted language allowlist must preserve the existing unrestricted authoring behavior.' );
	}

	$changed = $resolved;
	$changed['content_language'] = 'en-US';
	try {
		$registry->validate_proposal( $changed, 12, 99 );
		throw new RuntimeException( 'Monolingual language drift must block proposal merge.' );
	} catch ( Execution_Exception ) {
		// Expected.
	}

	echo "localization-provider-registry: ok\n";
}
