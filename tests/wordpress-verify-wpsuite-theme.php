<?php

use SmartCloud\AgentComposer\Application\Configuration\SiteDiscoveryService;
use SmartCloud\AgentComposer\Execution\Ability_Provider_Registry;
use SmartCloud\AgentComposer\Infrastructure\Persistence\AuditTable;
use SmartCloud\AgentComposer\Infrastructure\Persistence\WordPressConfigurationRepository;
use SmartCloud\AgentComposer\Integration\Providers\ProviderRegistry;

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

$theme = wp_get_theme();
if ( 'twentytwentyfive-child' !== $theme->get_stylesheet() || '1.0.29' !== (string) $theme->get( 'Version' ) ) {
	throw new RuntimeException( 'The stripped WP Suite child theme is not active at version 1.0.29.' );
}

$theme_root = $theme->get_stylesheet_directory();
$retired    = array(
	'agent',
	'inc/agent-design.php',
	'inc/gutenberg-migration.php',
	'assets/css/agent-design.css',
	'assets/css/legacy-elementor.css',
	'assets/css/legacy-elementor-blog.css',
	'templates/legacy-elementor-page.html',
	'templates/legacy-elementor-archive.html',
);
foreach ( $retired as $relative_path ) {
	if ( file_exists( trailingslashit( $theme_root ) . $relative_path ) ) {
		throw new RuntimeException( 'Retired theme artifact remains: ' . $relative_path );
	}
}

$repository = new WordPressConfigurationRepository();
$active     = (string) get_option( 'smartcloud_composer_active_config_set', '' );
$set        = $repository->describe_config_set( $active );
$blueprints = array_values(
	array_filter(
		$set['entities'],
		static fn ( array $entity ): bool => 'blueprint' === ( $entity['type'] ?? '' )
	)
);
if ( 'wpsuite-site-contract-2' !== $active || 15 !== count( $blueprints ) ) {
	throw new RuntimeException( 'The active database Config Set does not contain all 15 WP Suite blueprints.' );
}

$discovery = new SiteDiscoveryService(
	$repository,
	new ProviderRegistry(),
	new Ability_Provider_Registry(),
	new AuditTable()
);
$result = $discovery->discover( false );
if ( 'confirmed' !== $result['theme']['manifest_status'] ) {
	throw new RuntimeException( 'Composer did not confirm the child-theme presentational manifest.' );
}
if ( '1.0.0-rc.1' !== ( $result['theme']['manifest']['schema_version'] ?? '' ) ) {
	throw new RuntimeException( 'Unexpected theme presentational-manifest schema version.' );
}

$registered_block_names = array_column( $result['registered_blocks'] ?? array(), 'name' );
if ( ! in_array( 'core/paragraph', $registered_block_names, true ) ) {
	throw new RuntimeException( 'Discovery did not expose the current WordPress registered-block catalog.' );
}

$pattern_names = $result['theme']['manifest']['patterns'] ?? array();
if ( 39 !== count( $pattern_names ) ) {
	throw new RuntimeException( 'Expected 39 declared WP Suite patterns, found ' . count( $pattern_names ) . '.' );
}

$common_css = apply_filters( 'wpsuite_scoped_css_common_files', array( 'common.css', 'wps-solutions.css' ) );
if ( ! in_array( 'pattern-library.css', $common_css, true ) ) {
	throw new RuntimeException( 'The pattern-library stylesheet is not registered.' );
}

echo wp_json_encode(
	array(
		'theme'             => $theme->get_stylesheet(),
		'theme_version'     => (string) $theme->get( 'Version' ),
		'active_config_set' => $active,
		'blueprint_count'   => count( $blueprints ),
		'pattern_count'     => count( $pattern_names ),
		'registered_blocks' => count( $registered_block_names ),
		'manifest_status'   => $result['theme']['manifest_status'],
	),
	JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
) . PHP_EOL;
