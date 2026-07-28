<?php

namespace SmartCloud\AgentComposer\Application\Configuration;

use InvalidArgumentException;
use SmartCloud\AgentComposer\Domain\Configuration\CanonicalJson;
use SmartCloud\AgentComposer\Infrastructure\Persistence\AuditTable;
use SmartCloud\AgentComposer\Infrastructure\Persistence\WordPressConfigurationRepository;

final class ConfigPackageExporter {
	public function __construct(
		private readonly WordPressConfigurationRepository $repository,
		private readonly AuditTable $audit
	) {}

	public function export( string $config_set, bool $record_audit = true ): array {
		$entities = array();
		foreach ( $this->repository->entities( $config_set ) as $post ) {
			$entity = $this->repository->describe_entity( $post );
			$this->assert_no_secrets( $entity['payload'] );
			$entities[] = array(
				'id'       => $entity['key'],
				'type'     => $entity['type'],
				'payload'  => $entity['payload'],
				'checksum' => $entity['content_hash'],
			);
		}
		$package = array(
			'schema_version' => '1.0.0-rc.1',
			'activation'     => 'working-set-only',
			'package'        => array( 'id' => sanitize_key( $config_set ), 'exported_gmt' => gmdate( 'c' ), 'source_site' => home_url( '/' ) ),
			'entities'       => $entities,
			'checksums'      => array( 'entities' => CanonicalJson::checksum( $entities ) ),
		);
		if ( $record_audit ) {
			$this->audit->record( 'config-package-exported', 'success', array( 'config_set' => $config_set, 'entity_count' => count( $entities ), 'checksum' => $package['checksums']['entities'] ) );
		}
		return $package;
	}

	private function assert_no_secrets( mixed $value, string $key = '' ): void {
		if ( preg_match( '/(?:secret|password|credential|api[_-]?key|authorization|access[_-]?token|refresh[_-]?token|id[_-]?token|bearer[_-]?token)/i', $key ) ) {
			throw new InvalidArgumentException( 'Configuration exports cannot contain secrets.' );
		}
		if ( ! is_array( $value ) ) {
			return;
		}
		foreach ( $value as $child_key => $child ) {
			$this->assert_no_secrets( $child, (string) $child_key );
		}
	}
}
