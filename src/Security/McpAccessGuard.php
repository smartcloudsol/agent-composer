<?php

namespace SmartCloud\AgentComposer\Security;

use SmartCloud\AgentComposer\Execution\Abilities;
use SmartCloud\AgentComposer\Execution\Config_Repository;
use SmartCloud\AgentComposer\Infrastructure\Persistence\AuditTable;
use SmartCloud\AgentComposer\Infrastructure\WordPress\Activation;
use SmartCloud\AgentComposer\Integration\Mcp\ComposerMcpServer;

final class McpAccessGuard {
	public const MODE_OPEN = 'OPEN';
	public const MODE_PROTECTED = 'PROTECTED';
	public const MODE_REQUIRED = 'PROTECTED_REQUIRED';
	private const ROLE_LEVEL = array( 'reader' => 1, 'contributor' => 2, 'publisher' => 3 );
	private const CAPABILITY_SCOPE = array(
		'read'            => 'composer.read',
		'draft'           => 'composer.draft',
		'propose'         => 'composer.propose',
		'request_publish' => 'composer.publish.request',
		'publish_media'   => 'composer.publish.request',
	);

	private ?ActorContext $actor = null;
	private bool $capability_filter_registered = false;
	private bool $transport_denial_audited = false;
	private bool $transport_success_audited = false;
	private bool $tool_list_audited = false;
	private array $authentication_diagnostics = array();

	public function __construct(
		private readonly McpSecuritySettings $settings,
		private readonly CognitoJwtValidator $tokens,
		private readonly Config_Repository $config,
		private readonly ?AuditTable $audit = null
	) {}

	public function register_hooks(): void {
		add_filter( 'mcp_adapter_tools_list', array( $this, 'filter_tools' ), 20, 2 );
		add_filter( 'mcp_adapter_pre_tool_call', array( $this, 'filter_tool_call' ), 20, 4 );
		add_filter( 'mcp_adapter_tool_call_result', array( $this, 'filter_tool_result' ), 20, 5 );
	}

	public function mode(): string {
		$required = false;
		try {
			$required = $this->config->is_mcp_authentication_required();
		} catch ( \Throwable ) {
			$required = false;
		}
		if ( $required ) { return self::MODE_REQUIRED; }
		$settings = $this->settings->get();
		$identity = $this->settings->identity_configuration();
		return 'none' !== $identity['source'] && ! empty( $settings['group_roles'] )
			? self::MODE_PROTECTED
			: self::MODE_OPEN;
	}

	public function transport_permission( mixed $request = null ): bool|\WP_Error {
		$actor = $this->authenticate( $request );
		if ( is_wp_error( $actor ) ) {
			$context = array(
				'mode'           => $this->mode(),
				'bearer_present' => '' !== $this->authorization_header( $request ),
			);
			$this->audit_transport_denial( $actor, $context );
			do_action( 'smartcloud_agent_composer_mcp_access_denied', $actor, $context );
			return $actor;
		}
		$this->set_actor( $actor );
		$this->audit_transport_success( $request );
		return true;
	}

	public function authenticate( mixed $request = null ): ActorContext|\WP_Error {
		$this->authentication_diagnostics = array();
		$mode = $this->mode();
		if ( self::MODE_OPEN === $mode ) {
			$user_id = get_current_user_id();
			return new ActorContext(
				$user_id > 0 ? 'wp-user:' . $user_id : 'open:anonymous',
				'',
				$user_id > 0 ? (string) $user_id : 'anonymous',
				$user_id > 0 ? (string) wp_get_current_user()->user_email : '',
				array(),
				'open',
				array(),
				'contributor',
				$mode,
				$user_id > 0 ? 'wordpress' : 'open'
			);
		}
		$identity = $this->settings->identity_configuration();
		$settings = $this->settings->get();
		if ( 'none' === $identity['source'] || empty( $settings['group_roles'] ) ) {
			return $this->error( 'security_configuration_incomplete', 'The Site Contract requires MCP authentication, but the Composer identity configuration is incomplete.', 503 );
		}
		$authorization = $this->authorization_header( $request );
		if ( ! preg_match( '/^Bearer\s+([^\s]+)$/i', $authorization, $match ) ) {
			return $this->error( 'authentication_required', 'A Cognito Bearer access token is required.', 401 );
		}
		$this->authentication_diagnostics = $this->bearer_diagnostics( $match[1] );
		$claims = $this->tokens->validate( $match[1], $identity, (string) $settings['oauth_resource_uri'] );
		if ( is_wp_error( $claims ) ) { return $claims; }
		$raw_scopes = preg_split( '/\s+/', trim( (string) ( $claims['scope'] ?? '' ) ) ) ?: array();
		$groups = $this->groups_from_claims( $claims, $raw_scopes );
		$this->authentication_diagnostics = array_merge(
			$this->authentication_diagnostics,
			$this->diagnostics_from_claims( $claims, $raw_scopes, $groups )
		);
		$group_role = '';
		foreach ( $groups as $group ) {
			$role = (string) ( $settings['group_roles'][ $group ] ?? '' );
			if ( $this->role_level( $role ) > $this->role_level( $group_role ) ) { $group_role = $role; }
		}
		if ( '' === $group_role ) {
			return $this->error( 'role_mapping_missing', 'The authenticated principal has no Composer role mapping.', 403 );
		}
		$client_id = (string) $claims['client_id'];
		$client = null;
		foreach ( $settings['clients'] as $candidate ) {
			if ( hash_equals( (string) $candidate['client_id'], $client_id ) ) { $client = $candidate; break; }
		}
		if ( ! is_array( $client ) && 'deny' === $settings['unknown_client_policy'] ) {
			return $this->error( 'oauth_client_not_allowed', 'This OAuth client is not allowed to use Agent Composer.', 403 );
		}
		$client_role = is_array( $client ) ? (string) $client['role_ceiling'] : $group_role;
		$role = $this->role_level( $group_role ) <= $this->role_level( $client_role ) ? $group_role : $client_role;
		$token_scopes = $this->canonical_scopes( $raw_scopes, (string) $settings['oauth_resource_uri'] );
		$configured_scopes = $this->canonical_scopes( is_array( $client ) ? (array) ( $client['scopes'] ?? array() ) : array(), '' );
		$scopes = empty( $configured_scopes ) ? $token_scopes : array_values( array_intersect( $token_scopes, $configured_scopes ) );
		$issuer = (string) $claims['iss'];
		$subject = (string) $claims['sub'];
		$actor = new ActorContext(
			'cognito:' . hash( 'sha256', $issuer . "\0" . $subject ),
			$issuer,
			$subject,
			sanitize_email( (string) ( $claims['email'] ?? '' ) ),
			$groups,
			$client_id,
			$scopes,
			$role,
			$mode,
			'cognito'
		);
		$effective_role = apply_filters( 'smartcloud_agent_composer_mcp_effective_role', $role, $actor );
		if ( is_string( $effective_role ) && array_key_exists( $effective_role, self::ROLE_LEVEL ) && $effective_role !== $role ) {
			$actor = new ActorContext(
				$actor->principal_id(), $issuer, $subject, $actor->email(), $groups, $client_id,
				$scopes, $effective_role, $mode, 'cognito'
			);
		}
		$filtered = apply_filters( 'smartcloud_agent_composer_mcp_actor_context', $actor, $request, $claims );
		$filtered = apply_filters( 'smartcloud_agent_composer_actor_context', $filtered instanceof ActorContext ? $filtered : $actor, $claims, $settings );
		return $filtered instanceof ActorContext ? $filtered : $actor;
	}

	public function authorize_ability( string $ability_name ): bool|\WP_Error {
		if ( null === $this->actor ) {
			$authenticated = $this->transport_permission( null );
			if ( is_wp_error( $authenticated ) ) { return $authenticated; }
		}
		$capability = $this->capability_for_name( $ability_name );
		if ( 'forbidden' === $capability ) {
			$error = $this->error( 'direct_publish_forbidden', 'Agent Composer never exposes direct publish or destructive production mutation to an agent.', 403 );
			do_action( 'smartcloud_agent_composer_mcp_access_denied', $error, $this->actor );
			return $error;
		}
		if ( ! $this->actor_allows( $this->actor, $capability ) ) {
			$error = $this->error( 'tool_not_authorized', 'The current Composer actor is not authorized for this ability.', 403 );
			do_action( 'smartcloud_agent_composer_mcp_access_denied', $error, $this->actor );
			return $error;
		}
		return true;
	}

	public function filter_tools( array $tools, object $server ): array {
		if ( ! $this->is_composer_server( $server ) ) { return $tools; }
		if ( null === $this->actor ) {
			$result = $this->transport_permission( null );
			if ( is_wp_error( $result ) ) { return array(); }
		}
		$visible = array_values(
			array_filter(
				$tools,
				function ( mixed $tool ): bool {
					$name = $this->component_name( $tool );
					return '' !== $name && true === $this->authorize_ability( $name );
				}
			)
		);
		$filtered = apply_filters( 'smartcloud_agent_composer_mcp_visible_tools', $visible, $this->actor );
		$result = is_array( $filtered ) ? $filtered : $visible;
		if ( ! $this->tool_list_audited ) {
			$this->tool_list_audited = true;
			$this->record_audit_event(
				'mcp-tool-list-served',
				'success',
				array(
					'tool_count' => count( $result ),
					'tool_names' => array_values( array_filter( array_map( fn( mixed $tool ): string => $this->component_name( $tool ), $result ) ) ),
				)
			);
		}
		return $result;
	}

	public function filter_tool_call( mixed $args, string $tool_name, object $tool, object $server ): mixed {
		unset( $tool );
		if ( ! $this->is_composer_server( $server ) ) { return $args; }
		$allowed = $this->authorize_ability( $tool_name );
		$authorized = true === $allowed;
		$authorized = apply_filters( 'smartcloud_agent_composer_mcp_authorize_tool', $authorized, $tool_name, $args, $this->actor );
		if ( ! $authorized && true === $allowed ) {
			$allowed = $this->error( 'tool_not_authorized', 'The current Composer actor is not authorized for this ability.', 403 );
		}
		return is_wp_error( $allowed ) ? $allowed : $args;
	}

	public function filter_tool_result( mixed $result, array $args, string $tool_name, object $tool, object $server ): mixed {
		unset( $args, $tool );
		if ( ! $this->is_composer_server( $server ) ) { return $result; }
		$failed = is_wp_error( $result ) || ( is_array( $result ) && false === ( $result['success'] ?? true ) );
		$context = array(
			'tool_name'  => substr( sanitize_text_field( $tool_name ), 0, 256 ),
			'capability' => $this->capability_for_name( $tool_name ),
		);
		if ( is_wp_error( $result ) ) {
			$context['error_code'] = $result->get_error_code();
		}
		$this->record_audit_event( 'mcp-tool-call-completed', $failed ? 'error' : 'success', $context );
		return $result;
	}

	public function status(): array {
		return array_merge(
			array( 'mode' => $this->mode(), 'actor' => $this->actor?->public_summary() ),
			$this->settings->public_status()
		);
	}

	public function current_actor(): ?ActorContext { return $this->actor; }

	public function capability_for_name( string $name ): string {
		$name = strtolower( str_replace( '_', '-', $name ) );
		if ( in_array( $name, array( 'smartcloud-static-publisher/list-targets', 'smartcloud-static-publisher/list-content-sync-rules', 'smartcloud-static-publisher/get-job-status' ), true ) ) { return 'read'; }
		if ( str_contains( $name, 'upload-media-asset' ) ) { return 'publish_media'; }
		if ( str_contains( $name, 'request-publish' ) || str_contains( $name, 'publishable-draft' ) || str_contains( $name, 'publish-approval' ) ) { return 'request_publish'; }
		if ( preg_match( '/(?:^|[-\/])(publish|delete|trash|unpublish)(?:$|[-\/])/', $name ) ) { return 'forbidden'; }
		$slug = str_replace( Abilities::PREFIX, '', str_replace( 'smartcloud-agent-composer-', '', $name ) );
		if ( in_array( $slug, self::read_slugs(), true ) || str_contains( $slug, 'preview' ) || str_starts_with( $slug, 'get-' ) || str_starts_with( $slug, 'list-' ) || str_starts_with( $slug, 'inspect-' ) || str_starts_with( $slug, 'search-' ) || str_starts_with( $slug, 'validate-' ) ) {
			return 'read';
		}
		if ( str_contains( $slug, 'proposal' ) || str_contains( $slug, 'propose' ) || str_contains( $slug, 'migration' ) ) { return 'propose'; }
		return 'draft';
	}

	private function actor_allows( ?ActorContext $actor, string $capability ): bool {
		if ( ! $actor ) { return false; }
		if ( self::MODE_OPEN === $actor->mode() && in_array( $capability, array( 'request_publish', 'publish_media' ), true ) ) { return false; }
		$minimum = array( 'read' => 1, 'draft' => 2, 'propose' => 2, 'request_publish' => 3, 'publish_media' => 3 )[ $capability ] ?? 99;
		if ( $this->role_level( $actor->role() ) < $minimum ) { return false; }
		$settings = $this->settings->get();
		if ( true === $settings['enforce_scopes'] ) {
			$scope = self::CAPABILITY_SCOPE[ $capability ] ?? '';
			if ( '' === $scope || ! in_array( $scope, $actor->scopes(), true ) ) { return false; }
		}
		return true;
	}

	private function set_actor( ActorContext $actor ): void {
		$this->actor = $actor;
		ActorIdentity::set( $actor );
		if ( ! $this->capability_filter_registered && in_array( $actor->source(), array( 'cognito', 'open' ), true ) ) {
			add_filter( 'user_has_cap', array( $this, 'grant_request_capabilities' ), 20, 4 );
			$this->capability_filter_registered = true;
		}
		do_action( 'smartcloud_agent_composer_actor_resolved', $actor );
		do_action( 'smartcloud_agent_composer_mcp_actor_resolved', $actor );
	}

	public function grant_request_capabilities( array $allcaps, array $caps, array $args, \WP_User $user ): array {
		unset( $caps, $args, $user );
		if ( ! $this->actor || ! in_array( $this->actor->source(), array( 'cognito', 'open' ), true ) ) { return $allcaps; }
		$granted = array(
			'read', 'edit_posts', 'edit_pages', 'create_posts', 'create_pages',
			Activation::CAP_USE, Activation::CAP_EXECUTE_DRAFTS, Activation::CAP_INGEST_MEDIA,
			Activation::CAP_ASSIGN_TERMS, Activation::CAP_CREATE_TERMS, Activation::CAP_PROPOSE_UPDATES,
			Activation::CAP_RUN_MIGRATIONS,
		);
		if ( 'cognito' === $this->actor->source() && 'publisher' === $this->actor->role() ) {
			$granted[] = Activation::CAP_PUBLISH_MEDIA;
		}
		foreach ( $granted as $capability ) { $allcaps[ $capability ] = true; }
		foreach ( array( 'publish_posts', 'publish_pages', 'delete_posts', 'delete_pages', Activation::CAP_MERGE_PROPOSALS ) as $denied ) { $allcaps[ $denied ] = false; }
		return $allcaps;
	}

	private function authorization_header( mixed $request ): string {
		if ( $request instanceof \WP_REST_Request ) { return trim( (string) $request->get_header( 'authorization' ) ); }
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The bearer value is unslashed and sanitized immediately below, then cryptographically validated.
		$header = wp_unslash( (string) ( $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '' ) );
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		return trim( sanitize_text_field( $header ) );
	}

	/**
	 * Keep the authentication boundary diagnosable without ever persisting the
	 * bearer value, authorization code, token claims, or user identifier.
	 */
	private function audit_transport_denial( \WP_Error $error, array $context ): void {
		if ( null === $this->audit || $this->transport_denial_audited ) {
			return;
		}
		$this->transport_denial_audited = true;
		$data = $error->get_error_data();
		try {
			$this->audit->record(
				'mcp-access-denied',
				'denied',
				array_merge(
					array(
						'error_code'     => $error->get_error_code(),
						'http_status'    => is_array( $data ) ? (int) ( $data['status'] ?? 0 ) : 0,
						'mode'           => (string) ( $context['mode'] ?? '' ),
						'bearer_present' => true === ( $context['bearer_present'] ?? false ),
					),
					$this->authentication_diagnostics
				)
			);
		} catch ( \Throwable ) {
			// Authentication must fail closed even if the diagnostic audit sink is unavailable.
		}
	}

	/**
	 * Record the accepted MCP request shape without request arguments or bearer
	 * material. Tool completion is audited separately after execution.
	 */
	private function audit_transport_success( mixed $request ): void {
		if ( null === $this->audit || $this->transport_success_audited ) {
			return;
		}
		$this->transport_success_audited = true;
		$context = array(
			'mode'           => $this->mode(),
			'bearer_present' => '' !== $this->authorization_header( $request ),
			'http_method'    => $request instanceof \WP_REST_Request ? $request->get_method() : '',
		);
		if ( $request instanceof \WP_REST_Request ) {
			$context = array_merge( $context, $this->request_shape( $request->get_json_params() ) );
		}
		$this->record_audit_event( 'mcp-access-granted', 'success', $context );
	}

	private function request_shape( mixed $payload ): array {
		$messages = is_array( $payload ) && array_is_list( $payload ) ? $payload : array( $payload );
		$methods = array();
		$tools = array();
		foreach ( array_slice( $messages, 0, 32 ) as $message ) {
			if ( ! is_array( $message ) ) { continue; }
			$method = substr( sanitize_text_field( (string) ( $message['method'] ?? '' ) ), 0, 128 );
			if ( '' !== $method ) { $methods[] = $method; }
			$params = is_array( $message['params'] ?? null ) ? $message['params'] : array();
			if ( 'tools/call' === $method && is_string( $params['name'] ?? null ) ) {
				$tools[] = substr( sanitize_text_field( $params['name'] ), 0, 256 );
			}
		}
		return array(
			'rpc_methods' => array_values( array_unique( $methods ) ),
			'tool_names'  => array_values( array_unique( $tools ) ),
			'batch_size'  => count( $messages ),
		);
	}

	private function record_audit_event( string $event_type, string $outcome, array $context ): void {
		if ( null === $this->audit ) { return; }
		try {
			$this->audit->record( $event_type, $outcome, $context );
		} catch ( \Throwable ) {
			// MCP processing must not become unavailable if the diagnostic audit sink fails.
		}
	}

	private function component_name( mixed $tool ): string {
		if ( is_object( $tool ) && method_exists( $tool, 'toArray' ) ) {
			$value = $tool->toArray();
			return is_array( $value ) ? (string) ( $value['name'] ?? '' ) : '';
		}
		if ( is_object( $tool ) && method_exists( $tool, 'getName' ) ) { return (string) $tool->getName(); }
		return is_array( $tool ) ? (string) ( $tool['name'] ?? '' ) : '';
	}

	private function is_composer_server( object $server ): bool {
		foreach ( array( 'get_server_id', 'get_id', 'get_name' ) as $method ) {
			if ( method_exists( $server, $method ) && ComposerMcpServer::SERVER_ID === (string) $server->{$method}() ) { return true; }
		}
		return false;
	}

	private function role_level( string $role ): int { return self::ROLE_LEVEL[ $role ] ?? 0; }
	private function groups_from_claims( array $claims, array $scopes ): array {
		$groups = array_values(
			array_filter(
				array_map( static fn( mixed $group ): string => trim( (string) $group ), (array) ( $claims['cognito:groups'] ?? array() ) ),
				static fn( string $group ): bool => '' !== $group && strlen( $group ) <= 128
			)
		);
		foreach ( $scopes as $scope ) {
			$scope = trim( (string) $scope );
			if ( ! str_starts_with( $scope, 'sc.group.' ) ) { continue; }
			$group = substr( $scope, strlen( 'sc.group.' ) );
			if ( '' !== $group && strlen( $group ) <= 128 ) { $groups[] = $group; }
		}
		return array_values( array_unique( $groups ) );
	}
	private function bearer_diagnostics( string $bearer ): array {
		return array(
			'bearer_sha256' => hash( 'sha256', $bearer ),
			'bearer_length' => strlen( $bearer ),
		);
	}
	/**
	 * Record only non-secret authorization-shape data. Never persist claim
	 * values such as subject, email, timestamps, bearer tokens, or codes.
	 */
	private function diagnostics_from_claims( array $claims, array $scopes, array $groups ): array {
		$claim_names = array_values(
			array_filter(
				array_map( 'strval', array_keys( $claims ) ),
				static fn( string $name ): bool => preg_match( '/^[A-Za-z0-9:_-]{1,128}$/', $name ) === 1
			)
		);
		sort( $claim_names, SORT_STRING );

		$scope_groups = array();
		foreach ( $scopes as $scope ) {
			$scope = trim( (string) $scope );
			if ( ! str_starts_with( $scope, 'sc.group.' ) ) { continue; }
			$group = substr( $scope, strlen( 'sc.group.' ) );
			if ( '' !== $group && strlen( $group ) <= 128 ) { $scope_groups[] = $group; }
		}

		return array(
			'claim_names'               => $claim_names,
			'cognito_groups_claim_type' => get_debug_type( $claims['cognito:groups'] ?? null ),
			'resolved_groups'           => array_values( array_unique( array_map( 'strval', $groups ) ) ),
			'scope_groups'              => array_values( array_unique( $scope_groups ) ),
			'granted_scopes'            => array_values(
				array_slice(
					array_filter(
						array_map( static fn( mixed $scope ): string => trim( (string) $scope ), $scopes ),
						static fn( string $scope ): bool => '' !== $scope && strlen( $scope ) <= 256
					),
					0,
					64
				)
			),
			'issuer'                    => substr( (string) ( $claims['iss'] ?? '' ), 0, 512 ),
			'client_id'                 => substr( (string) ( $claims['client_id'] ?? '' ), 0, 256 ),
			'credential_use'            => substr( (string) ( $claims['token_use'] ?? '' ), 0, 32 ),
			'issued_at'                 => (int) ( $claims['iat'] ?? 0 ),
			'expires_at'                => (int) ( $claims['exp'] ?? 0 ),
			'principal_subject_sha256'  => hash( 'sha256', (string) ( $claims['sub'] ?? '' ) ),
		);
	}
	private function canonical_scopes( array $scopes, string $resource_uri = '' ): array {
		$aliases = array(
			'composer.read'            => 'composer.read',
			'composer.draft'           => 'composer.draft',
			'composer.propose'         => 'composer.propose',
			'composer.publish.request' => 'composer.publish.request',
		);
		$scope_names = array_combine(
			McpSecuritySettings::RESOURCE_SCOPE_NAMES,
			array( 'composer.read', 'composer.draft', 'composer.propose', 'composer.publish.request' )
		);
		if ( '' !== $resource_uri ) {
			foreach ( $scope_names as $name => $canonical ) {
				$aliases[ $resource_uri . '/' . $name ] = $canonical;
			}
		} else {
			// Preserve existing direct-client policy input while no external
			// resource is configured. These aliases are never accepted as the
			// scopes of a resource-bound token.
			foreach ( $scope_names as $name => $canonical ) {
				$aliases[ 'composer/' . $name ] = $canonical;
			}
		}
		$result = array();
		foreach ( $scopes as $scope ) {
			$scope = trim( (string) $scope );
			if ( isset( $aliases[ $scope ] ) ) { $result[] = $aliases[ $scope ]; }
		}
		return array_values( array_unique( $result ) );
	}
	private function error( string $code, string $message, int $status ): \WP_Error { return new \WP_Error( 'smartcloud_composer_' . $code, $message, array( 'status' => $status ) ); }
	private static function read_slugs(): array {
		return array( 'get-page-blueprint', 'get-contract', 'get-document', 'get-design-context', 'get-runtime-capabilities', 'list-supported-content-languages', 'list-approved-patterns', 'read-reference-page', 'search-media', 'materialize-media-image', 'materialize-query-loop', 'get-content-field-contract', 'search-relation-targets', 'inspect-content-fields', 'get-taxonomy-contract', 'search-taxonomy-terms', 'inspect-taxonomy-terms', 'list-content-drafts', 'inspect-content-item', 'inspect-draft-for-adoption', 'validate-content-draft', 'validate-page-draft', 'get-draft', 'get-preview', 'get-rendered-preview', 'get-rendered-preview-asset', 'preview-blueprint-migration', 'plan-blueprint-migration', 'validate-proposal' );
	}
}
