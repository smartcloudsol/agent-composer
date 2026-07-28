<?php

namespace SmartCloud\AgentComposer\Infrastructure\Persistence;

use RuntimeException;
use SmartCloud\AgentComposer\Domain\Configuration\CanonicalJson;

final class AuditTable {
	public static function name(): string {
		global $wpdb;
		return $wpdb->prefix . 'smartcloud_composer_audit';
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::name();
		$collate = $wpdb->get_charset_collate();
		$sql     = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			event_uuid char(36) NOT NULL,
			created_gmt datetime NOT NULL,
			request_id varchar(64) NOT NULL,
			actor_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			event_type varchar(96) NOT NULL,
			outcome varchar(16) NOT NULL,
			config_set_id bigint(20) unsigned NOT NULL DEFAULT 0,
			entity_id bigint(20) unsigned NOT NULL DEFAULT 0,
			context_json longtext NULL,
			previous_hash char(64) NOT NULL DEFAULT '',
			event_hash char(64) NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY event_uuid (event_uuid),
			KEY created_gmt (created_gmt),
			KEY request_id (request_id),
			KEY event_type (event_type),
			KEY config_entity (config_set_id, entity_id)
		) {$collate};";
		dbDelta( $sql );
	}

	public function record( string $event_type, string $outcome, array $context = array(), int $config_set_id = 0, int $entity_id = 0, ?string $event_uuid = null, bool $manage_transaction = true ): string {
		global $wpdb;
		$table         = esc_sql( self::name() );
		$event_uuid    = null !== $event_uuid && preg_match( '/^[a-f0-9-]{36}$/', $event_uuid ) ? $event_uuid : wp_generate_uuid4();
		$created_gmt   = gmdate( 'Y-m-d H:i:s' );
		$request_id    = substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_REQUEST_ID'] ?? $event_uuid ) ), 0, 64 );
		$clean_context = $this->redact( $context );
		if ( $manage_transaction ) {
			$wpdb->query( 'START TRANSACTION' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The append-only audit chain requires a database transaction.
		}
		$existing = $wpdb->get_var( $wpdb->prepare( "SELECT event_uuid FROM {$table} WHERE event_uuid = %s LIMIT 1 FOR UPDATE", $event_uuid ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Locking audit rows cannot use the object cache.
		if ( $event_uuid === $existing ) {
			if ( $manage_transaction ) {
				$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Completes the audit transaction.
			}
			return $event_uuid;
		}
		$previous_hash = (string) $wpdb->get_var( "SELECT event_hash FROM {$table} ORDER BY id DESC LIMIT 1 FOR UPDATE" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The hash-chain tail must be locked and must not be cached.
		$hash_payload  = array(
			'event_uuid'    => $event_uuid,
			'created_gmt'   => $created_gmt,
			'request_id'    => $request_id,
			'actor_user_id' => get_current_user_id(),
			'event_type'    => sanitize_key( $event_type ),
			'outcome'       => sanitize_key( $outcome ),
			'config_set_id' => $config_set_id,
			'entity_id'     => $entity_id,
			'context'       => $clean_context,
			'previous_hash' => $previous_hash,
		);
		$event_hash = hash( 'sha256', CanonicalJson::encode( $hash_payload ) );
		$inserted   = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- This plugin owns the append-only audit table.
			$table,
			array(
				'event_uuid'    => $event_uuid,
				'created_gmt'   => $created_gmt,
				'request_id'    => $request_id,
				'actor_user_id' => get_current_user_id(),
				'event_type'    => sanitize_key( $event_type ),
				'outcome'       => sanitize_key( $outcome ),
				'config_set_id' => $config_set_id,
				'entity_id'     => $entity_id,
				'context_json'  => CanonicalJson::encode( $clean_context ),
				'previous_hash' => $previous_hash,
				'event_hash'    => $event_hash,
			),
			array( '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%d', '%s', '%s', '%s' )
		);
		if ( false === $inserted ) {
			if ( $manage_transaction ) {
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Rolls back a failed audit append.
			}
			throw new RuntimeException( 'Unable to append the Composer audit event.' );
		}
		if ( $manage_transaction ) {
			$wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Commits the audit append atomically.
		}
		return $event_uuid;
	}

	public function events( int $limit = 50, int $offset = 0 ): array {
		global $wpdb;
		$table = esc_sql( self::name() );
		$limit = min( 200, max( 1, $limit ) );
		$offset = max( 0, $offset );
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Audit events are append-only and must be read from the current chain.
			$wpdb->prepare(
				"SELECT id, event_uuid, created_gmt, request_id, actor_user_id, event_type, outcome, config_set_id, entity_id, context_json, previous_hash, event_hash FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$limit,
				$offset
			),
			ARRAY_A
		);
		return array_map(
			static function ( array $row ): array {
				$context = json_decode( (string) $row['context_json'], true );
				$row['id']            = (int) $row['id'];
				$row['actor_user_id'] = (int) $row['actor_user_id'];
				$row['config_set_id'] = (int) $row['config_set_id'];
				$row['entity_id']     = (int) $row['entity_id'];
				$row['context']       = is_array( $context ) ? $context : array();
				unset( $row['context_json'] );
				return $row;
			},
			is_array( $rows ) ? $rows : array()
		);
	}

	private function redact( mixed $value, string $key = '' ): mixed {
		if ( preg_match( '/(?:secret|token|password|api[_-]?key|authorization)/i', $key ) ) {
			return '[redacted]';
		}
		if ( ! is_array( $value ) ) {
			return is_scalar( $value ) || null === $value ? $value : '[unsupported]';
		}
		$clean = array();
		foreach ( $value as $item_key => $item ) {
			$clean[ $item_key ] = $this->redact( $item, (string) $item_key );
		}
		return $clean;
	}
}
