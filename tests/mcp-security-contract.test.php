<?php

declare(strict_types=1);

namespace {
	$options = array();
	$filters = array();
	$actions = array();
	class WP_Error {
		public function __construct( public string $code = '', public string $message = '', public mixed $data = null ) {}
		public function get_error_code(): string { return $this->code; }
		public function get_error_message(): string { return $this->message; }
	}
	class WP_User { public string $user_email = ''; }
	function get_option(string $key, mixed $default = false): mixed { global $options; return $options[$key] ?? $default; }
	function update_option(string $key, mixed $value, bool $autoload = false): bool { global $options; $options[$key] = $value; return true; }
	function sanitize_text_field(string $value): string { return trim(strip_tags($value)); }
	function wp_unslash(string $value): string { return stripslashes($value); }
	function sanitize_key(string $value): string { return strtolower((string) preg_replace('/[^a-z0-9_\-]/i', '', $value)); }
	function sanitize_email(string $value): string { return filter_var($value, FILTER_SANITIZE_EMAIL) ?: ''; }
	function get_current_user_id(): int { return 0; }
	function wp_get_current_user(): WP_User { return new WP_User(); }
	function is_wp_error(mixed $value): bool { return $value instanceof WP_Error; }
	function add_filter(string $name, callable $callback, int $priority = 10, int $accepted = 1): void { global $filters; $filters[$name][] = $callback; }
	function do_action(string $name, mixed ...$args): void { global $actions; $actions[$name][] = $args; }
	function apply_filters(string $name, mixed $value, mixed ...$args): mixed {
		global $filters;
		foreach ($filters[$name] ?? array() as $callback) $value = $callback($value, ...$args);
		return $value;
	}

	function security_assert(bool $condition, string $message): void {
		if (!$condition) { fwrite(STDERR, $message . PHP_EOL); exit(1); }
	}

	require_once dirname(__DIR__) . '/src/Execution/Execution_Exception.php';
	require_once dirname(__DIR__) . '/src/Execution/Config_Repository.php';
	require_once dirname(__DIR__) . '/src/Execution/Abilities.php';
	require_once dirname(__DIR__) . '/src/Infrastructure/WordPress/Activation.php';
	require_once dirname(__DIR__) . '/src/Integration/Mcp/ComposerMcpServer.php';
	require_once dirname(__DIR__) . '/src/Security/ActorContext.php';
	require_once dirname(__DIR__) . '/src/Security/ActorIdentity.php';
	require_once dirname(__DIR__) . '/src/Security/McpSecuritySettings.php';
	require_once dirname(__DIR__) . '/src/Security/CognitoJwtValidator.php';
	require_once dirname(__DIR__) . '/src/Security/McpAccessGuard.php';

	$settings = new \SmartCloud\AgentComposer\Security\McpSecuritySettings();
	$normalized = $settings->normalize(array(
		'identity_provider' => array('manual' => array('region' => 'eu-central-1', 'user_pool_id' => 'eu-central-1_Example1')),
		'group_roles' => array('editors' => 'contributor', 'admins' => 'publisher', '' => 'reader'),
		'clients' => array(array('label' => 'ChatGPT', 'client_id' => 'client-1', 'role_ceiling' => 'contributor', 'scopes' => array('composer.read', 'composer.draft'))),
		'oauth_resource_uri' => 'https://tunnel-service.example.test/v1/mcp/tunnel_example/',
		'approval_ttl_seconds' => 20,
	));
	security_assert(120 === $normalized['approval_ttl_seconds'], 'Approval TTL must be clamped to the safe minimum.');
	security_assert(2 === count($normalized['group_roles']), 'Only valid bounded group mappings may survive normalization.');
	security_assert(false === $normalized['identity_provider']['prefer_manual'], 'Automatic provider discovery must remain the default.');
	security_assert('https://tunnel-service.example.test/v1/mcp/tunnel_example' === $normalized['oauth_resource_uri'], 'The external resource URI must be normalized without a trailing slash.');
	security_assert(
		array('https://tunnel-service.example.test/v1/mcp/tunnel_example/read', 'https://tunnel-service.example.test/v1/mcp/tunnel_example/draft', 'https://tunnel-service.example.test/v1/mcp/tunnel_example/propose', 'https://tunnel-service.example.test/v1/mcp/tunnel_example/publish.request') === $settings->resource_bound_scopes($normalized),
		'Resource-bound scopes must be derived from the exact external resource URI.'
	);
	add_filter('smartcloud_agent_composer_cognito_config', static fn(): array => array('region' => 'eu-central-1', 'user_pool_id' => 'eu-central-1_Provided1'));
	$status = $settings->public_status();
	security_assert(true === $status['provider_configured'], 'A valid provider configuration must be reported to the administrator.');
	security_assert(false === $status['access_ready'], 'Identity alone must not report protected access as ready without group and client policy.');
	security_assert('eu-central-1_Provided1' === $status['user_pool_id'], 'The effective resolved User Pool must be visible.');
	security_assert(str_ends_with($status['jwks_url'], '/.well-known/jwks.json'), 'The resolved JWKS URL must be visible.');

	$config = new \SmartCloud\AgentComposer\Execution\Config_Repository();
	$guard = new \SmartCloud\AgentComposer\Security\McpAccessGuard($settings, new \SmartCloud\AgentComposer\Security\CognitoJwtValidator(), $config);
	security_assert('OPEN' === $guard->mode(), 'A fresh installation must remain backward-compatible Open mode.');
	security_assert(true === $guard->authorize_ability('smartcloud-agent-composer/create-content-draft'), 'Open mode must permit non-publishing draft operations.');
	security_assert(is_wp_error($guard->authorize_ability('smartcloud-agent-composer/request-publish')), 'Open mode must deny publish requests.');
	security_assert(is_wp_error($guard->authorize_ability('smartcloud-agent-composer/upload-media-asset')), 'Open mode must deny public Media Library publication.');
	security_assert('forbidden' === $guard->capability_for_name('smartcloud-agent-composer/publish'), 'Direct publish tools must always classify as forbidden.');
	security_assert('publish_media' === $guard->capability_for_name('smartcloud-agent-composer/upload-media-asset'), 'Governed media publication must use its dedicated Publisher capability boundary.');
	security_assert('read' === $guard->capability_for_name('smartcloud-static-publisher/list-targets'), 'Publisher target discovery must use the read capability boundary.');
	security_assert('read' === $guard->capability_for_name('smartcloud-static-publisher/list-content-sync-rules'), 'Publisher rule discovery must use the read capability boundary.');
	security_assert('read' === $guard->capability_for_name('smartcloud-static-publisher/get-job-status'), 'Publisher job status must use the read capability boundary.');

	$settings->save($normalized);
	$protected = new \SmartCloud\AgentComposer\Security\McpAccessGuard($settings, new \SmartCloud\AgentComposer\Security\CognitoJwtValidator(), $config);
	$protected->register_hooks();
	security_assert(isset($filters['mcp_adapter_tool_call_result']), 'Composer must register post-execution MCP auditing on the Adapter result hook.');
	security_assert('PROTECTED' === $protected->mode(), 'Identity configuration plus group mappings must activate Protected mode.');
	security_assert(is_wp_error($protected->transport_permission(null)), 'Protected mode must reject a request without a bearer token.');
	$denials = $actions['smartcloud_agent_composer_mcp_access_denied'] ?? array();
	$last_denial = end($denials);
	$denial = is_array($last_denial) ? ($last_denial[1] ?? array()) : array();
	security_assert(false === ($denial['bearer_present'] ?? null), 'Authentication diagnostics may report only whether a Bearer header exists.');
	security_assert(!array_key_exists('authorization', $denial) && !array_key_exists('token', $denial), 'Authentication diagnostics must never expose bearer material.');
	$set_actor = new ReflectionMethod($protected, 'set_actor');
	$set_actor->setAccessible(true);
	$set_actor->invoke($protected, new \SmartCloud\AgentComposer\Security\ActorContext('cognito:reader', 'issuer', 'sub', '', array('readers'), 'client-1', array('composer.read'), 'reader', 'PROTECTED', 'cognito'));
	security_assert(true === $protected->authorize_ability('smartcloud-agent-composer/get-contract'), 'Reader must be able to inspect contracts.');
	security_assert(is_wp_error($protected->authorize_ability('smartcloud-agent-composer/create-content-draft')), 'Reader must not create drafts.');
	security_assert(is_wp_error($protected->authorize_ability('smartcloud-agent-composer/upload-media-asset')), 'Reader must not publish Media Library assets.');
	security_assert(is_wp_error($protected->authorize_ability('smartcloud-agent-composer/inspect-publishable-draft')), 'Reader must not inspect another principal\'s draft through the Publisher handoff boundary.');
	$canonical_scopes = new ReflectionMethod($protected, 'canonical_scopes');
	$canonical_scopes->setAccessible(true);
	security_assert(array('composer.read') === $canonical_scopes->invoke($protected, array('https://tunnel-service.example.test/v1/mcp/tunnel_example/read'), $normalized['oauth_resource_uri']), 'Only a scope bound to the configured resource must normalize to the Composer canonical name.');
	security_assert(array() === $canonical_scopes->invoke($protected, array('composer/read'), $normalized['oauth_resource_uri']), 'A legacy scope from another resource server must not normalize for a resource-bound token.');
	$audience_matches = new ReflectionMethod(new \SmartCloud\AgentComposer\Security\CognitoJwtValidator(), 'audience_matches');
	$audience_matches->setAccessible(true);
	security_assert(true === $audience_matches->invoke(new \SmartCloud\AgentComposer\Security\CognitoJwtValidator(), $normalized['oauth_resource_uri'], $normalized['oauth_resource_uri']), 'The exact string audience must be accepted.');
	security_assert(true === $audience_matches->invoke(new \SmartCloud\AgentComposer\Security\CognitoJwtValidator(), array('another', $normalized['oauth_resource_uri']), $normalized['oauth_resource_uri']), 'The exact audience may appear in an audience array.');
	security_assert(false === $audience_matches->invoke(new \SmartCloud\AgentComposer\Security\CognitoJwtValidator(), 'https://other.example.test/mcp', $normalized['oauth_resource_uri']), 'A different audience must be rejected.');
	$groups_from_claims = new ReflectionMethod($protected, 'groups_from_claims');
	$groups_from_claims->setAccessible(true);
	security_assert(
		array('editors', 'admin') === $groups_from_claims->invoke($protected, array('cognito:groups' => array('editors')), array('composer/read', 'sc.group.admin')),
		'Legacy WP Suite group scopes must supplement Cognito group claims without replacing them.'
	);
	$diagnostics_from_claims = new ReflectionMethod($protected, 'diagnostics_from_claims');
	$diagnostics_from_claims->setAccessible(true);
	$diagnostics = $diagnostics_from_claims->invoke(
		$protected,
		array(
			'sub' => 'must-not-be-recorded',
			'email' => 'must-not-be-recorded@example.com',
			'client_id' => 'client',
			'cognito:groups' => array('editors'),
			'scope' => 'composer/read sc.group.admin',
		),
		array('composer/read', 'sc.group.admin'),
		array('editors', 'admin')
	);
	security_assert(array('client_id', 'cognito:groups', 'email', 'scope', 'sub') === $diagnostics['claim_names'], 'Authorization diagnostics must list only claim names.');
	security_assert('array' === $diagnostics['cognito_groups_claim_type'], 'Authorization diagnostics must expose the group claim shape.');
	security_assert(array('editors', 'admin') === $diagnostics['resolved_groups'], 'Authorization diagnostics must expose only resolved group names.');
	security_assert(array('admin') === $diagnostics['scope_groups'], 'Authorization diagnostics must expose group-derived scope names.');
	security_assert(array('composer/read', 'sc.group.admin') === $diagnostics['granted_scopes'], 'Authorization diagnostics must expose granted scope names.');
	security_assert('client' === $diagnostics['client_id'], 'Authorization diagnostics must expose the public OAuth client ID.');
	security_assert(hash('sha256', 'must-not-be-recorded') === $diagnostics['principal_subject_sha256'], 'Authorization diagnostics must hash the principal subject.');
	security_assert(!in_array('must-not-be-recorded', $diagnostics, true), 'Authorization diagnostics must not persist subject or other claim values.');
	$bearer_diagnostics = new ReflectionMethod($protected, 'bearer_diagnostics');
	$bearer_diagnostics->setAccessible(true);
	$bearer_shape = $bearer_diagnostics->invoke($protected, 'header.payload.signature');
	security_assert(hash('sha256', 'header.payload.signature') === $bearer_shape['bearer_sha256'], 'Bearer diagnostics must expose only a one-way fingerprint.');
	security_assert(strlen('header.payload.signature') === $bearer_shape['bearer_length'], 'Bearer diagnostics must expose the received credential length.');
	security_assert(!in_array('header.payload.signature', $bearer_shape, true), 'Bearer diagnostics must never persist the credential itself.');
	$set_actor->invoke($protected, new \SmartCloud\AgentComposer\Security\ActorContext('cognito:contributor', 'issuer', 'sub1', '', array('editors'), 'client-1', array('composer.read', 'composer.draft', 'composer.propose', 'composer.publish.request'), 'contributor', 'PROTECTED', 'cognito'));
	security_assert(is_wp_error($protected->authorize_ability('smartcloud-agent-composer/upload-media-asset')), 'Contributor must not publish new Media Library assets even when the token carries the shared publication scope.');
	$contributor_caps = $protected->grant_request_capabilities(array(), array(), array(), new WP_User());
	security_assert(! isset($contributor_caps[\SmartCloud\AgentComposer\Infrastructure\WordPress\Activation::CAP_PUBLISH_MEDIA]), 'Contributor requests must not receive the media-publication capability.');
	$set_actor->invoke($protected, new \SmartCloud\AgentComposer\Security\ActorContext('cognito:publisher', 'issuer', 'sub2', '', array('admins'), 'internal', array('composer.read', 'composer.draft', 'composer.propose', 'composer.publish.request'), 'publisher', 'PROTECTED', 'cognito'));
	security_assert(true === $protected->authorize_ability('smartcloud-agent-composer/request-publish'), 'Publisher may request human approval.');
	security_assert(true === $protected->authorize_ability('smartcloud-agent-composer/inspect-publishable-draft'), 'Publisher may inspect another principal\'s Composer draft at the read-only publication boundary.');
	security_assert(true === $protected->authorize_ability('smartcloud-agent-composer/open-publish-approval'), 'The authenticated Publisher may open the app-only approval session.');
	security_assert(true === $protected->authorize_ability('smartcloud-agent-composer/decide-publish-approval'), 'The authenticated Publisher may use the app-only decision helper.');
	security_assert(true === $protected->authorize_ability('smartcloud-agent-composer/get-publish-approval-asset'), 'The authenticated Publisher may load app-only approval assets.');
	security_assert(true === $protected->authorize_ability('smartcloud-agent-composer/upload-media-asset'), 'The authenticated Publisher may publish a governed Media Library asset under the publication scope.');
	$publisher_caps = $protected->grant_request_capabilities(array(), array(), array(), new WP_User());
	security_assert(true === ($publisher_caps[\SmartCloud\AgentComposer\Infrastructure\WordPress\Activation::CAP_PUBLISH_MEDIA] ?? false), 'Only the authenticated Publisher request must receive the narrow media-publication capability.');
	security_assert(is_wp_error($protected->authorize_ability('smartcloud-agent-composer/publish')), 'Publisher must still be denied direct publication.');
	$tool_result = array('success' => true, 'value' => 'unchanged');
	$composer_server = new class {
		public function get_server_id(): string { return \SmartCloud\AgentComposer\Integration\Mcp\ComposerMcpServer::SERVER_ID; }
	};
	security_assert($tool_result === $protected->filter_tool_result($tool_result, array(), 'smartcloud-agent-composer/get-contract', new \stdClass(), $composer_server), 'MCP audit logging must not mutate successful tool results.');

	$abilities_source = file_get_contents(dirname(__DIR__) . '/src/Execution/Abilities.php');
	$approval_source = file_get_contents(dirname(__DIR__) . '/src/Infrastructure/WordPress/PublishApprovalController.php');
	security_assert(str_contains($abilities_source, "self::PREFIX . 'request-publish'"), 'The Publisher request ability must be discoverable.');
	security_assert(str_contains($abilities_source, "self::PREFIX . 'inspect-publishable-draft'"), 'The Publisher handoff inspection ability must be discoverable.');
	security_assert(!str_contains($abilities_source, "self::PREFIX . 'publish',"), 'A direct publish ability must not exist.');
	security_assert(str_contains($approval_source, 'check_admin_referer'), 'Human approval must require a WordPress nonce.');
	security_assert(str_contains($approval_source, 'CAP_APPROVE_PUBLISH'), 'Human approval must require its dedicated capability.');
	security_assert(str_contains($approval_source, 'smartcloud_composer_publish_approval_asset'), 'Approval preview assets must use a bounded token-authorized endpoint.');
	security_assert(str_contains($approval_source, 'rewrite_asset_references'), 'Approval HTML and CSS must reuse rewritten rendered-preview assets.');

	echo "mcp-security-contract: ok\n";
}
