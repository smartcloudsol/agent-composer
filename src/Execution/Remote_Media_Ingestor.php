<?php
/* SmartCloud Agent Composer governed remote Media Library ingestion. */

namespace SmartCloud\AgentComposer\Execution;

final class Remote_Media_Ingestor {
	private const SOURCE_URL_HASH_META = '_smartcloud_composer_source_url_hash';
	private const SOURCE_SHA256_META   = '_smartcloud_composer_source_sha256';
	private const IDEMPOTENCY_META     = '_smartcloud_composer_media_idempotency_key';

	public function __construct(
		private readonly Config_Repository $config,
		private readonly Draft_Service $drafts
	) {}

	public function ingest( array $input ): array {
		if ( ! current_user_can( \SmartCloud\AgentComposer\Infrastructure\WordPress\Activation::CAP_INGEST_MEDIA ) ) {
			throw new Execution_Exception( 'media_upload_denied', 'The authenticated WordPress user cannot upload media.' );
		}

		$policy = $this->config->get_remote_media_ingest_policy();
		if ( empty( $policy['enabled'] ) ) {
			throw new Execution_Exception( 'remote_media_ingest_disabled', 'The active Site Contract has not enabled remote media ingestion.' );
		}

		$source_url      = $this->source_url( (string) ( $input['source_url'] ?? '' ), $policy['allowed_hosts'] );
		$idempotency_key = $this->idempotency_key( (string) ( $input['idempotency_key'] ?? '' ) );
		$source_url_hash = hash( 'sha256', $source_url );
		$existing        = $this->find_attachment( self::IDEMPOTENCY_META, hash( 'sha256', $idempotency_key ) );
		if ( $existing > 0 ) {
			$existing_source_hashes = array_map( 'strval', (array) get_post_meta( $existing, self::SOURCE_URL_HASH_META, false ) );
			$source_matches         = array_filter(
				$existing_source_hashes,
				static fn( string $hash ): bool => hash_equals( $source_url_hash, $hash )
			);
			if ( empty( $source_matches ) ) {
				throw new Execution_Exception( 'media_idempotency_conflict', 'The media idempotency key is already bound to another source URL.' );
			}
			return $this->result( $existing, false, $input );
		}

		$filename = $this->filename( $source_url );
		$tmp_name = wp_tempnam( $filename );
		if ( ! is_string( $tmp_name ) || '' === $tmp_name ) {
			throw new Execution_Exception( 'media_temp_file_failed', 'WordPress could not allocate a temporary media file.' );
		}

		try {
			$response = wp_safe_remote_get(
				$source_url,
				array(
					'timeout'             => 45,
					'redirection'         => 0,
					'reject_unsafe_urls'  => true,
					'stream'              => true,
					'filename'            => $tmp_name,
					'limit_response_size' => (int) $policy['max_bytes'] + 1,
					'user-agent'          => 'SmartCloud Agent Composer/' . SMARTCLOUD_COMPOSER_VERSION,
				)
			);
			if ( is_wp_error( $response ) ) {
				throw new Execution_Exception( 'media_download_failed', 'WordPress could not download the remote media file.' );
			}
			$status = (int) wp_remote_retrieve_response_code( $response );
			if ( $status < 200 || $status >= 300 ) {
				throw new Execution_Exception( 'media_download_http_error', 'The remote media server did not return a successful response.' );
			}
			$bytes = filesize( $tmp_name );
			if ( false === $bytes || $bytes < 1 || $bytes > (int) $policy['max_bytes'] ) {
				throw new Execution_Exception( 'media_file_size_invalid', 'The remote media file is empty or exceeds the Site Contract size limit.' );
			}

			$file_check = wp_check_filetype_and_ext( $tmp_name, $filename );
			$mime_type  = sanitize_mime_type( (string) ( $file_check['type'] ?? '' ) );
			if ( '' === $mime_type || ! in_array( $mime_type, $policy['allowed_mime_types'], true ) ) {
				throw new Execution_Exception( 'media_mime_type_denied', 'The downloaded file is not an allowed image type.' );
			}

			$source_sha256 = hash_file( 'sha256', $tmp_name );
			if ( ! is_string( $source_sha256 ) || '' === $source_sha256 ) {
				throw new Execution_Exception( 'media_hash_failed', 'WordPress could not fingerprint the downloaded media file.' );
			}
			$duplicate = $this->find_attachment( self::SOURCE_SHA256_META, $source_sha256 );
			if ( $duplicate > 0 ) {
				add_post_meta( $duplicate, self::IDEMPOTENCY_META, hash( 'sha256', $idempotency_key ), false );
				add_post_meta( $duplicate, self::SOURCE_URL_HASH_META, $source_url_hash, false );
				return $this->result( $duplicate, false, $input );
			}

			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
			$title   = sanitize_text_field( (string) ( $input['title'] ?? pathinfo( $filename, PATHINFO_FILENAME ) ) );
			$caption = sanitize_textarea_field( (string) ( $input['caption'] ?? '' ) );
			$file    = array( 'name' => $filename, 'tmp_name' => $tmp_name );
			$post    = array(
				'post_title'   => '' !== $title ? $title : pathinfo( $filename, PATHINFO_FILENAME ),
				'post_excerpt' => $caption,
			);
			$attachment_id = media_handle_sideload( $file, 0, '', $post );
			if ( is_wp_error( $attachment_id ) ) {
				throw new Execution_Exception( 'media_library_insert_failed', 'WordPress rejected the downloaded media file.' );
			}
			$tmp_name = '';
			$attachment_id = absint( $attachment_id );
			if ( ! wp_attachment_is_image( $attachment_id ) ) {
				wp_delete_attachment( $attachment_id, true );
				throw new Execution_Exception( 'media_image_validation_failed', 'The imported attachment is not a WordPress image.' );
			}
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( (string) ( $input['alt'] ?? '' ) ) );
			add_post_meta( $attachment_id, self::SOURCE_URL_HASH_META, $source_url_hash, false );
			update_post_meta( $attachment_id, self::SOURCE_SHA256_META, $source_sha256 );
			add_post_meta( $attachment_id, self::IDEMPOTENCY_META, hash( 'sha256', $idempotency_key ), false );
			return $this->result( $attachment_id, true, $input );
		} finally {
			if ( '' !== $tmp_name && is_file( $tmp_name ) ) {
				wp_delete_file( $tmp_name );
			}
		}
	}

	private function result( int $attachment_id, bool $created, array $input ): array {
		$assignment = null;
		if ( isset( $input['featured_for'] ) && is_array( $input['featured_for'] ) ) {
			$assignment = $this->drafts->assign_featured_image( $input['featured_for'], $attachment_id );
		}
		$metadata = wp_get_attachment_metadata( $attachment_id );
		return array(
			'attachment_id' => $attachment_id,
			'created'       => $created,
			'mime_type'     => (string) get_post_mime_type( $attachment_id ),
			'url'           => (string) ( wp_get_attachment_url( $attachment_id ) ?: '' ),
			'width'         => is_array( $metadata ) ? absint( $metadata['width'] ?? 0 ) : 0,
			'height'        => is_array( $metadata ) ? absint( $metadata['height'] ?? 0 ) : 0,
			'featured_for'  => $assignment,
		);
	}

	private function find_attachment( string $meta_key, string $meta_value ): int {
		$query = new \WP_Query(
			array(
				'post_type'              => 'attachment',
				'post_status'            => 'inherit',
				'post_mime_type'         => 'image',
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_query'             => array(
					array( 'key' => $meta_key, 'value' => $meta_value, 'compare' => '=' ),
				),
			)
		);
		$id = absint( $query->posts[0] ?? 0 );
		return $id > 0 && current_user_can( 'read_post', $id ) ? $id : 0;
	}

	private function source_url( string $url, array $allowed_hosts ): string {
		$url   = esc_url_raw( trim( $url ), array( 'https' ) );
		$parts = wp_parse_url( $url );
		$host  = is_array( $parts ) ? strtolower( (string) ( $parts['host'] ?? '' ) ) : '';
		if (
			'' === $url
			|| ! wp_http_validate_url( $url )
			|| ! is_array( $parts )
			|| 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) )
			|| isset( $parts['user'] )
			|| isset( $parts['pass'] )
			|| isset( $parts['fragment'] )
			|| isset( $parts['port'] )
			|| ! in_array( $host, $allowed_hosts, true )
		) {
			throw new Execution_Exception( 'media_source_url_denied', 'The media source must be an HTTPS URL on a Site Contract-approved host.' );
		}
		return $url;
	}

	private function idempotency_key( string $key ): string {
		$key = trim( $key );
		if ( ! preg_match( '/^[A-Za-z0-9._:-]{8,128}$/', $key ) ) {
			throw new Execution_Exception( 'invalid_media_idempotency_key', 'A stable media idempotency key is required.' );
		}
		return $key;
	}

	private function filename( string $source_url ): string {
		$path     = (string) ( wp_parse_url( $source_url, PHP_URL_PATH ) ?? '' );
		$filename = sanitize_file_name( rawurldecode( basename( $path ) ) );
		if ( '' === $filename || ! str_contains( $filename, '.' ) ) {
			throw new Execution_Exception( 'media_filename_invalid', 'The media source URL must contain a safe filename extension.' );
		}
		return $filename;
	}
}
