<?php

namespace SmartCloud\AgentComposer\Infrastructure\WordPress;

use SmartCloud\AgentComposer\Execution\Config_Repository;
use SmartCloud\AgentComposer\Execution\Draft_Service;
use SmartCloud\AgentComposer\Execution\Execution_Exception;
use SmartCloud\AgentComposer\Execution\Pattern_Assembler;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/** Supply approved, version-pinned pattern instances to the native slot editor. */
final class InstanceSlotEditorController {
	public function __construct( private Config_Repository $config, private Pattern_Assembler $assembler ) {}

	public function register(): void {
		register_rest_route( StatusController::NAMESPACE, '/editor-slot/pattern-template', array(
			'methods'             => 'GET',
			'permission_callback' => static function ( WP_REST_Request $request ): bool {
				return current_user_can( 'edit_post', absint( $request->get_param( 'post_id' ) ) );
			},
			'callback'            => array( $this, 'pattern_template' ),
			'args'                => array(
				'post_id' => array( 'required' => true, 'type' => 'integer', 'minimum' => 1 ),
				'slot_id' => array( 'required' => true, 'type' => 'string' ),
				'pattern' => array( 'required' => true, 'type' => 'string' ),
			),
		) );
	}

	public function pattern_template( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$post_id = absint( $request->get_param( 'post_id' ) );
		$slot_id = trim( (string) $request->get_param( 'slot_id' ) );
		$pattern = strtolower( trim( (string) $request->get_param( 'pattern' ) ) );
		$page_type = sanitize_key( (string) get_post_meta( $post_id, Draft_Service::PAGE_TYPE_META, true ) );
		if ( '' === $page_type || '' === $slot_id || '' === $pattern ) {
			return new WP_Error( 'composer_editor_slot_invalid', 'The selected post, slot, or pattern is unavailable.', array( 'status' => 400 ) );
		}
		try {
			$blueprint = $this->config->get_blueprint( $page_type );
			$nodes = (array) ( $blueprint['resolved_structure_contract']['nodes'] ?? array() );
			$allowed = false;
			foreach ( $nodes as $node ) {
				if ( is_array( $node ) && 'slot' === (string) ( $node['mode'] ?? '' ) && $slot_id === (string) ( $node['id'] ?? '' ) && in_array( $pattern, (array) ( $node['allowed_patterns'] ?? array() ), true ) ) {
					$allowed = true;
					break;
				}
			}
			if ( ! $allowed ) {
				return new WP_Error( 'composer_editor_pattern_forbidden', 'This pattern is not approved for the selected slot.', array( 'status' => 403 ) );
			}
			$block = $this->assembler->admin_slot_pattern_instance( $pattern, $blueprint );
			$response = new WP_REST_Response( array( 'content' => serialize_block( $block ) ) );
			$response->header( 'Cache-Control', 'no-store, private' );
			return $response;
		} catch ( Execution_Exception $error ) {
			return new WP_Error( 'composer_editor_pattern_unavailable', $error->getMessage(), array( 'status' => 409 ) );
		}
	}
}
