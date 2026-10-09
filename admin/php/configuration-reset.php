<?php

namespace SmartCloud\AgentComposer\Admin;

use SmartCloud\AgentComposer\Infrastructure\Persistence\AuditTable;
use SmartCloud\AgentComposer\Infrastructure\WordPress\Activation;
use SmartCloud\AgentComposer\Infrastructure\WordPress\EntityPostType;
use SmartCloud\WPSuite\Hub\ConfigurationReset;

/** Explicit full configuration lifecycle operation. Ordinary content and audit records survive. */
trait EditingConfigurationReset {
	public function editingConfigurationReset(): array {
		return array(
			'options' => array('smartcloud_composer_active_config_set', 'smartcloud_composer_previous_config_set', 'smartcloud_composer_activation_receipt', 'smartcloud_composer_active_snapshot', 'smartcloud_composer_last_discovery', 'smartcloud_composer_mcp_security'),
			'title' => __('Reset all AI Editing configuration?', 'smartcloud-agent-composer'),
			'consequences' => array(
				__('All Config Sets, site contracts, blueprints, components, style mappings, provider policies and discovery snapshots will be permanently deleted, including active and inactive configurations.', 'smartcloud-agent-composer'),
				__('Configuration activation and MCP access settings will be removed. Agent editing will require a new validated and activated configuration.', 'smartcloud-agent-composer'),
			),
			'preserved' => array(__('Pages, posts, patterns, media, content proposals, audit history, WordPress users and AWS resources are preserved.', 'smartcloud-agent-composer')),
			'revision' => array($this, 'editingConfigurationResetState'),
			'reset' => array($this, 'resetEditingConfiguration'),
		);
	}

	/** Hash configuration rows and their metadata without exposing authored data through the reset API. */
	public function editingConfigurationResetState(): string {
		global $wpdb;
		$rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->posts} WHERE post_type = %s ORDER BY ID", EntityPostType::POST_TYPE), ARRAY_A); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Fresh concurrency snapshot, exact plugin-owned CPT.
		if (null === $rows || $wpdb->last_error) throw new \RuntimeException('Cannot read Composer configuration.');
		$meta = $wpdb->get_results($wpdb->prepare("SELECT m.* FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE p.post_type = %s ORDER BY m.meta_id", EntityPostType::POST_TYPE), ARRAY_A); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Includes advanced metadata in confirmation revision.
		if (null === $meta || $wpdb->last_error) throw new \RuntimeException('Cannot read Composer configuration metadata.');
		return hash('sha256', (string) wp_json_encode(array($rows, $meta)));
	}

	public function resetEditingConfiguration(string $revision): bool|\WP_Error {
		if (!current_user_can('manage_options') || !current_user_can(Activation::CAP_EDIT_CONFIG) || !current_user_can(Activation::CAP_ACTIVATE_CONFIG)) {
			return new \WP_Error('composer_reset_forbidden', __('You cannot reset Composer configuration.', 'smartcloud-agent-composer'), array('status' => 403));
		}
		// Share the existing activate/deactivate/delete lifecycle lock; never steal an in-flight operation.
		$lock = 'smartcloud_composer_activation_lock';
		$token = wp_generate_uuid4();
		if (!add_option($lock, array('token' => $token, 'expires' => time() + 300), '', false)) {
			return new \WP_Error('composer_reset_busy', __('Another configuration lifecycle operation is in progress. Wait before resetting.', 'smartcloud-agent-composer'), array('status' => 409));
		}
		global $wpdb;
		$ids = array();
		$transaction = false;
		try {
			if (false === $wpdb->query('START TRANSACTION')) throw new \RuntimeException('Cannot start configuration reset.');
			$transaction = true;
			$ids = $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type = %s ORDER BY ID FOR UPDATE", EntityPostType::POST_TYPE)); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Locks only plugin configuration rows against concurrent edits.
			if ($wpdb->last_error) throw new \RuntimeException('Cannot lock configuration records.');
			foreach ($ids as $id) clean_post_cache((int) $id);
			if (!hash_equals($revision, ConfigurationReset::revision($this->editingConfigurationReset()))) {
				$wpdb->query('ROLLBACK');
				$transaction = false;
				return new \WP_Error('composer_reset_conflict', __('Composer configuration changed. Review the reset again.', 'smartcloud-agent-composer'), array('status' => 409));
			}
			$result = ConfigurationReset::deleteOptions($this->editingConfigurationReset()['options'], false);
			if (is_wp_error($result)) throw new \RuntimeException('Cannot reset configuration options.');
			foreach (array_reverse($ids) as $id) {
				// Core deletes the configuration record, its revisions and metadata using the normal WP lifecycle.
				if (!wp_delete_post((int) $id, true) instanceof \WP_Post) throw new \RuntimeException('Cannot delete configuration record.');
			}
			(new AuditTable())->record('configuration-reset', 'success', array('configuration_entities_deleted' => count($ids)), 0, 0, null, false);
			if (false === $wpdb->query('COMMIT')) throw new \RuntimeException('Cannot commit configuration reset.');
			$transaction = false;
			return true;
		} catch (\Throwable $error) {
			if ($transaction) $wpdb->query('ROLLBACK');
			return new \WP_Error('composer_reset_failed', __('Composer configuration could not be completely reset. Reload the settings before trying again.', 'smartcloud-agent-composer'), array('status' => 500));
		} finally {
			foreach ($ids as $id) clean_post_cache((int) $id);
			foreach ($this->editingConfigurationReset()['options'] as $key) wp_cache_delete($key, 'options');
			wp_cache_delete('alloptions', 'options');
			wp_cache_delete('notoptions', 'options');
			$current = get_option($lock, array());
			if (is_array($current) && ($current['token'] ?? '') === $token) delete_option($lock);
		}
	}
}
