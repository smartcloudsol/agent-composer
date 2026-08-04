<?php

namespace SmartCloud\AgentComposer\Admin;

use SmartCloud\AgentComposer\Infrastructure\WordPress\Activation;

final class AdminPage {
	private const SLUG = 'smartcloud-agent-composer';
	private string $hook_suffix = '';

	public function hooks(): void {
		add_action( 'admin_menu', array( $this, 'menu' ), 30 );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
	}

	public function menu(): void {
		$parent = ! empty( $GLOBALS['smartcloud_wpsuite_menu_parent'] )
			? (string) $GLOBALS['smartcloud_wpsuite_menu_parent']
			: ( defined( 'SMARTCLOUD_WPSUITE_CANONICAL_SLUG' ) ? SMARTCLOUD_WPSUITE_CANONICAL_SLUG : 'smartcloud-wpsuite' );
		$this->hook_suffix = (string) add_submenu_page(
			$parent,
			__( 'Agent Composer', 'smartcloud-agent-composer' ),
			__( 'Agent Composer', 'smartcloud-agent-composer' ),
			Activation::CAP_VIEW_STATUS,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	public function render(): void {
		echo '<div class="wrap"><div id="smartcloud-composer-admin"></div></div>';
	}

	public function assets( string $hook ): void {
		if ( $this->hook_suffix !== $hook ) {
			return;
		}
		$base       = SMARTCLOUD_COMPOSER_DIR . 'admin/';
		$asset_file = $base . 'index.asset.php';
		$script     = $base . 'index.js';
		$style      = $base . 'index.css';
		if ( ! is_readable( $script ) ) {
			$base       = SMARTCLOUD_COMPOSER_DIR . 'admin/dist/';
			$asset_file = $base . 'index.asset.php';
			$script     = $base . 'index.js';
			$style      = $base . 'index.css';
		}
		if ( ! is_readable( $script ) ) {
			return;
		}
		$asset        = is_readable( $asset_file ) ? require $asset_file : array( 'dependencies' => array( 'wp-api-fetch', 'wp-element', 'wp-i18n' ), 'version' => SMARTCLOUD_COMPOSER_VERSION );
		$dependencies = (array) $asset['dependencies'];
		$hub_path = SMARTCLOUD_COMPOSER_DIR . 'smartcloud-wpsuite/';
		$hub_url  = SMARTCLOUD_COMPOSER_URL . 'smartcloud-wpsuite/';
		if ( ! wp_script_is( 'smartcloud-wpsuite-mantine-vendor', 'registered' ) && is_readable( $hub_path . 'assets/js/mantine-vendor.min.js' ) ) {
			wp_register_script( 'smartcloud-wpsuite-mantine-vendor', $hub_url . 'assets/js/mantine-vendor.min.js', array( 'react', 'react-dom' ), '1.0.8', true );
		}
		if ( wp_script_is( 'smartcloud-wpsuite-mantine-vendor', 'registered' ) ) {
			$dependencies[] = 'smartcloud-wpsuite-mantine-vendor';
		}
		$url   = str_replace( SMARTCLOUD_COMPOSER_DIR, SMARTCLOUD_COMPOSER_URL, $base );
		wp_enqueue_script( 'smartcloud-composer-admin', $url . 'index.js', array_values( array_unique( $dependencies ) ), $asset['version'], true );
		if ( defined( 'SMARTCLOUD_WPSUITE_URL' ) ) {
			$hub_url = SMARTCLOUD_WPSUITE_URL;
		}
		if ( is_readable( str_replace( SMARTCLOUD_COMPOSER_URL, SMARTCLOUD_COMPOSER_DIR, $hub_url ) . 'assets/css/mantine-vendor.css' ) || defined( 'SMARTCLOUD_WPSUITE_URL' ) ) {
			wp_enqueue_style( 'smartcloud-wpsuite-mantine-vendor-style', $hub_url . 'assets/css/mantine-vendor.css', array(), '1.0.8' );
		}
		if ( is_readable( $style ) ) {
			wp_enqueue_style( 'smartcloud-composer-admin', $url . 'index.css', array(), $asset['version'] );
		}
		wp_add_inline_script(
			'smartcloud-composer-admin',
			'window.smartcloudComposerAdmin=' . wp_json_encode(
				array(
					'restRoot' => esc_url_raw( rest_url( 'smartcloud-agent-composer/v1/' ) ),
					'nonce'    => wp_create_nonce( 'wp_rest' ),
					'version'  => SMARTCLOUD_COMPOSER_VERSION,
				)
			) . ';',
			'before'
		);
	}
}
