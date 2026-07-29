<?php
/* SmartCloud Agent Composer execution contract. */

namespace SmartCloud\AgentComposer\Execution;

final class Pattern_Repository {
	private Config_Repository $config;
	private Markup_Contract_Validator $markup_validator;
	private array $resolved = array();
	private array $approved_resolved = array();
	private ?array $theme_patterns = null;

	public function __construct( Config_Repository $config, Markup_Contract_Validator $markup_validator ) {
		$this->config           = $config;
		$this->markup_validator = $markup_validator;
	}

	/**
	 * Resolve a pattern from the WordPress registry or safely load an approved
	 * declarative pattern file from the active child/parent theme.
	 */
	public function resolve( string $pattern_name ): ?array {
		$pattern_name = strtolower( trim( $pattern_name ) );
		if ( ! preg_match( '#^[a-z0-9-]+/[a-z0-9-]+$#', $pattern_name ) ) {
			return null;
		}
		if ( array_key_exists( $pattern_name, $this->resolved ) ) {
			return $this->resolved[ $pattern_name ];
		}

		$registry   = \WP_Block_Patterns_Registry::get_instance();
		$registered = $registry->get_registered( $pattern_name );
		if ( is_array( $registered ) && '' !== trim( (string) ( $registered['content'] ?? '' ) ) ) {
			$registered['_composer_source']       = 'wordpress-registry';
			$registered['_wordpress_registered'] = true;
			$this->resolved[ $pattern_name ]     = $registered;
			return $registered;
		}

		$theme_pattern = $this->theme_patterns()[ $pattern_name ] ?? null;
		if ( ! is_array( $theme_pattern ) ) {
			$this->resolved[ $pattern_name ] = null;
			return null;
		}

		if ( ! $registry->is_registered( $pattern_name ) && function_exists( 'register_block_pattern' ) ) {
			register_block_pattern( $pattern_name, $theme_pattern );
			$registered = $registry->get_registered( $pattern_name );
		}

		if ( is_array( $registered ) && '' !== trim( (string) ( $registered['content'] ?? '' ) ) ) {
			$registered['_composer_source']        = 'theme-fallback-registered';
			$registered['_wordpress_registered'] = true;
			$this->resolved[ $pattern_name ]      = $registered;
			return $registered;
		}

		$theme_pattern['_composer_source']        = 'theme-fallback';
		$theme_pattern['_wordpress_registered'] = false;
		$this->resolved[ $pattern_name ]         = $theme_pattern;
		return $theme_pattern;
	}

	/**
	 * Resolve and structurally validate one blueprint-approved pattern.
	 *
	 * Listing and assembly both call this method, so a pattern cannot appear
	 * usable through discovery and then take a different validation path during
	 * a write request.
	 */
	public function resolve_approved( string $pattern_name, array $blueprint ): ?array {
		$pattern_name = strtolower( trim( $pattern_name ) );
		if ( ! in_array( $pattern_name, $blueprint['allowed_patterns'] ?? array(), true ) ) {
			throw new Execution_Exception( 'pattern_not_allowed', 'A requested pattern is not allowed by this blueprint.' );
		}

		$page_type = sanitize_key( (string) ( $blueprint['page_type'] ?? '' ) );
		$cache_key = $page_type . ':' . $pattern_name;
		if ( array_key_exists( $cache_key, $this->approved_resolved ) ) {
			return $this->approved_resolved[ $cache_key ];
		}

		$pattern = $this->resolve( $pattern_name );
		if ( ! is_array( $pattern ) || '' === trim( (string) ( $pattern['content'] ?? '' ) ) ) {
			$this->approved_resolved[ $cache_key ] = null;
			return null;
		}

		$source = preg_replace(
			'/<!--\s*wpsuite-agent-section:[a-z0-9-]+\/[a-z0-9-]+\s*-->/',
			'',
			(string) $pattern['content']
		);
		$pattern['content'] = is_string( $source ) ? $source : (string) $pattern['content'];

		$report = $this->markup_validator->validate( $pattern_name, $pattern['content'], $blueprint );
		$pattern['_composer_markup_contract_validated'] = ! empty( $report['active'] );

		$this->approved_resolved[ $cache_key ] = $pattern;
		return $pattern;
	}

	/**
	 * Warm the registry for every pattern referenced by a valid blueprint.
	 */
	public function register_approved_patterns(): void {
		$names = array();
		foreach ( $this->config->list_page_types() as $page_type ) {
			try {
				$blueprint = $this->config->get_blueprint( $page_type );
				$names     = array_merge( $names, $blueprint['allowed_patterns'] );
			} catch ( Execution_Exception $error ) {
				do_action( 'smartcloud_composer_pattern_preload_error', $error, $page_type );
			}
		}

		foreach ( array_values( array_unique( $names ) ) as $pattern_name ) {
			$this->resolve( $pattern_name );
		}
	}

	private function theme_patterns(): array {
		if ( null !== $this->theme_patterns ) {
			return $this->theme_patterns;
		}

		$this->theme_patterns = array();
		$theme                = wp_get_theme();
		$themes               = array( $theme );
		if ( $theme->parent() ) {
			$themes[] = $theme->parent();
		}

		foreach ( $themes as $candidate_theme ) {
			$directory = realpath( trailingslashit( $candidate_theme->get_stylesheet_directory() ) . 'patterns' );
			if ( false === $directory || ! is_dir( $directory ) ) {
				continue;
			}
			$files = glob( trailingslashit( $directory ) . '*.php' );
			foreach ( is_array( $files ) ? $files : array() as $file ) {
				$pattern = $this->read_declarative_pattern( $file, $directory );
				if ( ! is_array( $pattern ) || isset( $this->theme_patterns[ $pattern['slug'] ] ) ) {
					continue;
				}
				$slug = $pattern['slug'];
				unset( $pattern['slug'] );
				$this->theme_patterns[ $slug ] = $pattern;
			}
		}

		return $this->theme_patterns;
	}

	private function read_declarative_pattern( string $file, string $directory ): ?array {
		$real_file = realpath( $file );
		$base      = trailingslashit( (string) realpath( $directory ) );
		if ( false === $real_file || ! str_starts_with( $real_file, $base ) || ! is_readable( $real_file ) ) {
			return null;
		}
		$size = filesize( $real_file );
		if ( false === $size || $size < 1 || $size > 1000000 ) {
			return null;
		}

		$headers = get_file_data(
			$real_file,
			array(
				'title'         => 'Title',
				'slug'          => 'Slug',
				'categories'    => 'Categories',
				'description'   => 'Description',
				'inserter'      => 'Inserter',
				'viewportWidth' => 'Viewport Width',
				'blockTypes'    => 'Block Types',
				'postTypes'     => 'Post Types',
				'templateTypes' => 'Template Types',
			)
		);
		$slug = strtolower( trim( (string) ( $headers['slug'] ?? '' ) ) );
		if ( '' === trim( (string) ( $headers['title'] ?? '' ) ) || ! preg_match( '#^[a-z0-9-]+/[a-z0-9-]+$#', $slug ) ) {
			return null;
		}

		$contents = file_get_contents( $real_file ); // phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown
		if ( ! is_string( $contents ) || ! preg_match( '/\A(?:\xEF\xBB\xBF)?\s*<\?php\b.*?\?>\s*/s', $contents, $match ) ) {
			return null;
		}
		$content = substr( $contents, strlen( $match[0] ) );
		if ( '' === trim( $content ) || preg_match( '/<\?(?:php|=)?/i', $content ) ) {
			return null;
		}

		$pattern = array(
			'slug'        => $slug,
			'title'       => sanitize_text_field( (string) $headers['title'] ),
			'description' => sanitize_text_field( (string) ( $headers['description'] ?? '' ) ),
			'categories'  => $this->comma_list( $headers['categories'] ?? '' ),
			'inserter'    => 'no' !== strtolower( trim( (string) ( $headers['inserter'] ?? '' ) ) ),
			'content'     => trim( $content ),
			'source'      => 'theme',
		);

		if ( absint( $headers['viewportWidth'] ?? 0 ) ) {
			$pattern['viewportWidth'] = absint( $headers['viewportWidth'] );
		}
		foreach ( array( 'blockTypes', 'postTypes', 'templateTypes' ) as $list_key ) {
			$list = $this->comma_list( $headers[ $list_key ] ?? '' );
			if ( ! empty( $list ) ) {
				$pattern[ $list_key ] = $list;
			}
		}

		return $pattern;
	}

	private function comma_list( mixed $value ): array {
		return array_values(
			array_filter(
				array_map(
					static fn( string $item ): string => trim( $item ),
					explode( ',', (string) $value )
				)
			)
		);
	}
}
