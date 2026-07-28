<?php

namespace SmartCloud\AgentComposer\Application\Configuration;

use InvalidArgumentException;
use Throwable;
use SmartCloud\AgentComposer\Domain\Configuration\CanonicalJson;
use SmartCloud\AgentComposer\Domain\Configuration\EntityType;
use SmartCloud\AgentComposer\Infrastructure\Persistence\AuditTable;
use SmartCloud\AgentComposer\Infrastructure\Persistence\WordPressConfigurationRepository;

final class ConfigBackupService {
	private const KIND = 'smartcloud-agent-composer-backup';
	private const MAX_PACKAGES = 50;

	public function __construct(
		private readonly WordPressConfigurationRepository $repository,
		private readonly ConfigPackageExporter $exporter,
		private readonly ConfigPackageImporter $importer,
		private readonly AuditTable $audit
	) {}

	public function export_all(): array {
		$sets = $this->repository->list_config_sets();
		usort( $sets, static fn( array $a, array $b ): int => strcmp( (string) $a['config_set'], (string) $b['config_set'] ) );
		$packages = array_map(
			fn( array $set ): array => $this->exporter->export( (string) $set['config_set'], false ),
			$sets
		);
		$backup = array(
			'schema_version'    => '1.0.0-rc.1',
			'kind'              => self::KIND,
			'activation'        => 'working-set-only',
			'created_gmt'       => gmdate( 'c' ),
			'source_site'       => home_url( '/' ),
			'active_config_set' => (string) get_option( 'smartcloud_composer_active_config_set', '' ),
			'packages'          => $packages,
			'checksums'         => array( 'packages' => CanonicalJson::checksum( $packages ) ),
		);
		$this->audit->record( 'config-backup-exported', 'success', array( 'package_count' => count( $packages ), 'checksum' => $backup['checksums']['packages'] ) );
		return $backup;
	}

	public function import_all( array $backup ): array {
		$this->validate_backup( $backup );
		$package_ids = array();
		foreach ( $backup['packages'] as $package ) {
			$this->importer->validate_package( $package );
			$package_id = (string) $package['package']['id'];
			if ( isset( $package_ids[ $package_id ] ) ) {
				throw new InvalidArgumentException( 'A backup cannot contain duplicate package IDs.' );
			}
			if ( ! empty( $this->repository->find_by_type( EntityType::CONFIG_SET, $package_id ) ) ) {
				throw new InvalidArgumentException( 'A configuration set from this backup already exists.' );
			}
			$package_ids[ $package_id ] = true;
		}

		$imports = array();
		$created = array();
		try {
			foreach ( $backup['packages'] as $package ) {
				$result    = $this->importer->import( $package, false );
				$imports[] = $result['config_set'];
				$created   = array_merge( $created, $result['entity_ids'] );
			}
			$this->audit->record( 'config-backup-imported', 'success', array( 'package_count' => count( $imports ), 'entity_count' => count( $created ), 'checksum' => $backup['checksums']['packages'] ) );
		} catch ( Throwable $error ) {
			foreach ( array_reverse( $created ) as $post_id ) {
				wp_delete_post( $post_id, true );
			}
			try {
				$this->audit->record( 'config-backup-imported', 'error', array( 'package_count' => count( $imports ), 'rolled_back_entities' => count( $created ), 'error_class' => get_class( $error ) ) );
			} catch ( Throwable ) {
				// Preserve the original import or audit failure after configuration rollback.
			}
			throw $error;
		}

		return array(
			'config_sets'             => $imports,
			'active'                  => false,
			'source_active_config_set' => sanitize_key( (string) ( $backup['active_config_set'] ?? '' ) ),
		);
	}

	public static function is_backup( array $value ): bool {
		return self::KIND === ( $value['kind'] ?? null );
	}

	private function validate_backup( array $backup ): void {
		if ( '1.0.0-rc.1' !== ( $backup['schema_version'] ?? null ) || self::KIND !== ( $backup['kind'] ?? null ) ) {
			throw new InvalidArgumentException( 'Unsupported Composer backup format.' );
		}
		if ( 'working-set-only' !== ( $backup['activation'] ?? null ) ) {
			throw new InvalidArgumentException( 'Restored configuration sets must remain inactive.' );
		}
		if ( ! is_array( $backup['packages'] ?? null ) || count( $backup['packages'] ) > self::MAX_PACKAGES ) {
			throw new InvalidArgumentException( 'Invalid backup package collection.' );
		}
		$declared = (string) ( $backup['checksums']['packages'] ?? '' );
		if ( '' === $declared || ! hash_equals( $declared, CanonicalJson::checksum( $backup['packages'] ) ) ) {
			throw new InvalidArgumentException( 'Composer backup checksum mismatch.' );
		}
	}
}
