<?php

declare(strict_types=1);

namespace {
	define( 'SMARTCLOUD_AGENT_COMPOSER_POLYLANG_VERSION', '1.0.0' );
	class WP_Post {
		public string $post_type = 'page';
		public string $post_status = 'draft';
		public function __construct( public int $ID, string $post_status = 'draft' ) { $this->post_status = $post_status; }
	}
	class WP_Error {
		public function __construct( public string $code = '', public string $message = '' ) {}
	}
	$GLOBALS['polylang_test_posts'] = array( 12 => new WP_Post( 12 ), 13 => new WP_Post( 13 ), 99 => new WP_Post( 99 ), 201 => new WP_Post( 201 ), 202 => new WP_Post( 202 ), 203 => new WP_Post( 203 ), 204 => new WP_Post( 204 ), 205 => new WP_Post( 205 ) );
	foreach ( array( 301, 302, 303, 304, 401, 402, 501, 502, 503, 504, 505, 506, 601, 602, 15184 ) as $post_id ) {
		$GLOBALS['polylang_test_posts'][ $post_id ] = new WP_Post( $post_id, 'publish' );
	}
	$GLOBALS['polylang_test_languages'] = array(
		12 => 'hu', 13 => 'en', 99 => 'hu', 204 => 'en',
		301 => 'hu', 302 => 'de', 303 => 'es', 304 => 'fr', 401 => 'en', 402 => 'en',
		501 => 'en', 502 => 'fr', 503 => 'de', 504 => 'es', 505 => 'de', 506 => 'fr', 601 => 'en', 602 => 'hu', 15184 => 'en',
	);
	$GLOBALS['polylang_test_translations'] = array(
		12 => array( 'hu' => 12, 'en' => 13 ),
		13 => array( 'hu' => 12, 'en' => 13 ),
		99 => array( 'hu' => 99 ),
		301 => array( 'hu' => 301 ),
		302 => array( 'de' => 302 ),
		303 => array( 'es' => 303 ),
		304 => array( 'fr' => 304 ),
		401 => array( 'en' => 401 ),
		402 => array( 'en' => 402 ),
		501 => array( 'en' => 501 ),
		502 => array( 'fr' => 502 ),
		503 => array( 'de' => 503, 'es' => 504 ),
		504 => array( 'de' => 503, 'es' => 504 ),
		505 => array( 'de' => 505 ),
		506 => array( 'fr' => 506 ),
		601 => array( 'en' => 601 ),
		602 => array( 'hu' => 602 ),
		15184 => array( 'en' => 15184 ),
	);
	$GLOBALS['polylang_test_denied_edits'] = array();
	$GLOBALS['polylang_test_corrupt_next_save'] = false;
	$GLOBALS['polylang_test_saves'] = array();
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
	function current_user_can( string $capability, int $id = 0 ): bool {
		if ( 'edit_post' === $capability ) {
			return ! in_array( $id, $GLOBALS['polylang_test_denied_edits'], true );
		}
		return 'read_post' !== $capability || 13 !== $id;
	}
	function absint( mixed $value ): int { return abs( (int) $value ); }
	function sanitize_key( string $value ): string { return strtolower( (string) preg_replace( '/[^a-z0-9_-]/i', '', $value ) ); }
	function sanitize_text_field( string $value ): string { return trim( strip_tags( $value ) ); }
	function get_locale(): string { return 'hu_HU'; }
	function wp_json_encode( mixed $value, int $flags = 0 ): string|false { return json_encode( $value, $flags ); }
	function get_preview_post_link( WP_Post $post ): string { return 'https://example.test/?p=' . $post->ID . '&preview=true'; }
	function add_query_arg( string $key, string $value, string $url ): string { return $url . '&' . rawurlencode( $key ) . '=' . rawurlencode( $value ); }
	function pll_languages_list( array $args = array() ): array {
		return match ( $args['fields'] ?? 'slug' ) {
			'locale' => array( 'hu_HU', 'en_US', 'de_DE', 'es_ES', 'fr_FR' ),
			'name' => array( 'Magyar', 'English', 'Deutsch', 'Español', 'Français' ),
			default => array( 'hu', 'en', 'de', 'es', 'fr' ),
		};
	}
	function pll_default_language( string $field = 'slug' ): string { return 'locale' === $field ? 'hu_HU' : 'hu'; }
	function pll_get_post_language( int $post_id, string $field = 'slug' ): string|false {
		$slug = $GLOBALS['polylang_test_languages'][ $post_id ] ?? false;
		$locales = array( 'hu' => 'hu_HU', 'en' => 'en_US', 'de' => 'de_DE', 'es' => 'es_ES', 'fr' => 'fr_FR' );
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
		$GLOBALS['polylang_test_saves'][] = $translations;
		$stored = $translations;
		if ( true === $GLOBALS['polylang_test_corrupt_next_save'] ) {
			$GLOBALS['polylang_test_corrupt_next_save'] = false;
			array_pop( $stored );
		}
		foreach ( $stored as $post_id ) {
			$GLOBALS['polylang_test_translations'][ $post_id ] = $stored;
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
	$assert( in_array( 'smartcloud-agent-composer-polylang/attach-content-to-translation-group', $manifest['polylang']['ability_names'], true ), 'Polylang must expose verified draft or published-content attachment.' );
	$assert( in_array( 'smartcloud-agent-composer-polylang/merge-translation-groups', $manifest['polylang']['ability_names'], true ), 'Polylang must expose verified translation-group merging.' );
	$assert( ! in_array( 'smartcloud-agent-composer-polylang/attach-content-to-translation-group', $manifest['polylang']['mcp_ability_names'], true ) && ! in_array( 'smartcloud-agent-composer-polylang/merge-translation-groups', $manifest['polylang']['mcp_ability_names'], true ), 'Polylang mutation abilities must remain outside the direct MCP provider surface.' );
	$assert( true === $provider->capabilities()['attaches_agent_owned_drafts_to_groups'], 'Polylang must report support for additive translation-group attachment.' );
	$assert( true === $provider->capabilities()['attaches_verified_content_to_groups'], 'Polylang must report support for service-verified content attachment.' );
	$assert( true === $provider->capabilities()['merges_verified_translation_groups'], 'Polylang must report support for exact translation-group merging.' );
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

	$published_target = $provider->resolve( array( 'post_id' => 501, 'post_type' => 'page' ) );
	$published_attach_input = array(
		'page_type' => 'landing-page',
		'post_type' => 'page',
		'anchor_post_id' => 501,
		'expected_target_localization_group' => $published_target['localization_group'],
		'target_translations' => $published_target['translations'],
		'content' => array( 'post_id' => 502, 'language_code' => 'fr', 'content_language' => 'fr-FR' ),
	);
	$published_attach = $provider->attach_content_to_translation_group( $published_attach_input );
	$assert( is_array( $published_attach ) && array( 'en' => 501, 'fr' => 502 ) === $published_attach['translations'], 'Service-verified published content must attach only to an empty target-language slot.' );
	$assert( 'publish' === get_post( 502 )->post_status, 'Published-content attachment must not depend on draft ownership metadata.' );
	$published_attach_again = $provider->attach_content_to_translation_group( $published_attach_input );
	$assert( is_array( $published_attach_again ) && true === $published_attach_again['idempotent_replay'], 'Published-content attachment must support idempotent replay with the inspected fingerprint.' );
	$occupied_slot = $published_attach_input;
	$occupied_slot['content']['post_id'] = 506;
	$occupied_slot_denied = $provider->attach_content_to_translation_group( $occupied_slot );
	$assert( $occupied_slot_denied instanceof \WP_Error && 'smartcloud_polylang_language_slot_occupied' === $occupied_slot_denied->code && 502 === pll_get_post_translations( 501 )['fr'], 'Content attachment must never overwrite an occupied target-language slot.' );

	$linked_source_target = $provider->resolve( array( 'post_id' => 501, 'post_type' => 'page' ) );
	$linked_source_denied = $provider->attach_content_to_translation_group( array(
		'page_type' => 'landing-page',
		'post_type' => 'page',
		'anchor_post_id' => 501,
		'expected_target_localization_group' => $linked_source_target['localization_group'],
		'target_translations' => $linked_source_target['translations'],
		'content' => array( 'post_id' => 503, 'language_code' => 'de', 'content_language' => 'de-DE' ),
	) );
	$assert( $linked_source_denied instanceof \WP_Error && 'smartcloud_polylang_content_already_linked' === $linked_source_denied->code, 'Content moved into a target group must originate from a self-only source group.' );
	$GLOBALS['polylang_test_denied_edits'] = array( 505 );
	$permission_denied = $provider->attach_content_to_translation_group( array(
		'page_type' => 'landing-page',
		'post_type' => 'page',
		'anchor_post_id' => 501,
		'expected_target_localization_group' => $linked_source_target['localization_group'],
		'target_translations' => $linked_source_target['translations'],
		'content' => array( 'post_id' => 505, 'language_code' => 'de', 'content_language' => 'de-DE' ),
	) );
	$assert( $permission_denied instanceof \WP_Error && 'smartcloud_polylang_content_group_attachment_invalid' === $permission_denied->code, 'Content attachment must require edit_post for the moved content.' );
	$GLOBALS['polylang_test_denied_edits'] = array();

	$merge_groups = static function ( PolylangLocalizationProvider $provider, int $target_id, int $source_id ): array {
		$target = $provider->resolve( array( 'post_id' => $target_id, 'post_type' => 'page' ) );
		$source = $provider->resolve( array( 'post_id' => $source_id, 'post_type' => 'page' ) );
		$input = array(
			'page_type' => 'landing-page',
			'post_type' => 'page',
			'target_anchor_post_id' => $target_id,
			'source_anchor_post_id' => $source_id,
			'expected_target_localization_group' => $target['localization_group'],
			'expected_source_localization_group' => $source['localization_group'],
			'target_translations' => pll_get_post_translations( $target_id ),
			'source_translations' => pll_get_post_translations( $source_id ),
		);
		return array( $provider->merge_translation_groups( $input ), $input );
	};

	list( $merged_hu ) = $merge_groups( $provider, 15184, 301 );
	$assert( is_array( $merged_hu ) && array( 'en' => 15184, 'hu' => 301 ) === $merged_hu['translations'], 'The English source and Hungarian translation groups must merge without losing either slot.' );
	list( $merged_de ) = $merge_groups( $provider, 15184, 302 );
	$assert( is_array( $merged_de ) && 302 === $merged_de['translations']['de'], 'The separate German group must join the verified target map.' );
	list( $merged_es ) = $merge_groups( $provider, 15184, 303 );
	$assert( is_array( $merged_es ) && 303 === $merged_es['translations']['es'], 'The separate Spanish group must join the verified target map.' );
	list( $merged_fr, $last_merge_input ) = $merge_groups( $provider, 15184, 304 );
	$five_languages = array( 'de' => 302, 'en' => 15184, 'es' => 303, 'fr' => 304, 'hu' => 301 );
	$assert( is_array( $merged_fr ) && $five_languages === $merged_fr['translations'], 'EN 15184 and the separate HU, DE, ES, and FR groups must produce one exact five-language union.' );
	$assert( $five_languages === pll_get_post_translations( 15184 ) && $five_languages === pll_get_post_translations( 304 ), 'Every member must expose the complete persisted five-language map.' );
	$merged_again = $provider->merge_translation_groups( $last_merge_input );
	$assert( is_array( $merged_again ) && true === $merged_again['idempotent_replay'] && $five_languages === $merged_again['translations'], 'Repeating a completed group merge must be idempotent even with the pre-merge fingerprints.' );
	$invalid_replay = $last_merge_input;
	$invalid_replay['source_translations'] = array( 'fr' => 999 );
	$invalid_replay_result = $provider->merge_translation_groups( $invalid_replay );
	$assert( $invalid_replay_result instanceof \WP_Error && 'smartcloud_polylang_translation_group_conflict' === $invalid_replay_result->code, 'An idempotent replay must still prove that its exact pre-merge snapshots produce the current union.' );

	list( $same_language_conflict ) = $merge_groups( $provider, 401, 402 );
	$assert( $same_language_conflict instanceof \WP_Error && 'smartcloud_polylang_merge_language_conflict' === $same_language_conflict->code, 'Different post IDs in the same language slot must fail closed.' );

	$rollback_target = $provider->resolve( array( 'post_id' => 601, 'post_type' => 'page' ) );
	$rollback_source = $provider->resolve( array( 'post_id' => 602, 'post_type' => 'page' ) );
	$rollback_input = array(
		'page_type' => 'landing-page',
		'post_type' => 'page',
		'target_anchor_post_id' => 601,
		'source_anchor_post_id' => 602,
		'expected_target_localization_group' => $rollback_target['localization_group'],
		'expected_source_localization_group' => $rollback_source['localization_group'],
		'target_translations' => array( 'en' => 601 ),
		'source_translations' => array( 'hu' => 602 ),
	);
	$stale_snapshot = $rollback_input;
	$stale_snapshot['target_translations'] = array( 'en' => 999 );
	$save_count = count( $GLOBALS['polylang_test_saves'] );
	$snapshot_conflict = $provider->merge_translation_groups( $stale_snapshot );
	$assert( $snapshot_conflict instanceof \WP_Error && 'smartcloud_polylang_translation_group_conflict' === $snapshot_conflict->code && $save_count === count( $GLOBALS['polylang_test_saves'] ), 'A mismatched exact map snapshot must fail before Polylang is mutated.' );
	$GLOBALS['polylang_test_corrupt_next_save'] = true;
	$rollback = $provider->merge_translation_groups( $rollback_input );
	$assert( $rollback instanceof \WP_Error && 'smartcloud_polylang_group_merge_verification_failed' === $rollback->code, 'A merge that Polylang does not preserve must fail verification.' );
	$assert( array( 'en' => 601 ) === pll_get_post_translations( 601 ) && array( 'hu' => 602 ) === pll_get_post_translations( 602 ), 'A failed merge must restore both exact pre-merge maps.' );
	$last_saves = array_slice( $GLOBALS['polylang_test_saves'], -3 );
	$assert( array( 'en' => 601, 'hu' => 602 ) === $last_saves[0] && array( 'en' => 601 ) === $last_saves[1] && array( 'hu' => 602 ) === $last_saves[2], 'Rollback must save only the requested union followed by the two previous maps.' );

	echo "polylang-localization-adapter: ok\n";
}
