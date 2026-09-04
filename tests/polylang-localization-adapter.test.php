<?php

declare(strict_types=1);

namespace {
	define( 'SMARTCLOUD_AGENT_COMPOSER_POLYLANG_VERSION', '1.0.0' );
	class WP_Post {
		public string $post_type = 'page';
		public string $post_status = 'draft';
		public function __construct( public int $ID ) {}
	}
	class WP_Error {
		public function __construct( public string $code = '', public string $message = '' ) {}
	}
	$GLOBALS['polylang_test_posts'] = array( 12 => new WP_Post( 12 ), 13 => new WP_Post( 13 ), 99 => new WP_Post( 99 ), 201 => new WP_Post( 201 ), 202 => new WP_Post( 202 ), 203 => new WP_Post( 203 ), 204 => new WP_Post( 204 ), 205 => new WP_Post( 205 ) );
	$GLOBALS['polylang_test_languages'] = array( 12 => 'hu', 13 => 'en', 99 => 'hu', 204 => 'en' );
	$GLOBALS['polylang_test_translations'] = array(
		12 => array( 'hu' => 12, 'en' => 13 ),
		13 => array( 'hu' => 12, 'en' => 13 ),
		99 => array( 'hu' => 99 ),
	);
	$GLOBALS['polylang_test_meta'] = array(
		201 => array( '_wpsuite_agent_owned' => '1', '_wpsuite_agent_page_type' => 'landing-page', '_wpsuite_agent_localization_provider' => 'polylang' ),
		202 => array( '_wpsuite_agent_owned' => '1', '_wpsuite_agent_page_type' => 'landing-page', '_wpsuite_agent_localization_provider' => 'polylang' ),
		203 => array( '_wpsuite_agent_owned' => '1', '_wpsuite_agent_page_type' => 'landing-page', '_wpsuite_agent_localization_provider' => 'polylang' ),
		204 => array( '_wpsuite_agent_owned' => '1', '_wpsuite_agent_page_type' => 'landing-page', '_wpsuite_agent_content_language' => 'hu-HU' ),
		205 => array( '_wpsuite_agent_owned' => '1', '_wpsuite_agent_page_type' => 'landing-page', '_wpsuite_agent_localization_provider' => 'polylang' ),
	);
	function get_post( int $id ): ?WP_Post { return $GLOBALS['polylang_test_posts'][ $id ] ?? null; }
	function get_post_meta( int $id, string $key, bool $single = false ): mixed {
		unset( $single );
		return $GLOBALS['polylang_test_meta'][ $id ][ $key ] ?? '';
	}
	function current_user_can( string $capability, int $id = 0 ): bool { return 'read_post' !== $capability || 13 !== $id; }
	function absint( mixed $value ): int { return abs( (int) $value ); }
	function sanitize_key( string $value ): string { return strtolower( (string) preg_replace( '/[^a-z0-9_-]/i', '', $value ) ); }
	function sanitize_text_field( string $value ): string { return trim( strip_tags( $value ) ); }
	function get_locale(): string { return 'hu_HU'; }
	function wp_json_encode( mixed $value, int $flags = 0 ): string|false { return json_encode( $value, $flags ); }
	function get_preview_post_link( WP_Post $post ): string { return 'https://example.test/?p=' . $post->ID . '&preview=true'; }
	function add_query_arg( string $key, string $value, string $url ): string { return $url . '&' . rawurlencode( $key ) . '=' . rawurlencode( $value ); }
	function pll_languages_list( array $args = array() ): array {
		return match ( $args['fields'] ?? 'slug' ) {
			'locale' => array( 'hu_HU', 'en_US', 'fr_FR' ),
			'name' => array( 'Magyar', 'English', 'Français' ),
			default => array( 'hu', 'en', 'fr' ),
		};
	}
	function pll_default_language( string $field = 'slug' ): string { return 'locale' === $field ? 'hu_HU' : 'hu'; }
	function pll_get_post_language( int $post_id, string $field = 'slug' ): string|false {
		$slug = $GLOBALS['polylang_test_languages'][ $post_id ] ?? false;
		$locales = array( 'hu' => 'hu_HU', 'en' => 'en_US', 'fr' => 'fr_FR' );
		return false === $slug || 'slug' === $field ? $slug : ( $locales[ $slug ] ?? false );
	}
	function pll_get_post_translations( int $post_id ): array { return $GLOBALS['polylang_test_translations'][ $post_id ] ?? array(); }
	function pll_is_translated_post_type( string $post_type ): bool { return 'page' === $post_type; }
	function pll_set_post_language( int $post_id, string $language_code ): void {
		$GLOBALS['polylang_test_languages'][ $post_id ] = $language_code;
		$GLOBALS['polylang_test_translations'][ $post_id ] = array( $language_code => $post_id );
	}
	function pll_save_post_translations( array $translations ): void {
		ksort( $translations );
		foreach ( $translations as $post_id ) {
			$GLOBALS['polylang_test_translations'][ $post_id ] = $translations;
		}
	}
}

namespace SmartCloud\AgentComposer\Execution {
	final class Localization_Provider_Registry {
		public const FILTER = 'smartcloud_composer_localization_providers';
	}
}

namespace SmartCloud\AgentComposerPolylang {
	use RuntimeException;

	require_once dirname( __DIR__ ) . '/integrations/polylang/src/PolylangLocalizationProvider.php';

	$assert = static function ( bool $condition, string $message ): void {
		if ( ! $condition ) throw new RuntimeException( $message );
	};
	$provider = new PolylangLocalizationProvider();
	$manifest = $provider->manifest( array() );
	$assert( true === $manifest['polylang']['active'], 'The Polylang bridge must activate only when its public API is available.' );
	$assert( '1.0.0' === $manifest['polylang']['plugin_version'], 'The manifest must report the bridge version.' );
	$assert( in_array( 'smartcloud-agent-composer-polylang/attach-draft-to-translation-group', $manifest['polylang']['ability_names'], true ), 'Polylang must expose additive draft attachment to existing translation groups.' );
	$assert( true === $provider->capabilities()['attaches_agent_owned_drafts_to_groups'], 'Polylang must report support for additive translation-group attachment.' );
	$languages = $provider->languages();
	$assert( 'hu-HU' === $languages['items'][0]['content_language'], 'Polylang locales must normalize to BCP 47 syntax.' );
	$resolved = $provider->resolve( array( 'post_id' => 12, 'post_type' => 'page' ) );
	$assert( is_array( $resolved ) && str_starts_with( $resolved['localization_group'], 'page:' ), 'The bridge must derive a stable source group fingerprint.' );
	$assert( array( 'hu' => 12 ) === $resolved['translations'], 'Unreadable translations must not be exposed.' );
	$provider->assign_proposal_language( array( 'provider' => 'polylang', 'language_code' => 'hu' ), 12, 'page', 99 );
	$assert( 'hu' === pll_get_post_language( 99, 'slug' ), 'A proposal must receive the source language.' );
	$preview = $provider->preview( array( 'proposal_post_id' => 99, 'language_code' => 'hu' ) );
	$assert( is_array( $preview ) && str_contains( $preview['preview_url'], 'lang=hu' ), 'The proposal preview must carry a validated Polylang language.' );
	$context = array(
		'provider' => 'polylang',
		'localization_group' => $resolved['localization_group'],
		'language_code' => 'hu',
		'element_type' => 'post_page',
		'source_language_code' => '',
		'content_language' => 'hu-HU',
	);
	$assert( true === $provider->validate( array( 'source_post_id' => 12, 'proposal_post_id' => 99, 'context' => $context ) )['valid'], 'An unchanged Polylang relationship must validate.' );
	$GLOBALS['polylang_test_translations'][99] = array( 'hu' => 99, 'en' => 13 );
	$assert( 'proposal-attached-to-source-translation-group' === $provider->validate( array( 'source_post_id' => 12, 'proposal_post_id' => 99, 'context' => $context ) )['reason'], 'A source-linked proposal must fail closed.' );
	$GLOBALS['polylang_test_translations'][99] = array( 'hu' => 99 );
	$GLOBALS['polylang_test_translations'][12]['de'] = 14;
	$assert( false === $provider->validate( array( 'source_post_id' => 12, 'proposal_post_id' => 99, 'context' => $context ) )['valid'], 'A changed source translation group must block merge.' );

	$assigned_hu = $provider->assign_draft_language( array( 'post_id' => 201, 'post_type' => 'page', 'content_language' => 'hu-HU' ) );
	$assigned_en = $provider->assign_draft_language( array( 'post_id' => 202, 'post_type' => 'page', 'content_language' => 'en-US' ) );
	$assigned_over_default = $provider->assign_draft_language( array( 'post_id' => 204, 'post_type' => 'page', 'content_language' => 'hu-HU' ) );
	$assert( is_array( $assigned_hu ) && 'hu' === $assigned_hu['language_code'], 'A new Composer draft must receive its requested Polylang language.' );
	$assert( is_array( $assigned_en ) && 'en' === $assigned_en['language_code'], 'A second Composer draft must receive its requested Polylang language.' );
	$assert( is_array( $assigned_over_default ) && 'hu' === pll_get_post_language( 204, 'slug' ), 'Composer initialization must replace Polylang\'s automatic default on a new owned draft.' );
	$linked = $provider->link_draft_translations( array(
		'page_type' => 'landing-page',
		'post_type' => 'page',
		'drafts' => array(
			array( 'post_id' => 201, 'language_code' => 'hu', 'content_language' => 'hu-HU' ),
			array( 'post_id' => 202, 'language_code' => 'en', 'content_language' => 'en-US' ),
		),
	) );
	$assert( is_array( $linked ) && array( 'en' => 202, 'hu' => 201 ) === $linked['translations'], 'Separately authored drafts must be linked in one exact Polylang translation set.' );
	$assert( array( 'en' => 202, 'hu' => 201 ) === pll_get_post_translations( 201 ), 'Polylang must persist the complete translation relationship.' );

	$provider->assign_draft_language( array( 'post_id' => 203, 'post_type' => 'page', 'content_language' => 'hu-HU' ) );
	$GLOBALS['polylang_test_translations'][203] = array( 'hu' => 203, 'en' => 13 );
	$denied = $provider->link_draft_translations( array(
		'page_type' => 'landing-page',
		'post_type' => 'page',
		'drafts' => array(
			array( 'post_id' => 202, 'language_code' => 'en', 'content_language' => 'en-US' ),
			array( 'post_id' => 203, 'language_code' => 'hu', 'content_language' => 'hu-HU' ),
		),
	) );
	$assert( $denied instanceof \WP_Error && 'smartcloud_polylang_draft_already_linked' === $denied->code, 'A draft linked outside the exact requested set must fail closed.' );

	$GLOBALS['polylang_test_posts'][12]->post_status = 'publish';
	$GLOBALS['polylang_test_posts'][13]->post_status = 'pending';
	$GLOBALS['polylang_test_languages'][205] = 'fr';
	$GLOBALS['polylang_test_translations'][205] = array( 'fr' => 205 );
	$group = $provider->resolve( array( 'post_id' => 12, 'post_type' => 'page' ) );
	$attached = $provider->attach_draft_to_translation_group( array(
		'page_type' => 'landing-page',
		'post_type' => 'page',
		'anchor_post_id' => 12,
		'expected_localization_group' => $group['localization_group'],
		'draft' => array( 'post_id' => 205, 'language_code' => 'fr', 'content_language' => 'fr-FR' ),
	) );
	$assert( is_array( $attached ) && 205 === $attached['translations']['fr'], 'A draft must attach to an empty Polylang language slot regardless of existing member statuses.' );
	$assert( 12 === $attached['translations']['hu'] && 13 === $attached['translations']['en'], 'Polylang attachment must preserve every existing translation slot.' );
	$attached_again = $provider->attach_draft_to_translation_group( array(
		'page_type' => 'landing-page',
		'post_type' => 'page',
		'anchor_post_id' => 12,
		'expected_localization_group' => $group['localization_group'],
		'draft' => array( 'post_id' => 205, 'language_code' => 'fr', 'content_language' => 'fr-FR' ),
	) );
	$assert( true === $attached_again['idempotent_replay'], 'Repeating a successful Polylang attachment must be idempotent even though its group fingerprint changed.' );

	echo "polylang-localization-adapter: ok\n";
}
