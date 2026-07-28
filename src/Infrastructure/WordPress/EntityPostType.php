<?php

namespace SmartCloud\AgentComposer\Infrastructure\WordPress;

use SmartCloud\AgentComposer\Domain\Configuration\EntityType;

final class EntityPostType {
	public const POST_TYPE = 'smartcloud_composer';

	public static function register(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => array( 'name' => __( 'Composer configuration', 'smartcloud-agent-composer' ) ),
				'public'              => false,
				'publicly_queryable'  => false,
				'show_ui'             => false,
				'show_in_rest'        => false,
				'exclude_from_search' => true,
				'rewrite'             => false,
				'query_var'           => false,
				'supports'            => array( 'title', 'editor', 'revisions' ),
				'capability_type'      => array( 'smartcloud_composer_entity', 'smartcloud_composer_entities' ),
				'map_meta_cap'         => true,
			)
		);

		register_post_meta(
			self::POST_TYPE,
			'_smartcloud_composer_entity_type',
			array(
				'type'              => 'string',
				'single'            => true,
				'show_in_rest'      => false,
				'sanitize_callback' => static fn ( mixed $value ): string => in_array( $value, EntityType::all(), true ) ? (string) $value : '',
				'auth_callback'     => static fn (): bool => current_user_can( Activation::CAP_EDIT_CONFIG ),
			)
		);
	}

	private function __construct() {}
}
