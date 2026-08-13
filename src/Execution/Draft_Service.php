<?php
/* SmartCloud Agent Composer execution contract. */

namespace SmartCloud\AgentComposer\Execution;

final class Draft_Service {
	private const ZERO_MODIFIED_GMT = '1970-01-01T00:00:00Z';

	public const OWNED_META = '_wpsuite_agent_owned';
	public const PAGE_TYPE_META = '_wpsuite_agent_page_type';
	public const IDEMPOTENCY_META = '_wpsuite_agent_idempotency_key';
	public const REVISION_META = '_wpsuite_agent_revision';
	public const POST_TYPE_META = '_wpsuite_agent_target_post_type';
	public const TEMPLATE_META = '_wpsuite_agent_target_template';
	public const ASSIGNED_AGENT_META = '_wpsuite_agent_assigned_user_id';
	public const ASSIGNMENT_SOURCE_META = '_wpsuite_agent_assignment_source';
	public const ADOPTED_GMT_META = '_wpsuite_agent_adopted_gmt';
	public const CLONED_FROM_META = '_wpsuite_agent_cloned_from_post_id';
	public const YOAST_METADESC_META = '_yoast_wpseo_metadesc';

	private Pattern_Assembler $assembler;
	private Page_Validator $validator;
	private Target_Resolver $targets;
	private Block_Tree_Service $trees;
	private Config_Repository $config;
	private Content_Language_Validator $language;

	public function __construct(
		Pattern_Assembler $assembler,
		Page_Validator $validator,
		Target_Resolver $targets,
		Block_Tree_Service $trees,
		Config_Repository $config,
		Content_Language_Validator $language
	) {
		$this->assembler = $assembler;
		$this->validator = $validator;
		$this->targets   = $targets;
		$this->trees     = $trees;
		$this->config    = $config;
		$this->language  = $language;
	}

	public function validate_request( array $input ): array {
		$page_type = sanitize_key( (string) ( $input['page_type'] ?? '' ) );
		$this->language->assert_request_language( $page_type, $input );
		$target    = $this->targets->resolve( $page_type );
		$editorial = $this->sanitize_editorial_fields( $input, $page_type );
		$assembled = $this->assembler->assemble( $page_type, $input['sections'] ?? array() );
		$result           = $this->validator->validate( $page_type, $assembled['content'] );
		$result           = $this->add_editorial_language_issues( $result, $page_type, $input, $editorial );
		$result['target'] = $this->targets->public_contract( $target );
		$result['seo']    = $this->describe_editorial_fields( $editorial['excerpt'], $editorial['meta_description'], $editorial['excerpt_policy'] );
		return $result;
	}

	public function create( array $input, array $structured_fields = array() ): array {
		$this->assert_no_forbidden_input( $input );
		$user_id   = get_current_user_id();
		$page_type = sanitize_key( (string) ( $input['page_type'] ?? '' ) );
		$this->language->assert_request_language( $page_type, $input );
		$title     = $this->sanitize_title( $input['title'] ?? '' );
		$slug      = $this->sanitize_slug( $input['slug'] ?? '' );
		$key       = $this->sanitize_idempotency_key( $input['idempotency_key'] ?? '' );
		$editorial = $this->sanitize_editorial_fields( $input, $page_type );
		$target    = $this->targets->resolve( $page_type );
		$this->targets->assert_current_user_can_create( $target );

		$assembled  = $this->assembler->assemble( $page_type, $input['sections'] ?? array() );
		$validation = $this->validator->validate( $page_type, $assembled['content'] );
		$validation = $this->add_editorial_language_issues( $validation, $page_type, $input, $editorial );
		if ( ! $validation['valid'] ) {
			throw new Execution_Exception( 'validation_failed', 'The assembled content failed validation and was not saved.' );
		}

		$lock_name = $this->acquire_idempotency_lock( $user_id, $key );
		try {
			$existing = $this->find_idempotent_draft( $user_id, $key, $page_type, $target );
			if ( $existing ) {
				return $this->describe( $existing, true );
			}

			$meta_input = array_merge(
				array(
					self::OWNED_META              => '1',
					self::PAGE_TYPE_META          => $page_type,
					self::IDEMPOTENCY_META        => $key,
					self::REVISION_META           => wp_generate_uuid4(),
					self::POST_TYPE_META          => $target['post_type'],
					self::TEMPLATE_META           => $this->targets->template_identity( $target ),
					self::ASSIGNED_AGENT_META     => $user_id,
					self::ASSIGNMENT_SOURCE_META  => 'created',
					Target_Resolver::TEMPLATE_META => $this->targets->template_meta_value( $target ),
					self::YOAST_METADESC_META     => $editorial['meta_description'],
				),
				$structured_fields
			);
			$post_id = wp_insert_post(
				wp_slash(
					array(
						'post_type'    => $target['post_type'],
						'post_status'  => 'draft',
						'post_author'  => $user_id,
						'post_title'   => $title,
						'post_name'    => $slug,
						'post_content' => $assembled['content'],
						'post_excerpt' => $editorial['excerpt'],
						'meta_input'   => $meta_input,
					)
				),
				true
			);

			if ( is_wp_error( $post_id ) ) {
				throw new Execution_Exception( 'draft_create_failed', $post_id->get_error_message() );
			}

			$post = get_post( (int) $post_id );
			if ( ! $post instanceof \WP_Post ) {
				throw new Execution_Exception( 'draft_read_after_create_failed', 'The draft was created but could not be read back.' );
			}
			$post = $this->initialize_created_modified_gmt( $post );
			$this->assert_post_contract( $post, $page_type, $target );

			$result               = $this->describe( $post );
			$result['validation'] = $validation;
			$result['updated_fields'] = array_values( array_keys( $structured_fields ) );
			return $result;
		} finally {
			$this->release_idempotency_lock( $lock_name );
		}
	}

	public function update( array $input, array $structured_fields = array() ): array {
		$this->assert_no_forbidden_input( $input );
		$post_id  = absint( $input['post_id'] ?? 0 );
		$expected = $this->normalize_expected_modified_gmt( (string) ( $input['expected_modified_gmt'] ?? '' ) );
		$expected_revision = $this->sanitize_revision( $input['expected_revision'] ?? '' );
		$post      = $this->get_owned_draft( $post_id );
		$page_type = sanitize_key( (string) get_post_meta( $post_id, self::PAGE_TYPE_META, true ) );
		if ( isset( $input['page_type'] ) && $page_type !== sanitize_key( (string) $input['page_type'] ) ) {
			throw new Execution_Exception( 'page_type_immutable', 'The page type cannot be changed after draft creation.' );
		}
		$this->language->assert_request_language( $page_type, $input );
		$editorial = $this->sanitize_editorial_fields( $input, $page_type );
		$target = $this->targets->resolve( $page_type );
		$this->assert_post_contract( $post, $page_type, $target );
		$this->targets->assert_current_user_can_edit( $post, true );

		$assembled  = $this->assembler->assemble( $page_type, $input['sections'] ?? array() );
		$validation = $this->validator->validate( $page_type, $assembled['content'] );
		$validation = $this->add_editorial_language_issues( $validation, $page_type, $input, $editorial );
		if ( ! $validation['valid'] ) {
			throw new Execution_Exception( 'validation_failed', 'The assembled content failed validation and was not saved.' );
		}

		$update = array(
			'ID'           => $post_id,
			'post_type'    => $target['post_type'],
			'post_status'  => 'draft',
			'post_content' => $assembled['content'],
			'post_excerpt' => $editorial['excerpt'],
			'meta_input'   => array(
				Target_Resolver::TEMPLATE_META => $this->targets->template_meta_value( $target ),
				self::YOAST_METADESC_META => $editorial['meta_description'],
			),
		);
		$update['meta_input'] = array_merge( $update['meta_input'], $structured_fields );
		if ( array_key_exists( 'title', $input ) ) {
			$update['post_title'] = $this->sanitize_title( $input['title'] );
		}
		if ( array_key_exists( 'slug', $input ) ) {
			$update['post_name'] = $this->sanitize_slug( $input['slug'] );
		}

		global $wpdb;
		$this->begin_locked_update( $post_id, $expected, $expected_revision, $page_type, $target );
		try {
			$result = wp_update_post( wp_slash( $update ), true );
			if ( is_wp_error( $result ) ) {
				throw new Execution_Exception( 'draft_update_failed', $result->get_error_message() );
			}
			if ( false === $wpdb->query( 'COMMIT' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The explicit transaction is required for optimistic-concurrency guarantees and has no cacheable result.
				throw new Execution_Exception( 'commit_failed', 'The database could not safely commit the draft update.' );
			}
		} catch ( \Throwable $error ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Rolls back the explicit concurrency transaction.
			clean_post_cache( $post_id );
			throw $error;
		}

		$updated = get_post( $post_id );
		if ( ! $updated instanceof \WP_Post ) {
			throw new Execution_Exception( 'draft_read_after_update_failed', 'The draft was updated but could not be read back.' );
		}
		$this->assert_post_contract( $updated, $page_type, $target );

		$response               = $this->describe( $updated );
		$response['validation'] = $validation;
		$response['updated_fields'] = array_values( array_keys( $structured_fields ) );
		return $response;
	}

	/**
	 * Atomically update an already validated set of registered post-meta fields.
	 *
	 * Field discovery, allowlisting, type validation, and sanitization belong to
	 * Content_Field_Materializer. This method owns only the draft, target, and
	 * optimistic-concurrency boundary shared by every Composer write.
	 *
	 * @param array<string,mixed> $fields
	 */
	public function update_owned_meta_fields( array $input, array $fields ): array {
		$this->assert_no_forbidden_input( $input );
		if ( empty( $fields ) ) {
			throw new Execution_Exception( 'content_fields_empty', 'At least one approved content field is required.' );
		}

		$post_id           = absint( $input['post_id'] ?? 0 );
		$expected_modified = $this->normalize_expected_modified_gmt( (string) ( $input['expected_modified_gmt'] ?? '' ) );
		$expected_revision = $this->sanitize_revision( $input['expected_revision'] ?? '' );
		$post              = $this->get_owned_draft( $post_id );
		$page_type         = sanitize_key( (string) get_post_meta( $post_id, self::PAGE_TYPE_META, true ) );
		if ( $page_type !== sanitize_key( (string) ( $input['page_type'] ?? '' ) ) ) {
			throw new Execution_Exception( 'page_type_immutable', 'The page type cannot be changed during a content-field update.' );
		}
		$target = $this->targets->resolve( $page_type );
		$this->assert_post_contract( $post, $page_type, $target );
		$this->targets->assert_current_user_can_edit( $post, true );

		global $wpdb;
		$this->begin_locked_update( $post_id, $expected_modified, $expected_revision, $page_type, $target );
		try {
			foreach ( $fields as $meta_key => $value ) {
				update_post_meta( $post_id, (string) $meta_key, $value );
			}
			$result = wp_update_post(
				array(
					'ID'          => $post_id,
					'post_status' => 'draft',
				),
				true
			);
			if ( is_wp_error( $result ) ) {
				throw new Execution_Exception( 'content_field_update_failed', $result->get_error_message() );
			}
			if ( false === $wpdb->query( 'COMMIT' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Commits the locked content-field update.
				throw new Execution_Exception( 'commit_failed', 'The database could not safely commit the content-field update.' );
			}
		} catch ( \Throwable $error ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Rolls back the explicit concurrency transaction.
			clean_post_cache( $post_id );
			throw $error;
		}

		clean_post_cache( $post_id );
		$updated = $this->get_owned_draft( $post_id );
		$response = $this->describe( $updated );
		$response['updated_fields'] = array_values( array_keys( $fields ) );
		return $response;
	}

	/**
	 * Atomically assign an already validated term set to an assigned draft.
	 *
	 * Taxonomy policy, term discovery, and term validation belong to
	 * Taxonomy_Term_Service. This method retains the same ownership, immutable
	 * target, and optimistic-concurrency boundary as every other draft write.
	 *
	 * @param int[] $term_ids
	 */
	public function update_owned_taxonomy_terms( array $input, string $taxonomy, array $term_ids, bool $append, int $maximum_items ): array {
		$this->assert_no_forbidden_input( $input );
		$taxonomy = sanitize_key( $taxonomy );
		if ( '' === $taxonomy || empty( $term_ids ) ) {
			throw new Execution_Exception( 'taxonomy_assignment_invalid', 'A taxonomy and at least one term ID are required.' );
		}

		$post_id           = absint( $input['post_id'] ?? 0 );
		$expected_modified = $this->normalize_expected_modified_gmt( (string) ( $input['expected_modified_gmt'] ?? '' ) );
		$expected_revision = $this->sanitize_revision( $input['expected_revision'] ?? '' );
		$post              = $this->get_owned_draft( $post_id );
		$page_type         = sanitize_key( (string) get_post_meta( $post_id, self::PAGE_TYPE_META, true ) );
		if ( $page_type !== sanitize_key( (string) ( $input['page_type'] ?? '' ) ) ) {
			throw new Execution_Exception( 'page_type_immutable', 'The page type cannot be changed during taxonomy assignment.' );
		}
		$target = $this->targets->resolve( $page_type );
		$this->assert_post_contract( $post, $page_type, $target );
		$this->targets->assert_current_user_can_edit( $post, true );
		if ( ! is_object_in_taxonomy( $post->post_type, $taxonomy ) ) {
			throw new Execution_Exception( 'taxonomy_post_type_mismatch', 'The taxonomy is not registered for this draft post type.' );
		}

		global $wpdb;
		$this->begin_locked_update( $post_id, $expected_modified, $expected_revision, $page_type, $target );
		try {
			$final_ids = $term_ids;
			if ( $append ) {
				$existing = wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'ids' ) );
				if ( is_wp_error( $existing ) ) {
					throw new Execution_Exception( 'taxonomy_term_inspection_failed', $existing->get_error_message() );
				}
				$final_ids = array_values( array_unique( array_merge( array_map( 'absint', (array) $existing ), $term_ids ) ) );
			}
			if ( count( $final_ids ) > min( 100, max( 1, $maximum_items ) ) ) {
				throw new Execution_Exception( 'taxonomy_term_limit_exceeded', 'The assignment exceeds the Site Contract taxonomy-term limit.' );
			}
			$assigned = wp_set_object_terms( $post_id, $term_ids, $taxonomy, $append );
			if ( is_wp_error( $assigned ) ) {
				throw new Execution_Exception( 'taxonomy_assignment_failed', $assigned->get_error_message() );
			}
			$result = wp_update_post( array( 'ID' => $post_id, 'post_status' => 'draft' ), true );
			if ( is_wp_error( $result ) ) {
				throw new Execution_Exception( 'taxonomy_assignment_failed', $result->get_error_message() );
			}
			if ( false === $wpdb->query( 'COMMIT' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Commits the locked taxonomy relationship update.
				throw new Execution_Exception( 'commit_failed', 'The database could not safely commit the taxonomy assignment.' );
			}
		} catch ( \Throwable $error ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Rolls back the explicit concurrency transaction.
			clean_post_cache( $post_id );
			clean_object_term_cache( $post_id, $post->post_type );
			throw $error;
		}

		clean_post_cache( $post_id );
		clean_object_term_cache( $post_id, $post->post_type );
		$updated = $this->get_owned_draft( $post_id );
		$response = $this->describe( $updated );
		$response['assigned_term_ids'] = array_values( array_map( 'absint', $term_ids ) );
		return $response;
	}

	public function assign_featured_image( array $input, int $attachment_id ): array {
		$this->assert_no_forbidden_input( $input );
		if ( $attachment_id < 1 || ! wp_attachment_is_image( $attachment_id ) || ! current_user_can( 'read_post', $attachment_id ) ) {
			throw new Execution_Exception( 'featured_image_invalid', 'The requested featured image is not a readable Media Library image.' );
		}
		$post_id           = absint( $input['post_id'] ?? 0 );
		$expected_modified = $this->normalize_expected_modified_gmt( (string) ( $input['expected_modified_gmt'] ?? '' ) );
		$expected_revision = $this->sanitize_revision( $input['expected_revision'] ?? '' );
		$post              = $this->get_owned_draft( $post_id );
		$page_type         = sanitize_key( (string) get_post_meta( $post_id, self::PAGE_TYPE_META, true ) );
		if ( $page_type !== sanitize_key( (string) ( $input['page_type'] ?? '' ) ) ) {
			throw new Execution_Exception( 'page_type_immutable', 'The page type cannot be changed during featured-image assignment.' );
		}
		$target = $this->targets->resolve( $page_type );
		$this->assert_post_contract( $post, $page_type, $target );
		$this->targets->assert_current_user_can_edit( $post, true );
		if ( get_post_thumbnail_id( $post_id ) === $attachment_id ) {
			return $this->describe( $post );
		}

		global $wpdb;
		$this->begin_locked_update( $post_id, $expected_modified, $expected_revision, $page_type, $target );
		try {
			if ( ! set_post_thumbnail( $post_id, $attachment_id ) ) {
				throw new Execution_Exception( 'featured_image_assignment_failed', 'WordPress could not assign the featured image.' );
			}
			$result = wp_update_post( array( 'ID' => $post_id, 'post_status' => 'draft' ), true );
			if ( is_wp_error( $result ) ) {
				throw new Execution_Exception( 'featured_image_assignment_failed', $result->get_error_message() );
			}
			if ( false === $wpdb->query( 'COMMIT' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Commits the locked featured-image update.
				throw new Execution_Exception( 'commit_failed', 'The database could not safely commit the featured-image update.' );
			}
		} catch ( \Throwable $error ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Rolls back the explicit concurrency transaction.
			clean_post_cache( $post_id );
			throw $error;
		}
		clean_post_cache( $post_id );
		$updated = $this->get_owned_draft( $post_id );
		$response = $this->describe( $updated );
		$response['featured_image_id'] = $attachment_id;
		return $response;
	}

	public function insert_or_update_blocks( array $input ): array {
		$this->assert_no_forbidden_input( $input );
		$post_id           = absint( $input['post_id'] ?? 0 );
		$expected_modified = $this->normalize_expected_modified_gmt( (string) ( $input['expected_modified_gmt'] ?? '' ) );
		$expected_revision = $this->sanitize_revision( $input['expected_revision'] ?? '' );
		$post              = $this->get_owned_draft( $post_id );
		$page_type         = sanitize_key( (string) get_post_meta( $post_id, self::PAGE_TYPE_META, true ) );
		$target            = $this->targets->resolve( $page_type );
		$blueprint         = $this->config->get_blueprint( $page_type );
		if ( 'structured-record' === $blueprint['composition_mode'] ) {
			throw new Execution_Exception( 'structured_record_block_mutation_forbidden', 'Structured-record drafts cannot receive Gutenberg body blocks.' );
		}
		$this->assert_post_contract( $post, $page_type, $target );
		$this->targets->assert_current_user_can_edit( $post, true );

		$tree = $this->trees->apply_to_content(
			(string) $post->post_content,
			isset( $input['blocks'] ) && is_array( $input['blocks'] ) ? $input['blocks'] : array(),
			(string) ( $input['mode'] ?? '' ),
			isset( $input['path'] ) && is_array( $input['path'] ) ? $input['path'] : array(),
			$page_type
		);
		$validation = $this->validator->validate( $page_type, $tree['content'] );
		if ( ! $validation['valid'] ) {
			throw new Execution_Exception( 'validation_failed', 'The updated page failed its blueprint or block-tree validation and was not saved.' );
		}

		$update = array(
			'ID'           => $post_id,
			'post_type'    => $target['post_type'],
			'post_status'  => 'draft',
			'post_content' => $tree['content'],
			'meta_input'   => array(
				Target_Resolver::TEMPLATE_META => $this->targets->template_meta_value( $target ),
			),
		);

		global $wpdb;
		$this->begin_locked_update( $post_id, $expected_modified, $expected_revision, $page_type, $target );
		try {
			$result = wp_update_post( wp_slash( $update ), true );
			if ( is_wp_error( $result ) ) {
				throw new Execution_Exception( 'draft_block_update_failed', $result->get_error_message() );
			}
			clean_post_cache( $post_id );
			$stored_content = get_post_field( 'post_content', $post_id, 'raw' );
			if ( ! is_string( $stored_content ) || ! hash_equals( hash( 'sha256', $tree['content'] ), hash( 'sha256', $stored_content ) ) ) {
				throw new Execution_Exception( 'validated_html_not_preserved', 'WordPress altered the validated block content during save, so the update was rolled back.' );
			}
			if ( false === $wpdb->query( 'COMMIT' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The explicit transaction is required for optimistic-concurrency guarantees and has no cacheable result.
				throw new Execution_Exception( 'commit_failed', 'The database could not safely commit the block update.' );
			}
		} catch ( \Throwable $error ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Rolls back the explicit concurrency transaction.
			clean_post_cache( $post_id );
			throw $error;
		}

		clean_post_cache( $post_id );
		$updated = get_post( $post_id );
		if ( ! $updated instanceof \WP_Post ) {
			throw new Execution_Exception( 'draft_read_after_update_failed', 'The draft was updated but could not be read back.' );
		}
		$this->assert_post_contract( $updated, $page_type, $target );
		$response                       = $this->describe( $updated );
		$response['validation']         = $validation;
		$response['block_tree']         = $tree['statistics'];
		$response['classic_html_kept']  = in_array( 'core/freeform', $tree['statistics']['block_names'], true );
		return $response;
	}

	/**
	 * Inspect an existing draft without changing content, author, or ownership.
	 *
	 * The returned modification time and content hash must be echoed to adopt().
	 */
	public function inspect_for_adoption( array $input ): array {
		$this->assert_no_forbidden_input( $input );
		$post_id   = absint( $input['post_id'] ?? 0 );
		$page_type = sanitize_key( (string) ( $input['page_type'] ?? '' ) );
		$target    = $this->targets->resolve( $page_type );
		$post      = $this->get_adoptable_draft( $post_id, $page_type, $target );
		$content    = (string) $post->post_content;
		$validation = $this->validator->validate( $page_type, $content );
		$seo        = $this->validate_stored_editorial_fields(
			wp_strip_all_tags( (string) $post->post_excerpt ),
			wp_strip_all_tags( (string) get_post_meta( $post_id, self::YOAST_METADESC_META, true ) ),
			$page_type
		);
		$assigned_agent_id = absint( get_post_meta( $post_id, self::ASSIGNED_AGENT_META, true ) );

		return array(
			'post_id'               => $post_id,
			'title'                 => get_the_title( $post ),
			'status'                => $post->post_status,
			'page_type'             => $page_type,
			'post_type'             => $post->post_type,
			'post_author_id'        => (int) $post->post_author,
			'assigned_agent_user_id' => $assigned_agent_id,
			'already_assigned'       => get_current_user_id() === $assigned_agent_id,
			'modified_gmt'           => $this->modified_gmt_token( $post->post_modified_gmt ),
			'content_hash'           => hash( 'sha256', $content ),
			'template'               => $this->targets->public_contract( $target )['template'],
			'validation'             => $validation,
			'seo'                    => $seo,
			'adoptable'              => true,
		);
	}

	/**
	 * Assign an existing validated-target draft to the current agent.
	 *
	 * This operation deliberately preserves post_author and post_content.
	 */
	public function adopt( array $input ): array {
		$this->assert_no_forbidden_input( $input );
		if ( true !== ( $input['confirm_adoption'] ?? false ) ) {
			throw new Execution_Exception( 'adoption_confirmation_required', 'Draft adoption requires explicit confirmation.' );
		}

		$post_id               = absint( $input['post_id'] ?? 0 );
		$page_type             = sanitize_key( (string) ( $input['page_type'] ?? '' ) );
		$expected_modified_gmt = $this->normalize_expected_modified_gmt( (string) ( $input['expected_modified_gmt'] ?? '' ) );
		$expected_content_hash = strtolower( trim( (string) ( $input['expected_content_hash'] ?? '' ) ) );
		if ( '' === $expected_modified_gmt ) {
			throw new Execution_Exception( 'invalid_modified_gmt', 'A valid expected_modified_gmt value from the adoption inspection is required.' );
		}
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $expected_content_hash ) ) {
			throw new Execution_Exception( 'invalid_content_hash', 'A valid content hash from the adoption inspection is required.' );
		}

		$target     = $this->targets->resolve( $page_type );
		$inspection = $this->inspect_for_adoption(
			array(
				'post_id'   => $post_id,
				'page_type' => $page_type,
			)
		);
		if (
			! hash_equals( $expected_modified_gmt, (string) $inspection['modified_gmt'] )
			|| ! hash_equals( $expected_content_hash, (string) $inspection['content_hash'] )
		) {
			throw new Execution_Exception( 'edit_conflict', 'The draft changed after it was inspected. Inspect it again before adoption.' );
		}

		$current_user_id  = get_current_user_id();
		$revision         = '';
		$already_assigned = false;

		global $wpdb;
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Row locking is required for atomic draft adoption and has no cacheable result.
			throw new Execution_Exception( 'locking_unavailable', 'The database could not start a safe draft-adoption transaction.' );
		}

		try {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- FOR UPDATE cannot be expressed through a cache-aware WordPress API.
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT ID, post_type, post_status, post_author, post_modified_gmt, post_content
					FROM {$wpdb->posts}
					WHERE ID = %d
					FOR UPDATE",
					$post_id
				),
				ARRAY_A
			);
			if ( ! is_array( $row ) || (string) $target['post_type'] !== (string) ( $row['post_type'] ?? '' ) ) {
				throw new Execution_Exception( 'draft_not_found', 'The requested content draft does not exist.' );
			}
			if ( 'draft' !== (string) $row['post_status'] ) {
				throw new Execution_Exception( 'not_a_draft', 'Only drafts can be adopted.' );
			}
			$locked_modified = $this->modified_gmt_token( (string) $row['post_modified_gmt'] );
			$locked_hash     = hash( 'sha256', (string) $row['post_content'] );
			if (
				! hash_equals( $expected_modified_gmt, $locked_modified )
				|| ! hash_equals( $expected_content_hash, $locked_hash )
			) {
				throw new Execution_Exception( 'edit_conflict', 'The draft changed during adoption. Inspect it again before retrying.' );
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Assignment metadata must be locked in the same transaction as the post row.
			$meta_rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT meta_key, meta_value
					FROM {$wpdb->postmeta}
					WHERE post_id = %d
					AND meta_key IN (%s, %s, %s, %s, %s, %s, %s)
					FOR UPDATE",
					$post_id,
					self::OWNED_META,
					self::PAGE_TYPE_META,
					self::REVISION_META,
					self::POST_TYPE_META,
					self::TEMPLATE_META,
					self::ASSIGNED_AGENT_META,
					Target_Resolver::TEMPLATE_META
				),
				ARRAY_A
			);
			$meta = array();
			foreach ( is_array( $meta_rows ) ? $meta_rows : array() as $meta_row ) {
				$meta[ $meta_row['meta_key'] ] = $meta_row['meta_value'];
			}

			$this->assert_adoption_meta_contract( $meta, $page_type, $target );
			if (
				'1' !== (string) ( $meta[ self::OWNED_META ] ?? '' )
				&& ! $this->config->get_content_access( (string) $row['post_type'] )['adopt_drafts']
			) {
				throw new Execution_Exception( 'adoption_not_allowed', 'The active Site Contract does not allow Composer to adopt drafts of this post type.' );
			}
			$assigned_agent_id = absint( $meta[ self::ASSIGNED_AGENT_META ] ?? 0 );
			if ( 0 !== $assigned_agent_id && $current_user_id !== $assigned_agent_id ) {
				throw new Execution_Exception( 'draft_assigned_to_other_agent', 'This draft is already assigned to a different SmartCloud agent.' );
			}

			$already_assigned = $current_user_id === $assigned_agent_id
				&& '1' === (string) ( $meta[ self::OWNED_META ] ?? '' );
			$revision = $this->revision_or_new( $meta[ self::REVISION_META ] ?? '' );
			if ( ! $already_assigned ) {
				$revision = wp_generate_uuid4();
				update_post_meta( $post_id, self::OWNED_META, '1' );
				update_post_meta( $post_id, self::PAGE_TYPE_META, $page_type );
				update_post_meta( $post_id, self::REVISION_META, $revision );
				update_post_meta( $post_id, self::POST_TYPE_META, $target['post_type'] );
				update_post_meta( $post_id, self::TEMPLATE_META, $this->targets->template_identity( $target ) );
				update_post_meta( $post_id, self::ASSIGNED_AGENT_META, $current_user_id );
				update_post_meta( $post_id, self::ASSIGNMENT_SOURCE_META, 'adopted' );
				update_post_meta( $post_id, self::ADOPTED_GMT_META, current_time( 'mysql', true ) );
			} elseif ( ! hash_equals( $revision, (string) ( $meta[ self::REVISION_META ] ?? '' ) ) ) {
				update_post_meta( $post_id, self::REVISION_META, $revision );
			}

			if ( false === $wpdb->query( 'COMMIT' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Commits the explicit adoption transaction.
				throw new Execution_Exception( 'commit_failed', 'The database could not safely commit the draft adoption.' );
			}
		} catch ( \Throwable $error ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Rolls back the explicit adoption transaction.
			clean_post_cache( $post_id );
			throw $error;
		}

		clean_post_cache( $post_id );
		$post                              = $this->get_owned_draft( $post_id );
		$result                            = $this->describe( $post );
		$result['adopted']                 = ! $already_assigned;
		$result['already_assigned']        = $already_assigned;
		$result['post_author_preserved']   = (int) $post->post_author === (int) $inspection['post_author_id'];
		$result['content_preserved']       = hash_equals( $expected_content_hash, hash( 'sha256', (string) $post->post_content ) );
		$result['validation']              = $inspection['validation'];
		$result['seo']                     = $inspection['seo'];
		$result['revision']                = $revision;
		return $result;
	}

	/**
	 * Rotate the revision token after every WordPress update, including a human
	 * editor save. This supplements second-resolution post_modified_gmt.
	 */
	public function rotate_revision( int $post_id, \WP_Post $post_after, \WP_Post $post_before ): void {
		if ( '1' !== (string) get_post_meta( $post_id, self::OWNED_META, true ) ) {
			return;
		}
		update_post_meta( $post_id, self::REVISION_META, wp_generate_uuid4() );
	}

	/**
	 * Acquire an InnoDB row lock and re-check every mutable ownership boundary.
	 * This closes the check/write race between modified_gmt validation and
	 * wp_update_post() while retaining normal WordPress hooks and revisions.
	 */
	private function begin_locked_update( int $post_id, string $expected_modified, string $expected_revision, string $expected_page_type, array $target ): void {
		global $wpdb;

		if ( '' === $expected_modified ) {
			throw new Execution_Exception( 'invalid_modified_gmt', 'A valid expected_modified_gmt value is required.' );
		}
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Row locking requires an explicit transaction and has no cacheable result.
			throw new Execution_Exception( 'locking_unavailable', 'The database could not start a safe draft update transaction.' );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- FOR UPDATE cannot be expressed through a cache-aware WordPress API.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT ID, post_type, post_status, post_author, post_modified_gmt
				FROM {$wpdb->posts}
				WHERE ID = %d
				FOR UPDATE",
				$post_id
			),
			ARRAY_A
		);

		try {
			if ( ! is_array( $row ) || (string) $target['post_type'] !== $row['post_type'] ) {
				throw new Execution_Exception( 'draft_not_found', 'The requested content draft does not exist.' );
			}
			if ( 'draft' !== $row['post_status'] ) {
				throw new Execution_Exception( 'not_a_draft', 'Only drafts can be updated.' );
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The ownership metadata must be locked in the same transaction as the post row.
			$meta_rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT meta_key, meta_value
					FROM {$wpdb->postmeta}
					WHERE post_id = %d
					AND meta_key IN (%s, %s, %s, %s, %s, %s, %s)
					FOR UPDATE",
					$post_id,
					self::OWNED_META,
					self::PAGE_TYPE_META,
					self::REVISION_META,
					self::POST_TYPE_META,
					self::TEMPLATE_META,
					self::ASSIGNED_AGENT_META,
					Target_Resolver::TEMPLATE_META
				),
				ARRAY_A
			);
			$meta = array();
			foreach ( is_array( $meta_rows ) ? $meta_rows : array() as $meta_row ) {
				$meta[ $meta_row['meta_key'] ] = $meta_row['meta_value'];
			}
			if ( '1' !== (string) ( $meta[ self::OWNED_META ] ?? '' ) ) {
				throw new Execution_Exception( 'not_agent_owned', 'The draft was not created by SmartCloud Agent Composer.' );
			}
			$assigned_agent_id = absint( $meta[ self::ASSIGNED_AGENT_META ] ?? 0 );
			if ( 0 === $assigned_agent_id && get_current_user_id() === (int) $row['post_author'] ) {
				$assigned_agent_id = get_current_user_id();
				update_post_meta( $post_id, self::ASSIGNED_AGENT_META, $assigned_agent_id );
				update_post_meta( $post_id, self::ASSIGNMENT_SOURCE_META, 'created' );
			}
			if ( get_current_user_id() !== $assigned_agent_id ) {
				throw new Execution_Exception( 'not_draft_owner', 'The current agent is not assigned to this draft.' );
			}
			if ( $expected_page_type !== sanitize_key( (string) ( $meta[ self::PAGE_TYPE_META ] ?? '' ) ) ) {
				throw new Execution_Exception( 'page_type_immutable', 'The stored page type changed while the draft was being prepared.' );
			}
			if ( (string) $target['post_type'] !== sanitize_key( (string) ( $meta[ self::POST_TYPE_META ] ?? '' ) ) ) {
				throw new Execution_Exception( 'post_type_immutable', 'The stored target post type changed while the draft was being prepared.' );
			}
			if ( ! hash_equals( $this->targets->template_identity( $target ), (string) ( $meta[ self::TEMPLATE_META ] ?? '' ) ) ) {
				throw new Execution_Exception( 'template_immutable', 'The stored target template changed while the draft was being prepared.' );
			}
			$stored_template = trim( (string) ( $meta[ Target_Resolver::TEMPLATE_META ] ?? '' ) );
			$stored_template = '' === $stored_template || 'default' === $stored_template ? 'default' : $stored_template;
			if ( ! hash_equals( $this->targets->template_meta_value( $target ), $stored_template ) ) {
				throw new Execution_Exception( 'template_immutable', 'The assigned WordPress template changed while the draft was being prepared.' );
			}
			$current_revision = $this->sanitize_revision( $meta[ self::REVISION_META ] ?? '' );
			if ( ! hash_equals( $current_revision, $expected_revision ) ) {
				throw new Execution_Exception( 'edit_conflict', 'The draft revision changed after it was read. Fetch it again before updating.' );
			}
			$current_modified = $this->modified_gmt_token( (string) $row['post_modified_gmt'] );
			if ( ! hash_equals( $current_modified, $expected_modified ) ) {
				throw new Execution_Exception( 'edit_conflict', 'The draft changed after it was read. Fetch it again before updating.' );
			}
		} catch ( \Throwable $error ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Rolls back the explicit concurrency transaction.
			throw $error;
		}
	}

	public function get( int $post_id ): array {
		return $this->describe( $this->get_owned_draft( $post_id ) );
	}

	public function list_content_drafts( array $input ): array {
		$status     = $this->sanitize_list_status( $input['status'] ?? 'draft' );
		$post_type  = $this->sanitize_list_post_type( $input['post_type'] ?? '' );
		$page_type  = sanitize_key( (string) ( $input['page_type'] ?? '' ) );
		$assignment = $this->sanitize_assignment_filter( $input['assignment'] ?? 'any' );
		$limit      = min( 50, max( 1, absint( $input['limit'] ?? 20 ) ) );
		$offset     = min( 5000, max( 0, absint( $input['offset'] ?? 0 ) ) );
		$orderby    = $this->sanitize_list_orderby( $input['orderby'] ?? 'modified' );
		$order      = 'ASC' === strtoupper( (string) ( $input['order'] ?? 'DESC' ) ) ? 'ASC' : 'DESC';
		$search     = sanitize_text_field( (string) ( $input['search'] ?? '' ) );

		$query_args = array(
			'post_type'              => '' !== $post_type ? $post_type : $this->targets->registered_allowed_post_types(),
			'post_status'            => 'any' === $status ? array( 'draft', 'pending', 'future', 'private', 'publish' ) : $status,
			'posts_per_page'         => 200,
			'orderby'                => $orderby,
			'order'                  => $order,
			's'                      => $search,
			'ignore_sticky_posts'    => true,
			'fields'                 => 'all',
			'update_post_meta_cache' => true,
			'update_post_term_cache' => false,
		);

		$items            = array();
		$visible_total    = 0;
		$candidate_offset = 0;
		do {
			$query_args['offset'] = $candidate_offset;
			$query = new \WP_Query( $query_args );
			foreach ( $query->posts as $post ) {
				if ( ! $post instanceof \WP_Post || ! $this->can_list_post( $post ) ) {
					continue;
				}
				$item = $this->summarize_list_item( $post );
				if ( ! $this->matches_page_type_filter( $item, $page_type ) || ! $this->matches_assignment_filter( $item, $assignment ) ) {
					continue;
				}
				if ( $visible_total >= $offset && count( $items ) < $limit ) {
					$items[] = $item;
				}
				++$visible_total;
			}
			$candidate_offset += count( $query->posts );
		} while ( ! empty( $query->posts ) && $candidate_offset < (int) $query->found_posts );

		return array(
			'purpose'     => 'editable-content-discovery',
			'items'       => $items,
			'count'       => count( $items ),
			'total'       => $visible_total,
			'has_more'    => $offset + count( $items ) < $visible_total,
			'limit'       => $limit,
			'offset'      => $offset,
			'filters'     => array(
				'status'     => $status,
				'post_type'  => $post_type,
				'page_type'  => $page_type,
				'assignment' => $assignment,
				'search'     => $search,
				'orderby'    => $orderby,
				'order'      => $order,
			),
			'content_included' => false,
			'relation_target_lookup_supported' => false,
			'relation_target_lookup_ability'   => 'smartcloud-agent-composer/search-relation-targets',
		);
	}

	public function inspect_content_item( array $input ): array {
		$this->assert_no_forbidden_input( $input );
		$post_id = absint( $input['post_id'] ?? 0 );
		$page_type = sanitize_key( (string) ( $input['page_type'] ?? '' ) );
		$target = $this->targets->resolve( $page_type );
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || $post->post_type !== (string) $target['post_type'] ) {
			throw new Execution_Exception( 'content_item_not_found', 'The requested content item does not match the selected blueprint.' );
		}
		$access = $this->config->get_content_access( $post->post_type );
		$composer_owned = '1' === (string) get_post_meta( $post_id, self::OWNED_META, true );
		if ( ! $composer_owned && ! $access['read'] ) {
			throw new Execution_Exception( 'content_read_not_allowed', 'The active Site Contract does not allow Composer to read this post type.' );
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			throw new Execution_Exception( 'content_read_forbidden', 'The current user cannot read this content through Composer.' );
		}
		$content = (string) $post->post_content;
		return array(
			'post_id'       => $post_id,
			'post_type'     => $post->post_type,
			'page_type'     => $page_type,
			'status'        => $post->post_status,
			'title'         => get_the_title( $post ),
			'slug'          => $post->post_name,
			'modified_gmt'  => $this->modified_gmt_token( $post->post_modified_gmt ),
			'content_hash'  => hash( 'sha256', $content ),
			'content'       => $content,
			'excerpt'       => (string) $post->post_excerpt,
			'meta_description' => (string) get_post_meta( $post_id, self::YOAST_METADESC_META, true ),
			'composer_owned' => $composer_owned,
			'cloneable'     => ! $composer_owned && $access['clone'],
			'validation'    => $this->validator->validate( $page_type, $content ),
		);
	}

	public function clone_content_item( array $input ): array {
		$this->assert_no_forbidden_input( $input );
		if ( true !== ( $input['confirm_clone'] ?? false ) ) {
			throw new Execution_Exception( 'clone_confirmation_required', 'Cloning existing content requires explicit confirmation.' );
		}
		$source = $this->inspect_content_item( $input );
		if ( empty( $source['cloneable'] ) ) {
			throw new Execution_Exception( 'content_clone_not_allowed', 'The active Site Contract does not allow Composer to clone this content item.' );
		}
		$expected_modified = $this->normalize_expected_modified_gmt( (string) ( $input['expected_modified_gmt'] ?? '' ) );
		$expected_hash = strtolower( trim( (string) ( $input['expected_content_hash'] ?? '' ) ) );
		if ( ! hash_equals( $expected_modified, (string) $source['modified_gmt'] ) || ! hash_equals( $expected_hash, (string) $source['content_hash'] ) ) {
			throw new Execution_Exception( 'edit_conflict', 'The source item changed after it was inspected. Inspect it again before cloning.' );
		}

		$page_type = sanitize_key( (string) $source['page_type'] );
		$target = $this->targets->resolve( $page_type );
		$this->targets->assert_current_user_can_create( $target );
		$user_id = get_current_user_id();
		$key = $this->sanitize_idempotency_key( $input['idempotency_key'] ?? '' );
		$title = isset( $input['title'] ) ? $this->sanitize_title( $input['title'] ) : $this->sanitize_title( 'Copy of ' . (string) $source['title'] );
		$slug = isset( $input['slug'] ) ? $this->sanitize_slug( $input['slug'] ) : '';
		$lock_name = $this->acquire_idempotency_lock( $user_id, $key );
		try {
			$existing = $this->find_idempotent_draft( $user_id, $key, $page_type, $target );
			if ( $existing ) {
				return $this->describe( $existing, true );
			}
			$post_id = wp_insert_post(
				wp_slash(
					array(
						'post_type'    => $target['post_type'],
						'post_status'  => 'draft',
						'post_author'  => $user_id,
						'post_title'   => $title,
						'post_name'    => $slug,
						'post_content' => (string) $source['content'],
						'post_excerpt' => (string) $source['excerpt'],
						'meta_input'   => array(
							self::OWNED_META              => '1',
							self::PAGE_TYPE_META          => $page_type,
							self::IDEMPOTENCY_META        => $key,
							self::REVISION_META           => wp_generate_uuid4(),
							self::POST_TYPE_META          => $target['post_type'],
							self::TEMPLATE_META           => $this->targets->template_identity( $target ),
							self::ASSIGNED_AGENT_META     => $user_id,
							self::ASSIGNMENT_SOURCE_META  => 'cloned',
							self::CLONED_FROM_META        => (int) $source['post_id'],
							Target_Resolver::TEMPLATE_META => $this->targets->template_meta_value( $target ),
							self::YOAST_METADESC_META     => (string) $source['meta_description'],
						),
					)
				),
				true
			);
			if ( $post_id instanceof \WP_Error ) {
				throw new Execution_Exception( 'content_clone_failed', $post_id->get_error_message() );
			}
			$post = get_post( (int) $post_id );
			if ( ! $post instanceof \WP_Post ) {
				throw new Execution_Exception( 'draft_read_after_clone_failed', 'The clone was created but could not be read back.' );
			}
			$post = $this->initialize_created_modified_gmt( $post );
			$this->assert_post_contract( $post, $page_type, $target );
			$result = $this->describe( $post );
			$result['cloned_from_post_id'] = (int) $source['post_id'];
			$result['source_preserved'] = hash_equals( (string) $source['content_hash'], hash( 'sha256', (string) $source['content'] ) );
			$result['validation'] = $source['validation'];
			return $result;
		} finally {
			$this->release_idempotency_lock( $lock_name );
		}
	}

	public function get_preview( int $post_id ): array {
		$post = $this->get_owned_draft( $post_id );
		$page_type = (string) get_post_meta( $post->ID, self::PAGE_TYPE_META, true );
		$target    = $this->targets->resolve( $page_type );
		$validation = $this->validator->validate( $page_type, (string) $post->post_content );
		$seo = $this->validate_stored_editorial_fields(
			wp_strip_all_tags( (string) $post->post_excerpt ),
			wp_strip_all_tags( (string) get_post_meta( $post->ID, self::YOAST_METADESC_META, true ) ),
			$page_type
		);
		if ( ! $seo['valid'] ) {
			$validation['valid']  = false;
			$validation['errors'] = array_merge( $validation['errors'], $seo['errors'] );
		}
		return array(
			'post_id'      => $post->ID,
			'post_type'    => $post->post_type,
			'template'     => $this->targets->public_contract( $target )['template'],
			'edit_url'     => get_edit_post_link( $post->ID, 'raw' ) ?: '',
			'preview_url'  => $this->preview_url( $post ),
			'modified_gmt' => $this->modified_gmt_token( $post->post_modified_gmt ),
			'revision'     => (string) get_post_meta( $post->ID, self::REVISION_META, true ),
			'seo'           => $seo,
			'validation'    => $validation,
		);
	}

	public function get_owned_draft( int $post_id ): \WP_Post {
		clean_post_cache( $post_id );
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			throw new Execution_Exception( 'draft_not_found', 'The requested content draft does not exist.' );
		}
		if ( 'draft' !== $post->post_status ) {
			throw new Execution_Exception( 'not_a_draft', 'Only drafts can be accessed through this write surface.' );
		}
		if ( '1' !== (string) get_post_meta( $post_id, self::OWNED_META, true ) ) {
			throw new Execution_Exception( 'not_agent_owned', 'The draft was not created by SmartCloud Agent Composer.' );
		}
		$assigned_agent_id = absint( get_post_meta( $post_id, self::ASSIGNED_AGENT_META, true ) );
		if ( 0 === $assigned_agent_id && get_current_user_id() === (int) $post->post_author ) {
			$assigned_agent_id = get_current_user_id();
			update_post_meta( $post_id, self::ASSIGNED_AGENT_META, $assigned_agent_id );
			update_post_meta( $post_id, self::ASSIGNMENT_SOURCE_META, 'created' );
		}
		if ( get_current_user_id() !== $assigned_agent_id ) {
			throw new Execution_Exception( 'not_draft_owner', 'The current agent is not assigned to this draft.' );
		}
		$page_type = sanitize_key( (string) get_post_meta( $post_id, self::PAGE_TYPE_META, true ) );
		$target    = $this->targets->resolve( $page_type );
		$this->assert_post_contract( $post, $page_type, $target );
		$this->targets->assert_current_user_can_edit( $post, true );
		return $post;
	}

	private function describe( \WP_Post $post, bool $idempotent_replay = false ): array {
		$page_type = (string) get_post_meta( $post->ID, self::PAGE_TYPE_META, true );
		$target    = $this->targets->resolve( $page_type );
		return array(
			'post_id'           => $post->ID,
			'title'             => get_the_title( $post ),
			'slug'              => $post->post_name,
			'status'            => $post->post_status,
			'page_type'         => $page_type,
			'post_type'         => $post->post_type,
			'post_author_id'    => (int) $post->post_author,
			'assigned_agent_user_id' => absint( get_post_meta( $post->ID, self::ASSIGNED_AGENT_META, true ) ),
			'assignment_source' => sanitize_key( (string) get_post_meta( $post->ID, self::ASSIGNMENT_SOURCE_META, true ) ),
			'template'          => $this->targets->public_contract( $target )['template'],
			'modified_gmt'      => $this->modified_gmt_token( $post->post_modified_gmt ),
			'revision'          => (string) get_post_meta( $post->ID, self::REVISION_META, true ),
			'excerpt'           => wp_strip_all_tags( (string) $post->post_excerpt ),
			'meta_description'  => wp_strip_all_tags( (string) get_post_meta( $post->ID, self::YOAST_METADESC_META, true ) ),
			'edit_url'          => get_edit_post_link( $post->ID, 'raw' ) ?: '',
			'preview_url'       => $this->preview_url( $post ),
			'idempotent_replay' => $idempotent_replay,
		);
	}

	private function summarize_list_item( \WP_Post $post ): array {
		$assigned_agent_id = absint( get_post_meta( $post->ID, self::ASSIGNED_AGENT_META, true ) );
		$current_user_id   = get_current_user_id();
		$composer_owned      = '1' === (string) get_post_meta( $post->ID, self::OWNED_META, true );
		$content_access      = $this->config->get_content_access( $post->post_type );
		$page_type         = sanitize_key( (string) get_post_meta( $post->ID, self::PAGE_TYPE_META, true ) );
		$template_identity = sanitize_text_field( (string) get_post_meta( $post->ID, self::TEMPLATE_META, true ) );
		$assignment_state  = 'not-composer-owned';
		if ( $composer_owned && $assigned_agent_id === $current_user_id ) {
			$assignment_state = 'current-agent';
		} elseif ( $composer_owned && 0 === $assigned_agent_id ) {
			$assignment_state = 'unassigned';
		} elseif ( $composer_owned ) {
			$assignment_state = 'other-agent';
		}

		return array(
			'post_id'                  => $post->ID,
			'title'                    => get_the_title( $post ),
			'slug'                     => $post->post_name,
			'status'                   => $post->post_status,
			'post_type'                => $post->post_type,
			'post_author_id'           => (int) $post->post_author,
			'modified_gmt'             => $this->modified_gmt_token( $post->post_modified_gmt ),
			'date_gmt'                 => $this->normalize_gmt( $post->post_date_gmt ),
			'composer_owned'             => $composer_owned,
			'page_type'                => $page_type,
			'assignment_state'         => $assignment_state,
			'assigned_agent_user_id'   => $assigned_agent_id,
			'assigned_to_current_agent' => $assigned_agent_id === $current_user_id,
			'adoptable_by_current_agent' => 'draft' === $post->post_status
				&& ( $composer_owned || $content_access['adopt_drafts'] )
				&& ( 0 === $assigned_agent_id || $assigned_agent_id === $current_user_id ),
			'readable_by_composer'       => $composer_owned || $content_access['read'],
			'cloneable_by_composer'      => ! $composer_owned && $content_access['clone'],
			'editable_by_current_user' => $this->can_list_post( $post ),
			'revision'                 => sanitize_text_field( (string) get_post_meta( $post->ID, self::REVISION_META, true ) ),
			'template_identity'        => $template_identity,
			'edit_url'                 => get_edit_post_link( $post->ID, 'raw' ) ?: '',
			'preview_url'              => $this->preview_url( $post ),
		);
	}

	private function can_list_post( \WP_Post $post ): bool {
		if ( ! in_array( $post->post_type, $this->targets->registered_allowed_post_types(), true ) ) {
			return false;
		}
		$composer_owned = '1' === (string) get_post_meta( $post->ID, self::OWNED_META, true );
		if ( ! $composer_owned ) {
			return $this->config->get_content_access( $post->post_type )['discover']
				&& current_user_can( 'edit_post', $post->ID );
		}
		if ( 'draft' !== $post->post_status ) {
			return false;
		}
		$assigned_agent_id = absint( get_post_meta( $post->ID, self::ASSIGNED_AGENT_META, true ) );
		return current_user_can( \SmartCloud\AgentComposer\Infrastructure\WordPress\Activation::CAP_USE )
			&& ( 0 === $assigned_agent_id || get_current_user_id() === $assigned_agent_id );
	}

	private function preview_url( \WP_Post $post ): string {
		$preview_url = get_preview_post_link( $post ) ?: '';
		if ( '' === $preview_url ) {
			return '';
		}

		$site_scheme = (string) wp_parse_url( home_url( '/' ), PHP_URL_SCHEME );
		if ( ! in_array( $site_scheme, array( 'http', 'https' ), true ) ) {
			return $preview_url;
		}

		return set_url_scheme( $preview_url, $site_scheme );
	}

	private function matches_page_type_filter( array $item, string $page_type ): bool {
		return '' === $page_type || $page_type === (string) ( $item['page_type'] ?? '' );
	}

	private function matches_assignment_filter( array $item, string $assignment ): bool {
		return match ( $assignment ) {
			'current-agent'    => 'current-agent' === (string) ( $item['assignment_state'] ?? '' ),
			'unassigned'       => 'unassigned' === (string) ( $item['assignment_state'] ?? '' ),
			'other-agent'      => 'other-agent' === (string) ( $item['assignment_state'] ?? '' ),
			'composer-owned'     => ! empty( $item['composer_owned'] ),
			'not-composer-owned' => empty( $item['composer_owned'] ),
			default            => true,
		};
	}

	private function sanitize_list_status( mixed $status ): string {
		$status = sanitize_key( (string) $status );
		return in_array( $status, array( 'draft', 'pending', 'future', 'private', 'publish', 'any' ), true ) ? $status : 'draft';
	}

	private function sanitize_list_post_type( mixed $post_type ): string {
		$post_type = sanitize_key( (string) $post_type );
		if ( '' === $post_type ) {
			return '';
		}
		if ( ! in_array( $post_type, $this->targets->registered_allowed_post_types(), true ) ) {
			throw new Execution_Exception( 'post_type_not_allowed', 'The requested post type is not allowed by the active design policy.' );
		}
		return $post_type;
	}

	private function sanitize_assignment_filter( mixed $assignment ): string {
		$assignment = sanitize_key( (string) $assignment );
		return in_array( $assignment, array( 'any', 'current-agent', 'unassigned', 'other-agent', 'composer-owned', 'not-composer-owned' ), true )
			? $assignment
			: 'any';
	}

	private function sanitize_list_orderby( mixed $orderby ): string {
		$orderby = (string) $orderby;
		return in_array( $orderby, array( 'modified', 'date', 'title', 'ID' ), true ) ? $orderby : 'modified';
	}

	private function find_idempotent_draft( int $user_id, string $key, string $page_type, array $target ): ?\WP_Post {
		if ( '' === $key ) {
			return null;
		}
		$query = new \WP_Query(
			array(
				'post_type'              => $this->targets->registered_allowed_post_types(),
				'post_status'            => 'draft',
				'posts_per_page'         => 1,
				'no_found_rows'          => true,
				'ignore_sticky_posts'    => true,
				'fields'                 => 'all',
				'update_post_meta_cache' => true,
				'update_post_term_cache' => false,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Idempotency is an exact lookup narrowed by assigned agent, draft status, and allowed post types.
				'meta_query'             => array(
					'relation' => 'AND',
					array( 'key' => self::OWNED_META, 'value' => '1' ),
					array( 'key' => self::IDEMPOTENCY_META, 'value' => $key ),
					array( 'key' => self::ASSIGNED_AGENT_META, 'value' => $user_id, 'type' => 'NUMERIC' ),
				),
			)
		);
		$post = $query->posts[0] ?? null;
		if ( ! $post instanceof \WP_Post ) {
			$legacy_query = new \WP_Query(
				array(
					'post_type'              => $this->targets->registered_allowed_post_types(),
					'post_status'            => 'draft',
					'author'                 => $user_id,
					'posts_per_page'         => 1,
					'no_found_rows'          => true,
					'ignore_sticky_posts'    => true,
					'fields'                 => 'all',
					'update_post_meta_cache' => true,
					'update_post_term_cache' => false,
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Backward-compatible exact lookup for a pre-0.6.0 draft.
					'meta_query'             => array(
						'relation' => 'AND',
						array( 'key' => self::OWNED_META, 'value' => '1' ),
						array( 'key' => self::IDEMPOTENCY_META, 'value' => $key ),
						array( 'key' => self::ASSIGNED_AGENT_META, 'compare' => 'NOT EXISTS' ),
					),
				)
			);
			$post = $legacy_query->posts[0] ?? null;
			if ( ! $post instanceof \WP_Post ) {
				return null;
			}
			update_post_meta( $post->ID, self::ASSIGNED_AGENT_META, $user_id );
			update_post_meta( $post->ID, self::ASSIGNMENT_SOURCE_META, 'created' );
		}
		if ( $page_type !== (string) get_post_meta( $post->ID, self::PAGE_TYPE_META, true ) || (string) $target['post_type'] !== $post->post_type ) {
			throw new Execution_Exception( 'idempotency_key_conflict', 'This idempotency key already belongs to a different draft target.' );
		}
		$this->assert_post_contract( $post, $page_type, $target );
		return $post;
	}

	private function assert_post_contract( \WP_Post $post, string $page_type, array $target ): void {
		$stored_page_type = sanitize_key( (string) get_post_meta( $post->ID, self::PAGE_TYPE_META, true ) );
		if ( $page_type !== $stored_page_type ) {
			throw new Execution_Exception( 'page_type_immutable', 'The stored blueprint page type does not match this draft.' );
		}

		$stored_post_type = sanitize_key( (string) get_post_meta( $post->ID, self::POST_TYPE_META, true ) );
		$stored_template  = (string) get_post_meta( $post->ID, self::TEMPLATE_META, true );
		if ( '' === $stored_post_type && '' === $stored_template ) {
			if ( $post->post_type !== (string) $target['post_type'] ) {
				throw new Execution_Exception( 'legacy_draft_target_mismatch', 'This version 0.1.x draft uses a different post type than the current blueprint and cannot be migrated automatically.' );
			}
			update_post_meta( $post->ID, self::POST_TYPE_META, $target['post_type'] );
			update_post_meta( $post->ID, self::TEMPLATE_META, $this->targets->template_identity( $target ) );
			update_post_meta( $post->ID, Target_Resolver::TEMPLATE_META, $this->targets->template_meta_value( $target ) );
			update_post_meta( $post->ID, self::REVISION_META, wp_generate_uuid4() );
			$stored_post_type = (string) $target['post_type'];
			$stored_template  = $this->targets->template_identity( $target );
		}

		if ( $post->post_type !== (string) $target['post_type'] || $stored_post_type !== (string) $target['post_type'] ) {
			throw new Execution_Exception( 'post_type_immutable', 'The draft post type does not match its immutable blueprint target.' );
		}
		if ( ! hash_equals( $this->targets->template_identity( $target ), $stored_template ) ) {
			throw new Execution_Exception( 'template_immutable', 'The draft template contract does not match its immutable blueprint target.' );
		}
		if ( ! hash_equals( $this->targets->template_meta_value( $target ), $this->targets->normalized_stored_template( $post->ID ) ) ) {
			throw new Execution_Exception( 'template_immutable', 'The assigned WordPress template no longer matches the blueprint.' );
		}
	}

	private function get_adoptable_draft( int $post_id, string $page_type, array $target ): \WP_Post {
		clean_post_cache( $post_id );
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			throw new Execution_Exception( 'draft_not_found', 'The requested content draft does not exist.' );
		}
		if ( 'draft' !== $post->post_status ) {
			throw new Execution_Exception( 'not_a_draft', 'Only drafts can be inspected or adopted.' );
		}
		if ( $post->post_type !== (string) $target['post_type'] ) {
			throw new Execution_Exception( 'adoption_post_type_mismatch', 'The draft post type does not match the selected blueprint.' );
		}
		if ( ! hash_equals( $this->targets->template_meta_value( $target ), $this->targets->normalized_stored_template( $post_id ) ) ) {
			throw new Execution_Exception( 'adoption_template_mismatch', 'The draft template does not match the selected blueprint.' );
		}
		$this->targets->assert_current_user_can_adopt( $post, $target );

		$meta = array(
			self::OWNED_META          => get_post_meta( $post_id, self::OWNED_META, true ),
			self::PAGE_TYPE_META      => get_post_meta( $post_id, self::PAGE_TYPE_META, true ),
			self::POST_TYPE_META      => get_post_meta( $post_id, self::POST_TYPE_META, true ),
			self::TEMPLATE_META       => get_post_meta( $post_id, self::TEMPLATE_META, true ),
			self::ASSIGNED_AGENT_META => get_post_meta( $post_id, self::ASSIGNED_AGENT_META, true ),
		);
		if (
			'1' !== (string) $meta[ self::OWNED_META ]
			&& ! $this->config->get_content_access( $post->post_type )['adopt_drafts']
		) {
			throw new Execution_Exception( 'adoption_not_allowed', 'The active Site Contract does not allow Composer to adopt drafts of this post type.' );
		}
		$this->assert_adoption_meta_contract( $meta, $page_type, $target );
		$assigned_agent_id = absint( $meta[ self::ASSIGNED_AGENT_META ] ?? 0 );
		if ( 0 !== $assigned_agent_id && get_current_user_id() !== $assigned_agent_id ) {
			throw new Execution_Exception( 'draft_assigned_to_other_agent', 'This draft is already assigned to a different SmartCloud agent.' );
		}
		return $post;
	}

	private function assert_adoption_meta_contract( array $meta, string $page_type, array $target ): void {
		$stored_page_type = trim( (string) ( $meta[ self::PAGE_TYPE_META ] ?? '' ) );
		if ( '' !== $stored_page_type && $page_type !== sanitize_key( $stored_page_type ) ) {
			throw new Execution_Exception( 'page_type_immutable', 'The existing Composer page type does not match the requested adoption blueprint.' );
		}

		$stored_post_type = trim( (string) ( $meta[ self::POST_TYPE_META ] ?? '' ) );
		if ( '' !== $stored_post_type && (string) $target['post_type'] !== sanitize_key( $stored_post_type ) ) {
			throw new Execution_Exception( 'post_type_immutable', 'The existing Composer post type does not match the requested adoption blueprint.' );
		}

		$stored_template = trim( (string) ( $meta[ self::TEMPLATE_META ] ?? '' ) );
		if ( '' !== $stored_template && ! hash_equals( $this->targets->template_identity( $target ), $stored_template ) ) {
			throw new Execution_Exception( 'template_immutable', 'The existing Composer template contract does not match the requested adoption blueprint.' );
		}
	}

	private function revision_or_new( mixed $value ): string {
		$value = strtolower( trim( (string) $value ) );
		return preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/', $value )
			? $value
			: wp_generate_uuid4();
	}

	private function acquire_idempotency_lock( int $user_id, string $key ): string {
		global $wpdb;

		$lock_name = 'wpsuite-agent-' . substr( hash( 'sha256', get_current_blog_id() . ':' . $user_id . ':' . $key ), 0, 48 );
		$acquired  = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock_name, 10 ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- MySQL advisory locks provide cross-request idempotency and are not cacheable.
		if ( '1' !== (string) $acquired ) {
			throw new Execution_Exception( 'idempotency_lock_unavailable', 'A concurrent draft creation is still in progress. Retry safely with the same idempotency key.' );
		}
		return $lock_name;
	}

	private function release_idempotency_lock( string $lock_name ): void {
		global $wpdb;
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Releases the non-cacheable advisory lock acquired above.
	}

	private function assert_no_forbidden_input( array $input ): void {
		$forbidden = array(
			'post_status',
			'status',
			'post_type',
			'target_post_type',
			'post_author',
			'author',
			'template',
			'page_template',
			'target_template',
			'_wp_page_template',
			'publish',
			'delete',
			'trash',
			'post_content',
			'content',
			'post_excerpt',
			'meta_input',
			'_yoast_wpseo_metadesc',
		);
		foreach ( $forbidden as $key ) {
			if ( array_key_exists( $key, $input ) ) {
				throw new Execution_Exception( 'forbidden_input', 'The request contains a forbidden field: ' . $key ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data is returned by an Ability and is not rendered as HTML.
			}
		}
	}

	private function sanitize_title( mixed $title ): string {
		$title = sanitize_text_field( (string) $title );
		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $title ) : strlen( $title );
		if ( '' === $title || $length > 200 ) {
			throw new Execution_Exception( 'invalid_title', 'The title must contain between 1 and 200 characters.' );
		}
		return $title;
	}

	private function sanitize_slug( mixed $slug ): string {
		$slug = sanitize_title( (string) $slug );
		if ( '' === $slug || strlen( $slug ) > 200 ) {
			throw new Execution_Exception( 'invalid_slug', 'The slug must contain between 1 and 200 characters.' );
		}
		return $slug;
	}

	private function sanitize_idempotency_key( mixed $key ): string {
		$key = trim( (string) $key );
		if ( '' === $key || ! preg_match( '/^[A-Za-z0-9._:-]{8,128}$/', $key ) ) {
			throw new Execution_Exception( 'invalid_idempotency_key', 'The idempotency key must be 8-128 characters using letters, numbers, dot, underscore, colon, or hyphen.' );
		}
		return $key;
	}

	private function sanitize_editorial_fields( array $input, string $page_type ): array {
		$policy = $this->excerpt_policy( $page_type );
		return array(
			'excerpt'          => Excerpt_Policy::sanitize_input( $input['excerpt'] ?? '', array_key_exists( 'excerpt', $input ), $policy ),
			'excerpt_policy'   => $policy,
			'meta_description' => $this->sanitize_editorial_text( $input['meta_description'] ?? '', 'meta description', 120, 160 ),
		);
	}

	private function sanitize_editorial_text( mixed $value, string $label, int $minimum, int $maximum ): string {
		$value = sanitize_text_field( wp_strip_all_tags( (string) $value, true ) );
		$value = trim( preg_replace( '/\s+/u', ' ', $value ) ?? $value );
		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $value ) : strlen( $value );
		if ( $length < $minimum || $length > $maximum ) {
			throw new Execution_Exception(
				'invalid_seo_metadata',
				sprintf( 'The %s must contain between %d and %d characters.', $label, $minimum, $maximum ) // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data is returned by an Ability and is not rendered as HTML.
			);
		}
		return $value;
	}

	private function add_editorial_language_issues( array $validation, string $page_type, array $input, array $editorial ): array {
		$text = implode(
			"\n",
			array_filter(
				array(
					isset( $input['title'] ) ? (string) $input['title'] : '',
					(string) ( $editorial['excerpt'] ?? '' ),
					(string) ( $editorial['meta_description'] ?? '' ),
				)
			)
		);
		$issues = $this->language->issues( $page_type, $text );
		foreach ( $issues as $issue ) {
			$validation['errors'][] = array(
				'code'    => (string) ( $issue['code'] ?? 'content_language_mismatch' ),
				'message' => (string) ( $issue['message'] ?? 'Generated editorial fields conflict with the strict content language policy.' ),
				'context' => array( 'surface' => 'title-excerpt-seo' ),
			);
		}
		$validation['errors'] = array_values( (array) ( $validation['errors'] ?? array() ) );
		$validation['valid']  = empty( $validation['errors'] );
		return $validation;
	}

	private function describe_editorial_fields( string $excerpt, string $meta_description, string $excerpt_policy ): array {
		return array(
			'excerpt'                    => $excerpt,
			'excerpt_characters'         => function_exists( 'mb_strlen' ) ? mb_strlen( $excerpt ) : strlen( $excerpt ),
			'excerpt_policy'             => $excerpt_policy,
			'meta_description'           => $meta_description,
			'meta_description_characters' => function_exists( 'mb_strlen' ) ? mb_strlen( $meta_description ) : strlen( $meta_description ),
			'meta_description_provider'  => 'Yoast SEO',
		);
	}

	private function validate_stored_editorial_fields( string $excerpt, string $meta_description, string $page_type ): array {
		$policy  = $this->excerpt_policy( $page_type );
		$summary = $this->describe_editorial_fields( $excerpt, $meta_description, $policy );
		$errors  = Excerpt_Policy::stored_errors( $excerpt, $policy );
		if ( $summary['meta_description_characters'] < 120 || $summary['meta_description_characters'] > 160 ) {
			$errors[] = array(
				'code' => 'invalid_meta_description_length',
				'message' => 'The Yoast SEO meta description must contain between 120 and 160 characters.',
				'context' => array( 'found' => $summary['meta_description_characters'], 'minimum' => 120, 'maximum' => 160 ),
			);
		}
		$summary['valid']  = empty( $errors );
		$summary['errors'] = $errors;
		return $summary;
	}

	private function excerpt_policy( string $page_type ): string {
		$blueprint = $this->config->get_blueprint( $page_type );
		return Excerpt_Policy::normalize( $blueprint['excerpt_policy'] ?? Excerpt_Policy::OPTIONAL );
	}

	private function sanitize_revision( mixed $revision ): string {
		$revision = strtolower( trim( (string) $revision ) );
		if ( ! preg_match( '/^[a-f0-9-]{36}$/', $revision ) ) {
			throw new Execution_Exception( 'invalid_revision', 'A valid draft revision token is required.' );
		}
		return $revision;
	}

	/**
	 * WordPress normally initializes post_modified_gmt during wp_insert_post().
	 * Some draft-save integrations can nevertheless leave its zero-date value
	 * behind. Touch a newly created draft once so its first Composer response
	 * contains a real optimistic-concurrency timestamp whenever WordPress can
	 * supply one. The zero-date token remains a safe fallback for legacy data.
	 */
	private function initialize_created_modified_gmt( \WP_Post $post ): \WP_Post {
		if ( self::ZERO_MODIFIED_GMT !== $this->modified_gmt_token( $post->post_modified_gmt ) ) {
			return $post;
		}

		$result = wp_update_post( array( 'ID' => $post->ID ), true );
		if ( is_wp_error( $result ) ) {
			return $post;
		}

		clean_post_cache( $post->ID );
		$refreshed = get_post( $post->ID );
		return $refreshed instanceof \WP_Post ? $refreshed : $post;
	}

	/**
	 * Normalize a client-supplied modification token without treating a missing
	 * value as the legacy WordPress zero date.
	 */
	private function normalize_expected_modified_gmt( string $value ): string {
		return '' === trim( $value ) ? '' : $this->modified_gmt_token( $value );
	}

	/**
	 * Return a valid RFC 3339 token even for legacy zero-date drafts.
	 *
	 * Revision UUIDs still protect owned-draft updates, while adoption also
	 * verifies the complete content hash. After the first successful write,
	 * WordPress replaces this sentinel with the actual modification time.
	 */
	private function modified_gmt_token( string $value ): string {
		$value = trim( $value );
		if ( '' === $value || '0000-00-00 00:00:00' === $value ) {
			return self::ZERO_MODIFIED_GMT;
		}
		return $this->normalize_gmt( $value );
	}

	private function normalize_gmt( string $value ): string {
		$value = trim( $value );
		if ( '' === $value || '0000-00-00 00:00:00' === $value ) {
			return '';
		}
		$timestamp = strtotime( $value . ( str_contains( $value, 'Z' ) || str_contains( $value, '+' ) ? '' : ' UTC' ) );
		return false === $timestamp ? '' : gmdate( 'Y-m-d\TH:i:s\Z', $timestamp );
	}
}
