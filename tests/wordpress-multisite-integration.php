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

$assert( is_multisite(), 'The multisite integration test requires WordPress multisite.' );
$site_ids = array_map( 'intval', get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) );
$assert( count( $site_ids ) >= 2, 'The multisite integration test requires at least two sites.' );

$main_site_id      = (int) get_main_site_id();
$secondary_site_id = (int) current( array_values( array_diff( $site_ids, array( $main_site_id ) ) ) );
$suffix            = substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 10 );
$main_set          = 'c4-network-main-' . $suffix;
$secondary_set     = 'c4-network-secondary-' . $suffix;
$created           = array(
	$main_site_id      => array( $main_set ),
	$secondary_site_id => array( $secondary_set ),
);

$cleanup_site = static function ( int $site_id, array $config_sets ): void {
	switch_to_blog( $site_id );
	$repository = new WordPressConfigurationRepository();
	delete_option( 'smartcloud_composer_active_config_set' );
	delete_option( 'smartcloud_composer_previous_config_set' );
	delete_option( 'smartcloud_composer_active_snapshot' );
	delete_option( 'smartcloud_composer_activation_receipt' );
	delete_option( 'smartcloud_composer_activation_lock' );
	foreach ( $config_sets as $config_set ) {
		foreach ( $repository->entities( $config_set ) as $post ) {
			wp_delete_post( $post->ID, true );
		}
	}
	restore_current_blog();
};

$exercise_site = static function ( int $site_id, string $config_set, string $other_set ) use ( $assert ): array {
	switch_to_blog( $site_id );
	try {
		Activation::maybe_upgrade();
		$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => array( 'ID' ) ) );
		$assert( ! empty( $admins ), 'Every tested site must have an administrator.' );
		wp_set_current_user( (int) $admins[0]->ID );

		$repository = new WordPressConfigurationRepository();
		$audit      = new AuditTable();
		$receipts   = new ValidationReceiptService();
		$manager    = new ConfigSetManager( $repository, $audit );
		$validator  = new ConfigSetValidator( $repository, $receipts, new ProviderRegistry(), $audit );
		$activator  = new ConfigSetActivator( $repository, $receipts, $audit );

		$assert( empty( $repository->find_by_type( EntityType::CONFIG_SET, $other_set ) ), 'A config set from another site must not be visible.' );
		$manager->create( 'C4 multisite fixture', $config_set );
		$manager->create_entity(
			$config_set,
			EntityType::BLUEPRINT,
			'page',
			array(
				'schema_version' => '1.0',
				'page_type'      => 'page',
				'target'         => array( 'post_type' => 'page' ),
				'composition_mode' => 'structured-record',
				'excerpt_policy' => 'optional',
			)
		);
		$validation = $validator->validate( $config_set );
		$assert( true === $validation['valid'], 'The per-site fixture must validate.' );
		$activator->activate( $config_set, $validation['receipt'] );
		$assert( $config_set === get_option( 'smartcloud_composer_active_config_set' ), 'The active pointer must be site-local.' );
		$assert( get_role( Activation::ROLE ) instanceof WP_Role, 'The agent role must exist on every site.' );
		global $wpdb;
		$audit_table = $wpdb->prefix . 'smartcloud_composer_audit';
		$assert( $audit_table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $audit_table ) ), 'Every site must have its own audit table.' );

		return array(
			'site_id'       => $site_id,
			'table_prefix'  => $wpdb->prefix,
			'active_config' => $config_set,
			'audit_events'  => count( $audit->events( 50 ) ),
		);
	} finally {
		restore_current_blog();
	}
};

try {
	$main      = $exercise_site( $main_site_id, $main_set, $secondary_set );
	$secondary = $exercise_site( $secondary_site_id, $secondary_set, $main_set );

	switch_to_blog( $main_site_id );
	try {
		$repository = new WordPressConfigurationRepository();
		$assert( $main_set === get_option( 'smartcloud_composer_active_config_set' ), 'Secondary activation must not replace the main-site pointer.' );
		$assert( empty( $repository->find_by_type( EntityType::CONFIG_SET, $secondary_set ) ), 'Secondary configuration must remain isolated from the main site.' );
	} finally {
		restore_current_blog();
	}

	$sentinel = 'composer-no-network-state';
	$assert( $sentinel === get_site_option( 'smartcloud_composer_active_config_set', $sentinel ), 'Composer must not create a network-wide active config pointer.' );
	$assert( $sentinel === get_site_option( 'smartcloud_composer_network_preset', $sentinel ), 'Composer 1.0 must not distribute network presets.' );

	echo wp_json_encode(
		array(
			'plugin_version'    => SMARTCLOUD_COMPOSER_VERSION,
			'wordpress_version' => get_bloginfo( 'version' ),
			'php_version'       => PHP_VERSION,
			'multisite'         => true,
			'network_sites'     => count( $site_ids ),
			'per_site_config'   => true,
			'per_site_roles'    => true,
			'per_site_audit'    => true,
			'network_presets'   => false,
			'sites'             => array( $main, $secondary ),
		),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
	) . PHP_EOL;
} finally {
	foreach ( $created as $site_id => $config_sets ) {
		$cleanup_site( (int) $site_id, $config_sets );
	}
}
