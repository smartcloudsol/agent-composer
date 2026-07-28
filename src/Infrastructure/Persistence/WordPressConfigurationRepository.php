<?php

namespace SmartCloud\AgentComposer\Infrastructure\Persistence;

use InvalidArgumentException;
use RuntimeException;
use SmartCloud\AgentComposer\Domain\Configuration\CanonicalJson;
use SmartCloud\AgentComposer\Domain\Configuration\ConfigurationConflict;
use SmartCloud\AgentComposer\Domain\Configuration\EntityType;
use SmartCloud\AgentComposer\Infrastructure\WordPress\EntityPostType;

final class WordPressConfigurationRepository {
	private const META_PREFIX = '_smartcloud_composer_';
	private const LEGACY_META = array(
		'page_type' => '_wpsuite_agent_page_type',
		'agent_id'  => '_wpsuite_agent_id',
	);

	public function save_working_entity( string $type, string $key, array $payload, string $config_set ): int {
		EntityType::assert( $type );
		$key        = $this->assert_key( $key );
		$config_set = $this->assert_config_set( $config_set );
		if ( $this->find_entity( $config_set, $type, $key ) instanceof \WP_Post ) {
			throw new InvalidArgumentException( 'The configuration entity already exists in this working set.' );
		}

		$canonical = CanonicalJson::encode( $payload );
		$post_id   = wp_insert_post(
			wp_slash(
				array(
					'post_type'    => EntityPostType::POST_TYPE,
					'post_status'  => 'draft',
					'post_name'    => sanitize_title( $key ),
					'post_title'   => sanitize_text_field( $payload['label'] ?? $key ),
					'post_content' => $canonical,
				)
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			// The sanitized message is serialized by REST error handling; it is not printed as HTML here.
			throw new RuntimeException( sanitize_text_field( $post_id->get_error_message() ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}

		$checksum = CanonicalJson::checksum( $payload );
		update_post_meta( $post_id, self::META_PREFIX . 'entity_type', $type );
		update_post_meta( $post_id, self::META_PREFIX . 'entity_key', $key );
		update_post_meta( $post_id, self::META_PREFIX . 'config_set', $config_set );
		update_post_meta( $post_id, self::META_PREFIX . 'schema_version', '1.0.0-rc.1' );
		update_post_meta( $post_id, self::META_PREFIX . 'checksum', $checksum );
		update_post_meta( $post_id, self::META_PREFIX . 'entity_revision', 1 );
		update_post_meta( $post_id, self::META_PREFIX . 'modified_by', get_current_user_id() );
		update_post_meta( $post_id, self::META_PREFIX . 'active', '0' );
		if ( EntityType::CONFIG_SET === $type ) {
			update_post_meta( $post_id, self::META_PREFIX . 'lifecycle', 'working' );
		}
		return (int) $post_id;
	}

	public function update_working_entity( int $post_id, array $payload, int $expected_revision, string $expected_checksum, bool $invalidate = true ): array {
		$post    = $this->get_post( $post_id );
		$current = $this->assert_working_entity_version( $post, $expected_revision, $expected_checksum, $payload );
		$set     = (string) $current['config_set'];

		$updated = wp_update_post(
			wp_slash(
				array(
					'ID'           => $post_id,
					'post_title'   => sanitize_text_field( $payload['label'] ?? $current['key'] ),
					'post_content' => CanonicalJson::encode( $payload ),
				)
			),
			true
		);
		if ( is_wp_error( $updated ) ) {
			// The sanitized message is serialized by REST error handling; it is not printed as HTML here.
			throw new RuntimeException( sanitize_text_field( $updated->get_error_message() ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
		}
		update_post_meta( $post_id, self::META_PREFIX . 'checksum', CanonicalJson::checksum( $payload ) );
		update_post_meta( $post_id, self::META_PREFIX . 'entity_revision', $current['entity_revision'] + 1 );
		update_post_meta( $post_id, self::META_PREFIX . 'modified_by', get_current_user_id() );
		if ( $invalidate ) {
			$this->invalidate_validation( $set );
		}
		return $this->describe_entity( $this->get_post( $post_id ) );
	}

	public function assert_working_entity_version( \WP_Post $post, int $expected_revision, string $expected_checksum, ?array $submitted = null ): array {
		$current = $this->describe_entity( $this->get_post( $post->ID ) );
		$set     = (string) $current['config_set'];
		if ( $set === (string) get_option( 'smartcloud_composer_active_config_set', '' ) || $current['active'] ) {
			throw new InvalidArgumentException( 'Active configuration is immutable. Clone it before editing.' );
		}
		$expected_checksum = trim( $expected_checksum, "\" \t\n\r\0\x0B" );
		if ( $expected_revision !== $current['entity_revision'] || $expected_checksum !== $current['content_hash'] ) {
			$details = array(
				'code'             => 'SMARTCLOUD_COMPOSER_CONFLICT',
				'current_revision' => (int) $current['entity_revision'],
				'current_hash'     => sanitize_text_field( (string) $current['content_hash'] ),
				'base_to_current'  => array( 'changed' => true, 'current' => $current['payload'] ),
			);
			if ( null !== $submitted ) {
				$details['base_to_submitted'] = array( 'changed' => true, 'submitted' => $submitted );
			}
			throw new ConfigurationConflict( $details );
		}
		return $current;
	}

	public function lock_working_entity( int $post_id ): \WP_Post {
		global $wpdb;
		$locked = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- A configuration changeset must lock its entity rows until commit.
			$wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID = %d AND post_type = %s FOR UPDATE", $post_id, EntityPostType::POST_TYPE ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Core table name is supplied by wpdb.
		);
		if ( $post_id !== absint( $locked ) ) {
			throw new InvalidArgumentException( 'The changeset target no longer exists.' );
		}
		clean_post_cache( $post_id );
		return $this->get_post( $post_id );
	}

	public function delete_working_entity( int $post_id, int $expected_revision, string $expected_checksum, bool $invalidate = true ): array {
		$post    = $this->get_post( $post_id );
		$current = $this->assert_working_entity_version( $post, $expected_revision, $expected_checksum );
		$set     = (string) $current['config_set'];
		if ( EntityType::CONFIG_SET === $current['type'] ) {
			throw new InvalidArgumentException( 'Delete a complete Config Set through its lifecycle controls, not as an entity.' );
		}

		if ( ! wp_delete_post( $post_id, true ) instanceof \WP_Post ) {
			throw new RuntimeException( 'Composer could not delete the configuration entity.' );
		}
		if ( $invalidate ) {
			$this->invalidate_validation( $set );
		}
		return array(
			'config_set'   => $set,
			'entity_type' => (string) $current['type'],
			'entity_key'  => (string) $current['key'],
			'content_hash' => (string) $current['content_hash'],
		);
	}

	public function find_by_type( string $type, ?string $config_set = null ): array {
		EntityType::assert( $type );
		$meta_query = array(
			array( 'key' => self::META_PREFIX . 'entity_type', 'value' => $type ),
		);
		if ( null !== $config_set ) {
			$meta_query[] = array( 'key' => self::META_PREFIX . 'config_set', 'value' => $this->assert_config_set( $config_set ) );
		}
		return get_posts(
			array(
				'post_type'      => EntityPostType::POST_TYPE,
				'post_status'    => array( 'draft', 'private' ),
				'posts_per_page' => 500,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'meta_query'     => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Typed configuration entities are intentionally indexed and queried by private post meta.
			)
		);
	}

	public function list_config_sets(): array {
		$sets = array_map( array( $this, 'describe_entity' ), $this->find_by_type( EntityType::CONFIG_SET ) );
		foreach ( $sets as &$set ) {
			$entities              = $this->entities( $set['config_set'] );
			$set['lifecycle']   = $this->lifecycle( $set['config_set'] );
			$set['entity_count'] = count( $entities );
			$set['config_hash'] = $this->configuration_hash( $set['config_set'] );
			$set['modified_gmt'] = $this->aggregate_modified_gmt( $entities );
		}
		unset( $set );
		usort( $sets, static fn( array $a, array $b ): int => strcmp( $b['modified_gmt'], $a['modified_gmt'] ) );
		return $sets;
	}

	public function describe_config_set( string $config_set ): array {
		$post = $this->find_entity( $config_set, EntityType::CONFIG_SET, $config_set );
		if ( ! $post instanceof \WP_Post ) {
			$posts = $this->find_by_type( EntityType::CONFIG_SET, $config_set );
			$post  = $posts[0] ?? null;
		}
		if ( ! $post instanceof \WP_Post ) {
			throw new InvalidArgumentException( 'The requested configuration set does not exist.' );
		}
		$entities            = $this->entities( $config_set );
		$set                 = $this->describe_entity( $post );
		$set['lifecycle']    = $this->lifecycle( $config_set );
		$set['config_hash']  = $this->configuration_hash( $config_set );
		$set['entities']     = array_map( array( $this, 'describe_entity' ), $entities );
		$set['modified_gmt'] = $this->aggregate_modified_gmt( $entities );
		return $set;
	}

	public function entities( string $config_set ): array {
		$config_set = $this->assert_config_set( $config_set );
		$posts      = array();
		foreach ( EntityType::all() as $type ) {
			$posts = array_merge( $posts, $this->find_by_type( $type, $config_set ) );
		}
		usort(
			$posts,
			fn( \WP_Post $a, \WP_Post $b ): int => strcmp(
				$this->entity_type( $a ) . ':' . $this->entity_key( $a ),
				$this->entity_type( $b ) . ':' . $this->entity_key( $b )
			)
		);
		return $posts;
	}

	public function find_entity( string $config_set, string $type, string $key ): ?\WP_Post {
		$key = $this->assert_key( $key );
		foreach ( $this->find_by_type( $type, $config_set ) as $post ) {
			if ( $key === $this->entity_key( $post ) ) {
				return $post;
			}
		}
		return null;
	}

	public function describe_entity( \WP_Post $post ): array {
		$payload  = json_decode( (string) $post->post_content, true );
		$payload  = is_array( $payload ) ? $payload : array();
		$checksum = (string) get_post_meta( $post->ID, self::META_PREFIX . 'checksum', true );
		if ( '' === $checksum ) {
			$checksum = CanonicalJson::checksum( $payload );
		}
		return array(
			'id'              => (int) $post->ID,
			'key'             => $this->entity_key( $post ),
			'type'            => $this->entity_type( $post ),
			'config_set'      => (string) get_post_meta( $post->ID, self::META_PREFIX . 'config_set', true ),
			'label'           => get_the_title( $post ),
			'payload'         => $payload,
			'entity_revision' => max( 1, absint( get_post_meta( $post->ID, self::META_PREFIX . 'entity_revision', true ) ) ),
			'modified_gmt'    => mysql_to_rfc3339( $post->post_modified_gmt ),
			'modified_by'     => absint( get_post_meta( $post->ID, self::META_PREFIX . 'modified_by', true ) ),
			'content_hash'    => $checksum,
			'active'          => '1' === (string) get_post_meta( $post->ID, self::META_PREFIX . 'active', true ),
		);
	}

	public function configuration_hash( string $config_set ): string {
		$entities = array_map(
			fn( \WP_Post $post ): array => array(
				'type'     => $this->entity_type( $post ),
				'key'      => $this->entity_key( $post ),
				'checksum' => (string) $this->describe_entity( $post )['content_hash'],
			),
			$this->entities( $config_set )
		);
		return CanonicalJson::checksum( $entities );
	}

	private function aggregate_modified_gmt( array $posts ): string {
		$latest = '';
		foreach ( $posts as $post ) {
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}
			$modified = trim( (string) $post->post_modified_gmt );
			if ( ! preg_match( '/^[1-9][0-9]{3}-(?:0[1-9]|1[0-2])-(?:0[1-9]|[12][0-9]|3[01]) (?:[01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9]$/', $modified ) ) {
				continue;
			}
			if ( '' === $latest || strcmp( $modified, $latest ) > 0 ) {
				$latest = $modified;
			}
		}
		return '' === $latest ? '' : mysql_to_rfc3339( $latest );
	}

	public function set_lifecycle( string $config_set, string $lifecycle ): void {
		if ( ! in_array( $lifecycle, array( 'working', 'invalid', 'valid', 'active', 'archived' ), true ) ) {
			throw new InvalidArgumentException( 'Invalid configuration lifecycle state.' );
		}
		$set = $this->find_by_type( EntityType::CONFIG_SET, $config_set )[0] ?? null;
		if ( $set instanceof \WP_Post ) {
			update_post_meta( $set->ID, self::META_PREFIX . 'lifecycle', $lifecycle );
		}
	}

	public function set_active( string $config_set, bool $active ): void {
		foreach ( $this->entities( $config_set ) as $entity ) {
			update_post_meta( $entity->ID, self::META_PREFIX . 'active', $active ? '1' : '0' );
			if ( $active && 'draft' === $entity->post_status ) {
				wp_update_post( array( 'ID' => $entity->ID, 'post_status' => 'private' ) );
			}
		}
	}

	public function invalidate_validation( string $config_set ): void {
		$set = $this->find_by_type( EntityType::CONFIG_SET, $config_set )[0] ?? null;
		if ( $set instanceof \WP_Post ) {
			delete_post_meta( $set->ID, self::META_PREFIX . 'validation_receipt' );
			delete_post_meta( $set->ID, self::META_PREFIX . 'validation_checksum' );
			delete_post_meta( $set->ID, self::META_PREFIX . 'validation_expires_gmt' );
			$this->set_lifecycle( $config_set, 'working' );
		}
	}

	public function legacy_meta( int $post_id, string $key ): mixed {
		$current = get_post_meta( $post_id, self::META_PREFIX . $key, true );
		if ( '' !== $current || ! isset( self::LEGACY_META[ $key ] ) ) {
			return $current;
		}
		return get_post_meta( $post_id, self::LEGACY_META[ $key ], true );
	}

	private function lifecycle( string $config_set ): string {
		if ( $config_set === (string) get_option( 'smartcloud_composer_active_config_set', '' ) ) {
			return 'active';
		}
		$set = $this->find_by_type( EntityType::CONFIG_SET, $config_set )[0] ?? null;
		return $set instanceof \WP_Post
			? (string) ( get_post_meta( $set->ID, self::META_PREFIX . 'lifecycle', true ) ?: 'working' )
			: 'working';
	}

	private function get_post( int $post_id ): \WP_Post {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || EntityPostType::POST_TYPE !== $post->post_type ) {
			throw new InvalidArgumentException( 'The requested configuration entity does not exist.' );
		}
		return $post;
	}

	private function entity_key( \WP_Post $post ): string {
		return (string) ( get_post_meta( $post->ID, self::META_PREFIX . 'entity_key', true ) ?: $post->post_name );
	}

	private function entity_type( \WP_Post $post ): string {
		return (string) get_post_meta( $post->ID, self::META_PREFIX . 'entity_type', true );
	}

	private function assert_key( string $key ): string {
		$key = strtolower( trim( $key ) );
		if ( ! preg_match( '/^[a-z0-9][a-z0-9._:-]{0,127}$/', $key ) ) {
			throw new InvalidArgumentException( 'Invalid configuration entity key.' );
		}
		return $key;
	}

	private function assert_config_set( string $config_set ): string {
		$config_set = sanitize_key( $config_set );
		if ( '' === $config_set || strlen( $config_set ) > 128 ) {
			throw new InvalidArgumentException( 'Invalid configuration set identifier.' );
		}
		return $config_set;
	}
}
