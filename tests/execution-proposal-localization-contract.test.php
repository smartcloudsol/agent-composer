<?php

declare(strict_types=1);

$root = dirname( __DIR__ );
$read = static fn( string $file ): string => (string) file_get_contents( $root . '/' . $file );
$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};

$proposal = $read( 'src/Execution/Content_Proposal_Service.php' );
$abilities = $read( 'src/Execution/Abilities.php' );
$activation = $read( 'src/Infrastructure/WordPress/Activation.php' );
$controller = $read( 'src/Infrastructure/WordPress/ContentProposalController.php' );
$admin_list = $read( 'src/Infrastructure/WordPress/ContentProposalAdminList.php' );
$config = $read( 'src/Execution/Config_Repository.php' );
$language = $read( 'src/Execution/Content_Language_Validator.php' );
$drafts = $read( 'src/Execution/Draft_Service.php' );
$localization = $read( 'src/Execution/Localization_Provider_Registry.php' );
$localized_drafts = $read( 'src/Execution/Localized_Draft_Service.php' );
$runtime = $read( 'src/Application/Execution/ExecutionRuntime.php' );
$wpml = $read( 'integrations/wpml/src/WpmlLocalizationProvider.php' );
$polylang = $read( 'integrations/polylang/src/PolylangLocalizationProvider.php' );
$mcp = $read( 'src/Integration/Mcp/ComposerMcpServer.php' );

foreach ( array( 'SOURCE_META', 'BASE_FINGERPRINT_META', 'STATE_META', 'LOCALIZATION_META', "'working'", "'ready-for-review'", "'merged'", "'rejected'" ) as $required ) {
	$assert( str_contains( $proposal, $required ), 'Proposal lifecycle is missing: ' . $required );
}
foreach ( array( 'post_content', 'post_excerpt', 'post_name', 'menu_order', 'YOAST_METADESC_META', '_thumbnail_id', 'get_content_field_access', 'get_content_taxonomy_access', 'wp_set_object_terms' ) as $required ) {
	$assert( str_contains( $proposal, $required ), 'Proposal projection is incomplete: ' . $required );
}
$assert( substr_count( $proposal, 'FOR UPDATE' ) >= 3 && str_contains( $proposal, 'ID IN (%d, %d)' ), 'Human merge must lock both post rows plus governed metadata and taxonomy relationships.' );
$transaction_start = strpos( $proposal, "query( 'START TRANSACTION'" );
$locked_revalidation = strpos( $proposal, '$this->assert_proposal_token( $proposal, $input );', $transaction_start );
$assert( false !== $transaction_start && false !== $locked_revalidation, 'Proposal state and concurrency tokens must be revalidated under the merge lock.' );
$assert( str_contains( $proposal, 'proposal_source_conflict' ), 'Human merge must fail closed on source drift.' );
$assert( substr_count( $proposal, '$this->fresh_post( $source_id )' ) >= 4 && str_contains( $proposal, 'SELECT * FROM {$wpdb->posts} WHERE ID = %d LIMIT 1' ), 'Proposal creation, review, return, and merge must bypass stale long-running-process post caches.' );
$commit_position = strpos( $proposal, "query( 'COMMIT' )" );
$post_commit_invalidation = strpos( $proposal, 'clean_post_cache( $source_id );', $commit_position );
$assert( false !== $commit_position && false !== $post_commit_invalidation && $post_commit_invalidation > $commit_position, 'A successful merge must invalidate source caches after the database commit.' );
$assert( str_contains( $proposal, "result['conflict'] = true" ) && str_contains( $proposal, "result['localization_conflict'] = true" ) && str_contains( $proposal, 'validate_proposal( $this->localization_context' ), 'Proposal inspection must expose localization drift before the human attempts merge.' );
$assert( str_contains( $proposal, "unset( \$projection['modified_gmt'] )" ) && substr_count( $proposal, 'source_fingerprint( $this->projection( $source, $page_type ) )' ) >= 2, 'No-op WordPress timestamp touches must not create false proposal conflicts while governed source values remain fingerprinted.' );
$assert( str_contains( $proposal, "'post_status' => 'publish'" ), 'Merge must preserve the live published state.' );
$assert( str_contains( $proposal, "add_filter( 'pre_wp_unique_post_slug', \$preserve_governed_slug, 10, 6 )" ) && str_contains( $proposal, "remove_filter( 'pre_wp_unique_post_slug', \$preserve_governed_slug, 10 )" ), 'Merge must scope and remove its source-slug override so a proposal working copy cannot rename the published canonical.' );
$assert( str_contains( $proposal, "wp_unique_post_slug( \$desired_slug, \$source_id" ) && str_contains( $proposal, 'proposal_slug_conflict' ), 'A changed proposal target slug must be checked for conflicts while excluding only its published source.' );
$assert( str_contains( $proposal, 'TARGET_SLUG_META' ) && str_contains( $proposal, 'assign_proposal_storage_slug' ) && str_contains( $proposal, "'-composer-proposal-'" ), 'Every proposal state must leave the public canonical namespace while retaining its governed target slug.' );
$assert( str_contains( $proposal, 'proposal_projection' ) && str_contains( $proposal, 'proposal_target_slug' ) && str_contains( $proposal, "self::TARGET_SLUG_META => \$source->post_name" ), 'Proposal validation and merge projections must use the governed target slug rather than the internal storage slug.' );
$assert( str_contains( $drafts, "Content_Proposal_Service::TARGET_SLUG_META" ) && str_contains( $drafts, 'governed_slug( $post )' ), 'Draft updates and responses must edit and expose a proposal target slug without mutating its internal storage slug.' );
$assert( str_contains( $admin_list, 'pre_wp_unique_post_slug' ) && str_contains( $admin_list, 'preserve_existing_published_slug' ), 'Normal Gutenberg saves must preserve an unchanged published canonical when a Composer draft shares its slug.' );
$assert( str_contains( $admin_list, 'wp_insert_post_data' ) && str_contains( $admin_list, 'keep_proposals_unpublished' ), 'Proposal working and archive copies must not be independently publishable.' );
$assert( str_contains( $admin_list, 'proposal_editor_notice' ) && str_contains( $admin_list, 'Continue editing the live source in Gutenberg.' ), 'The editor must direct humans from a merged archive to its live Gutenberg source.' );
$assert( str_contains( $proposal, 'public function return_for_changes' ) && str_contains( $proposal, "array( 'ready-for-review', 'rejected' )" ), 'Submitted and rejected proposals must be returnable as the same working copy.' );
$assert( str_contains( $proposal, "wp_update_post( array( 'ID' => \$proposal_id ), true )" ) && str_contains( $proposal, "self::STATE_META, 'working', \$state" ), 'Returning a proposal must rotate its edit token before atomically reopening the same draft.' );
$assert( str_contains( $proposal, 'content-proposal-returned-for-changes' ) && str_contains( $proposal, "'reason' => \$reason" ), 'A human change request must retain its reason in the proposal audit trail.' );
$assert( str_contains( $abilities, 'create-content-proposal' ) && str_contains( $abilities, 'submit-content-proposal' ), 'Agent proposal abilities are missing.' );
$assert( substr_count( $abilities, 'MUST call submit-content-proposal' ) >= 2, 'Proposal creation and draft update metadata must require the explicit human-review submission step.' );
$aliases = $read( 'src/Integration/Abilities/ExecutionAbilityAliases.php' );
$assert( str_contains( $aliases, 'MUST call smartcloud-agent-composer/submit-content-proposal' ), 'The canonical update-content-draft alias must advertise the proposal submission step.' );
$assert( ! str_contains( $abilities, 'merge-content-proposal' ), 'Merge must never be registered as an agent execution ability.' );
$assert( str_contains( $activation, 'CAP_PROPOSE_UPDATES' ) && str_contains( $activation, 'CAP_MERGE_PROPOSALS' ), 'Proposal capabilities must be distinct.' );
$agent_caps = substr( $activation, strpos( $activation, '$agent_caps' ), strpos( $activation, '$role =', strpos( $activation, '$agent_caps' ) ) - strpos( $activation, '$agent_caps' ) );
$assert( str_contains( $agent_caps, 'CAP_PROPOSE_UPDATES' ) && ! str_contains( $agent_caps, 'CAP_MERGE_PROPOSALS' ), 'The agent role may propose but must not merge.' );
$assert( str_contains( $controller, 'wp_verify_nonce' ) && str_contains( $controller, 'CAP_MERGE_PROPOSALS' ), 'Human proposal mutations require nonce and merge capability.' );
$assert( str_contains( $controller, '/return-for-changes' ) && str_contains( $controller, "'maxLength' => 1000" ), 'Returning a proposal for changes requires a bounded human-only REST operation.' );
$assert( str_contains( $controller, "'states'" ) && str_contains( $controller, "'search'" ) && str_contains( $controller, "'per_page'" ), 'Proposal review listing must expose bounded multi-state search and pagination.' );
$assert( str_contains( $proposal, "array( 'ready-for-review' )" ) && str_contains( $proposal, 'matches_list_search' ) && str_contains( $proposal, 'array_slice' ) && str_contains( $proposal, "'total_pages'" ), 'Proposal review must default to ready items and apply ID/title/slug filtering before pagination.' );
$assert( str_contains( $config, 'propose_updates' ) && str_contains( $config, 'published_update_policy' ), 'Site Contract and Blueprint proposal gates are required.' );
$assert( str_contains( $drafts, "'proposable'    => 'publish' === \$post->post_status" ), 'A human-published Composer draft must become eligible for the separately governed proposal workflow.' );
$assert( str_contains( $language, 'allowed_content_languages' ) && str_contains( $language, 'array_unique' ), 'Multilingual Blueprints must retain strict request allowlisting without applying one ambiguous language-signal list.' );
$assert( str_contains( $proposal, 'Content_Language_Validator::is_allowed_language' ), 'Published-content proposals must use the same wildcard-aware language policy as draft creation.' );
$assert( ! str_contains( $proposal, 'self::LOCALIZATION_META => $context' ) && str_contains( $proposal, 'persist_localization_context( (int) $post_id, $context )' ) && str_contains( $proposal, 'update_post_meta( $proposal_id, self::LOCALIZATION_META, $normalized )' ) && str_contains( $proposal, 'is_array( $stored ) ? $stored : json_decode' ), 'Proposal localization context must be persisted and verified after insertion while retaining backward-compatible JSON reads.' );
$assert( substr_count( $proposal, "'suppress_filters' => true" ) >= 2, 'Proposal discovery must not be narrowed by a localization plugin\'s ambient query filter.' );
$assert( substr_count( $proposal, "'fields' => 'ids'" ) >= 2 && substr_count( $proposal, "'cache_results' => false" ) >= 2 && str_contains( $proposal, "'draft' !== \$post->post_status" ), 'Proposal queries must use uncached ID-only results and revalidate hydrated posts so localization or theme query side effects cannot inject unrelated content.' );
$assert( str_contains( $drafts, "'suppress_filters'       => true" ), 'Editable-content discovery must enumerate all contract-visible languages independently of ambient localization filters.' );
$assert( substr_count( $drafts, "'change_request_reason'" ) >= 2 && substr_count( $drafts, 'CHANGE_REQUEST_REASON_META' ) >= 2, 'Returned proposal instructions must be visible through draft discovery and inspection.' );
$assert( substr_count( $drafts, "'' !== \$proposal_state && 'working' !== \$proposal_state" ) >= 2, 'Submitted and closed proposals must be read-only and excluded from the agent-editable draft list until a reviewer returns them.' );
$assert( str_contains( $drafts, 'matches_search_filter( $post, $search )' ) && str_contains( $drafts, '$post->post_name' ), 'Editable-content discovery must support exact canonical slug lookup.' );
$assert( substr_count( $drafts, '$this->fresh_post( $post_id )' ) >= 3 && str_contains( $drafts, '$this->fresh_post( $candidate->ID )' ) && str_contains( $drafts, 'Content concurrency requires the committed posts row' ), 'Content inspection and discovery must bypass a long-running MCP worker\'s stale post cache.' );
$assert( str_contains( $drafts, 'summarize_list_item( $post, $page_type )' ) && str_contains( $drafts, 'normalized_stored_template( $post->ID )' ), 'Published items without Composer metadata must derive the requested page type only from a matching Blueprint target and template.' );
$assert( substr_count( $drafts, '\'draft\' === $post->post_status && \'1\' === (string) get_post_meta' ) >= 3, 'Composer ownership metadata must grant draft semantics only while the item is actually a draft.' );
$assert( str_contains( $drafts, 'self::CONTENT_LANGUAGE_META   => $content_language' ) && substr_count( $drafts, 'assign_draft_language' ) >= 2, 'Clones must inherit and persist the inspected source language.' );
$assert( str_contains( $abilities, 'MUST NOT be used to revise a published canonical item; use create-content-proposal' ), 'Clone guidance must direct published canonical updates into the proposal workflow.' );

$assert( str_contains( $localization, 'smartcloud_composer_localization_providers' ), 'Localization providers need a dedicated data-only registry.' );
$assert( str_contains( $localization, 'ability_namespace' ) && str_contains( $localization, 'claimed_namespaces' ), 'Localization providers must own a unique Ability namespace.' );
foreach ( array( 'get-localization-capabilities', 'list-content-languages', 'resolve-localized-content', 'preview-localized-proposal', 'validate-localized-proposal', 'assign-draft-language', 'link-draft-translations', 'attach-draft-to-translation-group' ) as $ability ) {
	$assert( str_contains( $localization, $ability ) && str_contains( $wpml, $ability ) && str_contains( $polylang, $ability ), 'Localization bridge contract is missing: ' . $ability );
}
$assert( str_contains( $abilities, 'list-supported-content-languages' ), 'Composer must expose generic language discovery independently of a concrete provider.' );
$assert( str_contains( $abilities, 'link-content-draft-translations' ), 'Composer must expose governed linking for separately authored drafts.' );
$assert( str_contains( $abilities, 'attach-content-draft-to-translation-group' ), 'Composer must expose additive draft attachment to existing translation groups.' );
$assert( str_contains( $localized_drafts, 'confirm_link' ) && str_contains( $localized_drafts, 'expected_modified_gmt' ) && str_contains( $localized_drafts, 'expected_revision' ), 'Localized draft linking requires explicit confirmation and optimistic concurrency.' );
$assert( str_contains( $localized_drafts, 'get_owned_draft' ) && str_contains( $localized_drafts, 'LOCALIZATION_PROVIDER_META' ), 'Only Composer-owned drafts assigned by one provider may be linked.' );
$assert( str_contains( $localized_drafts, 'localized_draft_language_slot_occupied' ) && str_contains( $localized_drafts, 'inspect_content_item' ), 'Group attachment must require a readable contract-matching anchor and an empty target language slot without restricting anchor status.' );
$assert( str_contains( $localization, 'assign-draft-language' ) && str_contains( $localization, 'link-draft-translations' ), 'Composer must discover optional provider-backed draft assignment and linking operations.' );
$assert( ! str_contains( strtolower( $runtime ), 'wpml' ) && ! str_contains( strtolower( $runtime ), 'polylang' ), 'Composer runtime must not instantiate or name a concrete localization provider.' );
foreach ( array( 'wpml_is_translated_post_type', 'wpml_default_language', 'wpml_element_type', 'wpml_element_trid', 'wpml_get_element_translations', 'wpml_element_language_details', 'wpml_post_language_details', 'wpml_set_element_language_details', 'wpml_permalink' ) as $hook ) {
	$assert( str_contains( $wpml, $hook ), 'WPML adapter is missing official hook: ' . $hook );
}
$assert( str_contains( $wpml, 'int|false $trid' ) && str_contains( $wpml, "'trid' => \$trid" ) && str_contains( $wpml, "'source_language_code' => \$source_language_code" ), 'WPML draft assignment and linking must use explicit public relationship arguments.' );
$assert( str_contains( $wpml, 'smartcloud_agent_composer_before_proposal_insert' ) && str_contains( $wpml, 'proposal-attached-to-translation-group' ), 'WPML proposals must stay outside translation groups and fail closed if WPML attaches one.' );
$assert( ! str_contains( $wpml, '$sitepress' ), 'WPML integration must use documented public hooks only.' );
$assert( str_contains( $wpml, 'CAP_MERGE_PROPOSALS' ), 'Human reviewers must be able to run internal WPML relationship validation.' );
foreach ( array( 'pll_languages_list', 'pll_default_language', 'pll_get_post_language', 'pll_get_post_translations', 'pll_is_translated_post_type', 'pll_set_post_language', 'pll_save_post_translations' ) as $function ) {
	$assert( str_contains( $polylang, $function ), 'Polylang bridge is missing documented public function: ' . $function );
}
$assert( str_contains( $polylang, 'proposal-attached-to-source-translation-group' ), 'Polylang proposals must remain outside the source translation group.' );
$assert( ! str_contains( $polylang, 'PLL()' ), 'Polylang integration must not use internal model APIs.' );
$assert( str_contains( $mcp, '$this->localization->mcp_ability_names()' ), 'Safe localization abilities must be discoverable through Composer MCP.' );
$assert( ! str_contains( $controller, 'wp_register_ability' ), 'Human merge must remain outside the Abilities registry.' );

echo "proposal-localization-contract: ok\n";
