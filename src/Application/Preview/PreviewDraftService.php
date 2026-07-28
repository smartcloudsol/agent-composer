<?php

namespace SmartCloud\AgentComposer\Application\Preview;

use SmartCloud\AgentComposer\Execution\Abilities;
use SmartCloud\AgentComposer\Execution\Draft_Service;

final class PreviewDraftService {
	public const CLEANUP_HOOK = 'smartcloud_composer_cleanup_preview_drafts';
	public const PREVIEW_META = '_smartcloud_composer_preview';
	public const EXPIRES_META = '_smartcloud_composer_preview_expires_gmt';
	private const TTL_SECONDS = 900;

	public function __construct( private readonly Abilities $abilities ) {}

	public function register_ability(): void {
		if ( ! function_exists( 'wp_register_ability' ) || ! function_exists( 'wp_has_ability' ) || wp_has_ability( Abilities::PREFIX . 'create-preview-draft' ) ) {
			return;
		}
		wp_register_ability(
			Abilities::PREFIX . 'create-preview-draft',
			array(
				'label'               => __( 'Create short-lived preview draft', 'smartcloud-agent-composer' ),
				'description'         => __( 'Creates a real governed draft for preview and schedules its automatic cleanup.', 'smartcloud-agent-composer' ),
				'category'            => Abilities::CATEGORY,
				'input_schema'        => $this->abilities->candidate_schema( true ),
				'output_schema'       => array( 'type' => 'object', 'additionalProperties' => true ),
				'execute_callback'    => array( $this, 'create' ),
				'permission_callback' => array( $this->abilities, 'check_permission' ),
				'meta'                => array(
					'show_in_rest'        => false,
					'mcp'                 => array( 'public' => false ),
					'smartcloud_composer' => array( 'preview' => true, 'ttl_seconds' => self::TTL_SECONDS ),
				),
			)
		);
	}

	public function create( array $input ): array|\WP_Error {
		$input['idempotency_key'] = 'preview-' . wp_generate_uuid4();
		$result = $this->abilities->create_content_draft( $input );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$post_id = absint( $result['post_id'] ?? 0 );
		if ( $post_id < 1 ) {
			return new \WP_Error( 'smartcloud_composer_preview_failed', 'The preview draft was not created.', array( 'status' => 500 ) );
		}

		$expires = time() + self::TTL_SECONDS;
		update_post_meta( $post_id, self::PREVIEW_META, '1' );
		update_post_meta( $post_id, self::EXPIRES_META, $expires );
		wp_schedule_single_event( $expires + 60, self::CLEANUP_HOOK );

		$preview = $this->abilities->get_preview( array( 'post_id' => $post_id ) );
		if ( is_wp_error( $preview ) ) {
			return $preview;
		}
		$preview['expires_gmt'] = gmdate( 'c', $expires );
		$preview['temporary']   = true;
		return $preview;
	}

	public function cleanup(): void {
		$post_ids = get_posts(
			array(
				'post_type'      => 'any',
				'post_status'    => 'draft',
				'fields'         => 'ids',
				'posts_per_page' => 100,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Cleanup is bounded to 100 plugin-owned expiring preview drafts.
					array( 'key' => self::PREVIEW_META, 'value' => '1' ),
					array( 'key' => self::EXPIRES_META, 'value' => time(), 'compare' => '<=', 'type' => 'NUMERIC' ),
				),
			)
		);
		foreach ( $post_ids as $post_id ) {
			if (
				'1' === (string) get_post_meta( (int) $post_id, self::PREVIEW_META, true )
				&& '1' === (string) get_post_meta( (int) $post_id, Draft_Service::OWNED_META, true )
			) {
				wp_delete_post( (int) $post_id, true );
			}
		}
	}
}
