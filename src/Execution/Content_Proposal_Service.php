<?php
/* SmartCloud Agent Composer execution contract. */

namespace SmartCloud\AgentComposer\Execution;

use SmartCloud\AgentComposer\Domain\Configuration\CanonicalJson;
use SmartCloud\AgentComposer\Infrastructure\Persistence\AuditTable;
use SmartCloud\AgentComposer\Infrastructure\WordPress\Activation;

/** Durable working copies for changes to already published content. */
final class Content_Proposal_Service {
	public const SOURCE_META = '_wpsuite_agent_proposal_source_id';
	public const SOURCE_STATUS_META = '_wpsuite_agent_proposal_source_status';
	public const BASE_FINGERPRINT_META = '_wpsuite_agent_proposal_base_fingerprint';
	public const STATE_META = '_wpsuite_agent_proposal_state';
	public const LOCALIZATION_META = '_wpsuite_agent_proposal_localization';
	public const MERGED_BY_META = '_wpsuite_agent_proposal_merged_by';
	public const MERGED_GMT_META = '_wpsuite_agent_proposal_merged_gmt';
	public const CHANGE_REQUEST_REASON_META = '_wpsuite_agent_proposal_change_request_reason';
	public const RETURNED_BY_META = '_wpsuite_agent_proposal_returned_by';
	public const RETURNED_GMT_META = '_wpsuite_agent_proposal_returned_gmt';
	public const TARGET_SLUG_META = '_wpsuite_agent_proposal_target_slug';

	private const ACTIVE_STATES = array( 'working', 'ready-for-review' );

	public function __construct(
		private readonly Config_Repository $config,
		private readonly Target_Resolver $targets,
		private readonly Page_Validator $validator,
		private readonly Localization_Provider_Registry $localization,
		private readonly AuditTable $audit,
		private readonly Draft_Service $drafts
	) {}

	public function create( array $input ): array {
		if ( ! current_user_can( Activation::CAP_PROPOSE_UPDATES ) ) {
			throw new Execution_Exception( 'proposal_create_denied', 'The current agent cannot create published-content proposals.' );
		}
		if ( true !== ( $input['confirm_proposal'] ?? false ) ) {
			throw new Execution_Exception( 'proposal_confirmation_required', 'Creating a published-content proposal requires explicit confirmation.' );
		}
		$source_id = absint( $input['post_id'] ?? 0 );
		$page_type = sanitize_key( (string) ( $input['page_type'] ?? '' ) );
		$source = $this->fresh_post( $source_id );
		if ( ! $source instanceof \WP_Post || 'publish' !== $source->post_status ) {
			throw new Execution_Exception( 'proposal_source_not_published', 'A proposal source must be an existing published content item.' );
		}
		$blueprint = $this->config->get_blueprint( $page_type );
		$target = $this->targets->resolve( $page_type );
		if ( $source->post_type !== $target['post_type'] ) {
			throw new Execution_Exception( 'proposal_source_type_mismatch', 'The source post type does not match the selected Blueprint.' );
		}
		$access = $this->config->get_content_access( $source->post_type );
		if ( empty( $access['propose_updates'] ) || 'proposal-only' !== (string) ( $blueprint['published_update_policy'] ?? '' ) ) {
			throw new Execution_Exception( 'proposal_policy_denied', 'Both the Site Contract and Blueprint must allow published update proposals.' );
		}
		if ( ! current_user_can( 'read_post', $source_id ) ) {
			throw new Execution_Exception( 'proposal_source_read_denied', 'The current agent cannot read the proposal source.' );
		}
		$source_modified = $this->time_token( $source->post_modified_gmt );
		$source_content_hash = hash( 'sha256', (string) $source->post_content );
		if ( ! hash_equals( $source_modified, (string) ( $input['expected_modified_gmt'] ?? '' ) )
			|| ! hash_equals( $source_content_hash, strtolower( (string) ( $input['expected_content_hash'] ?? '' ) ) ) ) {
			throw new Execution_Exception( 'edit_conflict', 'The published source changed after it was inspected.' );
		}
		$this->targets->assert_current_user_can_create( $target );
		if ( ! hash_equals( $this->targets->template_meta_value( $target ), $this->targets->normalized_stored_template( $source_id ) ) ) {
			throw new Execution_Exception( 'proposal_source_template_mismatch', 'The published source does not use the selected Blueprint template.' );
		}

		$context = $this->localization->resolve( $source_id, $source->post_type );
		$content_language = trim( (string) ( $context['content_language'] ?? $blueprint['content_language'] ?? '' ) );
		$requested_language = trim( (string) ( $input['content_language'] ?? '' ) );
		$allowed_languages = array_map( 'strtolower', (array) ( $blueprint['allowed_content_languages'] ?? array() ) );
		$language_allowed = Content_Language_Validator::is_allowed_language( $allowed_languages, $content_language );
		if ( '' === $content_language || ! $language_allowed ) {
			throw new Execution_Exception( 'proposal_language_not_allowed', 'The source language is not explicitly enabled for this Blueprint.' );
		}
		if ( '' === $requested_language || ! hash_equals( strtolower( $content_language ), strtolower( $requested_language ) ) ) {
			throw new Execution_Exception( 'proposal_language_mismatch', 'The request language must match the localized source item.' );
		}
		$context['content_language'] = $content_language;

		$proposal_lock = $this->acquire_creation_lock( $source_id, $page_type, $context );
		try {
		$active = $this->find_active( $source_id, $page_type, $context );
		if ( $active instanceof \WP_Post ) {
			if ( get_current_user_id() !== absint( get_post_meta( $active->ID, Draft_Service::ASSIGNED_AGENT_META, true ) ) ) {
				throw new Execution_Exception( 'proposal_assigned_to_other_agent', 'Another agent already owns the active proposal for this localized item.' );
			}
			$result = $this->describe( $active );
			$result['idempotent_replay'] = true;
			return $result;
		}

		$projection = $this->projection( $source, $page_type );
		$key = trim( (string) ( $input['idempotency_key'] ?? '' ) );
		if ( ! preg_match( '/^[A-Za-z0-9._:-]{8,128}$/', $key ) ) {
			throw new Execution_Exception( 'invalid_idempotency_key', 'A stable idempotency key is required.' );
		}
		$user_id = get_current_user_id();
		$meta_input = array_merge(
			$projection['meta'],
			array(
				Draft_Service::OWNED_META => '1',
				Draft_Service::PAGE_TYPE_META => $page_type,
				Draft_Service::IDEMPOTENCY_META => $key,
				Draft_Service::REVISION_META => wp_generate_uuid4(),
				Draft_Service::POST_TYPE_META => $target['post_type'],
				Draft_Service::TEMPLATE_META => $this->targets->template_identity( $target ),
				Draft_Service::ASSIGNED_AGENT_META => $user_id,
				Draft_Service::ASSIGNMENT_SOURCE_META => 'published-update-proposal',
				Draft_Service::CLONED_FROM_META => $source_id,
				Target_Resolver::TEMPLATE_META => $projection['template'],
				Draft_Service::YOAST_METADESC_META => $projection['meta_description'],
				self::SOURCE_META => $source_id,
				self::SOURCE_STATUS_META => $source->post_status,
				self::BASE_FINGERPRINT_META => $this->source_fingerprint( $projection ),
				self::STATE_META => 'working',
				self::TARGET_SLUG_META => $source->post_name,
			)
		);
		$temporary_storage_slug = sanitize_title( $source->post_name . '-composer-proposal-new-' . substr( hash( 'sha256', $source_id . '|' . $user_id . '|' . $key ), 0, 12 ) );
		do_action( 'smartcloud_agent_composer_before_proposal_insert', $context, $source_id, $source->post_type );
		$post_id = 0;
		try {
			$post_id = wp_insert_post( wp_slash( array(
				'post_type' => $source->post_type,
				'post_status' => 'draft',
				'post_author' => $user_id,
				'post_parent' => (int) $source->post_parent,
				'menu_order' => (int) $source->menu_order,
				'post_title' => $source->post_title,
				'post_name' => $temporary_storage_slug,
				'post_content' => $source->post_content,
				'post_excerpt' => $source->post_excerpt,
				'meta_input' => $meta_input,
			) ), true );
		} finally {
			do_action( 'smartcloud_agent_composer_after_proposal_insert', $context, $source_id, $source->post_type, $post_id instanceof \WP_Error ? 0 : absint( $post_id ) );
		}
		if ( $post_id instanceof \WP_Error ) {
			throw new Execution_Exception( 'proposal_create_failed', $post_id->get_error_message() );
		}
		$this->assign_proposal_storage_slug( (int) $post_id, $source->post_name );
		$this->persist_localization_context( (int) $post_id, $context );
		foreach ( $projection['taxonomies'] as $taxonomy => $term_ids ) {
			$result = wp_set_object_terms( (int) $post_id, $term_ids, $taxonomy, false );
			if ( $result instanceof \WP_Error ) {
				update_post_meta( (int) $post_id, self::STATE_META, 'rejected' );
				update_post_meta( (int) $post_id, '_wpsuite_agent_proposal_rejection_reason', 'Proposal initialization failed before it became editable.' );
				throw new Execution_Exception( 'proposal_taxonomy_copy_failed', $result->get_error_message() );
			}
		}
		if ( $projection['featured_image_id'] > 0 ) {
			update_post_meta( (int) $post_id, '_thumbnail_id', $projection['featured_image_id'] );
		}
		$post = get_post( (int) $post_id );
		if ( ! $post instanceof \WP_Post ) {
			throw new Execution_Exception( 'proposal_read_failed', 'The proposal was created but could not be read.' );
		}
		$post = $this->drafts->initialize_created_modified_gmt( $post );
		try {
			$this->localization->validate_proposal( $context, $source_id, $post->ID );
		} catch ( Execution_Exception $error ) {
			update_post_meta( $post->ID, self::STATE_META, 'rejected' );
			update_post_meta( $post->ID, '_wpsuite_agent_proposal_rejection_reason', 'The localization provider attached an unsafe relationship while the working proposal was created.' );
			throw $error;
		}
		$this->audit->record( 'content-proposal-created', 'success', array( 'source_post_id' => $source_id, 'page_type' => $page_type, 'language' => $content_language ), 0, $post->ID );
		return $this->describe( $post );
		} finally {
			$this->release_creation_lock( $proposal_lock );
		}
	}

	public function submit( array $input ): array {
		$proposal = $this->proposal( absint( $input['post_id'] ?? 0 ) );
		if ( get_current_user_id() !== absint( get_post_meta( $proposal->ID, Draft_Service::ASSIGNED_AGENT_META, true ) ) ) {
			throw new Execution_Exception( 'proposal_not_editable', 'Only the assigned agent can submit this proposal.' );
		}
		$this->assert_proposal_token( $proposal, $input );
		$state = (string) get_post_meta( $proposal->ID, self::STATE_META, true );
		if ( 'ready-for-review' === $state ) {
			$result = $this->describe( $proposal );
			$result['idempotent_replay'] = true;
			return $result;
		}
		if ( 'working' !== $state ) {
			throw new Execution_Exception( 'proposal_not_editable', 'Only a working proposal can be submitted.' );
		}
		$rendered_preview_token = trim( (string) ( $input['rendered_preview_token'] ?? '' ) );
		$rendered_preview_policy = $this->config->get_rendered_preview_policy();
		if ( 'required' === $rendered_preview_policy || '' !== $rendered_preview_token ) {
			Rendered_Preview_Service::assert_submission_token(
				$rendered_preview_token,
				$proposal->ID,
				(string) ( $input['expected_modified_gmt'] ?? '' ),
				(string) ( $input['expected_revision'] ?? '' ),
				get_current_user_id()
			);
		}
		$validation = $this->validate_post( $proposal );
		if ( ! $validation['valid'] ) {
			throw new Execution_Exception( 'proposal_validation_failed', 'The proposal must pass its current Blueprint before review.' );
		}
		if ( false === update_post_meta( $proposal->ID, self::STATE_META, 'ready-for-review', 'working' ) ) {
			throw new Execution_Exception( 'proposal_state_conflict', 'The proposal state changed before review submission could be recorded.' );
		}
		$this->audit->record(
			'content-proposal-submitted',
			'success',
			array(
				'source_post_id'           => absint( get_post_meta( $proposal->ID, self::SOURCE_META, true ) ),
				'rendered_preview_policy'  => $rendered_preview_policy,
				'rendered_preview_attested' => '' !== $rendered_preview_token,
			),
			0,
			$proposal->ID
		);
		return $this->describe( $proposal );
	}

	/** @param array<int,string>|string $state */
	public function list( array|string $state = array( 'ready-for-review' ), string $search = '', int $page = 1, int $per_page = 20 ): array {
		$allowed_states = array( 'working', 'ready-for-review', 'merged', 'rejected', 'superseded' );
		$requested_states = is_array( $state ) ? $state : ( 'any' === $state ? $allowed_states : array( $state ) );
		$states = array_values( array_intersect( $allowed_states, array_map( 'sanitize_key', $requested_states ) ) );
		if ( empty( $states ) ) {
			$states = array( 'ready-for-review' );
		}
		$search = trim( sanitize_text_field( $search ) );
		$page = max( 1, $page );
		$per_page = min( 50, max( 5, $per_page ) );
		$query = new \WP_Query( array(
			'post_type' => 'any', 'post_status' => 'draft', 'posts_per_page' => -1,
			'orderby' => 'modified', 'order' => 'DESC', 'no_found_rows' => true,
			'suppress_filters' => true,
			'cache_results' => false, 'update_post_meta_cache' => false, 'update_post_term_cache' => false,
			'fields' => 'ids',
			'meta_query' => array( array( 'key' => self::STATE_META, 'value' => $states, 'compare' => 'IN' ) ),
		) );
		$items = array();
		foreach ( array_map( 'absint', $query->posts ) as $proposal_id ) {
			$post = $this->fresh_post( $proposal_id );
			$current_state = sanitize_key( (string) get_post_meta( $proposal_id, self::STATE_META, true ) );
			if ( $post instanceof \WP_Post && 'draft' === $post->post_status && in_array( $current_state, $states, true ) && $this->matches_list_search( $post, $search ) ) {
				$items[] = $this->describe( $post );
			}
		}
		$total = count( $items );
		$total_pages = max( 1, (int) ceil( $total / $per_page ) );
		$page = min( $page, $total_pages );
		return array(
			'items' => array_slice( $items, ( $page - 1 ) * $per_page, $per_page ),
			'total' => $total,
			'page' => $page,
			'per_page' => $per_page,
			'total_pages' => $total_pages,
		);
	}

	private function matches_list_search( \WP_Post $proposal, string $search ): bool {
		if ( '' === $search ) {
			return true;
		}
		$source_id = absint( get_post_meta( $proposal->ID, self::SOURCE_META, true ) );
		$source = $this->fresh_post( $source_id );
		$needle = ltrim( $search, '#' );
		$values = array(
			(string) $proposal->ID,
			(string) $source_id,
			(string) $proposal->post_title,
			(string) $proposal->post_name,
			(string) get_post_meta( $proposal->ID, self::TARGET_SLUG_META, true ),
			$source instanceof \WP_Post ? (string) $source->post_title : '',
			$source instanceof \WP_Post ? (string) $source->post_name : '',
		);
		foreach ( $values as $value ) {
			if ( false !== stripos( $value, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	public function inspect( int $proposal_id ): array {
		$proposal = $this->proposal( $proposal_id );
		$result = $this->describe( $proposal );
		$result['localization_conflict'] = false;
		if ( in_array( $result['state'], self::ACTIVE_STATES, true ) ) {
			try {
				$this->localization->validate_proposal( $this->localization_context( $proposal_id ), (int) $result['source_post_id'], $proposal_id );
			} catch ( Execution_Exception ) {
				$result['conflict'] = true;
				$result['localization_conflict'] = true;
			}
		}
		$result['validation'] = $this->validate_post( $proposal );
		$result['changes'] = $this->changes( $proposal );
		return $result;
	}

	public function merge( int $proposal_id, array $input ): array {
		$proposal = $this->proposal( $proposal_id );
		if ( 'ready-for-review' !== get_post_meta( $proposal_id, self::STATE_META, true ) ) {
			throw new Execution_Exception( 'proposal_not_ready', 'Only a ready-for-review proposal can be merged.' );
		}
		$source_id = absint( get_post_meta( $proposal_id, self::SOURCE_META, true ) );
		if ( (string) ( $input['confirmation'] ?? '' ) !== 'merge:' . $proposal_id . ':' . $source_id ) {
			throw new Execution_Exception( 'proposal_merge_confirmation_required', 'The exact proposal merge confirmation is required.' );
		}
		if ( ! current_user_can( Activation::CAP_MERGE_PROPOSALS ) || ! current_user_can( 'edit_post', $source_id ) ) {
			throw new Execution_Exception( 'proposal_merge_denied', 'Only an authorized human editor can merge this proposal.' );
		}
		$source = $this->fresh_post( $source_id );
		if ( ! $source instanceof \WP_Post || 'publish' !== $source->post_status ) {
			throw new Execution_Exception( 'proposal_source_changed', 'The source is no longer published.' );
		}
		$source_post_type = $source->post_type;
		$post_type_object = get_post_type_object( $source->post_type );
		$publish_cap = $post_type_object instanceof \WP_Post_Type ? (string) ( $post_type_object->cap->publish_posts ?? '' ) : '';
		if ( '' === $publish_cap || ! current_user_can( $publish_cap ) ) {
			throw new Execution_Exception( 'proposal_publish_denied', 'The reviewer cannot update published items of this post type.' );
		}
		$this->assert_proposal_token( $proposal, $input );
		$validation = $this->validate_post( $proposal );
		if ( ! $validation['valid'] ) {
			throw new Execution_Exception( 'proposal_validation_failed', 'The proposal no longer passes its current Blueprint.' );
		}
		$context = $this->localization_context( $proposal_id );
		$this->localization->validate_proposal( $context, $source_id, $proposal_id );

		global $wpdb;
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Merge requires source and proposal row locks.
			throw new Execution_Exception( 'proposal_merge_locking_unavailable', 'The database could not start a safe proposal merge transaction.' );
		}
		try {
			$locked_ids = array_map(
				'intval',
				(array) $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID IN (%d, %d) ORDER BY ID ASC FOR UPDATE", $source_id, $proposal_id ) ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Deterministic merge locks.
			);
			$expected_locked_ids = array( $source_id, $proposal_id );
			sort( $expected_locked_ids, SORT_NUMERIC );
			if ( $locked_ids !== $expected_locked_ids ) {
				throw new Execution_Exception( 'proposal_merge_item_missing', 'The proposal or its published source no longer exists.' );
			}
			$wpdb->get_col( $wpdb->prepare( "SELECT meta_id FROM {$wpdb->postmeta} WHERE post_id IN (%d, %d) ORDER BY post_id ASC, meta_id ASC FOR UPDATE", $source_id, $proposal_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Prevents proposal/source metadata changes during merge.
			$wpdb->get_col( $wpdb->prepare( "SELECT object_id FROM {$wpdb->term_relationships} WHERE object_id IN (%d, %d) ORDER BY object_id ASC, term_taxonomy_id ASC FOR UPDATE", $source_id, $proposal_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Prevents governed taxonomy changes during merge.
			clean_post_cache( $source_id );
			clean_post_cache( $proposal_id );
			$proposal = $this->proposal( $proposal_id );
			if ( 'ready-for-review' !== get_post_meta( $proposal_id, self::STATE_META, true ) ) {
				throw new Execution_Exception( 'proposal_not_ready', 'Only a ready-for-review proposal can be merged.' );
			}
			if ( $source_id !== absint( get_post_meta( $proposal_id, self::SOURCE_META, true ) ) ) {
				throw new Execution_Exception( 'proposal_source_conflict', 'The proposal source relationship changed before merge.' );
			}
			$this->assert_proposal_token( $proposal, $input );
			$validation = $this->validate_post( $proposal );
			if ( ! $validation['valid'] ) {
				throw new Execution_Exception( 'proposal_validation_failed', 'The proposal no longer passes its current Blueprint.' );
			}
			$context = $this->localization_context( $proposal_id );
			$source = $this->fresh_post( $source_id );
			$page_type = sanitize_key( (string) get_post_meta( $proposal_id, Draft_Service::PAGE_TYPE_META, true ) );
			if ( ! $source instanceof \WP_Post || 'publish' !== $source->post_status || $source->post_type !== $proposal->post_type || ! hash_equals( (string) get_post_meta( $proposal_id, self::BASE_FINGERPRINT_META, true ), $this->source_fingerprint( $this->projection( $source, $page_type ) ) ) ) {
				throw new Execution_Exception( 'proposal_source_conflict', 'The live source changed after this proposal was created.' );
			}
			$this->localization->validate_proposal( $context, $source_id, $proposal_id );
			$desired_slug = $this->proposal_target_slug( $proposal );
			$desired_parent = (int) $proposal->post_parent;
			$same_slug_location = $desired_slug === (string) $source->post_name
				&& ( ! is_post_type_hierarchical( $source->post_type ) || (int) $source->post_parent === $desired_parent );
			if ( ! $same_slug_location ) {
				$available_slug = wp_unique_post_slug( $desired_slug, $source_id, 'publish', $source->post_type, $desired_parent );
				if ( $available_slug !== $desired_slug ) {
					throw new Execution_Exception( 'proposal_slug_conflict', 'The proposal slug is no longer available for the published item.' );
				}
			}
			$preserve_governed_slug = static function ( $override, $slug, $post_id, $post_status, $post_type, $post_parent ) use ( $desired_slug, $desired_parent, $source_id, $source ): mixed {
				if ( $source_id === (int) $post_id && $desired_slug === (string) $slug && 'publish' === $post_status && $source->post_type === $post_type && $desired_parent === (int) $post_parent ) {
					return $desired_slug;
				}
				return $override;
			};
			add_filter( 'pre_wp_unique_post_slug', $preserve_governed_slug, 10, 6 );
			try {
				$result = wp_update_post( wp_slash( array(
					'ID' => $source_id, 'post_status' => 'publish', 'post_title' => $proposal->post_title,
					'post_name' => $desired_slug, 'post_content' => $proposal->post_content,
					'post_excerpt' => $proposal->post_excerpt, 'post_parent' => $desired_parent,
					'menu_order' => (int) $proposal->menu_order,
				) ), true );
			} finally {
				remove_filter( 'pre_wp_unique_post_slug', $preserve_governed_slug, 10 );
			}
			if ( $result instanceof \WP_Error ) {
				throw new Execution_Exception( 'proposal_merge_failed', $result->get_error_message() );
			}
			$expected_projection = $this->projection_for_merge_verification( $this->proposal_projection( $proposal, $page_type ) );
			$this->copy_projection_details( $proposal, $source_id, $page_type );
			clean_post_cache( $source_id );
			$merged_source = $this->fresh_post( $source_id );
			if ( ! $merged_source instanceof \WP_Post || ! hash_equals( $this->fingerprint( $expected_projection ), $this->fingerprint( $this->projection_for_merge_verification( $this->projection( $merged_source, $page_type ) ) ) ) ) {
				throw new Execution_Exception( 'proposal_merge_verification_failed', 'The published item did not exactly match the governed proposal projection.' );
			}
			$this->assign_proposal_storage_slug( $proposal->ID, $desired_slug );
			update_post_meta( $proposal_id, self::STATE_META, 'merged' );
			update_post_meta( $proposal_id, self::MERGED_BY_META, get_current_user_id() );
			update_post_meta( $proposal_id, self::MERGED_GMT_META, gmdate( 'Y-m-d H:i:s' ) );
			if ( false === $wpdb->query( 'COMMIT' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Completes the locked merge.
				throw new Execution_Exception( 'proposal_merge_commit_failed', 'The database could not commit the content proposal merge.' );
			}
		} catch ( \Throwable $error ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Prevents partial database merge state.
			clean_post_cache( $source_id );
			clean_post_cache( $proposal_id );
			clean_object_term_cache( $source_id, $source_post_type );
			clean_object_term_cache( $proposal_id, $proposal->post_type );
			throw $error;
		}
		// A long-running MCP process can retain a pre-merge WP_Post instance even
		// after another PHP request commits the update. Invalidate once more after
		// commit; proposal reads also bypass the object cache through fresh_post().
		clean_post_cache( $source_id );
		clean_post_cache( $proposal_id );
		clean_object_term_cache( $source_id, $source_post_type );
		clean_object_term_cache( $proposal_id, $proposal->post_type );
		$this->audit->record( 'content-proposal-merged', 'success', array( 'source_post_id' => $source_id, 'proposal_post_id' => $proposal_id ), 0, $proposal_id );
		return $this->inspect( $proposal_id );
	}

	public function reject( int $proposal_id, string $reason, array $input ): array {
		if ( ! current_user_can( Activation::CAP_MERGE_PROPOSALS ) ) {
			throw new Execution_Exception( 'proposal_reject_denied', 'Only an authorized human reviewer can reject this proposal.' );
		}
		$reason = sanitize_textarea_field( $reason );
		if ( '' === trim( $reason ) ) {
			throw new Execution_Exception( 'proposal_rejection_reason_required', 'A non-empty rejection reason is required.' );
		}
		$proposal = $this->proposal( $proposal_id );
		$this->assert_proposal_token( $proposal, $input );
		$state = (string) get_post_meta( $proposal_id, self::STATE_META, true );
		if ( ! in_array( $state, self::ACTIVE_STATES, true ) ) {
			throw new Execution_Exception( 'proposal_closed', 'This proposal is already closed.' );
		}
		if ( false === update_post_meta( $proposal_id, self::STATE_META, 'rejected', $state ) ) {
			throw new Execution_Exception( 'proposal_state_conflict', 'The proposal state changed before rejection could be recorded.' );
		}
		update_post_meta( $proposal_id, '_wpsuite_agent_proposal_rejection_reason', $reason );
		$this->audit->record( 'content-proposal-rejected', 'success', array( 'source_post_id' => absint( get_post_meta( $proposal_id, self::SOURCE_META, true ) ) ), 0, $proposal_id );
		return $this->describe( $proposal );
	}

	/** Return the same review copy to its assigned agent for another editing pass. */
	public function return_for_changes( int $proposal_id, string $reason, array $input ): array {
		if ( ! current_user_can( Activation::CAP_MERGE_PROPOSALS ) ) {
			throw new Execution_Exception( 'proposal_return_denied', 'Only an authorized human reviewer can return this proposal for changes.' );
		}
		$reason = sanitize_textarea_field( $reason );
		if ( '' === trim( $reason ) ) {
			throw new Execution_Exception( 'proposal_change_request_reason_required', 'A non-empty change request is required.' );
		}

		$proposal = $this->proposal( $proposal_id );
		$this->assert_proposal_token( $proposal, $input );
		$state = sanitize_key( (string) get_post_meta( $proposal_id, self::STATE_META, true ) );
		if ( ! in_array( $state, array( 'ready-for-review', 'rejected' ), true ) ) {
			throw new Execution_Exception( 'proposal_not_returnable', 'Only a submitted or rejected proposal can be returned for changes.' );
		}

		$source_id = absint( get_post_meta( $proposal_id, self::SOURCE_META, true ) );
		$source = $this->fresh_post( $source_id );
		$page_type = sanitize_key( (string) get_post_meta( $proposal_id, Draft_Service::PAGE_TYPE_META, true ) );
		$base = (string) get_post_meta( $proposal_id, self::BASE_FINGERPRINT_META, true );
		if ( ! $source instanceof \WP_Post || 'publish' !== $source->post_status || ! hash_equals( $base, $this->source_fingerprint( $this->projection( $source, $page_type ) ) ) ) {
			throw new Execution_Exception( 'proposal_source_conflict', 'The live source changed after this proposal was created, so this review copy cannot be returned without rebasing.' );
		}
		$this->localization->validate_proposal( $this->localization_context( $proposal_id ), $source_id, $proposal_id );

		// Touch the post while it is still read-only. The post_updated hook rotates
		// the agent revision before the state becomes editable again.
		$updated = wp_update_post( array( 'ID' => $proposal_id ), true );
		if ( $updated instanceof \WP_Error ) {
			throw new Execution_Exception( 'proposal_return_failed', $updated->get_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Sanitized REST error data; never rendered as HTML.
		}
		update_post_meta( $proposal_id, Draft_Service::REVISION_META, wp_generate_uuid4() );
		if ( false === update_post_meta( $proposal_id, self::STATE_META, 'working', $state ) ) {
			throw new Execution_Exception( 'proposal_state_conflict', 'The proposal state changed before the change request could be recorded.' );
		}
		update_post_meta( $proposal_id, self::CHANGE_REQUEST_REASON_META, $reason );
		update_post_meta( $proposal_id, self::RETURNED_BY_META, get_current_user_id() );
		update_post_meta( $proposal_id, self::RETURNED_GMT_META, gmdate( 'Y-m-d H:i:s' ) );
		$this->audit->record(
			'content-proposal-returned-for-changes',
			'success',
			array( 'source_post_id' => $source_id, 'from_state' => $state, 'reason' => $reason ),
			0,
			$proposal_id
		);
		clean_post_cache( $proposal_id );
		return $this->inspect( $proposal_id );
	}

	private function projection( \WP_Post $post, string $page_type ): array {
		$meta = array();
		foreach ( $this->config->get_content_field_access( $post->post_type ) as $key => $rules ) {
			if ( ! empty( $rules['write'] ) ) {
				$meta[ $key ] = get_post_meta( $post->ID, $key, true );
			}
		}
		$taxonomies = array();
		foreach ( $this->config->get_content_taxonomy_access( $post->post_type ) as $taxonomy => $rules ) {
			if ( ! empty( $rules['assign'] ) ) {
				$ids = wp_get_object_terms( $post->ID, $taxonomy, array( 'fields' => 'ids' ) );
				$ids = is_wp_error( $ids ) ? array() : array_map( 'intval', $ids );
				sort( $ids, SORT_NUMERIC );
				$taxonomies[ $taxonomy ] = $ids;
			}
		}
		ksort( $meta );
		ksort( $taxonomies );
		return array(
			'post_type' => $post->post_type, 'status' => $post->post_status, 'modified_gmt' => $this->time_token( $post->post_modified_gmt ), 'title' => $post->post_title,
			'slug' => $post->post_name, 'content' => $post->post_content, 'excerpt' => $post->post_excerpt,
			'parent_id' => (int) $post->post_parent, 'menu_order' => (int) $post->menu_order,
			'template' => $this->targets->normalized_stored_template( $post->ID ),
			'meta_description' => (string) get_post_meta( $post->ID, Draft_Service::YOAST_METADESC_META, true ),
			'featured_image_id' => absint( get_post_thumbnail_id( $post->ID ) ), 'meta' => $meta, 'taxonomies' => $taxonomies,
			'page_type' => $page_type,
		);
	}

	private function fingerprint( array $projection ): string {
		return CanonicalJson::checksum( $projection );
	}

	/**
	 * Detect changes to every governed live value without treating a no-op
	 * WordPress touch as an editorial conflict. The published status remains in
	 * the fingerprint and is also checked explicitly at merge time.
	 */
	private function source_fingerprint( array $projection ): string {
		unset( $projection['modified_gmt'] );
		return $this->fingerprint( $projection );
	}

	private function projection_for_merge_verification( array $projection ): array {
		unset( $projection['status'], $projection['modified_gmt'] );
		return $projection;
	}

	private function copy_projection_details( \WP_Post $proposal, int $source_id, string $page_type ): void {
		$projection = $this->projection( $proposal, $page_type );
		update_post_meta( $source_id, Target_Resolver::TEMPLATE_META, $projection['template'] );
		update_post_meta( $source_id, Draft_Service::YOAST_METADESC_META, $projection['meta_description'] );
		foreach ( $projection['meta'] as $key => $value ) {
			update_post_meta( $source_id, $key, $value );
		}
		foreach ( $projection['taxonomies'] as $taxonomy => $term_ids ) {
			$result = wp_set_object_terms( $source_id, $term_ids, $taxonomy, false );
			if ( $result instanceof \WP_Error ) {
				throw new Execution_Exception( 'proposal_taxonomy_merge_failed', $result->get_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Sanitized REST error data; never rendered as HTML.
			}
		}
		if ( $projection['featured_image_id'] > 0 ) {
			update_post_meta( $source_id, '_thumbnail_id', $projection['featured_image_id'] );
		} else {
			delete_post_meta( $source_id, '_thumbnail_id' );
		}
	}

	private function validate_post( \WP_Post $proposal ): array {
		$page_type = sanitize_key( (string) get_post_meta( $proposal->ID, Draft_Service::PAGE_TYPE_META, true ) );
		$result = $this->validator->validate( $page_type, (string) $proposal->post_content );
		$blueprint = $this->config->get_blueprint( $page_type );
		$seo = trim( (string) get_post_meta( $proposal->ID, Draft_Service::YOAST_METADESC_META, true ) );
		$seo_contract = (array) ( $blueprint['seo_contract']['meta_description'] ?? array() );
		$minimum = max( 0, (int) ( $seo_contract['minimum_characters'] ?? 120 ) );
		$maximum = max( $minimum, (int) ( $seo_contract['maximum_characters'] ?? 160 ) );
		$seo_length = function_exists( 'mb_strlen' ) ? mb_strlen( $seo ) : strlen( $seo );
		if ( $seo_length < $minimum || $seo_length > $maximum ) {
			$result['valid'] = false;
			$result['errors'][] = array( 'code' => 'meta_description_invalid', 'message' => 'The proposal SEO description does not meet its Blueprint length contract.', 'path' => 'meta_description' );
		}
		$excerpt = trim( wp_strip_all_tags( (string) $proposal->post_excerpt ) );
		$excerpt_policy = (string) ( $blueprint['excerpt_policy'] ?? 'optional' );
		if ( ( 'required' === $excerpt_policy && '' === $excerpt ) || ( 'disabled' === $excerpt_policy && '' !== $excerpt ) ) {
			$result['valid'] = false;
			$result['errors'][] = array( 'code' => 'excerpt_policy_invalid', 'message' => 'The proposal excerpt does not meet its Blueprint policy.', 'path' => 'excerpt' );
		}
		return $result;
	}

	private function changes( \WP_Post $proposal ): array {
		$source = $this->fresh_post( absint( get_post_meta( $proposal->ID, self::SOURCE_META, true ) ) );
		if ( ! $source instanceof \WP_Post ) {
			return array();
		}
		$page_type = sanitize_key( (string) get_post_meta( $proposal->ID, Draft_Service::PAGE_TYPE_META, true ) );
		$before = $this->projection( $source, $page_type );
		$after = $this->proposal_projection( $proposal, $page_type );
		$changed = array();
		foreach ( $after as $field => $value ) {
			if ( in_array( $field, array( 'post_type', 'status', 'modified_gmt', 'page_type' ), true ) ) {
				continue;
			}
			if ( $before[ $field ] !== $value ) {
				$changed[] = $field;
			}
		}
		return $changed;
	}

	/** Keep every proposal state outside the public canonical namespace. */
	private function assign_proposal_storage_slug( int $proposal_id, string $target_slug ): void {
		$proposal = $this->fresh_post( $proposal_id );
		if ( ! $proposal instanceof \WP_Post || 'draft' !== $proposal->post_status ) {
			throw new Execution_Exception( 'proposal_archive_slug_failed', 'The proposal storage row is unavailable.' );
		}
		$target_slug = sanitize_title( $target_slug );
		if ( '' === $target_slug ) {
			throw new Execution_Exception( 'proposal_archive_slug_invalid', 'The proposal target slug could not be stored safely.' );
		}

		$archive_base = sanitize_title( $target_slug . '-composer-proposal-' . $proposal->ID );
		$archive_slug = wp_unique_post_slug(
			$archive_base,
			$proposal->ID,
			'publish',
			$proposal->post_type,
			(int) $proposal->post_parent
		);
		if ( '' === $archive_slug || $target_slug === $archive_slug ) {
			throw new Execution_Exception( 'proposal_archive_slug_invalid', 'The proposal storage slug could not be reserved safely.' );
		}

		update_post_meta( $proposal->ID, self::TARGET_SLUG_META, $target_slug );
		delete_post_meta( $proposal->ID, '_wp_old_slug' );

		global $wpdb;
		$updated = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The proposal row is already locked by the merge transaction; bypass normal canonical-slug mutation hooks for this non-public archive name.
			$wpdb->prepare(
				"UPDATE {$wpdb->posts} SET post_name = %s WHERE ID = %d AND post_status = 'draft'",
				$archive_slug,
				$proposal->ID
			)
		);
		if ( false === $updated || ( 0 === $updated && $archive_slug !== $proposal->post_name ) ) {
			throw new Execution_Exception( 'proposal_archive_slug_failed', 'The proposal could not be moved out of the public canonical namespace.' );
		}
		clean_post_cache( $proposal->ID );
	}

	private function proposal_target_slug( \WP_Post $proposal ): string {
		$target_slug = sanitize_title( (string) get_post_meta( $proposal->ID, self::TARGET_SLUG_META, true ) );
		return '' !== $target_slug ? $target_slug : sanitize_title( $proposal->post_name );
	}

	private function proposal_projection( \WP_Post $proposal, string $page_type ): array {
		$projection         = $this->projection( $proposal, $page_type );
		$projection['slug'] = $this->proposal_target_slug( $proposal );
		return $projection;
	}

	private function describe( \WP_Post $proposal ): array {
		$source_id = absint( get_post_meta( $proposal->ID, self::SOURCE_META, true ) );
		$source = $this->fresh_post( $source_id );
		$page_type = sanitize_key( (string) get_post_meta( $proposal->ID, Draft_Service::PAGE_TYPE_META, true ) );
		$base = (string) get_post_meta( $proposal->ID, self::BASE_FINGERPRINT_META, true );
		$current = $source instanceof \WP_Post ? $this->source_fingerprint( $this->projection( $source, $page_type ) ) : '';
		$localization = $this->localization_context( $proposal->ID );
		$state = sanitize_key( (string) get_post_meta( $proposal->ID, self::STATE_META, true ) );
		$returned_gmt = trim( (string) get_post_meta( $proposal->ID, self::RETURNED_GMT_META, true ) );
		return array(
			'proposal_id' => $proposal->ID, 'post_id' => $proposal->ID, 'source_post_id' => $source_id,
			'title' => get_the_title( $proposal ), 'page_type' => $page_type, 'post_type' => $proposal->post_type,
			'state' => $state,
			'modified_gmt' => $this->time_token( $proposal->post_modified_gmt ),
			'revision' => (string) get_post_meta( $proposal->ID, Draft_Service::REVISION_META, true ),
			'base_fingerprint' => $base, 'current_source_fingerprint' => $current,
			'source_conflict' => in_array( $state, self::ACTIVE_STATES, true ) && ( '' === $current || ! hash_equals( $base, $current ) ),
			'conflict' => in_array( $state, self::ACTIVE_STATES, true ) && ( '' === $current || ! hash_equals( $base, $current ) ),
			'localization' => $localization,
			'edit_url' => get_edit_post_link( $proposal->ID, 'raw' ) ?: '',
			'preview_url' => $this->localization->preview_url( $proposal->ID, $localization ),
			'source_edit_url' => $source instanceof \WP_Post ? ( get_edit_post_link( $source_id, 'raw' ) ?: '' ) : '',
			'change_request_reason' => (string) get_post_meta( $proposal->ID, self::CHANGE_REQUEST_REASON_META, true ),
			'returned_by' => absint( get_post_meta( $proposal->ID, self::RETURNED_BY_META, true ) ),
			'returned_gmt' => '' === $returned_gmt ? '' : $this->time_token( $returned_gmt ),
		);
	}

	private function proposal( int $proposal_id ): \WP_Post {
		$post = $this->fresh_post( $proposal_id );
		if ( ! $post instanceof \WP_Post || 'draft' !== $post->post_status || '1' !== (string) get_post_meta( $proposal_id, Draft_Service::OWNED_META, true ) || '' === (string) get_post_meta( $proposal_id, self::STATE_META, true ) ) {
			throw new Execution_Exception( 'proposal_not_found', 'The requested content proposal does not exist.' );
		}
		return $post;
	}

	private function assert_proposal_token( \WP_Post $proposal, array $input ): void {
		$modified = $this->time_token( $proposal->post_modified_gmt );
		if ( ! hash_equals( $modified, (string) ( $input['expected_modified_gmt'] ?? '' ) ) || ! hash_equals( (string) get_post_meta( $proposal->ID, Draft_Service::REVISION_META, true ), (string) ( $input['expected_revision'] ?? '' ) ) ) {
			throw new Execution_Exception( 'edit_conflict', 'The proposal changed after it was inspected.' );
		}
	}

	private function find_active( int $source_id, string $page_type, array $context ): ?\WP_Post {
		$query = new \WP_Query( array(
			'post_type' => $this->targets->registered_allowed_post_types(), 'post_status' => 'draft', 'posts_per_page' => 10, 'no_found_rows' => true,
			'suppress_filters' => true,
			'cache_results' => false, 'update_post_meta_cache' => false, 'update_post_term_cache' => false,
			'fields' => 'ids',
			'meta_query' => array(
				array( 'key' => self::SOURCE_META, 'value' => $source_id, 'type' => 'NUMERIC' ),
				array( 'key' => Draft_Service::PAGE_TYPE_META, 'value' => $page_type ),
				array( 'key' => self::STATE_META, 'value' => self::ACTIVE_STATES, 'compare' => 'IN' ),
			),
		) );
		foreach ( array_map( 'absint', $query->posts ) as $proposal_id ) {
			$post = $this->fresh_post( $proposal_id );
			if ( ! $post instanceof \WP_Post
				|| 'draft' !== $post->post_status
				|| $source_id !== absint( get_post_meta( $proposal_id, self::SOURCE_META, true ) )
				|| $page_type !== sanitize_key( (string) get_post_meta( $proposal_id, Draft_Service::PAGE_TYPE_META, true ) )
				|| ! in_array( sanitize_key( (string) get_post_meta( $proposal_id, self::STATE_META, true ) ), self::ACTIVE_STATES, true ) ) {
				continue;
			}
			$stored = $this->localization_context( $proposal_id );
			if ( (string) ( $stored['provider'] ?? '' ) === (string) ( $context['provider'] ?? '' )
				&& (string) ( $stored['localization_group'] ?? '' ) === (string) ( $context['localization_group'] ?? '' )
				&& (string) ( $stored['content_language'] ?? '' ) === (string) ( $context['content_language'] ?? '' ) ) {
				return $post;
			}
		}
		return null;
	}

	/**
	 * Read a post from the committed database row instead of trusting a
	 * potentially long-lived WordPress object cache (for example an MCP worker).
	 */
	private function fresh_post( int $post_id ): ?\WP_Post {
		if ( $post_id < 1 ) {
			return null;
		}

		global $wpdb;
		clean_post_cache( $post_id );
		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Proposal concurrency requires the committed posts row, not a long-running process cache.
			$wpdb->prepare( "SELECT * FROM {$wpdb->posts} WHERE ID = %d LIMIT 1", $post_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		return is_object( $row ) ? new \WP_Post( $row ) : null;
	}

	private function localization_context( int $proposal_id ): array {
		$stored = get_post_meta( $proposal_id, self::LOCALIZATION_META, true );
		$value = is_array( $stored ) ? $stored : json_decode( (string) $stored, true );
		return $this->normalize_localization_context( $value );
	}

	private function persist_localization_context( int $proposal_id, array $context ): void {
		$normalized = $this->normalize_localization_context( $context );
		update_post_meta( $proposal_id, self::LOCALIZATION_META, $normalized );
		$stored = $this->localization_context( $proposal_id );
		foreach ( array( 'provider', 'language_code', 'content_language', 'localization_group', 'element_type' ) as $key ) {
			if ( ! hash_equals( (string) ( $normalized[ $key ] ?? '' ), (string) ( $stored[ $key ] ?? '' ) ) ) {
				update_post_meta( $proposal_id, self::STATE_META, 'rejected' );
				update_post_meta( $proposal_id, '_wpsuite_agent_proposal_rejection_reason', 'The proposal localization context could not be stored safely.' );
				throw new Execution_Exception( 'proposal_localization_context_persist_failed', 'The proposal localization context could not be stored safely.' );
			}
		}
	}

	private function normalize_localization_context( mixed $value ): array {
		$provider_raw = is_array( $value ) ? (string) ( $value['provider'] ?? '' ) : '';
		$provider = sanitize_key( $provider_raw );
		$language = is_array( $value ) ? trim( (string) ( $value['content_language'] ?? '' ) ) : '';
		$group = is_array( $value ) ? trim( (string) ( $value['localization_group'] ?? '' ) ) : '';
		if ( ! is_array( $value ) || '' === $provider || $provider !== $provider_raw || '' === $group || sanitize_text_field( $group ) !== $group || strlen( $group ) > 128 || ! preg_match( '/^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$/', $language ) ) {
			throw new Execution_Exception( 'proposal_localization_context_invalid', 'The proposal localization context is missing or invalid.' );
		}
		$value['provider'] = $provider;
		$value['content_language'] = $language;
		$value['localization_group'] = sanitize_text_field( $group );
		return $value;
	}

	/** @return array{name:string,value:string} */
	private function acquire_creation_lock( int $source_id, string $page_type, array $context ): array {
		$name = '_wpsuite_agent_proposal_lock_' . hash( 'sha256', $source_id . '|' . $page_type . '|' . (string) ( $context['provider'] ?? '' ) . '|' . (string) ( $context['content_language'] ?? '' ) );
		$value = wp_generate_uuid4() . ':' . time();
		if ( ! add_option( $name, $value, '', false ) ) {
			$existing = (string) get_option( $name, '' );
			$separator = strrpos( $existing, ':' );
			$created = false === $separator ? 0 : (int) substr( $existing, $separator + 1 );
			if ( $created <= time() - 60 ) {
				global $wpdb;
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $name, $existing ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact compare-and-delete recovers only an expired proposal lock.
				wp_cache_delete( $name, 'options' );
			}
			if ( ! add_option( $name, $value, '', false ) ) {
				throw new Execution_Exception( 'proposal_creation_conflict', 'A proposal for this localized source is already being created.' );
			}
		}
		return array( 'name' => $name, 'value' => $value );
	}

	/** @param array{name:string,value:string} $lock */
	private function release_creation_lock( array $lock ): void {
		if ( hash_equals( $lock['value'], (string) get_option( $lock['name'], '' ) ) ) {
			delete_option( $lock['name'] );
		}
	}

	private function time_token( string $value ): string {
		$value = trim( $value );
		if ( '' === $value || '0000-00-00 00:00:00' === $value ) {
			return '1970-01-01T00:00:00Z';
		}
		$timestamp = strtotime( $value . ( str_contains( $value, 'Z' ) || str_contains( $value, '+' ) ? '' : ' UTC' ) );
		return false === $timestamp ? '' : gmdate( 'Y-m-d\TH:i:s\Z', $timestamp );
	}
}
