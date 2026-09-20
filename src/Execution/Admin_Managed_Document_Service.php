<?php
/* SmartCloud Agent Composer execution contract. */

namespace SmartCloud\AgentComposer\Execution;

use SmartCloud\AgentComposer\Infrastructure\Persistence\AuditTable;

/** Bootstrap native wp-admin creation into the same governed document model. */
final class Admin_Managed_Document_Service {
	public function __construct(
		private readonly Config_Repository $config,
		private readonly Pattern_Assembler $assembler,
		private readonly Page_Validator $validator,
		private readonly Target_Resolver $targets,
		private readonly Managed_Document_State $document_state,
		private readonly Localization_Provider_Registry $localization,
		private readonly AuditTable $audit
	) {}

	public function register(): void {
		add_action( 'load-post-new.php', array( $this, 'bootstrap_native_editor' ), 1 );
		add_filter( 'wp_insert_post_empty_content', array( $this, 'prevent_unmanaged_creation' ), 10, 2 );
		add_filter( 'preview_post_link', array( $this, 'normalize_managed_preview_url' ), 20, 2 );
	}

	/** Keep native preview links on the canonical site scheme behind TLS proxies. */
	public function normalize_managed_preview_url( string $preview_url, \WP_Post $post ): string {
		if ( ! $this->document_state->is_managed( $post->ID ) ) {
			return $preview_url;
		}
		$scheme = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_SCHEME ) );
		return in_array( $scheme, array( 'http', 'https' ), true )
			? set_url_scheme( $preview_url, $scheme )
			: $preview_url;
	}

	/** Replace WordPress' blank auto-draft with one canonical Composer-managed draft. */
	public function bootstrap_native_editor(): void {
		$post_type = isset( $_GET['post_type'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The native Add New screen is authorized below with the post type's create capability.
			? sanitize_key( wp_unslash( (string) $_GET['post_type'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			: 'post';
		$rule = $this->config->get_admin_creation_policy( $post_type );
		$mode = (string) ( $rule['mode'] ?? 'off' );
		if ( 'off' === $mode ) {
			return;
		}

		$requested = isset( $_GET['composer_managed'] ) && '1' === sanitize_text_field( wp_unslash( (string) $_GET['composer_managed'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Optional managed creation verifies its action nonce below; required creation is authorized by the target capability.
		if ( 'optional' === $mode && ! $requested ) {
			return;
		}
		if ( 'optional' === $mode ) {
			check_admin_referer( 'smartcloud_composer_new_' . $post_type );
		}

		try {
			$post_id = $this->create_managed_auto_draft( $post_type, (string) ( $rule['default_page_type'] ?? '' ) );
		} catch ( \Throwable $error ) {
			$this->audit_failure( $post_type, (string) ( $rule['default_page_type'] ?? '' ), $error );
			wp_die(
				esc_html__( 'Composer could not prepare the governed starting document. No content was created. Validate the active Config Set and its synced patterns, then try again.', 'smartcloud-agent-composer' ),
				esc_html__( 'Governed document creation failed', 'smartcloud-agent-composer' ),
				array( 'response' => 409, 'back_link' => true )
			);
		}

		$url = get_edit_post_link( $post_id, 'url' );
		if ( ! is_string( $url ) || '' === $url ) {
			$url = admin_url( 'post.php?post=' . $post_id . '&action=edit' );
		}
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Fail closed for out-of-band creation on post types whose Site Contract
	 * requires a governed baseline. Existing records remain adoptable.
	 */
	public function prevent_unmanaged_creation( bool $maybe_empty, array $postarr ): bool {
		if ( absint( $postarr['ID'] ?? 0 ) > 0 ) {
			return $maybe_empty;
		}
		$post_type = sanitize_key( (string) ( $postarr['post_type'] ?? 'post' ) );
		$rule      = $this->config->get_admin_creation_policy( $post_type );
		if ( 'required' !== (string) ( $rule['mode'] ?? 'off' ) ) {
			return $maybe_empty;
		}

		$meta      = is_array( $postarr['meta_input'] ?? null ) ? $postarr['meta_input'] : array();
		$page_type = sanitize_key( (string) ( $meta[ Draft_Service::PAGE_TYPE_META ] ?? '' ) );
		$managed   = '1' === (string) ( $meta[ Managed_Document_State::MANAGED_META ] ?? '' );
		if ( $managed && $page_type === (string) ( $rule['default_page_type'] ?? '' ) ) {
			return $maybe_empty;
		}
		return true;
	}

	/** Create a human-owned, Composer-managed auto-draft without agent ownership. */
	private function create_managed_auto_draft( string $post_type, string $page_type ): int {
		$page_type = sanitize_key( $page_type );
		$target    = $this->targets->resolve( $page_type );
		if ( $post_type !== (string) $target['post_type'] ) {
			throw new Execution_Exception( 'admin_creation_target_mismatch', 'The native editor target does not match its configured default Blueprint.' );
		}
		$this->targets->assert_current_user_can_create( $target );

		$blueprint = $this->config->get_blueprint( $page_type );
		$assembled = $this->assembler->assemble_admin_default( $page_type );
		$validation = $this->validator->validate( $page_type, (string) $assembled['content'] );
		if ( true !== ( $validation['valid'] ?? false ) ) {
			throw new Execution_Exception(
				'admin_creation_default_invalid',
				'The configured native-editor starting document does not satisfy its active Blueprint and Structure Contract.',
				array( 'validation' => $validation ) // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Structured exception context is returned to the caller and is never rendered as HTML.
			);
		}

		$content_language = sanitize_text_field( (string) ( $blueprint['content_language'] ?? '' ) );
		$meta = array_merge(
			array(
				Draft_Service::PAGE_TYPE_META           => $page_type,
				Draft_Service::REVISION_META            => wp_generate_uuid4(),
				Draft_Service::POST_TYPE_META           => $post_type,
				Draft_Service::TEMPLATE_META            => $this->targets->template_identity( $target ),
				Draft_Service::ASSIGNMENT_SOURCE_META   => 'wp-admin',
				Draft_Service::CONTENT_LANGUAGE_META    => $content_language,
				Target_Resolver::TEMPLATE_META          => $this->targets->template_meta_value( $target ),
			),
			$this->document_state->metadata_for( 0, $page_type, $validation )
		);
		$post_id = wp_insert_post(
			wp_slash(
				array(
					'post_type'    => $post_type,
					'post_status'  => 'auto-draft',
					'post_author'  => get_current_user_id(),
					'post_title'   => '',
					'post_content' => (string) $assembled['content'],
					'meta_input'   => $meta,
				)
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			throw new Execution_Exception( 'admin_creation_insert_failed', $post_id->get_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- WordPress error data is propagated as structured execution data, not HTML.
		}

		try {
			$language = $this->localization->assign_draft_language( (int) $post_id, $post_type, $content_language );
			update_post_meta( (int) $post_id, Draft_Service::LOCALIZATION_PROVIDER_META, sanitize_key( (string) ( $language['provider'] ?? 'wordpress' ) ) );
			$language_code = sanitize_key( (string) ( $language['language_code'] ?? '' ) );
			if ( '' !== $language_code ) {
				update_post_meta( (int) $post_id, Draft_Service::LANGUAGE_CODE_META, $language_code );
			}
			$this->audit->record(
				'admin-managed-document-created',
				'success',
				array( 'post_id' => (int) $post_id, 'post_type' => $post_type, 'page_type' => $page_type ),
				0,
				(int) $post_id
			);
		} catch ( \Throwable $error ) {
			wp_delete_post( (int) $post_id, true );
			throw $error;
		}
		return (int) $post_id;
	}

	private function audit_failure( string $post_type, string $page_type, \Throwable $error ): void {
		try {
			$this->audit->record(
				'admin-managed-document-create-failed',
				'failure',
				array(
					'post_type' => sanitize_key( $post_type ),
					'page_type' => sanitize_key( $page_type ),
					'error'     => $error instanceof Execution_Exception ? $error->get_execution_code() : 'unexpected_error',
				)
			);
		} catch ( \Throwable ) {
			// Preserve the original failure shown to the operator.
		}
	}
}
