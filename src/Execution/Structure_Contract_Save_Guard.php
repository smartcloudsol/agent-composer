<?php
/* SmartCloud Agent Composer execution contract. */

namespace SmartCloud\AgentComposer\Execution;

use SmartCloud\AgentComposer\Infrastructure\Persistence\AuditTable;
use SmartCloud\AgentComposer\Infrastructure\WordPress\Activation;

/** Enforce the same document validator on native Gutenberg REST saves. */
final class Structure_Contract_Save_Guard {
	private array $pending = array();

	public function __construct(
		private readonly Config_Repository $config,
		private readonly Page_Validator $validator,
		private readonly Managed_Document_State $document_state,
		private readonly AuditTable $audit
	) {}

	public function register(): void {
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
		if ( null === $request->get_param( 'content' ) || ! $this->document_state->is_managed( $post_id ) ) {
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
		if ( 'publish' === $current->post_status && 'proposal-only' === (string) ( $blueprint['published_update_policy'] ?? 'disabled' ) ) {
			return new \WP_Error(
				'smartcloud_agent_published_proposal_required',
				'This managed published item can be changed only through a separate review proposal.',
				array( 'status' => 409, 'post_id' => $post_id, 'page_type' => $page_type )
			);
		}
		$proposed_content = is_object( $prepared_post ) && isset( $prepared_post->post_content )
			? (string) $prepared_post->post_content
			: (string) $current->post_content;
		$legacy_baseline = $this->legacy_baseline_validation( $post_id, $page_type, (string) $current->post_content );

		try {
			$validation = $this->validator->validate( $page_type, $proposed_content, (string) $current->post_content );
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
					(string) $current->post_content,
					array( 'allow_structure_change' => true )
				);
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

	public function record_save( \WP_Post $post, \WP_REST_Request $request, bool $creating ): void {
		if ( $creating || ! isset( $this->pending[ $post->ID ] ) ) {
			return;
		}
		$pending = $this->pending[ $post->ID ];
		unset( $this->pending[ $post->ID ] );
		if ( is_array( $pending['legacy_baseline'] ?? null ) ) {
			$this->document_state->persist( $post->ID, (string) $pending['page_type'], $pending['legacy_baseline'] );
		}
		$state = $this->document_state->persist( $post->ID, (string) $pending['page_type'], (array) $pending['validation'] );
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
