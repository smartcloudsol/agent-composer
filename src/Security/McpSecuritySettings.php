<?php

namespace SmartCloud\AgentComposer\Security;

final class McpSecuritySettings {
	public const OPTION = 'smartcloud_composer_mcp_security';
	public const ROLES  = array( 'reader', 'contributor', 'publisher' );
	public const RESOURCE_SCOPE_NAMES = array( 'read', 'draft', 'propose', 'publish.request' );

	public function get(): array {
		$stored = get_option( self::OPTION, array() );
		return $this->normalize( is_array( $stored ) ? $stored : array() );
	}

	public function save( array $value ): array {
		$normalized = $this->normalize( $value );
		update_option( self::OPTION, $normalized, false );
		do_action( 'smartcloud_agent_composer_mcp_security_updated', $normalized );
		return $normalized;
	}

	public function provider_configuration( ?array $settings = null ): array {
		$settings = null === $settings ? $this->get() : $this->normalize( $settings );
		$provided = apply_filters( 'smartcloud_agent_composer_cognito_config', null, $settings );
		$provided = apply_filters( 'smartcloud_agent_composer_cognito_configuration', $provided, $settings );
		if ( is_array( $provided ) ) {
			$region = $this->region( $provided['region'] ?? '' );
			$pool   = $this->pool_id( $provided['user_pool_id'] ?? '' );
			if ( '' !== $region && '' !== $pool ) {
				return $this->resolved_configuration( $region, $pool, 'provider' );
			}
		}
		return $this->resolved_configuration( '', '', 'none' );
	}

	public function identity_configuration( ?array $settings = null ): array {
		$settings = null === $settings ? $this->get() : $this->normalize( $settings );
		$provided = $this->provider_configuration( $settings );
		if ( ! $settings['identity_provider']['prefer_manual'] && 'provider' === $provided['source'] ) {
			return $provided;
		}
		$manual = $settings['identity_provider']['manual'];
		$source = '' !== $manual['region'] && '' !== $manual['user_pool_id'] ? 'manual' : 'none';
		return $this->resolved_configuration(
			$manual['region'],
			$manual['user_pool_id'],
			$source
		);
	}

	public function public_status(): array {
		$settings = $this->get();
		$identity = $this->identity_configuration();
		$provided = $this->provider_configuration( $settings );
		$resource_scopes = $this->resource_bound_scopes( $settings );
		$access_ready = 'none' !== $identity['source']
			&& ! empty( $settings['group_roles'] )
			&& ( ! empty( $settings['clients'] ) || 'human-role' === $settings['unknown_client_policy'] )
			&& ( ! $settings['enforce_scopes'] || ! empty( $resource_scopes ) );
		return array(
			'identity_configured' => 'none' !== $identity['source'],
			'access_ready'        => $access_ready,
			'identity_source'     => $identity['source'],
			'region'              => $identity['region'],
			'user_pool_id'        => $identity['user_pool_id'],
			'issuer'              => $identity['issuer'],
			'jwks_url'            => $identity['jwks_url'],
			'provider_configured' => 'provider' === $provided['source'],
			'provider_region'     => $provided['region'],
			'provider_user_pool_id' => $provided['user_pool_id'],
			'group_role_count'    => count( $settings['group_roles'] ),
			'client_count'        => count( $settings['clients'] ),
			'enforce_scopes'      => $settings['enforce_scopes'],
			'oauth_resource_uri'  => $settings['oauth_resource_uri'],
			'audience_validation' => '' !== $settings['oauth_resource_uri'],
			'resource_bound_scopes' => $resource_scopes,
			'unknown_client_policy' => $settings['unknown_client_policy'],
			'approval_ttl_seconds'  => $settings['approval_ttl_seconds'],
		);
	}

	public function normalize( array $value ): array {
		$identity = is_array( $value['identity_provider'] ?? null ) ? $value['identity_provider'] : array();
		$manual   = is_array( $identity['manual'] ?? null ) ? $identity['manual'] : array();
		$groups   = array();
		foreach ( (array) ( $value['group_roles'] ?? array() ) as $group => $role ) {
			$group = trim( sanitize_text_field( (string) $group ) );
			$role  = sanitize_key( (string) $role );
			if ( '' !== $group && strlen( $group ) <= 128 && in_array( $role, self::ROLES, true ) ) {
				$groups[ $group ] = $role;
			}
		}
		$clients = array();
		foreach ( (array) ( $value['clients'] ?? array() ) as $client ) {
			if ( ! is_array( $client ) ) { continue; }
			$client_id = trim( sanitize_text_field( (string) ( $client['client_id'] ?? '' ) ) );
			$role      = sanitize_key( (string) ( $client['role_ceiling'] ?? 'reader' ) );
			if ( '' === $client_id || strlen( $client_id ) > 256 || ! in_array( $role, self::ROLES, true ) ) { continue; }
			$clients[] = array(
				'label'        => substr( sanitize_text_field( (string) ( $client['label'] ?? $client_id ) ), 0, 160 ),
				'client_id'    => $client_id,
				'role_ceiling' => $role,
				'scopes'       => $this->string_list( $client['scopes'] ?? array(), 32, 128 ),
			);
		}
		return array(
			'identity_provider' => array(
				'provider' => 'amazon-cognito',
				'prefer_manual' => true === ( $identity['prefer_manual'] ?? false ),
				'manual'   => array(
					'region'       => $this->region( $manual['region'] ?? '' ),
					'user_pool_id' => $this->pool_id( $manual['user_pool_id'] ?? '' ),
				),
			),
			'group_roles'          => $groups,
			'clients'              => $clients,
			'oauth_resource_uri'   => $this->resource_uri( $value['oauth_resource_uri'] ?? '' ),
			'enforce_scopes'       => true === ( $value['enforce_scopes'] ?? false ),
			'unknown_client_policy'=> in_array( (string) ( $value['unknown_client_policy'] ?? '' ), array( 'human-role', 'reader' ), true ) ? 'human-role' : 'deny',
			'approval_ttl_seconds' => min( 3600, max( 120, (int) ( $value['approval_ttl_seconds'] ?? 900 ) ) ),
		);
	}

	public function resource_bound_scopes( ?array $settings = null ): array {
		$settings = null === $settings ? $this->get() : $this->normalize( $settings );
		$resource = (string) ( $settings['oauth_resource_uri'] ?? '' );
		if ( '' === $resource ) { return array(); }
		return array_map(
			static fn( string $scope ): string => $resource . '/' . $scope,
			self::RESOURCE_SCOPE_NAMES
		);
	}

	private function region( mixed $value ): string {
		$value = strtolower( trim( (string) $value ) );
		return preg_match( '/^[a-z]{2}(?:-gov)?-[a-z]+-\d$/', $value ) ? $value : '';
	}

	private function pool_id( mixed $value ): string {
		$value = trim( (string) $value );
		return preg_match( '/^[a-z]{2}(?:-gov)?-[a-z]+-\d_[A-Za-z0-9]+$/', $value ) ? $value : '';
	}

	private function resource_uri( mixed $value ): string {
		$value = rtrim( trim( sanitize_text_field( (string) $value ) ), '/' );
		if ( '' === $value ) { return ''; }
		if ( strlen( $value ) > 220 || str_contains( $value, '|' ) ) { return ''; }
		$parts = parse_url( $value );
		if (
			! is_array( $parts )
			|| 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) )
			|| '' === (string) ( $parts['host'] ?? '' )
			|| isset( $parts['user'] )
			|| isset( $parts['pass'] )
			|| isset( $parts['query'] )
			|| isset( $parts['fragment'] )
		) {
			return '';
		}
		return $value;
	}

	private function resolved_configuration( string $region, string $pool, string $source ): array {
		$issuer = '';
		if ( '' !== $region && '' !== $pool ) {
			// phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- Cognito is the configured identity-provider boundary, not a plugin asset CDN.
			$issuer = sprintf( 'https://cognito-idp.%s.amazonaws.com/%s', $region, $pool );
		}
		return array(
			'region'       => $region,
			'user_pool_id' => $pool,
			'issuer'       => $issuer,
			'jwks_url'     => '' !== $issuer ? $issuer . '/.well-known/jwks.json' : '',
			'source'       => $source,
		);
	}

	private function string_list( mixed $value, int $limit, int $length ): array {
		$result = array();
		foreach ( array_slice( (array) $value, 0, $limit ) as $item ) {
			$item = trim( sanitize_text_field( (string) $item ) );
			if ( '' !== $item && strlen( $item ) <= $length ) { $result[] = $item; }
		}
		return array_values( array_unique( $result ) );
	}
}
