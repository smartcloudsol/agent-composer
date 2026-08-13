<?php

namespace SmartCloud\AgentComposer\Integration\Abilities;

use SmartCloud\AgentComposer\Execution\Abilities;

final class ExecutionAbilityAliases {
	private const CANONICAL_ALIASES = array(
		'materialize-image'    => 'materialize-media-image',
		'list-editable-content'=> 'list-content-drafts',
		'insert-blocks'        => 'insert-or-update-blocks',
		'adopt-draft'          => 'adopt-content-draft',
		'update-content-draft' => 'update-own-draft',
	);

	private const OPERATIONS = array(
		'get-page-blueprint'          => array( 'get_page_blueprint', 'page-type' ),
		'get-design-context'          => array( 'get_design_context', 'empty' ),
		'get-runtime-capabilities'    => array( 'get_runtime_capabilities', 'empty' ),
		'list-approved-patterns'      => array( 'list_approved_patterns', 'page-type' ),
		'read-reference-page'        => array( 'read_reference_page', 'reference' ),
		'search-media'               => array( 'search_media', 'search-media' ),
		'materialize-media-image'    => array( 'materialize_media_image', 'media-image' ),
		'list-content-drafts'        => array( 'list_content_drafts', 'draft-list' ),
		'insert-or-update-blocks'    => array( 'insert_or_update_blocks', 'block-update' ),
		'inspect-draft-for-adoption' => array( 'inspect_draft_for_adoption', 'adoption-inspection' ),
		'adopt-content-draft'        => array( 'adopt_content_draft', 'adoption' ),
		'validate-content-draft'     => array( 'validate_content_draft', 'candidate-update' ),
		'create-content-draft'       => array( 'create_content_draft', 'candidate-create' ),
		'validate-page-draft'        => array( 'validate_page_draft', 'candidate-update' ),
		'create-page-draft'          => array( 'create_page_draft', 'candidate-create' ),
		'update-own-draft'           => array( 'update_own_draft', 'update' ),
		'get-draft'                  => array( 'get_draft', 'post-id' ),
		'get-preview'                => array( 'get_preview', 'post-id' ),
	);

	public function __construct( private readonly Abilities $abilities ) {}

	public static function canonical_names(): array {
		return array_map(
			static fn( string $slug ): string => Abilities::PREFIX . $slug,
			array_keys( self::CANONICAL_ALIASES )
		);
	}

	public function register(): void {
		if ( ! function_exists( 'wp_register_ability' ) || ! function_exists( 'wp_has_ability' ) ) {
			return;
		}
		foreach ( self::CANONICAL_ALIASES as $alias => $target ) {
			$this->register_alias( $alias, $target );
		}
	}

	private function register_alias( string $alias, string $target ): void {
		$name = Abilities::PREFIX . $alias;
		if ( wp_has_ability( $name ) || ! isset( self::OPERATIONS[ $target ] ) ) {
			return;
		}
		list( $method, $schema ) = self::OPERATIONS[ $target ];
		wp_register_ability(
			$name,
			array(
				'label'               => $this->label( $target ),
				'description'         => $this->description( $target ),
				'category'            => Abilities::CATEGORY,
				'input_schema'        => $this->schema( $schema ),
				'output_schema'       => $this->output_schema( $target ),
				'execute_callback'    => array( $this->abilities, $method ),
				'permission_callback' => array( $this->abilities, 'check_permission' ),
				'meta'                => array(
					'show_in_rest'        => false,
					'mcp'                 => array( 'public' => false ),
					'smartcloud_composer' => array( 'execution_contract' => Abilities::CONTRACT, 'alias_of' => Abilities::PREFIX . $target ),
				),
			)
		);
	}

	private function label( string $target ): string {
		if ( 'list-content-drafts' === $target ) {
			return 'List editable content (canonical name)';
		}
		return 'SmartCloud Agent Composer canonical execution name';
	}

	private function description( string $target ): string {
		if ( 'list-content-drafts' === $target ) {
			return 'Lists only editable or adoptable content. Never use this ability to resolve relation target IDs; use search-relation-targets instead.';
		}
		return sprintf( 'Canonical public name for the %s%s execution operation.', Abilities::PREFIX, $target );
	}

	private function output_schema( string $target ): array {
		if ( 'list-content-drafts' === $target ) {
			return $this->abilities->draft_list_output_schema();
		}
		return array( 'type' => 'object', 'additionalProperties' => true );
	}

	private function schema( string $schema ): array {
		return match ( $schema ) {
			'empty'               => array( 'type' => 'object', 'additionalProperties' => false ),
			'page-type'           => array( 'type' => 'object', 'properties' => array( 'page_type' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 64 ) ), 'required' => array( 'page_type' ), 'additionalProperties' => false ),
			'post-id'             => $this->abilities->post_id_schema(),
			'media-image'         => $this->abilities->media_image_schema(),
			'draft-list'          => $this->abilities->draft_list_schema(),
			'adoption-inspection' => $this->abilities->adoption_inspection_schema(),
			'adoption'            => $this->abilities->adoption_schema(),
			'candidate-create'    => $this->abilities->candidate_schema( true ),
			'candidate-update'    => $this->abilities->candidate_schema( false ),
			'update'              => $this->abilities->update_schema(),
			'block-update'        => $this->abilities->block_update_schema(),
			'reference'           => array( 'type' => 'object', 'properties' => array( 'page_type' => array( 'type' => 'string' ), 'post_id' => array( 'type' => 'integer', 'minimum' => 1 ) ), 'required' => array( 'page_type', 'post_id' ), 'additionalProperties' => false ),
			'search-media'        => array( 'type' => 'object', 'properties' => array( 'query' => array( 'type' => 'string', 'maxLength' => 200 ), 'limit' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 20 ), 'mime_type' => array( 'type' => 'string' ) ), 'additionalProperties' => false ),
			default               => array( 'type' => 'object', 'additionalProperties' => false ),
		};
	}
}
