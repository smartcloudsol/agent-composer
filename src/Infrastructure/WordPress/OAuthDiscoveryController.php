<?php

namespace SmartCloud\AgentComposer\Infrastructure\WordPress;

use SmartCloud\AgentComposer\Integration\Mcp\ComposerMcpServer;
use SmartCloud\AgentComposer\Security\McpSecuritySettings;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class OAuthDiscoveryController {
	private const CACHE_SECONDS = 43200;

	public function __construct(
		private readonly McpSecuritySettings $settings,
		private readonly ?\Closure $authentication_required = null
	) {}

	public function hooks(): void {
		// Run before the REST bootstrap so resource-specific discovery paths below
		// /wp-json can still be answered as standards-based JSON documents.
		add_action( 'parse_request', array( $this, 'maybe_serve' ), 0 );
		add_filter( 'rest_post_dispatch', array( $this, 'add_authentication_challenge' ), 20, 3 );
	}

	public function maybe_serve(): void {
		$path = $this->request_path();
		$authorization_path = $this->site_path( '/.well-known/oauth-authorization-server' );
		$resource_paths = array(
			$this->site_path( '/.well-known/oauth-protected-resource' ),
			$this->site_path( '/.well-known/oauth-protected-resource' . ComposerMcpServer::HTTP_ENDPOINT ),
			$this->site_path( ComposerMcpServer::HTTP_ENDPOINT . '/.well-known/oauth-protected-resource' ),
		);

		if ( $authorization_path === $path ) {
			$this->serve( $this->authorization_server_metadata() );
		}
		if ( in_array( $path, $resource_paths, true ) ) {
			$this->serve( $this->protected_resource_metadata() );
		}
	}

	public function authorization_server_metadata(): array|WP_Error {
		$identity = $this->settings->identity_configuration();
		if ( 'none' === $identity['source'] ) {
			return $this->identity_unresolved_error();
		}

		$cognito = $this->cognito_metadata( $identity );
		if ( is_wp_error( $cognito ) ) {
			return $cognito;
		}
		$metadata_issuer = untrailingslashit( (string) ( $cognito['issuer'] ?? '' ) );
		$expected_issuer = untrailingslashit( (string) $identity['issuer'] );
		if ( '' === $metadata_issuer || ! hash_equals( $expected_issuer, $metadata_issuer ) ) {
			return new WP_Error(
				'smartcloud_composer_cognito_metadata_invalid',
				'Cognito OpenID metadata does not match the configured User Pool issuer.',
				array( 'status' => 503 )
			);
		}

		return array(
			// This is OAuth authorization-server metadata, not Cognito's OpenID
			// metadata. Its issuer must exactly match authorization_servers[0] in
			// the protected-resource document. Cognito remains the upstream token
			// issuer and Composer validates the returned access token itself.
			'issuer'                                => untrailingslashit( home_url( '/' ) ),
			'authorization_endpoint'                 => $cognito['authorization_endpoint'],
			'token_endpoint'                         => $cognito['token_endpoint'],
			'jwks_uri'                               => (string) $identity['jwks_url'],
			'response_types_supported'               => array( 'code' ),
			'grant_types_supported'                  => array( 'authorization_code', 'refresh_token' ),
			'code_challenge_methods_supported'       => array( 'S256' ),
			'token_endpoint_auth_methods_supported'  => array( 'none' ),
			'scopes_supported'                       => $this->composer_scopes(),
		);
	}

	public function protected_resource_metadata(): array|WP_Error {
		$identity = $this->settings->identity_configuration();
		if ( 'none' === $identity['source'] ) {
			return $this->identity_unresolved_error();
		}

		return array(
			'resource'                  => $this->resource_uri(),
			'authorization_servers'     => array( untrailingslashit( home_url( '/' ) ) ),
			'scopes_supported'          => $this->composer_scopes(),
			'bearer_methods_supported'  => array( 'header' ),
			'resource_documentation'    => home_url( '/wp-admin/admin.php?page=smartcloud-agent-composer' ),
		);
	}

	/**
	 * Return the upstream Cognito document for diagnostics and contract tests.
	 *
	 * This document is intentionally not served below the WordPress issuer. OIDC
	 * requires the discovery URL's issuer, the metadata `issuer`, and an ID
	 * token's `iss` claim to be identical. Protected-resource metadata therefore
	 * advertises Cognito itself as the second authorization server.
	 */
	public function openid_provider_metadata(): array|WP_Error {
		$identity = $this->settings->identity_configuration();
		if ( 'none' === $identity['source'] ) {
			return $this->identity_unresolved_error();
		}

		$cognito = $this->cognito_metadata( $identity );
		if ( is_wp_error( $cognito ) ) {
			return $cognito;
		}

		$metadata_issuer = untrailingslashit( (string) ( $cognito['issuer'] ?? '' ) );
		$expected_issuer = untrailingslashit( (string) $identity['issuer'] );
		if ( '' === $metadata_issuer || ! hash_equals( $expected_issuer, $metadata_issuer ) ) {
			return new WP_Error(
				'smartcloud_composer_cognito_metadata_invalid',
				'Cognito OpenID metadata does not match the configured User Pool issuer.',
				array( 'status' => 503 )
			);
		}

		foreach ( array( 'userinfo_endpoint', 'jwks_uri' ) as $key ) {
			$url = (string) ( $cognito[ $key ] ?? '' );
			if ( '' === $url || ! str_starts_with( $url, 'https://' ) || false === wp_http_validate_url( $url ) ) {
				return new WP_Error(
					'smartcloud_composer_cognito_metadata_invalid',
					'Cognito OpenID metadata does not contain valid HTTPS identity endpoints.',
					array( 'status' => 503 )
				);
			}
		}

		$provider_scopes = isset( $cognito['scopes_supported'] ) && is_array( $cognito['scopes_supported'] )
			? array_values( array_filter( $cognito['scopes_supported'], 'is_string' ) )
			: array();

		// Cognito is the real OpenID Provider, while this same-origin facade adds
		// the OAuth capabilities that its public App Client supports but Cognito's
		// discovery document does not advertise. Keeping both sets of scopes lets
		// ChatGPT use OIDC identity information and Composer resource scopes in one
		// authorization-code request.
		return array_merge(
			$cognito,
			array(
				'grant_types_supported'                 => array( 'authorization_code', 'refresh_token' ),
				'code_challenge_methods_supported'       => array( 'S256' ),
				'token_endpoint_auth_methods_supported'  => array( 'none' ),
				'scopes_supported'                       => array_values( array_unique( array_merge( $provider_scopes, $this->composer_scopes() ) ) ),
			)
		);
	}

	public function add_authentication_challenge( mixed $response, mixed $server, WP_REST_Request $request ): mixed {
		unset( $server );
		if (
			! $response instanceof WP_REST_Response
			|| 401 !== $response->get_status()
			|| '/mcp/' . ComposerMcpServer::SERVER_ID !== untrailingslashit( $request->get_route() )
		) {
			return $response;
		}

		$response->header(
			'WWW-Authenticate',
			sprintf( 'Bearer resource_metadata="%s"', esc_url_raw( home_url( '/.well-known/oauth-protected-resource' ) ) )
		);
		$response->header( 'Access-Control-Expose-Headers', 'WWW-Authenticate' );
		return $response;
	}

	private function cognito_metadata( array $identity ): array|WP_Error {
		$filtered = apply_filters( 'smartcloud_agent_composer_cognito_oidc_metadata', null, $identity );
		if ( is_array( $filtered ) ) {
			return $this->validate_cognito_metadata( $filtered );
		}

		$cache_key = 'smartcloud_composer_oidc_' . sha1( (string) $identity['issuer'] );
		$cached = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $this->validate_cognito_metadata( $cached );
		}

		$response = wp_remote_get(
			$identity['issuer'] . '/.well-known/openid-configuration',
			array(
				'timeout'            => 5,
				'redirection'        => 2,
				'reject_unsafe_urls' => true,
				'headers'            => array( 'Accept' => 'application/json' ),
			)
		);
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return new WP_Error(
				'smartcloud_composer_cognito_metadata_unavailable',
				'Cognito OpenID metadata could not be loaded.',
				array( 'status' => 503 )
			);
		}

		$metadata = json_decode( wp_remote_retrieve_body( $response ), true );
		$metadata = is_array( $metadata ) ? $metadata : array();
		$validated = $this->validate_cognito_metadata( $metadata );
		if ( ! is_wp_error( $validated ) ) {
			set_transient( $cache_key, $validated, self::CACHE_SECONDS );
		}
		return $validated;
	}

	private function validate_cognito_metadata( array $metadata ): array|WP_Error {
		foreach ( array( 'authorization_endpoint', 'token_endpoint' ) as $key ) {
			$url = (string) ( $metadata[ $key ] ?? '' );
			if ( '' === $url || ! str_starts_with( $url, 'https://' ) || false === wp_http_validate_url( $url ) ) {
				return new WP_Error(
					'smartcloud_composer_cognito_metadata_invalid',
					'Cognito OpenID metadata does not contain valid HTTPS OAuth endpoints.',
					array( 'status' => 503 )
				);
			}
		}
		return $metadata;
	}

	private function composer_scopes(): array {
		// The deployed Cognito flow needs openid for its group context. Custom
		// scopes are advertised only when they are bound to the exact external MCP
		// resource and Composer is configured to enforce them.
		$settings = $this->settings->get();
		$scopes = array( 'openid' );
		if ( true === $settings['enforce_scopes'] ) {
			$scopes = array_merge( $scopes, $this->settings->resource_bound_scopes( $settings ) );
		}
		return $scopes;
	}

	private function resource_uri(): string {
		$configured = (string) ( $this->settings->get()['oauth_resource_uri'] ?? '' );
		return '' !== $configured ? $configured : home_url( ComposerMcpServer::HTTP_ENDPOINT );
	}

	private function identity_unresolved_error(): WP_Error {
		$authentication_required = null === $this->authentication_required
			|| true === ( $this->authentication_required )();
		return new WP_Error(
			'smartcloud_composer_identity_unresolved',
			'The Composer Cognito identity provider is not configured.',
			array( 'status' => $authentication_required ? 503 : 404 )
		);
	}

	private function serve( array|WP_Error $metadata ): never {
		$status = 200;
		if ( is_wp_error( $metadata ) ) {
			$data = $metadata->get_error_data();
			$status = is_array( $data ) ? (int) ( $data['status'] ?? 503 ) : 503;
			$metadata = array(
				'error'             => $metadata->get_error_code(),
				'error_description' => $metadata->get_error_message(),
			);
		}

		status_header( $status );
		header( 'Content-Type: application/json; charset=UTF-8' );
		header( 'Access-Control-Allow-Origin: *' );
		header( 'Cache-Control: public, max-age=300' );
		echo wp_json_encode( $metadata, JSON_UNESCAPED_SLASHES );
		exit;
	}

	private function request_path(): string {
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_url( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) : '';
		$path = wp_parse_url( $request_uri, PHP_URL_PATH );
		return is_string( $path ) ? untrailingslashit( $path ) : '';
	}

	private function site_path( string $path ): string {
		$site_path = wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$site_path = is_string( $site_path ) ? '/' . trim( $site_path, '/' ) : '';
		return untrailingslashit( ( '/' === $site_path ? '' : $site_path ) . '/' . ltrim( $path, '/' ) );
	}
}
