<?php

namespace SmartCloud\AgentComposer\Application\Configuration;

use SmartCloud\AgentComposer\Domain\Configuration\CanonicalJson;
use SmartCloud\AgentComposer\Domain\Configuration\EntityType;
use SmartCloud\AgentComposer\Execution\Ability_Provider_Registry;
use SmartCloud\AgentComposer\Infrastructure\Persistence\AuditTable;
use SmartCloud\AgentComposer\Infrastructure\Persistence\WordPressConfigurationRepository;
use SmartCloud\AgentComposer\Integration\Providers\ProviderRegistry;

final class SiteDiscoveryService {
	public function __construct(
		private readonly WordPressConfigurationRepository $repository,
		private readonly ProviderRegistry $profiles,
		private readonly Ability_Provider_Registry $execution_providers,
		private readonly AuditTable $audit
	) {}

	public function discover( bool $persist = true ): array {
		$theme     = wp_get_theme();
		$parent    = $theme->parent();
		$manifest  = $this->theme_manifest( $theme->get_stylesheet_directory() );
		$providers = array();
		foreach ( $this->execution_providers->public_manifests() as $provider ) {
			$runtime_name = $this->ability_with_suffix( $provider['ability_names'], '/get-runtime-capabilities' );
			$runtime      = null;
			if ( $runtime_name && wp_has_ability( $runtime_name ) ) {
				$ability = wp_get_ability( $runtime_name );
				$runtime = is_object( $ability ) && method_exists( $ability, 'execute' ) ? $ability->execute( array() ) : null;
			}
			$providers[] = array(
				'id'                   => $provider['id'],
				'label'                => $provider['label'],
				'plugin_version'       => $provider['plugin_version'],
				'contract_version'     => $provider['contract_version'],
				'ability_names'        => $provider['ability_names'],
				'block_namespaces'     => $provider['block_namespaces'],
				'runtime'              => is_wp_error( $runtime ) ? array( 'runtime_ready' => false, 'error' => $runtime->get_error_code() ) : $runtime,
			);
		}
		$profile_count = count( $this->profiles->profiles() );
		$registered_blocks = $this->registered_blocks( $providers );
		$registered_patterns = $this->registered_patterns();
		$registered_templates = $this->registered_templates();
		$registered_post_types = $this->registered_post_types();
		$theme_profile = array(
			'name'             => (string) $theme->get( 'Name' ),
			'stylesheet'       => $theme->get_stylesheet(),
			'template'         => $theme->get_template(),
			'version'          => (string) $theme->get( 'Version' ),
			'parent_name'      => $parent ? (string) $parent->get( 'Name' ) : '',
			'parent_version'   => $parent ? (string) $parent->get( 'Version' ) : '',
			'manifest_status'  => $manifest['status'],
			'manifest'         => $manifest['data'],
		);
		$result = array(
			'generated_gmt'      => gmdate( 'c' ),
			'theme'              => $theme_profile,
			'providers'          => $providers,
			'registered_blocks'  => $registered_blocks,
			'registered_patterns' => $registered_patterns,
			'registered_templates' => $registered_templates,
			'registered_post_types' => $registered_post_types,
			'provider_profiles'  => $profile_count,
			'theme_fingerprint'  => CanonicalJson::checksum( array( $theme_profile, $registered_patterns, $registered_templates ) ),
			'provider_fingerprint' => CanonicalJson::checksum( array( $providers, $registered_blocks ) ),
			'content_model_fingerprint' => CanonicalJson::checksum( $registered_post_types ),
		);
		$result['site_capability_fingerprint'] = CanonicalJson::checksum( array( $result['theme_fingerprint'], $result['provider_fingerprint'], $result['content_model_fingerprint'] ) );

		if ( $persist ) {
			$key = 'discovery:' . gmdate( 'YmdHis' ) . '-' . substr( str_replace( '-', '', wp_generate_uuid4() ), 0, 6 );
			$this->repository->save_working_entity( EntityType::DISCOVERY, $key, array_merge( array( 'label' => 'Site capability discovery' ), $result ), 'site-discovery' );
			update_option( 'smartcloud_composer_last_discovery', array( 'key' => $key, 'fingerprint' => $result['site_capability_fingerprint'], 'generated_gmt' => $result['generated_gmt'] ), false );
			$this->audit->record( 'site-discovery-completed', 'success', array( 'discovery_key' => $key, 'provider_count' => count( $providers ), 'fingerprint' => $result['site_capability_fingerprint'] ) );
			$result['key'] = $key;
		}
		return $result;
	}

	private function registered_blocks( array $providers ): array {
		if ( ! class_exists( '\\WP_Block_Type_Registry' ) ) {
			return array();
		}
		$blocks = array();
		foreach ( \WP_Block_Type_Registry::get_instance()->get_all_registered() as $name => $block ) {
			$namespace = str_contains( (string) $name, '/' ) ? explode( '/', (string) $name, 2 )[0] : '';
			$provider  = null;
			foreach ( $providers as $candidate ) {
				if ( in_array( $namespace . '/', $candidate['block_namespaces'], true ) ) {
					$provider = $candidate['id'];
					break;
				}
			}
			$blocks[] = array(
				'name'      => sanitize_text_field( (string) $name ),
				'title'     => sanitize_text_field( (string) ( $block->title ?? $name ) ),
				'category'  => sanitize_key( (string) ( $block->category ?? '' ) ),
				'namespace' => sanitize_key( $namespace ),
				'provider'  => null === $provider ? null : sanitize_key( (string) $provider ),
			);
		}
		usort( $blocks, static fn( array $left, array $right ): int => strcmp( $left['name'], $right['name'] ) );
		return $blocks;
	}

	private function registered_patterns(): array {
		if ( ! class_exists( '\\WP_Block_Patterns_Registry' ) ) {
			return array();
		}
		$patterns = array();
		foreach ( \WP_Block_Patterns_Registry::get_instance()->get_all_registered() as $pattern ) {
			if ( ! is_array( $pattern ) || empty( $pattern['name'] ) ) {
				continue;
			}
			$name = sanitize_text_field( (string) $pattern['name'] );
			if ( ! preg_match( '#^[a-z0-9][a-z0-9_-]*/[a-z0-9][a-z0-9_-]*$#', $name ) ) {
				continue;
			}
			$patterns[] = array(
				'name'       => $name,
				'title'      => sanitize_text_field( (string) ( $pattern['title'] ?? $name ) ),
				'categories' => array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) ( $pattern['categories'] ?? array() ) ) ) ) ),
				'block_types' => array_values( array_unique( array_filter( array_map( 'sanitize_text_field', (array) ( $pattern['blockTypes'] ?? array() ) ) ) ) ),
				'source'     => sanitize_key( (string) ( $pattern['source'] ?? '' ) ),
			);
		}
		usort( $patterns, static fn( array $left, array $right ): int => strcmp( $left['name'], $right['name'] ) );
		return $patterns;
	}

	private function registered_templates(): array {
		if ( ! function_exists( 'get_block_templates' ) ) {
			return array();
		}
		$templates = array();
		foreach ( get_block_templates( array(), 'wp_template' ) as $template ) {
			$slug = sanitize_key( (string) ( $template->slug ?? '' ) );
			if ( '' === $slug ) {
				continue;
			}
			$templates[] = array(
				'slug'   => $slug,
				'title'  => sanitize_text_field( (string) ( $template->title ?? $slug ) ),
				'source' => sanitize_key( (string) ( $template->source ?? '' ) ),
				'theme'  => sanitize_key( (string) ( $template->theme ?? '' ) ),
			);
		}
		usort( $templates, static fn( array $left, array $right ): int => strcmp( $left['slug'], $right['slug'] ) );
		return $templates;
	}

	private function registered_post_types(): array {
		$post_types = array();
		foreach ( get_post_types( array(), 'objects' ) as $name => $post_type ) {
			if (
				! $post_type instanceof \WP_Post_Type
				|| 'attachment' === $name
				|| empty( $post_type->show_ui )
				|| ( empty( $post_type->public ) && empty( $post_type->publicly_queryable ) )
			) {
				continue;
			}
			$capability = (string) ( $post_type->cap->edit_posts ?? '' );
			$post_types[] = array(
				'name'            => sanitize_key( (string) $name ),
				'label'           => sanitize_text_field( (string) ( $post_type->labels->name ?? $post_type->label ?? $name ) ),
				'builtin'         => ! empty( $post_type->_builtin ),
				'public'          => ! empty( $post_type->public ) || ! empty( $post_type->publicly_queryable ),
				'show_ui'         => ! empty( $post_type->show_ui ),
				'show_in_rest'    => ! empty( $post_type->show_in_rest ),
				'supports_editor' => post_type_supports( (string) $name, 'editor' ),
				'current_user_can_edit' => '' !== $capability && current_user_can( $capability ),
				'registered_meta' => $this->registered_meta( (string) $name ),
			);
		}
		usort( $post_types, static fn( array $left, array $right ): int => strcmp( $left['label'], $right['label'] ) );
		return $post_types;
	}

	private function registered_meta( string $post_type ): array {
		$fields = array();
		foreach ( get_registered_meta_keys( 'post', $post_type ) as $meta_key => $registration ) {
			$meta_key = (string) $meta_key;
			$type     = (string) ( $registration['type'] ?? '' );
			if (
				! is_array( $registration )
				|| '' === $meta_key
				|| '_' === $meta_key[0]
				|| empty( $registration['single'] )
				|| empty( $registration['show_in_rest'] )
				|| ! in_array( $type, array( 'string', 'integer', 'number', 'boolean', 'array', 'object' ), true )
			) {
				continue;
			}
			$show_in_rest = $registration['show_in_rest'];
			$fields[] = array(
				'key'         => $meta_key,
				'type'        => $type,
				'description' => sanitize_text_field( (string) ( $registration['description'] ?? '' ) ),
				'rest_schema' => is_array( $show_in_rest ) && is_array( $show_in_rest['schema'] ?? null )
					? $show_in_rest['schema']
					: array( 'type' => $type ),
			);
		}
		usort( $fields, static fn( array $left, array $right ): int => strcmp( $left['key'], $right['key'] ) );
		return $fields;
	}

	private function theme_manifest( string $theme_directory ): array {
		$file = trailingslashit( $theme_directory ) . 'smartcloud-agent-composer.json';
		if ( ! is_readable( $file ) ) {
			return array( 'status' => 'not-present', 'data' => array() );
		}
		if ( filesize( $file ) > 262144 ) {
			return array( 'status' => 'invalid-size', 'data' => array() );
		}
		$data = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown
		if ( ! is_array( $data ) || $this->contains_forbidden_manifest_key( $data ) ) {
			return array( 'status' => 'invalid', 'data' => array() );
		}
		return array( 'status' => 'confirmed', 'data' => $data );
	}

	private function contains_forbidden_manifest_key( array $data ): bool {
		foreach ( $data as $key => $value ) {
			// The public theme manifest schema defines this boolean capability flag.
			// It describes the frontend runtime; it cannot contain executable code.
			if ( 'frontend_javascript' === (string) $key && is_bool( $value ) ) {
				continue;
			}
			if ( preg_match( '/(?:secret|password|credential|api[_-]?key|authorization|access[_-]?token|refresh[_-]?token|id[_-]?token|bearer[_-]?token|callback|javascript|prompt|external[_-]?url)/i', (string) $key ) ) {
				return true;
			}
			if ( is_array( $value ) && $this->contains_forbidden_manifest_key( $value ) ) {
				return true;
			}
		}
		return false;
	}

	private function ability_with_suffix( array $names, string $suffix ): ?string {
		foreach ( $names as $name ) {
			if ( str_ends_with( (string) $name, $suffix ) ) {
				return (string) $name;
			}
		}
		return null;
	}
}
