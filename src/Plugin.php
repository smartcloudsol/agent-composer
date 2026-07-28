<?php

namespace SmartCloud\AgentComposer;

use SmartCloud\AgentComposer\Application\Execution\ExecutionRuntime;
use SmartCloud\AgentComposer\Application\Configuration\PresetPatternRegistry;
use SmartCloud\AgentComposer\Integration\Abilities\ComposerAbilities;
use SmartCloud\AgentComposer\Integration\Providers\ProviderRegistry;
use SmartCloud\AgentComposer\Infrastructure\WordPress\Activation;
use SmartCloud\AgentComposer\Infrastructure\WordPress\ConfigurationController;
use SmartCloud\AgentComposer\Infrastructure\WordPress\EntityPostType;
use SmartCloud\AgentComposer\Infrastructure\WordPress\StatusController;

final class Plugin {
	private static ?self $instance = null;
	private ?ExecutionRuntime $execution = null;

	public static function boot(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->hooks();
		}
		return self::$instance;
	}

	private function hooks(): void {
		add_action( 'init', array( EntityPostType::class, 'register' ) );
		add_action( 'init', array( PresetPatternRegistry::class, 'register' ), 12 );
		add_action( 'init', array( Activation::class, 'maybe_upgrade' ), 20 );
		add_action( 'rest_api_init', array( new StatusController(), 'register_routes' ) );
		add_action( 'rest_api_init', array( new ConfigurationController(), 'register_routes' ) );
		$abilities = new ComposerAbilities( new ProviderRegistry() );
		add_action( 'wp_abilities_api_categories_init', array( $abilities, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( $abilities, 'register' ) );
		$this->execution = new ExecutionRuntime();
		$this->execution->hooks();

		$admin_file = SMARTCLOUD_COMPOSER_DIR . 'admin/admin.php';
		if ( ! is_readable( $admin_file ) ) {
			$admin_file = SMARTCLOUD_COMPOSER_DIR . 'admin/php/admin.php';
		}
		if ( is_readable( $admin_file ) ) {
			require_once $admin_file;
			if ( class_exists( 'SmartCloud\\AgentComposer\\Admin\\AdminPage' ) ) {
				( new \SmartCloud\AgentComposer\Admin\AdminPage() )->hooks();
			}
		}
	}

	private function __construct() {}
}
