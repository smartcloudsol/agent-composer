<?php
/* SmartCloud Agent Composer execution contract. */

namespace SmartCloud\AgentComposer\Execution;

use SmartCloud\AgentComposer\Infrastructure\Persistence\AuditTable;
use SmartCloud\AgentComposer\Infrastructure\WordPress\Activation;
use SmartCloud\AgentComposer\Security\ActorIdentity;

/** Enforce the same document validator on native Gutenberg REST saves. */
final class Structure_Contract_Save_Guard {
	private array $pending = array();
	private array $admin_prepared = array();
	private bool $admin_registered = false;
	private bool $rest_registered = false;

	public function __construct(
		private readonly Config_Repository $config,
		private readonly Page_Validator $validator,
		private readonly Managed_Document_State $document_state,
		private readonly AuditTable $audit
	) {}

	public function register(): void {
		if ( $this->rest_registered ) {
			return;
		}
		try {
			$policy = $this->config->get_design_policy();
		} catch ( \Throwable ) {
			return;
		}
		$post_types = array_values(
			array_unique(
				array_filter(
					array_map( 'sanitize_key', (array) ( $policy['post_type_contract'] ?? array() ) )
				)
			)
		);
		foreach ( $post_types as $post_type ) {
			add_filter( 'rest_pre_insert_' . $post_type, array( $this, 'validate_save' ), 10, 2 );
			add_action( 'rest_after_insert_' . $post_type, array( $this, 'record_save' ), 10, 3 );
		}
		$this->rest_registered = true;
	}

	/** Restrict Gutenberg lock management only on Composer-owned documents. */
	public function filter_editor_settings( array $settings, mixed $context ): array {
		$post = is_object( $context ) && isset( $context->post ) ? $context->post : null;
		if ( $post instanceof \WP_Post && $this->document_state->is_managed( $post->ID ) ) {
			$settings['canLockBlocks'] = current_user_can( Activation::CAP_MANAGE_STRUCTURE );
			$page_type = sanitize_key( (string) get_post_meta( $post->ID, Draft_Service::PAGE_TYPE_META, true ) );
			if ( '' !== $page_type ) {
				try {
					$blueprint = $this->config->get_blueprint( $page_type );
					if ( 'enforced' === (string) ( $blueprint['structure_contract_mode'] ?? '' ) ) {
						// Top-level structure comes only from the Blueprint. A nested extension
						// slot explicitly resets templateLock to false for its own children.
						$settings['templateLock'] = 'insert';
					}
				} catch ( Execution_Exception ) {
					// The save boundary remains fail-closed; do not break editor bootstrap.
				}
			}
		}
		return $settings;
	}

	/**
	 * @param mixed            $prepared_post Prepared post object or an earlier WP_Error.
	 * @param \WP_REST_Request $request       Core posts-controller request.
	 */
	public function validate_save( mixed $prepared_post, \WP_REST_Request $request ): mixed {
		if ( $prepared_post instanceof \WP_Error ) {
			return $prepared_post;
		}

		$post_id = absint( $request->get_param( 'id' ) );
		$post_type = is_object( $prepared_post ) && isset( $prepared_post->post_type )
			? sanitize_key( (string) $prepared_post->post_type )
			: '';
		if ( $post_id < 1 ) {
			$creation = $this->config->get_admin_creation_policy( $post_type );
			if ( 'required' === (string) ( $creation['mode'] ?? 'off' ) ) {
				return new \WP_Error(
					'smartcloud_agent_managed_creation_required',
					'Create this content type through its WordPress Add New screen so Composer can establish the required Blueprint baseline.',
					array( 'status' => 409, 'post_type' => $post_type, 'page_type' => (string) ( $creation['default_page_type'] ?? '' ) )
				);
			}
			return $prepared_post;
		}
		return $this->validate_existing_save( $prepared_post, $post_id, $request, (array) $request->get_param( 'meta' ) );
	}

	/** Both transports validate one complete effective document; authorization is never synthesized. */
	private function validate_existing_save( mixed $prepared_post, int $post_id, ?\WP_REST_Request $request, array $meta, bool $native_admin = false ): mixed {
		if ( ! $this->document_state->is_managed( $post_id ) ) {
			return $prepared_post;
		}
		$page_type = sanitize_key( (string) get_post_meta( $post_id, Draft_Service::PAGE_TYPE_META, true ) );
		if ( '' === $page_type ) {
			return $prepared_post;
		}
		$current = get_post( $post_id );
		if ( ! $current instanceof \WP_Post ) {
			return $prepared_post;
		}
		$blueprint = $this->config->get_blueprint( $page_type );
		$native_published_edit = 'publish' === $current->post_status
			&& 'proposal-only' === (string) ( $blueprint['published_update_policy'] ?? 'disabled' )
			&& ( null !== $request ? $this->allows_native_published_edit( $request, $current, $blueprint )
				: $native_admin && $this->allows_admin_published_edit( $current, $blueprint ) );
		if ( 'publish' === $current->post_status
			&& 'proposal-only' === (string) ( $blueprint['published_update_policy'] ?? 'disabled' )
			&& ! $native_published_edit ) {
			return new \WP_Error(
				'smartcloud_agent_published_proposal_required',
				'This managed published item can be changed only through a separate review proposal.',
				array( 'status' => 409, 'post_id' => $post_id, 'page_type' => $page_type )
			);
		}
		$proposed_content = is_object( $prepared_post ) && isset( $prepared_post->post_content )
			? (string) $prepared_post->post_content
			: (string) $current->post_content;
		$previous_content = (string) $current->post_content;
		$native_pattern_refreshes = array();
		if ( $native_published_edit ) {
			try {
				$previous = $this->validator->refresh_native_pattern_revisions( $page_type, $previous_content );
				$proposed = $this->validator->refresh_native_pattern_revisions( $page_type, $proposed_content );
				$previous_content = (string) $previous['content'];
				$proposed_content = (string) $proposed['content'];
				$native_pattern_refreshes = array_values( array_unique( array_merge( (array) $previous['operations'], (array) $proposed['operations'] ), SORT_REGULAR ) );
				$prepared_post->post_content = $proposed_content;
			} catch ( Execution_Exception $error ) {
				return new \WP_Error( 'smartcloud_agent_' . $error->get_execution_code(), $error->getMessage(), array( 'status' => 409, 'post_id' => $post_id ) );
			}
		}
		$legacy_baseline = $this->legacy_baseline_validation( $post_id, $page_type, (string) $current->post_content );

		try {
			// Partial REST saves must validate the same complete editorial state as agent saves.
			$title = isset( $prepared_post->post_title ) ? (string) $prepared_post->post_title : (string) $current->post_title;
			$excerpt = isset( $prepared_post->post_excerpt ) ? (string) $prepared_post->post_excerpt : (string) $current->post_excerpt;
			$description = is_array( $meta ) && array_key_exists( Draft_Service::YOAST_METADESC_META, $meta )
				? $meta[ Draft_Service::YOAST_METADESC_META ]
				: get_post_meta( $post_id, Draft_Service::YOAST_METADESC_META, true );
			Editorial_Field_Validator::sanitize_title( $title );
			$editorial_validator = new Editorial_Field_Validator( $this->config, new Content_Language_Validator( $this->config ) );
			$editorial = $editorial_validator->sanitize_fields( array( 'excerpt' => $excerpt, 'meta_description' => $description ), $page_type );
			$validation = $this->validator->validate( $page_type, $proposed_content, $previous_content );
			$validation = $editorial_validator->add_language_issues( $validation, $page_type, array( 'title' => $title ), $editorial );
		} catch ( Execution_Exception $error ) {
			return new \WP_Error(
				'smartcloud_agent_' . $error->get_execution_code(),
				$error->getMessage(),
				array_merge( $error->get_execution_data(), array( 'status' => 409 ) )
			);
		}
		if ( true === ( $validation['valid'] ?? false ) ) {
			$this->pending[ $post_id ] = array(
				'page_type'                   => $page_type,
				'validation'                  => $validation,
				'privileged_structure_change' => false,
				'operations'                  => array(),
				'legacy_baseline'             => $legacy_baseline,
				'native_pattern_refreshes'    => $native_pattern_refreshes,
			);
			return $prepared_post;
		}

		$violations = array_values(
			array_filter(
				(array) ( $validation['errors'] ?? array() ),
				static fn( mixed $error ): bool => is_array( $error ) && 'composer_contract_violation' === ( $error['code'] ?? '' )
			)
		);
		if ( ! empty( $violations ) && current_user_can( Activation::CAP_MANAGE_STRUCTURE ) ) {
			try {
				$privileged_validation = $this->validator->validate(
					$page_type,
					$proposed_content,
					$previous_content,
					array( 'allow_structure_change' => true )
				);
				$privileged_validation = $editorial_validator->add_language_issues( $privileged_validation, $page_type, array( 'title' => $title ), $editorial );
			} catch ( Execution_Exception $error ) {
				return new \WP_Error(
					'smartcloud_agent_' . $error->get_execution_code(),
					$error->getMessage(),
					array_merge( $error->get_execution_data(), array( 'status' => 409 ) )
				);
			}
			if ( true === ( $privileged_validation['valid'] ?? false ) ) {
				$this->pending[ $post_id ] = array(
					'page_type'                   => $page_type,
					'validation'                  => $privileged_validation,
					'privileged_structure_change' => true,
					'operations'                  => array_values( (array) ( $validation['structure_contract']['operations'] ?? array() ) ),
					'legacy_baseline'             => $legacy_baseline,
					'native_pattern_refreshes'    => $native_pattern_refreshes,
				);
				return $prepared_post;
			}
			$violations = array_values(
				array_filter(
					(array) ( $privileged_validation['errors'] ?? array() ),
					static fn( mixed $error ): bool => is_array( $error ) && 'composer_contract_violation' === ( $error['code'] ?? '' )
				)
			);
		}
		$code = empty( $violations ) ? 'smartcloud_agent_validation_failed' : 'composer_contract_violation';
		return new \WP_Error(
			$code,
			empty( $violations )
				? 'The Gutenberg document does not satisfy its active Blueprint.'
				: 'The Gutenberg document violates its active Structure Contract.',
			array(
				'status'     => 409,
				'violations' => empty( $violations ) ? (array) ( $validation['errors'] ?? array() ) : $violations,
			)
		);
	}

	/** Install the non-REST boundary before any core post or taxonomy persistence. */
	public function register_admin(): void {
		if ( $this->admin_registered ) {
			return;
		}
		add_filter( 'wp_insert_post_empty_content', array( $this, 'validate_admin_save' ), 1, 2 );
		add_filter( 'wp_insert_post_data', array( $this, 'persist_admin_content' ), PHP_INT_MAX, 4 );
		add_action( 'post_updated', array( $this, 'record_admin_save' ), 20, 3 );
		$this->admin_registered = true;
	}

	/** Classic, Quick Edit and Bulk Edit use core PHP writes rather than the REST controller. */
	public function validate_admin_save( bool $maybe_empty, array $postarr ): bool {
		$post_id = absint( $postarr['ID'] ?? 0 );
		$route = $this->admin_route( $post_id );
		if ( null === $route || ! $this->document_state->is_managed( $post_id ) ) {
			return $maybe_empty;
		}
		unset( $this->admin_prepared[ $post_id ], $this->pending[ $post_id ] );
		if ( ! $this->authenticated_admin_route( $post_id, $route ) ) {
			$this->reject_admin_save( new \WP_Error( 'smartcloud_agent_native_auth_required', 'A valid signed-in editor session and native save nonce are required.', array( 'status' => 403 ) ) );
		}
		$current = get_post( $post_id );
		if ( ! $current instanceof \WP_Post ) {
			return $maybe_empty;
		}
		$prepared = new \stdClass();
		foreach ( array( 'post_title', 'post_excerpt', 'post_content' ) as $field ) {
			if ( array_key_exists( $field, $postarr ) ) {
				$prepared->{$field} = wp_unslash( (string) $postarr[ $field ] );
			}
		}
		$meta = isset( $postarr['meta_input'] ) && is_array( $postarr['meta_input'] ) ? wp_unslash( $postarr['meta_input'] ) : array();
		// Match the existing SEO provider's classic metabox field and its real nonce.
		if ( isset( $_POST['yoast_wpseo_metadesc'], $_POST['yoast_free_metabox_nonce'], $_POST['ID'] )
			&& $post_id === absint( $_POST['ID'] )
			&& false !== wp_verify_nonce( (string) wp_unslash( $_POST['yoast_free_metabox_nonce'] ), 'yoast_free_metabox' ) ) {
			$meta[ Draft_Service::YOAST_METADESC_META ] = wp_unslash( $_POST['yoast_wpseo_metadesc'] );
		}
		try {
			$result = $this->validate_existing_save( $prepared, $post_id, null, $meta, true );
			/** Native adapters may validate their effective form fields before core writes. */
			$result = apply_filters( 'smartcloud_composer_admin_pre_save_validation', $result, $postarr, $post_id );
		} catch ( \Throwable $error ) {
			unset( $this->pending[ $post_id ] );
			$this->reject_admin_save( new \WP_Error( 'smartcloud_agent_admin_validation_unavailable', 'The complete native save could not be validated.', array( 'status' => 409 ) ) );
		}
		if ( $result instanceof \WP_Error ) {
			unset( $this->pending[ $post_id ] );
			$this->reject_admin_save( $result );
		}
		if ( ! $maybe_empty && isset( $this->pending[ $post_id ] ) ) {
			$this->admin_prepared[ $post_id ] = isset( $prepared->post_content ) ? (string) $prepared->post_content : (string) $current->post_content;
		}
		return $maybe_empty;
	}

	/** Core post arrays are slashed; persist only the exact validated/refreshed body. */
	public function persist_admin_content( array $data, array $postarr, array $unsanitized_postarr, bool $update ): array {
		$id = absint( $postarr['ID'] ?? 0 );
		if ( $update && array_key_exists( $id, $this->admin_prepared ) ) {
			$data['post_content'] = wp_slash( $this->admin_prepared[ $id ] );
		}
		return $data;
	}

	public function record_admin_save( int $post_id, \WP_Post $post_after, \WP_Post $post_before ): void {
		if ( ! array_key_exists( $post_id, $this->admin_prepared ) ) {
			return;
		}
		unset( $this->admin_prepared[ $post_id ] );
		$this->record_pending_save( $post_after );
	}

	private function admin_route( int $post_id ): ?array {
		if ( $post_id < 1 || ! is_admin()
			|| ( defined( 'WP_CLI' ) && WP_CLI )
			|| ( defined( 'REST_REQUEST' ) && REST_REQUEST )
			|| ( defined( 'DOING_CRON' ) && DOING_CRON )
			|| ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE )
			|| wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return null;
		}
		$action = (string) ( $_REQUEST['action'] ?? $_POST['action'] ?? '' );
		if ( '-1' === $action ) {
			$action = (string) ( $_REQUEST['action2'] ?? '' );
		}
		if ( in_array( $action, array( 'editpost', 'inline-save' ), true )
			&& $post_id === absint( $_POST['post_ID'] ?? $_POST['ID'] ?? 0 ) ) {
			return array( 'nonce' => (string) ( $_POST[ 'inline-save' === $action ? '_inline_edit' : '_wpnonce' ] ?? '' ), 'action' => 'inline-save' === $action ? 'inlineeditnonce' : 'update-post_' . $post_id );
		}
		if ( 'edit' === $action && in_array( $post_id, array_map( 'absint', (array) ( $_REQUEST['post'] ?? array() ) ), true ) ) {
			return array( 'nonce' => (string) ( $_REQUEST['_wpnonce'] ?? '' ), 'action' => 'bulk-posts' );
		}
		return null; // Internal draft, proposal and unrelated admin operations are not form saves.
	}

	private function authenticated_admin_route( int $post_id, array $route ): bool {
		if ( null !== ActorIdentity::context() || ! defined( 'LOGGED_IN_COOKIE' )
			|| '' !== (string) ( $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '' ) ) {
			return false;
		}
		$user_id = get_current_user_id();
		$cookie = (string) ( $_COOKIE[ LOGGED_IN_COOKIE ] ?? '' );
		return $user_id > 0 && '' !== $cookie
			&& $user_id === (int) wp_validate_auth_cookie( $cookie, 'logged_in' )
			&& false !== wp_verify_nonce( (string) wp_unslash( $route['nonce'] ), $route['action'] )
			&& current_user_can( 'edit_post', $post_id );
	}

	private function allows_admin_published_edit( \WP_Post $post, array $blueprint ): bool {
		$type = get_post_type_object( $post->post_type );
		return 'browser-editor' === (string) ( $blueprint['native_published_edit_policy'] ?? 'blocked' )
			&& $type instanceof \WP_Post_Type && current_user_can( $type->cap->edit_published_posts );
	}

	private function reject_admin_save( \WP_Error $error ): never {
		$data = $error->get_error_data();
		wp_die( esc_html( $error->get_error_message() ), esc_html( $error->get_error_code() ), array( 'response' => is_array( $data ) ? (int) ( $data['status'] ?? 409 ) : 409, 'back_link' => ! wp_doing_ajax() ) );
	}

	/** Only an opted-in, cookie-authenticated wp-admin editor may bypass the agent proposal gate. */
	private function allows_native_published_edit( \WP_REST_Request $request, \WP_Post $post, array $blueprint ): bool {
		if ( 'browser-editor' !== (string) ( $blueprint['native_published_edit_policy'] ?? 'blocked' )
			|| null !== ActorIdentity::context()
			|| '' !== (string) $request->get_header( 'authorization' )
			|| ! defined( 'LOGGED_IN_COOKIE' ) ) {
			return false;
		}
		$user_id = get_current_user_id();
		$cookie = (string) ( $_COOKIE[ LOGGED_IN_COOKIE ] ?? '' );
		$post_type = get_post_type_object( $post->post_type );
		return $user_id > 0
			&& '' !== $cookie
			&& $user_id === (int) wp_validate_auth_cookie( $cookie, 'logged_in' )
			&& false !== wp_verify_nonce( (string) $request->get_header( 'x_wp_nonce' ), 'wp_rest' )
			&& current_user_can( 'edit_post', $post->ID )
			&& $post_type instanceof \WP_Post_Type
			&& current_user_can( $post_type->cap->edit_published_posts );
	}

	public function record_save( \WP_Post $post, \WP_REST_Request $request, bool $creating ): void {
		if ( ! $creating ) {
			$this->record_pending_save( $post );
		}
	}

	private function record_pending_save( \WP_Post $post ): void {
		if ( ! isset( $this->pending[ $post->ID ] ) ) {
			return;
		}
		$pending = $this->pending[ $post->ID ];
		unset( $this->pending[ $post->ID ] );
		if ( is_array( $pending['legacy_baseline'] ?? null ) ) {
			$this->document_state->persist( $post->ID, (string) $pending['page_type'], $pending['legacy_baseline'] );
		}
		$state = $this->document_state->persist( $post->ID, (string) $pending['page_type'], (array) $pending['validation'] );
		if ( ! empty( $pending['native_pattern_refreshes'] ) ) {
			$this->audit->record(
				'native-pattern-revision-accepted',
				'success',
				array( 'post_id' => $post->ID, 'page_type' => (string) $pending['page_type'], 'revisions' => (array) $pending['native_pattern_refreshes'] ),
				0,
				$post->ID
			);
		}
		if ( true === ( $pending['privileged_structure_change'] ?? false ) ) {
			$this->audit->record(
				'structure-drift-created',
				'success',
				array(
					'post_id'     => $post->ID,
					'page_type'   => (string) $pending['page_type'],
					'status'      => (string) ( $state['status'] ?? '' ),
					'operations'  => (array) ( $pending['operations'] ?? array() ),
				),
				0,
				$post->ID
			);
		}
	}

	/** Capture a valid pre-save baseline when an older owned draft has no managed state yet. */
	private function legacy_baseline_validation( int $post_id, string $page_type, string $content ): ?array {
		if ( 'LEGACY_VERSION' !== (string) ( $this->document_state->public_state( $post_id )['status'] ?? '' ) ) {
			return null;
		}
		try {
			$validation = $this->validator->validate( $page_type, $content );
		} catch ( Execution_Exception ) {
			return null;
		}
		return true === ( $validation['valid'] ?? false ) ? $validation : null;
	}
}
