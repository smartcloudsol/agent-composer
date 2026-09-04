<?php
/**
 * Plugin Name: SmartCloud Agent Composer
 * Description: Configures governed agent drafts and human-reviewed published-content proposals.
 * Version: 1.2.0
 * Requires at least: 6.9
 * Tested up to: 7.1
 * Requires PHP: 8.1
 * Author: SmartCloud
 * Text Domain: smartcloud-agent-composer
 * License: MIT
 * License URI: https://mit-license.org/
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SMARTCLOUD_COMPOSER_VERSION', '1.2.0' );
define( 'SMARTCLOUD_COMPOSER_FILE', __FILE__ );
define( 'SMARTCLOUD_COMPOSER_DIR', plugin_dir_path( __FILE__ ) );
define( 'SMARTCLOUD_COMPOSER_URL', plugin_dir_url( __FILE__ ) );

$smartcloud_composer_hub_loader_file = SMARTCLOUD_COMPOSER_DIR . 'hub-loader.php';
if ( is_readable( $smartcloud_composer_hub_loader_file ) ) {
	require_once $smartcloud_composer_hub_loader_file;
	if ( class_exists( '\\SmartCloud\\WPSuite\\Hub\\AgentComposerHubLoader' ) ) {
		$smartcloud_composer_hub_loader = \SmartCloud\WPSuite\Hub\AgentComposerHubLoader::instance(
			'smartcloud-agent-composer/smartcloud-agent-composer.php',
			'smartcloud-agent-composer'
		);
		add_action( 'init', array( $smartcloud_composer_hub_loader, 'init' ), 15 );
		add_action( 'plugins_loaded', array( $smartcloud_composer_hub_loader, 'check' ), 20 );
	}
}

spl_autoload_register(
	static function ( string $class ): void {
		$prefix = 'SmartCloud\\AgentComposer\\';
		if ( ! str_starts_with( $class, $prefix ) ) {
			return;
		}
		$relative = str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) );
		$file     = SMARTCLOUD_COMPOSER_DIR . 'src/' . $relative . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

register_activation_hook( __FILE__, array( SmartCloud\AgentComposer\Infrastructure\WordPress\Activation::class, 'activate' ) );
SmartCloud\AgentComposer\Plugin::boot();
