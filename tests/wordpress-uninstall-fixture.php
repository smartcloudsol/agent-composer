<?php

use SmartCloud\AgentComposer\Application\Configuration\ConfigSetManager;
use SmartCloud\AgentComposer\Domain\Configuration\EntityType;
use SmartCloud\AgentComposer\Infrastructure\Persistence\AuditTable;
use SmartCloud\AgentComposer\Infrastructure\Persistence\WordPressConfigurationRepository;

$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => array( 'ID' ) ) );
if ( empty( $admins ) ) {
	throw new RuntimeException( 'The uninstall fixture needs an administrator.' );
}
wp_set_current_user( (int) $admins[0]->ID );

$repository = new WordPressConfigurationRepository();
$manager    = new ConfigSetManager( $repository, new AuditTable() );
$config_set = 'c4-uninstall-fixture';
if ( empty( $repository->find_by_type( EntityType::CONFIG_SET, $config_set ) ) ) {
	$manager->create( 'C4 uninstall fixture', $config_set );
	$manager->create_entity(
		$config_set,
		EntityType::BLUEPRINT,
		'page',
		array( 'schema_version' => '1.0', 'page_type' => 'page', 'excerpt_policy' => 'optional' )
	);
}

$ordinary_draft = get_page_by_path( 'c4-uninstall-preserved-draft', OBJECT, 'page' );
if ( ! $ordinary_draft instanceof WP_Post ) {
	$ordinary_draft_id = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'draft',
			'post_name'    => 'c4-uninstall-preserved-draft',
			'post_title'   => 'C4 uninstall preserved draft',
			'post_content' => '<!-- wp:paragraph --><p>Ordinary draft fixture.</p><!-- /wp:paragraph -->',
		),
		true
	);
	if ( is_wp_error( $ordinary_draft_id ) ) {
		throw new RuntimeException( $ordinary_draft_id->get_error_message() );
	}
}

$preview_draft = get_page_by_path( 'c4-uninstall-preview-draft', OBJECT, 'page' );
if ( ! $preview_draft instanceof WP_Post ) {
	$preview_draft_id = wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_status'  => 'draft',
			'post_name'    => 'c4-uninstall-preview-draft',
			'post_title'   => 'C4 uninstall preview draft',
			'post_content' => '<!-- wp:paragraph --><p>Temporary preview fixture.</p><!-- /wp:paragraph -->',
		),
		true
	);
	if ( is_wp_error( $preview_draft_id ) ) {
		throw new RuntimeException( $preview_draft_id->get_error_message() );
	}
	update_post_meta( $preview_draft_id, '_smartcloud_composer_preview', '1' );
	update_post_meta( $preview_draft_id, '_wpsuite_agent_owned', '1' );
}

if ( ! wp_next_scheduled( 'smartcloud_composer_cleanup_preview_drafts' ) ) {
	wp_schedule_single_event( time() + HOUR_IN_SECONDS, 'smartcloud_composer_cleanup_preview_drafts' );
}

echo wp_json_encode(
	array(
		'config_entities' => count( $repository->entities( $config_set ) ),
		'role_exists'     => get_role( 'smartcloud_agent' ) instanceof WP_Role,
		'audit_events'    => count( ( new AuditTable() )->events( 20 ) ),
		'ordinary_draft'  => get_page_by_path( 'c4-uninstall-preserved-draft', OBJECT, 'page' ) instanceof WP_Post,
		'preview_draft'   => get_page_by_path( 'c4-uninstall-preview-draft', OBJECT, 'page' ) instanceof WP_Post,
		'cleanup_cron'    => false !== wp_next_scheduled( 'smartcloud_composer_cleanup_preview_drafts' ),
	)
) . PHP_EOL;
