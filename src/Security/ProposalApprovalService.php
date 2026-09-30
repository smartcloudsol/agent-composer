<?php

namespace SmartCloud\AgentComposer\Security;

use SmartCloud\AgentComposer\Execution\Content_Proposal_Service;
use SmartCloud\AgentComposer\Execution\Draft_Service;
use SmartCloud\AgentComposer\Execution\Execution_Exception;
use SmartCloud\AgentComposer\Execution\Rendered_Preview_Service;
use SmartCloud\AgentComposer\Infrastructure\Persistence\AuditTable;
use SmartCloud\AgentComposer\Infrastructure\Persistence\ProposalApprovalTable;

/** A human-only, exact-revision approval handoff for published-content proposals. */
final class ProposalApprovalService {
	public function __construct(
		private readonly Content_Proposal_Service $proposals,
		private readonly Draft_Service $drafts,
		private readonly Rendered_Preview_Service $previews,
		private readonly McpSecuritySettings $settings,
		private readonly ProposalApprovalTable $table,
		private readonly AuditTable $audit
	) {}

	public function request( array $input ): array {
		$actor = $this->publisher();
		$proposal_id = absint( $input['proposal_id'] ?? 0 );
		$review = $this->proposals->inspect( $proposal_id );
		if ( 'ready-for-review' !== (string) ( $review['state'] ?? '' ) ) {
			throw new Execution_Exception( 'proposal_approval_not_ready', 'Submit the update proposal before requesting human approval.' );
		}
		$expected_revision = trim( (string) ( $input['expected_revision'] ?? '' ) );
		$expected_modified = trim( (string) ( $input['expected_modified_gmt'] ?? '' ) );
		if ( ( '' === $expected_revision ) !== ( '' === $expected_modified ) ) {
			throw new Execution_Exception( 'proposal_approval_concurrency_pair_required', 'Supply both expected_revision and expected_modified_gmt, or omit both to lock the current submitted revision.' );
		}
		if ( '' !== $expected_revision && ( ! hash_equals( $expected_revision, (string) $review['revision'] ) || ! hash_equals( $expected_modified, (string) $review['modified_gmt'] ) ) ) {
			throw new Execution_Exception( 'edit_conflict', 'The update proposal changed before its approval request was created.' );
		}
		if ( function_exists( 'clean_post_cache' ) ) {
			clean_post_cache( $proposal_id );
		}
		$post = get_post( $proposal_id );
		if ( ! $post instanceof \WP_Post || 'draft' !== $post->post_status ) {
			throw new Execution_Exception( 'proposal_not_found', 'The update proposal is no longer available.' );
		}
		$document = $this->render( $post, $review );
		$uuid = wp_generate_uuid4();
		$requested = time();
		$requested_gmt = gmdate( 'Y-m-d H:i:s', $requested );
		$token = $this->app_token( $uuid, $actor->principal_id(), $actor->client_id(), $requested_gmt );
		if ( ! $this->table->insert( array(
			'request_uuid' => $uuid,
			'proposal_post_id' => $proposal_id,
			'source_post_id' => (int) $review['source_post_id'],
			'revision' => (string) $review['revision'],
			'modified_gmt' => (string) $review['modified_gmt'],
			'content_hash' => hash( 'sha256', (string) $post->post_content ),
			'rendered_hash' => (string) ( $document['document']['review_sha256'] ?? '' ),
			'base_fingerprint' => (string) $review['base_fingerprint'],
			'assigned_principal' => $this->drafts->assigned_principal_id( $proposal_id ),
			'assigned_user_id' => absint( get_post_meta( $proposal_id, Draft_Service::ASSIGNED_AGENT_META, true ) ),
			'requester_principal' => $actor->principal_id(),
			'requester_client' => $actor->client_id(),
			'requested_gmt' => $requested_gmt,
			'expires_gmt' => gmdate( 'Y-m-d H:i:s', $requested + (int) $this->settings->get()['approval_ttl_seconds'] ),
			'status' => 'pending',
			'preview_token_hash' => hash( 'sha256', $token ),
			'approver_principal' => '',
			'decision_reason' => '',
			'decided_gmt' => null,
		) ) ) {
			throw new Execution_Exception( 'proposal_approval_request_failed', 'The update proposal approval request could not be stored.' );
		}
		$result = $this->public_record( $this->table->find( $uuid ) ?? array() );
		$result['preview_url'] = (string) ( $document['preview_url'] ?? '' );
		$result['validation'] = (array) ( $review['validation'] ?? array() );
		$result['conflict'] = ! empty( $review['conflict'] );
		$result['changes'] = (array) ( $review['changes'] ?? array() );
		$result['document'] = (array) ( $document['document'] ?? array() );
		$this->audit->record( 'content-proposal-approval-requested', 'success', $this->public_record( $this->table->find( $uuid ) ?? array() ), 0, $proposal_id );
		return $result;
	}

	public function open_from_app( array $input ): array {
		$uuid = sanitize_text_field( (string) ( $input['id'] ?? '' ) );
		$row = $this->find_row( $uuid );
		$this->assert_app_actor( $row );
		if ( 'pending' === (string) $row['status'] && strtotime( (string) $row['expires_gmt'] . ' UTC' ) < time() ) {
			$this->table->decide( $uuid, 'pending', 'expired', '' );
			$row = $this->table->find( $uuid ) ?? $row;
		}
		$result = $this->public_record( $row );
		if ( 'pending' !== (string) $row['status'] ) {
			return $result;
		}
		$token = $this->app_token( $uuid, (string) $row['requester_principal'], (string) $row['requester_client'], (string) $row['requested_gmt'] );
		if ( ! hash_equals( (string) $row['preview_token_hash'], hash( 'sha256', $token ) ) ) {
			throw new Execution_Exception( 'proposal_approval_not_found', 'The approval request was not found.' );
		}
		$result['approval_token'] = $token;
		return $result;
	}

	/** @return array{kind:string,mime_type:string,content:string} */
	public function asset_from_app( string $uuid, string $token, string $asset_id ): array {
		$row = $this->authorized_row( $uuid, $token );
		$this->assert_app_actor( $row );
		$this->validated_state( $row );
		return $this->previews->built_asset_payload( $asset_id );
	}

	public function decide_from_app( array $input ): array {
		if ( true !== ( $input['confirm_decision'] ?? false ) ) {
			throw new Execution_Exception( 'proposal_approval_confirmation_required', 'Confirm this decision from the approval interface.' );
		}
		$uuid = sanitize_text_field( (string) ( $input['id'] ?? '' ) );
		$token = sanitize_text_field( (string) ( $input['token'] ?? '' ) );
		$decision = sanitize_key( (string) ( $input['decision'] ?? '' ) );
		$reason = trim( sanitize_textarea_field( (string) ( $input['reason'] ?? '' ) ) );
		if ( ! in_array( $decision, array( 'approve', 'request-changes', 'reject' ), true ) ) {
			throw new Execution_Exception( 'proposal_approval_decision_invalid', 'Choose Approve, Request changes, or Reject.' );
		}
		if ( 'approve' !== $decision && ( '' === $reason || strlen( $reason ) > 1000 ) ) {
			throw new Execution_Exception( 'proposal_approval_reason_required', 'A reason of up to 1000 characters is required for this decision.' );
		}
		$row = $this->authorized_row( $uuid, $token );
		$actor = $this->assert_app_actor( $row );
		$approver = 'mcp-app:' . $actor->principal_id();
		if ( ! $this->table->decide( $uuid, 'pending', 'deciding', $approver, $reason ) ) {
			throw new Execution_Exception( 'proposal_approval_state_conflict', 'This approval request has already been decided.' );
		}
		try {
			$review = $this->validated_state( $row, 'reject' !== $decision );
			if ( 'approve' !== $decision && ! empty( $review['conflict'] ) && 'reject' !== $decision ) {
				throw new Execution_Exception( 'proposal_source_conflict', 'The live source changed; this proposal cannot be returned without rebasing.' );
			}
			if ( 'approve' === $decision && ( ! empty( $review['conflict'] ) || empty( $review['validation']['valid'] ) ) ) {
				throw new Execution_Exception( 'proposal_approval_invalidated', 'The source or Blueprint changed; this proposal cannot be merged.' );
			}
			$proposal_id = (int) $row['proposal_post_id'];
			$version = array( 'expected_modified_gmt' => (string) $row['modified_gmt'], 'expected_revision' => (string) $row['revision'] );
			if ( 'approve' === $decision ) {
				$proposal = $this->proposals->merge( $proposal_id, $version + array( 'confirmation' => 'merge:' . $proposal_id . ':' . (int) $row['source_post_id'] ), true );
				$status = 'approved';
			} elseif ( 'request-changes' === $decision ) {
				$proposal = $this->proposals->return_for_changes( $proposal_id, $reason, $version, true );
				$status = 'changes-requested';
			} else {
				$proposal = $this->proposals->reject( $proposal_id, $reason, $version, true );
				$status = 'rejected';
			}
		} catch ( \Throwable $error ) {
			$this->table->decide( $uuid, 'deciding', 'invalidated', $approver, $reason );
			throw $error;
		}
		if ( ! $this->table->decide( $uuid, 'deciding', $status, $approver, $reason ) ) {
			throw new Execution_Exception( 'proposal_approval_state_conflict', 'The decision applied, but its approval status could not be finalized.' );
		}
		$result = $this->public_record( $this->table->find( $uuid ) ?? $row );
		$result['proposal'] = $proposal;
		$this->audit->record( 'content-proposal-approval-' . $status, 'success', $this->public_record( $this->table->find( $uuid ) ?? $row ), 0, (int) $row['proposal_post_id'] );
		return $result;
	}

	private function validated_state( array $row, bool $require_same_preview = true ): array {
		$proposal_id = (int) $row['proposal_post_id'];
		$review = $this->proposals->inspect( $proposal_id );
		if ( function_exists( 'clean_post_cache' ) ) {
			clean_post_cache( $proposal_id );
		}
		$post = get_post( $proposal_id );
		if ( ! $post instanceof \WP_Post || 'draft' !== $post->post_status || 'ready-for-review' !== (string) $review['state']
			|| (int) $review['source_post_id'] !== (int) $row['source_post_id']
			|| ! hash_equals( (string) $row['revision'], (string) $review['revision'] )
			|| ! hash_equals( (string) $row['modified_gmt'], (string) $review['modified_gmt'] )
			|| ! hash_equals( (string) $row['base_fingerprint'], (string) $review['base_fingerprint'] )
			|| ! hash_equals( (string) $row['content_hash'], hash( 'sha256', (string) $post->post_content ) ) ) {
			throw new Execution_Exception( 'proposal_approval_invalidated', 'The submitted proposal changed. Request a new approval for its current revision.' );
		}
		if ( $require_same_preview ) {
			$document = $this->render( $post, $review );
			if ( '' === (string) $row['rendered_hash'] || ! hash_equals( (string) $row['rendered_hash'], (string) ( $document['document']['review_sha256'] ?? '' ) ) ) {
				throw new Execution_Exception( 'proposal_approval_preview_changed', 'The WordPress-rendered preview changed. Request a new approval and inspect it again.' );
			}
		}
		return $review;
	}

	private function render( \WP_Post $post, array $review ): array {
		$language = (string) ( $review['localization']['content_language'] ?? get_post_meta( $post->ID, Draft_Service::CONTENT_LANGUAGE_META, true ) );
		return $this->previews->build_document( $post, $review, $language, false );
	}

	private function authorized_row( string $uuid, string $token ): array {
		if ( ! preg_match( '/^[a-f0-9-]{36}$/', $uuid ) || ! preg_match( '/^[A-Za-z0-9_-]{43}$/', $token ) ) {
			throw new Execution_Exception( 'proposal_approval_not_found', 'The approval request was not found.' );
		}
		$row = $this->find_row( $uuid );
		if ( ! hash_equals( (string) $row['preview_token_hash'], hash( 'sha256', $token ) ) ) {
			throw new Execution_Exception( 'proposal_approval_not_found', 'The approval request was not found.' );
		}
		if ( 'pending' !== (string) $row['status'] ) {
			throw new Execution_Exception( 'proposal_approval_not_pending', 'This approval request is no longer pending.' );
		}
		if ( strtotime( (string) $row['expires_gmt'] . ' UTC' ) < time() ) {
			$this->table->decide( $uuid, 'pending', 'expired', '' );
			throw new Execution_Exception( 'proposal_approval_expired', 'This approval request expired.' );
		}
		return $row;
	}

	private function find_row( string $uuid ): array {
		if ( ! preg_match( '/^[a-f0-9-]{36}$/', $uuid ) ) {
			throw new Execution_Exception( 'proposal_approval_not_found', 'The approval request was not found.' );
		}
		$row = $this->table->find( $uuid );
		if ( ! is_array( $row ) ) {
			throw new Execution_Exception( 'proposal_approval_not_found', 'The approval request was not found.' );
		}
		return $row;
	}

	private function publisher(): ActorContext {
		$actor = ActorIdentity::context();
		if ( null === $actor || 'publisher' !== $actor->role() ) {
			throw new Execution_Exception( 'proposal_approval_denied', 'Only a protected Publisher actor may request this approval.' );
		}
		return $actor;
	}

	private function assert_app_actor( array $row ): ActorContext {
		$actor = $this->publisher();
		if ( ! hash_equals( (string) $row['requester_principal'], $actor->principal_id() ) || ! hash_equals( (string) $row['requester_client'], $actor->client_id() ) ) {
			throw new Execution_Exception( 'proposal_approval_actor_mismatch', 'This approval card belongs to a different authenticated Publisher session.' );
		}
		return $actor;
	}

	private function app_token( string $uuid, string $principal, string $client, string $requested_gmt ): string {
		$bytes = hash_hmac( 'sha256', "proposal-approval\0{$uuid}\0{$principal}\0{$client}\0{$requested_gmt}", wp_salt( 'auth' ), true );
		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
	}

	private function public_record( array $row ): array {
		return array(
			'id' => (string) ( $row['request_uuid'] ?? '' ),
			'proposal_id' => (int) ( $row['proposal_post_id'] ?? 0 ),
			'source_post_id' => (int) ( $row['source_post_id'] ?? 0 ),
			'revision' => (string) ( $row['revision'] ?? '' ),
			'modified_gmt' => (string) ( $row['modified_gmt'] ?? '' ),
			'assigned_principal_id' => (string) ( $row['assigned_principal'] ?? '' ),
			'assigned_agent_user_id' => (int) ( $row['assigned_user_id'] ?? 0 ),
			'principal_id' => (string) ( $row['requester_principal'] ?? '' ),
			'requesting_client_id' => (string) ( $row['requester_client'] ?? '' ),
			'requested_gmt' => (string) ( $row['requested_gmt'] ?? '' ),
			'expires_gmt' => (string) ( $row['expires_gmt'] ?? '' ),
			'status' => (string) ( $row['status'] ?? '' ),
			'approver_principal' => (string) ( $row['approver_principal'] ?? '' ),
			'decision_reason' => (string) ( $row['decision_reason'] ?? '' ),
		);
	}
}
