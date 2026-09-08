<?php

declare(strict_types=1);

namespace SmartCloud\AgentComposer\Execution {
	use RuntimeException;

	final class Execution_Exception extends RuntimeException {
		public function __construct( public string $execution_code, string $message ) { parent::__construct( $message ); }
	}

	final class Config_Repository {
		public function get_blueprint( string $page_type ): array {
			return 'product' === $page_type ? array( 'target_post_type' => 'page' ) : array();
		}
	}

	final class Content_Proposal_Service {
		public const STATE_META = '_wpsuite_agent_proposal_state';
	}

	final class Draft_Service {
		public function __construct( private array $items ) {}
		public function inspect_content_item( array $input ): array {
			$post_id = (int) ( $input['post_id'] ?? 0 );
			if ( 'product' !== (string) ( $input['page_type'] ?? '' ) || ! isset( $this->items[ $post_id ] ) ) {
				throw new Execution_Exception( 'content_item_not_found', 'Missing item.' );
			}
			$item = $this->items[ $post_id ];
			$item['localization'] = $GLOBALS['localized_group_registry']->resolve( $post_id, 'page' );
			return $item;
		}
	}

	final class Localization_Provider_Registry {
		public int $attach_calls = 0;
		public int $merge_calls = 0;
		public array $maps;

		public function __construct() {
			$this->maps = array(
				10 => array( 'en' => 10 ),
				20 => array( 'hu' => 20 ),
				30 => array( 'en' => 30 ),
				31 => array( 'hu' => 31, 'de' => 32, 'es' => 33, 'fr' => 34 ),
				32 => array( 'hu' => 31, 'de' => 32, 'es' => 33, 'fr' => 34 ),
				33 => array( 'hu' => 31, 'de' => 32, 'es' => 33, 'fr' => 34 ),
				34 => array( 'hu' => 31, 'de' => 32, 'es' => 33, 'fr' => 34 ),
			);
		}

		public function resolve( int $post_id, string $post_type ): array {
			unset( $post_type );
			$map = $this->maps[ $post_id ];
			$language = (string) array_search( $post_id, $map, true );
			$locales = array( 'en' => 'en-US', 'hu' => 'hu-HU', 'de' => 'de-DE', 'es' => 'es-ES', 'fr' => 'fr-FR' );
			return array(
				'provider' => 'polylang',
				'language_code' => $language,
				'content_language' => $locales[ $language ],
				'localization_group' => 'page:' . hash( 'sha256', json_encode( $map ) ),
				'translations' => $map,
			);
		}

		public function attach_content_to_translation_group( string $page_type, string $post_type, int $anchor_post_id, array $content, string $expected_group, array $expected_translations ): array {
			unset( $page_type, $post_type, $expected_group, $expected_translations );
			++$this->attach_calls;
			$merged = $this->maps[ $anchor_post_id ];
			$merged[ $content['language_code'] ] = $content['post_id'];
			ksort( $merged );
			foreach ( $merged as $post_id ) $this->maps[ $post_id ] = $merged;
			return array( 'provider' => 'polylang', 'translations' => $merged );
		}

		public function merge_translation_groups( string $page_type, string $post_type, array $target, array $source ): array {
			unset( $page_type, $post_type );
			++$this->merge_calls;
			$merged = array_merge( $target['translations'], $source['translations'] );
			ksort( $merged );
			foreach ( $merged as $post_id ) $this->maps[ $post_id ] = $merged;
			return array( 'provider' => 'polylang', 'translations' => $merged );
		}
	}

	function absint( mixed $value ): int { return abs( (int) $value ); }
	function sanitize_key( string $value ): string { return strtolower( (string) preg_replace( '/[^a-z0-9_-]/i', '', $value ) ); }
	function sanitize_text_field( string $value ): string { return trim( strip_tags( $value ) ); }
	function get_post_meta( int $post_id, string $key, bool $single = false ): mixed { unset( $post_id, $key, $single ); return ''; }
	function current_user_can( string $capability, int $post_id = 0 ): bool { unset( $post_id ); return 'edit_post' === $capability; }
	function add_option( string $name, mixed $value, string $deprecated = '', bool $autoload = false ): bool { unset( $name, $value, $deprecated, $autoload ); return true; }
	function wp_generate_uuid4(): string { return '123e4567-e89b-12d3-a456-426614174999'; }
	function wp_json_encode( mixed $value ): string|false { return json_encode( $value ); }
	function wp_cache_delete( string $key, string $group = '' ): void { unset( $key, $group ); }

	final class LocalizedGroupWpdb {
		public string $options = 'wp_options';
		public function prepare( string $query, mixed ...$args ): string { unset( $args ); return $query; }
		public function query( string $query ): int { unset( $query ); return 1; }
	}
	$GLOBALS['wpdb'] = new LocalizedGroupWpdb();

	$items = array();
	foreach ( array( 10 => 'en-US', 20 => 'hu-HU', 30 => 'en-US', 31 => 'hu-HU', 32 => 'de-DE', 33 => 'es-ES', 34 => 'fr-FR' ) as $post_id => $locale ) {
		$items[ $post_id ] = array(
			'post_id' => $post_id,
			'post_type' => 'page',
			'page_type' => 'product',
			'status' => in_array( $post_id, array( 20, 32 ), true ) ? 'draft' : 'publish',
			'modified_gmt' => '2026-09-08T12:00:00Z',
			'content_hash' => hash( 'sha256', 'content-' . $post_id ),
			'content_language' => $locale,
		);
	}
	$registry = new Localization_Provider_Registry();
	$GLOBALS['localized_group_registry'] = $registry;
	require_once dirname( __DIR__ ) . '/src/Execution/Localized_Draft_Service.php';
	$service = new Localized_Draft_Service( new Config_Repository(), new Draft_Service( $items ), $registry );

	$assert = static function ( bool $condition, string $message ): void {
		if ( ! $condition ) throw new RuntimeException( $message );
	};
	$attach = $service->attach_content_to_group( array(
		'page_type' => 'product',
		'anchor_post_id' => 10,
		'expected_localization_group' => $registry->resolve( 10, 'page' )['localization_group'],
		'content' => array(
			'post_id' => 20,
			'content_language' => 'hu-HU',
			'expected_modified_gmt' => '2026-09-08T12:00:00Z',
			'expected_content_hash' => hash( 'sha256', 'content-20' ),
		),
		'confirm_attach' => true,
	) );
	$assert( array( 'en' => 10, 'hu' => 20 ) === $attach['translations'] && 1 === $registry->attach_calls, 'Draft or published content must attach to an empty target slot.' );

	$target = $registry->resolve( 30, 'page' );
	$source = $registry->resolve( 31, 'page' );
	$request = array(
		'page_type' => 'product',
		'target' => array(
			'anchor_post_id' => 30,
			'expected_localization_group' => $target['localization_group'],
			'translations' => array( array( 'language_code' => 'en', 'post_id' => 30 ) ),
		),
		'source' => array(
			'anchor_post_id' => 31,
			'expected_localization_group' => $source['localization_group'],
			'translations' => array(
				array( 'language_code' => 'hu', 'post_id' => 31 ),
				array( 'language_code' => 'de', 'post_id' => 32 ),
				array( 'language_code' => 'es', 'post_id' => 33 ),
				array( 'language_code' => 'fr', 'post_id' => 34 ),
			),
		),
		'confirm_merge' => true,
	);
	$merged = $service->merge_groups( $request );
	$assert( array( 'de' => 32, 'en' => 30, 'es' => 33, 'fr' => 34, 'hu' => 31 ) === $merged['translations'], 'The exact five-language union must be preserved.' );
	$replay = $service->merge_groups( $request );
	$assert( true === $replay['idempotent_replay'] && 1 === $registry->merge_calls, 'An exact completed merge must replay idempotently.' );

	echo "localized-content-group-merge: ok\n";
}
