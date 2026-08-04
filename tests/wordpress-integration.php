<?php

use SmartCloud\AgentComposer\Application\Configuration\ConfigPackageExporter;
use SmartCloud\AgentComposer\Application\Configuration\ConfigPackageImporter;
use SmartCloud\AgentComposer\Application\Configuration\ConfigBackupService;
use SmartCloud\AgentComposer\Application\Configuration\ConfigSetActivator;
use SmartCloud\AgentComposer\Application\Configuration\ConfigSetManager;
use SmartCloud\AgentComposer\Application\Configuration\ConfigSetValidator;
use SmartCloud\AgentComposer\Application\Configuration\ValidationReceiptService;
use SmartCloud\AgentComposer\Domain\Configuration\CanonicalJson;
use SmartCloud\AgentComposer\Domain\Configuration\ConfigurationConflict;
use SmartCloud\AgentComposer\Domain\Configuration\EntityType;
use SmartCloud\AgentComposer\Infrastructure\Persistence\AuditTable;
use SmartCloud\AgentComposer\Infrastructure\Persistence\ActiveConfigurationSource;
use SmartCloud\AgentComposer\Infrastructure\Persistence\WordPressConfigurationRepository;
use SmartCloud\AgentComposer\Infrastructure\WordPress\Activation;
use SmartCloud\AgentComposer\Infrastructure\WordPress\ConfigurationController;
use SmartCloud\AgentComposer\Integration\Providers\ProviderRegistry;

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};

$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => array( 'ID' ) ) );
$assert( ! empty( $admins ), 'The integration site needs an administrator.' );
wp_set_current_user( (int) $admins[0]->ID );

$suffix     = substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 10 );
$set_a      = 'c4-smoke-a-' . $suffix;
$set_b      = 'c4-smoke-b-' . $suffix;
$set_c      = 'c4-smoke-c-' . $suffix;
$set_d      = 'c4-smoke-delete-' . $suffix;
$created    = array();
$repository = new WordPressConfigurationRepository();
$audit      = new AuditTable();
$receipts   = new ValidationReceiptService();
$manager    = new ConfigSetManager( $repository, $audit );
$validator  = new ConfigSetValidator( $repository, $receipts, new ProviderRegistry(), $audit );
$activator  = new ConfigSetActivator( $repository, $receipts, $audit );
$exporter   = new ConfigPackageExporter( $repository, $audit );
$importer   = new ConfigPackageImporter( $repository, $audit );
$backup     = new ConfigBackupService( $repository, $exporter, $importer, $audit );
$summary    = array();

$cleanup = static function () use ( &$created, $repository ): void {
	delete_option( 'smartcloud_composer_active_config_set' );
	delete_option( 'smartcloud_composer_previous_config_set' );
	delete_option( 'smartcloud_composer_active_snapshot' );
	delete_option( 'smartcloud_composer_activation_receipt' );
	delete_option( 'smartcloud_composer_activation_lock' );
	foreach ( array_unique( $created ) as $config_set ) {
		foreach ( $repository->entities( $config_set ) as $post ) {
			wp_delete_post( $post->ID, true );
		}
	}
};

try {
	$manager->create( 'C4 smoke A', $set_a );
	$created[] = $set_a;
	$manager->create_entity(
		$set_a,
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
	$validation_a = $validator->validate( $set_a );
	$assert( true === $validation_a['valid'], 'Set A must validate.' );
	$activation_a = $activator->activate( $set_a, $validation_a['receipt'] );
	$assert( $set_a === get_option( 'smartcloud_composer_active_config_set' ), 'Set A must become active.' );
	$assert( '' === $activation_a['previous'], 'The first activation must not have a previous set.' );

	$active_blueprint = $repository->find_entity( $set_a, EntityType::BLUEPRINT, 'page' );
	$assert( $active_blueprint instanceof WP_Post, 'The active blueprint must exist.' );
	$active_description = $repository->describe_entity( $active_blueprint );
	try {
		$repository->update_working_entity( $active_blueprint->ID, $active_description['payload'], $active_description['entity_revision'], $active_description['content_hash'] );
		throw new RuntimeException( 'Active entities must be immutable.' );
	} catch ( InvalidArgumentException ) {
		// Expected.
	}

	$manager->clone( $set_a, 'C4 smoke B', $set_b );
	$created[] = $set_b;
	$working_blueprint = $repository->find_entity( $set_b, EntityType::BLUEPRINT, 'page' );
	$assert( $working_blueprint instanceof WP_Post, 'The cloned blueprint must exist.' );
	$before = $repository->describe_entity( $working_blueprint );
	$payload = $before['payload'];
	$payload['excerpt_policy'] = 'required';
	$after = $repository->update_working_entity( $working_blueprint->ID, $payload, $before['entity_revision'], $before['content_hash'] );
	$assert( $before['entity_revision'] + 1 === $after['entity_revision'], 'A successful edit must increment the entity revision.' );
	try {
		$repository->update_working_entity( $working_blueprint->ID, $payload, $before['entity_revision'], $before['content_hash'] );
		throw new RuntimeException( 'A stale edit must conflict.' );
	} catch ( ConfigurationConflict $error ) {
		$assert( 'SMARTCLOUD_COMPOSER_CONFLICT' === $error->details()['code'], 'The conflict response code must be stable.' );
	}

	$validation_b = $validator->validate( $set_b );
	$assert( true === $validation_b['valid'], 'Set B must validate.' );
	try {
		$receipts->assert_valid( $validation_b['receipt'], $set_b, 'sha256:' . str_repeat( '0', 64 ) );
		throw new RuntimeException( 'A receipt must be checksum-bound.' );
	} catch ( InvalidArgumentException ) {
		// Expected.
	}
	$activation_b = $activator->activate( $set_b, $validation_b['receipt'] );
	$assert( $set_a === $activation_b['previous'], 'Set B activation must retain Set A as the rollback target.' );
	$assert( $set_b === get_option( 'smartcloud_composer_active_config_set' ), 'Set B must become active.' );
	update_post_meta( $working_blueprint->ID, '_smartcloud_composer_entity_key', 'page-2' );
	delete_option( 'smartcloud_composer_blueprint_keys_migrated' );
	Activation::maybe_upgrade();
	$assert( 'page' === get_post_meta( $working_blueprint->ID, '_smartcloud_composer_entity_key', true ), 'The upgrade must persist the canonical page type for pre-migration blueprints.' );
	$active_source = new ActiveConfigurationSource( $repository );
	$assert( array( 'page' ) === $active_source->page_types(), 'Active blueprints must recover their canonical page types from payloads that predate canonical entity-key migration.' );
	$assert( 'page' === ( $active_source->blueprint( 'page' )['page_type'] ?? '' ), 'The canonical page type must resolve its active blueprint.' );

	$rollback_validation = $validator->validate( $set_a );
	$rollback = $activator->activate( $set_a, $rollback_validation['receipt'], 'rollback' );
	$assert( 'rollback' === $rollback['operation'], 'Rollback must be distinguished in the result and audit event.' );
	$assert( $set_a === get_option( 'smartcloud_composer_active_config_set' ), 'Rollback must restore Set A.' );

	$lock_validation = $validator->validate( $set_b );
	add_option( 'smartcloud_composer_activation_lock', array( 'token' => 'parallel-test', 'expires' => time() + 30 ), '', false );
	try {
		$activator->activate( $set_b, $lock_validation['receipt'] );
		throw new RuntimeException( 'Parallel activation must be rejected.' );
	} catch ( InvalidArgumentException $error ) {
		$assert( str_contains( $error->getMessage(), 'already in progress' ), 'Parallel activation must report the lock conflict.' );
	} finally {
		delete_option( 'smartcloud_composer_activation_lock' );
		$receipts->consume( $lock_validation['receipt'] );
	}

	$package = $exporter->export( $set_b );
	$package['package']['id'] = $set_c;
	foreach ( $package['entities'] as &$entity ) {
		if ( EntityType::CONFIG_SET === $entity['type'] ) {
			$entity['id'] = $set_c;
			$entity['payload']['config_set_id'] = $set_c;
			$entity['checksum'] = CanonicalJson::checksum( $entity['payload'] );
		}
	}
	unset( $entity );
	$package['checksums']['entities'] = CanonicalJson::checksum( $package['entities'] );
	$import = $importer->import( $package );
	$created[] = $set_c;
	$assert( false === $import['active'], 'Imported packages must remain inactive.' );
	$assert( true === $validator->validate( $set_c )['valid'], 'The checksum-preserving imported set must validate.' );

	$manager->create_entity( $set_c, EntityType::COMPONENT, 'temporary-delete-check', array( 'schema_version' => '1.0', 'entity_type' => 'component', 'key' => 'temporary-delete-check', 'label' => 'Temporary delete check' ) );
	$delete_post = $repository->find_entity( $set_c, EntityType::COMPONENT, 'temporary-delete-check' );
	$assert( $delete_post instanceof WP_Post, 'The disposable entity must exist before deletion.' );
	$delete_description = $repository->describe_entity( $delete_post );
	$batch_blueprint = $repository->find_entity( $set_c, EntityType::BLUEPRINT, 'page' );
	$assert( $batch_blueprint instanceof WP_Post, 'The batch update blueprint must exist.' );
	$batch_description = $repository->describe_entity( $batch_blueprint );
	$batch_payload = $batch_description['payload'];
	$batch_payload['purpose'] = 'Atomic changeset verification';
	$config_before = $repository->describe_config_set( $set_c );
	$changeset_events_before = count( array_filter( $audit->events( 200 ), static fn( array $event ): bool => 'config-changeset-applied' === $event['event_type'] ) );
	$changeset = $manager->apply_changes(
		$set_c,
		array(
			array( 'action' => 'update', 'type' => EntityType::BLUEPRINT, 'key' => 'page', 'payload' => $batch_payload, 'entity_revision' => $batch_description['entity_revision'], 'content_hash' => $batch_description['content_hash'] ),
			array( 'action' => 'create', 'type' => EntityType::COMPONENT, 'key' => 'changeset-created', 'payload' => array( 'schema_version' => '1.0', 'entity_type' => 'component', 'key' => 'changeset-created', 'label' => 'Changeset created' ) ),
			array( 'action' => 'delete', 'type' => EntityType::COMPONENT, 'key' => 'temporary-delete-check', 'entity_revision' => $delete_description['entity_revision'], 'content_hash' => $delete_description['content_hash'] ),
		)
	);
	$assert( array( 'created' => 1, 'updated' => 1, 'deleted' => 1 ) === $changeset['summary'], 'The changeset must report one operation of each type.' );
	$assert( null === $repository->find_entity( $set_c, EntityType::COMPONENT, 'temporary-delete-check' ), 'The changeset must remove only its exact delete target.' );
	$assert( $repository->find_entity( $set_c, EntityType::COMPONENT, 'changeset-created' ) instanceof WP_Post, 'The changeset must create its requested entity.' );
	$batch_after = $repository->describe_entity( $repository->find_entity( $set_c, EntityType::BLUEPRINT, 'page' ) );
	$assert( $batch_description['entity_revision'] + 1 === $batch_after['entity_revision'], 'One applied changeset must create exactly one revision for its updated entity.' );
	$config_after = $repository->describe_config_set( $set_c );
	$assert( 'working' === $config_after['lifecycle'], 'Applying a changeset must invalidate whole-set validation once.' );
	$assert( $config_before['config_hash'] !== $config_after['config_hash'], 'Applying an entity changeset must refresh the aggregate Config Set hash.' );
	$entity_modified = array_values( array_filter( array_column( $config_after['entities'], 'modified_gmt' ) ) );
	$assert( array() !== $entity_modified, 'A Config Set with persisted entities must expose entity modification times.' );
	$assert(
		max( $entity_modified ) === $config_after['modified_gmt'],
		'The Config Set modification time must represent its most recently modified entity: ' . wp_json_encode(
			array( 'aggregate' => $config_after['modified_gmt'], 'entities' => $entity_modified )
		)
	);
	$changeset_events_after = count( array_filter( $audit->events( 200 ), static fn( array $event ): bool => 'config-changeset-applied' === $event['event_type'] ) );
	$assert( $changeset_events_before + 1 === $changeset_events_after, 'One applied changeset must append exactly one changeset audit event.' );

	$controller = new ConfigurationController();
	$permission_method = new ReflectionMethod( $controller, 'permission' );
	$permission_method->setAccessible( true );
	$permission = $permission_method->invoke( $controller, Activation::CAP_EDIT_CONFIG, true );
	$request = new WP_REST_Request( 'POST', '/smartcloud-agent-composer/v1/config-sets' );
	$missing_nonce = $permission( $request );
	$assert( is_wp_error( $missing_nonce ) && 'smartcloud_composer_invalid_nonce' === $missing_nonce->get_error_code(), 'Mutating REST routes must reject a missing nonce.' );
	$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
	$assert( true === $permission( $request ), 'An administrator with a valid REST nonce must pass the mutation boundary.' );

	$rest_server = rest_get_server();
	do_action( 'rest_api_init', $rest_server );
	$routes = $rest_server->get_routes();
	$sets_route = $routes['/smartcloud-agent-composer/v1/config-sets'] ?? array();
	$create_endpoint = current(
		array_filter(
			$sets_route,
			static fn( array $endpoint ): bool => ! empty( $endpoint['methods']['POST'] )
		)
	);
	$assert( is_array( $create_endpoint ) && true === ( $create_endpoint['args']['label']['required'] ?? false ), 'The config-set REST schema must require a label.' );
	$set_route = $routes['/smartcloud-agent-composer/v1/config-sets/(?P<id>[a-z0-9_-]+)'] ?? array();
	$delete_set_endpoint = current( array_filter( $set_route, static fn( array $endpoint ): bool => ! empty( $endpoint['methods']['DELETE'] ) ) );
	$assert( is_array( $delete_set_endpoint ) && true === ( $delete_set_endpoint['args']['confirmation']['required'] ?? false ) && true === ( $delete_set_endpoint['args']['config_hash']['required'] ?? false ), 'Complete Config Set deletion must require an exact ID confirmation and optimistic hash.' );

	$invalid_create = new WP_REST_Request( 'POST', '/smartcloud-agent-composer/v1/config-sets' );
	$invalid_create->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
	$invalid_create_response = rest_do_request( $invalid_create );
	$assert( 400 === $invalid_create_response->get_status(), 'REST schema validation must reject a missing config-set label before execution.' );

	$invalid_entity = new WP_REST_Request( 'POST', '/smartcloud-agent-composer/v1/config-sets/' . $set_c . '/entities' );
	$invalid_entity->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
	$invalid_entity->set_body_params( array( 'type' => 'unsupported-entity', 'key' => 'invalid', 'payload' => array() ) );
	$invalid_entity_response = rest_do_request( $invalid_entity );
	$assert( 400 === $invalid_entity_response->get_status(), 'REST schema validation must reject an unknown entity type before execution.' );

	$manager->create( 'C4 disposable set', $set_d );
	$created[] = $set_d;
	$set_d_description = $repository->describe_config_set( $set_d );
	$wrong_delete = new WP_REST_Request( 'DELETE', '/smartcloud-agent-composer/v1/config-sets/' . $set_d );
	$wrong_delete->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
	$wrong_delete->set_body_params( array( 'confirmation' => $set_d . '-wrong', 'config_hash' => $set_d_description['config_hash'] ) );
	$assert( 400 === rest_do_request( $wrong_delete )->get_status(), 'Config Set deletion must reject an incorrect stable-ID confirmation.' );
	$assert( ! empty( $repository->entities( $set_d ) ), 'A rejected Config Set deletion must leave every entity intact.' );
	$delete_set_request = new WP_REST_Request( 'DELETE', '/smartcloud-agent-composer/v1/config-sets/' . $set_d );
	$delete_set_request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
	$delete_set_request->set_body_params( array( 'confirmation' => $set_d, 'config_hash' => $set_d_description['config_hash'] ) );
	$delete_set_response = rest_do_request( $delete_set_request );
	$assert( 200 === $delete_set_response->get_status() && true === ( $delete_set_response->get_data()['deleted'] ?? false ), 'An exact confirmed inactive Config Set deletion must succeed.' );
	$assert( empty( $repository->entities( $set_d ) ), 'Complete Config Set deletion must remove every nested entity.' );

	$discovery_response = rest_do_request( new WP_REST_Request( 'GET', '/smartcloud-agent-composer/v1/discovery' ) );
	$assert( 200 === $discovery_response->get_status(), 'Theme and provider discovery must be readable.' );
	$discovery_data = $discovery_response->get_data();
	$assert( isset( $discovery_data['registered_blocks'] ) && is_array( $discovery_data['registered_blocks'] ), 'Discovery must expose the registered block inventory.' );
	$assert( isset( $discovery_data['registered_patterns'] ) && is_array( $discovery_data['registered_patterns'] ), 'Discovery must expose the registered pattern inventory.' );
	$assert( isset( $discovery_data['registered_templates'] ) && is_array( $discovery_data['registered_templates'] ), 'Discovery must expose the registered block-template inventory.' );

	$presets_response = rest_do_request( new WP_REST_Request( 'GET', '/smartcloud-agent-composer/v1/presets' ) );
	$assert( 200 === $presets_response->get_status(), 'The public preset catalogue must be readable.' );
	$presets_data = $presets_response->get_data();
	$assert( 3 === count( $presets_data['items'] ?? array() ), 'The public catalogue must expose exactly the three vendor-neutral entry paths.' );
	$assert(
		array( 'universal-gutenberg', 'smartcloud-recommended', 'detected-theme-starter' ) === array_column( $presets_data['items'], 'id' ),
		'The preset catalogue must not mix the private WP Suite reference preset into public onboarding.'
	);
	$preset_request = new WP_REST_Request( 'POST', '/smartcloud-agent-composer/v1/presets/universal-gutenberg/instantiate' );
	$preset_request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
	$preset_request->set_body_params( array( 'label' => 'Universal smoke preset' ) );
	$preset_response = rest_do_request( $preset_request );
	$assert( 201 === $preset_response->get_status(), 'A public preset must instantiate through the governed mutation route.' );
	$preset_data = $preset_response->get_data();
	$preset_set = (string) ( $preset_data['config_set']['config_set'] ?? '' );
	$assert( '' !== $preset_set, 'Preset instantiation must return its new Config Set.' );
	$created[] = $preset_set;
	$assert( false === ( $preset_data['active'] ?? true ), 'Preset instantiation must never activate configuration automatically.' );
	$assert( 'working' === ( $preset_data['config_set']['lifecycle'] ?? '' ), 'A selected preset must begin as a working Config Set.' );
	$preset_manifest = $repository->find_entity( $preset_set, EntityType::CONFIG_SET, $preset_set );
	$assert( $preset_manifest instanceof WP_Post, 'The instantiated preset manifest must exist.' );
	$preset_manifest_data = $repository->describe_entity( $preset_manifest );
	$assert( 'universal-gutenberg' === ( $preset_manifest_data['payload']['preset']['id'] ?? '' ), 'The working copy must preserve its preset origin metadata.' );
	$assert( true === $validator->validate( $preset_set )['valid'], 'The built-in Universal Gutenberg working set must pass lifecycle validation.' );

	$agent_role = get_role( Activation::ROLE );
	$assert( $agent_role instanceof WP_Role, 'The dedicated agent role must exist.' );
	foreach ( array( 'publish_pages', 'delete_pages', 'manage_options', 'install_plugins', 'upload_files', 'unfiltered_html' ) as $forbidden_capability ) {
		$assert( ! $agent_role->has_cap( $forbidden_capability ), 'The agent role must not have ' . $forbidden_capability . '.' );
	}
	$assert( $agent_role->has_cap( Activation::CAP_EXECUTE_DRAFTS ), 'The agent role must have the draft execution capability.' );
	$assert( $agent_role->has_cap( Activation::CAP_INGEST_MEDIA ), 'The agent role must have only the governed Composer media-ingest capability.' );
	$assert( ! $agent_role->has_cap( Activation::CAP_ACTIVATE_CONFIG ), 'The agent role must not activate configuration.' );

	$runtime_ability = wp_get_ability( 'smartcloud-agent-composer/get-runtime-capabilities' );
	$assert( is_object( $runtime_ability ), 'The runtime-capabilities ability must be registered.' );
	$runtime_capabilities = $runtime_ability->execute( array() );
	$assert( ! is_wp_error( $runtime_capabilities ), 'The runtime-capabilities ability must execute without an internal error.' );
	$assert( 'SmartCloud Agent Composer' === ( $runtime_capabilities['composer']['name'] ?? '' ), 'Runtime capabilities must identify Composer.' );
	$assert( 'smartcloud-agent-composer' === ( $runtime_capabilities['mcp']['server_id'] ?? '' ), 'Runtime capabilities must expose the canonical Composer MCP server ID.' );
	$assert( '/wp-json/mcp/smartcloud-agent-composer' === ( $runtime_capabilities['mcp']['endpoint'] ?? '' ), 'Runtime capabilities must expose the canonical Composer MCP endpoint.' );

	$backup_bundle = $backup->export_all();
	$backup_bundle['packages'] = array_values(
		array_filter(
			$backup_bundle['packages'],
			static fn( array $item ): bool => in_array( (string) $item['package']['id'], array( $set_a, $set_b, $set_c ), true )
		)
	);
	$backup_bundle['checksums']['packages'] = CanonicalJson::checksum( $backup_bundle['packages'] );
	$assert( 3 === count( $backup_bundle['packages'] ), 'The complete backup must contain every smoke-test config set.' );
	delete_option( 'smartcloud_composer_active_config_set' );
	delete_option( 'smartcloud_composer_previous_config_set' );
	foreach ( array( $set_a, $set_b, $set_c ) as $config_set ) {
		foreach ( $repository->entities( $config_set ) as $post ) {
			wp_delete_post( $post->ID, true );
		}
	}
	$restore = $backup->import_all( $backup_bundle );
	$assert( false === $restore['active'], 'A complete restore must remain inactive.' );
	$assert( 3 === count( $restore['config_sets'] ), 'A complete restore must recreate every config set.' );
	foreach ( $restore['config_sets'] as $config_set ) {
		$assert( 'working' === $repository->describe_config_set( $config_set )['lifecycle'], 'Every restored config set must be a working set.' );
	}
	$before_failed_restore = count( $repository->list_config_sets() );
	$invalid_backup = $backup_bundle;
	$invalid_backup['checksums']['packages'] = 'sha256:' . str_repeat( '0', 64 );
	try {
		$backup->import_all( $invalid_backup );
		throw new RuntimeException( 'A corrupted complete backup must be rejected.' );
	} catch ( InvalidArgumentException ) {
		// Expected.
	}
	$assert( $before_failed_restore === count( $repository->list_config_sets() ), 'A rejected backup must not create partial configuration.' );

	$reset_validation = $validator->validate( $set_a );
	$assert( true === $reset_validation['valid'], 'The restored Config Set must validate before the maintenance lifecycle test.' );
	$activator->activate( $set_a, $reset_validation['receipt'] );
	$active_for_reset = $repository->describe_config_set( $set_a );
	try {
		$manager->delete( $set_a, $set_a, $active_for_reset['config_hash'] );
		throw new RuntimeException( 'An active Config Set must never be deleted directly.' );
	} catch ( InvalidArgumentException $error ) {
		$assert( str_contains( $error->getMessage(), 'Deactivate' ), 'Direct active deletion must explain the required deactivation step.' );
	}
	try {
		$activator->deactivate( $set_a, $set_a . '-wrong', $active_for_reset['config_hash'] );
		throw new RuntimeException( 'Config Set deactivation must require its complete stable ID.' );
	} catch ( InvalidArgumentException ) {
		// Expected.
	}
	$deactivated = $activator->deactivate( $set_a, $set_a, $active_for_reset['config_hash'] );
	$assert( '' === ( $deactivated['active'] ?? 'unexpected' ) && '' === (string) get_option( 'smartcloud_composer_active_config_set', '' ), 'Explicit deactivation must leave Composer without an active Config Set.' );
	$assert( 'archived' === $repository->describe_config_set( $set_a )['lifecycle'], 'The deactivated Config Set must remain inspectable as archived until deletion.' );
	$deleted_after_deactivation = $manager->delete( $set_a, $set_a, $active_for_reset['config_hash'] );
	$assert( true === $deleted_after_deactivation['deleted'] && empty( $repository->entities( $set_a ) ), 'A deactivated Config Set must be completely deletable with the reviewed hash.' );

	$events = $audit->events( 20 );
	$assert( ! empty( $events ), 'Lifecycle operations must append audit events.' );
	foreach ( $events as $event ) {
		$assert( 64 === strlen( $event['event_hash'] ), 'Every audit event must have a SHA-256 chain hash.' );
	}

	$summary = array(
		'plugin_version'          => SMARTCLOUD_COMPOSER_VERSION,
		'wordpress_version'       => get_bloginfo( 'version' ),
		'php_version'             => PHP_VERSION,
		'multisite'               => is_multisite(),
		'config_lifecycle'        => true,
		'optimistic_conflict'     => true,
		'confirmed_delete'        => true,
		'complete_set_delete'     => true,
		'explicit_deactivation'   => true,
		'activation_lock'         => true,
		'rollback'                => true,
		'package_roundtrip'       => true,
		'canonical_active_keys'   => true,
		'blueprint_key_migration' => true,
		'backup_roundtrip'        => true,
		'backup_atomicity'        => true,
		'capability_boundary'     => true,
		'nonce_boundary'          => true,
		'rest_schema_boundary'    => true,
		'theme_capability_inventory' => true,
		'runtime_capabilities'    => true,
		'audit_chain_present'     => true,
	);
} finally {
	$cleanup();
}

echo wp_json_encode( $summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . PHP_EOL;
