<?php

use SmartCloud\AgentComposer\Application\Configuration\ConfigSetActivator;
use SmartCloud\AgentComposer\Application\Configuration\ConfigSetManager;
use SmartCloud\AgentComposer\Application\Configuration\ConfigSetValidator;
use SmartCloud\AgentComposer\Application\Configuration\ValidationReceiptService;
use SmartCloud\AgentComposer\Application\Execution\ExecutionRuntime;
use SmartCloud\AgentComposer\Domain\Configuration\EntityType;
use SmartCloud\AgentComposer\Domain\Structure\StructureContract;
use SmartCloud\AgentComposer\Domain\Structure\StructureDocumentValidator;
use SmartCloud\AgentComposer\Execution\Config_Repository;
use SmartCloud\AgentComposer\Execution\Content_Proposal_Service;
use SmartCloud\AgentComposer\Execution\Managed_Document_State;
use SmartCloud\AgentComposer\Execution\Synced_Structural_Pattern_Service;
use SmartCloud\AgentComposer\Infrastructure\Persistence\ActiveConfigurationSource;
use SmartCloud\AgentComposer\Infrastructure\Persistence\AuditTable;
use SmartCloud\AgentComposer\Infrastructure\Persistence\WordPressConfigurationRepository;
use SmartCloud\AgentComposer\Integration\Providers\ProviderRegistry;
use SmartCloud\AgentComposer\Security\McpSecuritySettings;

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

require_once __DIR__ . '/support/cognito-jwt-fixture.php';

$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};

$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => array( 'ID' ) ) );
$assert( ! empty( $admins ), 'The structure migration fixture needs an administrator.' );
wp_set_current_user( (int) $admins[0]->ID );

$suffix       = substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 10 );
$config_set   = 'c4-migration-' . $suffix;
$page_type    = 'migration-page';
$contract_id  = 'migration-editor';
$migration_id = 'migration-page-3-to-4';
$hero_pattern = 'wpsuite/migration-hero-' . $suffix;
$body_pattern = 'wpsuite/migration-body-' . $suffix;
$hero_name    = 'wpsuite-migration-hero-' . $suffix;
$body_name    = 'wpsuite-migration-body-' . $suffix;
$post_ids     = array();
$source_ids   = array();
$proposal_ids = array();
$mcp_security_previous = get_option( McpSecuritySettings::OPTION, null );
$jwks_cache_key = '';
$repository   = new WordPressConfigurationRepository();
$audit        = new AuditTable();
$receipts     = new ValidationReceiptService();
$manager      = new ConfigSetManager( $repository, $audit );
$validator    = new ConfigSetValidator( $repository, $receipts, new ProviderRegistry(), $audit );
$activator    = new ConfigSetActivator( $repository, $receipts, $audit );

$cleanup = static function () use ( &$post_ids, $repository, $config_set, $mcp_security_previous, &$jwks_cache_key ): void {
	foreach ( array_reverse( array_unique( array_map( 'absint', $post_ids ) ) ) as $post_id ) {
		if ( $post_id > 0 ) {
			wp_delete_post( $post_id, true );
		}
	}
	foreach ( array( 'smartcloud_composer_active_config_set', 'smartcloud_composer_previous_config_set', 'smartcloud_composer_active_snapshot', 'smartcloud_composer_activation_receipt', 'smartcloud_composer_activation_lock' ) as $option ) {
		delete_option( $option );
	}
	foreach ( $repository->entities( $config_set ) as $post ) {
		wp_delete_post( $post->ID, true );
	}
	if ( null === $mcp_security_previous ) {
		delete_option( McpSecuritySettings::OPTION );
	} else {
		update_option( McpSecuritySettings::OPTION, $mcp_security_previous, false );
	}
	if ( '' !== $jwks_cache_key ) {
		delete_transient( $jwks_cache_key );
	}
};

$contract = static function ( int $version, int $hero_position, int $body_position ) use ( $contract_id ): array {
	return array(
		'id'      => $contract_id,
		'version' => $version,
		'label'   => 'Migration editor v' . $version,
		'nodes'   => array(
			array( 'id' => 'hero', 'block' => 'core/group', 'ownership' => 'BLUEPRINT', 'mode' => 'structure', 'parent' => null, 'position' => $hero_position, 'required' => true ),
			array( 'id' => 'hero.title', 'block' => 'core/heading', 'ownership' => 'INSTANCE_CONTENT', 'mode' => 'content', 'parent' => 'hero', 'position' => 10, 'required' => true, 'editable_attributes' => array( 'content' ), 'editable_content' => true ),
			array( 'id' => 'body', 'block' => 'core/group', 'ownership' => 'BLUEPRINT', 'mode' => 'structure', 'parent' => null, 'position' => $body_position, 'required' => true ),
			array( 'id' => 'body.text', 'block' => 'core/paragraph', 'ownership' => 'INSTANCE_CONTENT', 'mode' => 'content', 'parent' => 'body', 'position' => 10, 'required' => true, 'editable_attributes' => array( 'content' ), 'editable_content' => true ),
			array( 'id' => 'body.additional', 'block' => 'smartcloud-agent-composer/extension-slot', 'ownership' => 'BLUEPRINT', 'mode' => 'slot', 'parent' => 'body', 'position' => 20, 'required' => true, 'allowed_blocks' => array( 'core/heading', 'core/paragraph', 'core/separator' ), 'min_blocks' => 0, 'max_blocks' => 2 ),
		),
	);
};

$contract_v3_definition = $contract( 3, 10, 20 );
$contract_v4_definition = $contract( 4, 20, 10 );
$contract_v3_result = StructureContract::normalize_definition( $contract_v3_definition );
$contract_v4_result = StructureContract::normalize_definition( $contract_v4_definition );
$assert( $contract_v3_result['valid'] && $contract_v4_result['valid'], 'Both structure contract fixture versions must normalize.' );
$contract_v3 = $contract_v3_result['value'];
$contract_v4 = $contract_v4_result['value'];

$pattern_markup = static function ( string $section_id, string $field_id, string $block, string $default, bool $with_slot = false ): string {
	$group_attrs = wp_json_encode( array( 'metadata' => array( 'name' => $section_id ) ), JSON_UNESCAPED_SLASHES );
	$field_attrs = array(
		'metadata' => array(
			'name'     => $field_id,
			'bindings' => array( '__default' => array( 'source' => 'core/pattern-overrides' ) ),
		),
	);
	if ( 'core/heading' === $block ) {
		$field_attrs['level'] = 1;
	}
	$field_json = wp_json_encode( $field_attrs, JSON_UNESCAPED_SLASHES );
	$safe       = esc_html( $default );
	$element    = 'core/heading' === $block
		? '<h1 class="wp-block-heading">' . $safe . '</h1>'
		: '<p>' . $safe . '</p>';
	$short_name = substr( $block, strlen( 'core/' ) );
	$slot = $with_slot
		? '<!-- wp:smartcloud-agent-composer/extension-slot {"metadata":{"name":"body.additional"},"slotId":"body.additional","allowedBlocks":["core/heading","core/paragraph","core/separator"],"minBlocks":0,"maxBlocks":2,"templateLock":false,"lock":{"move":true,"remove":true}} --><div class="wp-block-smartcloud-agent-composer-extension-slot" data-composer-extension-slot="body.additional"></div><!-- /wp:smartcloud-agent-composer/extension-slot -->'
		: '';
	return '<!-- wp:group ' . $group_attrs . ' --><div class="wp-block-group">'
		. '<!-- wp:' . $short_name . ' ' . $field_json . ' -->' . $element . '<!-- /wp:' . $short_name . ' -->'
		. $slot
		. '</div><!-- /wp:group -->';
};

try {
	$hero_block_id = wp_insert_post(
		array(
			'post_type'    => 'wp_block',
			'post_status'  => 'publish',
			'post_name'    => $hero_name,
			'post_title'   => 'Migration hero fixture',
			'post_content' => $pattern_markup( 'hero', 'hero.title', 'core/heading', 'Default migration title' ),
		),
		true
	);
	$body_block_id = wp_insert_post(
		array(
			'post_type'    => 'wp_block',
			'post_status'  => 'publish',
			'post_name'    => $body_name,
			'post_title'   => 'Migration body fixture',
			'post_content' => $pattern_markup( 'body', 'body.text', 'core/paragraph', 'Default migration body.', true ),
		),
		true
	);
	$assert( ! is_wp_error( $hero_block_id ) && ! is_wp_error( $body_block_id ), 'Both synced wp_block fixtures must be created.' );
	$post_ids[] = (int) $hero_block_id;
	$post_ids[] = (int) $body_block_id;

	$manager->create( 'Structure migration fixture', $config_set );
	$site_post = $repository->find_entity( $config_set, EntityType::SITE_CONTRACT, 'contract:site' );
	$assert( $site_post instanceof WP_Post, 'The fixture Site Contract must exist.' );
	$site_description = $repository->describe_entity( $site_post );
	$site_payload = array(
		'schema_version' => '1.0',
		'entity_type'    => 'site-contract',
		'key'            => 'site',
		'label'          => 'Structure migration fixture Site Contract',
		'security'       => array( 'mcp' => array( 'requireAuthentication' => true ) ),
		'design_policy'  => array(
			'schema_version'               => 1,
			'policy_name'                  => 'Structure migration fixture',
			'policy_version'               => '1.0.0',
			'rendered_preview_policy'      => 'optional',
			'content_language'             => 'en-US',
			'content_language_enforcement' => 'advisory',
			'localization'                 => array( 'provider' => 'auto', 'allowed_content_languages' => array( 'en-US' ) ),
			'allowed_pattern_namespaces'   => array( 'wpsuite' ),
			'post_type_contract'           => array( $page_type => 'page' ),
			'constraints'                  => array( 'exactly_one_h1' => true, 'inline_css' => false, 'custom_html' => false, 'shortcodes' => false, 'external_embeds' => false, 'theme_presets_only' => false, 'maximum_words' => 500 ),
			'block_extensions'             => array( 'allowed_core_blocks' => array(), 'allowed_plugin_namespaces' => array(), 'registered_block_contracts' => array(), 'require_registered_blocks' => true ),
		),
	);
	$site_payload['design_policy']['content_access'] = array(
		'page' => array( 'discover' => true, 'read' => true, 'clone' => false, 'adopt_drafts' => false, 'propose_updates' => true ),
	);
	$site_payload['design_policy']['admin_creation'] = array(
		'page' => array( 'mode' => 'required', 'default_page_type' => $page_type ),
	);
	$site_payload['design_policy']['synced_structural_patterns'] = array(
		$hero_pattern => array(
			'version'   => 1,
			'post_name' => $hero_name,
			'overrides' => array(
				'hero.title' => array( 'block' => 'core/heading', 'attributes' => array( 'content' ), 'type' => 'richtext', 'required' => true ),
			),
		),
		$body_pattern => array(
			'version'   => 1,
			'post_name' => $body_name,
			'overrides' => array(
				'body.text' => array( 'block' => 'core/paragraph', 'attributes' => array( 'content' ), 'type' => 'richtext', 'required' => true ),
			),
		),
	);
	$site_payload['design_policy']['structure_contracts'] = array( $contract_id => $contract_v4_definition );
	$site_payload['design_policy']['structure_migrations'] = array(
		$migration_id => array(
			'id'            => $migration_id,
			'blueprint'     => $page_type,
			'from'          => array( 'blueprint_version' => 3, 'structure_contract' => array( 'id' => $contract_id, 'version' => 3 ) ),
			'to'            => array( 'blueprint_version' => 4, 'structure_contract' => array( 'id' => $contract_id, 'version' => 4 ) ),
			'from_contract' => $contract_v3_definition,
			'operations'    => array( array( 'type' => 'move_section', 'id' => 'body', 'before' => 'hero' ) ),
			'override_rules' => array(),
		),
	);
	$repository->update_working_entity( $site_post->ID, $site_payload, $site_description['entity_revision'], $site_description['content_hash'] );

	$manager->create_entity(
		$config_set,
		EntityType::BLUEPRINT,
		$page_type,
		array(
			'schema_version'          => 4,
			'page_type'               => $page_type,
			'composition_mode'        => 'document',
			'published_update_policy' => 'proposal-only',
			'label'                   => 'Structure migration fixture page',
			'target_post_type'        => 'page',
			'target_template'         => array( 'label' => 'Default Page', 'mode' => 'default', 'slug' => 'default' ),
			'content_language'        => 'en-US',
			'allowed_content_languages' => array( 'en-US' ),
			'allowed_patterns'        => array( $body_pattern, $hero_pattern ),
			'required_sequence'       => array( $body_pattern, $hero_pattern ),
			'synced_patterns'         => array( $body_pattern, $hero_pattern ),
			'allowed_blocks'           => array( 'core/block', 'core/group', 'core/heading', 'core/paragraph' ),
			'constraints'              => array( 'exactly_one_h1' => true, 'inline_css' => false, 'custom_html' => false, 'shortcodes' => false, 'external_embeds' => false, 'theme_presets_only' => false, 'maximum_words' => 500 ),
			'excerpt_policy'           => 'optional',
			'structure_contract'       => array( 'id' => $contract_id, 'version' => 4 ),
		)
	);

	$config_validation = $validator->validate( $config_set );
	$assert( true === $config_validation['valid'], 'The migration Config Set must validate: ' . wp_json_encode( $config_validation['errors'] ) );
	$activator->activate( $config_set, (string) $config_validation['receipt'] );
	$assert( $config_set === get_option( 'smartcloud_composer_active_config_set' ), 'The migration Config Set must become active.' );

	// Exercise the native-editor entry path against the same active Blueprint.
	$native_runtime = new ExecutionRuntime();
	$admin_property = new ReflectionProperty( $native_runtime, 'admin_documents' );
	$admin_property->setAccessible( true );
	$admin_documents = $admin_property->getValue( $native_runtime );
	$create_auto_draft = new ReflectionMethod( $admin_documents, 'create_managed_auto_draft' );
	$create_auto_draft->setAccessible( true );
	$native_draft_id = (int) $create_auto_draft->invoke( $admin_documents, 'page', $page_type );
	$post_ids[] = $native_draft_id;
	$assert( 'auto-draft' === get_post_status( $native_draft_id ), 'Add New must bootstrap a canonical managed auto-draft.' );
	$assert( 'wp-admin' === get_post_meta( $native_draft_id, \SmartCloud\AgentComposer\Execution\Draft_Service::ASSIGNMENT_SOURCE_META, true ), 'The native draft must remain human-owned.' );
	$assert( 'VALID' === get_post_meta( $native_draft_id, Managed_Document_State::STATUS_META, true ), 'The native draft must persist a valid managed baseline.' );
	$native_content = (string) get_post_field( 'post_content', $native_draft_id );
	$assert( str_contains( $native_content, 'wp:block' ) && str_contains( $native_content, '"lock":{"move":true,"remove":true}' ), 'The native draft must store locked synced-pattern instances.' );
	$native_config = new Config_Repository( new ActiveConfigurationSource( $repository ) );
	$native_blueprint = $native_config->get_blueprint( $page_type );
	$native_expanded = ( new Synced_Structural_Pattern_Service( $native_config ) )->expand_blocks( parse_blocks( $native_content ), $native_blueprint );
	$native_expanded_json = wp_json_encode( $native_expanded, JSON_UNESCAPED_SLASHES );
	$assert( is_string( $native_expanded_json ) && str_contains( $native_expanded_json, 'body.additional' ) && str_contains( $native_expanded_json, 'smartcloud-agent-composer/extension-slot' ), 'The native editor must resolve the canonical extension slot from its synced pattern.' );

	// The public renderer must bind extension-slot children to their individual
	// synced-pattern instance rather than rendering the shared pattern's empty slot.
	$frontend_patterns = new Synced_Structural_Pattern_Service( $native_config );
	$first_reference = $frontend_patterns->materialize_instance(
		$body_pattern,
		array( 'body.text' => array( 'content' => 'First reference body.' ) ),
		$native_blueprint,
		false,
		'pattern-public-first-12345678'
	)['block'];
	$second_reference = $frontend_patterns->materialize_instance(
		$body_pattern,
		array( 'body.text' => array( 'content' => 'Second reference body.' ) ),
		$native_blueprint,
		false,
		'pattern-public-second-12345678'
	)['block'];
	$first_slot_blocks = parse_blocks( '<!-- wp:paragraph {"metadata":{"wpsuiteAgentComposer":{"userBlockId":"user-public-first-12345678","slotId":"body.additional"}}} --><p>First reference paragraph.</p><!-- /wp:paragraph -->' );
	$second_slot_blocks = parse_blocks( '<!-- wp:paragraph {"metadata":{"wpsuiteAgentComposer":{"userBlockId":"user-public-second-12345678","slotId":"body.additional"}}} --><p>Second reference paragraph.</p><!-- /wp:paragraph -->' );
	$first_reference = $frontend_patterns->with_slot_children( $first_reference, 'body.additional', $first_slot_blocks, 'pattern-public-first-12345678' );
	$second_reference = $frontend_patterns->with_slot_children( $second_reference, 'body.additional', $second_slot_blocks, 'pattern-public-second-12345678' );
	$repeated_markup = serialize_blocks( array( $first_reference, $second_reference ) );
	$stored_references = parse_blocks( $repeated_markup );
	$assert( 'First reference paragraph.' === wp_strip_all_tags( (string) ( $stored_references[0]['attrs']['metadata']['wpsuiteAgentComposer']['instanceSlots']['body.additional'][0]['innerHTML'] ?? '' ) ), 'The first reference must retain its own saved instance-slot payload.' );
	$assert( 'Second reference paragraph.' === wp_strip_all_tags( (string) ( $stored_references[1]['attrs']['metadata']['wpsuiteAgentComposer']['instanceSlots']['body.additional'][0]['innerHTML'] ?? '' ) ), 'The second reference must retain its own saved instance-slot payload.' );
	$repeated_html = do_blocks( $repeated_markup );
	$assert( 1 === substr_count( $repeated_html, 'First reference paragraph.' ) && 1 === substr_count( $repeated_html, 'Second reference paragraph.' ), 'Public rendering must output each synced-pattern instance slot exactly once.' );
	$rendered_positions = array_map(
		static fn( string $needle ): int|false => strpos( $repeated_html, $needle ),
		array( 'First reference body.', 'First reference paragraph.', 'Second reference body.', 'Second reference paragraph.' )
	);
	$assert(
		! in_array( false, $rendered_positions, true )
		&& $rendered_positions[0] < $rendered_positions[1]
		&& $rendered_positions[1] < $rendered_positions[2]
		&& $rendered_positions[2] < $rendered_positions[3],
		'Public rendering must keep each slot paragraph after the matching repeated pattern override.'
	);

	$guard_property = new ReflectionProperty( $native_runtime, 'structure_guard' );
	$guard_property->setAccessible( true );
	$structure_guard = $guard_property->getValue( $native_runtime );
	$unmanaged_request = new WP_REST_Request( 'POST', '/wp/v2/pages' );
	$unmanaged_request->set_param( 'content', '<!-- wp:paragraph --><p>Out of band</p><!-- /wp:paragraph -->' );
	$unmanaged_result = $structure_guard->validate_save( (object) array( 'post_type' => 'page', 'post_content' => '<!-- wp:paragraph --><p>Out of band</p><!-- /wp:paragraph -->' ), $unmanaged_request );
	$assert( is_wp_error( $unmanaged_result ) && 'smartcloud_agent_managed_creation_required' === $unmanaged_result->get_error_code(), 'A direct REST create without a managed baseline must fail closed.' );

	// Protect the remainder with real RS256 Cognito-shaped access tokens. The
	// User Pool, client ceiling, group mapping, and scope intersection all run
	// through the production validator and access guard.
	$mcp_settings = new McpSecuritySettings();
	$mcp_settings->save(
		array(
			'identity_provider' => array(
				'prefer_manual' => true,
				'manual' => array( 'region' => 'eu-central-1', 'user_pool_id' => 'eu-central-1_ComposerE2E' ),
			),
			'group_roles' => array( 'admins' => 'publisher', 'editors' => 'contributor', 'readers' => 'reader' ),
			'clients' => array(
				array( 'label' => 'Reader client', 'client_id' => 'reader-client', 'role_ceiling' => 'reader', 'scopes' => array( 'composer.read', 'composer.draft', 'composer.propose' ) ),
				array( 'label' => 'Read-scoped contributor', 'client_id' => 'contributor-read-client', 'role_ceiling' => 'contributor', 'scopes' => array( 'composer.read' ) ),
				array( 'label' => 'Contributor client', 'client_id' => 'contributor-client', 'role_ceiling' => 'contributor', 'scopes' => array( 'composer.read', 'composer.draft', 'composer.propose' ) ),
			),
			'enforce_scopes' => true,
			'unknown_client_policy' => 'deny',
		)
	);
	$identity = $mcp_settings->identity_configuration();
	$token_fixture = new SmartCloud_Composer_Cognito_Jwt_Fixture();
	$jwks_cache_key = $token_fixture->prime_jwks( (string) $identity['issuer'] );
	$runtime_for = static function ( array $claims ) use ( $token_fixture, $identity ): array {
		$token = $token_fixture->access_token(
			array_merge(
				array(
					'iss' => $identity['issuer'],
					'cognito:groups' => array( 'admins' ),
					'scope' => 'composer/read composer/draft composer/propose composer/publish.request',
				),
				$claims
			)
		);
		$runtime = new ExecutionRuntime();
		$request = new WP_REST_Request( 'POST', '/wp-json/mcp/smartcloud-agent-composer' );
		$request->set_header( 'Authorization', 'Bearer ' . $token );
		$permission = $runtime->mcp_access()->transport_permission( $request );
		$abilities_property = new ReflectionProperty( $runtime, 'abilities' );
		$abilities_property->setAccessible( true );
		return array( $runtime, $abilities_property->getValue( $runtime ), $permission );
	};

	$missing_runtime = new ExecutionRuntime();
	$missing_permission = $missing_runtime->mcp_access()->transport_permission( new WP_REST_Request( 'POST', '/wp-json/mcp/smartcloud-agent-composer' ) );
	$assert( is_wp_error( $missing_permission ) && 'smartcloud_composer_authentication_required' === $missing_permission->get_error_code(), 'Protected-required mode must reject a missing bearer token.' );
	list( $unknown_runtime, $unknown_abilities, $unknown_permission ) = $runtime_for( array( 'client_id' => 'unknown-client' ) );
	unset( $unknown_runtime, $unknown_abilities );
	$assert( is_wp_error( $unknown_permission ) && 'smartcloud_composer_oauth_client_not_allowed' === $unknown_permission->get_error_code(), 'An unknown OAuth client must fail before Structure Contract execution.' );

	$config          = new Config_Repository( new ActiveConfigurationSource( $repository ) );
	$blueprint       = $config->get_blueprint( $page_type );
	$synced_patterns = new Synced_Structural_Pattern_Service( $config );
	$hero_instance   = $synced_patterns->materialize_instance( $hero_pattern, array( 'hero.title' => array( 'content' => 'Migrated title' ) ), $blueprint );
	$body_instance   = $synced_patterns->materialize_instance( $body_pattern, array( 'body.text' => array( 'content' => 'Migrated body copy.' ) ), $blueprint );
	$source_content  = serialize_blocks( array( $hero_instance['block'], $body_instance['block'] ) );
	$source_structure = ( new StructureDocumentValidator() )->validate( $contract_v3, $synced_patterns->expand_blocks( parse_blocks( $source_content ), $blueprint ) );
	$assert( true === $source_structure['valid'], 'The historical source document must satisfy Structure Contract v3.' );

	$create_source = static function ( string $title, array $overrides ) use ( &$post_ids, &$source_ids, $source_content, $page_type, $contract_id, $source_structure ): int {
		$post_id = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_name'    => sanitize_title( $title . '-' . wp_generate_password( 8, false, false ) ),
				'post_content' => $source_content,
				'meta_input'   => array( '_wp_page_template' => 'default' ),
			),
			true
		);
		if ( $post_id instanceof WP_Error ) {
			throw new RuntimeException( $post_id->get_error_message() );
		}
		$baseline = array(
			'blueprint'             => array( 'id' => $page_type, 'version' => 3 ),
			'structure_contract'     => array( 'id' => $contract_id, 'version' => 3 ),
			'contract_hash'          => $source_structure['contract_hash'],
			'structural_fingerprint' => $source_structure['structural_fingerprint'],
		);
		$meta = array(
			Managed_Document_State::BLUEPRINT_META                   => $page_type,
			Managed_Document_State::BLUEPRINT_VERSION_META           => 3,
			Managed_Document_State::STRUCTURE_CONTRACT_META           => $contract_id,
			Managed_Document_State::STRUCTURE_CONTRACT_VERSION_META   => 3,
			Managed_Document_State::CANONICAL_BASELINE_META           => $baseline,
			Managed_Document_State::OVERRIDE_MANIFEST_META            => array( 'schema_version' => 1, 'overrides' => $overrides ),
			Managed_Document_State::CONTRACT_HASH_META                => $source_structure['contract_hash'],
			Managed_Document_State::STATUS_META                       => 'MIGRATION_REQUIRED',
		);
		foreach ( $meta as $key => $value ) {
			update_post_meta( (int) $post_id, $key, $value );
		}
		$post_ids[]   = (int) $post_id;
		$source_ids[] = (int) $post_id;
		return (int) $post_id;
	};

	$content_overrides = array(
		array( 'type' => 'CONTENT', 'path' => 'hero.title' ),
		array( 'type' => 'CONTENT', 'path' => 'body.text' ),
	);
	$single_source = $create_source( 'Single structure migration fixture', $content_overrides );
	$bulk_source   = $create_source( 'Bulk structure migration fixture', $content_overrides );
	$review_source = $create_source( 'Review structure migration fixture', array_merge( $content_overrides, array( array( 'type' => 'ORDER', 'path' => 'body' ) ) ) );
	$single_before = (string) get_post_field( 'post_content', $single_source );

	$preview_ability = wp_get_ability( 'smartcloud-agent-composer/preview-blueprint-migration' );
	$create_ability  = wp_get_ability( 'smartcloud-agent-composer/create-blueprint-migration-proposal' );
	$plan_ability    = wp_get_ability( 'smartcloud-agent-composer/plan-blueprint-migration' );
	$bulk_ability    = wp_get_ability( 'smartcloud-agent-composer/create-blueprint-migration-proposals' );
	$assert( is_object( $preview_ability ) && is_object( $create_ability ) && is_object( $plan_ability ) && is_object( $bulk_ability ), 'All single and bulk migration abilities must be registered.' );

	list( $reader_runtime, $reader_abilities, $reader_permission ) = $runtime_for( array( 'client_id' => 'reader-client', 'sub' => 'reader-principal' ) );
	$assert( true === $reader_permission && 'reader' === $reader_runtime->mcp_access()->current_actor()?->role(), 'The OAuth client ceiling must reduce an admin-group principal to Reader.' );
	$reader_preview = $reader_abilities->preview_blueprint_migration( array( 'post_id' => $single_source, 'page_type' => $page_type, 'migration_id' => $migration_id ) );
	$assert( is_array( $reader_preview ) && true === ( $reader_preview['proposal_eligible'] ?? false ), 'Reader must be able to inspect a zero-write migration preview.' );
	$reader_denied = $reader_abilities->create_blueprint_migration_proposal( array() );
	$assert( is_wp_error( $reader_denied ) && 'smartcloud_composer_tool_not_authorized' === $reader_denied->get_error_code(), 'Reader must not create a migration proposal even when its token carries broader scopes.' );

	list( $scoped_runtime, $scoped_abilities, $scoped_permission ) = $runtime_for( array( 'client_id' => 'contributor-read-client', 'sub' => 'scope-limited-principal' ) );
	$assert( true === $scoped_permission && 'contributor' === $scoped_runtime->mcp_access()->current_actor()?->role(), 'The scope fixture must retain its Contributor role.' );
	$scope_denied = $scoped_abilities->create_blueprint_migration_proposal( array() );
	$assert( is_wp_error( $scope_denied ) && 'smartcloud_composer_tool_not_authorized' === $scope_denied->get_error_code(), 'A Contributor without the effective propose scope must not reach migration execution.' );

	list( $contributor_runtime, $ability_boundary, $contributor_permission ) = $runtime_for( array( 'client_id' => 'contributor-client', 'sub' => 'contributor-principal' ) );
	$contributor_actor = $contributor_runtime->mcp_access()->current_actor();
	$assert( true === $contributor_permission && 'contributor' === $contributor_actor?->role(), 'The allowed client must authenticate as Contributor.' );
	$assert( in_array( 'composer.propose', $contributor_actor?->scopes() ?? array(), true ), 'The allowed client and token scope intersection must retain composer.propose.' );

	// Run the authoring half of the Structure Contract through the same guarded
	// ability boundary before exercising published-content migration.
	$draft = $ability_boundary->create_content_draft(
		array(
			'page_type'        => $page_type,
			'content_language' => 'en-US',
			'title'            => 'Structure Contract authoring fixture',
			'slug'             => 'structure-contract-authoring-' . $suffix,
			'meta_description' => str_repeat( 'A', 120 ),
			'idempotency_key'  => 'structure-authoring-' . $suffix,
			'sections'         => array(
				array( 'pattern' => $body_pattern, 'fields' => array( 'body.text' => array( 'content' => 'Initial governed body.' ) ) ),
				array( 'pattern' => $hero_pattern, 'fields' => array( 'hero.title' => array( 'content' => 'Initial governed title' ) ) ),
			),
		)
	);
	$draft_diagnostic = is_wp_error( $draft ) ? array( 'code' => $draft->get_error_code(), 'message' => $draft->get_error_message(), 'data' => $draft->get_error_data() ) : $draft;
	$assert( is_array( $draft ) && (int) ( $draft['post_id'] ?? 0 ) > 0, 'Contributor must create a governed Structure Contract draft: ' . wp_json_encode( $draft_diagnostic ) );
	$draft_id = (int) $draft['post_id'];
	$post_ids[] = $draft_id;
	$document = $ability_boundary->get_document( array( 'post_id' => $draft_id ) );
	$assert( is_array( $document ) && true === ( $document['validation']['valid'] ?? false ), 'The semantic document must read back with a valid exact contract baseline.' );

	$field_update = $ability_boundary->set_field(
		array(
			'post_id' => $draft_id,
			'expected_modified_gmt' => $document['modified_gmt'],
			'expected_revision' => $document['revision'],
			'field' => 'body.text',
			'value' => 'Updated governed body.',
			'confirm_update' => true,
		)
	);
	$assert( is_array( $field_update ) && 'set_field' === ( $field_update['semantic_mutation']['operation'] ?? '' ), 'A declared instance-content field must remain editable.' );

	$slot_insert = $ability_boundary->insert_slot_block(
		array(
			'post_id' => $draft_id,
			'expected_modified_gmt' => $field_update['modified_gmt'],
			'expected_revision' => $field_update['revision'],
			'slot' => 'body.additional',
			'position' => 'end',
			'block' => array( 'name' => 'core/paragraph', 'content' => 'Approved extension content.' ),
			'confirm_update' => true,
		)
	);
	$slot_insert_diagnostic = is_wp_error( $slot_insert )
		? array( 'code' => $slot_insert->get_error_code(), 'message' => $slot_insert->get_error_message(), 'data' => $slot_insert->get_error_data() )
		: $slot_insert;
	$assert( is_array( $slot_insert ) && str_starts_with( (string) ( $slot_insert['semantic_mutation']['user_block_id'] ?? '' ), 'user-' ), 'An allow-listed extension block must receive a stable user-owned identity: ' . wp_json_encode( $slot_insert_diagnostic ) );
	$first_user_block = (string) $slot_insert['semantic_mutation']['user_block_id'];
	$slot_insert_two = $ability_boundary->insert_slot_block(
		array(
			'post_id' => $draft_id,
			'expected_modified_gmt' => $slot_insert['modified_gmt'],
			'expected_revision' => $slot_insert['revision'],
			'slot' => 'body.additional',
			'position' => 'end',
			'block' => array( 'name' => 'core/heading', 'attributes' => array( 'level' => 3 ), 'content' => 'Second extension block' ),
			'confirm_update' => true,
		)
	);
	$assert( is_array( $slot_insert_two ), 'A second allow-listed extension block must fit the declared cardinality.' );

	$content_before_rejection = (string) get_post_field( 'post_content', $draft_id );
	$slot_overflow = $ability_boundary->insert_slot_block(
		array(
			'post_id' => $draft_id,
			'expected_modified_gmt' => $slot_insert_two['modified_gmt'],
			'expected_revision' => $slot_insert_two['revision'],
			'slot' => 'body.additional',
			'position' => 'end',
			'block' => array( 'name' => 'core/paragraph', 'content' => 'This block must not be stored.' ),
			'confirm_update' => true,
		)
	);
	$assert( is_wp_error( $slot_overflow ) && $content_before_rejection === (string) get_post_field( 'post_content', $draft_id ), 'Slot cardinality violations must fail atomically.' );

	$raw_structure_change = $ability_boundary->insert_or_update_blocks(
		array(
			'post_id' => $draft_id,
			'expected_modified_gmt' => $slot_insert_two['modified_gmt'],
			'expected_revision' => $slot_insert_two['revision'],
			'mode' => 'replace',
			'path' => array( 0 ),
			'blocks' => array(),
		)
	);
	$assert( is_wp_error( $raw_structure_change ) && $content_before_rejection === (string) get_post_field( 'post_content', $draft_id ), 'A raw attempt to remove protected Blueprint structure must be rejected without a partial write.' );

	$slot_remove = $ability_boundary->remove_slot_block(
		array(
			'post_id' => $draft_id,
			'expected_modified_gmt' => $slot_insert_two['modified_gmt'],
			'expected_revision' => $slot_insert_two['revision'],
			'slot' => 'body.additional',
			'user_block_id' => $first_user_block,
			'confirm_update' => true,
		)
	);
	$assert( is_array( $slot_remove ) && 'remove_slot_block' === ( $slot_remove['semantic_mutation']['operation'] ?? '' ), 'A user-owned extension block must remain removable inside its slot.' );

	$draft_content_before_pattern_checks = (string) get_post_field( 'post_content', $draft_id );
	$body_pattern_original = (string) get_post_field( 'post_content', (int) $body_block_id );
	$compatible_pattern = str_replace( 'Default migration body.', 'Revised central default.', $body_pattern_original );
	wp_update_post( wp_slash( array( 'ID' => (int) $body_block_id, 'post_content' => $compatible_pattern ) ) );
	// Each MCP tool call creates a fresh runtime. Use the same request boundary
	// here so request-local resolved-pattern caches cannot hide a central edit.
	list( $compatible_runtime, $compatible_abilities, $compatible_permission ) = $runtime_for( array( 'client_id' => 'contributor-client', 'sub' => 'contributor-principal' ) );
	$assert( true === $compatible_permission, 'The central pattern hash-check request must authenticate.' );
	$compatible_document = $compatible_abilities->get_document( array( 'post_id' => $draft_id ) );
	$assert( is_wp_error( $compatible_document ) && 'smartcloud_agent_synced_pattern_hash_mismatch' === $compatible_document->get_error_code(), 'Any unreviewed central pattern revision must fail closed against the instance-pinned content hash.' );
	$assert( str_contains( $draft_content_before_pattern_checks, 'Updated governed body.' ), 'A rejected central pattern revision must leave the stored instance Pattern Override intact.' );
	unset( $compatible_runtime, $compatible_abilities );

	$incompatible_pattern = preg_replace(
		'#<!-- wp:smartcloud-agent-composer/extension-slot .*?<!-- /wp:smartcloud-agent-composer/extension-slot -->#s',
		'',
		$compatible_pattern
	);
	$assert( is_string( $incompatible_pattern ) && $incompatible_pattern !== $compatible_pattern, 'The incompatible pattern fixture must remove the required extension slot.' );
	wp_update_post( wp_slash( array( 'ID' => (int) $body_block_id, 'post_content' => $incompatible_pattern ) ) );
	list( $incompatible_runtime, $incompatible_abilities, $incompatible_permission ) = $runtime_for( array( 'client_id' => 'contributor-client', 'sub' => 'contributor-principal' ) );
	$assert( true === $incompatible_permission, 'The structurally incompatible pattern hash-check request must authenticate.' );
	$incompatible_document = $incompatible_abilities->get_document( array( 'post_id' => $draft_id ) );
	$assert( is_wp_error( $incompatible_document ) && 'smartcloud_agent_synced_pattern_hash_mismatch' === $incompatible_document->get_error_code(), 'A structurally incompatible central pattern revision must also fail at the pinned hash boundary.' );
	$assert( $draft_content_before_pattern_checks !== '' && $draft_content_before_pattern_checks === (string) get_post_field( 'post_content', $draft_id ), 'Pattern hash checks must not silently rewrite the stored draft.' );
	unset( $incompatible_runtime, $incompatible_abilities );
	wp_update_post( wp_slash( array( 'ID' => (int) $body_block_id, 'post_content' => $body_pattern_original ) ) );

	$preview = $ability_boundary->preview_blueprint_migration( array( 'post_id' => $single_source, 'page_type' => $page_type, 'migration_id' => $migration_id ) );
	$preview_diagnostic = is_wp_error( $preview )
		? array( 'code' => $preview->get_error_code(), 'message' => $preview->get_error_message(), 'data' => $preview->get_error_data() )
		: $preview;
	$assert( is_array( $preview ) && true === ( $preview['proposal_eligible'] ?? false ), 'The content-only migration preview must be proposal-eligible: ' . wp_json_encode( $preview_diagnostic ) );
	$assert( true === ( $preview['validation']['valid'] ?? false ), 'The migration preview target must satisfy Blueprint v4 and Structure Contract v4.' );
	$assert( 'applied' === ( $preview['operations'][0]['status'] ?? '' ), 'The reviewed section move must be applied.' );
	$assert( 2 === count( $preview['override_rebase']['preserved'] ?? array() ), 'Both content overrides must be preserved.' );
	$assert( $single_before === (string) get_post_field( 'post_content', $single_source ), 'Migration preview must not write the published source.' );

	$proposal = $ability_boundary->create_blueprint_migration_proposal(
		array(
			'post_id'                      => $single_source,
			'page_type'                    => $page_type,
			'migration_id'                 => $migration_id,
			'content_language'             => 'en-US',
			'expected_modified_gmt'        => $preview['source_modified_gmt'],
			'expected_content_hash'        => $preview['source_content_hash'],
			'expected_migration_plan_hash' => $preview['plan_hash'],
			'idempotency_key'              => 'migration-single-' . $suffix,
			'confirm_proposal'             => true,
		)
	);
	$assert( is_array( $proposal ) && (int) ( $proposal['proposal_id'] ?? 0 ) > 0, 'The single migration must create a separate proposal draft.' );
	$proposal_id   = (int) $proposal['proposal_id'];
	$proposal_ids[] = $proposal_id;
	$post_ids[]     = $proposal_id;
	$proposal_post  = get_post( $proposal_id );
	$assert( $proposal_post instanceof WP_Post && 'draft' === $proposal_post->post_status, 'The migration result must remain a draft proposal.' );
	$assert( $single_before === (string) get_post_field( 'post_content', $single_source ), 'Proposal creation must not rewrite the published source.' );
	$assert( 3 === (int) get_post_meta( $single_source, Managed_Document_State::BLUEPRINT_VERSION_META, true ), 'The published source baseline must remain at Blueprint v3.' );
	$assert( 4 === (int) get_post_meta( $proposal_id, Managed_Document_State::BLUEPRINT_VERSION_META, true ), 'The proposal must carry the Blueprint v4 baseline.' );
	$assert( 4 === (int) get_post_meta( $proposal_id, Managed_Document_State::STRUCTURE_CONTRACT_VERSION_META, true ), 'The proposal must carry Structure Contract v4.' );
	$proposal_blocks = array_values( array_filter( parse_blocks( (string) $proposal_post->post_content ), static fn( array $block ): bool => null !== ( $block['blockName'] ?? null ) ) );
	$proposal_patterns = array_map(
		static fn( array $block ): string => (string) ( $block['attrs']['metadata']['wpsuiteAgentComposer']['patternName'] ?? '' ),
		$proposal_blocks
	);
	$assert( array( $body_pattern, $hero_pattern ) === $proposal_patterns, 'The proposal content must use the target body-before-hero order.' );

	$replay = $ability_boundary->create_blueprint_migration_proposal(
		array(
			'post_id'                      => $single_source,
			'page_type'                    => $page_type,
			'migration_id'                 => $migration_id,
			'content_language'             => 'en-US',
			'expected_modified_gmt'        => $preview['source_modified_gmt'],
			'expected_content_hash'        => $preview['source_content_hash'],
			'expected_migration_plan_hash' => $preview['plan_hash'],
			'idempotency_key'              => 'migration-single-' . $suffix,
			'confirm_proposal'             => true,
		)
	);
	$assert( true === ( $replay['idempotent_replay'] ?? false ) && $proposal_id === (int) ( $replay['proposal_id'] ?? 0 ), 'Retrying the same single migration must reuse its proposal.' );

	$batch_plan = $ability_boundary->plan_blueprint_migration( array( 'page_type' => $page_type, 'migration_id' => $migration_id, 'page' => 1, 'per_page' => 100 ) );
	$assert( is_array( $batch_plan ) && 3 === (int) ( $batch_plan['total_candidates'] ?? 0 ), 'The bounded batch plan must find all exact v3 candidates.' );
	$assert( 2 === (int) ( $batch_plan['batch_counts']['automatic'] ?? 0 ), 'Two content-only sources must classify as automatic.' );
	$assert( 1 === (int) ( $batch_plan['batch_counts']['review_required'] ?? 0 ), 'The explicit order override must classify as review-required.' );
	$assert( 0 === (int) ( $batch_plan['batch_counts']['incompatible'] ?? -1 ), 'No valid fixture source may classify as incompatible.' );
	$bulk_item = current( array_filter( $batch_plan['items'], static fn( array $item ): bool => $bulk_source === (int) ( $item['post_id'] ?? 0 ) ) );
	$review_item = current( array_filter( $batch_plan['items'], static fn( array $item ): bool => $review_source === (int) ( $item['post_id'] ?? 0 ) ) );
	$assert( is_array( $bulk_item ) && 'automatic' === ( $bulk_item['classification'] ?? '' ), 'The selected bulk source must be automatic.' );
	$assert( is_array( $review_item ) && 'review_required' === ( $review_item['classification'] ?? '' ), 'The order-override source must be held for review.' );
	$bulk_before = (string) get_post_field( 'post_content', $bulk_source );
	$bulk_request_item = array(
		'post_id'                      => $bulk_source,
		'content_language'             => $bulk_item['content_language'],
		'expected_modified_gmt'        => $bulk_item['expected_modified_gmt'],
		'expected_content_hash'        => $bulk_item['expected_content_hash'],
		'expected_migration_plan_hash' => $bulk_item['expected_migration_plan_hash'],
		'idempotency_key'              => 'migration-bulk-' . $suffix,
	);
	$bulk_result = $ability_boundary->create_blueprint_migration_proposals( array( 'page_type' => $page_type, 'migration_id' => $migration_id, 'confirm_bulk_proposals' => true, 'items' => array( $bulk_request_item ) ) );
	$assert( is_array( $bulk_result ) && 1 === (int) ( $bulk_result['counts']['created'] ?? 0 ), 'The reviewed bulk item must create one proposal.' );
	$assert( false === ( $bulk_result['publication_changed'] ?? true ), 'Bulk migration must report that publication was not changed.' );
	$bulk_proposal_id = (int) ( $bulk_result['items'][0]['proposal']['proposal_id'] ?? 0 );
	$assert( $bulk_proposal_id > 0 && 'draft' === get_post_status( $bulk_proposal_id ), 'The bulk result must also be a draft proposal.' );
	$proposal_ids[] = $bulk_proposal_id;
	$post_ids[]     = $bulk_proposal_id;
	$assert( $bulk_before === (string) get_post_field( 'post_content', $bulk_source ), 'Bulk proposal creation must not rewrite the published source.' );

	$bulk_replay = $ability_boundary->create_blueprint_migration_proposals( array( 'page_type' => $page_type, 'migration_id' => $migration_id, 'confirm_bulk_proposals' => true, 'items' => array( $bulk_request_item ) ) );
	$assert( 1 === (int) ( $bulk_replay['counts']['idempotent'] ?? 0 ), 'Retrying the same bulk item must be idempotent.' );
	$assert( count( array_unique( $proposal_ids ) ) === 2, 'The fixture must create exactly one single and one bulk proposal.' );

	echo wp_json_encode(
		array(
			'config_set'       => $config_set,
			'sources'          => count( $source_ids ),
			'proposals'        => count( array_unique( $proposal_ids ) ),
			'automatic'        => $batch_plan['batch_counts']['automatic'],
			'review_required'  => $batch_plan['batch_counts']['review_required'],
			'publication_changed' => false,
		)
	) . PHP_EOL;
} finally {
	$cleanup();
}
