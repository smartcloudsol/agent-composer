<?php

use SmartCloud\AgentComposer\Application\Configuration\ConfigPackageImporter;
use SmartCloud\AgentComposer\Application\Configuration\ConfigSetActivator;
use SmartCloud\AgentComposer\Application\Configuration\ConfigSetValidator;
use SmartCloud\AgentComposer\Application\Configuration\ValidationReceiptService;
use SmartCloud\AgentComposer\Domain\Configuration\EntityType;
use SmartCloud\AgentComposer\Infrastructure\Persistence\AuditTable;
use SmartCloud\AgentComposer\Infrastructure\Persistence\WordPressConfigurationRepository;
use SmartCloud\AgentComposer\Integration\Providers\ProviderRegistry;

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

$package_file = '/tmp/wpsuite-site-contract.package.json';
if ( ! is_readable( $package_file ) ) {
	throw new RuntimeException( 'The staged WP Suite Composer package is missing.' );
}
$package = json_decode( (string) file_get_contents( $package_file ), true );
if ( ! is_array( $package ) ) {
	throw new RuntimeException( 'The staged WP Suite Composer package is invalid JSON.' );
}

$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => array( 'ID' ) ) );
if ( empty( $admins ) ) {
	throw new RuntimeException( 'The WP Suite migration needs an administrator.' );
}
wp_set_current_user( (int) $admins[0]->ID );

$config_set = 'wpsuite-site-contract-7-theme-1-0-59';
$repository = new WordPressConfigurationRepository();
$audit      = new AuditTable();
$importer   = new ConfigPackageImporter( $repository, $audit );
$receipts   = new ValidationReceiptService();
$validator  = new ConfigSetValidator( $repository, $receipts, new ProviderRegistry(), $audit );
$activator  = new ConfigSetActivator( $repository, $receipts, $audit );

$existing = $repository->find_by_type( EntityType::CONFIG_SET, $config_set );
if ( empty( $existing ) ) {
	$importer->import( $package );
}

$validation = $validator->validate( $config_set );
if ( ! $validation['valid'] || 26 !== $validation['page_type_count'] ) {
	throw new RuntimeException( 'The canonical WP Suite Config Set did not pass complete validation: ' . wp_json_encode( $validation ) );
}

$activation = $activator->activate( $config_set, $validation['receipt'] );
$set        = $repository->describe_config_set( $config_set );

echo wp_json_encode(
	array(
		'config_set'      => $config_set,
		'active'          => (string) get_option( 'smartcloud_composer_active_config_set', '' ),
		'previous'        => $activation['previous'],
		'lifecycle'       => $set['lifecycle'],
		'config_hash'     => $set['config_hash'],
		'entity_count'    => count( $set['entities'] ),
		'blueprint_count' => $validation['page_type_count'],
		'provider_count'  => $validation['provider_count'],
	),
	JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
) . PHP_EOL;
