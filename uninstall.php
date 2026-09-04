<?php
/**
 * SmartCloud Agent Composer uninstall cleanup.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Remove Composer-owned data from the current site without deleting normal content drafts.
 */
function smartcloud_composer_uninstall_site(): void {
	global $wpdb;

	$config_ids = get_posts(
		array(
			'post_type'      => 'smartcloud_composer',
			'post_status'    => 'any',
			'fields'         => 'ids',
			'posts_per_page' => -1,
			'no_found_rows'  => true,
		)
	);
	foreach ( $config_ids as $post_id ) {
		wp_delete_post( (int) $post_id, true );
	}

	$preview_ids = get_posts(
		array(
			'post_type'      => 'any',
			'post_status'    => 'draft',
			'fields'         => 'ids',
			'posts_per_page' => 500,
			'no_found_rows'  => true,
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Uninstall targets only Composer-marked temporary previews.
				array( 'key' => '_smartcloud_composer_preview', 'value' => '1' ),
				array( 'key' => '_wpsuite_agent_owned', 'value' => '1' ),
			),
		)
	);
	foreach ( $preview_ids as $post_id ) {
		wp_delete_post( (int) $post_id, true );
	}

	wp_clear_scheduled_hook( 'smartcloud_composer_cleanup_preview_drafts' );

	$options = array(
		'smartcloud_composer_db_version',
		'smartcloud_composer_role_schema_version',
		'smartcloud_composer_active_config_set',
		'smartcloud_composer_previous_config_set',
		'smartcloud_composer_active_snapshot',
		'smartcloud_composer_activation_receipt',
		'smartcloud_composer_activation_lock',
		'smartcloud_composer_last_discovery',
		'smartcloud_composer_legacy_role_migrated',
		'smartcloud_composer_blueprint_keys_migrated',
		'smartcloud_composer_validation_receipts',
	);
	foreach ( $options as $option ) {
		delete_option( $option );
	}

	$transient_like         = $wpdb->esc_like( '_transient_smartcloud_composer_validation_' ) . '%';
	$transient_timeout_like = $wpdb->esc_like( '_transient_timeout_smartcloud_composer_validation_' ) . '%';
	$transient_options      = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall must enumerate plugin-owned transient rows.
		$wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$transient_like,
			$transient_timeout_like
		)
	);
	foreach ( $transient_options as $option ) {
		delete_option( (string) $option );
	}
	$proposal_lock_like = $wpdb->esc_like( '_wpsuite_agent_proposal_lock_' ) . '%';
	$localization_lock_like = $wpdb->esc_like( '_wpsuite_agent_localization_lock_' ) . '%';
	$idempotency_lock_like = $wpdb->esc_like( '_wpsuite_agent_lock_' ) . '%';
	$proposal_locks = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall enumerates only Composer-owned locks.
		$wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$proposal_lock_like,
			$localization_lock_like,
			$idempotency_lock_like
		)
	);
	foreach ( $proposal_locks as $option ) {
		delete_option( (string) $option );
	}

	$table = esc_sql( $wpdb->prefix . 'smartcloud_composer_audit' );
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- The table is plugin-owned and uninstall is explicit.

	$capabilities = array(
		'smartcloud_agent_use',
		'smartcloud_composer_view_status',
		'smartcloud_composer_edit_config',
		'smartcloud_composer_validate_config',
		'smartcloud_composer_activate_config',
		'smartcloud_composer_rollback_config',
		'smartcloud_composer_view_audit',
		'smartcloud_composer_execute_drafts',
		'smartcloud_composer_ingest_media',
		'smartcloud_composer_assign_terms',
		'smartcloud_composer_create_terms',
		'smartcloud_composer_propose_published_updates',
		'smartcloud_composer_merge_content_proposals',
	);
	$administrator = get_role( 'administrator' );
	if ( $administrator ) {
		foreach ( $capabilities as $capability ) {
			$administrator->remove_cap( $capability );
		}
	}
	remove_role( 'smartcloud_agent' );
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $smartcloud_composer_site_id ) {
		switch_to_blog( (int) $smartcloud_composer_site_id );
		smartcloud_composer_uninstall_site();
		restore_current_blog();
	}
} else {
	smartcloud_composer_uninstall_site();
}
