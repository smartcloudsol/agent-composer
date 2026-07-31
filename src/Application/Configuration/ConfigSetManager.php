<?php

namespace SmartCloud\AgentComposer\Application\Configuration;

use InvalidArgumentException;
use RuntimeException;
use SmartCloud\AgentComposer\Domain\Configuration\EntityType;
use SmartCloud\AgentComposer\Infrastructure\Persistence\AuditTable;
use SmartCloud\AgentComposer\Infrastructure\Persistence\WordPressConfigurationRepository;

final class ConfigSetManager {
	private const LOCK_OPTION = 'smartcloud_composer_activation_lock';

	public function __construct(
		private readonly WordPressConfigurationRepository $repository,
		private readonly AuditTable $audit
	) {}

	public function create( string $label, string $requested_id = '' ): array {
		$label = sanitize_text_field( $label );
		if ( '' === $label ) {
			throw new InvalidArgumentException( 'A configuration set label is required.' );
		}
		$config_set = sanitize_key( $requested_id );
		if ( '' === $config_set ) {
			$config_set = sanitize_key( sanitize_title( $label ) . '-' . substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 8 ) );
		}
		if ( ! empty( $this->repository->find_by_type( EntityType::CONFIG_SET, $config_set ) ) ) {
			throw new InvalidArgumentException( 'A configuration set with this identifier already exists.' );
		}
		$this->repository->save_working_entity(
			EntityType::CONFIG_SET,
			$config_set,
			array( 'schema_version' => '1.0', 'config_set_id' => $config_set, 'label' => $label, 'mode' => 'universal-gutenberg', 'fallback_policy' => 'strict', 'entities' => array() ),
			$config_set
		);
		$this->repository->save_working_entity(
			EntityType::SITE_CONTRACT,
			'contract:site',
			array( 'schema_version' => '1.0', 'entity_type' => 'site-contract', 'key' => 'site', 'label' => $label . ' site contract', 'content' => array(), 'seo' => array(), 'media' => array(), 'accessibility' => array(), 'security' => array(), 'lifecycle' => array() ),
			$config_set
		);
		$this->audit->record( 'config-set-created', 'success', array( 'config_set' => $config_set, 'label' => $label ) );
		return $this->repository->describe_config_set( $config_set );
	}

	public function clone( string $source, string $label, string $requested_id = '' ): array {
		$source_set = $this->repository->describe_config_set( $source );
		$label      = sanitize_text_field( $label ?: $source_set['label'] . ' copy' );
		$target     = sanitize_key( $requested_id );
		if ( '' === $target ) {
			$target = sanitize_key( sanitize_title( $label ) . '-' . substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 8 ) );
		}
		if ( ! empty( $this->repository->find_by_type( EntityType::CONFIG_SET, $target ) ) ) {
			throw new InvalidArgumentException( 'The clone target already exists.' );
		}

		$created = array();
		try {
			foreach ( $source_set['entities'] as $entity ) {
				$payload = $entity['payload'];
				$key     = $entity['key'];
				if ( EntityType::CONFIG_SET === $entity['type'] ) {
					$key                      = $target;
					$payload['config_set_id'] = $target;
					$payload['label']         = $label;
					$payload['created_from_config_hash'] = $source_set['config_hash'];
				}
				$created[] = $this->repository->save_working_entity( $entity['type'], $key, $payload, $target );
			}
		} catch ( \Throwable $error ) {
			foreach ( array_reverse( $created ) as $post_id ) {
				wp_delete_post( $post_id, true );
			}
			throw $error;
		}
		$this->audit->record( 'config-set-cloned', 'success', array( 'source' => $source, 'target' => $target, 'entity_count' => count( $created ) ) );
		return $this->repository->describe_config_set( $target );
	}

	/** @return array{deleted:true,config_set:string,entity_count:int,config_hash:string} */
	public function delete( string $config_set, string $confirmation, string $expected_hash ): array {
		$config_set = sanitize_key( $config_set );
		if ( '' === $config_set || ! hash_equals( $config_set, $confirmation ) ) {
			throw new InvalidArgumentException( 'Type the complete Config Set ID to confirm permanent deletion.' );
		}
		if ( $config_set === (string) get_option( 'smartcloud_composer_active_config_set', '' ) ) {
			throw new InvalidArgumentException( 'Deactivate the active Config Set before deleting it.' );
		}
		if ( ! preg_match( '/^sha256:[a-f0-9]{64}$/', $expected_hash ) ) {
			throw new InvalidArgumentException( 'A valid Config Set hash is required for deletion.' );
		}
		$lock = $this->acquire_lifecycle_lock();
		try {
			return $this->delete_locked( $config_set, $expected_hash );
		} finally {
			$this->release_lifecycle_lock( $lock );
		}
	}

	/** @return array{deleted:true,config_set:string,entity_count:int,config_hash:string} */
	private function delete_locked( string $config_set, string $expected_hash ): array {
		if ( $config_set === (string) get_option( 'smartcloud_composer_active_config_set', '' ) ) {
			throw new InvalidArgumentException( 'The Config Set became active while deletion was being confirmed. Deactivate it and review the deletion again.' );
		}

		global $wpdb;
		$deleted_ids = array();
		$config_post_id = 0;
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Complete Config Set deletion must be atomic.
			throw new RuntimeException( 'Composer could not start the Config Set deletion transaction.' );
		}
		try {
			$entities = $this->repository->entities( $config_set );
			if ( empty( $entities ) ) {
				throw new InvalidArgumentException( 'The requested Config Set does not exist.' );
			}
			foreach ( $entities as $entity ) {
				$locked = $this->repository->lock_working_entity( $entity->ID );
				$deleted_ids[] = $locked->ID;
				if ( EntityType::CONFIG_SET === (string) get_post_meta( $locked->ID, '_smartcloud_composer_entity_type', true ) ) {
					$config_post_id = $locked->ID;
				}
			}
			$current_hash = $this->repository->configuration_hash( $config_set );
			if ( ! hash_equals( $expected_hash, $current_hash ) ) {
				throw new InvalidArgumentException( 'The Config Set changed after the deletion dialog opened. Review it again.' );
			}
			foreach ( array_reverse( $entities ) as $entity ) {
				if ( ! wp_delete_post( $entity->ID, true ) instanceof \WP_Post ) {
					throw new RuntimeException( 'Composer could not delete every Config Set entity.' );
				}
			}
			if ( false === $wpdb->query( 'COMMIT' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Completes the atomic Config Set deletion.
				throw new RuntimeException( 'Composer could not commit the Config Set deletion.' );
			}
		} catch ( \Throwable $error ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Prevents partial Config Set deletion.
			foreach ( $deleted_ids as $post_id ) {
				clean_post_cache( $post_id );
			}
			throw $error;
		}

		foreach ( $deleted_ids as $post_id ) {
			clean_post_cache( $post_id );
		}
		if ( $config_set === (string) get_option( 'smartcloud_composer_previous_config_set', '' ) ) {
			delete_option( 'smartcloud_composer_previous_config_set' );
		}
		$result = array( 'deleted' => true, 'config_set' => $config_set, 'entity_count' => count( $deleted_ids ), 'config_hash' => $expected_hash );
		$this->audit->record( 'config-set-deleted', 'success', $result, $config_post_id );
		return $result;
	}

	private function acquire_lifecycle_lock(): string {
		$token   = wp_generate_uuid4();
		$payload = array( 'token' => $token, 'expires' => time() + 30 );
		if ( add_option( self::LOCK_OPTION, $payload, '', false ) ) {
			return $token;
		}
		$current = get_option( self::LOCK_OPTION, array() );
		if ( is_array( $current ) && (int) ( $current['expires'] ?? 0 ) < time() ) {
			delete_option( self::LOCK_OPTION );
			if ( add_option( self::LOCK_OPTION, $payload, '', false ) ) {
				return $token;
			}
		}
		throw new InvalidArgumentException( 'Another configuration lifecycle operation is already in progress.' );
	}

	private function release_lifecycle_lock( string $token ): void {
		$current = get_option( self::LOCK_OPTION, array() );
		if ( is_array( $current ) && hash_equals( $token, (string) ( $current['token'] ?? '' ) ) ) {
			delete_option( self::LOCK_OPTION );
		}
	}

	public function create_entity( string $config_set, string $type, string $key, array $payload ): array {
		if ( $config_set === (string) get_option( 'smartcloud_composer_active_config_set', '' ) ) {
			throw new InvalidArgumentException( 'Active configuration is immutable. Clone it before editing.' );
		}
		$post_id = $this->repository->save_working_entity( EntityType::assert( $type ), $key, $payload, $config_set );
		$this->repository->invalidate_validation( $config_set );
		$this->audit->record( 'config-entity-created', 'success', array( 'config_set' => $config_set, 'entity_type' => $type, 'entity_key' => $key ), 0, $post_id );
		return $this->repository->describe_entity( get_post( $post_id ) );
	}

	public function apply_changes( string $config_set, array $changes ): array {
		if ( $config_set === (string) get_option( 'smartcloud_composer_active_config_set', '' ) ) {
			throw new InvalidArgumentException( 'Active configuration is immutable. Clone it before editing.' );
		}
		if ( empty( $changes ) || count( $changes ) > 100 || ! array_is_list( $changes ) ) {
			throw new InvalidArgumentException( 'A changeset must contain between 1 and 100 operations.' );
		}

		global $wpdb;
		$affected  = array();
		$counts    = array( 'created' => 0, 'updated' => 0, 'deleted' => 0 );
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- The changeset must commit or roll back as one configuration operation.
			throw new RuntimeException( 'Composer could not start the configuration changeset transaction.' );
		}
		try {
			$config_entity = $this->repository->find_entity( $config_set, EntityType::CONFIG_SET, $config_set );
			if ( ! $config_entity instanceof \WP_Post ) {
				throw new InvalidArgumentException( 'The requested configuration set does not exist.' );
			}
			$this->repository->lock_working_entity( $config_entity->ID );

			$prepared   = array();
			$identities = array();
			foreach ( $changes as $change ) {
				if ( ! is_array( $change ) ) {
					throw new InvalidArgumentException( 'Every changeset operation must be an object.' );
				}
				$action   = sanitize_key( (string) ( $change['action'] ?? '' ) );
				$type     = EntityType::assert( sanitize_key( (string) ( $change['type'] ?? '' ) ) );
				$key      = strtolower( trim( (string) ( $change['key'] ?? '' ) ) );
				$identity = $type . ':' . $key;
				if ( ! in_array( $action, array( 'create', 'update', 'delete' ), true ) || isset( $identities[ $identity ] ) ) {
					throw new InvalidArgumentException( 'The changeset contains an invalid or duplicate operation.' );
				}
				$identities[ $identity ] = true;
				$post = $this->repository->find_entity( $config_set, $type, $key );
				if ( 'create' === $action ) {
					if ( EntityType::CONFIG_SET === $type || $post instanceof \WP_Post || ! is_array( $change['payload'] ?? null ) ) {
						throw new InvalidArgumentException( 'The changeset create operation is invalid.' );
					}
				} else {
					if ( ! $post instanceof \WP_Post || ( 'delete' === $action && EntityType::CONFIG_SET === $type ) ) {
						throw new InvalidArgumentException( 'The changeset target does not exist or cannot be deleted.' );
					}
					$post = $this->repository->lock_working_entity( $post->ID );
					if ( 'update' === $action && ! is_array( $change['payload'] ?? null ) ) {
						throw new InvalidArgumentException( 'A changeset update requires an object payload.' );
					}
					$payload = 'update' === $action && is_array( $change['payload'] ?? null ) ? $change['payload'] : null;
					$this->repository->assert_working_entity_version( $post, absint( $change['entity_revision'] ?? 0 ), (string) ( $change['content_hash'] ?? '' ), $payload );
				}
				$prepared[] = compact( 'action', 'type', 'key', 'post', 'change' );
			}

			foreach ( $prepared as $item ) {
				$change = $item['change'];
				if ( 'create' === $item['action'] ) {
					$affected[] = $this->repository->save_working_entity( $item['type'], $item['key'], (array) $change['payload'], $config_set );
					++$counts['created'];
				} elseif ( 'update' === $item['action'] ) {
					$affected[] = $item['post']->ID;
					$this->repository->update_working_entity( $item['post']->ID, (array) $change['payload'], absint( $change['entity_revision'] ), (string) $change['content_hash'], false );
					++$counts['updated'];
				} else {
					$affected[] = $item['post']->ID;
					$this->repository->delete_working_entity( $item['post']->ID, absint( $change['entity_revision'] ), (string) $change['content_hash'], false );
					++$counts['deleted'];
				}
			}
			$this->repository->invalidate_validation( $config_set );
			$this->audit->record( 'config-changeset-applied', 'success', array( 'config_set' => $config_set ) + $counts, $config_entity->ID, 0, null, false );
			if ( false === $wpdb->query( 'COMMIT' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Completes the atomic configuration changeset.
				throw new RuntimeException( 'Composer could not commit the configuration changeset.' );
			}
		} catch ( \Throwable $error ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Prevents partial configuration changes.
			foreach ( array_unique( $affected ) as $post_id ) {
				clean_post_cache( $post_id );
			}
			throw $error;
		}

		return array( 'config_set' => $this->repository->describe_config_set( $config_set ), 'summary' => $counts );
	}
}
