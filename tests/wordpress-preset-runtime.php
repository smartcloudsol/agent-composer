<?php

use SmartCloud\AgentComposer\Domain\Configuration\EntityType;
use SmartCloud\AgentComposer\Infrastructure\Persistence\WordPressConfigurationRepository;

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};
$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => array( 'ID' ) ) );
$assert( ! empty( $admins ), 'The preset runtime fixture needs an administrator.' );
wp_set_current_user( (int) $admins[0]->ID );

$stage      = sanitize_key( (string) getenv( 'SMARTCLOUD_PRESET_STAGE' ) );
$fixture    = get_option( 'smartcloud_composer_preset_runtime_fixture', array() );
$repository = new WordPressConfigurationRepository();

$request = static function ( string $method, string $route, array $body = array() ): WP_REST_Response {
	$request = new WP_REST_Request( $method, $route );
	if ( 'GET' !== $method ) {
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$request->set_body_params( $body );
	}
	$response = rest_do_request( $request );
	if ( ! $response instanceof WP_REST_Response ) {
		throw new RuntimeException( 'The preset runtime REST request failed before producing a response.' );
	}
	return $response;
};

if ( 'setup' === $stage ) {
	$universal = $request( 'POST', '/smartcloud-agent-composer/v1/presets/universal-gutenberg/instantiate', array( 'label' => 'Universal runtime fixture' ) );
	$recommended = $request( 'POST', '/smartcloud-agent-composer/v1/presets/smartcloud-recommended/instantiate', array( 'label' => 'Recommended runtime fixture' ) );
	$assert( 201 === $universal->get_status() && 201 === $recommended->get_status(), 'Both built-in presets must instantiate.' );
	$universal_id   = (string) ( $universal->get_data()['config_set']['config_set'] ?? '' );
	$recommended_id = (string) ( $recommended->get_data()['config_set']['config_set'] ?? '' );
	$validation = $request( 'POST', '/smartcloud-agent-composer/v1/config-sets/' . $universal_id . '/validate' );
	$assert( true === ( $validation->get_data()['valid'] ?? false ), 'The Universal preset must validate before activation.' );
	$activation = $request( 'POST', '/smartcloud-agent-composer/v1/config-sets/' . $universal_id . '/activate' );
	$assert( 200 === $activation->get_status(), 'The Universal preset must activate explicitly.' );
	update_option( 'smartcloud_composer_preset_runtime_fixture', array( 'universal' => $universal_id, 'recommended' => $recommended_id ), false );
	echo wp_json_encode( array( 'stage' => 'setup', 'universal' => $universal_id, 'recommended' => $recommended_id, 'active' => get_option( 'smartcloud_composer_active_config_set' ) ) ) . PHP_EOL;
	return;
}

$assert( is_array( $fixture ) && ! empty( $fixture['universal'] ) && ! empty( $fixture['recommended'] ), 'The preset runtime fixture is missing.' );
if ( 'universal' === $stage ) {
	$ability = wp_get_ability( 'smartcloud-agent-composer/validate-content-draft' );
	$assert( is_object( $ability ), 'The Composer validation ability must exist.' );
	$result = $ability->execute(
		array(
			'page_type'       => 'page',
			'content_language' => str_replace( '_', '-', (string) get_bloginfo( 'language' ) ),
			'meta_description' => 'A portable Gutenberg page validated with the Universal Composer preset before any WordPress draft content is created or changed.',
			'sections'        => array(
				array( 'pattern' => 'smartcloud-composer/universal-hero', 'fields' => array( 'title' => 'Universal Gutenberg', 'introduction' => 'A theme-neutral starting point for governed content.' ) ),
				array( 'pattern' => 'smartcloud-composer/universal-content', 'fields' => array( 'heading' => 'Clear structure', 'body' => 'Composer limits generation to approved patterns and registered core blocks.' ) ),
				array( 'pattern' => 'smartcloud-composer/universal-cta', 'fields' => array( 'heading' => 'Review the result', 'body' => 'Validate and preview before publication.', 'url' => 'https://example.test/review/', 'label' => 'Review' ) ),
			),
		)
	);
	$assert( is_array( $result ) && true === ( $result['valid'] ?? false ), 'The Universal preset must assemble a valid non-writing candidate.' );
	$validation = $request( 'POST', '/smartcloud-agent-composer/v1/config-sets/' . $fixture['recommended'] . '/validate' );
	$assert( true === ( $validation->get_data()['valid'] ?? false ), 'The Recommended preset must validate before activation.' );
	$activation = $request( 'POST', '/smartcloud-agent-composer/v1/config-sets/' . $fixture['recommended'] . '/activate' );
	$assert( 200 === $activation->get_status(), 'The Recommended preset must activate explicitly.' );
	echo wp_json_encode( array( 'stage' => 'universal', 'valid' => true, 'active' => get_option( 'smartcloud_composer_active_config_set' ) ) ) . PHP_EOL;
	return;
}

if ( 'recommended' === $stage ) {
	$ability = wp_get_ability( 'smartcloud-agent-composer/validate-content-draft' );
	$assert( is_object( $ability ), 'The Composer validation ability must exist.' );
	$result = $ability->execute(
		array(
			'page_type'       => 'page',
			'content_language' => str_replace( '_', '-', (string) get_bloginfo( 'language' ) ),
			'meta_description' => 'A structured Gutenberg page validated with the SmartCloud Recommended preset before any WordPress draft content is created or changed.',
			'sections'        => array(
				array( 'pattern' => 'smartcloud-composer/recommended-hero', 'fields' => array( 'eyebrow' => 'Governed Gutenberg', 'title' => 'SmartCloud Recommended', 'introduction' => 'A richer portable starting point.', 'url' => 'https://example.test/start/', 'label' => 'Start' ) ),
				array( 'pattern' => 'smartcloud-composer/recommended-features', 'fields' => array( 'heading' => 'Built for review', 'item_one_title' => 'Discover', 'item_one_body' => 'Read theme capabilities.', 'item_two_title' => 'Compose', 'item_two_body' => 'Use approved patterns.', 'item_three_title' => 'Validate', 'item_three_body' => 'Check before saving.' ) ),
				array( 'pattern' => 'smartcloud-composer/recommended-steps', 'fields' => array( 'heading' => 'A clear workflow', 'step_one' => 'Select a working preset.', 'step_two' => 'Review its contract.', 'step_three' => 'Validate and activate explicitly.' ) ),
				array( 'pattern' => 'smartcloud-composer/recommended-cta', 'fields' => array( 'heading' => 'Continue safely', 'body' => 'Create only an agent-owned draft.', 'url' => 'https://example.test/continue/', 'label' => 'Continue' ) ),
			),
		)
	);
	$assert( is_array( $result ) && true === ( $result['valid'] ?? false ), 'The Recommended preset must assemble a valid non-writing candidate.' );
	foreach ( array( $fixture['universal'], $fixture['recommended'] ) as $config_set ) {
		foreach ( $repository->entities( (string) $config_set ) as $post ) {
			wp_delete_post( $post->ID, true );
		}
	}
	foreach ( array( 'smartcloud_composer_active_config_set', 'smartcloud_composer_previous_config_set', 'smartcloud_composer_active_snapshot', 'smartcloud_composer_activation_receipt', 'smartcloud_composer_preset_runtime_fixture' ) as $option ) {
		delete_option( $option );
	}
	echo wp_json_encode( array( 'stage' => 'recommended', 'valid' => true, 'cleaned' => true ) ) . PHP_EOL;
	return;
}

throw new RuntimeException( 'Unknown SMARTCLOUD_PRESET_STAGE.' );
