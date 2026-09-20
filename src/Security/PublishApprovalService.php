<?php

namespace SmartCloud\AgentComposer\Security;

use SmartCloud\AgentComposer\Execution\Draft_Service;
use SmartCloud\AgentComposer\Execution\Execution_Exception;
use SmartCloud\AgentComposer\Execution\Rendered_Preview_Service;
use SmartCloud\AgentComposer\Infrastructure\Persistence\AuditTable;
use SmartCloud\AgentComposer\Infrastructure\Persistence\PublishApprovalTable;

final class PublishApprovalService {
	public function __construct(
		private readonly Draft_Service $drafts,
		private readonly Rendered_Preview_Service $previews,
		private readonly McpSecuritySettings $settings,
		private readonly PublishApprovalTable $table,
		private readonly AuditTable $audit
	) {}

	public function request( array $input ): array {
		$post_id = absint( $input['post_id'] ?? 0 );
		$actor = ActorIdentity::context();
		if ( null === $actor || 'publisher' !== $actor->role() ) {
			throw new Execution_Exception( 'publish_request_denied', 'Only a protected Publisher actor may request publication.' );
		}
		$post = $this->drafts->get_publishable_draft_for_publisher( $post_id );
		$preview = $this->drafts->validate_stored_draft_for_publish( $post_id );
		$expected_revision = trim( (string) ( $input['expected_revision'] ?? '' ) );
		$expected_modified = trim( (string) ( $input['expected_modified_gmt'] ?? '' ) );
		if ( ( '' === $expected_revision ) !== ( '' === $expected_modified ) ) {
			throw new Execution_Exception( 'publish_concurrency_pair_required', 'Supply both expected_revision and expected_modified_gmt, or omit both and let Composer lock the current validated revision.' );
		}
		if ( '' !== $expected_revision && ( ! hash_equals( $expected_revision, (string) $preview['revision'] ) || ! hash_equals( $expected_modified, (string) $preview['modified_gmt'] ) ) ) {
			throw new Execution_Exception( 'edit_conflict', 'The draft changed before the publish request was created. Inspect it again.' );
		}
		if ( empty( $preview['validation']['valid'] ) ) {
			throw new Execution_Exception( 'publish_validation_failed', 'The draft does not satisfy its active Blueprint and Site Contract.' );
		}
		$rendered = $this->previews->build_document(
			$post,
			$preview,
			(string) get_post_meta( $post->ID, Draft_Service::CONTENT_LANGUAGE_META, true ),
			false
		);
		$uuid = wp_generate_uuid4();
		$requested = time();
		$expires = $requested + (int) $this->settings->get()['approval_ttl_seconds'];
		$requested_gmt = gmdate( 'Y-m-d H:i:s', $requested );
		$token = $this->app_token( $uuid, $actor->principal_id(), $actor->client_id(), $requested_gmt );
		$content_hash = hash( 'sha256', (string) $post->post_content );
		if ( ! $this->table->insert( array(
			'request_uuid'       => $uuid,
			'post_id'            => $post_id,
			'revision'           => (string) $preview['revision'],
			'content_hash'       => $content_hash,
			'assigned_principal' => $this->drafts->assigned_principal_id( $post_id ),
			'assigned_user_id'   => absint( get_post_meta( $post_id, Draft_Service::ASSIGNED_AGENT_META, true ) ),
			'requester_principal'=> $actor->principal_id(),
			'requester_client'   => $actor->client_id(),
			'requested_gmt'      => $requested_gmt,
			'expires_gmt'        => gmdate( 'Y-m-d H:i:s', $expires ),
			'status'             => 'pending',
			'preview_token_hash' => hash( 'sha256', $token ),
			'approver_principal' => '',
			'decided_gmt'        => null,
		) ) ) {
			throw new Execution_Exception( 'publish_request_failed', 'The publish approval request could not be stored.' );
		}
		$result = $this->public_record( $this->table->find( $uuid ) ?? array() );
		$result['validation'] = (array) ( $rendered['validation'] ?? array() );
		$result['document'] = (array) ( $rendered['document'] ?? array() );
		$audit_context = $this->public_record( $this->table->find( $uuid ) ?? array() );
		$audit_context['inline_review'] = true;
		$this->audit->record( 'publish-requested', 'success', $audit_context, 0, $post_id );
		do_action( 'smartcloud_agent_composer_publish_requested', $result, $actor );
		return $result;
	}

	/** Open one app-only approval session without exposing its token to the model-facing request result. */
	public function open_from_app( array $input ): array {
		$uuid = sanitize_text_field( (string) ( $input['id'] ?? '' ) );
		if ( ! preg_match( '/^[a-f0-9-]{36}$/', $uuid ) ) {
			throw new Execution_Exception( 'approval_not_found', 'The publish approval request was not found.' );
		}
		$row = $this->table->find( $uuid );
		if ( ! is_array( $row ) ) {
			throw new Execution_Exception( 'approval_not_found', 'The publish approval request was not found.' );
		}
		$this->assert_app_actor( $row, 'This approval card belongs to a different authenticated Publisher session.' );
		if ( 'pending' === (string) ( $row['status'] ?? '' ) && strtotime( (string) $row['expires_gmt'] . ' UTC' ) < time() ) {
			$this->table->decide( $uuid, 'pending', 'expired', '' );
			$row = $this->table->find( $uuid ) ?? $row;
		}
		if ( 'pending' !== (string) ( $row['status'] ?? '' ) ) {
			return $this->public_record( $row );
		}
		$token = $this->app_token(
			$uuid,
			(string) $row['requester_principal'],
			(string) $row['requester_client'],
			(string) $row['requested_gmt']
		);
		if ( ! hash_equals( (string) $row['preview_token_hash'], hash( 'sha256', $token ) ) ) {
			throw new Execution_Exception( 'approval_not_found', 'The publish approval request was not found.' );
		}
		$result = $this->public_record( $row );
		$result['approval_token'] = $token;
		$result['approval_url'] = add_query_arg(
			array( 'action' => 'smartcloud_composer_publish_review', 'id' => $uuid, 'token' => $token ),
			admin_url( 'admin-post.php' )
		);
		return $result;
	}

	/**
	 * Apply a decision originating from the app-only MCP approval surface.
	 *
	 * The calling OAuth Publisher must be the same principal and client that
	 * created the approval request. Exact revision and content-hash validation
	 * is still performed immediately before publication.
	 */
	public function decide_from_app( array $input ): array {
		if ( true !== ( $input['confirm_decision'] ?? false ) ) {
			throw new Execution_Exception( 'approval_confirmation_required', 'Confirm the publication decision from the approval interface.' );
		}
		$uuid = sanitize_text_field( (string) ( $input['id'] ?? '' ) );
		$token = sanitize_text_field( (string) ( $input['token'] ?? '' ) );
		$decision = sanitize_key( (string) ( $input['decision'] ?? '' ) );
		$row = $this->authorized_row( $uuid, $token );
		$actor = $this->assert_app_actor( $row, 'This approval card belongs to a different authenticated Publisher session.' );
		return $this->apply_decision( $row, $decision, 'mcp-app:' . $actor->principal_id(), false );
	}

	public function review( string $uuid, string $token ): array {
		$row = $this->authorized_row( $uuid, $token );
		list( $post, $preview ) = $this->validated_state( $row );
		$document = $this->previews->build_document( $post, $preview, (string) get_post_meta( $post->ID, Draft_Service::CONTENT_LANGUAGE_META, true ), false );
		$document['document']['approval_stylesheets'] = array();
		foreach ( (array) ( $document['document']['assets'] ?? array() ) as $asset ) {
			if ( is_array( $asset ) && 'stylesheet' === ( $asset['kind'] ?? '' ) && ! empty( $asset['asset_id'] ) ) {
				$payload = $this->previews->built_asset_payload( (string) $asset['asset_id'] );
				$document['document']['approval_stylesheets'][ (string) $asset['asset_id'] ] = (string) $payload['content'];
			}
		}
		return array( 'approval' => $this->public_record( $row ), 'post' => $post, 'preview' => $document );
	}

	/** @return array{kind:string,mime_type:string,content:string} */
	public function asset( string $uuid, string $token, string $asset_id ): array {
		$row = $this->authorized_row( $uuid, $token );
		list( $post, $preview ) = $this->validated_state( $row );
		$this->previews->build_document( $post, $preview, (string) get_post_meta( $post->ID, Draft_Service::CONTENT_LANGUAGE_META, true ), false );
		return $this->previews->built_asset_payload( $asset_id );
	}

	/** @return array{kind:string,mime_type:string,content:string} */
	public function asset_from_app( string $uuid, string $token, string $asset_id ): array {
		$row = $this->authorized_row( $uuid, $token );
		$this->assert_app_actor( $row, 'This approval preview belongs to a different authenticated Publisher session.' );
		list( $post, $preview ) = $this->validated_state( $row );
		$this->previews->build_document( $post, $preview, (string) get_post_meta( $post->ID, Draft_Service::CONTENT_LANGUAGE_META, true ), false );
		return $this->previews->built_asset_payload( $asset_id );
	}

	public function decide( string $uuid, string $token, string $decision, int $user_id ): array {
		$row = $this->authorized_row( $uuid, $token );
		$approver = sanitize_text_field( (string) apply_filters( 'smartcloud_agent_composer_publish_approver_principal', 'wp-user:' . $user_id, $row, $user_id ) );
		$authorized = (bool) apply_filters( 'smartcloud_agent_composer_publish_approval_authorized', '' !== $approver, $approver, $row, $user_id );
		if ( ! $authorized ) {
			throw new Execution_Exception( 'publish_approver_mismatch', 'The authenticated human is not authorized to decide this publication request.' );
		}
		return $this->apply_decision( $row, $decision, $approver, true );
	}

	private function apply_decision( array $row, string $decision, string $approver, bool $require_wordpress_capability ): array {
		$uuid = (string) $row['request_uuid'];
		if ( 'reject' === $decision ) {
			if ( ! $this->table->decide( $uuid, 'pending', 'rejected', $approver ) ) {
				throw new Execution_Exception( 'approval_state_conflict', 'The approval request has already been decided.' );
			}
			$result = $this->public_record( $this->table->find( $uuid ) ?? $row );
			$this->audit->record( 'publish-rejected', 'success', $result, 0, (int) $row['post_id'] );
			do_action( 'smartcloud_agent_composer_publish_rejected', $result );
			return $result;
		}
		if ( 'approve' !== $decision ) {
			throw new Execution_Exception( 'approval_decision_invalid', 'Choose Publish or Reject.' );
		}
		$post_id = (int) $row['post_id'];
		$post_for_capability = get_post( $post_id );
		$post_type_object = $post_for_capability instanceof \WP_Post ? get_post_type_object( $post_for_capability->post_type ) : null;
		$publish_capability = is_object( $post_type_object ) ? (string) ( $post_type_object->cap->publish_posts ?? '' ) : '';
		if ( $require_wordpress_capability && ( '' === $publish_capability || ! current_user_can( $publish_capability ) ) ) {
			throw new Execution_Exception( 'publish_capability_required', 'The current human cannot publish this content type.' );
		}
		if ( ! $this->table->decide( $uuid, 'pending', 'approving', $approver ) ) {
			throw new Execution_Exception( 'approval_state_conflict', 'The approval request has already been decided.' );
		}
		try {
			$preview = $this->drafts->validate_stored_draft_for_publish( $post_id );
			$post = get_post( $post_id );
		} catch ( \Throwable $error ) {
			$this->table->decide( $uuid, 'approving', 'invalidated', $approver );
			throw $error;
		}
		if ( ! $post instanceof \WP_Post
			|| ! hash_equals( (string) $row['revision'], (string) $preview['revision'] )
			|| ! hash_equals( (string) $row['content_hash'], hash( 'sha256', (string) $post->post_content ) )
			|| empty( $preview['validation']['valid'] ) ) {
			$this->table->decide( $uuid, 'approving', 'invalidated', $approver );
			throw new Execution_Exception( 'approval_invalidated', 'The draft or its active contract changed. Create a new publish request.' );
		}
		$result = wp_update_post( array( 'ID' => $post_id, 'post_status' => 'publish' ), true );
		if ( is_wp_error( $result ) ) {
			$this->table->decide( $uuid, 'approving', 'invalidated', $approver );
			throw new Execution_Exception( 'publish_failed', 'WordPress could not publish the approved content.' );
		}
		if ( ! $this->table->decide( $uuid, 'approving', 'approved', $approver ) ) {
			throw new Execution_Exception( 'approval_state_conflict', 'The content was published, but the approval state could not be finalized.' );
		}
		$record = $this->public_record( $this->table->find( $uuid ) ?? $row );
		$this->audit->record( 'publish-approved', 'success', $record, 0, $post_id );
		do_action( 'smartcloud_agent_composer_publish_approved', $record );
		return $record;
	}

	private function authorized_row( string $uuid, string $token ): array {
		if ( ! preg_match( '/^[a-f0-9-]{36}$/', $uuid ) || ! preg_match( '/^[A-Za-z0-9_-]{43}$/', $token ) ) {
			throw new Execution_Exception( 'approval_not_found', 'The publish approval request was not found.' );
		}
		$row = $this->table->find( $uuid );
		if ( ! is_array( $row ) || ! hash_equals( (string) $row['preview_token_hash'], hash( 'sha256', $token ) ) ) {
			throw new Execution_Exception( 'approval_not_found', 'The publish approval request was not found.' );
		}
		$this->assert_pending( $row );
		return $row;
	}

	private function assert_pending( array $row ): void {
		if ( 'pending' !== (string) ( $row['status'] ?? '' ) ) {
			throw new Execution_Exception( 'approval_not_pending', 'The publish approval request is no longer pending.' );
		}
		if ( strtotime( (string) $row['expires_gmt'] . ' UTC' ) < time() ) {
			$this->table->decide( (string) $row['request_uuid'], 'pending', 'expired', '' );
			throw new Execution_Exception( 'approval_expired', 'The publish approval request expired.' );
		}
	}

	private function assert_app_actor( array $row, string $message ): ActorContext {
		$actor = ActorIdentity::context();
		if ( null === $actor
			|| 'publisher' !== $actor->role()
			|| ! hash_equals( (string) $row['requester_principal'], $actor->principal_id() )
			|| ! hash_equals( (string) $row['requester_client'], $actor->client_id() ) ) {
			throw new Execution_Exception( 'publish_approver_mismatch', $message );
		}
		return $actor;
	}

	private function app_token( string $uuid, string $principal, string $client, string $requested_gmt ): string {
		$bytes = hash_hmac( 'sha256', "publish-approval\0{$uuid}\0{$principal}\0{$client}\0{$requested_gmt}", wp_salt( 'auth' ), true );
		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
	}

	/** @return array{0:\WP_Post,1:array<string,mixed>} */
	private function validated_state( array $row ): array {
		$post = get_post( (int) $row['post_id'] );
		if ( ! $post instanceof \WP_Post ) {
			throw new Execution_Exception( 'publish_draft_not_found', 'The Composer draft selected for publication no longer exists.' );
		}
		$preview = $this->drafts->validate_stored_draft_for_publish( $post->ID );
		if ( ! hash_equals( (string) $row['revision'], (string) ( $preview['revision'] ?? '' ) )
			|| ! hash_equals( (string) $row['content_hash'], hash( 'sha256', (string) $post->post_content ) )
			|| empty( $preview['validation']['valid'] ) ) {
			$this->table->decide( (string) $row['request_uuid'], 'pending', 'invalidated', '' );
			throw new Execution_Exception( 'approval_invalidated', 'The draft or its active contract changed. Create a new publish request.' );
		}
		return array( $post, $preview );
	}

	private function public_record( array $row ): array {
		return array(
			'id' => (string) ( $row['request_uuid'] ?? '' ), 'post_id' => (int) ( $row['post_id'] ?? 0 ),
			'revision' => (string) ( $row['revision'] ?? '' ), 'content_hash' => (string) ( $row['content_hash'] ?? '' ),
			'assigned_principal_id' => (string) ( $row['assigned_principal'] ?? '' ), 'assigned_agent_user_id' => (int) ( $row['assigned_user_id'] ?? 0 ),
			'principal_id' => (string) ( $row['requester_principal'] ?? '' ), 'requesting_client_id' => (string) ( $row['requester_client'] ?? '' ),
			'requested_gmt' => (string) ( $row['requested_gmt'] ?? '' ), 'expires_gmt' => (string) ( $row['expires_gmt'] ?? '' ),
			'status' => (string) ( $row['status'] ?? '' ), 'approver_principal' => (string) ( $row['approver_principal'] ?? '' ),
		);
	}
}
