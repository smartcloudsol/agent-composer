<?php

namespace SmartCloud\AgentComposer\Application\Configuration;

use SmartCloud\AgentComposer\Domain\Configuration\EntityType;
use SmartCloud\AgentComposer\Infrastructure\Persistence\AuditTable;
use SmartCloud\AgentComposer\Infrastructure\Persistence\WordPressConfigurationRepository;
use SmartCloud\AgentComposer\Integration\Providers\ProviderRegistry;

final class ConfigSetValidator {
	public function __construct(
		private readonly WordPressConfigurationRepository $repository,
		private readonly ValidationReceiptService $receipts,
		private readonly ProviderRegistry $providers,
		private readonly AuditTable $audit
	) {}

	public function validate( string $config_set ): array {
		$entities = array_map( array( $this->repository, 'describe_entity' ), $this->repository->entities( $config_set ) );
		$errors   = array();
		$warnings = array();
		$by_type  = array();
		foreach ( $entities as $entity ) {
			$by_type[ $entity['type'] ][] = $entity;
			$this->assert_no_secrets( $entity['payload'], '', $errors, $entity['key'] );
		}

		if ( 1 !== count( $by_type[ EntityType::CONFIG_SET ] ?? array() ) ) {
			$errors[] = $this->issue( 'config-set-cardinality', 'Exactly one config-set manifest is required.' );
		}
		if ( 1 !== count( $by_type[ EntityType::SITE_CONTRACT ] ?? array() ) ) {
			$errors[] = $this->issue( 'site-contract-cardinality', 'Exactly one site contract is required.' );
		}
		if ( empty( $by_type[ EntityType::BLUEPRINT ] ) ) {
			$errors[] = $this->issue( 'blueprint-missing', 'At least one page blueprint is required.' );
		}

		$page_types = array();
		foreach ( $by_type[ EntityType::BLUEPRINT ] ?? array() as $blueprint ) {
			$payload     = $blueprint['payload'];
			$page_type   = sanitize_key( (string) ( $payload['page_type'] ?? $blueprint['key'] ) );
			$excerpt     = sanitize_key( (string) ( $payload['excerpt_policy'] ?? $payload['excerpt'] ?? 'optional' ) );
			if ( '' === $page_type || isset( $page_types[ $page_type ] ) ) {
				$errors[] = $this->issue( 'blueprint-page-type', 'Blueprint page types must be present and unique.', $blueprint['key'] );
			}
			$page_types[ $page_type ] = true;
			if ( ! in_array( $excerpt, array( 'required', 'optional', 'disabled' ), true ) ) {
				$errors[] = $this->issue( 'blueprint-excerpt-policy', 'Excerpt policy must be required, optional, or disabled.', $blueprint['key'] );
			}
		}

		$profiles       = $this->providers->profiles();
		$provider_ids   = array_values( array_unique( array_map( static fn( array $profile ): string => (string) $profile['provider']['id'], $profiles ) ) );
		$required       = $this->required_providers( $by_type[ EntityType::PROVIDER_POLICY ] ?? array() );
		foreach ( $required as $provider_id ) {
			if ( ! in_array( $provider_id, $provider_ids, true ) ) {
				$errors[] = $this->issue( 'required-provider-unavailable', 'A required provider has no ready registered Ability profile.', $provider_id );
			}
		}
		if ( empty( $profiles ) ) {
			$warnings[] = $this->issue( 'provider-profile-empty', 'No optional provider profiles are currently available.' );
		}

		$valid       = empty( $errors );
		$config_hash = $this->repository->configuration_hash( $config_set );
		$receipt     = null;
		if ( $valid ) {
			$receipt = $this->receipts->issue( $config_set, $config_hash );
			$this->repository->set_lifecycle( $config_set, 'valid' );
			$set_post = $this->repository->find_by_type( EntityType::CONFIG_SET, $config_set )[0] ?? null;
			if ( $set_post instanceof \WP_Post ) {
				update_post_meta( $set_post->ID, '_smartcloud_composer_validation_receipt', $receipt['receipt'] );
				update_post_meta( $set_post->ID, '_smartcloud_composer_validation_checksum', $config_hash );
				update_post_meta( $set_post->ID, '_smartcloud_composer_validation_expires_gmt', $receipt['payload']['expires_gmt'] );
			}
		} else {
			$this->repository->set_lifecycle( $config_set, 'invalid' );
		}

		$result = array(
			'valid'           => $valid,
			'config_set'      => sanitize_key( $config_set ),
			'config_hash'     => $config_hash,
			'entity_count'    => count( $entities ),
			'page_type_count' => count( $page_types ),
			'provider_count'  => count( $provider_ids ),
			'errors'          => $errors,
			'warnings'        => $warnings,
			'receipt'         => $receipt['receipt'] ?? '',
			'expires_gmt'     => $receipt['payload']['expires_gmt'] ?? '',
		);
		$this->audit->record( 'config-set-validated', $valid ? 'success' : 'error', array( 'config_set' => $config_set, 'config_hash' => $config_hash, 'error_count' => count( $errors ), 'warning_count' => count( $warnings ) ) );
		return $result;
	}

	private function required_providers( array $policies ): array {
		$required = array();
		foreach ( $policies as $policy ) {
			$payload = $policy['payload'];
			foreach ( (array) ( $payload['required_providers'] ?? array() ) as $provider_id ) {
				$required[] = sanitize_key( (string) $provider_id );
			}
			foreach ( (array) ( $payload['providers'] ?? array() ) as $provider ) {
				if ( is_array( $provider ) && ! empty( $provider['required'] ) ) {
					$required[] = sanitize_key( (string) ( $provider['id'] ?? '' ) );
				}
			}
		}
		return array_values( array_unique( array_filter( $required ) ) );
	}

	private function assert_no_secrets( mixed $value, string $key, array &$errors, string $entity ): void {
		if ( preg_match( '/(?:secret|password|credential|api[_-]?key|authorization|access[_-]?token|refresh[_-]?token|id[_-]?token|bearer[_-]?token)/i', $key ) ) {
			$errors[] = $this->issue( 'portable-secret-forbidden', 'Portable configuration cannot contain secret-like keys.', $entity . ':' . $key );
			return;
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $child_key => $child ) {
				$this->assert_no_secrets( $child, (string) $child_key, $errors, $entity );
			}
		}
	}

	private function issue( string $code, string $message, string $path = '' ): array {
		return array( 'code' => $code, 'message' => $message, 'path' => $path );
	}
}
