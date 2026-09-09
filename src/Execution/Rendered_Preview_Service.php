<?php
/* Static, bounded HTML preview generation for Composer-owned drafts. */

namespace SmartCloud\AgentComposer\Execution;

final class Rendered_Preview_Service {
	private const MAX_HTML_BYTES       = 500000;
	private const MAX_ASSETS           = 250;
	private const MAX_IMAGE_BYTES      = 5242880;
	private const MAX_STYLESHEETS      = 16;
	private const MAX_STYLESHEET_BYTES = 524288;
	private const MAX_CSS_BYTES        = 1048576;
	private const ASSET_ID_PATTERN     = '/^pa_[A-Za-z0-9_-]{43}$/';

	/** @var array<string,array<string,mixed>> */
	private array $asset_sources = array();

	public function __construct( private readonly Draft_Service $drafts ) {}

	public function get( array $input ): array {
		$post          = $this->drafts->get_owned_draft( absint( $input['post_id'] ?? 0 ) );
		$preview_start = $this->drafts->get_preview( $post->ID );
		$this->assert_expected_version( $input, $preview_start );
		$language = sanitize_text_field( (string) get_post_meta( $post->ID, Draft_Service::CONTENT_LANGUAGE_META, true ) );
		$result   = $this->build_document( $post, $preview_start, $language ?: 'und' );
		$this->assert_same_version( $preview_start, $this->drafts->get_preview( $post->ID ) );
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
		$this->get( $input );
		$source = $this->asset_sources[ $asset_id ] ?? null;
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
		$path = (string) ( $source['path'] ?? '' );
		$size = '' !== $path && is_file( $path ) && is_readable( $path ) ? filesize( $path ) : false;
		if ( false === $size || $size < 1 || $size > self::MAX_IMAGE_BYTES || $size !== ( $source['byte_length'] ?? -1 ) ) {
			throw new Execution_Exception( 'rendered_preview_asset_changed', 'The rendered preview image changed after the asset manifest was created.' );
		}
		$bytes = file_get_contents( $path );
		if ( false === $bytes || ! hash_equals( (string) $source['sha256'], hash( 'sha256', $bytes ) ) ) {
			throw new Execution_Exception( 'rendered_preview_asset_changed', 'The rendered preview image changed after the asset manifest was created.' );
		}
		return array( 'type' => 'image', 'results' => $bytes, 'mimeType' => (string) $source['mime_type'] );
	}

	/**
	 * Build the transport-safe static document after draft access is authorized.
	 */
	public function build_document( \WP_Post $post, array $preview, string $content_language ): array {
		$this->asset_sources = array();
		$direction = self::language_direction( $content_language );
		$rendered  = do_blocks( (string) $post->post_content );
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
			'<article class="smartcloud-composer-preview" lang="%1$s" dir="%2$s"><header><h1>%3$s</h1></header><div class="smartcloud-composer-preview__content">%4$s</div></article>',
			esc_attr( $content_language ?: 'en' ),
			esc_attr( $direction ),
			esc_html( get_the_title( $post ) ),
			$body
		);
		$byte_length = strlen( $html );
		if ( $byte_length > self::MAX_HTML_BYTES ) {
			throw new Execution_Exception( 'rendered_preview_too_large', 'The rendered preview exceeds the 500000-byte response limit.' );
		}
		return array(
			'post_id'     => $post->ID,
			'edit_url'    => (string) ( $preview['edit_url'] ?? '' ),
			'preview_url' => (string) ( $preview['preview_url'] ?? '' ),
			'validation'  => (array) ( $preview['validation'] ?? array() ),
			'document'    => array(
				'contract_version' => '2',
				'post_id'          => $post->ID,
				'modified_gmt'      => (string) ( $preview['modified_gmt'] ?? '' ),
				'revision'          => (string) ( $preview['revision'] ?? '' ),
				'title'             => wp_strip_all_tags( get_the_title( $post ) ),
				'content_language'  => $content_language,
				'direction'         => $direction,
				'scope'             => 'content',
				'fidelity'          => 'static',
				'mime_type'         => 'text/html',
				'html'              => $html,
				'sha256'            => hash( 'sha256', $html ),
				'byte_length'       => $byte_length,
				'assets'            => array_values( $assets ),
				'warnings'          => array_values( $warnings ),
			),
		);
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
		$expected_modified = trim( (string) ( $input['expected_modified_gmt'] ?? '' ) );
		$expected_revision = strtolower( trim( (string) ( $input['expected_revision'] ?? '' ) ) );
		$current_modified  = (string) ( $preview['modified_gmt'] ?? '' );
		$current_revision  = strtolower( (string) ( $preview['revision'] ?? '' ) );
		if ( '' !== $expected_modified && ! hash_equals( $current_modified, $expected_modified ) ) {
			throw new Execution_Exception( 'edit_conflict', 'The draft modification time changed after it was read. Fetch it again before rendering.' );
		}
		if ( '' !== $expected_revision && ! hash_equals( $current_revision, $expected_revision ) ) {
			throw new Execution_Exception( 'edit_conflict', 'The draft revision changed after it was read. Fetch it again before rendering.' );
		}
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
		if ( function_exists( 'wp_get_global_stylesheet' ) ) {
			$global = (string) wp_get_global_stylesheet();
			if ( '' !== trim( $global ) ) {
				$candidates[] = array( 'handle' => 'global-styles', 'content' => $global, 'media' => 'all', 'identity' => 'global:' . hash( 'sha256', $global ) );
			}
		}
		foreach ( $this->theme_stylesheet_paths() as $path ) {
			$candidates[] = array( 'handle' => 'theme-' . basename( $path, '.css' ), 'path' => $path, 'media' => 'all', 'identity' => 'theme:' . $path );
		}
		foreach ( $this->registered_style_handles( $block_names ) as $handle ) {
			$path = $this->registered_stylesheet_path( $handle );
			if ( null !== $path ) {
				$candidates[] = array( 'handle' => $handle, 'path' => $path, 'media' => 'all', 'identity' => 'handle:' . $handle . ':' . $path );
			}
		}
		$extra = function_exists( 'apply_filters' ) ? apply_filters( 'smartcloud_composer_rendered_preview_stylesheets', array(), $post, $block_names ) : array();
		foreach ( is_array( $extra ) ? $extra : array() as $descriptor ) {
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
				'identity' => 'filter:' . $handle . ':' . $path,
			);
		}

		$seen = array();
		$total_bytes = 0;
		$order = 0;
		foreach ( $candidates as $candidate ) {
			$identity = (string) $candidate['identity'];
			if ( isset( $seen[ $identity ] ) ) {
				continue;
			}
			$seen[ $identity ] = true;
			if ( $order >= self::MAX_STYLESHEETS ) {
				$this->add_warning_once( $warnings, 'stylesheet_limit_reached', 'Additional preview stylesheets were omitted after the 16-file limit.' );
				break;
			}
			$css  = isset( $candidate['content'] ) ? (string) $candidate['content'] : $this->read_stylesheet( (string) ( $candidate['path'] ?? '' ) );
			$css  = self::strip_external_css_references( $css );
			$size = strlen( $css );
			if ( 0 === $size || $size > self::MAX_STYLESHEET_BYTES || $total_bytes + $size > self::MAX_CSS_BYTES ) {
				$this->add_warning_once( $warnings, 'stylesheet_size_limit', 'A preview stylesheet was omitted because the stylesheet or aggregate CSS limit was exceeded.' );
				continue;
			}
			$asset_id = $this->asset_id( $post->ID, $revision, 'stylesheet', $identity . ':' . hash( 'sha256', $css ) );
			$this->asset_sources[ $asset_id ] = array( 'kind' => 'stylesheet', 'content' => $css );
			$assets[ $asset_id ] = array(
				'asset_id' => $asset_id, 'kind' => 'stylesheet', 'mime_type' => 'text/css', 'byte_length' => $size,
				'sha256' => hash( 'sha256', $css ), 'handle' => sanitize_key( (string) $candidate['handle'] ),
				'media' => self::sanitize_media( (string) ( $candidate['media'] ?? 'all' ) ), 'order' => $order,
			);
			$total_bytes += $size;
			++$order;
		}
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
	 * Remove @import rules and replace url() values without regex parsing.
	 */
	private static function strip_external_css_references( string $css ): string {
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
				$index = self::consume_css_construct( $css, $index + 7, ';' );
				continue;
			}
			if ( 0 === strncasecmp( substr( $css, $index, 3 ), 'url', 3 ) && self::css_boundary_before( $css, $index ) ) {
				$cursor = $index + 3;
				while ( $cursor < $length && ctype_space( $css[ $cursor ] ) ) {
					++$cursor;
				}
				if ( $cursor < $length && '(' === $css[ $cursor ] ) {
					$output .= 'none';
					$index = self::consume_css_parentheses( $css, $cursor );
					continue;
				}
			}
			if ( '"' === $css[ $index ] || "'" === $css[ $index ] ) {
				$end = self::consume_css_string( $css, $index );
				$output .= substr( $css, $index, $end - $index );
				$index = $end;
				continue;
			}
			$output .= $css[ $index ];
			++$index;
		}
		return $output;
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
