<?php

declare(strict_types=1);

namespace {
	$options = array();
	$filters = array();
	class WP_Error {
		public function __construct( public string $code = '', public string $message = '', public mixed $data = null ) {}
		public function get_error_code(): string { return $this->code; }
		public function get_error_message(): string { return $this->message; }
		public function get_error_data(): mixed { return $this->data; }
	}
	class WP_REST_Request { public function get_route(): string { return '/mcp/smartcloud-agent-composer'; } }
	class WP_REST_Response {
		public array $headers = array();
		public function __construct( private int $status = 200 ) {}
		public function get_status(): int { return $this->status; }
		public function header(string $name, string $value): void { $this->headers[$name] = $value; }
	}
	function get_option(string $key, mixed $default = false): mixed { global $options; return $options[$key] ?? $default; }
	function sanitize_text_field(string $value): string { return trim(strip_tags($value)); }
	function sanitize_key(string $value): string { return strtolower((string) preg_replace('/[^a-z0-9_\-]/i', '', $value)); }
	function apply_filters(string $name, mixed $value, mixed ...$args): mixed {
		global $filters;
		foreach ($filters[$name] ?? array() as $callback) $value = $callback($value, ...$args);
		return $value;
	}
	function add_filter(string $name, callable $callback, int $priority = 10, int $accepted = 1): void { global $filters; $filters[$name][] = $callback; }
	function home_url(string $path = ''): string { return 'https://staging.example.test' . $path; }
	function untrailingslashit(string $value): string { return rtrim($value, '/'); }
	function wp_http_validate_url(string $url): string|false { return str_starts_with($url, 'https://') ? $url : false; }
	function is_wp_error(mixed $value): bool { return $value instanceof WP_Error; }
	function esc_url_raw(string $value): string { return $value; }

	function discovery_assert(bool $condition, string $message): void {
		if (!$condition) { fwrite(STDERR, $message . PHP_EOL); exit(1); }
	}

	require_once dirname(__DIR__) . '/src/Integration/Mcp/ComposerMcpServer.php';
	require_once dirname(__DIR__) . '/src/Security/McpSecuritySettings.php';
	require_once dirname(__DIR__) . '/src/Infrastructure/WordPress/OAuthDiscoveryController.php';

	$open_controller = new \SmartCloud\AgentComposer\Infrastructure\WordPress\OAuthDiscoveryController(
		new \SmartCloud\AgentComposer\Security\McpSecuritySettings(),
		static fn(): bool => false
	);
	foreach ( array(
		$open_controller->authorization_server_metadata(),
		$open_controller->protected_resource_metadata(),
		$open_controller->openid_provider_metadata(),
	) as $missing_metadata ) {
		discovery_assert(is_wp_error($missing_metadata), 'Unconfigured Open mode must not advertise OAuth metadata.');
		discovery_assert(404 === ($missing_metadata->get_error_data()['status'] ?? 0), 'Unconfigured Open mode discovery must return 404 so a no-auth HTTP tunnel can become ready.');
	}

	$required_controller = new \SmartCloud\AgentComposer\Infrastructure\WordPress\OAuthDiscoveryController(
		new \SmartCloud\AgentComposer\Security\McpSecuritySettings(),
		static fn(): bool => true
	);
	$required_metadata = $required_controller->protected_resource_metadata();
	discovery_assert(is_wp_error($required_metadata), 'Incomplete Protected Required mode must fail discovery closed.');
	discovery_assert(503 === ($required_metadata->get_error_data()['status'] ?? 0), 'Incomplete Protected Required mode discovery must remain a service configuration error.');

	add_filter('smartcloud_agent_composer_cognito_config', static fn(): array => array(
		'region' => 'eu-central-1',
		'user_pool_id' => 'eu-central-1_Example1',
	));
	add_filter('smartcloud_agent_composer_cognito_oidc_metadata', static fn(): array => array(
		'issuer' => 'https://cognito-idp.eu-central-1.amazonaws.com/eu-central-1_Example1',
		'authorization_endpoint' => 'https://tenant.auth.eu-central-1.amazoncognito.com/oauth2/authorize',
		'token_endpoint' => 'https://tenant.auth.eu-central-1.amazoncognito.com/oauth2/token',
		'userinfo_endpoint' => 'https://tenant.auth.eu-central-1.amazoncognito.com/oauth2/userInfo',
		'jwks_uri' => 'https://cognito-idp.eu-central-1.amazonaws.com/eu-central-1_Example1/.well-known/jwks.json',
		'scopes_supported' => array('openid', 'email', 'profile'),
		'subject_types_supported' => array('public'),
		'id_token_signing_alg_values_supported' => array('RS256'),
	));

	$controller = new \SmartCloud\AgentComposer\Infrastructure\WordPress\OAuthDiscoveryController(
		new \SmartCloud\AgentComposer\Security\McpSecuritySettings()
	);
	$authorization = $controller->authorization_server_metadata();
	discovery_assert(!is_wp_error($authorization), 'Authorization-server metadata must resolve from Cognito configuration.');
	discovery_assert(array('S256') === $authorization['code_challenge_methods_supported'], 'OAuth metadata must explicitly advertise PKCE S256.');
	discovery_assert(array('none') === $authorization['token_endpoint_auth_methods_supported'], 'The public App Client must advertise no client-secret authentication.');
	discovery_assert(in_array('refresh_token', $authorization['grant_types_supported'], true), 'OAuth metadata must advertise the Cognito refresh-token grant used to retain the connection.');
	discovery_assert('https://staging.example.test' === $authorization['issuer'], 'Authorization-server metadata issuer must exactly match the protected-resource authorization server.');
	discovery_assert(str_ends_with($authorization['jwks_uri'], '/.well-known/jwks.json'), 'Authorization-server metadata must advertise the configured Cognito JWKS document.');
	discovery_assert(array('openid') === $authorization['scopes_supported'], 'OAuth metadata must request openid without advertising custom scopes that are not bound to the protected resource.');

	$resource = $controller->protected_resource_metadata();
	discovery_assert(!is_wp_error($resource), 'Protected-resource metadata must be available when Cognito is configured.');
	discovery_assert('https://staging.example.test/wp-json/mcp/smartcloud-agent-composer' === $resource['resource'], 'Protected-resource metadata must identify the exact Composer MCP endpoint.');
	discovery_assert('https://staging.example.test' === $resource['authorization_servers'][0], 'Tunnel discovery must start from the WordPress metadata facade.');
	discovery_assert(1 === count($resource['authorization_servers']), 'Composer must advertise one OAuth authorization server and must not opt the connector into OIDC discovery.');
	discovery_assert($authorization['scopes_supported'] === $resource['scopes_supported'], 'Protected-resource and authorization-server metadata must advertise the same Composer scopes.');

	$openid = $controller->openid_provider_metadata();
	discovery_assert(!is_wp_error($openid), 'OpenID Provider metadata must resolve from the configured Cognito User Pool.');
	discovery_assert('https://cognito-idp.eu-central-1.amazonaws.com/eu-central-1_Example1' === $openid['issuer'], 'The OpenID facade must preserve Cognito as the real token issuer.');
	discovery_assert('https://tenant.auth.eu-central-1.amazoncognito.com/oauth2/userInfo' === $openid['userinfo_endpoint'], 'OpenID metadata must advertise Cognito userinfo so ChatGPT can enable its OIDC support.');
	discovery_assert(array('S256') === $openid['code_challenge_methods_supported'], 'The selected OpenID document must advertise PKCE S256 for ChatGPT public clients.');
	discovery_assert(array('none') === $openid['token_endpoint_auth_methods_supported'], 'The selected OpenID document must advertise public-client token exchange.');
	discovery_assert(array('openid', 'email', 'profile') === $openid['scopes_supported'], 'The OpenID document must preserve provider identity scopes without advertising unbound Composer custom scopes.');

	$options[\SmartCloud\AgentComposer\Security\McpSecuritySettings::OPTION] = array(
		'oauth_resource_uri' => 'https://tunnel-service.example.test/v1/mcp/tunnel_example',
		'enforce_scopes' => true,
	);
	$bound_controller = new \SmartCloud\AgentComposer\Infrastructure\WordPress\OAuthDiscoveryController(
		new \SmartCloud\AgentComposer\Security\McpSecuritySettings()
	);
	$bound_resource = $bound_controller->protected_resource_metadata();
	$bound_scopes = array(
		'openid',
		'https://tunnel-service.example.test/v1/mcp/tunnel_example/read',
		'https://tunnel-service.example.test/v1/mcp/tunnel_example/draft',
		'https://tunnel-service.example.test/v1/mcp/tunnel_example/propose',
		'https://tunnel-service.example.test/v1/mcp/tunnel_example/publish.request',
	);
	discovery_assert('https://tunnel-service.example.test/v1/mcp/tunnel_example' === $bound_resource['resource'], 'Protected-resource metadata must advertise the configured external tunnel resource URI.');
	discovery_assert($bound_scopes === $bound_resource['scopes_supported'], 'Protected-resource metadata must advertise exact resource-bound scopes when enforcement is enabled.');
	discovery_assert($bound_scopes === $bound_controller->authorization_server_metadata()['scopes_supported'], 'Authorization-server metadata must advertise the same resource-bound scopes.');

	$response = new WP_REST_Response(401);
	$controller->add_authentication_challenge($response, null, new WP_REST_Request());
	discovery_assert(str_contains($response->headers['WWW-Authenticate'] ?? '', 'oauth-protected-resource'), 'A protected MCP 401 response must advertise its resource metadata.');
	discovery_assert('WWW-Authenticate' === ($response->headers['Access-Control-Expose-Headers'] ?? ''), 'Browser MCP clients must be able to read the authentication challenge.');

	echo "mcp-oauth-discovery-contract: ok\n";
}
