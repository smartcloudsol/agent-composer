<?php

namespace SmartCloud\AgentComposer\Application\Configuration;

use InvalidArgumentException;
use Throwable;
use SmartCloud\AgentComposer\Domain\Configuration\CanonicalJson;
use SmartCloud\AgentComposer\Domain\Configuration\EntityType;
use SmartCloud\AgentComposer\Infrastructure\Persistence\AuditTable;
use SmartCloud\AgentComposer\Infrastructure\Persistence\WordPressConfigurationRepository;

final class ConfigPackageImporter {
	private const MAX_ENTITIES = 500;

	public function __construct(
		private readonly WordPressConfigurationRepository $repository,
		private readonly AuditTable $audit
	) {}

	public function import( array $package, bool $record_audit = true ): array {
		$this->validate_package( $package );
		$config_set = sanitize_key( $package['package']['id'] );
		if ( ! empty( $this->repository->find_by_type( EntityType::CONFIG_SET, $config_set ) ) ) {
			throw new InvalidArgumentException( 'A configuration set with this package ID already exists.' );
		}
		$created = array();
		try {
			foreach ( $package['entities'] as $entity ) {
				$created[] = $this->repository->save_working_entity(
					EntityType::assert( (string) $entity['type'] ),
					(string) $entity['id'],
					(array) $entity['payload'],
					$config_set
				);
			}
		} catch ( Throwable $error ) {
			foreach ( array_reverse( $created ) as $post_id ) {
				wp_delete_post( $post_id, true );
			}
			if ( $record_audit ) {
				$this->audit->record( 'config-package-imported', 'error', array( 'package_id' => $config_set, 'created_before_failure' => count( $created ), 'error_class' => get_class( $error ) ) );
			}
			throw $error;
		}
		$this->repository->set_lifecycle( $config_set, 'working' );
		if ( $record_audit ) {
			$this->audit->record( 'config-package-imported', 'success', array( 'package_id' => $config_set, 'entity_count' => count( $created ) ) );
		}
		return array( 'config_set' => $config_set, 'entity_ids' => $created, 'active' => false );
	}

	public function validate_package( array $package ): void {
		if ( '1.0.0-rc.1' !== ( $package['schema_version'] ?? null ) ) {
			throw new InvalidArgumentException( 'Unsupported config package schema version.' );
		}
		if ( 'working-set-only' !== ( $package['activation'] ?? null ) ) {
			throw new InvalidArgumentException( 'Imported packages must remain inactive working sets.' );
		}
		$package_id = (string) ( $package['package']['id'] ?? '' );
		if ( '' === $package_id || sanitize_key( $package_id ) !== $package_id || ! is_array( $package['entities'] ?? null ) || count( $package['entities'] ) > self::MAX_ENTITIES ) {
			throw new InvalidArgumentException( 'Invalid config package structure or entity count.' );
		}
		$this->assert_no_secrets( $package );
		$manifest_count = 0;
		foreach ( $package['entities'] as $entity ) {
			if ( empty( $entity['id'] ) || empty( $entity['type'] ) || ! is_array( $entity['payload'] ?? null ) ) {
				throw new InvalidArgumentException( 'Invalid config package entity.' );
			}
			EntityType::assert( (string) $entity['type'] );
			if ( EntityType::CONFIG_SET === $entity['type'] ) {
				++$manifest_count;
				if ( $package_id !== (string) $entity['id'] ) {
					throw new InvalidArgumentException( 'The config-set manifest must match the package ID.' );
				}
			}
			if ( ! empty( $entity['checksum'] ) && ! hash_equals( (string) $entity['checksum'], CanonicalJson::checksum( $entity['payload'] ) ) ) {
				throw new InvalidArgumentException( 'Config package entity checksum mismatch.' );
			}
		}
		if ( 1 !== $manifest_count ) {
			throw new InvalidArgumentException( 'Exactly one matching config-set manifest entity is required.' );
		}
		$declared = $package['checksums']['entities'] ?? '';
		if ( '' === $declared || ! hash_equals( (string) $declared, CanonicalJson::checksum( $package['entities'] ) ) ) {
			throw new InvalidArgumentException( 'Config package entity collection checksum mismatch.' );
		}
	}

	private function assert_no_secrets( mixed $value, string $key = '' ): void {
		if ( preg_match( '/(?:secret|password|credential|api[_-]?key|authorization|access[_-]?token|refresh[_-]?token|id[_-]?token|bearer[_-]?token)/i', $key ) ) {
			throw new InvalidArgumentException( 'Config packages cannot contain secrets.' );
		}
		if ( ! is_array( $value ) ) {
			return;
		}
		foreach ( $value as $child_key => $child ) {
			$this->assert_no_secrets( $child, (string) $child_key );
		}
	}
}
