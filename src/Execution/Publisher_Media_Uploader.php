<?php
/* Publisher-only governed Media Library publication over MCP. */

namespace SmartCloud\AgentComposer\Execution;

use SmartCloud\AgentComposer\Infrastructure\WordPress\Activation;
use SmartCloud\AgentComposer\Security\ActorIdentity;

final class Publisher_Media_Uploader {
	public const MAX_DIRECT_BYTES = 12582912;
	private const MAX_PIXELS = 64000000;
	private const IDEMPOTENCY_META = '_smartcloud_composer_publisher_media_idempotency';
	private const REQUEST_HASH_META = '_smartcloud_composer_publisher_media_request_hash';
	private const CONTENT_HASH_META = '_smartcloud_composer_publisher_media_sha256';
	private const PRINCIPAL_META = '_smartcloud_composer_publisher_media_principal';
	private const LOCK_PREFIX = '_smartcloud_composer_publisher_media_lock_';
	private const MIME_EXTENSIONS = array(
		'image/jpeg' => 'jpg',
		'image/png'  => 'png',
		'image/webp' => 'webp',
		'image/avif' => 'avif',
		'image/gif'  => 'gif',
	);

	public function __construct( private readonly Config_Repository $config ) {}

	public function upload( array $input ): array {
		$actor = ActorIdentity::context();
		if ( null === $actor || 'publisher' !== $actor->role() || 'cognito' !== $actor->source() ) {
			throw new Execution_Exception( 'publisher_media_upload_denied', 'Only a protected Publisher actor may publish a new Media Library asset.' );
		}
		if ( ! current_user_can( Activation::CAP_PUBLISH_MEDIA ) ) {
			throw new Execution_Exception( 'publisher_media_upload_denied', 'The authenticated Publisher cannot publish Media Library assets.' );
		}
		if ( true !== ( $input['confirm_publication'] ?? false ) ) {
			throw new Execution_Exception( 'publisher_media_confirmation_required', 'Confirm that this file may become a publicly addressable Media Library asset.' );
		}
		if ( true !== ( $input['confirm_rights'] ?? false ) ) {
			throw new Execution_Exception( 'publisher_media_rights_confirmation_required', 'Confirm that the site may store and publish this media asset.' );
		}

		$policy = $this->config->get_remote_media_ingest_policy();
		if ( empty( $policy['publisher_upload_enabled'] ) ) {
			throw new Execution_Exception( 'publisher_media_upload_disabled', 'The active Site Contract has not enabled Publisher Media Library uploads.' );
		}
		$mime_type = sanitize_mime_type( (string) ( $input['mime_type'] ?? '' ) );
		if ( ! isset( self::MIME_EXTENSIONS[ $mime_type ] ) || ! in_array( $mime_type, (array) $policy['allowed_mime_types'], true ) ) {
			throw new Execution_Exception( 'publisher_media_mime_type_denied', 'The declared image MIME type is not allowed by the active Site Contract.' );
		}

		$slug = $this->slug( (string) ( $input['slug'] ?? '' ) );
		$title = sanitize_text_field( (string) ( $input['title'] ?? '' ) );
		if ( '' === $title || strlen( $title ) > 200 ) {
			throw new Execution_Exception( 'publisher_media_title_invalid', 'A concise Media Library title is required.' );
		}
		$alt_mode = sanitize_key( (string) ( $input['alt_mode'] ?? '' ) );
		$alt_text = sanitize_text_field( (string) ( $input['alt_text'] ?? '' ) );
		if ( 'descriptive' === $alt_mode && '' === $alt_text ) {
			throw new Execution_Exception( 'publisher_media_alt_text_required', 'A descriptive image requires meaningful alternative text.' );
		}
		if ( 'decorative' === $alt_mode && '' !== $alt_text ) {
			throw new Execution_Exception( 'publisher_media_decorative_alt_invalid', 'A decorative image must use empty alternative text.' );
		}
		if ( ! in_array( $alt_mode, array( 'descriptive', 'decorative' ), true ) || strlen( $alt_text ) > 500 ) {
			throw new Execution_Exception( 'publisher_media_alt_invalid', 'Choose descriptive or decorative alternative-text handling.' );
		}
		$caption = sanitize_textarea_field( (string) ( $input['caption'] ?? '' ) );
		$description = sanitize_textarea_field( (string) ( $input['description'] ?? '' ) );
		if ( strlen( $caption ) > 1000 || strlen( $description ) > 4000 ) {
			throw new Execution_Exception( 'publisher_media_metadata_too_long', 'The media caption or description exceeds its allowed length.' );
		}

		$idempotency_key = $this->idempotency_key( (string) ( $input['idempotency_key'] ?? '' ) );
		$source = $this->source( $input );
		$request_hash = hash(
			'sha256',
			implode( "\0", array( $source['fingerprint'], $mime_type, $slug, $title, $alt_mode, $alt_text, $caption, $description ) )
		);
		$idempotency_hash = hash( 'sha256', $actor->principal_id() . "\0" . $idempotency_key );
		$lock = $this->acquire_lock( $idempotency_hash );
		try {
		$existing = $this->find_attachment( $idempotency_hash );
		if ( $existing > 0 ) {
			$stored_hash = (string) get_post_meta( $existing, self::REQUEST_HASH_META, true );
			if ( '' === $stored_hash || ! hash_equals( $stored_hash, $request_hash ) ) {
				throw new Execution_Exception( 'publisher_media_idempotency_conflict', 'The media idempotency key is already bound to a different upload request.' );
			}
			return $this->result( $existing, false, $source['kind'] );
		}

		$max_bytes = min( self::MAX_DIRECT_BYTES, max( 1, (int) ( $policy['max_bytes'] ?? self::MAX_DIRECT_BYTES ) ) );
		$filename = $slug . '.' . self::MIME_EXTENSIONS[ $mime_type ];
		$tmp_name = wp_tempnam( $filename );
		if ( ! is_string( $tmp_name ) || '' === $tmp_name ) {
			throw new Execution_Exception( 'publisher_media_temp_file_failed', 'WordPress could not allocate a temporary media file.' );
		}

		try {
			$this->write_source( $source, $tmp_name, $max_bytes );
			$bytes = filesize( $tmp_name );
			if ( false === $bytes || $bytes < 1 || $bytes > $max_bytes ) {
				throw new Execution_Exception( 'publisher_media_file_size_invalid', 'The media file is empty or exceeds the governed upload limit.' );
			}
			$file_check = wp_check_filetype_and_ext( $tmp_name, $filename );
			$detected_mime = sanitize_mime_type( (string) ( $file_check['type'] ?? '' ) );
			if ( '' === $detected_mime || ! hash_equals( $mime_type, $detected_mime ) ) {
				throw new Execution_Exception( 'publisher_media_signature_mismatch', 'The image bytes do not match the declared MIME type and SEO filename extension.' );
			}
			$image_size = wp_getimagesize( $tmp_name );
			$width = is_array( $image_size ) ? absint( $image_size[0] ?? 0 ) : 0;
			$height = is_array( $image_size ) ? absint( $image_size[1] ?? 0 ) : 0;
			if ( $width < 1 || $height < 1 || $width > 12000 || $height > 12000 || $width * $height > self::MAX_PIXELS ) {
				throw new Execution_Exception( 'publisher_media_dimensions_invalid', 'The uploaded image dimensions are invalid or exceed the safe processing limit.' );
			}
			$content_hash = hash_file( 'sha256', $tmp_name );
			if ( ! is_string( $content_hash ) || '' === $content_hash ) {
				throw new Execution_Exception( 'publisher_media_hash_failed', 'WordPress could not fingerprint the uploaded media file.' );
			}

			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
			$file = array( 'name' => $filename, 'tmp_name' => $tmp_name );
			$post = array(
				'post_name'    => $slug,
				'post_title'   => $title,
				'post_excerpt' => $caption,
				'post_content' => $description,
			);
			$attachment_id = media_handle_sideload( $file, 0, '', $post );
			if ( is_wp_error( $attachment_id ) ) {
				throw new Execution_Exception( 'publisher_media_library_insert_failed', 'WordPress rejected the uploaded media file.' );
			}
			$tmp_name = '';
			$attachment_id = absint( $attachment_id );
			if ( ! wp_attachment_is_image( $attachment_id ) ) {
				wp_delete_attachment( $attachment_id, true );
				throw new Execution_Exception( 'publisher_media_image_validation_failed', 'The new attachment is not a valid WordPress image.' );
			}
			$updated = wp_update_post(
				array(
					'ID'           => $attachment_id,
					'post_name'    => $slug,
					'post_title'   => $title,
					'post_excerpt' => $caption,
					'post_content' => $description,
				),
				true
			);
			if ( is_wp_error( $updated ) ) {
				wp_delete_attachment( $attachment_id, true );
				throw new Execution_Exception( 'publisher_media_metadata_failed', 'WordPress could not save the governed attachment metadata.' );
			}
			$metadata_saved = array(
				update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt_text ),
				update_post_meta( $attachment_id, self::IDEMPOTENCY_META, $idempotency_hash ),
				update_post_meta( $attachment_id, self::REQUEST_HASH_META, $request_hash ),
				update_post_meta( $attachment_id, self::CONTENT_HASH_META, $content_hash ),
				update_post_meta( $attachment_id, self::PRINCIPAL_META, $actor->principal_id() ),
			);
			if ( in_array( false, $metadata_saved, true ) ) {
				wp_delete_attachment( $attachment_id, true );
				throw new Execution_Exception( 'publisher_media_metadata_failed', 'WordPress could not save the governed attachment metadata.' );
			}
			return $this->result( $attachment_id, true, $source['kind'] );
		} finally {
			if ( '' !== $tmp_name && is_file( $tmp_name ) ) {
				wp_delete_file( $tmp_name );
			}
		}
		} finally {
			$this->release_lock( $lock );
		}
	}

	/** @return array{kind:string,fingerprint:string,content?:string,url?:string} */
	private function source( array $input ): array {
		$content = trim( (string) ( $input['content_base64'] ?? '' ) );
		$url = trim( (string) ( $input['source_url'] ?? '' ) );
		if ( ( '' === $content ) === ( '' === $url ) ) {
			throw new Execution_Exception( 'publisher_media_source_invalid', 'Supply exactly one of content_base64 or source_url.' );
		}
		if ( '' !== $content ) {
			if ( strlen( $content ) > 4 * (int) ceil( self::MAX_DIRECT_BYTES / 3 ) ) {
				throw new Execution_Exception( 'publisher_media_payload_too_large', 'The base64 media payload exceeds the direct MCP upload limit.' );
			}
			return array( 'kind' => 'base64', 'fingerprint' => 'base64:' . hash( 'sha256', $content ), 'content' => $content );
		}
		$validated_url = $this->source_url( $url );
		return array( 'kind' => 'remote', 'fingerprint' => 'url:' . hash( 'sha256', $validated_url ), 'url' => $validated_url );
	}

	private function write_source( array $source, string $tmp_name, int $max_bytes ): void {
		if ( 'base64' === $source['kind'] ) {
			$bytes = base64_decode( (string) $source['content'], true );
			if ( false === $bytes || '' === $bytes || strlen( $bytes ) > $max_bytes ) {
				throw new Execution_Exception( 'publisher_media_base64_invalid', 'The media payload is not valid bounded base64 data.' );
			}
			if ( strlen( $bytes ) !== file_put_contents( $tmp_name, $bytes, LOCK_EX ) ) {
				throw new Execution_Exception( 'publisher_media_temp_file_failed', 'WordPress could not write the temporary media file.' );
			}
			return;
		}

		$response = wp_safe_remote_get(
			(string) $source['url'],
			array(
				'timeout'             => 45,
				'redirection'         => 0,
				'reject_unsafe_urls'  => true,
				'stream'              => true,
				'filename'            => $tmp_name,
				'limit_response_size' => $max_bytes + 1,
				'user-agent'          => 'SmartCloud Agent Composer/' . SMARTCLOUD_COMPOSER_VERSION,
			)
		);
		if ( is_wp_error( $response ) ) {
			throw new Execution_Exception( 'publisher_media_download_failed', 'WordPress could not download the Publisher-provided media source.' );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( $status < 200 || $status >= 300 ) {
			throw new Execution_Exception( 'publisher_media_download_http_error', 'The Publisher-provided media server did not return a successful response.' );
		}
	}

	private function source_url( string $url ): string {
		$url = esc_url_raw( $url, array( 'https' ) );
		$parts = wp_parse_url( $url );
		if (
			'' === $url
			|| strlen( $url ) > 4096
			|| ! wp_http_validate_url( $url )
			|| ! is_array( $parts )
			|| 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) )
			|| '' === (string) ( $parts['host'] ?? '' )
			|| isset( $parts['user'] )
			|| isset( $parts['pass'] )
			|| isset( $parts['fragment'] )
			|| isset( $parts['port'] )
		) {
			throw new Execution_Exception( 'publisher_media_source_url_denied', 'The Publisher media source must be a safe public HTTPS URL without credentials, fragments, or custom ports.' );
		}
		return $url;
	}

	private function slug( string $slug ): string {
		$slug = strtolower( trim( $slug ) );
		if ( strlen( $slug ) < 3 || strlen( $slug ) > 120 || ! preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug ) ) {
			throw new Execution_Exception( 'publisher_media_slug_invalid', 'Use a meaningful lowercase ASCII media slug containing only words, digits, and single hyphens.' );
		}
		if ( preg_match( '/(?:^|-)(?:chatgpt|openai|dall-e|dalle|midjourney|generated-image)(?:-|$)/', $slug ) ) {
			throw new Execution_Exception( 'publisher_media_slug_vendor_invalid', 'The media slug must describe the asset, not the AI tool or generated source filename.' );
		}
		return $slug;
	}

	private function idempotency_key( string $key ): string {
		$key = trim( $key );
		if ( ! preg_match( '/^[A-Za-z0-9._:-]{8,128}$/', $key ) ) {
			throw new Execution_Exception( 'publisher_media_idempotency_key_invalid', 'A stable media idempotency key is required.' );
		}
		return $key;
	}

	private function find_attachment( string $idempotency_hash ): int {
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
					array( 'key' => self::IDEMPOTENCY_META, 'value' => $idempotency_hash, 'compare' => '=' ),
				),
			)
		);
		return absint( $query->posts[0] ?? 0 );
	}

	/** @return array{name:string,value:string} */
	private function acquire_lock( string $idempotency_hash ): array {
		$name = self::LOCK_PREFIX . substr( $idempotency_hash, 0, 40 );
		$value = wp_generate_uuid4() . ':' . time();
		if ( ! add_option( $name, $value, '', false ) ) {
			$existing = (string) get_option( $name, '' );
			$separator = strrpos( $existing, ':' );
			$created = false === $separator ? 0 : (int) substr( $existing, $separator + 1 );
			if ( $created <= time() - 120 ) {
				global $wpdb;
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $name, $existing ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact compare-and-delete recovers only an expired upload lock.
				wp_cache_delete( $name, 'options' );
			}
			if ( ! add_option( $name, $value, '', false ) ) {
				throw new Execution_Exception( 'publisher_media_upload_conflict', 'This Publisher media upload is already in progress.' );
			}
		}
		return array( 'name' => $name, 'value' => $value );
	}

	/** @param array{name:string,value:string} $lock */
	private function release_lock( array $lock ): void {
		if ( hash_equals( $lock['value'], (string) get_option( $lock['name'], '' ) ) ) {
			delete_option( $lock['name'] );
		}
	}

	private function result( int $attachment_id, bool $created, string $source_kind ): array {
		$metadata = wp_get_attachment_metadata( $attachment_id );
		$file = (string) get_attached_file( $attachment_id );
		return array(
			'attachment_id' => $attachment_id,
			'created'       => $created,
			'public_asset'  => true,
			'source_kind'   => $source_kind,
			'slug'          => (string) get_post_field( 'post_name', $attachment_id ),
			'file_name'     => '' !== $file ? wp_basename( $file ) : '',
			'title'         => (string) get_post_field( 'post_title', $attachment_id ),
			'alt_text'      => (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
			'mime_type'     => (string) get_post_mime_type( $attachment_id ),
			'url'           => (string) ( wp_get_attachment_url( $attachment_id ) ?: '' ),
			'width'         => is_array( $metadata ) ? absint( $metadata['width'] ?? 0 ) : 0,
			'height'        => is_array( $metadata ) ? absint( $metadata['height'] ?? 0 ) : 0,
		);
	}
}
