<?php

use SmartCloud\AgentComposer\Application\Configuration\ConfigSetActivator;
use SmartCloud\AgentComposer\Application\Configuration\ConfigSetManager;
use SmartCloud\AgentComposer\Application\Configuration\ConfigSetValidator;
use SmartCloud\AgentComposer\Application\Configuration\ValidationReceiptService;
use SmartCloud\AgentComposer\Domain\Configuration\EntityType;
use SmartCloud\AgentComposer\Infrastructure\Persistence\AuditTable;
use SmartCloud\AgentComposer\Infrastructure\Persistence\WordPressConfigurationRepository;
use SmartCloud\AgentComposer\Infrastructure\WordPress\Activation;
use SmartCloud\AgentComposer\Integration\Providers\ProviderRegistry;

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};

$stage = getenv( 'SMARTCLOUD_TAXONOMY_STAGE' ) ?: 'run';
$assert( in_array( $stage, array( 'setup', 'run' ), true ), 'SMARTCLOUD_TAXONOMY_STAGE must be setup or run.' );

$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => array( 'ID' ) ) );
$assert( ! empty( $admins ), 'The taxonomy integration site needs an administrator.' );
wp_set_current_user( (int) $admins[0]->ID );

$fixture_option = 'smartcloud_composer_taxonomy_integration_fixture';
$suffix         = '';
$config_set     = '';
$term_slug      = '';
$term_name      = '';
$agent_id       = 0;
$draft_id     = 0;
$term_id      = 0;
$repository   = new WordPressConfigurationRepository();
$audit        = new AuditTable();
$receipts     = new ValidationReceiptService();
$manager      = new ConfigSetManager( $repository, $audit );
$validator    = new ConfigSetValidator( $repository, $receipts, new ProviderRegistry(), $audit );
$activator    = new ConfigSetActivator( $repository, $receipts, $audit );

$cleanup = static function () use ( &$agent_id, &$draft_id, &$term_id, &$config_set, $repository, $fixture_option ): void {
	wp_set_current_user( 1 );
	if ( $draft_id > 0 ) {
		wp_delete_post( $draft_id, true );
	}
	if ( $term_id > 0 ) {
		wp_delete_term( $term_id, 'category' );
	}
	if ( $agent_id > 0 ) {
		wp_delete_user( $agent_id );
	}
	foreach ( $repository->entities( $config_set ) as $post ) {
		wp_delete_post( $post->ID, true );
	}
	foreach ( array( 'smartcloud_composer_active_config_set', 'smartcloud_composer_previous_config_set', 'smartcloud_composer_active_snapshot', 'smartcloud_composer_activation_receipt' ) as $option ) {
		delete_option( $option );
	}
	delete_option( $fixture_option );
};


if ( 'setup' === $stage ) {
	$suffix     = substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 10 );
	$config_set = 'taxonomy-smoke-' . $suffix;
	$term_slug  = 'taxonomy-smoke-' . $suffix;
	$term_name  = 'Taxonomy Smoke ' . strtoupper( $suffix );

	$manager->create( 'Taxonomy workflow smoke', $config_set );
	$contract = $repository->find_entity( $config_set, EntityType::SITE_CONTRACT, 'contract:site' );
	$assert( $contract instanceof WP_Post, 'The taxonomy integration Site Contract must exist.' );
	$contract_data = $repository->describe_entity( $contract );
	$contract_payload = $contract_data['payload'];
	$contract_payload['design_policy'] = array(
		'schema_version'         => 1,
		'content_language'       => 'en-US',
		'post_type_contract'     => array( 'article' => 'post' ),
		'content_taxonomy_access' => array(
			'post' => array(
				'category' => array(
					'search'                 => true,
					'assign'                 => true,
					'create'                 => true,
					'maximum_items'          => 3,
					'assignment_mode'        => 'replace',
					'creation_parent_policy' => 'root-only',
					'creation_parent_slugs'  => array(),
				),
			),
		),
	);
	$repository->update_working_entity( $contract->ID, $contract_payload, $contract_data['entity_revision'], $contract_data['content_hash'] );
	$manager->create_entity(
		$config_set,
		EntityType::BLUEPRINT,
		'article',
		array(
			'schema_version'   => '1.0',
			'entity_type'     => 'blueprint',
			'page_type'       => 'article',
			'target_post_type' => 'post',
			'composition_mode' => 'structured-record',
			'excerpt_policy'  => 'optional',
		)
	);
	$validation = $validator->validate( $config_set );
	$assert( true === ( $validation['valid'] ?? false ), 'The taxonomy integration Config Set must validate: ' . wp_json_encode( $validation['errors'] ?? array() ) );
	$activator->activate( $config_set, (string) $validation['receipt'] );

	$agent_id = wp_create_user( 'taxonomy-agent-' . $suffix, wp_generate_password( 32, true, true ), 'taxonomy-' . $suffix . '@example.test' );
	$assert( ! is_wp_error( $agent_id ) && $agent_id > 0, 'The taxonomy integration agent must be created.' );
	( new WP_User( $agent_id ) )->set_role( Activation::ROLE );
	wp_set_current_user( $agent_id );
	$assert( current_user_can( Activation::CAP_ASSIGN_TERMS ), 'The agent must have the dedicated taxonomy assignment capability.' );
	$assert( current_user_can( Activation::CAP_CREATE_TERMS ), 'The agent must have the dedicated taxonomy creation capability.' );
	$assert( ! current_user_can( 'manage_categories' ), 'The agent must not gain broad native taxonomy management.' );
	update_option(
		$fixture_option,
		array(
			'suffix'     => $suffix,
			'config_set' => $config_set,
			'term_slug'  => $term_slug,
			'term_name'  => $term_name,
			'agent_id'   => $agent_id,
		),
		false
	);
	echo wp_json_encode( array( 'taxonomy_setup' => true, 'config_set' => $config_set ), JSON_UNESCAPED_SLASHES ) . PHP_EOL;
	return;
}

$fixture = get_option( $fixture_option, array() );
$assert( is_array( $fixture ) && ! empty( $fixture['config_set'] ), 'The taxonomy integration setup fixture is missing.' );
$suffix     = (string) $fixture['suffix'];
$config_set = (string) $fixture['config_set'];
$term_slug  = (string) $fixture['term_slug'];
$term_name  = (string) $fixture['term_name'];
$agent_id   = (int) $fixture['agent_id'];
wp_set_current_user( $agent_id );
$assert( current_user_can( Activation::CAP_ASSIGN_TERMS ), 'The agent must retain the dedicated taxonomy assignment capability.' );
$assert( current_user_can( Activation::CAP_CREATE_TERMS ), 'The agent must retain the dedicated taxonomy creation capability.' );
$assert( ! current_user_can( 'manage_categories' ), 'The agent must not gain broad native taxonomy management.' );

try {

	$contract_ability = wp_get_ability( 'smartcloud-agent-composer/get-taxonomy-contract' );
	$search_ability   = wp_get_ability( 'smartcloud-agent-composer/search-taxonomy-terms' );
	$create_ability   = wp_get_ability( 'smartcloud-agent-composer/create-taxonomy-term' );
	$assign_ability   = wp_get_ability( 'smartcloud-agent-composer/assign-taxonomy-terms' );
	$inspect_ability  = wp_get_ability( 'smartcloud-agent-composer/inspect-taxonomy-terms' );
	foreach ( array( $contract_ability, $search_ability, $create_ability, $assign_ability, $inspect_ability ) as $ability ) {
		$assert( is_object( $ability ), 'Every canonical taxonomy ability must be registered.' );
	}

	$taxonomy_contract = $contract_ability->execute( array( 'page_type' => 'article' ) );
	$contract_detail = is_wp_error( $taxonomy_contract )
		? $taxonomy_contract->get_error_code() . ': ' . $taxonomy_contract->get_error_message()
		: wp_json_encode( $taxonomy_contract );
	$assert( ! is_wp_error( $taxonomy_contract ) && 'category' === ( $taxonomy_contract['taxonomies'][0]['taxonomy'] ?? '' ), 'The active taxonomy contract must expose category: ' . $contract_detail );
	$initial_search = $search_ability->execute( array( 'page_type' => 'article', 'taxonomy' => 'category', 'query' => $term_slug ) );
	$assert( ! is_wp_error( $initial_search ) && 0 === ( $initial_search['match_count'] ?? -1 ), 'The workflow must confirm that the new slug does not exist.' );

	$created = $create_ability->execute(
		array(
			'page_type'        => 'article',
			'content_language' => 'en-US',
			'taxonomy'         => 'category',
			'name'             => $term_name,
			'slug'             => $term_slug,
			'description'      => 'A standalone public archive description created by the governed taxonomy integration workflow.',
			'confirm_create'   => true,
		)
	);
	$assert( ! is_wp_error( $created ) && true === ( $created['created'] ?? false ), 'A confirmed allowlisted term must be created.' );
	$term_id = (int) ( $created['term_id'] ?? 0 );
	$assert( $term_id > 0, 'Created taxonomy terms must return a positive term ID.' );
	$replay = $create_ability->execute(
		array(
			'page_type'        => 'article',
			'content_language' => 'en-US',
			'taxonomy'         => 'category',
			'name'             => $term_name,
			'slug'             => $term_slug,
			'description'      => 'A standalone public archive description created by the governed taxonomy integration workflow.',
			'confirm_create'   => true,
		)
	);
	$assert( ! is_wp_error( $replay ) && true === ( $replay['idempotent_replay'] ?? false ) && $term_id === (int) ( $replay['term_id'] ?? 0 ), 'Exact-slug term creation must be idempotent.' );

	$resolved = $search_ability->execute( array( 'page_type' => 'article', 'taxonomy' => 'category', 'query' => $term_slug ) );
	$assert( ! is_wp_error( $resolved ) && $term_id === (int) ( $resolved['matches'][0]['term_id'] ?? 0 ), 'Term search must resolve the created durable slug.' );

	$create_draft = wp_get_ability( 'smartcloud-agent-composer/create-content-draft' );
	$draft = $create_draft->execute(
		array(
			'page_type'        => 'article',
			'content_language' => 'en-US',
			'title'             => 'Taxonomy workflow draft',
			'slug'              => 'taxonomy-workflow-' . $suffix,
			'meta_description'  => 'This governed taxonomy workflow draft verifies term search, safe creation, assignment, concurrency, and explicit read-back before review.',
			'sections'          => array(),
			'idempotency_key'   => 'taxonomy-workflow-' . $suffix,
		)
	);
	$assert( ! is_wp_error( $draft ), 'The taxonomy integration draft must be created.' );
	$draft_id = (int) ( $draft['post_id'] ?? 0 );
	$assigned = $assign_ability->execute(
		array(
			'post_id'               => $draft_id,
			'page_type'             => 'article',
			'taxonomy'              => 'category',
			'term_ids'              => array( $term_id ),
			'mode'                  => 'replace',
			'expected_modified_gmt' => (string) $draft['modified_gmt'],
			'expected_revision'     => (string) $draft['revision'],
			'confirm_assignment'    => true,
		)
	);
	$assert( ! is_wp_error( $assigned ) && $term_id === (int) ( $assigned['terms'][0]['term_id'] ?? 0 ), 'The existing term must be assigned to the owned draft.' );
	$inspected = $inspect_ability->execute( array( 'post_id' => $draft_id, 'page_type' => 'article', 'taxonomy' => 'category' ) );
	$assert( ! is_wp_error( $inspected ) && $term_id === (int) ( $inspected['taxonomies'][0]['terms'][0]['term_id'] ?? 0 ), 'Taxonomy inspection must read back the stored assignment.' );
	$assert( (string) $assigned['revision'] === (string) $inspected['revision'], 'Read-back must return the current draft revision.' );

	echo wp_json_encode(
		array(
			'plugin_version'       => SMARTCLOUD_COMPOSER_VERSION,
			'taxonomy_contract'    => true,
			'search_before_create' => true,
			'idempotent_create'    => true,
			'draft_assignment'     => true,
			'read_back'            => true,
			'broad_native_caps'    => false,
		),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
	) . PHP_EOL;
} finally {
	$cleanup();
}
