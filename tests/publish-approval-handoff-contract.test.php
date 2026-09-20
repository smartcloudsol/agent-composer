<?php

declare(strict_types=1);

namespace {
	final class WP_Post {
		public int $ID = 4003;
		public string $post_type = 'partner';
		public string $post_status = 'draft';
		public string $post_content = '<!-- wp:paragraph --><p>Publisher handoff.</p><!-- /wp:paragraph -->';
	}
	final class WP_Error {}
	$GLOBALS['approval_meta'] = array(
		4003 => array(
			'_wpsuite_agent_assigned_agent_id' => 77,
			'_wpsuite_agent_content_language' => 'hu-HU',
		),
	);
	$GLOBALS['published_posts'] = array();
	function absint(mixed $value): int { return abs((int) $value); }
	function sanitize_text_field(string $value): string { return trim($value); }
	function sanitize_key(string $value): string { return strtolower((string) preg_replace('/[^a-z0-9_-]/i', '', $value)); }
	function get_post_meta(int $post_id, string $key, bool $single = false): mixed { return $GLOBALS['approval_meta'][$post_id][$key] ?? ''; }
	function wp_generate_uuid4(): string { static $counter = 0; return sprintf('00000000-0000-4000-8000-%012d', ++$counter); }
	function wp_salt(string $scheme = 'auth'): string { return 'publish-approval-contract-' . $scheme; }
	function admin_url(string $path = ''): string { return 'https://blocked.example.test/wp-admin/' . ltrim($path, '/'); }
	function add_query_arg(array $args, string $url): string { return $url . '?' . http_build_query($args); }
	function get_post(int $post_id): ?WP_Post { return 4003 === $post_id ? new WP_Post() : null; }
	function get_post_type_object(string $post_type): object { return (object) array('cap' => (object) array('publish_posts' => 'publish_' . $post_type)); }
	function current_user_can(string $capability): bool { return true; }
	function wp_update_post(array $post, bool $wp_error = false): int|WP_Error { $GLOBALS['published_posts'][] = $post; return (int) $post['ID']; }
	function is_wp_error(mixed $value): bool { return $value instanceof WP_Error; }
	function apply_filters(string $hook, mixed $value, mixed ...$args): mixed { return $value; }
	function do_action(string $hook, mixed ...$args): void {}
	function approval_assert(bool $condition, string $message): void { if (!$condition) { throw new \RuntimeException($message); } }
}

namespace SmartCloud\AgentComposer\Execution {
	final class Execution_Exception extends \RuntimeException {
		public function __construct(private readonly string $execution_code, string $message) { parent::__construct($message); }
		public function get_execution_code(): string { return $this->execution_code; }
	}
	final class Draft_Service {
		public const ASSIGNED_AGENT_META = '_wpsuite_agent_assigned_agent_id';
		public const CONTENT_LANGUAGE_META = '_wpsuite_agent_content_language';
		public int $publisher_reads = 0;
		public function get_publishable_draft_for_publisher(int $post_id): \WP_Post { $this->publisher_reads++; return new \WP_Post(); }
		public function validate_stored_draft_for_publish(int $post_id): array { return array('post_id' => $post_id, 'revision' => '123e4567-e89b-42d3-a456-426614174000', 'modified_gmt' => '2026-09-19T08:00:00Z', 'validation' => array('valid' => true)); }
		public function assigned_principal_id(int $post_id): string { return 'cognito:editor'; }
	}
	final class Rendered_Preview_Service {
		public function build_document(\WP_Post $post, array $preview, string $language, bool $token): array {
			return array('validation' => $preview['validation'], 'document' => array('post_id' => $post->ID, 'revision' => $preview['revision'], 'title' => 'Publisher handoff', 'html' => '<p>Publisher handoff.</p>', 'assets' => array()));
		}
		public function built_asset_payload(string $asset_id): array { return array('kind' => 'stylesheet', 'mime_type' => 'text/css', 'content' => ''); }
	}
}

namespace SmartCloud\AgentComposer\Infrastructure\Persistence {
	final class PublishApprovalTable {
		public array $rows = array();
		public function insert(array $row): bool { $this->rows[$row['request_uuid']] = $row; return true; }
		public function find(string $uuid): ?array { return $this->rows[$uuid] ?? null; }
		public function decide(string $uuid, string $from, string $to, string $approver): bool {
			if (($this->rows[$uuid]['status'] ?? '') !== $from) return false;
			$this->rows[$uuid]['status'] = $to; $this->rows[$uuid]['approver_principal'] = $approver; return true;
		}
	}
	final class AuditTable { public array $events = array(); public function record(string $event, string $outcome, array $context, int $actor, int $object): void { $this->events[] = compact('event', 'outcome', 'context', 'actor', 'object'); } }
}

namespace SmartCloud\AgentComposer\Security {
	final class McpSecuritySettings { public function get(): array { return array('approval_ttl_seconds' => 900); } }
	require_once dirname(__DIR__) . '/src/Security/ActorContext.php';
	require_once dirname(__DIR__) . '/src/Security/ActorIdentity.php';
	require_once dirname(__DIR__) . '/src/Security/PublishApprovalService.php';

	$drafts = new \SmartCloud\AgentComposer\Execution\Draft_Service();
	$table = new \SmartCloud\AgentComposer\Infrastructure\Persistence\PublishApprovalTable();
	$audit = new \SmartCloud\AgentComposer\Infrastructure\Persistence\AuditTable();
	$service = new PublishApprovalService($drafts, new \SmartCloud\AgentComposer\Execution\Rendered_Preview_Service(), new McpSecuritySettings(), $table, $audit);
	$publisher = new ActorContext('cognito:publisher', 'issuer', 'publisher', '', array('publisher'), 'chatgpt-client', array('composer.publish.request'), 'publisher', 'PROTECTED_REQUIRED', 'cognito');
	ActorIdentity::set($publisher);
	$request = $service->request(array('post_id' => 4003, 'expected_revision' => '123e4567-e89b-42d3-a456-426614174000', 'expected_modified_gmt' => '2026-09-19T08:00:00Z'));
	\approval_assert(1 === $drafts->publisher_reads, 'Publication request must use the dedicated Publisher read boundary.');
	\approval_assert('cognito:editor' === ($request['assigned_principal_id'] ?? ''), 'Approval record must preserve the creating principal.');
	\approval_assert('cognito:publisher' === ($request['principal_id'] ?? ''), 'Approval record must separately preserve the requesting Publisher.');
	\approval_assert(77 === ($request['assigned_agent_user_id'] ?? 0), 'Approval record must snapshot the legacy assigned user ID.');
	\approval_assert(isset($request['document']) && !isset($request['approval_token'], $request['approval_url']), 'The model-facing request must contain the inline document without the app token or fallback URL.');
	\approval_assert(!array_key_exists('approval_token', $audit->events[0]['context']), 'Audit events must never persist the short-lived approval token.');
	$request_without_tokens = $service->request(array('post_id' => 4003));
	\approval_assert(isset($request_without_tokens['id'], $request_without_tokens['document']), 'A Publisher must be able to atomically request the current validated revision by post_id alone.');
	try {
		$service->request(array('post_id' => 4003, 'expected_revision' => '123e4567-e89b-42d3-a456-426614174000'));
		\approval_assert(false, 'A partial concurrency pair must be rejected.');
	} catch (\SmartCloud\AgentComposer\Execution\Execution_Exception $error) {
		\approval_assert('publish_concurrency_pair_required' === $error->get_execution_code(), 'A partial concurrency pair must fail with a stable error code.');
	}
	$session = $service->open_from_app(array('id' => $request['id']));
	\approval_assert(isset($session['approval_token'], $session['approval_url']), 'The app-only open helper must return the short-lived token and fallback URL.');

	ActorIdentity::set(new ActorContext('cognito:other-publisher', 'issuer', 'other', '', array('publisher'), 'chatgpt-client', array('composer.publish.request'), 'publisher', 'PROTECTED_REQUIRED', 'cognito'));
	try {
		$service->decide_from_app(array('id' => $request['id'], 'token' => $session['approval_token'], 'decision' => 'approve', 'confirm_decision' => true));
		\approval_assert(false, 'A different Publisher principal must not reuse the approval card.');
	} catch (\SmartCloud\AgentComposer\Execution\Execution_Exception $error) {
		\approval_assert('publish_approver_mismatch' === $error->get_execution_code(), 'Cross-session approval must fail with a stable error code.');
	}
	ActorIdentity::set($publisher);
	$decision = $service->decide_from_app(array('id' => $request['id'], 'token' => $session['approval_token'], 'decision' => 'approve', 'confirm_decision' => true));
	\approval_assert('approved' === ($decision['status'] ?? ''), 'The requesting Publisher must be able to approve through the app-only decision path.');
	\approval_assert(array(array('ID' => 4003, 'post_status' => 'publish')) === $GLOBALS['published_posts'], 'Only the exact reviewed draft may be published.');
	$closed_session = $service->open_from_app(array('id' => $request['id']));
	\approval_assert('approved' === ($closed_session['status'] ?? ''), 'Reopening a decided approval must return its terminal status.');
	\approval_assert(!isset($closed_session['approval_token'], $closed_session['approval_url']), 'A decided approval must not return another decision token or fallback URL.');

	echo "publish-approval-handoff-contract: ok\n";
}
