<?php
/* SmartCloud Agent Composer execution contract. */

namespace SmartCloud\AgentComposer\Execution;

/**
 * Discovers product-owned Abilities providers without depending on them.
 *
 * Provider plugins contribute data-only manifests through the
 * smartcloud_composer_execution_providers filter. Composer accepts a
 * provider only when every advertised ability is present in the live
 * WordPress Abilities registry.
 */
final class Ability_Provider_Registry {
	public const FILTER = 'smartcloud_composer_execution_providers';

	private const MAX_PROVIDERS = 20;
	private const MAX_ABILITIES_PER_PROVIDER = 30;
	private const MAX_NAMESPACES_PER_PROVIDER = 10;
	private const REQUIRED_ABILITY_SUFFIXES = array(
		'/get-runtime-capabilities',
		'/list-components',
		'/get-component-schema',
		'/materialize-component',
		'/validate-block-tree',
	);
	private const MANIFEST_KEYS = array(
		'ability_names',
		'block_namespaces',
		'contract_version',
		'id',
		'label',
		'mcp_ability_names',
		'plugin_version',
	);

	private ?array $providers = null;

	/**
	 * Clear the request-local provider cache.
	 */
	public function reset(): void {
		$this->providers = null;
	}

	/**
	 * Return validated manifests keyed by provider ID.
	 */
	public function all(): array {
		if ( null !== $this->providers ) {
			return $this->providers;
		}

		$this->providers = array();
		if ( ! function_exists( 'wp_has_ability' ) || ! function_exists( 'wp_get_ability' ) ) {
			return $this->providers;
		}

		$manifests = apply_filters( 'smartcloud_composer_execution_providers', array() );
		if ( ! is_array( $manifests ) ) {
			return $this->providers;
		}

		$count = 0;
		foreach ( $manifests as $key => $manifest ) {
			if ( ++$count > self::MAX_PROVIDERS ) {
				break;
			}
			$provider = $this->normalize_manifest( $key, $manifest );
			if ( null === $provider ) {
				continue;
			}
			$this->providers[ $provider['id'] ] = $provider;
		}

		ksort( $this->providers );
		return $this->providers;
	}

	/**
	 * Return safe provider data for diagnostics and agent discovery.
	 */
	public function public_manifests(): array {
		return array_values( $this->all() );
	}

	/**
	 * Return the deliberate provider ability subset for the single MCP server.
	 */
	public function mcp_ability_names(): array {
		$names = array();
		foreach ( $this->all() as $provider ) {
			$names = array_merge( $names, $provider['mcp_ability_names'] );
		}
		return array_values( array_unique( $names ) );
	}

	/**
	 * Resolve the one provider that claims a block namespace.
	 *
	 * @throws Execution_Exception When more than one provider claims a namespace.
	 */
	public function provider_for_block( string $block_name ): ?array {
		$namespace = $this->block_namespace( $block_name );
		if ( '' === $namespace || 'core/' === $namespace ) {
			return null;
		}

		$matches = array();
		foreach ( $this->all() as $provider ) {
			if ( in_array( $namespace, $provider['block_namespaces'], true ) ) {
				$matches[] = $provider;
			}
		}
		if ( count( $matches ) > 1 ) {
			throw new Execution_Exception(
				'provider_namespace_conflict',
				'More than one registered ability provider claims the same block namespace.'
			);
		}
		return $matches[0] ?? null;
	}

	/**
	 * Delegate product-domain validation to each owning provider.
	 */
	public function validate_block_trees( array $blocks ): array {
		$groups   = array();
		$errors   = array();
		$warnings = array();

		try {
			$this->collect_provider_roots( $blocks, null, $groups );
		} catch ( Execution_Exception $error ) {
			return array(
				'valid'    => false,
				'errors'   => array( $this->issue( $error->get_execution_code(), $error->getMessage() ) ),
				'warnings' => array(),
			);
		}

		$providers = $this->all();
		foreach ( $groups as $provider_id => $provider_blocks ) {
			$provider = $providers[ $provider_id ] ?? null;
			if ( ! is_array( $provider ) ) {
				$errors[] = $this->issue(
					'component_provider_unavailable',
					'A block component provider is not available.',
					$provider_id
				);
				continue;
			}

			$ability_name = $this->ability_with_suffix( $provider['ability_names'], '/validate-block-tree' );
			if ( null === $ability_name ) {
				$errors[] = $this->issue(
					'provider_validation_unavailable',
					'The component provider does not advertise its required validation ability.',
					$provider_id
				);
				continue;
			}

			$ability = wp_has_ability( $ability_name ) ? wp_get_ability( $ability_name ) : null;
			if ( ! is_object( $ability ) || ! method_exists( $ability, 'execute' ) ) {
				$errors[] = $this->issue(
					'provider_validation_unavailable',
					'The component provider validation ability is not registered.',
					$provider_id
				);
				continue;
			}

			$result = $ability->execute( array( 'blocks' => array_values( $provider_blocks ) ) );
			if ( is_wp_error( $result ) ) {
				$errors[] = $this->issue(
					'provider_validation_failed',
					'The component provider could not validate its block tree.',
					$provider_id
				);
				continue;
			}
			if (
				! is_array( $result )
				|| ! array_key_exists( 'valid', $result )
				|| ! is_bool( $result['valid'] )
				|| ! array_key_exists( 'errors', $result )
				|| ! is_array( $result['errors'] )
				|| ! array_key_exists( 'warnings', $result )
				|| ! is_array( $result['warnings'] )
			) {
				$errors[] = $this->issue(
					'invalid_provider_validation_result',
					'The component provider returned an invalid validation result.',
					$provider_id
				);
				continue;
			}

			foreach ( $result['errors'] as $issue ) {
				$errors[] = $this->provider_issue( $issue, $provider_id, false );
			}
			foreach ( $result['warnings'] as $issue ) {
				$warnings[] = $this->provider_issue( $issue, $provider_id, true );
			}
			if ( false === $result['valid'] && empty( $result['errors'] ) ) {
				$errors[] = $this->issue(
					'provider_block_tree_invalid',
					'The component provider rejected its block tree.',
					$provider_id
				);
			}
		}

		return array(
			'valid'    => empty( $errors ),
			'errors'   => array_values( $errors ),
			'warnings' => array_values( $warnings ),
		);
	}

	private function normalize_manifest( mixed $key, mixed $manifest ): ?array {
		if ( ! is_string( $key ) || ! is_array( $manifest ) ) {
			return null;
		}
		$manifest_keys = array_keys( $manifest );
		sort( $manifest_keys );
		if ( self::MANIFEST_KEYS !== $manifest_keys || ! $this->is_data_only( $manifest ) ) {
			return null;
		}

		$id = isset( $manifest['id'] ) && is_string( $manifest['id'] )
			? trim( $manifest['id'] )
			: '';
		if ( $key !== $id || ! preg_match( '/^[a-z0-9][a-z0-9-]{0,63}$/', $id ) ) {
			return null;
		}
		if (
			! is_string( $manifest['label'] )
			|| '' === trim( $manifest['label'] )
			|| ! is_string( $manifest['contract_version'] )
			|| ! is_string( $manifest['plugin_version'] )
		) {
			return null;
		}

		$ability_names = $this->ability_name_list( $manifest['ability_names'] ?? null );
		$mcp_names     = $this->ability_name_list( $manifest['mcp_ability_names'] ?? null );
		$namespaces    = $this->block_namespace_list( $manifest['block_namespaces'] ?? null );
		$contract      = $this->safe_version( $manifest['contract_version'] );
		$plugin        = $this->safe_version( $manifest['plugin_version'] );
		if (
			null === $ability_names
			|| null === $mcp_names
			|| null === $namespaces
			|| empty( $ability_names )
			|| empty( $namespaces )
			|| null === $contract
			|| null === $plugin
			|| array_diff( $mcp_names, $ability_names )
			|| ! $this->has_required_abilities( $id, $ability_names )
		) {
			return null;
		}

		foreach ( $ability_names as $ability_name ) {
			if ( ! $this->ability_is_registered( $ability_name ) ) {
				return null;
			}
		}

		return array(
			'id'                => $id,
			'label'             => substr( sanitize_text_field( (string) ( $manifest['label'] ?? $id ) ), 0, 100 ),
			'contract_version'  => $contract,
			'plugin_version'    => $plugin,
			'ability_names'     => $ability_names,
			'mcp_ability_names' => $mcp_names,
			'block_namespaces'  => $namespaces,
		);
	}

	private function ability_name_list( mixed $value ): ?array {
		if (
			! is_array( $value )
			|| ! array_is_list( $value )
			|| count( $value ) > self::MAX_ABILITIES_PER_PROVIDER
		) {
			return null;
		}

		$names = array();
		foreach ( $value as $name ) {
			$name = is_string( $name ) ? strtolower( trim( $name ) ) : '';
			if ( ! preg_match( '#^[a-z0-9][a-z0-9-]{0,63}/[a-z0-9][a-z0-9-]{0,63}$#', $name ) ) {
				return null;
			}
			$names[] = $name;
		}
		$names = array_values( array_unique( $names ) );
		return count( $names ) === count( $value ) ? $names : null;
	}

	private function block_namespace_list( mixed $value ): ?array {
		if (
			! is_array( $value )
			|| ! array_is_list( $value )
			|| count( $value ) > self::MAX_NAMESPACES_PER_PROVIDER
		) {
			return null;
		}

		$namespaces = array();
		foreach ( $value as $namespace ) {
			$namespace = is_string( $namespace ) ? strtolower( trim( $namespace ) ) : '';
			if ( ! preg_match( '#^[a-z0-9][a-z0-9-]{0,63}/$#', $namespace ) || 'core/' === $namespace ) {
				return null;
			}
			$namespaces[] = $namespace;
		}
		$namespaces = array_values( array_unique( $namespaces ) );
		return count( $namespaces ) === count( $value ) ? $namespaces : null;
	}

	private function ability_is_registered( string $ability_name ): bool {
		return wp_has_ability( $ability_name );
	}

	private function has_required_abilities( string $provider_id, array $ability_names ): bool {
		foreach ( self::REQUIRED_ABILITY_SUFFIXES as $suffix ) {
			if ( ! in_array( $provider_id . $suffix, $ability_names, true ) ) {
				return false;
			}
		}
		foreach ( $ability_names as $ability_name ) {
			if ( ! str_starts_with( $ability_name, $provider_id . '/' ) ) {
				return false;
			}
		}
		return true;
	}

	private function is_data_only( mixed $value ): bool {
		if ( is_null( $value ) || is_scalar( $value ) ) {
			return true;
		}
		if ( ! is_array( $value ) ) {
			return false;
		}
		foreach ( $value as $key => $item ) {
			if ( ! is_int( $key ) && ! is_string( $key ) ) {
				return false;
			}
			if ( ! $this->is_data_only( $item ) ) {
				return false;
			}
		}
		return true;
	}

	private function collect_provider_roots( array $blocks, ?string $owning_provider, array &$groups ): void {
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			$name     = (string) ( $block['blockName'] ?? '' );
			$provider = $this->provider_for_block( $name );
			$current  = $owning_provider;

			if ( is_array( $provider ) && $provider['id'] !== $owning_provider ) {
				$current = $provider['id'];
				$groups[ $current ][] = $block;
			} elseif ( is_array( $provider ) ) {
				$current = $provider['id'];
			}

			$children = is_array( $block['innerBlocks'] ?? null ) ? $block['innerBlocks'] : array();
			$this->collect_provider_roots( $children, $current, $groups );
		}
	}

	private function ability_with_suffix( array $ability_names, string $suffix ): ?string {
		foreach ( $ability_names as $ability_name ) {
			if ( str_ends_with( $ability_name, $suffix ) ) {
				return $ability_name;
			}
		}
		return null;
	}

	private function block_namespace( string $block_name ): string {
		$block_name = strtolower( trim( $block_name ) );
		if ( ! preg_match( '#^([a-z0-9][a-z0-9-]{0,63})/[a-z0-9][a-z0-9-]{0,63}$#', $block_name, $matches ) ) {
			return '';
		}
		return $matches[1] . '/';
	}

	private function safe_version( mixed $value ): ?string {
		$value = is_string( $value ) ? trim( $value ) : '';
		return preg_match( '/^[A-Za-z0-9][A-Za-z0-9._+-]{0,63}$/', $value ) ? $value : null;
	}

	private function provider_issue( mixed $issue, string $provider_id, bool $warning ): array {
		if ( ! is_array( $issue ) ) {
			return $this->issue(
				$warning ? 'provider_warning' : 'provider_validation_error',
				$warning ? 'The component provider returned a warning.' : 'The component provider rejected part of the block tree.',
				$provider_id
			);
		}

		$code    = sanitize_key( (string) ( $issue['code'] ?? ( $warning ? 'provider_warning' : 'provider_validation_error' ) ) );
		$message = substr( sanitize_text_field( (string) ( $issue['message'] ?? '' ) ), 0, 1000 );
		$result  = $this->issue(
			'' !== $code ? $code : ( $warning ? 'provider_warning' : 'provider_validation_error' ),
			'' !== $message ? $message : ( $warning ? 'The component provider returned a warning.' : 'The component provider rejected part of the block tree.' ),
			$provider_id
		);
		if ( isset( $issue['path'] ) && is_string( $issue['path'] ) ) {
			$result['path'] = substr( sanitize_text_field( $issue['path'] ), 0, 500 );
		}
		if ( $warning ) {
			$result['blocking'] = ! empty( $issue['blocking'] );
		}
		return $result;
	}

	private function issue( string $code, string $message, string $provider_id = '' ): array {
		$issue = array(
			'code'    => $code,
			'message' => $message,
		);
		if ( '' !== $provider_id ) {
			$issue['provider'] = $provider_id;
		}
		return $issue;
	}
}
