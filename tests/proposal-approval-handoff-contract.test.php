<?php

declare(strict_types=1);

namespace {
	final class WP_Post {
		public int $ID = 6252;
		public string $post_status = 'draft';
		public string $post_content = '<p>Reviewed proposal</p>';
	}
	$GLOBALS['proposal_review_html'] = '<article><h1>Rendered by WordPress</h1></article>';
	function absint(mixed $value): int { return abs((int) $value); }
	function sanitize_text_field(string $value): string { return trim($value); }
	function sanitize_textarea_field(string $value): string { return trim($value); }
	function sanitize_key(string $value): string { return strtolower((string) preg_replace('/[^a-z0-9_-]/i', '', $value)); }
	function get_post_meta(int $id, string $key, bool $single = false): mixed { return $key === '_wpsuite_agent_assigned_agent_id' ? 77 : 'hu-HU'; }
	function get_post(int $id): ?WP_Post { return $id === 6252 ? new WP_Post() : null; }
	function wp_generate_uuid4(): string { static $number = 0; return sprintf('00000000-0000-4000-8000-%012d', ++$number); }
	function wp_salt(string $scheme = 'auth'): string { return 'proposal-contract-' . $scheme; }
	function approval_assert(bool $ok, string $message): void { if (!$ok) throw new \RuntimeException($message); }
	function approval_error(callable $fn, string $code): void {
		try { $fn(); } catch (\SmartCloud\AgentComposer\Execution\Execution_Exception $e) {
			approval_assert($e->get_execution_code() === $code, 'Expected ' . $code . ', got ' . $e->get_execution_code());
			return;
		}
		throw new \RuntimeException('Expected failure: ' . $code);
	}
}

namespace SmartCloud\AgentComposer\Execution {
	final class Execution_Exception extends \RuntimeException {
		public function __construct(private string $code_name, string $message) { parent::__construct($message); }
		public function get_execution_code(): string { return $this->code_name; }
	}
	final class Draft_Service {
		public const ASSIGNED_AGENT_META = '_wpsuite_agent_assigned_agent_id';
		public const CONTENT_LANGUAGE_META = '_wpsuite_agent_content_language';
		public function assigned_principal_id(int $id): string { return 'cognito:author'; }
	}
	final class Content_Proposal_Service {
		public array $review = array(
			'proposal_id' => 6252, 'source_post_id' => 1088, 'state' => 'ready-for-review',
			'revision' => '123e4567-e89b-42d3-a456-426614174000', 'modified_gmt' => '2026-09-30T08:00:00Z',
			'base_fingerprint' => 'sha256:source', 'validation' => array('valid' => true),
			'localization' => array('content_language' => 'hu-HU'), 'changes' => array('excerpt'), 'conflict' => false,
		);
		public array $decisions = array();
		public function inspect(int $id): array { return $this->review; }
		public function merge(int $id, array $input, bool $approved_in_app = false): array {
			$this->decisions[] = array('merge', $id, $input, $approved_in_app); return array('state' => 'merged');
		}
		public function return_for_changes(int $id, string $reason, array $input, bool $approved_in_app = false): array {
			$this->decisions[] = array('changes', $id, $reason, $input, $approved_in_app); return array('state' => 'working');
		}
		public function reject(int $id, string $reason, array $input, bool $approved_in_app = false): array {
			$this->decisions[] = array('reject', $id, $reason, $input, $approved_in_app); return array('state' => 'rejected');
		}
	}
	final class Rendered_Preview_Service {
		public function build_document(\WP_Post $post, array $review, string $language, bool $token): array {
			$html = $GLOBALS['proposal_review_html'];
			return array('preview_url' => 'https://example.test/?p=1088', 'document' => array('title' => 'Doctor', 'html' => $html, 'revision' => $review['revision'], 'review_sha256' => hash('sha256', $html), 'sha256' => hash('sha256', $html), 'assets' => array()));
		}
		public function built_asset_payload(string $id): array { return array('kind' => 'stylesheet', 'mime_type' => 'text/css', 'content' => 'body{}'); }
	}
}

namespace SmartCloud\AgentComposer\Infrastructure\Persistence {
	final class ProposalApprovalTable {
		public array $rows = array();
		public function insert(array $row): bool { $this->rows[$row['request_uuid']] = $row; return true; }
		public function find(string $id): ?array { return $this->rows[$id] ?? null; }
		public function decide(string $id, string $from, string $to, string $approver, string $reason = ''): bool {
			if (($this->rows[$id]['status'] ?? '') !== $from) return false;
			$this->rows[$id]['status'] = $to; $this->rows[$id]['approver_principal'] = $approver;
			$this->rows[$id]['decision_reason'] = $reason; return true;
		}
	}
	final class AuditTable { public array $events = array(); public function record(string $event, string $outcome, array $context, int $actor, int $object): void { $this->events[] = $context; } }
}

namespace SmartCloud\AgentComposer\Security {
	final class McpSecuritySettings { public function get(): array { return array('approval_ttl_seconds' => 900); } }
	require_once dirname(__DIR__) . '/src/Security/ActorContext.php';
	require_once dirname(__DIR__) . '/src/Security/ActorIdentity.php';
	require_once dirname(__DIR__) . '/src/Security/ProposalApprovalService.php';

	$proposals = new \SmartCloud\AgentComposer\Execution\Content_Proposal_Service();
	$table = new \SmartCloud\AgentComposer\Infrastructure\Persistence\ProposalApprovalTable();
	$audit = new \SmartCloud\AgentComposer\Infrastructure\Persistence\AuditTable();
	$service = new ProposalApprovalService($proposals, new \SmartCloud\AgentComposer\Execution\Draft_Service(), new \SmartCloud\AgentComposer\Execution\Rendered_Preview_Service(), new McpSecuritySettings(), $table, $audit);
	$publisher = new ActorContext('cognito:publisher', 'issuer', 'publisher', '', array('publisher'), 'chatgpt-client', array('composer.publish.request'), 'publisher', 'PROTECTED_REQUIRED', 'cognito');
	ActorIdentity::set($publisher);
	$request = $service->request(array('proposal_id' => 6252));
	\approval_assert(isset($request['document']['html']) && !isset($request['approval_token']), 'The model sees the rendered document but never its decision token.');
	\approval_assert($request['document']['html'] === $GLOBALS['proposal_review_html'], 'Review must use WordPress-rendered HTML.');
	\approval_assert(($request['assigned_principal_id'] ?? '') === 'cognito:author', 'Author assignment must be retained.');
	\approval_assert(!isset($audit->events[0]['approval_token']), 'Audit must not persist the decision token.');
	\approval_error(fn() => $service->request(array('proposal_id' => 6252, 'expected_revision' => $proposals->review['revision'])), 'proposal_approval_concurrency_pair_required');
	$session = $service->open_from_app(array('id' => $request['id']));
	\approval_assert(isset($session['approval_token']), 'The private app must obtain a decision token.');
	ActorIdentity::set(new ActorContext('cognito:other', 'issuer', 'other', '', array('publisher'), 'chatgpt-client', array('composer.publish.request'), 'publisher', 'PROTECTED_REQUIRED', 'cognito'));
	\approval_error(fn() => $service->decide_from_app(array('id' => $request['id'], 'token' => $session['approval_token'], 'decision' => 'approve', 'confirm_decision' => true)), 'proposal_approval_actor_mismatch');
	ActorIdentity::set($publisher);
	\approval_error(fn() => $service->decide_from_app(array('id' => $request['id'], 'token' => $session['approval_token'], 'decision' => 'approve')), 'proposal_approval_confirmation_required');
	$result = $service->decide_from_app(array('id' => $request['id'], 'token' => $session['approval_token'], 'decision' => 'approve', 'confirm_decision' => true));
	\approval_assert(($result['status'] ?? '') === 'approved' && count($proposals->decisions) === 1, 'The human decision must merge exactly once.');
	\approval_assert($proposals->decisions[0][3] === true && $proposals->decisions[0][2]['confirmation'] === 'merge:6252:1088', 'The private decision must carry exact merge confirmation.');
	\approval_error(fn() => $service->decide_from_app(array('id' => $request['id'], 'token' => $session['approval_token'], 'decision' => 'approve', 'confirm_decision' => true)), 'proposal_approval_not_pending');
	$changed = $service->request(array('proposal_id' => 6252));
	$changed_token = $service->open_from_app(array('id' => $changed['id']))['approval_token'];
	\approval_error(fn() => $service->decide_from_app(array('id' => $changed['id'], 'token' => $changed_token, 'decision' => 'request-changes', 'confirm_decision' => true)), 'proposal_approval_reason_required');
	$GLOBALS['proposal_review_html'] = '<article><h1>Template changed</h1></article>';
	\approval_error(fn() => $service->decide_from_app(array('id' => $changed['id'], 'token' => $changed_token, 'decision' => 'approve', 'confirm_decision' => true)), 'proposal_approval_preview_changed');
	\approval_assert(($table->find($changed['id'])['status'] ?? '') === 'invalidated' && count($proposals->decisions) === 1, 'Template drift must block merge.');
	$GLOBALS['proposal_review_html'] = '<article><h1>Rendered by WordPress</h1></article>';
	$stale_rejection = $service->request(array('proposal_id' => 6252));
	$stale_token = $service->open_from_app(array('id' => $stale_rejection['id']))['approval_token'];
	$GLOBALS['proposal_review_html'] = '<article><h1>Live source changed</h1></article>';
	$rejected = $service->decide_from_app(array('id' => $stale_rejection['id'], 'token' => $stale_token, 'decision' => 'reject', 'reason' => 'Source changed; discard this proposal.', 'confirm_decision' => true));
	\approval_assert(($rejected['status'] ?? '') === 'rejected', 'A human must be able to reject a stale preview without merging it.');
	$GLOBALS['proposal_review_html'] = '<article><h1>Rendered by WordPress</h1></article>';
	foreach (array('request-changes' => 'changes-requested', 'reject' => 'rejected') as $action => $state) {
		$card = $service->request(array('proposal_id' => 6252));
		$token = $service->open_from_app(array('id' => $card['id']))['approval_token'];
		$decision = $service->decide_from_app(array('id' => $card['id'], 'token' => $token, 'decision' => $action, 'reason' => 'Please revise.', 'confirm_decision' => true));
		\approval_assert(($decision['status'] ?? '') === $state, 'The human must be able to ' . $action . '.');
	}
	\approval_assert(count($proposals->decisions) === 4, 'All three decisions must reach the governed proposal service once.');
	echo "proposal-approval-handoff-contract: ok\n";
}
