<?php

namespace SmartCloud\AgentComposer\Infrastructure\Persistence;

use SmartCloud\AgentComposer\Domain\Configuration\CanonicalJson;
use SmartCloud\AgentComposer\Domain\Configuration\EntityType;

final class ActiveConfigurationSource {
	private ?array $entities = null;

	public function __construct( private readonly WordPressConfigurationRepository $repository ) {}

	public function active_config_set(): string {
		return sanitize_key( (string) get_option( 'smartcloud_composer_active_config_set', '' ) );
	}

	public function blueprint( string $page_type ): ?array {
		$page_type = sanitize_key( $page_type );
		$payload   = $this->entities()[ EntityType::BLUEPRINT ][ $page_type ] ?? null;
		return is_array( $payload ) ? $payload : null;
	}

	public function page_types(): array {
		$page_types = array_keys( $this->entities()[ EntityType::BLUEPRINT ] ?? array() );
		sort( $page_types );
		return array_values( $page_types );
	}

	public function design_policy(): ?array {
		foreach ( array( EntityType::SITE_CONTRACT, EntityType::CONFIG_SET ) as $type ) {
			foreach ( $this->entities()[ $type ] ?? array() as $payload ) {
				if ( ! is_array( $payload ) ) {
					continue;
				}
				$policy = $payload['design_policy'] ?? $payload['policy'] ?? null;
				if ( is_array( $policy ) ) {
					return $policy;
				}
				if ( isset( $payload['allowed_pattern_namespaces'], $payload['constraints'] ) ) {
					return $payload;
				}
			}
		}
		return null;
	}

	public function reset(): void {
		$this->entities = null;
	}

	private function entities(): array {
		if ( null !== $this->entities ) {
			return $this->entities;
		}
		$this->entities = array();
		$config_set     = $this->active_config_set();
		if ( '' === $config_set ) {
			return $this->entities;
		}

		foreach ( EntityType::all() as $type ) {
			foreach ( $this->repository->find_by_type( $type, $config_set ) as $entity ) {
				if ( ! $entity instanceof \WP_Post || '1' !== (string) get_post_meta( $entity->ID, '_smartcloud_composer_active', true ) ) {
					continue;
				}
				$payload = json_decode( (string) $entity->post_content, true );
				if ( ! is_array( $payload ) || JSON_ERROR_NONE !== json_last_error() ) {
					continue;
				}
				$checksum = (string) get_post_meta( $entity->ID, '_smartcloud_composer_checksum', true );
				if ( '' === $checksum || ! hash_equals( $checksum, CanonicalJson::checksum( $payload ) ) ) {
					continue;
				}
				$description = $this->repository->describe_entity( $entity );
				$key         = (string) ( $description['key'] ?? '' );
				if ( EntityType::BLUEPRINT === $type ) {
					$key = sanitize_key( (string) ( $payload['page_type'] ?? $key ) );
				}
				if ( '' !== $key ) {
					$this->entities[ $type ][ $key ] = $payload;
				}
			}
		}
		return $this->entities;
	}
}
