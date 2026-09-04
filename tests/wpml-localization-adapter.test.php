<?php

declare(strict_types=1);

namespace {
	define( 'ICL_SITEPRESS_VERSION', '4.test' );
	define( 'SMARTCLOUD_AGENT_COMPOSER_WPML_VERSION', '1.0.0' );
	class WP_Post {
		public int $ID;
		public string $post_type = 'page';
		public string $post_status = 'draft';
		public function __construct( int $id ) { $this->ID = $id; }
	}
	class WP_Error {
		public function __construct( public string $code = '', public string $message = '' ) {}
	}
	function absint( mixed $value ): int { return abs( (int) $value ); }
}

namespace SmartCloud\AgentComposer\Execution {
	final class Localization_Provider_Registry {
		public const FILTER = 'smartcloud_composer_localization_providers';
	}
}

namespace SmartCloud\AgentComposerWpml {
	use RuntimeException;

	$GLOBALS['wpml_test_next_trid'] = 3000;
	$GLOBALS['wpml_test_set_calls'] = 0;
	$GLOBALS['wpml_test_group_overrides'] = array();
	$GLOBALS['wpml_test_posts'] = array(
		12 => new \WP_Post( 12 ),
		13 => new \WP_Post( 13 ),
		14 => new \WP_Post( 14 ),
		99 => new \WP_Post( 99 ),
		201 => new \WP_Post( 201 ),
		202 => new \WP_Post( 202 ),
		203 => new \WP_Post( 203 ),
		204 => new \WP_Post( 204 ),
		205 => new \WP_Post( 205 ),
	);
	$GLOBALS['wpml_test_meta'] = array(
		201 => array( '_wpsuite_agent_owned' => '1', '_wpsuite_agent_page_type' => 'landing-page', '_wpsuite_agent_content_language' => 'hu-HU' ),
		202 => array( '_wpsuite_agent_owned' => '1', '_wpsuite_agent_page_type' => 'landing-page', '_wpsuite_agent_content_language' => 'en-US' ),
		203 => array( '_wpsuite_agent_owned' => '1', '_wpsuite_agent_page_type' => 'landing-page', '_wpsuite_agent_content_language' => 'hu-HU' ),
		204 => array( '_wpsuite_agent_owned' => '1', '_wpsuite_agent_page_type' => 'landing-page', '_wpsuite_agent_content_language' => 'hu-HU' ),
		205 => array( '_wpsuite_agent_owned' => '1', '_wpsuite_agent_page_type' => 'landing-page', '_wpsuite_agent_content_language' => 'fr-FR' ),
	);
	$GLOBALS['wpml_test_details'] = array(
		12 => array( 'trid' => 77, 'language_code' => 'hu', 'source_language_code' => 'en', 'locale' => 'hu_HU' ),
		13 => array( 'trid' => 77, 'language_code' => 'de', 'source_language_code' => 'en', 'locale' => 'de_DE' ),
		14 => array( 'trid' => 77, 'language_code' => 'en', 'source_language_code' => '', 'locale' => 'en_US' ),
		99 => array( 'trid' => 0, 'language_code' => 'hu', 'source_language_code' => '', 'locale' => 'hu_HU' ),
		201 => array( 'trid' => 2010, 'language_code' => 'en', 'source_language_code' => '', 'locale' => 'en_US' ),
		202 => array( 'trid' => 2020, 'language_code' => 'en', 'source_language_code' => '', 'locale' => 'en_US' ),
		203 => array( 'trid' => 2030, 'language_code' => 'en', 'source_language_code' => '', 'locale' => 'en_US' ),
		204 => array( 'trid' => 2040, 'language_code' => 'en', 'source_language_code' => '', 'locale' => 'en_US' ),
		205 => array( 'trid' => 2050, 'language_code' => 'fr', 'source_language_code' => '', 'locale' => 'fr_FR' ),
	);

	function get_post( int $id ): ?\WP_Post { return $GLOBALS['wpml_test_posts'][ $id ] ?? null; }
	function get_post_meta( int $id, string $key, bool $single = false ): mixed {
		unset( $single );
		return $GLOBALS['wpml_test_meta'][ $id ][ $key ] ?? '';
	}
	function current_user_can( string $capability, int $id = 0 ): bool { return 'read_post' !== $capability || ! in_array( $id, array( 13, 14 ), true ); }
	function sanitize_key( string $value ): string { return strtolower( (string) preg_replace( '/[^a-z0-9_-]/i', '', $value ) ); }
	function sanitize_text_field( string $value ): string { return trim( strip_tags( $value ) ); }
	function has_filter( string $hook ): bool { return 'wpml_element_trid' === $hook; }
	function has_action( string $hook ): bool { return 'wpml_set_element_language_details' === $hook; }
	function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool { return true; }
	function remove_filter( string $hook, callable $callback, int $priority = 10 ): bool { return true; }
	function get_preview_post_link( \WP_Post $post ): string { return 'https://example.test/?p=' . $post->ID . '&preview=true'; }
	function wpml_test_group( int $trid ): array {
		if ( isset( $GLOBALS['wpml_test_group_overrides'][ $trid ] ) ) {
			return $GLOBALS['wpml_test_group_overrides'][ $trid ];
		}
		$translations = array();
		foreach ( $GLOBALS['wpml_test_details'] as $post_id => $details ) {
			if ( $trid === (int) $details['trid'] ) {
				$translations[ $details['language_code'] ] = (object) array(
					'element_id' => $post_id,
					'language_code' => $details['language_code'],
					'source_language_code' => $details['source_language_code'],
				);
			}
		}
		return $translations;
	}
	function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
		$post_id = (int) ( $args[0] ?? 0 );
		return match ( $hook ) {
			'wpml_active_languages' => array(
				array( 'language_code' => 'en', 'default_locale' => 'en_US', 'native_name' => 'English', 'active' => true ),
				array( 'language_code' => 'hu', 'default_locale' => 'hu_HU', 'native_name' => 'Magyar', 'active' => false ),
				array( 'language_code' => 'de', 'default_locale' => 'de_DE', 'native_name' => 'Deutsch', 'active' => false ),
				array( 'language_code' => 'fr', 'default_locale' => 'fr_FR', 'native_name' => 'Français', 'active' => false ),
			),
			'wpml_default_language' => 'en',
			'wpml_is_translated_post_type' => true,
			'wpml_element_type' => 'post_page',
			'wpml_element_trid' => $GLOBALS['wpml_test_details'][ $post_id ]['trid'] ?? 0,
			'wpml_element_language_details' => (object) ( $GLOBALS['wpml_test_details'][ (int) ( $args[0]['element_id'] ?? 0 ) ] ?? array() ),
			'wpml_post_language_details' => $GLOBALS['wpml_test_details'][ $post_id ] ?? array(),
			'wpml_get_element_translations' => wpml_test_group( (int) ( $args[0] ?? 0 ) ),
			'wpml_permalink' => 'https://example.test/hu/?p=12&preview=true',
			default => $value,
		};
	}
	function do_action( string $hook, mixed ...$args ): void {
		if ( 'wpml_set_element_language_details' !== $hook || ! is_array( $args[0] ?? null ) ) {
			return;
		}
		$input = $args[0];
		$post_id = (int) ( $input['element_id'] ?? 0 );
		$language_code = sanitize_key( (string) ( $input['language_code'] ?? '' ) );
		$trid = false === ( $input['trid'] ?? null ) ? ++$GLOBALS['wpml_test_next_trid'] : (int) $input['trid'];
		$locales = array( 'en' => 'en_US', 'hu' => 'hu_HU', 'de' => 'de_DE', 'fr' => 'fr_FR' );
		$GLOBALS['wpml_test_details'][ $post_id ] = array(
			'trid' => $trid,
			'language_code' => $language_code,
			'source_language_code' => sanitize_key( (string) ( $input['source_language_code'] ?? '' ) ),
			'locale' => $locales[ $language_code ] ?? '',
		);
		++$GLOBALS['wpml_test_set_calls'];
	}

	require_once dirname( __DIR__ ) . '/integrations/wpml/src/WpmlLocalizationProvider.php';

	$assert = static function ( bool $condition, string $message ): void {
		if ( ! $condition ) throw new RuntimeException( $message );
	};
	$provider = new WpmlLocalizationProvider();
	$provider->before_proposal_insert( array( 'provider' => 'wpml' ), 12, 'page' );
	$assert( false === $provider->suppress_proposal_translation( true, 'page' ), 'The insertion scope must make the proposal post type non-translatable.' );
	$assert( true === $provider->suppress_proposal_translation( true, 'post' ), 'The insertion scope must not affect unrelated post types.' );
	$provider->after_proposal_insert( array( 'provider' => 'wpml' ), 12, 'page' );
	$manifest = $provider->manifest( array() );
	$assert( true === $manifest['wpml']['active'], 'The WPML manifest must become active only with the required runtime hooks.' );
	$assert( 'smartcloud-agent-composer-wpml' === $manifest['wpml']['ability_namespace'], 'The provider must claim the namespace of every exported Ability.' );
	$assert( in_array( 'smartcloud-agent-composer-wpml/assign-draft-language', $manifest['wpml']['ability_names'], true ), 'WPML must expose governed draft language assignment.' );
	$assert( in_array( 'smartcloud-agent-composer-wpml/link-draft-translations', $manifest['wpml']['ability_names'], true ), 'WPML must expose governed draft translation linking.' );
	$assert( in_array( 'smartcloud-agent-composer-wpml/attach-draft-to-translation-group', $manifest['wpml']['ability_names'], true ), 'WPML must expose additive draft attachment to existing translation groups.' );
	$assert( ! in_array( 'smartcloud-agent-composer-wpml/validate-localized-proposal', $manifest['wpml']['mcp_ability_names'], true ), 'Only non-mutating discovery abilities should be exported for direct MCP discovery.' );
	$capabilities = $provider->capabilities();
	$assert( true === $capabilities['links_agent_owned_drafts'], 'WPML must report support for linking separately authored Composer drafts.' );
	$assert( true === $capabilities['attaches_agent_owned_drafts_to_groups'], 'WPML must report support for additive translation-group attachment.' );
	$languages = $provider->languages();
	$assert( 'en-US' === $languages['items'][0]['content_language'], 'WPML locales must normalize to BCP 47 syntax.' );
	$assert( true === $languages['items'][1]['active'] && true === $languages['items'][2]['active'], 'Every language returned by wpml_active_languages must remain authorable even when it is not the current request language.' );
	$resolved = $provider->resolve( array( 'post_id' => 12, 'post_type' => 'page' ) );
	$assert( is_array( $resolved ) && '77' === $resolved['localization_group'], 'The adapter must resolve the WPML translation group.' );
	$assert( 'en' === $resolved['source_language_code'], 'The adapter must derive the source language from the matching WPML translation record.' );
	$assert( array( 'hu' => 12 ) === $resolved['translations'], 'Unreadable translations must not be exposed.' );
	$preview = $provider->preview( array( 'proposal_post_id' => 12, 'language_code' => 'hu' ) );
	$assert( is_array( $preview ) && str_contains( $preview['preview_url'], '/hu/' ), 'WPML must localize the proposal preview URL from the post ID.' );
	$context = array(
		'provider' => 'wpml',
		'localization_group' => '77',
		'language_code' => 'hu',
		'element_type' => 'post_page',
		'source_language_code' => 'en',
		'content_language' => 'hu-HU',
	);
	$assert( true === $provider->validate( array( 'source_post_id' => 12, 'proposal_post_id' => 99, 'context' => $context ) )['valid'], 'An unchanged WPML relationship must validate.' );
	$GLOBALS['wpml_test_details'][99]['trid'] = 901;
	$assert( 'proposal-attached-to-translation-group' === $provider->validate( array( 'source_post_id' => 12, 'proposal_post_id' => 99, 'context' => $context ) )['reason'], 'A WPML-attached proposal must fail closed.' );
	$GLOBALS['wpml_test_details'][99]['trid'] = 0;
	$GLOBALS['wpml_test_details'][12]['trid'] = 88;
	$assert( false === $provider->validate( array( 'source_post_id' => 12, 'proposal_post_id' => 99, 'context' => $context ) )['valid'], 'A changed WPML translation group must block merge.' );
	$GLOBALS['wpml_test_details'][12]['trid'] = 0;
	$assert( $provider->resolve( array( 'post_id' => 12, 'post_type' => 'page' ) ) instanceof \WP_Error, 'A missing WPML translation group must fail closed.' );
	$GLOBALS['wpml_test_details'][12]['trid'] = 77;
	$GLOBALS['wpml_test_group_overrides'][77] = array(
		'hu' => (object) array( 'element_id' => 99, 'language_code' => 'hu', 'source_language_code' => 'en' ),
	);
	$assert( $provider->resolve( array( 'post_id' => 12, 'post_type' => 'page' ) ) instanceof \WP_Error, 'The source post must occupy its declared WPML language slot.' );
	unset( $GLOBALS['wpml_test_group_overrides'][77] );

	$assigned_hu = $provider->assign_draft_language( array( 'post_id' => 201, 'post_type' => 'page', 'content_language' => 'hu-HU' ) );
	$assigned_en = $provider->assign_draft_language( array( 'post_id' => 202, 'post_type' => 'page', 'content_language' => 'en-US' ) );
	$assigned_over_default = $provider->assign_draft_language( array( 'post_id' => 204, 'post_type' => 'page', 'content_language' => 'hu-HU' ) );
	$assert( is_array( $assigned_hu ) && 'hu' === $assigned_hu['language_code'], 'A new Composer draft must receive its requested WPML language.' );
	$assert( is_array( $assigned_en ) && 'en' === $assigned_en['language_code'], 'A second Composer draft must retain its requested WPML language.' );
	$assert( is_array( $assigned_over_default ) && 'hu' === $GLOBALS['wpml_test_details'][204]['language_code'], 'Composer initialization must replace WPML\'s automatic default on a new owned draft.' );
	foreach ( array( 201, 202, 204 ) as $post_id ) {
		$GLOBALS['wpml_test_meta'][ $post_id ]['_wpsuite_agent_localization_provider'] = 'wpml';
	}
	$linked = $provider->link_draft_translations( array(
		'page_type' => 'landing-page',
		'post_type' => 'page',
		'drafts' => array(
			array( 'post_id' => 201, 'language_code' => 'hu', 'content_language' => 'hu-HU' ),
			array( 'post_id' => 202, 'language_code' => 'en', 'content_language' => 'en-US' ),
		),
	) );
	$assert( is_array( $linked ) && array( 'en' => 202, 'hu' => 201 ) === $linked['translations'], 'Separately authored drafts must be linked in one exact WPML translation set.' );
	$assert( $GLOBALS['wpml_test_details'][201]['trid'] === $GLOBALS['wpml_test_details'][202]['trid'], 'WPML must persist one shared translation group.' );
	$assert( '' === $GLOBALS['wpml_test_details'][202]['source_language_code'] && 'en' === $GLOBALS['wpml_test_details'][201]['source_language_code'], 'The configured default language must become the WPML translation-group original.' );
	$set_calls = $GLOBALS['wpml_test_set_calls'];
	$linked_again = $provider->link_draft_translations( array(
		'page_type' => 'landing-page',
		'post_type' => 'page',
		'drafts' => array(
			array( 'post_id' => 202, 'language_code' => 'en', 'content_language' => 'en-US' ),
			array( 'post_id' => 201, 'language_code' => 'hu', 'content_language' => 'hu-HU' ),
		),
	) );
	$assert( is_array( $linked_again ) && $set_calls === $GLOBALS['wpml_test_set_calls'], 'Relinking the same exact WPML draft set must be idempotent.' );

	$provider->assign_draft_language( array( 'post_id' => 203, 'post_type' => 'page', 'content_language' => 'hu-HU' ) );
	$GLOBALS['wpml_test_meta'][203]['_wpsuite_agent_localization_provider'] = 'wpml';
	$GLOBALS['wpml_test_details'][203]['trid'] = 77;
	$GLOBALS['wpml_test_details'][203]['source_language_code'] = 'en';
	$denied = $provider->link_draft_translations( array(
		'page_type' => 'landing-page',
		'post_type' => 'page',
		'drafts' => array(
			array( 'post_id' => 202, 'language_code' => 'en', 'content_language' => 'en-US' ),
			array( 'post_id' => 203, 'language_code' => 'hu', 'content_language' => 'hu-HU' ),
		),
	) );
	$assert( $denied instanceof \WP_Error && 'smartcloud_wpml_draft_already_linked' === $denied->code, 'A draft linked outside the exact requested set must fail closed.' );

	$GLOBALS['wpml_test_details'][203]['trid'] = 2030;
	$GLOBALS['wpml_test_details'][203]['source_language_code'] = '';
	$GLOBALS['wpml_test_posts'][12]->post_status = 'publish';
	$GLOBALS['wpml_test_posts'][13]->post_status = 'pending';
	$GLOBALS['wpml_test_meta'][205]['_wpsuite_agent_localization_provider'] = 'wpml';
	$attached = $provider->attach_draft_to_translation_group( array(
		'page_type' => 'landing-page',
		'post_type' => 'page',
		'anchor_post_id' => 12,
		'expected_localization_group' => '77',
		'draft' => array( 'post_id' => 205, 'language_code' => 'fr', 'content_language' => 'fr-FR' ),
	) );
	$assert( is_array( $attached ) && 205 === $attached['translations']['fr'], 'A draft must attach to an empty WPML language slot regardless of existing member statuses.' );
	$assert( 12 === $attached['translations']['hu'] && 13 === $attached['translations']['de'] && 14 === $attached['translations']['en'], 'WPML attachment must preserve every existing translation slot.' );
	$set_calls = $GLOBALS['wpml_test_set_calls'];
	$attached_again = $provider->attach_draft_to_translation_group( array(
		'page_type' => 'landing-page',
		'post_type' => 'page',
		'anchor_post_id' => 12,
		'expected_localization_group' => '77',
		'draft' => array( 'post_id' => 205, 'language_code' => 'fr', 'content_language' => 'fr-FR' ),
	) );
	$assert( true === $attached_again['idempotent_replay'] && $set_calls === $GLOBALS['wpml_test_set_calls'], 'Repeating a successful WPML attachment must be idempotent.' );

	echo "wpml-localization-adapter: ok\n";
}
