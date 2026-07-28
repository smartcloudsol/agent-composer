<?php

namespace SmartCloud\AgentComposer\Infrastructure\WordPress;

use SmartCloud\AgentComposer\Domain\Configuration\EntityType;
use SmartCloud\AgentComposer\Infrastructure\Persistence\AuditTable;
use SmartCloud\AgentComposer\Infrastructure\Persistence\WordPressConfigurationRepository;

final class Activation {
	public const ROLE                = 'smartcloud_agent';
	public const CAP_USE             = 'smartcloud_agent_use';
	public const CAP_VIEW_STATUS     = 'smartcloud_composer_view_status';
	public const CAP_EDIT_CONFIG     = 'smartcloud_composer_edit_config';
	public const CAP_VALIDATE_CONFIG = 'smartcloud_composer_validate_config';
	public const CAP_ACTIVATE_CONFIG = 'smartcloud_composer_activate_config';
	public const CAP_ROLLBACK_CONFIG = 'smartcloud_composer_rollback_config';
	public const CAP_VIEW_AUDIT      = 'smartcloud_composer_view_audit';
	public const CAP_EXECUTE_DRAFTS  = 'smartcloud_composer_execute_drafts';

	public static function activate( bool $network_wide = false ): void {
		if ( is_multisite() && $network_wide ) {
			foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $site_id ) {
				switch_to_blog( (int) $site_id );
				self::activate_site();
				restore_current_blog();
			}
			return;
		}
		self::activate_site();
	}

	public static function activate_site(): void {
		EntityPostType::register();
		self::install_roles();
		AuditTable::install();
		self::migrate_legacy_role_users();
		self::migrate_blueprint_entity_keys();
		update_option( 'smartcloud_composer_db_version', SMARTCLOUD_COMPOSER_VERSION, false );
		flush_rewrite_rules( false );
	}

	public static function maybe_upgrade(): void {
		self::migrate_blueprint_entity_keys();
		if ( (string) get_option( 'smartcloud_composer_db_version', '' ) !== SMARTCLOUD_COMPOSER_VERSION ) {
			self::activate_site();
		}
	}

	private static function install_roles(): void {
		$agent_caps = array(
			'read'                   => true,
			'edit_pages'             => true,
			'edit_posts'             => true,
			self::CAP_USE            => true,
			self::CAP_VIEW_STATUS    => true,
			self::CAP_EXECUTE_DRAFTS => true,
		);
		$role = get_role( self::ROLE ) ?: add_role( self::ROLE, __( 'SmartCloud Agent', 'smartcloud-agent-composer' ), $agent_caps );
		if ( $role ) {
			foreach ( array_keys( $agent_caps ) as $capability ) {
				$role->add_cap( $capability );
			}
			foreach ( self::forbidden_caps() as $capability ) {
				$role->remove_cap( $capability );
			}
		}

		$administrator = get_role( 'administrator' );
		if ( $administrator ) {
			foreach ( self::composer_caps() as $capability ) {
				$administrator->add_cap( $capability );
			}
		}
	}

	private static function migrate_legacy_role_users(): void {
		if ( get_option( 'smartcloud_composer_legacy_role_migrated', false ) ) {
			return;
		}
		foreach ( get_users( array( 'role' => 'wpsuite_agent', 'fields' => array( 'ID' ) ) ) as $legacy_user ) {
			$user = new \WP_User( (int) $legacy_user->ID );
			$user->add_role( self::ROLE );
		}
		update_option( 'smartcloud_composer_legacy_role_migrated', '1', false );
	}

	private static function migrate_blueprint_entity_keys(): void {
		if ( get_option( 'smartcloud_composer_blueprint_keys_migrated', false ) ) {
			return;
		}
		$repository = new WordPressConfigurationRepository();
		foreach ( $repository->find_by_type( EntityType::BLUEPRINT ) as $blueprint ) {
			$payload   = json_decode( (string) $blueprint->post_content, true );
			$page_type = sanitize_key( (string) ( is_array( $payload ) ? ( $payload['page_type'] ?? '' ) : '' ) );
			if ( '' !== $page_type ) {
				update_post_meta( $blueprint->ID, '_smartcloud_composer_entity_key', $page_type );
			}
		}
		update_option( 'smartcloud_composer_blueprint_keys_migrated', '1', false );
	}

	private static function composer_caps(): array {
		return array(
			self::CAP_USE,
			self::CAP_VIEW_STATUS,
			self::CAP_EDIT_CONFIG,
			self::CAP_VALIDATE_CONFIG,
			self::CAP_ACTIVATE_CONFIG,
			self::CAP_ROLLBACK_CONFIG,
			self::CAP_VIEW_AUDIT,
			self::CAP_EXECUTE_DRAFTS,
		);
	}

	private static function forbidden_caps(): array {
		return array(
			'publish_pages', 'publish_posts', 'delete_pages', 'delete_posts',
			'delete_others_pages', 'delete_others_posts', 'edit_others_pages',
			'edit_others_posts', 'manage_options', 'edit_theme_options',
			'install_plugins', 'activate_plugins', 'upload_files', 'unfiltered_html',
		);
	}

	private function __construct() {}
}
