<?php
/* SmartCloud Agent Composer execution contract. */

namespace SmartCloud\AgentComposer\Execution;

final class Target_Resolver {
	public const TEMPLATE_META = '_wp_page_template';

	private Config_Repository $config;

	public function __construct( Config_Repository $config ) {
		$this->config = $config;
	}

	/**
	 * Resolve the immutable WordPress target from a validated theme blueprint.
	 */
	public function resolve( string $page_type ): array {
		$blueprint = $this->config->get_blueprint( $page_type );
		$post_type = (string) $blueprint['target_post_type'];

		if ( ! in_array( $post_type, $this->config->get_allowed_post_types(), true ) ) {
			throw new Execution_Exception( 'target_post_type_not_allowed', 'The target post type is not allowed by the active design policy.' );
		}

		$post_type_object = get_post_type_object( $post_type );
		if ( ! $post_type_object instanceof \WP_Post_Type ) {
			throw new Execution_Exception( 'target_post_type_unavailable', 'The blueprint target post type is not registered.' );
		}
		if ( ! empty( $post_type_object->_builtin ) && ! in_array( $post_type, array( 'page', 'post' ), true ) ) {
			throw new Execution_Exception( 'target_post_type_forbidden', 'This built-in WordPress post type cannot be used by Composer.' );
		}
		if ( empty( $post_type_object->public ) || empty( $post_type_object->show_ui ) || empty( $post_type_object->show_in_rest ) ) {
			throw new Execution_Exception( 'target_post_type_unsafe', 'The target post type must be public, visible in wp-admin, and available to Gutenberg.' );
		}
		if ( ! post_type_supports( $post_type, 'editor' ) ) {
			throw new Execution_Exception( 'target_post_type_has_no_editor', 'The target post type must support the Gutenberg editor.' );
		}

		return array(
			'post_type' => $post_type,
			'template'  => $this->resolve_template( $blueprint['target_template'], $post_type ),
		);
	}

	public function assert_current_user_can_create( array $target ): void {
		$post_type_object = get_post_type_object( (string) $target['post_type'] );
		$capability       = $post_type_object instanceof \WP_Post_Type
			? (string) ( $post_type_object->cap->create_posts ?? $post_type_object->cap->edit_posts ?? '' )
			: '';

		if ( '' === $capability || ! current_user_can( $capability ) ) {
			throw new Execution_Exception( 'target_create_denied', 'The current agent cannot create drafts for this post type.' );
		}
	}

	public function assert_current_user_can_edit( \WP_Post $post, bool $composer_assigned = false ): void {
		if ( current_user_can( 'edit_post', $post->ID ) ) {
			return;
		}
		if (
			$composer_assigned
			&& 'draft' === $post->post_status
			&& current_user_can( \SmartCloud\AgentComposer\Infrastructure\WordPress\Activation::CAP_USE )
			&& $this->current_user_can_edit_post_type( $post->post_type )
		) {
			return;
		}
		throw new Execution_Exception( 'target_edit_denied', 'The current agent cannot edit this draft post type.' );
	}

	/**
	 * Authorize explicit Composer assignment without granting edit_others_*.
	 */
	public function assert_current_user_can_adopt( \WP_Post $post, array $target ): void {
		if (
			'draft' !== $post->post_status
			|| $post->post_type !== (string) $target['post_type']
			|| ! current_user_can( \SmartCloud\AgentComposer\Infrastructure\WordPress\Activation::CAP_USE )
			|| ! $this->current_user_can_edit_post_type( $post->post_type )
		) {
			throw new Execution_Exception( 'target_adoption_denied', 'The current agent cannot adopt this draft post type.' );
		}
	}

	public function registered_allowed_post_types(): array {
		$result = array();
		foreach ( $this->config->get_allowed_post_types() as $post_type ) {
			if ( post_type_exists( $post_type ) ) {
				$result[] = $post_type;
			}
		}
		return array_values( array_unique( $result ) );
	}

	public function public_contract( array $target ): array {
		$template = $target['template'];
		unset( $template['identity'], $template['wp_template_value'] );
		return array(
			'post_type' => (string) $target['post_type'],
			'template'  => $template,
		);
	}

	public function template_identity( array $target ): string {
		return (string) ( $target['template']['identity'] ?? '' );
	}

	public function template_meta_value( array $target ): string {
		return (string) ( $target['template']['wp_template_value'] ?? 'default' );
	}

	public function normalized_stored_template( int $post_id ): string {
		$value = trim( (string) get_post_meta( $post_id, self::TEMPLATE_META, true ) );
		return '' === $value || 'default' === $value ? 'default' : $value;
	}

	private function resolve_template( array $template, string $post_type ): array {
		$mode  = (string) ( $template['mode'] ?? '' );
		$label = sanitize_text_field( (string) ( $template['label'] ?? '' ) );

		if ( 'default' === $mode ) {
			return array(
				'label'      => $label,
				'mode'       => 'default',
				'slug'       => 'default',
				'identity'   => 'default',
				'wp_template_value' => 'default',
			);
		}

		if ( 'assigned' === $mode ) {
			$slug = (string) ( $template['slug'] ?? '' );
			if ( ! $this->template_slug_available( $slug, $post_type ) ) {
				throw new Execution_Exception( 'target_template_unavailable', 'The blueprint target template is not available for its post type.' );
			}
			return array(
				'label'      => $label,
				'mode'       => 'assigned',
				'slug'       => $slug,
				'identity'   => 'assigned:' . $slug,
				'wp_template_value' => $slug,
			);
		}

		if ( 'hierarchy' === $mode ) {
			$file = (string) ( $template['file'] ?? '' );
			if ( '' === locate_template( array( $file ), false, false ) ) {
				throw new Execution_Exception( 'target_template_unavailable', 'The blueprint hierarchy template file is not available in the active theme.' );
			}
			return array(
				'label'      => $label,
				'mode'       => 'hierarchy',
				'file'       => $file,
				'identity'   => 'hierarchy:' . $file,
				'wp_template_value' => 'default',
			);
		}

		throw new Execution_Exception( 'invalid_target_template', 'The blueprint target template mode is invalid.' );
	}

	private function template_slug_available( string $slug, string $post_type ): bool {
		$theme_templates = wp_get_theme()->get_page_templates( null, $post_type );
		if ( isset( $theme_templates[ $slug ] ) ) {
			return true;
		}
		if ( function_exists( 'get_block_template' ) ) {
			$block_template = get_block_template( get_stylesheet() . '//' . $slug, 'wp_template' );
			if (
				is_object( $block_template )
				&& $slug === (string) ( $block_template->slug ?? '' )
				&& (
					empty( $block_template->post_types )
					|| in_array( $post_type, (array) $block_template->post_types, true )
				)
			) {
				return true;
			}
		}

		if ( ! function_exists( 'get_block_templates' ) ) {
			return false;
		}

		$block_templates = get_block_templates(
			array(
				'slug__in' => array( $slug ),
				'post_type' => $post_type,
			)
		);
		foreach ( $block_templates as $block_template ) {
			if ( is_object( $block_template ) && $slug === (string) ( $block_template->slug ?? '' ) ) {
				return true;
			}
		}

		return false;
	}

	private function current_user_can_edit_post_type( string $post_type ): bool {
		$post_type_object = get_post_type_object( $post_type );
		$capability       = $post_type_object instanceof \WP_Post_Type
			? (string) ( $post_type_object->cap->edit_posts ?? '' )
			: '';
		return '' !== $capability && current_user_can( $capability );
	}
}
