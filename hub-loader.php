<?php

namespace SmartCloud\WPSuite\Hub;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const SMARTCLOUD_WPSUITE_AGENT_COMPOSER_HUB_VERSION = '2.5.6';

final class AgentComposerHubLoader {
	private static ?self $instance = null;
	private ?object $admin = null;

	private function __construct(
		private readonly string $plugin,
		private readonly string $text_domain
	) {
		$this->includes();
	}

	public static function instance( string $plugin, string $text_domain ): self {
		return self::$instance ?? ( self::$instance = new self( $plugin, $text_domain ) );
	}

	public function init(): void {
		if ( ! isset( $this->admin ) ) {
			return;
		}
		add_action( 'admin_menu', array( $this, 'create_admin_menu' ), 10 );
		if ( method_exists( $this->admin, 'init' ) ) {
			$this->admin->init();
		}
	}

	public function create_admin_menu(): void {
		if ( ! isset( $this->admin ) ) {
			return;
		}
		$icon_url = method_exists( $this->admin, 'getIconUrl' ) ? $this->admin->getIconUrl() : 'dashicons-cloud';
		add_menu_page(
			__( 'SmartCloud', 'smartcloud-agent-composer' ),
			__( 'SmartCloud', 'smartcloud-agent-composer' ),
			'manage_options',
			SMARTCLOUD_WPSUITE_SLUG,
			null,
			$icon_url,
			58
		);
		$connect_suffix = add_submenu_page(
			SMARTCLOUD_WPSUITE_SLUG,
			__( 'Connect your Site to WP Suite', 'smartcloud-agent-composer' ),
			__( 'Connect your Site', 'smartcloud-agent-composer' ),
			'manage_options',
			SMARTCLOUD_WPSUITE_SLUG,
			array( $this->admin, 'renderAdminPage' )
		);
		$settings_suffix = add_submenu_page(
			SMARTCLOUD_WPSUITE_SLUG,
			__( 'WP Suite General Settings', 'smartcloud-agent-composer' ),
			__( 'Global Settings', 'smartcloud-agent-composer' ),
			'manage_options',
			SMARTCLOUD_WPSUITE_SLUG . '-settings',
			array( $this->admin, 'renderAdminPage' )
		);
		if ( method_exists( $this->admin, 'enqueueAdminScripts' ) ) {
			$this->admin->enqueueAdminScripts( $connect_suffix, $settings_suffix );
		}
	}

	public function check(): void {
		if ( isset( $this->admin ) && method_exists( $this->admin, 'check' ) ) {
			$this->admin->check();
		}
	}

	private function includes(): bool {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( ! empty( $GLOBALS['smartcloud_wpsuite_menu_parent'] ) ) {
			return false;
		}
		if ( ! defined( 'SMARTCLOUD_WPSUITE_SLUG' ) ) {
			define( 'SMARTCLOUD_WPSUITE_SLUG', 'hub-for-wpsuiteio' );
		}

		$owner_option = SMARTCLOUD_WPSUITE_SLUG . '/top-menu-owner';
		$owner         = get_option( $owner_option );
		$owner_version = (string) ( get_option( $owner_option . '/version' ) ?: '1.0.0' );
		$owner_missing = empty( $owner );
		$owner_is_me   = $owner === $this->plugin;

		$plugin_dir          = plugin_dir_path( __DIR__ );
		$owner_plugin        = ltrim( str_replace( '\\/', '/', wp_unslash( (string) $owner ) ), '/\\' );
		$owner_plugin_path   = wp_normalize_path( untrailingslashit( $plugin_dir ) . '/' . $owner_plugin );
		$active_valid        = array_map( 'wp_normalize_path', wp_get_active_and_valid_plugins() );
		$owner_is_active     = ! empty( $owner_plugin ) && is_plugin_active( $owner_plugin );
		$owner_exists        = ! empty( $owner_plugin ) && file_exists( $owner_plugin_path );
		$owner_is_valid      = in_array( $owner_plugin_path, $active_valid, true );
		$owner_inactive      = ! $owner_is_active || ! $owner_is_valid || ! $owner_exists;
		$version_is_smaller  = version_compare( $owner_version, SMARTCLOUD_WPSUITE_AGENT_COMPOSER_HUB_VERSION, '<' );
		$version_is_equal    = 0 === version_compare( $owner_version, SMARTCLOUD_WPSUITE_AGENT_COMPOSER_HUB_VERSION );

		if ( ! $owner_missing && ! $owner_is_me && ! $owner_inactive && ! $version_is_smaller ) {
			return false;
		}
		$result = false;
		if ( empty( $GLOBALS['smartcloud_wpsuite_fallback_parent_added'] ) ) {
			$GLOBALS['smartcloud_wpsuite_fallback_parent_added'] = true;
			$result = true;
			if ( ! defined( 'SMARTCLOUD_WPSUITE_VERSION' ) ) {
				define( 'SMARTCLOUD_WPSUITE_VERSION', SMARTCLOUD_WPSUITE_AGENT_COMPOSER_HUB_VERSION );
			}
			if ( ! defined( 'SMARTCLOUD_WPSUITE_PATH' ) ) {
				define( 'SMARTCLOUD_WPSUITE_PATH', plugin_dir_path( __FILE__ ) . SMARTCLOUD_WPSUITE_SLUG . '/' );
			}
			if ( ! defined( 'SMARTCLOUD_WPSUITE_URL' ) ) {
				define( 'SMARTCLOUD_WPSUITE_URL', plugin_dir_url( __FILE__ ) . SMARTCLOUD_WPSUITE_SLUG . '/' );
			}
			if ( ! defined( 'SMARTCLOUD_WPSUITE_READY_HOOK' ) ) {
				define( 'SMARTCLOUD_WPSUITE_READY_HOOK', SMARTCLOUD_WPSUITE_SLUG . '/ready' );
			}
			if ( file_exists( SMARTCLOUD_WPSUITE_PATH . 'index.php' ) ) {
				require_once SMARTCLOUD_WPSUITE_PATH . 'index.php';
			}
			if ( class_exists( '\\SmartCloud\\WPSuite\\Hub\\HubAdmin' ) ) {
				$this->admin = new HubAdmin();
			}
			if ( ! $owner_is_me || ! $version_is_equal ) {
				update_option( $owner_option, $this->plugin, false );
				update_option( $owner_option . '/version', SMARTCLOUD_WPSUITE_AGENT_COMPOSER_HUB_VERSION, false );
			}
		}
		if ( ! $owner_is_me && $version_is_smaller ) {
			update_option( $owner_option, $this->plugin, false );
			update_option( $owner_option . '/version', SMARTCLOUD_WPSUITE_AGENT_COMPOSER_HUB_VERSION, false );
		}
		return $result;
	}
}
