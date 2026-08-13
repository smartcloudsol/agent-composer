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
		$this->validate_content_taxonomy_access(
			is_array( $site_contract ) ? $site_contract : array(),
			$by_type[ EntityType::BLUEPRINT ] ?? array(),
			$errors
		);
		$this->validate_remote_media_policy(
			is_array( $site_contract ) ? $site_contract : array(),
			$errors
		);
		$this->validate_registered_block_contracts(
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
				if ( 'relation' === ( $rules['semantic_type'] ?? '' ) ) {
					$cardinality = (string) ( $rules['cardinality'] ?? 'many' );
					$registered_type = (string) ( $registration['type'] ?? '' );
					if ( ! in_array( $cardinality, array( 'one', 'many' ), true ) || ( 'one' === $cardinality ? 'integer' !== $registered_type : 'array' !== $registered_type ) ) {
						$errors[] = $this->issue( 'relation-cardinality-registration-mismatch', 'A relation cardinality must match its registered integer or array meta type.', $field_path );
					}
					$target_types = is_array( $rules['target_post_types'] ?? null ) ? $rules['target_post_types'] : array();
					if ( empty( $target_types ) ) {
						$errors[] = $this->issue( 'relation-target-types-missing', 'A relation field must declare at least one target post type.', $field_path );
					}
					foreach ( $target_types as $target_type ) {
						if ( ! post_type_exists( sanitize_key( (string) $target_type ) ) ) {
							$errors[] = $this->issue( 'relation-target-type-unavailable', 'A relation field targets an unavailable post type.', $field_path . '.target_post_types' );
						}
					}
				}
			}
		}
	}

	private function validate_content_taxonomy_access( array $site_contract, array $blueprints, array &$errors ): void {
		$policy = is_array( $site_contract['design_policy'] ?? null ) ? $site_contract['design_policy'] : array();
		$access = $policy['content_taxonomy_access'] ?? array();
		if ( null !== $access && ! is_array( $access ) ) {
			$errors[] = $this->issue( 'content-taxonomy-access-type', 'Content taxonomy access must be an object.', 'site-contract:design_policy.content_taxonomy_access' );
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

		foreach ( is_array( $access ) ? $access : array() as $post_type => $taxonomies ) {
			$post_type = sanitize_key( (string) $post_type );
			$base_path = 'site-contract:design_policy.content_taxonomy_access.' . $post_type;
			if ( '' === $post_type || ! isset( $targets[ $post_type ] ) ) {
				$errors[] = $this->issue( 'content-taxonomy-blueprint-missing', 'Content taxonomy access requires a Blueprint targeting the same post type.', $base_path );
				continue;
			}
			if ( ! post_type_exists( $post_type ) || ! is_array( $taxonomies ) ) {
				$errors[] = $this->issue( 'content-taxonomy-post-type-unavailable', 'Content taxonomy access targets an unavailable post type or invalid taxonomy map.', $base_path );
				continue;
			}

			foreach ( $taxonomies as $taxonomy => $rules ) {
				$taxonomy     = sanitize_key( (string) $taxonomy );
				$taxonomy_path = $base_path . '.' . $taxonomy;
				$object       = '' !== $taxonomy && function_exists( 'get_taxonomy' ) ? get_taxonomy( $taxonomy ) : false;
				if ( '' === $taxonomy || ! is_array( $rules ) || ! $object || ! is_object_in_taxonomy( $post_type, $taxonomy ) ) {
					$errors[] = $this->issue( 'content-taxonomy-unavailable', 'An enabled taxonomy must be registered for the selected post type.', $taxonomy_path );
					continue;
				}
				if ( ( empty( $object->public ) && empty( $object->publicly_queryable ) ) || empty( $object->show_ui ) || empty( $object->show_in_rest ) ) {
					$errors[] = $this->issue( 'content-taxonomy-registration-unsafe', 'An enabled taxonomy must be public, wp-admin-visible, and REST-visible.', $taxonomy_path );
				}
				foreach ( array( 'search', 'assign', 'create' ) as $flag ) {
					if ( array_key_exists( $flag, $rules ) && ! is_bool( $rules[ $flag ] ) ) {
						$errors[] = $this->issue( 'content-taxonomy-flag-invalid', 'Taxonomy search, assign, and create flags must be booleans.', $taxonomy_path . '.' . $flag );
					}
				}

				$search = true === ( $rules['search'] ?? false );
				$assign = true === ( $rules['assign'] ?? false );
				$create = true === ( $rules['create'] ?? false );
				if ( ! $search ) {
					$errors[] = $this->issue( 'content-taxonomy-search-required', 'Every enabled taxonomy policy must allow search.', $taxonomy_path . '.search' );
				}
				if ( $assign && ! $search ) {
					$errors[] = $this->issue( 'content-taxonomy-assign-dependency', 'Taxonomy assignment requires search access.', $taxonomy_path . '.assign' );
				}
				if ( $create && ( ! $assign || ! $search ) ) {
					$errors[] = $this->issue( 'content-taxonomy-create-dependency', 'Taxonomy creation requires assignment and search access.', $taxonomy_path . '.create' );
				}
				$assign_capability = (string) ( $object->cap->assign_terms ?? '' );
				if ( $assign && ( '' === $assign_capability || ! current_user_can( \SmartCloud\AgentComposer\Infrastructure\WordPress\Activation::CAP_ASSIGN_TERMS ) || ! current_user_can( $assign_capability ) ) ) {
					$errors[] = $this->issue( 'content-taxonomy-assign-capability-missing', 'The current user requires both the Composer taxonomy assignment capability and the taxonomy assignment capability.', $taxonomy_path . '.assign' );
				}
				if ( $create && ! current_user_can( \SmartCloud\AgentComposer\Infrastructure\WordPress\Activation::CAP_CREATE_TERMS ) ) {
					$errors[] = $this->issue( 'content-taxonomy-create-capability-missing', 'The current user lacks the dedicated Composer taxonomy creation capability.', $taxonomy_path . '.create' );
				}

				$maximum = $rules['maximum_items'] ?? 20;
				if ( ! is_int( $maximum ) || $maximum < 1 || $maximum > 100 ) {
					$errors[] = $this->issue( 'content-taxonomy-maximum-invalid', 'Taxonomy maximum_items must be an integer from 1 through 100.', $taxonomy_path . '.maximum_items' );
				}
				if ( ! in_array( (string) ( $rules['assignment_mode'] ?? 'replace' ), array( 'append', 'replace' ), true ) ) {
					$errors[] = $this->issue( 'content-taxonomy-assignment-mode-invalid', 'Taxonomy assignment mode must be append or replace.', $taxonomy_path . '.assignment_mode' );
				}

				$parent_policy = (string) ( $rules['creation_parent_policy'] ?? 'root-only' );
				$parent_slugs  = $rules['creation_parent_slugs'] ?? array();
				if ( ! in_array( $parent_policy, array( 'root-only', 'allowlist' ), true ) ) {
					$errors[] = $this->issue( 'content-taxonomy-parent-policy-invalid', 'Hierarchical taxonomy creation parent policy must be root-only or allowlist.', $taxonomy_path . '.creation_parent_policy' );
				}
				if ( ! is_array( $parent_slugs ) || array_is_list( $parent_slugs ) === false || count( $parent_slugs ) > 100 ) {
					$errors[] = $this->issue( 'content-taxonomy-parent-slugs-invalid', 'Taxonomy creation parent slugs must be a list.', $taxonomy_path . '.creation_parent_slugs' );
					continue;
				}
				foreach ( $parent_slugs as $index => $slug ) {
					if ( ! is_string( $slug ) || strlen( $slug ) > 200 || sanitize_title( $slug ) !== $slug || '' === $slug ) {
						$errors[] = $this->issue( 'content-taxonomy-parent-slug-invalid', 'Every taxonomy creation parent slug must be a durable WordPress term slug.', $taxonomy_path . '.creation_parent_slugs.' . $index );
					} elseif ( ! term_exists( $slug, $taxonomy ) ) {
						$errors[] = $this->issue( 'content-taxonomy-parent-term-unavailable', 'Every allowed taxonomy parent slug must resolve to an existing term.', $taxonomy_path . '.creation_parent_slugs.' . $index );
					}
				}
				if ( 'root-only' === $parent_policy && ! empty( $parent_slugs ) ) {
					$errors[] = $this->issue( 'content-taxonomy-root-parent-slugs-forbidden', 'Root-only taxonomy creation cannot declare parent slugs.', $taxonomy_path . '.creation_parent_slugs' );
				}
				if ( ! empty( $object->hierarchical ) && $create && 'allowlist' === $parent_policy && empty( $parent_slugs ) ) {
					$errors[] = $this->issue( 'content-taxonomy-parent-allowlist-empty', 'Allowlisted hierarchical creation requires at least one parent slug.', $taxonomy_path . '.creation_parent_slugs' );
				}
				if ( empty( $object->hierarchical ) && ( 'root-only' !== $parent_policy || ! empty( $parent_slugs ) ) ) {
					$errors[] = $this->issue( 'content-taxonomy-flat-parent-policy-invalid', 'Non-hierarchical taxonomies cannot declare creation parents.', $taxonomy_path . '.creation_parent_policy' );
				}
			}
		}
	}

	private function validate_remote_media_policy( array $site_contract, array &$errors ): void {
		$policy = is_array( $site_contract['design_policy'] ?? null ) ? $site_contract['design_policy'] : array();
		$media  = $policy['remote_media_ingest'] ?? array();
		if ( null !== $media && ! is_array( $media ) ) {
			$errors[] = $this->issue( 'remote-media-policy-type', 'Remote media ingestion policy must be an object.', 'site-contract:design_policy.remote_media_ingest' );
			return;
		}
		if ( ! is_array( $media ) || empty( $media['enabled'] ) ) {
			return;
		}
		$hosts = (array) ( $media['allowed_hosts'] ?? array() );
		$mimes = (array) ( $media['allowed_mime_types'] ?? array() );
		if ( empty( $hosts ) ) {
			$errors[] = $this->issue( 'remote-media-hosts-missing', 'Enabled remote media ingestion requires at least one exact host.', 'site-contract:design_policy.remote_media_ingest.allowed_hosts' );
		}
		foreach ( $hosts as $host ) {
			if ( ! preg_match( '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?))*$/', strtolower( trim( (string) $host ) ) ) ) {
				$errors[] = $this->issue( 'remote-media-host-invalid', 'Remote media hosts must be exact DNS names without schemes, paths, ports, or wildcards.', 'site-contract:design_policy.remote_media_ingest.allowed_hosts' );
			}
		}
		$allowed_mimes = array( 'image/jpeg', 'image/png', 'image/webp', 'image/avif', 'image/gif' );
		if ( empty( $mimes ) || array_diff( array_map( 'sanitize_mime_type', $mimes ), $allowed_mimes ) ) {
			$errors[] = $this->issue( 'remote-media-mime-invalid', 'Remote media MIME types must be selected from the supported raster image allowlist.', 'site-contract:design_policy.remote_media_ingest.allowed_mime_types' );
		}
		$max_bytes = $media['max_bytes'] ?? 12582912;
		if ( ! is_int( $max_bytes ) || $max_bytes < 1024 || $max_bytes > 26214400 ) {
			$errors[] = $this->issue( 'remote-media-size-invalid', 'Remote media max_bytes must be an integer from 1024 through 26214400.', 'site-contract:design_policy.remote_media_ingest.max_bytes' );
		}
	}

	private function validate_registered_block_contracts( array $site_contract, array $blueprints, array &$errors ): void {
		$policy     = is_array( $site_contract['design_policy'] ?? null ) ? $site_contract['design_policy'] : array();
		$extensions = is_array( $policy['block_extensions'] ?? null ) ? $policy['block_extensions'] : array();
		$contracts  = $extensions['registered_block_contracts'] ?? array();
		if ( null !== $contracts && ! is_array( $contracts ) ) {
			$errors[] = $this->issue( 'registered-block-contracts-type', 'Registered block contracts must be an object.', 'site-contract:design_policy.block_extensions.registered_block_contracts' );
			return;
		}

		$namespaces = array_values( array_filter( array_map( static fn( mixed $value ): string => sanitize_key( (string) $value ), (array) ( $extensions['allowed_plugin_namespaces'] ?? array() ) ) ) );
		$allowed    = array();
		foreach ( $blueprints as $blueprint ) {
			foreach ( (array) ( $blueprint['payload']['allowed_blocks'] ?? array() ) as $block_name ) {
				$allowed[ strtolower( trim( (string) $block_name ) ) ] = true;
			}
		}
		$registry = class_exists( '\\WP_Block_Type_Registry' ) ? \WP_Block_Type_Registry::get_instance() : null;
		foreach ( is_array( $contracts ) ? $contracts : array() as $block_name => $contract ) {
			$block_name = strtolower( trim( (string) $block_name ) );
			$path       = 'site-contract:design_policy.block_extensions.registered_block_contracts.' . $block_name;
			if ( ! preg_match( '#^[a-z0-9-]+/[a-z0-9-]+$#', $block_name ) || str_starts_with( $block_name, 'core/' ) || ! is_array( $contract ) ) {
				$errors[] = $this->issue( 'registered-block-contract-invalid', 'A registered block contract requires a non-core block name and object value.', $path );
				continue;
			}
			$namespace = strstr( $block_name, '/', true );
			if ( false === $namespace || ! in_array( $namespace, $namespaces, true ) ) {
				$errors[] = $this->issue( 'registered-block-namespace-not-allowed', 'A registered block contract requires an exact namespace opt-in.', $path );
			}
			if ( ! isset( $allowed[ $block_name ] ) ) {
				$errors[] = $this->issue( 'registered-block-blueprint-missing', 'A registered block contract must be selected by at least one Blueprint.', $path );
			}
			$block_type = is_object( $registry ) ? $registry->get_registered( $block_name ) : null;
			if ( ! is_object( $block_type ) ) {
				$errors[] = $this->issue( 'registered-block-unavailable', 'A contracted third-party block is not registered on this site.', $path );
				continue;
			}
			$rendering = (string) ( $contract['rendering'] ?? 'server' );
			$is_dynamic = method_exists( $block_type, 'is_dynamic' ) ? (bool) $block_type->is_dynamic() : ! empty( $block_type->render_callback );
			if ( 'server' === $rendering && ! $is_dynamic ) {
				$errors[] = $this->issue( 'registered-block-server-rendering-mismatch', 'A server-rendered contract requires a dynamic registered block.', $path );
			}
			$registered_attributes = is_array( $block_type->attributes ?? null ) ? $block_type->attributes : array();
			foreach ( (array) ( $contract['attributes'] ?? array() ) as $attribute => $schema ) {
				$attribute_path = $path . '.attributes.' . $attribute;
				if ( ! isset( $registered_attributes[ $attribute ] ) || ! is_array( $schema ) ) {
					$errors[] = $this->issue( 'registered-block-attribute-unavailable', 'A contracted attribute is absent from the registered block schema.', $attribute_path );
					continue;
				}
				$registered_type = (string) ( $registered_attributes[ $attribute ]['type'] ?? '' );
				if ( '' !== (string) ( $schema['type'] ?? '' ) && $registered_type !== (string) $schema['type'] ) {
					$errors[] = $this->issue( 'registered-block-attribute-type-mismatch', 'A contracted attribute type differs from the registered block schema.', $attribute_path );
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
		$signals = $policy['content_language_mismatch_signals'] ?? array();
		if ( ! is_array( $signals ) || array_is_list( $signals ) === false || count( $signals ) > 200 ) {
			$errors[] = $this->issue( 'content-language-signals-invalid', 'Content language mismatch signals must be a list of at most 200 terms.', 'site-contract:design_policy.content_language_mismatch_signals' );
		} else {
			foreach ( $signals as $index => $signal ) {
				if ( ! is_string( $signal ) || '' === trim( $signal ) || strlen( $signal ) > 64 ) {
					$errors[] = $this->issue( 'content-language-signal-invalid', 'Each content language mismatch signal must be a non-empty string no longer than 64 bytes.', 'site-contract:design_policy.content_language_mismatch_signals.' . $index );
				}
			}
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
