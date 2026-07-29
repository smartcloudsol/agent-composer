<?php

namespace SmartCloud\AgentComposer\Integration\Abilities;

use SmartCloud\AgentComposer\Infrastructure\WordPress\Activation;
use SmartCloud\AgentComposer\Integration\Providers\ProviderRegistry;
use SmartCloud\AgentComposer\Execution\Abilities;

final class ComposerAbilities {
	public const CATEGORY = 'smartcloud-agent-composer';
	public const PREFIX   = 'smartcloud-agent-composer/';

	public function __construct( private readonly ProviderRegistry $providers ) {}

	public function register_category(): void {
		if ( function_exists( 'wp_register_ability_category' ) ) {
			wp_register_ability_category( self::CATEGORY, array( 'label' => __( 'SmartCloud Agent Composer', 'smartcloud-agent-composer' ), 'description' => __( 'Governed configuration and draft execution.', 'smartcloud-agent-composer' ) ) );
		}
	}

	public function register(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}
		wp_register_ability(
			self::PREFIX . 'get-status',
			array(
				'label'               => __( 'Get Composer status', 'smartcloud-agent-composer' ),
				'description'         => __( 'Returns safe runtime, configuration, and provider discovery status.', 'smartcloud-agent-composer' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array( 'type' => 'object', 'additionalProperties' => false ),
				'output_schema'       => array( 'type' => 'object', 'additionalProperties' => true ),
				'execute_callback'    => array( $this, 'status' ),
				'permission_callback' => static fn (): bool => current_user_can( Activation::CAP_VIEW_STATUS ),
				'meta'                => array(
					'show_in_rest'        => false,
					'mcp'                 => array( 'public' => false ),
					'annotations'         => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
					'smartcloud_composer' => array( 'schema_version' => '1.0.0-rc.1', 'operation' => 'discover', 'agent_draft_safe' => true ),
				),
			)
		);
	}

	public function status(): array {
		return array(
			'version'           => SMARTCLOUD_COMPOSER_VERSION,
			'active_config_set' => (string) get_option( 'smartcloud_composer_active_config_set', '' ),
			'providers'         => $this->providers->profiles(),
			'execution_contract' => Abilities::CONTRACT,
		);
	}
}
