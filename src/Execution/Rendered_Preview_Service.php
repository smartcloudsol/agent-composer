<?php
/* Static, bounded HTML preview generation for Composer-owned drafts. */

namespace SmartCloud\AgentComposer\Execution;

final class Rendered_Preview_Service {
	private const MAX_HTML_BYTES = 500000;

	public function __construct( private readonly Draft_Service $drafts ) {}

	public function get( array $input ): array {
		$post    = $this->drafts->get_owned_draft( absint( $input['post_id'] ?? 0 ) );
		$preview = $this->drafts->get_preview( $post->ID );
		$this->assert_expected_version( $input, $preview );

		$content_language = sanitize_text_field( (string) get_post_meta( $post->ID, Draft_Service::CONTENT_LANGUAGE_META, true ) );
		return $this->build_document( $post, $preview, $content_language ?: 'und' );
	}

	/**
	 * Build the transport-safe static document after draft access is authorized.
	 */
	public function build_document( \WP_Post $post, array $preview, string $content_language ): array {
		$direction        = self::language_direction( $content_language );
		$rendered         = do_blocks( (string) $post->post_content );
		$sanitized        = wp_kses_post( $rendered );
		$warnings         = array(
			array(
				'code'    => 'static_content_preview',
				'message' => 'This preview renders the saved block content without site navigation, template parts, forms, or frontend JavaScript interactions.',
			),
		);
		if ( ! hash_equals( $rendered, $sanitized ) ) {
			$warnings[] = array(
				'code'    => 'active_markup_removed',
				'message' => 'Active or unsupported markup was removed from the embedded preview.',
			);
		}

		$assets = array();
		$body   = $this->normalize_image_assets( $sanitized, $assets, $warnings );
		if ( count( $assets ) > 250 ) {
			throw new Execution_Exception( 'rendered_preview_too_many_assets', 'The rendered preview exceeds the 250-asset response limit.' );
		}
		$html   = sprintf(
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
				'contract_version' => '1',
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
	 * Origins that the MCP Apps preview may load images from.
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

	private function normalize_image_assets( string $html, array &$assets, array &$warnings ): string {
		if ( ! class_exists( '\\WP_HTML_Tag_Processor' ) ) {
			return $html;
		}

		$allowed = self::allowed_asset_origins();
		$tags    = new \WP_HTML_Tag_Processor( $html );
		while ( $tags->next_tag( 'IMG' ) ) {
			$src = trim( html_entity_decode( (string) $tags->get_attribute( 'src' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
			if ( '' === $src ) {
				continue;
			}
			$url    = self::absolute_url( $src );
			$origin = self::url_origin( $url );
			if ( '' === $url || '' === $origin || ! in_array( $origin, $allowed, true ) ) {
				$tags->remove_attribute( 'src' );
				$tags->remove_attribute( 'srcset' );
				if ( ! in_array( 'image_origin_not_allowed', array_column( $warnings, 'code' ), true ) ) {
					$warnings[] = array(
						'code'    => 'image_origin_not_allowed',
						'message' => 'An image outside the site or uploads origin was omitted from the embedded preview.',
					);
				}
				continue;
			}
			$tags->set_attribute( 'src', $url );
			$tags->remove_attribute( 'srcset' );
			$assets[ $url ] = array( 'kind' => 'image', 'url' => $url, 'origin' => $origin );
		}

		$sources = new \WP_HTML_Tag_Processor( $tags->get_updated_html() );
		while ( $sources->next_tag( 'SOURCE' ) ) {
			$sources->remove_attribute( 'src' );
			$sources->remove_attribute( 'srcset' );
		}
		return $sources->get_updated_html();
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
