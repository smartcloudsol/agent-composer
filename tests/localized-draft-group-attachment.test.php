<?php

declare(strict_types=1);

namespace {
	class WP_Post {
		public string $post_type = 'feature';
		public string $post_status = 'draft';
		public string $post_modified_gmt = '2026-09-03 12:00:00';
		public function __construct( public int $ID ) {}
	}
	function absint( mixed $value ): int { return abs( (int) $value ); }
}

namespace SmartCloud\AgentComposer\Execution {
	use RuntimeException;

	final class Execution_Exception extends RuntimeException {
		public function __construct( public string $execution_code, string $message ) { parent::__construct( $message ); }
	}

	final class Config_Repository {
		public function get_blueprint( string $page_type ): array {
			return 'feature' === $page_type ? array( 'target_post_type' => 'feature' ) : array();
		}
	}

	final class Draft_Service {
		public const CONTENT_LANGUAGE_META = '_wpsuite_agent_content_language';
		public const LOCALIZATION_PROVIDER_META = '_wpsuite_agent_localization_provider';
		public const LANGUAGE_CODE_META = '_wpsuite_agent_language_code';
		public const PAGE_TYPE_META = '_wpsuite_agent_page_type';
		public const REVISION_META = '_wpsuite_agent_revision';

		public function inspect_content_item( array $input ): array {
			if ( 10 !== (int) ( $input['post_id'] ?? 0 ) || 'feature' !== (string) ( $input['page_type'] ?? '' ) ) {
				throw new Execution_Exception( 'content_item_not_found', 'Anchor missing.' );
			}
			return array( 'post_id' => 10, 'status' => 'pending' );
		}

		public function get_owned_draft( int $post_id ): \WP_Post {
			$post = $GLOBALS['attachment_test_posts'][ $post_id ] ?? null;
			if ( ! $post instanceof \WP_Post || 'draft' !== $post->post_status ) {
				throw new Execution_Exception( 'draft_not_found', 'Draft missing.' );
			}
			return $post;
		}
	}

	final class Content_Proposal_Service {
		public const STATE_META = '_wpsuite_agent_proposal_state';
	}

	final class Localization_Provider_Registry {
		public int $attach_calls = 0;
		public array $translations = array( 'en' => 10, 'hu' => 11 );

		public function resolve( int $post_id, string $post_type ): array {
			unset( $post_id, $post_type );
			return array(
				'provider' => 'polylang',
				'localization_group' => isset( $this->translations['fr'] ) ? 'feature:new-group' : 'feature:old-group',
				'translations' => $this->translations,
			);
		}

		public function attach_draft_to_translation_group( string $page_type, string $post_type, int $anchor_post_id, array $draft, string $expected_group ): array {
			unset( $page_type, $post_type, $anchor_post_id, $expected_group );
			++$this->attach_calls;
			$this->translations[ $draft['language_code'] ] = $draft['post_id'];
			ksort( $this->translations );
			return array( 'provider' => 'polylang', 'translations' => $this->translations, 'item' => $draft );
		}
	}

	function sanitize_key( string $value ): string { return strtolower( (string) preg_replace( '/[^a-z0-9_-]/i', '', $value ) ); }
	function sanitize_text_field( string $value ): string { return trim( strip_tags( $value ) ); }
	function get_post_meta( int $post_id, string $key, bool $single = false ): mixed {
		unset( $single );
		return $GLOBALS['attachment_test_meta'][ $post_id ][ $key ] ?? '';
	}
	function add_option( string $name, mixed $value, string $deprecated = '', bool $autoload = false ): bool {
		unset( $name, $value, $deprecated, $autoload );
		return true;
	}
	function wp_generate_uuid4(): string { return '123e4567-e89b-12d3-a456-426614174999'; }
	function wp_json_encode( mixed $value ): string|false { return json_encode( $value ); }

	final class AttachmentTestWpdb {
		public string $options = 'wp_options';
		public function prepare( string $query, mixed ...$args ): string { unset( $args ); return $query; }
		public function query( string $query ): int { unset( $query ); return 1; }
	}
	$GLOBALS['wpdb'] = new AttachmentTestWpdb();
	function wp_cache_delete( string $key, string $group = '' ): void { unset( $key, $group ); }

	$GLOBALS['attachment_test_posts'] = array( 20 => new \WP_Post( 20 ), 21 => new \WP_Post( 21 ) );
	$GLOBALS['attachment_test_meta'] = array(
		20 => array(
			Draft_Service::PAGE_TYPE_META => 'feature',
			Draft_Service::CONTENT_LANGUAGE_META => 'fr-FR',
			Draft_Service::LOCALIZATION_PROVIDER_META => 'polylang',
			Draft_Service::LANGUAGE_CODE_META => 'fr',
			Draft_Service::REVISION_META => '123e4567-e89b-12d3-a456-426614174000',
		),
		21 => array(
			Draft_Service::PAGE_TYPE_META => 'feature',
			Draft_Service::CONTENT_LANGUAGE_META => 'de-DE',
			Draft_Service::LOCALIZATION_PROVIDER_META => 'polylang',
			Draft_Service::LANGUAGE_CODE_META => 'de',
			Draft_Service::REVISION_META => '123e4567-e89b-12d3-a456-426614174001',
		),
	);

	require_once dirname( __DIR__ ) . '/src/Execution/Localized_Draft_Service.php';

	$assert = static function ( bool $condition, string $message ): void {
		if ( ! $condition ) throw new RuntimeException( $message );
	};
	$registry = new Localization_Provider_Registry();
	$service = new Localized_Draft_Service( new Config_Repository(), new Draft_Service(), $registry );
	$request = array(
		'page_type' => 'feature',
		'anchor_post_id' => 10,
		'expected_localization_group' => 'feature:old-group',
		'draft' => array(
			'post_id' => 20,
			'content_language' => 'fr-FR',
			'expected_modified_gmt' => '2026-09-03T12:00:00Z',
			'expected_revision' => '123e4567-e89b-12d3-a456-426614174000',
		),
		'confirm_attach' => true,
	);
	$result = $service->attach_to_group( $request );
	$assert( 20 === $result['translations']['fr'] && 1 === $registry->attach_calls, 'An owned draft must be added to an empty language slot.' );
	$assert( 10 === $result['translations']['en'] && 11 === $result['translations']['hu'], 'Existing group members must remain unchanged regardless of status.' );
	$replay = $service->attach_to_group( $request );
	$assert( true === $replay['idempotent_replay'] && 1 === $registry->attach_calls, 'A stale retry must remain idempotent after the provider group identifier changes.' );

	$registry->translations['de'] = 99;
	$request['draft'] = array(
		'post_id' => 21,
		'content_language' => 'de-DE',
		'expected_modified_gmt' => '2026-09-03T12:00:00Z',
		'expected_revision' => '123e4567-e89b-12d3-a456-426614174001',
	);
	try {
		$service->attach_to_group( $request );
		throw new RuntimeException( 'An occupied target language slot must be rejected.' );
	} catch ( Execution_Exception $error ) {
		$assert( 'localized_draft_language_slot_occupied' === $error->execution_code, 'The occupied-slot failure must be explicit.' );
	}

	echo "localized-draft-group-attachment: ok\n";
}
