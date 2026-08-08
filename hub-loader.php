<?php

namespace SmartCloud\WPSuite\Hub;

if (!defined('ABSPATH')) {
	exit;
}

const SMARTCLOUD_WPSUITE_AGENT_COMPOSER_HUB_VERSION = '2.5.11';

final class AgentComposerHubLoader
{
	private static ?self $instance = null;
	private ?object $admin = null;

	private function __construct(
		private readonly string $plugin,
		private readonly string $text_domain
	) {
		$this->includes();
	}

	public static function instance(string $plugin, string $text_domain): self
	{
		return self::$instance ?? (self::$instance = new self($plugin, $text_domain));
	}

	public function init(): void
	{
		if (!isset($this->admin)) {
			return;
		}
		add_action('admin_menu', array($this, 'create_admin_menu'), 10);
		if (method_exists($this->admin, 'init')) {
			$this->admin->init();
		}
	}

	public function create_admin_menu(): void
	{
		if (!isset($this->admin)) {
			return;
		}
		$icon_url = method_exists($this->admin, 'getIconUrl') ? $this->admin->getIconUrl() : 'dashicons-cloud';
		add_menu_page(
			__('SmartCloud', 'smartcloud-agent-composer'),
			__('SmartCloud', 'smartcloud-agent-composer'),
			'manage_options',
			SMARTCLOUD_WPSUITE_CANONICAL_SLUG,
			null,
			$icon_url,
			58
		);
		$connect_suffix = add_submenu_page(
			SMARTCLOUD_WPSUITE_CANONICAL_SLUG,
			__('Connect your Site to WP Suite', 'smartcloud-agent-composer'),
			__('Connect your Site', 'smartcloud-agent-composer'),
			'manage_options',
			SMARTCLOUD_WPSUITE_CANONICAL_SLUG,
			array($this->admin, 'renderAdminPage')
		);
		$settings_suffix = add_submenu_page(
			SMARTCLOUD_WPSUITE_CANONICAL_SLUG,
			__('WP Suite General Settings', 'smartcloud-agent-composer'),
			__('Global Settings', 'smartcloud-agent-composer'),
			'manage_options',
			SMARTCLOUD_WPSUITE_CANONICAL_SLUG . '-settings',
			array($this->admin, 'renderAdminPage')
		);
		add_submenu_page(
			null,
			__('WP Suite', 'smartcloud-agent-composer'),
			__('WP Suite', 'smartcloud-agent-composer'),
			'manage_options',
			SMARTCLOUD_WPSUITE_LEGACY_SLUG,
			array($this->admin, 'renderLegacyAdminPage')
		);
		if (method_exists($this->admin, 'enqueueAdminScripts')) {
			$this->admin->enqueueAdminScripts($connect_suffix, $settings_suffix);
		}
	}

	public function check(): void
	{
		if (isset($this->admin) && method_exists($this->admin, 'check')) {
			$this->admin->check();
		}
	}

	private function includes(): bool
	{
		if (!function_exists('is_plugin_active')) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$runtime_already_loaded = !empty($GLOBALS['smartcloud_wpsuite_menu_parent']);
		if (!defined('SMARTCLOUD_WPSUITE_CANONICAL_SLUG')) {
			define('SMARTCLOUD_WPSUITE_CANONICAL_SLUG', 'smartcloud-wpsuite');
		}
		if (!defined('SMARTCLOUD_WPSUITE_LEGACY_SLUG')) {
			define('SMARTCLOUD_WPSUITE_LEGACY_SLUG', 'hub-for-wpsuiteio');
		}
		if (!defined('SMARTCLOUD_WPSUITE_SLUG')) {
			define('SMARTCLOUD_WPSUITE_SLUG', SMARTCLOUD_WPSUITE_CANONICAL_SLUG);
		}
		if (!defined('SMARTCLOUD_WPSUITE_RUNTIME_DIRECTORY')) {
			define('SMARTCLOUD_WPSUITE_RUNTIME_DIRECTORY', 'smartcloud-wpsuite');
		}

		$owner_option = SMARTCLOUD_WPSUITE_CANONICAL_SLUG . '/top-menu-owner';
		$legacy_owner_option = SMARTCLOUD_WPSUITE_LEGACY_SLUG . '/top-menu-owner';
		$site_id = get_current_blog_id();

		$get_blog_option = static function (string $name, $default = '') use ($site_id) {
			if (is_multisite()) {
				return get_blog_option($site_id, $name, $default);
			}

			return get_option($name, $default);
		};
		$update_blog_option = static function (string $name, $value, bool $autoload = false) use ($site_id) {
			if (is_multisite()) {
				return update_blog_option($site_id, $name, $value);
			}

			return update_option($name, $value, $autoload);
		};

		$owner = $get_blog_option($owner_option, '');
		$owner_version = $get_blog_option($owner_option . '/version', '1.0.0');
		if (empty($owner)) {
			$legacy_owner = $get_blog_option($legacy_owner_option, '');
			if (!empty($legacy_owner)) {
				$owner = $legacy_owner;
				$owner_version = $get_blog_option($legacy_owner_option . '/version', '1.0.0');
				$update_blog_option($owner_option, $owner, false);
				$update_blog_option($owner_option . '/version', $owner_version, false);
			}
		}

		$set_owner = static function (string $plugin, string $version) use ($owner_option, $legacy_owner_option, $update_blog_option) {
			$update_blog_option($owner_option, $plugin, false);
			$update_blog_option($owner_option . '/version', $version, false);
			$update_blog_option($legacy_owner_option, $plugin, false);
			$update_blog_option($legacy_owner_option . '/version', $version, false);
		};
		$owner_missing = empty($owner);
		$owner_is_me = $owner === $this->plugin;

		$plugin_dir = plugin_dir_path(__DIR__);
		$owner_plugin = ltrim(str_replace('\\/', '/', wp_unslash((string) $owner)), '/\\');
		$owner_plugin_path = wp_normalize_path(untrailingslashit($plugin_dir) . '/' . $owner_plugin);
		$active_valid = wp_get_active_and_valid_plugins();
		if (is_multisite()) {
			$active_valid = array_merge($active_valid, wp_get_active_network_plugins());
		}
		$active_valid = array_map('wp_normalize_path', $active_valid);
		$owner_is_active = !empty($owner_plugin) && is_plugin_active($owner_plugin);
		$owner_exists = !empty($owner_plugin) && file_exists($owner_plugin_path);
		$owner_is_valid = in_array($owner_plugin_path, $active_valid, true);
		$owner_inactive = !$owner_is_active || !$owner_is_valid || !$owner_exists;
		$version_is_smaller = version_compare($owner_version, SMARTCLOUD_WPSUITE_AGENT_COMPOSER_HUB_VERSION, '<');
		$version_is_equal = 0 === version_compare($owner_version, SMARTCLOUD_WPSUITE_AGENT_COMPOSER_HUB_VERSION);
		if ($runtime_already_loaded) {
			if ($owner_missing || $owner_inactive || $version_is_smaller) {
				$set_owner($this->plugin, SMARTCLOUD_WPSUITE_AGENT_COMPOSER_HUB_VERSION);
			}
			return false;
		}

		if (!$owner_missing && !$owner_is_me && !$owner_inactive && !$version_is_smaller) {
			return false;
		}
		$result = false;
		if (empty($GLOBALS['smartcloud_wpsuite_fallback_parent_added'])) {
			$GLOBALS['smartcloud_wpsuite_fallback_parent_added'] = true;
			$result = true;
			if (!defined('SMARTCLOUD_WPSUITE_VERSION')) {
				define('SMARTCLOUD_WPSUITE_VERSION', SMARTCLOUD_WPSUITE_AGENT_COMPOSER_HUB_VERSION);
			}
			if (!defined('SMARTCLOUD_WPSUITE_PATH')) {
				define('SMARTCLOUD_WPSUITE_PATH', plugin_dir_path(__FILE__) . SMARTCLOUD_WPSUITE_RUNTIME_DIRECTORY . '/');
			}
			if (!defined('SMARTCLOUD_WPSUITE_URL')) {
				define('SMARTCLOUD_WPSUITE_URL', plugin_dir_url(__FILE__) . SMARTCLOUD_WPSUITE_RUNTIME_DIRECTORY . '/');
			}
			if (!defined('SMARTCLOUD_WPSUITE_READY_HOOK')) {
				define('SMARTCLOUD_WPSUITE_READY_HOOK', SMARTCLOUD_WPSUITE_CANONICAL_SLUG . '/ready');
			}
			$runtime_index = SMARTCLOUD_WPSUITE_PATH . 'index.php';
			if (file_exists($runtime_index)) {
				require_once SMARTCLOUD_WPSUITE_PATH . 'index.php';
			}
			if (class_exists('\\SmartCloud\\WPSuite\\Hub\\HubAdmin')) {
				$this->admin = new HubAdmin();
			}
			if (!$owner_is_me || !$version_is_equal) {
				$set_owner($this->plugin, SMARTCLOUD_WPSUITE_AGENT_COMPOSER_HUB_VERSION);
			}
		}
		if (!$owner_is_me && $version_is_smaller) {
			$set_owner($this->plugin, SMARTCLOUD_WPSUITE_AGENT_COMPOSER_HUB_VERSION);
		}
		return $result;
	}
}
