<?php

namespace SmartCloud\AgentComposer\Infrastructure\WordPress;

use SmartCloud\AgentComposer\Execution\Content_Proposal_Service;
use SmartCloud\AgentComposer\Execution\Draft_Service;

/** Keeps proposal working copies understandable in ordinary content lists. */
final class ContentProposalAdminList {
	private const VISIBILITY_PARAM = 'smartcloud_composer_proposals';

	public function hooks(): void {
		add_filter( 'display_post_states', array( $this, 'post_states' ), 10, 2 );
		add_filter( 'pre_wp_unique_post_slug', array( $this, 'preserve_existing_published_slug' ), 20, 6 );
		add_filter( 'wp_insert_post_data', array( $this, 'keep_proposals_unpublished' ), 20, 4 );
		add_action( 'restrict_manage_posts', array( $this, 'visibility_filter' ), 10, 2 );
		add_action( 'pre_get_posts', array( $this, 'filter_proposals' ) );
		add_action( 'admin_notices', array( $this, 'draft_count_notice' ) );
		add_action( 'admin_notices', array( $this, 'proposal_editor_notice' ) );
	}

	/**
	 * Keep an existing published canonical stable when a draft shares its slug.
	 *
	 * WordPress otherwise considers the draft while re-validating the unchanged
	 * slug and silently moves the published item to a -2/-3 variant.
	 */
	public function preserve_existing_published_slug( mixed $override, string $slug, int $post_id, string $post_status, string $post_type, int $post_parent ): mixed {
		if ( null !== $override || $post_id < 1 || 'publish' !== $post_status ) {
			return $override;
		}

		$current = get_post( $post_id );
		if ( ! $current instanceof \WP_Post
			|| 'publish' !== $current->post_status
			|| $post_type !== $current->post_type
			|| $slug !== $current->post_name
			|| ( is_post_type_hierarchical( $post_type ) && $post_parent !== (int) $current->post_parent ) ) {
			return $override;
		}

		return $slug;
	}

	/** A proposal is a review/archive object and can never become public itself. */
	public function keep_proposals_unpublished( array $data, array $postarr, array $unsanitized_postarr, bool $update ): array {
		$post_id = absint( $postarr['ID'] ?? 0 );
		if ( ! $update || $post_id < 1 || '' === (string) get_post_meta( $post_id, Content_Proposal_Service::STATE_META, true ) ) {
			return $data;
		}

		if ( in_array( (string) ( $data['post_status'] ?? '' ), array( 'publish', 'future', 'private', 'pending' ), true ) ) {
			$data['post_status'] = 'draft';
		}
		$current = get_post( $post_id );
		if ( $current instanceof \WP_Post ) {
			$data['post_name'] = $current->post_name;
		}

		return $data;
	}

	/** @param array<string,string> $states */
	public function post_states( array $states, \WP_Post $post ): array {
		$proposal_state = sanitize_key( (string) get_post_meta( $post->ID, Content_Proposal_Service::STATE_META, true ) );
		$labels = array(
			'working' => __( 'Update proposal - editing', 'smartcloud-agent-composer' ),
			'ready-for-review' => __( 'Update proposal - ready for review', 'smartcloud-agent-composer' ),
			'merged' => __( 'Update proposal - merged archive', 'smartcloud-agent-composer' ),
			'rejected' => __( 'Update proposal - rejected archive', 'smartcloud-agent-composer' ),
			'superseded' => __( 'Update proposal - superseded archive', 'smartcloud-agent-composer' ),
		);
		if ( isset( $labels[ $proposal_state ] ) ) {
			$states['smartcloud-composer-proposal'] = $labels[ $proposal_state ];
		} elseif ( 'draft' === $post->post_status && '1' === (string) get_post_meta( $post->ID, Draft_Service::OWNED_META, true ) ) {
			$states['smartcloud-composer-draft'] = __( 'Composer new-content draft', 'smartcloud-agent-composer' );
		}
		return $states;
	}

	public function visibility_filter( string $post_type, string $which ): void {
		if ( 'top' !== $which || ! $this->supports_content_list( $post_type ) ) {
			return;
		}
		$value = $this->show_proposals() ? 'show' : 'hide';
		?>
		<label class="screen-reader-text" for="smartcloud-composer-proposal-visibility"><?php esc_html_e( 'Update proposal visibility', 'smartcloud-agent-composer' ); ?></label>
		<select id="smartcloud-composer-proposal-visibility" name="<?php echo esc_attr( self::VISIBILITY_PARAM ); ?>">
			<option value="hide" <?php selected( $value, 'hide' ); ?>><?php esc_html_e( 'Update proposals hidden', 'smartcloud-agent-composer' ); ?></option>
			<option value="show" <?php selected( $value, 'show' ); ?>><?php esc_html_e( 'Show update proposals', 'smartcloud-agent-composer' ); ?></option>
		</select>
		<?php
	}

	/** Keep proposal working and audit copies out of ordinary content lists by default. */
	public function filter_proposals( \WP_Query $query ): void {
		global $pagenow;
		$post_type = $this->query_post_type( $query );
		if ( ! is_admin() || 'edit.php' !== $pagenow || ! $query->is_main_query() || ! $this->supports_content_list( $post_type ) || $this->show_proposals() ) {
			return;
		}
		$existing = $query->get( 'meta_query' );
		$hide = array(
			'key' => Content_Proposal_Service::STATE_META,
			'compare' => 'NOT EXISTS',
		);
		$query->set(
			'meta_query',
			is_array( $existing ) && ! empty( $existing )
				? array( 'relation' => 'AND', $existing, $hide )
				: array( $hide )
		);
	}

	public function draft_count_notice(): void {
		global $pagenow;
		if ( ! is_admin() || 'edit.php' !== $pagenow || $this->show_proposals() ) {
			return;
		}
		$post_status = isset( $_GET['post_status'] ) ? sanitize_key( wp_unslash( (string) $_GET['post_status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list preference.
		$post_type = isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( (string) $_GET['post_type'] ) ) : 'post'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list preference.
		if ( 'draft' !== $post_status || ! $this->supports_content_list( $post_type ) ) {
			return;
		}
		echo '<div class="notice notice-info"><p>' . esc_html__( 'The Drafts total can include Composer update proposals, which are hidden from this list by default. Choose “Show update proposals” in the filters to include and identify them.', 'smartcloud-agent-composer' ) . '</p></div>';
	}

	/** Make the post editor boundary between a closed archive and its live source explicit. */
	public function proposal_editor_notice(): void {
		global $pagenow, $post;
		if ( ! is_admin() || 'post.php' !== $pagenow || ! $post instanceof \WP_Post
			|| 'merged' !== sanitize_key( (string) get_post_meta( $post->ID, Content_Proposal_Service::STATE_META, true ) ) ) {
			return;
		}

		$source_id = absint( get_post_meta( $post->ID, Content_Proposal_Service::SOURCE_META, true ) );
		$edit_url  = $source_id > 0 ? get_edit_post_link( $source_id, 'raw' ) : '';
		echo '<div class="notice notice-warning"><p>';
		echo esc_html__( 'This is a closed Composer proposal archive. Editing it will not change the published item.', 'smartcloud-agent-composer' );
		if ( is_string( $edit_url ) && '' !== $edit_url ) {
			echo ' <a href="' . esc_url( $edit_url ) . '">' . esc_html__( 'Continue editing the live source in Gutenberg.', 'smartcloud-agent-composer' ) . '</a>';
		}
		echo '</p></div>';
	}

	private function show_proposals(): bool {
		$value = isset( $_GET[ self::VISIBILITY_PARAM ] ) ? sanitize_key( wp_unslash( (string) $_GET[ self::VISIBILITY_PARAM ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list preference.
		return 'show' === $value;
	}

	private function query_post_type( \WP_Query $query ): string {
		$post_type = $query->get( 'post_type' );
		return is_string( $post_type ) && '' !== $post_type ? sanitize_key( $post_type ) : 'post';
	}

	private function supports_content_list( string $post_type ): bool {
		$object = get_post_type_object( $post_type );
		return $object instanceof \WP_Post_Type && $object->show_ui && 'smartcloud_composer' !== $post_type && 'attachment' !== $post_type;
	}
}
