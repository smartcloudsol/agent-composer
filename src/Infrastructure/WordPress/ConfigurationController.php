<?php

namespace SmartCloud\AgentComposer\Infrastructure\WordPress;

use InvalidArgumentException;
use Throwable;
use SmartCloud\AgentComposer\Application\Configuration\ConfigBackupService;
use SmartCloud\AgentComposer\Application\Configuration\ConfigPackageExporter;
use SmartCloud\AgentComposer\Application\Configuration\ConfigPackageImporter;
use SmartCloud\AgentComposer\Application\Configuration\ConfigSetActivator;
use SmartCloud\AgentComposer\Application\Configuration\ConfigSetDiffer;
use SmartCloud\AgentComposer\Application\Configuration\ConfigSetManager;
use SmartCloud\AgentComposer\Application\Configuration\ConfigSetValidator;
use SmartCloud\AgentComposer\Application\Configuration\SiteDiscoveryService;
use SmartCloud\AgentComposer\Application\Configuration\PresetService;
use SmartCloud\AgentComposer\Application\Configuration\ValidationReceiptService;
use SmartCloud\AgentComposer\Domain\Configuration\ConfigurationConflict;
use SmartCloud\AgentComposer\Domain\Configuration\EntityType;
use SmartCloud\AgentComposer\Execution\Ability_Provider_Registry;
use SmartCloud\AgentComposer\Infrastructure\Persistence\AuditTable;
use SmartCloud\AgentComposer\Infrastructure\Persistence\WordPressConfigurationRepository;
use SmartCloud\AgentComposer\Integration\Providers\ProviderRegistry;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

final class ConfigurationController {
	private readonly WordPressConfigurationRepository $repository;
	private readonly AuditTable $audit;
	private readonly ConfigSetManager $manager;
	private readonly ConfigSetValidator $validator;
	private readonly ConfigSetActivator $activator;
	private readonly ConfigSetDiffer $differ;
	private readonly ConfigPackageImporter $importer;
	private readonly ConfigPackageExporter $exporter;
	private readonly ConfigBackupService $backup;
	private readonly SiteDiscoveryService $discovery;
	private readonly PresetService $presets;

	public function __construct() {
		$this->repository = new WordPressConfigurationRepository();
		$this->audit      = new AuditTable();
		$receipts         = new ValidationReceiptService();
		$profiles         = new ProviderRegistry();
		$execution        = new Ability_Provider_Registry();
		$this->manager    = new ConfigSetManager( $this->repository, $this->audit );
		$this->validator  = new ConfigSetValidator( $this->repository, $receipts, $profiles, $this->audit );
		$this->activator  = new ConfigSetActivator( $this->repository, $receipts, $this->audit );
		$this->differ     = new ConfigSetDiffer( $this->repository );
		$this->importer   = new ConfigPackageImporter( $this->repository, $this->audit );
		$this->exporter   = new ConfigPackageExporter( $this->repository, $this->audit );
		$this->backup     = new ConfigBackupService( $this->repository, $this->exporter, $this->importer, $this->audit );
		$this->discovery  = new SiteDiscoveryService( $this->repository, $profiles, $execution, $this->audit );
		$this->presets    = new PresetService( $this->repository, $this->importer, $this->discovery, $this->audit );
	}

	public function register_routes(): void {
		$namespace = StatusController::NAMESPACE;
		register_rest_route( $namespace, '/config-sets', array(
			array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( $this, 'list_sets' ), 'permission_callback' => $this->permission( Activation::CAP_VIEW_STATUS ) ),
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_set' ),
				'permission_callback' => $this->permission( Activation::CAP_EDIT_CONFIG, true ),
				'args'                => array(
					'label' => array( 'type' => 'string', 'required' => true, 'minLength' => 1, 'maxLength' => 160, 'sanitize_callback' => 'sanitize_text_field' ),
					'id'    => self::config_set_id_argument(),
				),
			),
		) );
		register_rest_route( $namespace, '/config-sets/(?P<id>[a-z0-9_-]+)', array(
			'methods' => WP_REST_Server::READABLE,
			'callback' => array( $this, 'get_set' ),
			'permission_callback' => $this->permission( Activation::CAP_VIEW_STATUS ),
		) );
		register_rest_route( $namespace, '/config-sets/(?P<id>[a-z0-9_-]+)/clone', array(
			'methods' => WP_REST_Server::CREATABLE,
			'callback' => array( $this, 'clone_set' ),
			'permission_callback' => $this->permission( Activation::CAP_EDIT_CONFIG, true ),
			'args' => array(
				'label'     => array( 'type' => 'string', 'maxLength' => 160, 'sanitize_callback' => 'sanitize_text_field' ),
				'target_id' => self::config_set_id_argument(),
			),
		) );
		register_rest_route( $namespace, '/config-sets/(?P<id>[a-z0-9_-]+)/entities', array(
			'methods' => WP_REST_Server::CREATABLE,
			'callback' => array( $this, 'create_entity' ),
			'permission_callback' => $this->permission( Activation::CAP_EDIT_CONFIG, true ),
			'args' => array(
				'type'    => array( 'type' => 'string', 'required' => true, 'enum' => EntityType::all(), 'sanitize_callback' => 'sanitize_key' ),
				'key'     => self::entity_key_argument( true ),
				'payload' => array( 'type' => 'object', 'required' => true ),
			),
		) );
		register_rest_route( $namespace, '/config-sets/(?P<id>[a-z0-9_-]+)/changes', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'apply_changes' ),
			'permission_callback' => $this->permission( Activation::CAP_EDIT_CONFIG, true ),
			'args'                => array(
				'changes' => array( 'type' => 'array', 'required' => true, 'minItems' => 1, 'maxItems' => 100, 'items' => array( 'type' => 'object' ) ),
			),
		) );
		register_rest_route( $namespace, '/config-sets/(?P<id>[a-z0-9_-]+)/entities/(?P<key>[a-zA-Z0-9._:-]+)', array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_entity' ),
				'permission_callback' => $this->permission( Activation::CAP_VIEW_STATUS ),
				'args'                => array(
					'type' => array( 'type' => 'string', 'required' => true, 'enum' => EntityType::all(), 'sanitize_callback' => 'sanitize_key' ),
				),
			),
			array(
				'methods'             => 'PATCH',
				'callback'            => array( $this, 'update_entity' ),
				'permission_callback' => $this->permission( Activation::CAP_EDIT_CONFIG, true ),
				'args'                => array(
					'type'            => array( 'type' => 'string', 'required' => true, 'enum' => EntityType::all(), 'sanitize_callback' => 'sanitize_key' ),
					'entity_revision' => array( 'type' => 'integer', 'required' => true, 'minimum' => 1 ),
					'payload'         => array( 'type' => 'object', 'required' => true ),
				),
			),
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'delete_entity' ),
				'permission_callback' => $this->permission( Activation::CAP_EDIT_CONFIG, true ),
				'args'                => array(
					'type'            => array( 'type' => 'string', 'required' => true, 'enum' => EntityType::all(), 'sanitize_callback' => 'sanitize_key' ),
					'entity_revision' => array( 'type' => 'integer', 'required' => true, 'minimum' => 1 ),
				),
			),
		) );
		foreach ( array( 'validate', 'activate', 'rollback', 'export' ) as $operation ) {
			$capability = match ( $operation ) {
				'validate' => Activation::CAP_VALIDATE_CONFIG,
				'activate' => Activation::CAP_ACTIVATE_CONFIG,
				'rollback' => Activation::CAP_ROLLBACK_CONFIG,
				default    => Activation::CAP_EDIT_CONFIG,
			};
			register_rest_route( $namespace, '/config-sets/(?P<id>[a-z0-9_-]+)/' . $operation, array(
				'methods' => WP_REST_Server::CREATABLE,
				'callback' => array( $this, $operation . '_set' ),
				'permission_callback' => $this->permission( $capability, true ),
			) );
		}
		register_rest_route( $namespace, '/config-sets/(?P<id>[a-z0-9_-]+)/diff', array(
			'methods' => WP_REST_Server::READABLE,
			'callback' => array( $this, 'diff_set' ),
			'permission_callback' => $this->permission( Activation::CAP_VIEW_STATUS ),
			'args' => array( 'to' => self::config_set_id_argument() ),
		) );
		register_rest_route( $namespace, '/imports', array(
			'methods' => WP_REST_Server::CREATABLE,
			'callback' => array( $this, 'import_package' ),
			'permission_callback' => $this->permission( Activation::CAP_EDIT_CONFIG, true ),
		) );
		register_rest_route( $namespace, '/backup', array(
			'methods' => WP_REST_Server::CREATABLE,
			'callback' => array( $this, 'export_backup' ),
			'permission_callback' => $this->permission( Activation::CAP_EDIT_CONFIG, true ),
		) );
		register_rest_route( $namespace, '/audit', array(
			'methods' => WP_REST_Server::READABLE,
			'callback' => array( $this, 'audit' ),
			'permission_callback' => $this->permission( Activation::CAP_VIEW_AUDIT ),
			'args' => array(
				'limit'  => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 50 ),
				'offset' => array( 'type' => 'integer', 'minimum' => 0, 'default' => 0 ),
			),
		) );
		register_rest_route( $namespace, '/discovery', array(
			array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( $this, 'get_discovery' ), 'permission_callback' => $this->permission( Activation::CAP_VIEW_STATUS ) ),
			array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( $this, 'run_discovery' ), 'permission_callback' => $this->permission( Activation::CAP_VALIDATE_CONFIG, true ) ),
		) );
		register_rest_route( $namespace, '/presets', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( $this, 'list_presets' ),
			'permission_callback' => $this->permission( Activation::CAP_VIEW_STATUS ),
		) );
		register_rest_route( $namespace, '/presets/(?P<preset>[a-z0-9_-]+)/instantiate', array(
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'instantiate_preset' ),
			'permission_callback' => $this->permission( Activation::CAP_EDIT_CONFIG, true ),
			'args'                => array(
				'label' => array( 'type' => 'string', 'maxLength' => 160, 'sanitize_callback' => 'sanitize_text_field' ),
			),
		) );
	}

	public function list_sets(): WP_REST_Response|WP_Error {
		return $this->respond( fn(): array => array( 'items' => $this->repository->list_config_sets(), 'active' => (string) get_option( 'smartcloud_composer_active_config_set', '' ) ) );
	}

	public function get_set( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->respond( fn(): array => $this->repository->describe_config_set( (string) $request['id'] ) );
	}

	public function create_set( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->respond( fn(): array => $this->manager->create( (string) $request->get_param( 'label' ), (string) $request->get_param( 'id' ) ), 201 );
	}

	public function clone_set( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->respond( fn(): array => $this->manager->clone( (string) $request['id'], (string) $request->get_param( 'label' ), (string) $request->get_param( 'target_id' ) ), 201 );
	}

	public function create_entity( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->respond( fn(): array => $this->manager->create_entity( (string) $request['id'], (string) $request->get_param( 'type' ), (string) $request->get_param( 'key' ), (array) $request->get_param( 'payload' ) ), 201 );
	}

	public function apply_changes( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->respond( fn(): array => $this->manager->apply_changes( (string) $request['id'], (array) $request->get_param( 'changes' ) ) );
	}

	public function get_entity( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->respond( function () use ( $request ): array {
			$post = $this->repository->find_entity( (string) $request['id'], (string) $request->get_param( 'type' ), (string) $request['key'] );
			if ( ! $post instanceof \WP_Post ) {
				throw new InvalidArgumentException( 'The requested configuration entity does not exist.' );
			}
			return $this->repository->describe_entity( $post );
		} );
	}

	public function update_entity( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->respond( function () use ( $request ): array {
			$post = $this->repository->find_entity( (string) $request['id'], (string) $request->get_param( 'type' ), (string) $request['key'] );
			if ( ! $post instanceof \WP_Post ) {
				throw new InvalidArgumentException( 'The requested configuration entity does not exist.' );
			}
			$updated = $this->repository->update_working_entity( $post->ID, (array) $request->get_param( 'payload' ), absint( $request->get_param( 'entity_revision' ) ), (string) $request->get_header( 'if-match' ) );
			$this->audit->record( 'config-entity-updated', 'success', array( 'config_set' => $request['id'], 'entity_type' => $updated['type'], 'entity_key' => $updated['key'], 'content_hash' => $updated['content_hash'] ), 0, $post->ID );
			return $updated;
		} );
	}

	public function delete_entity( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->respond( function () use ( $request ): array {
			$post = $this->repository->find_entity( (string) $request['id'], (string) $request->get_param( 'type' ), (string) $request['key'] );
			if ( ! $post instanceof \WP_Post ) {
				throw new InvalidArgumentException( 'The requested configuration entity does not exist.' );
			}
			$deleted = $this->repository->delete_working_entity( $post->ID, absint( $request->get_param( 'entity_revision' ) ), (string) $request->get_header( 'if-match' ) );
			$this->audit->record( 'config-entity-deleted', 'success', $deleted, 0, $post->ID );
			return array( 'deleted' => true ) + $deleted;
		} );
	}

	public function validate_set( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->respond( fn(): array => $this->validator->validate( (string) $request['id'] ) );
	}

	public function activate_set( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->respond( function () use ( $request ): array {
			$validation = $this->validator->validate( (string) $request['id'] );
			if ( ! $validation['valid'] ) {
				throw new InvalidArgumentException( 'The configuration set failed activation-time validation.' );
			}
			return $this->activator->activate( (string) $request['id'], $validation['receipt'] );
		} );
	}

	public function rollback_set( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->respond( function () use ( $request ): array {
			$validation = $this->validator->validate( (string) $request['id'] );
			if ( ! $validation['valid'] ) {
				throw new InvalidArgumentException( 'The rollback target is no longer compatible with the current site.' );
			}
			return $this->activator->activate( (string) $request['id'], $validation['receipt'], 'rollback' );
		} );
	}

	public function diff_set( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->respond( function () use ( $request ): array {
			$to = sanitize_key( (string) $request->get_param( 'to' ) );
			$to = $to ?: (string) get_option( 'smartcloud_composer_active_config_set', '' );
			if ( '' === $to ) {
				throw new InvalidArgumentException( 'Select a comparison configuration set.' );
			}
			return $this->differ->diff( (string) $request['id'], $to );
		} );
	}

	public function export_set( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->respond( fn(): array => $this->exporter->export( (string) $request['id'] ) );
	}

	public function import_package( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->respond( function () use ( $request ): array {
			$body = $request->get_json_params();
			if ( ! is_array( $body ) || strlen( (string) $request->get_body() ) > 8388608 ) {
				throw new InvalidArgumentException( 'The import package is invalid or exceeds the 8 MB limit.' );
			}
			return ConfigBackupService::is_backup( $body ) ? $this->backup->import_all( $body ) : $this->importer->import( $body );
		}, 201 );
	}

	public function export_backup(): WP_REST_Response|WP_Error {
		return $this->respond( fn(): array => $this->backup->export_all() );
	}

	public function audit( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->respond( fn(): array => array( 'items' => $this->audit->events( absint( $request->get_param( 'limit' ) ) ?: 50, absint( $request->get_param( 'offset' ) ) ) ) );
	}

	public function get_discovery(): WP_REST_Response|WP_Error {
		return $this->respond( fn(): array => $this->discovery->discover( false ) );
	}

	public function run_discovery(): WP_REST_Response|WP_Error {
		return $this->respond( fn(): array => $this->discovery->discover( true ), 201 );
	}

	public function list_presets(): WP_REST_Response|WP_Error {
		return $this->respond( fn(): array => $this->presets->catalogue() );
	}

	public function instantiate_preset( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->respond( fn(): array => $this->presets->instantiate( (string) $request['preset'], (string) $request->get_param( 'label' ) ), 201 );
	}

	private function permission( string $capability, bool $mutation = false ): callable {
		return static function ( WP_REST_Request $request ) use ( $capability, $mutation ): bool|WP_Error {
			if ( ! current_user_can( $capability ) ) {
				return new WP_Error( 'smartcloud_composer_forbidden', 'You do not have permission for this Composer operation.', array( 'status' => rest_authorization_required_code() ) );
			}
			if ( $mutation && ! wp_verify_nonce( (string) $request->get_header( 'x_wp_nonce' ), 'wp_rest' ) ) {
				return new WP_Error( 'smartcloud_composer_invalid_nonce', 'A valid WordPress REST nonce is required.', array( 'status' => 403 ) );
			}
			return true;
		};
	}

	private function respond( callable $callback, int $status = 200 ): WP_REST_Response|WP_Error {
		try {
			return new WP_REST_Response( $callback(), $status );
		} catch ( ConfigurationConflict $error ) {
			return new WP_Error( 'SMARTCLOUD_COMPOSER_CONFLICT', $error->getMessage(), array_merge( array( 'status' => 409 ), $error->details() ) );
		} catch ( InvalidArgumentException $error ) {
			return new WP_Error( 'smartcloud_composer_invalid_request', $error->getMessage(), array( 'status' => 400 ) );
		} catch ( Throwable $error ) {
			do_action( 'smartcloud_composer_internal_error', $error );
			return new WP_Error( 'smartcloud_composer_internal_error', 'Composer could not complete the operation.', array( 'status' => 500 ) );
		}
	}

	private static function config_set_id_argument(): array {
		return array(
			'type'              => 'string',
			'maxLength'         => 128,
			'pattern'           => '^[a-z0-9][a-z0-9_-]{0,127}$',
			'sanitize_callback' => 'sanitize_key',
		);
	}

	private static function entity_key_argument( bool $required = false ): array {
		return array(
			'type'      => 'string',
			'required'  => $required,
			'maxLength' => 128,
			'pattern'   => '^[a-zA-Z0-9][a-zA-Z0-9._:-]{0,127}$',
		);
	}
}
