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
			$mode        = (string) ( $payload['composition_mode'] ?? 'document' );
			if ( '' === $page_type || isset( $page_types[ $page_type ] ) ) {
				$errors[] = $this->issue( 'blueprint-page-type', 'Blueprint page types must be present and unique.', $blueprint['key'] );
			}
			$page_types[ $page_type ] = true;
			if ( ! in_array( $excerpt, array( 'required', 'optional', 'disabled' ), true ) ) {
				$errors[] = $this->issue( 'blueprint-excerpt-policy', 'Excerpt policy must be required, optional, or disabled.', $blueprint['key'] );
			}
			if ( ! in_array( $mode, array( 'document', 'structured-record' ), true ) ) {
				$errors[] = $this->issue( 'blueprint-composition-mode', 'Composition mode must be document or structured-record.', $blueprint['key'] );
			} elseif ( 'document' === $mode ) {
				if ( empty( $payload['allowed_patterns'] ) || empty( $payload['required_sequence'] ) || empty( $payload['allowed_blocks'] ) ) {
					$errors[] = $this->issue( 'document-composition-contract', 'Document Blueprints require allowed patterns, a required sequence, and allowed blocks.', $blueprint['key'] );
				}
			} elseif ( ! empty( $payload['allowed_patterns'] ) || ! empty( $payload['required_sequence'] ) || ! empty( $payload['allowed_blocks'] ) ) {
				$errors[] = $this->issue( 'structured-record-composition-contract', 'Structured-record Blueprints must not declare Gutenberg patterns, a required sequence, or body blocks.', $blueprint['key'] );
			}
			$this->validate_blueprint_constraints( $payload, (string) $blueprint['key'], $errors );
			$this->validate_target_template( $payload, (string) $blueprint['key'], $errors );
		}

		$site_contract = ( $by_type[ EntityType::SITE_CONTRACT ][0]['payload'] ?? array() );
		$this->validate_language_policy(
			is_array( $site_contract ) ? $site_contract : array(),
			$by_type[ EntityType::BLUEPRINT ] ?? array(),
			$errors
		);
		$this->validate_content_field_access(
			is_array( $site_contract ) ? $site_contract : array(),
			$by_type[ EntityType::BLUEPRINT ] ?? array(),
			$errors
		);

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

	private function validate_content_field_access( array $site_contract, array $blueprints, array &$errors ): void {
		$policy = is_array( $site_contract['design_policy'] ?? null ) ? $site_contract['design_policy'] : array();
		$access = $policy['content_field_access'] ?? array();
		if ( null !== $access && ! is_array( $access ) ) {
			$errors[] = $this->issue( 'content-field-access-type', 'Content field access must be an object.', 'site-contract:design_policy.content_field_access' );
			return;
		}

		$targets = array();
		foreach ( $blueprints as $blueprint ) {
			$payload   = is_array( $blueprint['payload'] ?? null ) ? $blueprint['payload'] : array();
			$post_type = sanitize_key( (string) ( $payload['target_post_type'] ?? '' ) );
			if ( '' !== $post_type ) {
				$targets[ $post_type ] = true;
			}
		}

		foreach ( is_array( $access ) ? $access : array() as $post_type => $fields ) {
			$post_type = sanitize_key( (string) $post_type );
			$base_path = 'site-contract:design_policy.content_field_access.' . $post_type;
			if ( '' === $post_type || ! isset( $targets[ $post_type ] ) ) {
				$errors[] = $this->issue( 'content-field-blueprint-missing', 'Content field access requires a Blueprint targeting the same post type.', $base_path );
				continue;
			}
			if ( ! post_type_exists( $post_type ) || ! is_array( $fields ) ) {
				$errors[] = $this->issue( 'content-field-post-type-unavailable', 'Content field access targets an unavailable post type or invalid field map.', $base_path );
				continue;
			}
			$registered = get_registered_meta_keys( 'post', $post_type );
			foreach ( $fields as $meta_key => $rules ) {
				$meta_key   = (string) $meta_key;
				$field_path = $base_path . '.' . $meta_key;
				if ( '' === $meta_key || '_' === $meta_key[0] || ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,254}$/', $meta_key ) ) {
					$errors[] = $this->issue( 'content-field-key-forbidden', 'Content field keys must be public, explicit, and syntactically safe.', $field_path );
					continue;
				}
				if ( ! is_array( $rules ) || ( empty( $rules['read'] ) && empty( $rules['write'] ) ) ) {
					$errors[] = $this->issue( 'content-field-rule-invalid', 'Each content field rule must enable read or write access.', $field_path );
					continue;
				}
				$registration = $registered[ $meta_key ] ?? null;
				if ( ! is_array( $registration ) ) {
					$errors[] = $this->issue( 'content-field-unregistered', 'An enabled content field is not registered for this post type.', $field_path );
					continue;
				}
				if ( empty( $registration['single'] ) || empty( $registration['show_in_rest'] ) ) {
					$errors[] = $this->issue( 'content-field-registration-unsafe', 'An enabled content field must be single-value and REST-visible.', $field_path );
				}
				if ( ! in_array( (string) ( $registration['type'] ?? '' ), array( 'string', 'integer', 'number', 'boolean', 'array', 'object' ), true ) ) {
					$errors[] = $this->issue( 'content-field-type-unsupported', 'An enabled content field uses an unsupported registered type.', $field_path );
				}
			}
		}
	}

	private function validate_language_policy( array $site_contract, array $blueprints, array &$errors ): void {
		$policy      = is_array( $site_contract['design_policy'] ?? null ) ? $site_contract['design_policy'] : array();
		$language    = trim( (string) ( $policy['content_language'] ?? '' ) );
		$enforcement = (string) ( $policy['content_language_enforcement'] ?? 'advisory' );
		if ( '' !== $language && ! preg_match( '/^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$/', $language ) ) {
			$errors[] = $this->issue( 'content-language-invalid', 'Site Contract content_language must be a bounded BCP 47 language tag.', 'site-contract:design_policy.content_language' );
		}
		if ( ! in_array( $enforcement, array( 'advisory', 'strict' ), true ) ) {
			$errors[] = $this->issue( 'content-language-enforcement-invalid', 'Content language enforcement must be advisory or strict.', 'site-contract:design_policy.content_language_enforcement' );
		}
		if ( 'strict' === $enforcement && '' === $language ) {
			$errors[] = $this->issue( 'strict-content-language-missing', 'Strict content-language enforcement requires content_language.', 'site-contract:design_policy.content_language' );
		}
		foreach ( $blueprints as $blueprint ) {
			$payload = is_array( $blueprint['payload'] ?? null ) ? $blueprint['payload'] : array();
			$narrowed = trim( (string) ( $payload['content_language'] ?? '' ) );
			if ( '' === $narrowed ) {
				continue;
			}
			if ( ! preg_match( '/^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$/', $narrowed ) ) {
				$errors[] = $this->issue( 'blueprint-content-language-invalid', 'Blueprint content_language must be a bounded BCP 47 language tag.', $blueprint['key'] );
				continue;
			}
			if ( '' !== $language && strtok( strtolower( $narrowed ), '-' ) !== strtok( strtolower( $language ), '-' ) ) {
				$errors[] = $this->issue( 'blueprint-content-language-conflict', 'A Blueprint may narrow but cannot override the Site Contract language.', $blueprint['key'] );
			}
		}
	}

	private function validate_target_template( array $blueprint, string $entity, array &$errors ): void {
		if ( ! array_key_exists( 'target_template', $blueprint ) ) {
			return;
		}
		$template = $blueprint['target_template'];
		$path     = $entity . ':target_template';
		if ( ! is_array( $template ) ) {
			$errors[] = $this->issue( 'blueprint-target-template-invalid', 'Blueprint target_template must be an object.', $path );
			return;
		}

		$has_slug = array_key_exists( 'slug', $template );
		$has_file = array_key_exists( 'file', $template );
		if ( $has_slug === $has_file ) {
			$errors[] = $this->issue( 'blueprint-target-template-exclusive', 'Blueprint target_template must define exactly one slug or hierarchy file.', $path );
			return;
		}

		if ( $has_slug ) {
			$slug = trim( (string) $template['slug'] );
			if ( ! preg_match( '/^[a-z0-9][a-z0-9_-]{0,199}$/', $slug ) ) {
				$errors[] = $this->issue( 'blueprint-target-template-slug-invalid', 'Blueprint target_template slug is invalid.', $path . '.slug' );
			}
			return;
		}

		$file = trim( str_replace( '\\', '/', (string) $template['file'] ) );
		if ( ! preg_match( '#^templates/[a-z0-9][a-z0-9_-]{0,199}\.html$#', $file ) ) {
			$errors[] = $this->issue( 'blueprint-target-template-file-invalid', 'Blueprint hierarchy template file is invalid.', $path . '.file' );
		}
	}

	private function validate_blueprint_constraints( array $blueprint, string $entity, array &$errors ): void {
		$constraints = $blueprint['constraints'] ?? array();
		if ( ! is_array( $constraints ) ) {
			$errors[] = $this->issue( 'blueprint-constraints-type', 'Blueprint constraints must be an object.', $entity . ':constraints' );
			return;
		}

		foreach ( array( 'exactly_one_h1', 'inline_css', 'custom_html', 'shortcodes', 'external_embeds', 'theme_presets_only' ) as $key ) {
			if ( array_key_exists( $key, $constraints ) && ! is_bool( $constraints[ $key ] ) ) {
				$errors[] = $this->issue( 'blueprint-constraint-boolean', 'Blueprint boolean constraints must use true or false.', $entity . ':constraints.' . $key );
			}
		}
		if ( true === ( $constraints['custom_html'] ?? false ) ) {
			$errors[] = $this->issue( 'blueprint-custom-html-forbidden', 'Custom HTML cannot be enabled by a Blueprint.', $entity . ':constraints.custom_html' );
		}
		if (
			array_key_exists( 'maximum_words', $constraints )
			&& ( ! is_int( $constraints['maximum_words'] ) || $constraints['maximum_words'] < 1 )
		) {
			$errors[] = $this->issue( 'blueprint-maximum-words', 'Blueprint maximum_words must be a positive integer.', $entity . ':constraints.maximum_words' );
		}
		if ( in_array( 'core/html', (array) ( $blueprint['allowed_blocks'] ?? array() ), true ) ) {
			$errors[] = $this->issue( 'blueprint-custom-html-block-forbidden', 'Custom HTML blocks cannot be enabled by a Blueprint.', $entity . ':allowed_blocks' );
		}
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
