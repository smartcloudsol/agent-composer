<?php

namespace SmartCloud\AgentComposer\Infrastructure\Persistence;

use RuntimeException;
use SmartCloud\AgentComposer\Domain\Configuration\CanonicalJson;
use SmartCloud\AgentComposer\Security\ActorIdentity;

final class AuditTable {
	private const MAX_APPEND_ATTEMPTS = 3;
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
		$actor = ActorIdentity::context();
		if ( null !== $actor ) {
			$context['composer_actor'] = $actor->audit_context();
		}
		$clean_context = $this->redact( $context );
		$actor_user_id = get_current_user_id();
		for ( $attempt = 1; ; ++$attempt ) {
			$started = false;
			try {
				if ( $manage_transaction ) {
					if ( false === $wpdb->query( 'START TRANSACTION' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The audit append owns this transaction.
						throw $this->database_exception( 'start the audit transaction' );
					}
					$started = true;
				}
				// Activation creates this permanent per-site row; reset preserves it.
				// Lock it before either audit index so missing-UUID gap locks cannot
				// deadlock concurrent appenders. Its value is never changed here.
				// In caller-owned transactions the lock survives until their commit.
				$options = esc_sql( $wpdb->options );
				$mutex = $wpdb->get_var( $wpdb->prepare( "SELECT option_id FROM {$options} WHERE option_name = %s LIMIT 1 FOR UPDATE", 'smartcloud_composer_db_schema_version' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- A permanent row serializes the append-only chain.
				$this->assert_database_read( 'lock the audit chain' );
				if ( null === $mutex ) {
					throw new RuntimeException( 'Composer audit serialization row is unavailable. Reactivate the plugin to restore its database installation.' );
				}
				$existing = $wpdb->get_var( $wpdb->prepare( "SELECT event_uuid FROM {$table} WHERE event_uuid = %s LIMIT 1 FOR UPDATE", $event_uuid ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Locking audit rows cannot use the object cache.
				$this->assert_database_read( 'read the audit event identity' );
				if ( $event_uuid === $existing ) {
					if ( $manage_transaction ) {
						$this->commit();
					}
					return $event_uuid;
				}
				$previous_hash = (string) $wpdb->get_var( "SELECT event_hash FROM {$table} ORDER BY id DESC LIMIT 1 FOR UPDATE" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The hash-chain tail must be locked and must not be cached.
				$this->assert_database_read( 'read the audit chain tail' );
				$hash_payload  = array(
					'event_uuid'    => $event_uuid,
					'created_gmt'   => $created_gmt,
					'request_id'    => $request_id,
					'actor_user_id' => $actor_user_id,
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
						'actor_user_id' => $actor_user_id,
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
					throw $this->database_exception( 'append the audit event' );
				}
				if ( $manage_transaction ) {
					$this->commit();
				}
				return $event_uuid;
			} catch ( \Throwable $error ) {
				if ( ! $manage_transaction ) {
					// A deadlock may have rolled back the entire caller operation.
					// Never retry just its audit suffix or commit caller-owned work.
					throw $error;
				}
				$rolled_back = ! $started || false !== $wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Explicitly cleans up owned deadlocks and lock timeouts.
				if ( ! $rolled_back || $attempt >= self::MAX_APPEND_ATTEMPTS || ! in_array( $error->getCode(), array( 1213, 1205 ), true ) ) {
					throw $error;
				}
				usleep( random_int( 10000, 30000 ) * $attempt );
			}
		}
	}

	private function assert_database_read( string $operation ): void {
		global $wpdb;
		if ( '' !== (string) $wpdb->last_error ) {
			throw $this->database_exception( $operation ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal operation label, never an HTML sink; controllers return a generic JSON failure.
		}
	}

	private function database_exception( string $operation ): RuntimeException {
		global $wpdb;
		$error = (string) $wpdb->last_error;
		$code = str_contains( $error, 'Deadlock found when trying to get lock' ) ? 1213 : ( str_contains( $error, 'Lock wait timeout exceeded' ) ? 1205 : 0 );
		return new RuntimeException( 'Composer could not ' . $operation . '.', $code );
	}

	private function commit(): void {
		global $wpdb;
		if ( false === $wpdb->query( 'COMMIT' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Commits only the owned audit transaction.
			// A failed commit has an ambiguous outcome; never replay it locally.
			throw new RuntimeException( 'Composer could not commit the audit transaction.' );
		}
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
