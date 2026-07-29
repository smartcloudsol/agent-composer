<?php
/* SmartCloud Agent Composer execution contract. */

namespace SmartCloud\AgentComposer\Execution;

/**
 * Read-only view of the active WordPress block registry.
 *
 * Product block schemas stay owned by their provider plugins. The Composer uses
 * the live registry only for generic WordPress relationship checks and applies
 * the active theme's extension allowlist on top.
 */
final class Block_Catalog {
	private Config_Repository $config;
	private Ability_Provider_Registry $providers;

	public function __construct( Config_Repository $config, Ability_Provider_Registry $providers ) {
		$this->config    = $config;
		$this->providers = $providers;
	}

	public function get( string $name ): array {
		$name = $this->normalize_name( $name );
		$types = $this->registered_types();
		if ( ! isset( $types[ $name ] ) || ! is_object( $types[ $name ] ) ) {
			throw new Execution_Exception( 'wpsuite_block_not_registered', 'The requested block is not registered on this WordPress site.' );
		}

		return $this->schema_from_type( $types[ $name ] );
	}

	public function is_registered( string $name ): bool {
		$name  = strtolower( trim( $name ) );
		$types = $this->registered_types();
		return isset( $types[ $name ] );
	}

	public function is_provider_block( string $name ): bool {
		return null !== $this->providers->provider_for_block( $name );
	}

	public function is_allowed( string $name, ?array $blueprint = null ): bool {
		$name       = strtolower( trim( $name ) );
		$policy     = $this->config->get_design_policy();
		$core       = str_starts_with( $name, 'core/' );
		$extensions = $this->config->get_block_extensions();

		if ( in_array( $name, $policy['disallowed_blocks'], true ) ) {
			return false;
		}

		// Every non-core block requires a registered, product-owned provider.
		// A live block registration or a theme namespace allowlist alone is not
		// enough to make the Composer reproduce product behavior.
		if ( ! $core && ! $this->is_provider_block( $name ) ) {
			return false;
		}

		if (
			$core
			&& in_array( $name, array( 'core/html', 'core/freeform' ), true )
			&& ! in_array( $name, $extensions['allowed_core_blocks'], true )
		) {
			return false;
		}
		if (
			'core/html' === $name
			&& empty( $extensions['core_html_javascript'] )
		) {
			return false;
		}
		if (
			'core/freeform' === $name
			&& empty( $extensions['passive_text_editor_html'] )
		) {
			return false;
		}

		$namespace = strstr( $name, '/', true );
		if (
			! $core
			&& (
				false === $namespace
				|| ! in_array( $namespace, $extensions['allowed_plugin_namespaces'], true )
			)
		) {
			return false;
		}

		/*
		 * A design-policy extension is a site-wide capability ceiling, not a
		 * blanket grant. Every selected blueprint must opt in to the exact
		 * block as well. This keeps JavaScript, Classic HTML, media, and product
		 * components limited to the content families that deliberately use
		 * them.
		 */
		if ( null !== $blueprint ) {
			if ( ! in_array( $name, $blueprint['allowed_blocks'], true ) ) {
				return false;
			}
			return $this->is_registered( $name );
		}

		if ( in_array( $name, $extensions['allowed_core_blocks'], true ) ) {
			return $this->is_registered( $name );
		}

		if (
			false !== $namespace
			&& in_array( $namespace, $extensions['allowed_plugin_namespaces'], true )
			&& $this->is_provider_block( $name )
			&& $this->is_registered( $name )
		) {
			return true;
		}

		// Standalone tree validation may need ordinary core children inside a
		// provider component. The final draft validator still applies the
		// selected blueprint before any write.
		return null === $blueprint
			&& $core
			&& $this->is_registered( $name );
	}

	public function defaults( string $name ): array {
		$schema   = $this->get( $name );
		$defaults = array();
		foreach ( $schema['attributes'] as $attribute => $definition ) {
			if ( is_array( $definition ) && array_key_exists( 'default', $definition ) ) {
				$defaults[ $attribute ] = $definition['default'];
			}
		}
		return $defaults;
	}

	private function registered_types(): array {
		if ( ! class_exists( '\\WP_Block_Type_Registry' ) ) {
			return array();
		}

		$registry = \WP_Block_Type_Registry::get_instance();
		$types    = is_object( $registry ) && method_exists( $registry, 'get_all_registered' )
			? $registry->get_all_registered()
			: array();
		return is_array( $types ) ? $types : array();
	}

	private function schema_from_type( object $type ): array {
		$name = $this->normalize_name( (string) ( $type->name ?? '' ) );
		$provider = $this->providers->provider_for_block( $name );

		return array(
			'name'             => $name,
			'provider'         => is_array( $provider )
				? $provider['id']
				: ( str_starts_with( $name, 'core/' ) ? 'wordpress' : '' ),
			'title'            => sanitize_text_field( (string) ( $type->title ?? $name ) ),
			'description'      => sanitize_text_field( (string) ( $type->description ?? '' ) ),
			'category'         => sanitize_key( (string) ( $type->category ?? '' ) ),
			'api_version'      => absint( $type->api_version ?? 0 ),
			'parent'           => $this->block_name_list( $type->parent ?? array() ),
			'ancestor'         => $this->block_name_list( $type->ancestor ?? array() ),
			'allowed_blocks'   => $this->block_name_list( $type->allowed_blocks ?? array() ),
			'attributes'       => $this->safe_value( is_array( $type->attributes ?? null ) ? $type->attributes : array() ),
			'supports'         => $this->safe_value( is_array( $type->supports ?? null ) ? $type->supports : array() ),
			'provides_context' => $this->safe_value( is_array( $type->provides_context ?? null ) ? $type->provides_context : array() ),
			'uses_context'     => $this->safe_value( is_array( $type->uses_context ?? null ) ? $type->uses_context : array() ),
			'dynamic'          => method_exists( $type, 'is_dynamic' ) ? (bool) $type->is_dynamic() : ! empty( $type->render_callback ),
		);
	}

	private function safe_value( array $value ): array {
		$encoded = wp_json_encode( $value );
		$decoded = is_string( $encoded ) ? json_decode( $encoded, true ) : null;
		return is_array( $decoded ) ? $decoded : array();
	}

	private function block_name_list( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$result = array();
		foreach ( $value as $name ) {
			$name = strtolower( trim( (string) $name ) );
			if ( preg_match( '#^[a-z0-9-]+/[a-z0-9-]+$#', $name ) ) {
				$result[] = $name;
			}
		}
		return array_values( array_unique( $result ) );
	}

	private function normalize_name( string $name ): string {
		$name = strtolower( trim( $name ) );
		if ( ! preg_match( '#^[a-z0-9-]+/[a-z0-9-]+$#', $name ) ) {
			throw new Execution_Exception( 'invalid_block_name', 'A valid namespace/block block name is required.' );
		}
		return $name;
	}
}
