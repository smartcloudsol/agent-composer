<?php

global $wpdb;

$config_entities = (int) $wpdb->get_var(
	$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s", 'smartcloud_composer' ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
);
$audit_table = $wpdb->prefix . 'smartcloud_composer_audit';
$table_exists = $audit_table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $audit_table ) );
$ordinary_draft = get_page_by_path( 'c4-uninstall-preserved-draft', OBJECT, 'page' );
$preview_draft  = get_page_by_path( 'c4-uninstall-preview-draft', OBJECT, 'page' );
$state = array(
	'config_entities' => $config_entities,
	'role_exists'     => get_role( 'smartcloud_agent' ) instanceof WP_Role,
	'audit_table'     => $table_exists,
	'db_version'      => get_option( 'smartcloud_composer_db_version', false ),
	'ordinary_draft'  => $ordinary_draft instanceof WP_Post,
	'preview_draft'   => $preview_draft instanceof WP_Post,
	'cleanup_cron'    => false !== wp_next_scheduled( 'smartcloud_composer_cleanup_preview_drafts' ),
);

if ( 0 !== $config_entities || $state['role_exists'] || $table_exists || false !== $state['db_version'] || ! $state['ordinary_draft'] || $state['preview_draft'] || $state['cleanup_cron'] ) {
	throw new RuntimeException( 'Composer uninstall left plugin-owned data behind: ' . wp_json_encode( $state ) );
}

wp_delete_post( $ordinary_draft->ID, true );

echo wp_json_encode( $state ) . PHP_EOL;
