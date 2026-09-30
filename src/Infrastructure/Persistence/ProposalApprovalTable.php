<?php

namespace SmartCloud\AgentComposer\Infrastructure\Persistence;

/** Durable, revision-bound approval requests for published-content proposals. */
final class ProposalApprovalTable {
	public static function name(): string {
		global $wpdb;
		return $wpdb->prefix . 'smartcloud_composer_proposal_approvals';
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table = self::name();
		$collate = $wpdb->get_charset_collate();
		dbDelta( "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			request_uuid char(36) NOT NULL,
			proposal_post_id bigint(20) unsigned NOT NULL,
			source_post_id bigint(20) unsigned NOT NULL,
			revision varchar(64) NOT NULL,
			modified_gmt datetime NOT NULL,
			content_hash char(64) NOT NULL,
			rendered_hash char(64) NOT NULL,
			base_fingerprint varchar(71) NOT NULL,
			assigned_principal varchar(96) NOT NULL DEFAULT '',
			assigned_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			requester_principal varchar(96) NOT NULL,
			requester_client varchar(256) NOT NULL DEFAULT '',
			requested_gmt datetime NOT NULL,
			expires_gmt datetime NOT NULL,
			status varchar(24) NOT NULL,
			preview_token_hash char(64) NOT NULL,
			approver_principal varchar(96) NOT NULL DEFAULT '',
			decision_reason text NOT NULL,
			decided_gmt datetime NULL,
			PRIMARY KEY (id),
			UNIQUE KEY request_uuid (request_uuid),
			KEY proposal_status (proposal_post_id, status),
			KEY source_status (source_post_id, status),
			KEY expires_gmt (expires_gmt)
		) {$collate};" );
	}

	public function insert( array $row ): bool {
		global $wpdb;
		return false !== $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- This plugin owns the proposal-approval table.
			self::name(),
			$row,
			array( '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	public function find( string $uuid ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Approval decisions require the current revision-bound record.
			$wpdb->prepare( 'SELECT * FROM %i WHERE request_uuid = %s LIMIT 1', self::name(), $uuid ),
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	/** Atomically move a request from one state to another. */
	public function decide( string $uuid, string $from, string $to, string $approver, string $reason = '' ): bool {
		global $wpdb;
		$updated = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic compare-and-set prevents repeated or competing approval decisions.
			$wpdb->prepare(
				'UPDATE %i SET status = %s, approver_principal = %s, decision_reason = %s, decided_gmt = %s WHERE request_uuid = %s AND status = %s',
				self::name(), $to, $approver, $reason, gmdate( 'Y-m-d H:i:s' ), $uuid, $from
			)
		);
		return 1 === $updated;
	}
}
