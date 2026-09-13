<?php
/* Static, bounded HTML preview generation for Composer-owned drafts. */

namespace SmartCloud\AgentComposer\Execution;

final class Rendered_Preview_Service {
	private const MAX_HTML_BYTES       = 500000;
	private const MAX_ASSETS           = 250;
	private const MAX_IMAGE_BYTES      = 5242880;
	private const MAX_FONT_BYTES       = 5242880;
	private const MAX_STYLESHEETS      = 32;
	private const MAX_STYLESHEET_BYTES = 524288;
	private const MAX_CSS_BYTES        = 1048576;
	private const MAX_IMPORT_DEPTH     = 4;
	private const MAX_IMPORTED_STYLESHEETS = 32;
	private const CSS_ASSET_SCHEME     = 'smartcloud-preview-asset://';
	private const ASSET_ID_PATTERN     = '/^pa_[A-Za-z0-9_-]{43}$/';
	private const SUBMISSION_TOKEN_TTL     = 1800;
	private const SUBMISSION_TOKEN_PATTERN = '/^pv1\.[0-9]{10}\.[A-Za-z0-9_-]{43}$/';
	private const ASSET_SNAPSHOT_TTL       = 1800;
	private const ASSET_SNAPSHOT_VERSION   = 1;
	private const PROPOSAL_TARGET_SLUG_META = '_wpsuite_agent_proposal_target_slug';
	private const PAGE_TYPE_META             = '_wpsuite_agent_page_type';

	/** @var array<string,array<string,mixed>> */
	private array $asset_sources = array();
	private int $imported_stylesheet_count = 0;

	public function __construct( private readonly Draft_Service $drafts ) {}

	public function get( array $input ): array {
		$post          = $this->drafts->get_owned_draft( absint( $input['post_id'] ?? 0 ) );
		$preview_start = $this->drafts->get_preview( $post->ID );
		$this->assert_expected_version( $input, $preview_start );
		$language = sanitize_text_field( (string) get_post_meta( $post->ID, Draft_Service::CONTENT_LANGUAGE_META, true ) );
		$result   = $this->build_document( $post, $preview_start, $language ?: 'und' );
		$this->assert_same_version( $preview_start, $this->drafts->get_preview( $post->ID ) );
		$this->store_asset_snapshot(
			$post->ID,
			(string) ( $preview_start['revision'] ?? '' ),
			$this->asset_sources
		);
		return $result;
	}

	/**
	 * Return one opaque preview asset after rebuilding the authorized draft snapshot.
	 */
	public function get_asset( array $input ): array {
		$asset_id = trim( (string) ( $input['asset_id'] ?? '' ) );
		if ( ! preg_match( self::ASSET_ID_PATTERN, $asset_id ) ) {
			throw new Execution_Exception( 'rendered_preview_asset_invalid', 'The rendered preview asset identifier is invalid.' );
		}
		$post              = $this->drafts->get_owned_draft_for_preview_asset( absint( $input['post_id'] ?? 0 ) );
		$expected_revision = strtolower( trim( (string) ( $input['expected_revision'] ?? '' ) ) );
		$current_revision  = strtolower( trim( (string) get_post_meta( $post->ID, Draft_Service::REVISION_META, true ) ) );
		if ( '' === $expected_revision || ! hash_equals( $current_revision, $expected_revision ) ) {
			throw new Execution_Exception( 'edit_conflict', 'The draft revision changed after the preview was created. Fetch it again before loading assets.' );
		}

		$sources = $this->load_asset_snapshot( $post->ID, $expected_revision );
		if ( null === $sources ) {
			$preview_start = $this->drafts->get_preview_for_preview_asset( $post->ID );
			$this->assert_expected_version( array( 'expected_revision' => $expected_revision ), $preview_start );
			$language = sanitize_text_field( (string) get_post_meta( $post->ID, Draft_Service::CONTENT_LANGUAGE_META, true ) );
			$this->build_document( $post, $preview_start, $language ?: 'und', false );
			$this->assert_same_version( $preview_start, $this->drafts->get_preview_for_preview_asset( $post->ID ) );
			$sources = $this->asset_sources;
			$this->store_asset_snapshot( $post->ID, $expected_revision, $sources );
		}
		$source = $sources[ $asset_id ] ?? null;
		if ( ! is_array( $source ) ) {
			throw new Execution_Exception( 'rendered_preview_asset_not_found', 'The rendered preview asset is not part of this exact draft revision.' );
		}
		if ( 'stylesheet' === ( $source['kind'] ?? '' ) ) {
			return array(
				'type'     => 'resource',
				'resource' => array(
					'uri'      => 'preview-asset://smartcloud-agent-composer/' . $asset_id,
					'mimeType' => 'text/css',
					'text'     => (string) $source['content'],
				),
			);
		}
		$kind = (string) ( $source['kind'] ?? '' );
		$path = (string) ( $source['path'] ?? '' );
		$size = '' !== $path && is_file( $path ) && is_readable( $path ) ? filesize( $path ) : false;
		$max_size = 'font' === $kind ? self::MAX_FONT_BYTES : self::MAX_IMAGE_BYTES;
		if ( false === $size || $size < 1 || $size > $max_size || $size !== ( $source['byte_length'] ?? -1 ) ) {
			throw new Execution_Exception( 'rendered_preview_asset_changed', 'The rendered preview binary asset changed after the asset manifest was created.' );
		}
		$bytes = file_get_contents( $path );
		if ( false === $bytes || ! hash_equals( (string) $source['sha256'], hash( 'sha256', $bytes ) ) ) {
			throw new Execution_Exception( 'rendered_preview_asset_changed', 'The rendered preview binary asset changed after the asset manifest was created.' );
		}
		if ( 'font' === $kind ) {
			return array(
				'type'     => 'resource',
				'resource' => array(
					'uri'      => 'preview-asset://smartcloud-agent-composer/' . $asset_id,
					'mimeType' => (string) $source['mime_type'],
					'blob'     => base64_encode( $bytes ),
				),
			);
		}
		return array( 'type' => 'image', 'results' => $bytes, 'mimeType' => (string) $source['mime_type'] );
	}

	/**
	 * Build the transport-safe static document after draft access is authorized.
	 */
	public function build_document( \WP_Post $post, array $preview, string $content_language, bool $issue_submission_token = true ): array {
		$this->asset_sources = array();
		$this->imported_stylesheet_count = 0;
		$direction = self::language_direction( $content_language );
		$rendered  = self::strip_gutenberg_serialization( $this->render_saved_blocks( $post, $content_language ) );
		$sanitized = wp_kses_post( $rendered );
		$warnings  = array(
			array(
				'code'    => 'static_content_preview',
				'message' => 'This preview renders the saved block content without site navigation, template parts, forms, or frontend JavaScript interactions.',
			),
		);
		if ( ! hash_equals( $rendered, $sanitized ) ) {
			$warnings[] = array( 'code' => 'active_markup_removed', 'message' => 'Active or unsupported markup was removed from the embedded preview.' );
		}

		$assets = array();
		$body   = $this->normalize_image_assets( $post->ID, (string) ( $preview['revision'] ?? '' ), $sanitized, $assets, $warnings );
		$this->collect_stylesheet_assets( $post, (string) ( $preview['revision'] ?? '' ), $assets, $warnings );
		if ( count( $assets ) > self::MAX_ASSETS ) {
			throw new Execution_Exception( 'rendered_preview_too_many_assets', 'The rendered preview exceeds the 250-asset response limit.' );
		}
		$html = sprintf(
			'<article class="smartcloud-composer-preview" data-smartcloud-preview-body-classes="%3$s" lang="%1$s" dir="%2$s"><div class="smartcloud-composer-preview__content">%4$s</div></article>',
			esc_attr( $content_language ?: 'en' ),
			esc_attr( $direction ),
			esc_attr( implode( ' ', $this->preview_body_classes( $post ) ) ),
			$body
		);
		$byte_length = strlen( $html );
		if ( $byte_length > self::MAX_HTML_BYTES ) {
			throw new Execution_Exception( 'rendered_preview_too_large', 'The rendered preview exceeds the 500000-byte response limit.' );
		}
		$modified_gmt = (string) ( $preview['modified_gmt'] ?? '' );
		$revision     = (string) ( $preview['revision'] ?? '' );
		$result = array(
			'post_id'     => $post->ID,
			'edit_url'    => (string) ( $preview['edit_url'] ?? '' ),
			'preview_url' => (string) ( $preview['preview_url'] ?? '' ),
			'validation'  => (array) ( $preview['validation'] ?? array() ),
			'document'    => array(
				'contract_version' => '3',
				'post_id'          => $post->ID,
				'modified_gmt'      => $modified_gmt,
				'revision'          => $revision,
				'title'             => wp_strip_all_tags( get_the_title( $post ) ),
				'content_language'  => $content_language,
				'direction'         => $direction,
				'scope'             => 'content',
				'fidelity'          => 'static',
				'source_format'     => 'rendered-html',
				'mime_type'         => 'text/html',
				'html'              => $html,
				'sha256'            => hash( 'sha256', $html ),
				'byte_length'       => $byte_length,
				'assets'            => array_values( $assets ),
				'warnings'          => array_values( $warnings ),
			),
		);
		if ( $issue_submission_token ) {
			$result['rendered_preview_token'] = self::issue_submission_token( $post->ID, $modified_gmt, $revision, self::current_user_id() );
		}
		return $result;
	}

	/**
	 * Render saved blocks with the draft language available to integrations.
	 *
	 * The MCP request locale is not necessarily the authored content locale.
	 * Limit the override to this render so it cannot leak into later WordPress
	 * rendering in the same request.
	 */
	private function render_saved_blocks( \WP_Post $post, string $content_language ): string {
		$language_filter = static fn( string $current_language = '' ): string => $content_language;
		add_filter( 'smartcloud_composer_rendered_preview_content_language', $language_filter, PHP_INT_MAX );
		try {
			return do_blocks( (string) $post->post_content );
		} finally {
			remove_filter( 'smartcloud_composer_rendered_preview_content_language', $language_filter, PHP_INT_MAX );
		}
	}

	/**
	 * Verify that the assigned agent rendered this exact proposal revision.
	 */
	public static function assert_submission_token( string $token, int $post_id, string $modified_gmt, string $revision, int $user_id ): void {
		if ( ! preg_match( self::SUBMISSION_TOKEN_PATTERN, $token ) ) {
			throw new Execution_Exception( 'rendered_preview_required', 'Render the final proposal revision before submitting it for review.' );
		}
		$parts   = explode( '.', $token, 3 );
		$expires = (int) ( $parts[1] ?? 0 );
		if ( $expires < time() ) {
			throw new Execution_Exception( 'rendered_preview_expired', 'The rendered preview token expired. Render the current proposal revision again before submitting it.' );
		}
		$expected = self::submission_signature( $post_id, $modified_gmt, $revision, $user_id, $expires );
		if ( ! hash_equals( $expected, (string) ( $parts[2] ?? '' ) ) ) {
			throw new Execution_Exception( 'rendered_preview_required', 'The supplied preview token does not attest to this exact proposal revision. Render it again before submitting it.' );
		}
	}

	private static function issue_submission_token( int $post_id, string $modified_gmt, string $revision, int $user_id ): string {
		$expires = time() + self::SUBMISSION_TOKEN_TTL;
		return 'pv1.' . $expires . '.' . self::submission_signature( $post_id, $modified_gmt, $revision, $user_id, $expires );
	}

	private static function submission_signature( int $post_id, string $modified_gmt, string $revision, int $user_id, int $expires ): string {
		$secret = function_exists( 'wp_salt' ) ? wp_salt( 'auth' ) : __CLASS__;
		$bytes  = hash_hmac( 'sha256', "preview-submit-v1\n{$post_id}\n{$modified_gmt}\n{$revision}\n{$user_id}\n{$expires}", $secret, true );
		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
	}

	private static function current_user_id(): int {
		return function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0;
	}

	private static function strip_gutenberg_serialization( string $html ): string {
		$rendered = preg_replace( '/<!--\s*\/?wp:[\s\S]*?-->/i', '', $html );
		return null === $rendered ? $html : $rendered;
	}

	/**
	 * Compatibility helper retained for callers that inspect site origins.
	 * Assets are transported through the private asset Ability.
	 *
	 * @return list<string>
	 */
	public static function allowed_asset_origins(): array {
		$urls = array( home_url( '/' ) );
		if ( function_exists( 'site_url' ) ) {
			$urls[] = site_url( '/' );
		}
		if ( function_exists( 'wp_upload_dir' ) ) {
			$uploads = wp_upload_dir( null, false );
			if ( empty( $uploads['error'] ) && ! empty( $uploads['baseurl'] ) ) {
				$urls[] = (string) $uploads['baseurl'];
			}
		}
		$origins = array();
		foreach ( $urls as $url ) {
			$origin = self::url_origin( self::absolute_url( (string) $url ) );
			if ( '' !== $origin ) {
				$origins[] = $origin;
			}
		}
		return array_values( array_unique( $origins ) );
	}

	private function assert_expected_version( array $input, array $preview ): void {
		$expected_modified = self::canonical_modified_gmt( (string) ( $input['expected_modified_gmt'] ?? '' ) );
		$expected_revision = strtolower( trim( (string) ( $input['expected_revision'] ?? '' ) ) );
		$current_modified  = self::canonical_modified_gmt( (string) ( $preview['modified_gmt'] ?? '' ) );
		$current_revision  = strtolower( (string) ( $preview['revision'] ?? '' ) );
		if ( '' !== $expected_modified && ! hash_equals( $current_modified, $expected_modified ) ) {
			throw new Execution_Exception( 'edit_conflict', 'The draft modification time changed after it was read. Fetch it again before rendering.' );
		}
		if ( '' !== $expected_revision && ! hash_equals( $current_revision, $expected_revision ) ) {
			throw new Execution_Exception( 'edit_conflict', 'The draft revision changed after it was read. Fetch it again before rendering.' );
		}
	}

	private static function canonical_modified_gmt( string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}
		$timestamp = strtotime( $value );
		return false === $timestamp ? $value : gmdate( 'Y-m-d\TH:i:s\Z', $timestamp );
	}

	/** @param array<string,array<string,mixed>> $sources */
	private function store_asset_snapshot( int $post_id, string $revision, array $sources ): void {
		if ( $post_id < 1 || '' === $revision || empty( $sources ) || ! function_exists( 'set_transient' ) ) {
			return;
		}
		set_transient(
			$this->asset_snapshot_key( $post_id, $revision ),
			array(
				'version'  => self::ASSET_SNAPSHOT_VERSION,
				'post_id'  => $post_id,
				'user_id'  => self::current_user_id(),
				'revision' => strtolower( $revision ),
				'sources'  => $sources,
			),
			self::ASSET_SNAPSHOT_TTL
		);
	}

	/** @return array<string,array<string,mixed>>|null */
	private function load_asset_snapshot( int $post_id, string $revision ): ?array {
		if ( $post_id < 1 || '' === $revision || ! function_exists( 'get_transient' ) ) {
			return null;
		}
		$snapshot = get_transient( $this->asset_snapshot_key( $post_id, $revision ) );
		if (
			! is_array( $snapshot )
			|| self::ASSET_SNAPSHOT_VERSION !== (int) ( $snapshot['version'] ?? 0 )
			|| $post_id !== (int) ( $snapshot['post_id'] ?? 0 )
			|| self::current_user_id() !== (int) ( $snapshot['user_id'] ?? 0 )
			|| ! hash_equals( strtolower( $revision ), strtolower( (string) ( $snapshot['revision'] ?? '' ) ) )
			|| ! is_array( $snapshot['sources'] ?? null )
			|| count( $snapshot['sources'] ) > self::MAX_ASSETS
		) {
			return null;
		}
		return $snapshot['sources'];
	}

	private function asset_snapshot_key( int $post_id, string $revision ): string {
		$secret = function_exists( 'wp_salt' ) ? wp_salt( 'auth' ) : __CLASS__;
		$digest = hash_hmac( 'sha256', "preview-assets-v1\n{$post_id}\n{$revision}\n" . self::current_user_id(), $secret );
		return 'smartcloud_ac_preview_assets_' . $digest;
	}

	private function assert_same_version( array $before, array $after ): void {
		$this->assert_expected_version(
			array(
				'expected_modified_gmt' => (string) ( $before['modified_gmt'] ?? '' ),
				'expected_revision'     => (string) ( $before['revision'] ?? '' ),
			),
			$after
		);
	}

	private function normalize_image_assets( int $post_id, string $revision, string $html, array &$assets, array &$warnings ): string {
		if ( ! class_exists( '\\WP_HTML_Tag_Processor' ) ) {
			return $html;
		}
		$tags = new \WP_HTML_Tag_Processor( $html );
		while ( $tags->next_tag( 'IMG' ) ) {
			$src = trim( html_entity_decode( (string) $tags->get_attribute( 'src' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
			$tags->remove_attribute( 'src' );
			$tags->remove_attribute( 'srcset' );
			if ( '' === $src ) {
				continue;
			}
			$image = $this->resolve_upload_image( $src );
			if ( null === $image ) {
				$this->add_warning_once( $warnings, 'image_not_proxyable', 'An image that is not a bounded local uploads raster was omitted from the embedded preview.' );
				continue;
			}
			$asset_id = $this->asset_id( $post_id, $revision, 'image', (string) $image['identity'] );
			$tags->set_attribute( 'data-smartcloud-preview-asset', $asset_id );
			$this->asset_sources[ $asset_id ] = array_merge( array( 'kind' => 'image' ), $image );
			$assets[ $asset_id ] = array(
				'asset_id' => $asset_id, 'kind' => 'image', 'mime_type' => (string) $image['mime_type'],
				'byte_length' => (int) $image['byte_length'], 'sha256' => (string) $image['sha256'],
			);
		}
		$sources = new \WP_HTML_Tag_Processor( $tags->get_updated_html() );
		while ( $sources->next_tag( 'SOURCE' ) ) {
			$sources->remove_attribute( 'src' );
			$sources->remove_attribute( 'srcset' );
		}
		return $sources->get_updated_html();
	}

	private function resolve_upload_image( string $src ): ?array {
		if ( ! function_exists( 'wp_upload_dir' ) ) {
			return null;
		}
		$uploads = wp_upload_dir( null, false );
		if ( ! empty( $uploads['error'] ) || empty( $uploads['baseurl'] ) || empty( $uploads['basedir'] ) ) {
			return null;
		}
		$url  = self::absolute_url( $src );
		$base = rtrim( (string) $uploads['baseurl'], '/' );
		if ( '' === $url || ! self::url_is_below( $url, $base ) || null !== wp_parse_url( $url, PHP_URL_QUERY ) || null !== wp_parse_url( $url, PHP_URL_FRAGMENT ) ) {
			return null;
		}
		$url_path  = rawurldecode( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		$base_path = rtrim( rawurldecode( (string) wp_parse_url( $base, PHP_URL_PATH ) ), '/' ) . '/';
		$relative  = ltrim( substr( $url_path, strlen( $base_path ) ), '/' );
		$base_dir  = realpath( (string) $uploads['basedir'] );
		$path      = realpath( rtrim( (string) $uploads['basedir'], DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . $relative );
		if ( false === $base_dir || false === $path || ! self::path_is_below( $path, $base_dir ) || ! is_file( $path ) || ! is_readable( $path ) ) {
			return null;
		}
		$extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		$mimes = array( 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif', 'avif' => 'image/avif' );
		$size  = filesize( $path );
		$mime  = self::detect_mime_type( $path );
		if ( ! isset( $mimes[ $extension ] ) || false === $size || $size < 1 || $size > self::MAX_IMAGE_BYTES || ! hash_equals( $mimes[ $extension ], $mime ) ) {
			return null;
		}
		$sha256 = hash_file( 'sha256', $path );
		return false === $sha256 ? null : array(
			'identity' => 'uploads/' . str_replace( DIRECTORY_SEPARATOR, '/', $relative ), 'path' => $path,
			'mime_type' => $mime, 'byte_length' => $size, 'sha256' => $sha256,
		);
	}

	private function collect_stylesheet_assets( \WP_Post $post, string $revision, array &$assets, array &$warnings ): void {
		$candidates  = array();
		$block_names = $this->block_names( (string) $post->post_content );
		foreach ( $this->registered_style_handles( $block_names ) as $handle ) {
			$path = $this->registered_stylesheet_path( $handle );
			if ( null !== $path ) {
				$candidates[] = array( 'handle' => $handle, 'path' => $path, 'media' => 'all', 'identity' => 'path:' . $path );
			}
		}
		if ( function_exists( 'wp_get_global_stylesheet' ) ) {
			$global = (string) wp_get_global_stylesheet();
			if ( '' !== trim( $global ) ) {
				$candidates[] = array( 'handle' => 'global-styles', 'content' => $global, 'media' => 'all', 'identity' => 'global:' . hash( 'sha256', $global ) );
			}
		}
		$extra = function_exists( 'apply_filters' ) ? apply_filters( 'smartcloud_composer_rendered_preview_stylesheets', array(), $post, $block_names ) : array();
		$explicit_theme_styles = is_array( $extra ) ? $extra : array();
		foreach ( $explicit_theme_styles as $descriptor ) {
			if ( ! is_array( $descriptor ) ) {
				continue;
			}
			$handle = sanitize_key( (string) ( $descriptor['handle'] ?? '' ) );
			$path   = $this->approved_stylesheet_path( (string) ( $descriptor['path'] ?? '' ) );
			if ( '' === $handle || null === $path ) {
				$this->add_warning_once( $warnings, 'stylesheet_not_allowed', 'A configured preview stylesheet was ignored because its handle or local path was invalid.' );
				continue;
			}
			$candidates[] = array(
				'handle' => $handle, 'path' => $path, 'media' => self::sanitize_media( (string) ( $descriptor['media'] ?? 'all' ) ),
				'identity' => 'path:' . $path,
			);
		}
		// A broad theme scan is only a compatibility fallback. Explicit block and
		// theme-provided candidates above must remain ahead of the bounded limit.
		if ( empty( $explicit_theme_styles ) ) {
			foreach ( $this->theme_stylesheet_paths() as $path ) {
				$candidates[] = array( 'handle' => 'theme-' . basename( $path, '.css' ), 'path' => $path, 'media' => 'all', 'identity' => 'path:' . $path );
			}
		}

		$seen = array();
		$total_bytes = 0;
		$stylesheet_count = 0;
		$combined_css = '';
		foreach ( $candidates as $candidate ) {
			$identity = (string) $candidate['identity'];
			if ( isset( $seen[ $identity ] ) ) {
				continue;
			}
			$seen[ $identity ] = true;
			if ( $stylesheet_count >= self::MAX_STYLESHEETS ) {
				$this->add_warning_once( $warnings, 'stylesheet_limit_reached', 'Additional preview stylesheets were omitted after the 32-file input limit.' );
				break;
			}
			++$stylesheet_count;
			$source_path = isset( $candidate['content'] ) ? null : (string) ( $candidate['path'] ?? '' );
			$css  = isset( $candidate['content'] ) ? (string) $candidate['content'] : $this->read_stylesheet( (string) $source_path );
			$initial_stack = null !== $source_path ? array( $source_path => true ) : array();
			$css  = $this->prepare_stylesheet_css( $css, $source_path, $post->ID, $revision, $assets, $warnings, 0, $initial_stack );
			$size = strlen( $css );
			if ( 0 === $size || $size > self::MAX_STYLESHEET_BYTES || $total_bytes + $size > self::MAX_CSS_BYTES ) {
				$this->add_warning_once( $warnings, 'stylesheet_size_limit', 'A preview stylesheet was omitted because the stylesheet or aggregate CSS limit was exceeded.' );
				continue;
			}
			$media = self::sanitize_media( (string) ( $candidate['media'] ?? 'all' ) );
			if ( 'all' !== strtolower( $media ) ) {
				$css = '@media ' . $media . '{' . $css . '}';
			}
			$combined_css .= "\n/* " . sanitize_key( (string) $candidate['handle'] ) . " */\n" . $css;
			$total_bytes += $size;
		}
		if ( '' !== trim( $combined_css ) ) {
			$size = strlen( $combined_css );
			$sha256 = hash( 'sha256', $combined_css );
			$asset_id = $this->asset_id( $post->ID, $revision, 'stylesheet', 'combined:' . $sha256 );
			$this->asset_sources[ $asset_id ] = array( 'kind' => 'stylesheet', 'content' => $combined_css );
			$assets[ $asset_id ] = array(
				'asset_id' => $asset_id, 'kind' => 'stylesheet', 'mime_type' => 'text/css', 'byte_length' => $size,
				'sha256' => $sha256, 'handle' => 'smartcloud-preview-styles', 'media' => 'all', 'order' => 0,
			);
		}
	}

	/** @return list<string> */
	private function preview_body_classes( \WP_Post $post ): array {
		$post_type = sanitize_key( (string) ( $post->post_type ?? 'page' ) ) ?: 'page';
		$slug = sanitize_title( (string) get_post_meta( $post->ID, self::PROPOSAL_TARGET_SLUG_META, true ) );
		if ( '' === $slug ) {
			$slug = sanitize_title( (string) ( $post->post_name ?? '' ) );
		}
		$classes = array( 'smartcloud-composer-preview-document', 'post-type-' . $post_type );
		$page_type = sanitize_key( (string) get_post_meta( $post->ID, self::PAGE_TYPE_META, true ) );
		if ( str_starts_with( $page_type, 'product-' ) ) {
			$classes[] = 'page-' . substr( $page_type, strlen( 'product-' ) );
		}
		if ( 'page' === $post_type ) {
			$classes[] = 'page';
			$classes[] = 'page-id-' . $post->ID;
			if ( '' !== $slug ) {
				$classes[] = 'page-' . $slug;
			}
		} else {
			$classes[] = 'single';
			$classes[] = 'single-' . $post_type;
			$classes[] = $post_type . '-template-default';
			if ( '' !== $slug ) {
				$classes[] = 'post-' . $slug;
			}
		}
		return array_values( array_unique( array_filter( $classes ) ) );
	}

	/** @return list<string> */
	private function theme_stylesheet_paths(): array {
		if ( ! function_exists( 'wp_get_theme' ) ) {
			return array();
		}
		$theme = wp_get_theme();
		$themes = array( $theme );
		$parent = is_object( $theme ) && method_exists( $theme, 'parent' ) ? $theme->parent() : false;
		if ( $parent ) {
			$themes[] = $parent;
		}
		$paths = array();
		foreach ( $themes as $candidate_theme ) {
			if ( ! is_object( $candidate_theme ) || ! method_exists( $candidate_theme, 'get_stylesheet_directory' ) ) {
				continue;
			}
			$root = realpath( (string) $candidate_theme->get_stylesheet_directory() );
			if ( false === $root ) {
				continue;
			}
			$patterns = array( $root . '/style.css' );
			foreach ( array( 'assets/css', 'styles', 'build', 'dist' ) as $directory ) {
				$patterns[] = $root . '/' . $directory . '/*.css';
				$patterns[] = $root . '/' . $directory . '/*/*.css';
			}
			foreach ( $patterns as $pattern ) {
				$matches = str_contains( $pattern, '*' ) ? glob( $pattern ) : array( $pattern );
				foreach ( is_array( $matches ) ? $matches : array() as $path ) {
					$approved = $this->approved_stylesheet_path( $path );
					if ( null !== $approved ) {
						$paths[] = $approved;
					}
				}
			}
		}
		return array_values( array_unique( $paths ) );
	}

	/** @return list<string> */
	private function block_names( string $content ): array {
		if ( ! function_exists( 'parse_blocks' ) ) {
			return array();
		}
		$names = array();
		$walk = static function ( array $blocks ) use ( &$walk, &$names ): void {
			foreach ( $blocks as $block ) {
				if ( ! empty( $block['blockName'] ) ) {
					$names[] = (string) $block['blockName'];
				}
				if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
					$walk( $block['innerBlocks'] );
				}
			}
		};
		$walk( parse_blocks( $content ) );
		return array_values( array_unique( $names ) );
	}

	/** @return list<string> */
	private function registered_style_handles( array $block_names ): array {
		$handles = array( 'wp-block-library', 'wp-block-library-theme', 'global-styles' );
		if ( class_exists( '\\WP_Block_Type_Registry' ) ) {
			$registry = \WP_Block_Type_Registry::get_instance();
			foreach ( $block_names as $block_name ) {
				$block = $registry->get_registered( $block_name );
				if ( ! is_object( $block ) ) {
					continue;
				}
				foreach ( array( 'style_handles', 'view_style_handles' ) as $property ) {
					foreach ( (array) ( $block->{$property} ?? array() ) as $handle ) {
						$handles[] = (string) $handle;
					}
				}
				foreach ( array( 'style', 'view_style' ) as $property ) {
					if ( ! empty( $block->{$property} ) && is_string( $block->{$property} ) ) {
						$handles[] = $block->{$property};
					}
				}
			}
		}
		return array_values( array_unique( array_filter( array_map( static fn( string $handle ): string => sanitize_key( $handle ), $handles ) ) ) );
	}

	private function registered_stylesheet_path( string $handle ): ?string {
		if ( ! function_exists( 'wp_styles' ) ) {
			return null;
		}
		$styles = wp_styles();
		$item = is_object( $styles ) && isset( $styles->registered[ $handle ] ) ? $styles->registered[ $handle ] : null;
		$src  = is_object( $item ) ? (string) ( $item->src ?? '' ) : '';
		if ( '' === $src ) {
			return null;
		}
		if ( str_starts_with( $src, '/' ) && defined( 'ABSPATH' ) && function_exists( 'site_url' ) ) {
			$site_path = (string) wp_parse_url( site_url( '/' ), PHP_URL_PATH );
			$relative = '' !== $site_path && str_starts_with( $src, rtrim( $site_path, '/' ) . '/' )
				? substr( $src, strlen( rtrim( $site_path, '/' ) ) + 1 ) : ltrim( $src, '/' );
			return $this->approved_stylesheet_path( rtrim( ABSPATH, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . $relative );
		}
		foreach ( $this->url_path_roots() as $mapping ) {
			if ( self::url_is_below( $src, $mapping['url'] ) ) {
				$url_path = rawurldecode( (string) wp_parse_url( $src, PHP_URL_PATH ) );
				$base_path = rtrim( rawurldecode( (string) wp_parse_url( $mapping['url'], PHP_URL_PATH ) ), '/' ) . '/';
				return $this->approved_stylesheet_path( rtrim( $mapping['path'], DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . ltrim( substr( $url_path, strlen( $base_path ) ), '/' ) );
			}
		}
		return null;
	}

	/** @return list<array{url:string,path:string}> */
	private function url_path_roots(): array {
		$roots = array();
		if ( function_exists( 'content_url' ) && defined( 'WP_CONTENT_DIR' ) ) {
			$roots[] = array( 'url' => content_url( '/' ), 'path' => WP_CONTENT_DIR );
		}
		if ( function_exists( 'includes_url' ) && defined( 'ABSPATH' ) && defined( 'WPINC' ) ) {
			$roots[] = array( 'url' => includes_url( '/' ), 'path' => rtrim( ABSPATH, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . WPINC );
		}
		return $roots;
	}

	private function approved_stylesheet_path( string $candidate ): ?string {
		$path = realpath( $candidate );
		if ( false === $path || 'css' !== strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) || ! is_file( $path ) || ! is_readable( $path ) ) {
			return null;
		}
		foreach ( $this->approved_file_roots() as $root ) {
			if ( self::path_is_below( $path, $root ) ) {
				return $path;
			}
		}
		return null;
	}

	/** @return list<string> */
	private function approved_file_roots(): array {
		$roots = array();
		foreach ( array( defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : '', defined( 'ABSPATH' ) ? ABSPATH : '' ) as $candidate ) {
			$root = '' !== $candidate ? realpath( $candidate ) : false;
			if ( false !== $root ) {
				$roots[] = $root;
			}
		}
		if ( function_exists( 'wp_get_theme' ) ) {
			$theme = wp_get_theme();
			foreach ( array( $theme, is_object( $theme ) && method_exists( $theme, 'parent' ) ? $theme->parent() : false ) as $candidate_theme ) {
				if ( $candidate_theme && method_exists( $candidate_theme, 'get_stylesheet_directory' ) ) {
					$root = realpath( (string) $candidate_theme->get_stylesheet_directory() );
					if ( false !== $root ) {
						$roots[] = $root;
					}
				}
			}
		}
		return array_values( array_unique( $roots ) );
	}

	private function read_stylesheet( string $path ): string {
		$approved = $this->approved_stylesheet_path( $path );
		$size = null !== $approved ? filesize( $approved ) : false;
		if ( null === $approved || false === $size || $size < 1 || $size > self::MAX_STYLESHEET_BYTES ) {
			return '';
		}
		$content = file_get_contents( $approved );
		return false === $content ? '' : $content;
	}

	/**
	 * Flatten bounded local imports and proxy bounded local url() dependencies.
	 */
	private function prepare_stylesheet_css(
		string $css,
		?string $source_path,
		int $post_id,
		string $revision,
		array &$assets,
		array &$warnings,
		int $depth = 0,
		array $stack = array()
	): string {
		$output = '';
		$length = strlen( $css );
		for ( $index = 0; $index < $length; ) {
			if ( '/' === $css[ $index ] && $index + 1 < $length && '*' === $css[ $index + 1 ] ) {
				$end = strpos( $css, '*/', $index + 2 );
				$end = false === $end ? $length - 2 : $end;
				$output .= substr( $css, $index, $end + 2 - $index );
				$index = $end + 2;
				continue;
			}
			if ( '@' === $css[ $index ] && 0 === strncasecmp( substr( $css, $index, 7 ), '@import', 7 ) && self::css_boundary( $css, $index + 7 ) ) {
				$end = self::consume_css_construct( $css, $index + 7, ';' );
				$import = self::parse_import_rule( substr( $css, $index + 7, $end - $index - 7 ) );
				if ( null === $import || $depth >= self::MAX_IMPORT_DEPTH || $this->imported_stylesheet_count >= self::MAX_IMPORTED_STYLESHEETS ) {
					$this->add_warning_once( $warnings, 'stylesheet_import_omitted', 'A stylesheet import was omitted because it was invalid or exceeded the preview import limits.' );
					$index = $end;
					continue;
				}
				$path = $this->resolve_local_file_reference( $import['reference'], $source_path, 'css' );
				if ( null === $path || isset( $stack[ $path ] ) ) {
					$this->add_warning_once( $warnings, 'stylesheet_import_not_allowed', 'A stylesheet import was omitted because it was external, cyclic, or outside the approved local roots.' );
					$index = $end;
					continue;
				}
				$imported_css = $this->read_stylesheet( $path );
				if ( '' === $imported_css ) {
					$this->add_warning_once( $warnings, 'stylesheet_import_unreadable', 'A local stylesheet import could not be read within the preview size limits.' );
					$index = $end;
					continue;
				}
				++$this->imported_stylesheet_count;
				$next_stack = $stack;
				$next_stack[ $path ] = true;
				$flattened = $this->prepare_stylesheet_css( $imported_css, $path, $post_id, $revision, $assets, $warnings, $depth + 1, $next_stack );
				if ( '' !== $import['media'] && 'all' !== strtolower( $import['media'] ) ) {
					$flattened = '@media ' . $import['media'] . '{' . $flattened . '}';
				}
				$output .= $flattened;
				$index = $end;
				continue;
			}
			$unsafe_function = self::unsafe_css_fetch_function_at( $css, $index );
			if ( null !== $unsafe_function ) {
				$cursor = $index + strlen( $unsafe_function );
				while ( $cursor < $length && ctype_space( $css[ $cursor ] ) ) {
					++$cursor;
				}
				$output .= 'none';
				$index = self::consume_css_parentheses( $css, $cursor );
				$this->add_warning_once( $warnings, 'stylesheet_fetch_function_omitted', 'A CSS function capable of loading an unverified resource was omitted from the embedded preview.' );
				continue;
			}
			if ( 0 === strncasecmp( substr( $css, $index, 3 ), 'url', 3 ) && self::css_boundary_before( $css, $index ) ) {
				$cursor = $index + 3;
				while ( $cursor < $length && ctype_space( $css[ $cursor ] ) ) {
					++$cursor;
				}
				if ( $cursor < $length && '(' === $css[ $cursor ] ) {
					$end = self::consume_css_parentheses( $css, $cursor );
					$reference = self::parse_url_value( substr( $css, $cursor + 1, max( 0, $end - $cursor - 2 ) ) );
					if ( null !== $reference && str_starts_with( $reference, '#' ) ) {
						$output .= 'url("' . $reference . '")';
						$index = $end;
						continue;
					}
					$binary = null !== $reference ? $this->resolve_stylesheet_binary( $reference, $source_path ) : null;
					if ( null === $binary || count( $assets ) >= self::MAX_ASSETS - self::MAX_STYLESHEETS ) {
						$this->add_warning_once( $warnings, 'stylesheet_url_omitted', 'A stylesheet URL was omitted because it was external, unsupported, outside the approved local roots, or exceeded the preview asset limits.' );
						$output .= 'none';
						$index = $end;
						continue;
					}
					$asset_id = $this->asset_id( $post_id, $revision, (string) $binary['kind'], (string) $binary['identity'] );
					$this->asset_sources[ $asset_id ] = $binary;
					$assets[ $asset_id ] = array(
						'asset_id' => $asset_id,
						'kind' => (string) $binary['kind'],
						'mime_type' => (string) $binary['mime_type'],
						'byte_length' => (int) $binary['byte_length'],
						'sha256' => (string) $binary['sha256'],
					);
					$output .= 'url("' . self::CSS_ASSET_SCHEME . $asset_id . '")';
					$index = $end;
					continue;
				}
			}
			if ( '"' === $css[ $index ] || "'" === $css[ $index ] ) {
				$end = self::consume_css_string( $css, $index );
				$output .= substr( $css, $index, $end - $index );
				$index = $end;
				continue;
			}
			if ( '\\' === $css[ $index ] ) {
				$output .= '_';
				++$index;
				$this->add_warning_once( $warnings, 'stylesheet_escaped_identifier_neutralized', 'A CSS escape outside a quoted value was neutralized to prevent hidden resource-loading constructs.' );
				continue;
			}
			$output .= $css[ $index ];
			++$index;
		}
		return $output;
	}

	private static function unsafe_css_fetch_function_at( string $css, int $index ): ?string {
		foreach ( array( '-webkit-image-set', 'image-set', 'image', 'src' ) as $function ) {
			$function_length = strlen( $function );
			if ( 0 !== strncasecmp( substr( $css, $index, $function_length ), $function, $function_length ) || ! self::css_boundary_before( $css, $index ) ) {
				continue;
			}
			$cursor = $index + $function_length;
			while ( $cursor < strlen( $css ) && ctype_space( $css[ $cursor ] ) ) {
				++$cursor;
			}
			if ( $cursor < strlen( $css ) && '(' === $css[ $cursor ] ) {
				return $function;
			}
		}
		return null;
	}

	/** @return array{reference:string,media:string}|null */
	private static function parse_import_rule( string $rule ): ?array {
		$rule = trim( rtrim( trim( $rule ), ';' ) );
		$pattern = '/^(?:url\(\s*(?:"([^"]*)"|\'([^\']*)\'|([^)]*))\s*\)|"([^"]*)"|\'([^\']*)\')\s*(.*?)\s*$/is';
		if ( 1 !== preg_match( $pattern, $rule, $matches ) ) {
			return null;
		}
		$reference = '';
		foreach ( array_slice( $matches, 1, 5 ) as $candidate ) {
			if ( '' !== trim( (string) $candidate ) ) {
				$reference = trim( (string) $candidate );
				break;
			}
		}
		$media = trim( (string) ( $matches[6] ?? '' ) );
		if ( '' === $reference || str_contains( $reference, '\\' ) || str_contains( $media, '\\' ) || preg_match( '/\b(?:layer|supports)\b|[{};]/i', $media ) ) {
			return null;
		}
		if ( '' !== $media && ! preg_match( '/^[A-Za-z0-9 ():\/.,_-]{1,120}$/', $media ) ) {
			return null;
		}
		return array( 'reference' => $reference, 'media' => '' === $media ? 'all' : $media );
	}

	private static function parse_url_value( string $value ): ?string {
		$value = trim( $value );
		if ( strlen( $value ) >= 2 && ( ( '"' === $value[0] && '"' === $value[ strlen( $value ) - 1 ] ) || ( "'" === $value[0] && "'" === $value[ strlen( $value ) - 1 ] ) ) ) {
			$value = substr( $value, 1, -1 );
		}
		$value = trim( $value );
		return '' === $value || str_contains( $value, '\\' ) || preg_match( '/[\x00-\x1F\x7F]/', $value ) ? null : $value;
	}

	private function resolve_stylesheet_binary( string $reference, ?string $source_path ): ?array {
		$path = $this->resolve_local_file_reference( $reference, $source_path );
		if ( null === $path ) {
			return null;
		}
		$extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		$image_mimes = array( 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif', 'avif' => 'image/avif' );
		$font_mimes = array( 'woff' => 'font/woff', 'woff2' => 'font/woff2' );
		$kind = isset( $image_mimes[ $extension ] ) ? 'image' : ( isset( $font_mimes[ $extension ] ) ? 'font' : '' );
		$mime_type = $image_mimes[ $extension ] ?? $font_mimes[ $extension ] ?? '';
		$size = filesize( $path );
		$max_size = 'font' === $kind ? self::MAX_FONT_BYTES : self::MAX_IMAGE_BYTES;
		if ( '' === $kind || false === $size || $size < 1 || $size > $max_size ) {
			return null;
		}
		if ( 'image' === $kind && ! hash_equals( $mime_type, self::detect_mime_type( $path ) ) ) {
			return null;
		}
		if ( 'font' === $kind ) {
			$signature = file_get_contents( $path, false, null, 0, 4 );
			$expected = 'woff2' === $extension ? 'wOF2' : 'wOFF';
			if ( false === $signature || ! hash_equals( $expected, $signature ) ) {
				return null;
			}
		}
		$sha256 = hash_file( 'sha256', $path );
		return false === $sha256 ? null : array(
			'kind' => $kind,
			'identity' => str_replace( DIRECTORY_SEPARATOR, '/', $path ),
			'path' => $path,
			'mime_type' => $mime_type,
			'byte_length' => $size,
			'sha256' => $sha256,
		);
	}

	private function resolve_local_file_reference( string $reference, ?string $source_path, ?string $required_extension = null ): ?string {
		if ( str_starts_with( $reference, '//' ) || preg_match( '/^(?:data|blob|javascript):/i', $reference ) ) {
			return null;
		}
		$fragment = wp_parse_url( $reference, PHP_URL_FRAGMENT );
		if ( null !== $fragment ) {
			return null;
		}
		$path = null;
		$scheme = strtolower( (string) wp_parse_url( $reference, PHP_URL_SCHEME ) );
		if ( '' !== $scheme ) {
			if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
				return null;
			}
			foreach ( $this->url_path_roots() as $mapping ) {
				if ( self::url_is_below( $reference, $mapping['url'] ) ) {
					$url_path = rawurldecode( (string) wp_parse_url( $reference, PHP_URL_PATH ) );
					$base_path = rtrim( rawurldecode( (string) wp_parse_url( $mapping['url'], PHP_URL_PATH ) ), '/' ) . '/';
					$path = rtrim( $mapping['path'], DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . ltrim( substr( $url_path, strlen( $base_path ) ), '/' );
					break;
				}
			}
		} elseif ( str_starts_with( $reference, '/' ) && defined( 'ABSPATH' ) ) {
			$site_path = function_exists( 'site_url' ) ? rtrim( (string) wp_parse_url( site_url( '/' ), PHP_URL_PATH ), '/' ) : '';
			$relative = '' !== $site_path && str_starts_with( $reference, $site_path . '/' ) ? substr( $reference, strlen( $site_path ) + 1 ) : ltrim( $reference, '/' );
			$path = rtrim( ABSPATH, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . rawurldecode( (string) wp_parse_url( $relative, PHP_URL_PATH ) );
		} elseif ( null !== $source_path ) {
			$relative_path = rawurldecode( (string) wp_parse_url( $reference, PHP_URL_PATH ) );
			$path = dirname( $source_path ) . DIRECTORY_SEPARATOR . $relative_path;
		}
		$resolved = null !== $path ? realpath( $path ) : false;
		if ( false === $resolved || ! is_file( $resolved ) || ! is_readable( $resolved ) ) {
			return null;
		}
		$approved = false;
		foreach ( $this->approved_file_roots() as $root ) {
			if ( self::path_is_below( $resolved, $root ) ) {
				$approved = true;
				break;
			}
		}
		if ( ! $approved || ( null !== $required_extension && $required_extension !== strtolower( pathinfo( $resolved, PATHINFO_EXTENSION ) ) ) ) {
			return null;
		}
		return $resolved;
	}

	private static function consume_css_construct( string $css, int $index, string $terminator ): int {
		$length = strlen( $css );
		$depth = 0;
		while ( $index < $length ) {
			if ( '"' === $css[ $index ] || "'" === $css[ $index ] ) {
				$index = self::consume_css_string( $css, $index );
				continue;
			}
			if ( '(' === $css[ $index ] ) {
				++$depth;
			} elseif ( ')' === $css[ $index ] && $depth > 0 ) {
				--$depth;
			} elseif ( $terminator === $css[ $index ] && 0 === $depth ) {
				return $index + 1;
			}
			++$index;
		}
		return $length;
	}

	private static function consume_css_parentheses( string $css, int $index ): int {
		$length = strlen( $css );
		$depth = 0;
		while ( $index < $length ) {
			if ( '"' === $css[ $index ] || "'" === $css[ $index ] ) {
				$index = self::consume_css_string( $css, $index );
				continue;
			}
			if ( '(' === $css[ $index ] ) {
				++$depth;
			} elseif ( ')' === $css[ $index ] ) {
				--$depth;
				if ( 0 === $depth ) {
					return $index + 1;
				}
			}
			++$index;
		}
		return $length;
	}

	private static function consume_css_string( string $css, int $index ): int {
		$quote = $css[ $index ];
		$length = strlen( $css );
		++$index;
		while ( $index < $length ) {
			if ( '\\' === $css[ $index ] ) {
				$index += 2;
				continue;
			}
			if ( $quote === $css[ $index ] ) {
				return $index + 1;
			}
			++$index;
		}
		return $length;
	}

	private function asset_id( int $post_id, string $revision, string $kind, string $identity ): string {
		$secret = function_exists( 'wp_salt' ) ? wp_salt( 'auth' ) : __CLASS__;
		$digest = hash_hmac( 'sha256', "v1\n{$post_id}\n{$revision}\n{$kind}\n{$identity}", $secret, true );
		return 'pa_' . rtrim( strtr( base64_encode( $digest ), '+/', '-_' ), '=' );
	}

	private function add_warning_once( array &$warnings, string $code, string $message ): void {
		if ( ! in_array( $code, array_column( $warnings, 'code' ), true ) ) {
			$warnings[] = array( 'code' => $code, 'message' => $message );
		}
	}

	private static function detect_mime_type( string $path ): string {
		if ( function_exists( 'wp_get_image_mime' ) ) {
			return (string) wp_get_image_mime( $path );
		}
		$finfo = function_exists( 'finfo_open' ) ? finfo_open( FILEINFO_MIME_TYPE ) : false;
		if ( false === $finfo ) {
			return '';
		}
		$mime = finfo_file( $finfo, $path );
		finfo_close( $finfo );
		return false === $mime ? '' : (string) $mime;
	}

	private static function sanitize_media( string $media ): string {
		$media = trim( $media );
		return preg_match( '/^[A-Za-z0-9 ():\\/.,_-]{1,120}$/', $media ) ? $media : 'all';
	}

	private static function css_boundary( string $css, int $index ): bool {
		return $index >= strlen( $css ) || ! preg_match( '/[A-Za-z0-9_-]/', $css[ $index ] );
	}

	private static function css_boundary_before( string $css, int $index ): bool {
		return 0 === $index || ! preg_match( '/[A-Za-z0-9_-]/', $css[ $index - 1 ] );
	}

	private static function path_is_below( string $path, string $root ): bool {
		$path = rtrim( $path, DIRECTORY_SEPARATOR );
		$root = rtrim( $root, DIRECTORY_SEPARATOR );
		return $path === $root || str_starts_with( $path, $root . DIRECTORY_SEPARATOR );
	}

	private static function url_is_below( string $url, string $base ): bool {
		$url_scheme  = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		$url_host    = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$url_port    = wp_parse_url( $url, PHP_URL_PORT );
		$base_scheme = strtolower( (string) wp_parse_url( $base, PHP_URL_SCHEME ) );
		$base_host   = strtolower( (string) wp_parse_url( $base, PHP_URL_HOST ) );
		$base_port   = wp_parse_url( $base, PHP_URL_PORT );
		$url_path    = (string) wp_parse_url( $url, PHP_URL_PATH );
		$base_path   = rtrim( (string) wp_parse_url( $base, PHP_URL_PATH ), '/' ) . '/';
		return $url_scheme === $base_scheme && $url_host === $base_host && $url_port === $base_port && str_starts_with( $url_path, $base_path );
	}

	private static function absolute_url( string $url ): string {
		if ( str_starts_with( $url, '//' ) ) {
			$scheme = (string) wp_parse_url( home_url( '/' ), PHP_URL_SCHEME );
			return ( $scheme ?: 'https' ) . ':' . $url;
		}
		if ( str_starts_with( $url, '/' ) ) {
			return home_url( $url );
		}
		return preg_match( '#^https?://#i', $url ) ? $url : '';
	}

	private static function url_origin( string $url ): string {
		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		$host   = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$port   = wp_parse_url( $url, PHP_URL_PORT );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || '' === $host ) {
			return '';
		}
		return $scheme . '://' . $host . ( is_int( $port ) ? ':' . $port : '' );
	}

	private static function language_direction( string $language ): string {
		$primary = strtolower( (string) strtok( str_replace( '_', '-', $language ), '-' ) );
		return in_array( $primary, array( 'ar', 'arc', 'ckb', 'dv', 'fa', 'he', 'ks', 'ps', 'sd', 'ug', 'ur', 'yi' ), true ) ? 'rtl' : 'ltr';
	}
}
