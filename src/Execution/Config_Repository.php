<?php
/* SmartCloud Agent Composer execution contract. */

namespace SmartCloud\AgentComposer\Execution;

final class Config_Repository {
	private const DEFAULT_MAX_WORDS = 2500;

	private ?array $design_policy = null;
	private ?\SmartCloud\AgentComposer\Infrastructure\Persistence\ActiveConfigurationSource $active_source;

	public function __construct( ?\SmartCloud\AgentComposer\Infrastructure\Persistence\ActiveConfigurationSource $active_source = null ) {
		$this->active_source = $active_source;
	}

	public function get_blueprint( string $page_type ): array {
		$page_type = sanitize_key( $page_type );
		if ( '' === $page_type || ! preg_match( '/^[a-z0-9-]{1,64}$/', $page_type ) ) {
			throw new Execution_Exception( 'invalid_page_type', 'The page type is invalid.' );
		}

		$active_blueprint = $this->active_source?->blueprint( $page_type );
		if ( is_array( $active_blueprint ) ) {
			return $this->normalize_blueprint( $active_blueprint, $page_type );
		}
		throw new Execution_Exception( 'blueprint_not_found', 'No active Composer blueprint exists for this page type.' );
	}

	public function list_page_types(): array {
		return $this->active_source?->page_types() ?? array();
	}

	public function get_design_policy(): array {
		if ( null !== $this->design_policy ) {
			return $this->design_policy;
		}

		$active_policy = $this->active_source?->design_policy();
		$policy        = is_array( $active_policy ) ? $active_policy : array();

		$defaults = array(
			'schema_version'            => 1,
			'content_language'          => '',
			'content_language_enforcement' => 'advisory',
			'content_language_exceptions' => array(),
			'content_language_mismatch_signals' => array(),
			'localization'              => array( 'provider' => 'auto', 'allowed_content_languages' => array() ),
			'allowed_pattern_namespaces' => array( 'wpsuite' ),
			'post_type_contract'         => array( 'page' => 'page' ),
			'content_access'             => array(),
			'content_field_access'       => array(),
			'content_taxonomy_access'    => array(),
			'remote_media_ingest'        => array(
				'enabled'            => false,
				'allowed_hosts'      => array(),
				'allowed_mime_types' => array( 'image/jpeg', 'image/png', 'image/webp', 'image/avif', 'image/gif' ),
				'max_bytes'          => 12582912,
			),
			'disallowed_blocks'         => array(
				'core/html',
				'core/shortcode',
				'core/freeform',
				'core/legacy-widget',
				'core/widget-group',
				'core/embed',
			),
			'constraints'               => array(
				'exactly_one_h1'    => true,
				'inline_css'        => false,
				'custom_html'       => false,
				'shortcodes'        => false,
				'external_embeds'   => false,
				'theme_presets_only' => true,
				'maximum_words'     => self::DEFAULT_MAX_WORDS,
			),
			'block_extensions'          => array(
				'allowed_core_blocks'       => array(),
				'allowed_plugin_namespaces' => array(),
				'registered_block_contracts' => array(),
				'require_registered_blocks' => true,
				'custom_html_contract'      => array(),
				'text_editor_contract'      => array(),
			),
			'seo_contract'              => array(
				'required_fields' => array( 'meta_description' ),
				'excerpt' => array(
					'policy_source'      => 'blueprint.excerpt_policy',
					'allowed_policies'   => array( Excerpt_Policy::REQUIRED, Excerpt_Policy::OPTIONAL, Excerpt_Policy::DISABLED ),
					'minimum_characters' => 80,
					'maximum_characters' => 300,
					'storage'            => 'WordPress post_excerpt',
				),
				'meta_description' => array( 'minimum_characters' => 120, 'maximum_characters' => 160, 'provider' => 'Yoast SEO', 'storage' => '_yoast_wpseo_metadesc' ),
			),
		);

		$policy = array_replace_recursive( $defaults, $policy );
		$policy['content_language'] = $this->normalize_language_tag( $policy['content_language'] ?? '', true );
		$policy['content_language_enforcement'] = in_array( (string) ( $policy['content_language_enforcement'] ?? '' ), array( 'advisory', 'strict' ), true )
			? (string) $policy['content_language_enforcement']
			: 'advisory';
		$policy['content_language_exceptions'] = $this->bounded_string_list( $policy['content_language_exceptions'] ?? array(), 100, 200 );
		$policy['content_language_mismatch_signals'] = $this->bounded_string_list( $policy['content_language_mismatch_signals'] ?? array(), 200, 64 );
		$localization = is_array( $policy['localization'] ?? null ) ? $policy['localization'] : array();
		$provider = sanitize_key( (string) ( $localization['provider'] ?? 'auto' ) );
		$localization['provider'] = '' !== $provider ? $provider : 'auto';
		$localization['allowed_content_languages'] = array_values(
			array_unique(
				array_filter(
					array_map( fn( mixed $tag ): string => $this->normalize_language_allowlist_entry( $tag ), (array) ( $localization['allowed_content_languages'] ?? array() ) )
				)
			)
		);
		if ( '' !== $policy['content_language']
			&& ! in_array( '*', $localization['allowed_content_languages'], true )
			&& ! in_array( $policy['content_language'], $localization['allowed_content_languages'], true ) ) {
			array_unshift( $localization['allowed_content_languages'], $policy['content_language'] );
		}
		$policy['localization'] = $localization;
		if ( 'strict' === $policy['content_language_enforcement'] && '' === $policy['content_language'] ) {
			throw new Execution_Exception( 'strict_content_language_missing', 'Strict content-language enforcement requires a BCP 47 content_language.' );
		}
		$policy['allowed_pattern_namespaces'] = $this->slug_list( $policy['allowed_pattern_namespaces'] );
		$policy['disallowed_blocks']          = $this->block_name_list( $policy['disallowed_blocks'] );
		$policy['post_type_contract']         = $this->post_type_contract( $policy['post_type_contract'] ?? array() );
		$policy['allowed_post_types']         = array_values( array_unique( array_values( $policy['post_type_contract'] ) ) );
		$policy['content_access']             = $this->content_access_policy( $policy['content_access'] ?? array(), $policy['allowed_post_types'] );
		$policy['content_field_access']       = $this->content_field_access_policy( $policy['content_field_access'] ?? array(), $policy['allowed_post_types'] );
		$policy['content_taxonomy_access']    = $this->content_taxonomy_access_policy( $policy['content_taxonomy_access'] ?? array(), $policy['allowed_post_types'] );
		$policy['remote_media_ingest']        = $this->normalize_remote_media_ingest( $policy['remote_media_ingest'] ?? array() );
		$policy['block_extensions']            = $this->normalize_block_extensions( $policy['block_extensions'] ?? array() );
		$policy['seo_contract']['required_fields'] = array_values(
			array_unique(
				array_filter(
					array_map( 'sanitize_key', (array) ( $policy['seo_contract']['required_fields'] ?? array() ) ),
					static fn( string $field ): bool => 'excerpt' !== $field
				)
			)
		);
		$policy['seo_contract']['excerpt'] = array_replace(
			(array) ( $policy['seo_contract']['excerpt'] ?? array() ),
			array(
				'policy_source'      => 'blueprint.excerpt_policy',
				'allowed_policies'   => array( Excerpt_Policy::REQUIRED, Excerpt_Policy::OPTIONAL, Excerpt_Policy::DISABLED ),
				'minimum_characters' => 80,
				'maximum_characters' => 300,
				'storage'            => 'WordPress post_excerpt',
			)
		);
		$policy['constraints']['maximum_words'] = min(
			10000,
			max( 1, (int) $policy['constraints']['maximum_words'] )
		);

		$filtered = apply_filters( 'smartcloud_composer_design_policy', $policy );
		if ( ! is_array( $filtered ) ) {
			throw new Execution_Exception( 'invalid_filtered_design_policy', 'The filtered design policy must remain an object.' );
		}
		$filtered['disallowed_blocks'] = array_values(
			array_unique( array_merge( (array) ( $filtered['disallowed_blocks'] ?? array() ), array( 'core/html' ) ) )
		);
		$filtered['constraints']['custom_html'] = false;
		$filtered['block_extensions']['allowed_core_blocks'] = array_values(
			array_diff( (array) ( $filtered['block_extensions']['allowed_core_blocks'] ?? array() ), array( 'core/html' ) )
		);
		$filtered['block_extensions']['core_html_javascript'] = false;
		$filtered['block_extensions']['custom_html_contract'] = array();
		$this->design_policy = $filtered;
		return $this->design_policy;
	}

	/** @return array{enabled:bool,allowed_hosts:list<string>,allowed_mime_types:list<string>,max_bytes:int} */
	public function get_remote_media_ingest_policy(): array {
		return $this->get_design_policy()['remote_media_ingest'];
	}

	private function normalize_remote_media_ingest( mixed $value ): array {
		$value = is_array( $value ) ? $value : array();
		$hosts = array();
		foreach ( (array) ( $value['allowed_hosts'] ?? array() ) as $host ) {
			$host = strtolower( trim( (string) $host, ". \t\n\r\0\x0B" ) );
			if ( preg_match( '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?))*$/', $host ) ) {
				$hosts[] = $host;
			}
		}
		$allowed_mimes = array_values(
			array_intersect(
				array( 'image/jpeg', 'image/png', 'image/webp', 'image/avif', 'image/gif' ),
				array_map( 'sanitize_mime_type', (array) ( $value['allowed_mime_types'] ?? array() ) )
			)
		);
		return array(
			'enabled'            => true === ( $value['enabled'] ?? false ) && ! empty( $hosts ) && ! empty( $allowed_mimes ),
			'allowed_hosts'      => array_values( array_unique( $hosts ) ),
			'allowed_mime_types' => $allowed_mimes,
			'max_bytes'          => min( 26214400, max( 1024, (int) ( $value['max_bytes'] ?? 12582912 ) ) ),
		);
	}

	/**
	 * Return the active theme-owned markup contract without rewriting it.
	 *
	 * Themes without a markup contract retain the Composer 0.3.0 behavior. Once
	 * the key exists, its required class lists and rules fail closed.
	 */
	public function get_markup_contract(): ?array {
		return $this->markup_contract_from_policy( $this->get_design_policy() );
	}

	public function get_allowed_post_types(): array {
		$policy = $this->get_design_policy();
		return isset( $policy['allowed_post_types'] ) && is_array( $policy['allowed_post_types'] )
			? array_values( array_unique( array_filter( array_map( 'sanitize_key', $policy['allowed_post_types'] ) ) ) )
			: array( 'page' );
	}

	/** @return array{discover:bool,read:bool,clone:bool,adopt_drafts:bool,propose_updates:bool} */
	public function get_content_access( string $post_type ): array {
		$post_type = sanitize_key( $post_type );
		$policy = $this->get_design_policy();
		$access = is_array( $policy['content_access'][ $post_type ] ?? null ) ? $policy['content_access'][ $post_type ] : array();
		return array(
			'discover'     => true === ( $access['discover'] ?? false ),
			'read'         => true === ( $access['read'] ?? false ),
			'clone'        => true === ( $access['clone'] ?? false ),
			'adopt_drafts' => true === ( $access['adopt_drafts'] ?? false ),
			'propose_updates' => true === ( $access['propose_updates'] ?? false ),
		);
	}

	/** @return array<string,array{read:bool,write:bool}> */
	public function get_content_field_access( string $post_type ): array {
		$post_type = sanitize_key( $post_type );
		$policy    = $this->get_design_policy();
		return is_array( $policy['content_field_access'][ $post_type ] ?? null )
			? $policy['content_field_access'][ $post_type ]
			: array();
	}

	/** @return array<string,array{search:bool,assign:bool,create:bool,maximum_items:int,assignment_mode:string,creation_parent_policy:string,creation_parent_slugs:list<string>}> */
	public function get_content_taxonomy_access( string $post_type ): array {
		$post_type = sanitize_key( $post_type );
		$policy    = $this->get_design_policy();
		return is_array( $policy['content_taxonomy_access'][ $post_type ] ?? null )
			? $policy['content_taxonomy_access'][ $post_type ]
			: array();
	}

	public function get_block_extensions(): array {
		$policy = $this->get_design_policy();
		return isset( $policy['block_extensions'] ) && is_array( $policy['block_extensions'] )
			? $policy['block_extensions']
			: array(
				'allowed_core_blocks'       => array(),
				'allowed_plugin_namespaces' => array(),
				'registered_block_contracts' => array(),
				'require_registered_blocks' => true,
				'custom_html_contract'      => array(),
				'core_html_javascript'      => false,
				'passive_text_editor_html'  => false,
				'captioned_media_image_materializer' => false,
				'query_loop_materializer' => array(
					'enabled'                 => false,
					'allowed_post_types'      => array(),
					'allowed_taxonomies'      => array(),
					'allowed_orderby'         => array( 'date' ),
					'allowed_template_blocks' => array( 'core/post-title' ),
					'allowed_sticky_modes'     => array( 'include' ),
					'max_per_page'            => 12,
					'max_offset'              => 100,
				),
				'text_editor_contract'      => array(
					'source'                       => '',
					'materialized_block'           => 'core/freeform',
					'passive_html_only'            => false,
					'preserve_meaningful_wrappers' => true,
					'allowed_examples'             => array(),
					'forbidden_content'            => array(),
				),
			);
	}

	public function get_theme_context(): array {
		$theme     = wp_get_theme();
		$raw_theme = array();
		$policy    = $this->get_design_policy();
		$contract  = $this->markup_contract_from_policy( $policy );

		if ( class_exists( '\\WP_Theme_JSON_Resolver' ) ) {
			$theme_json = \WP_Theme_JSON_Resolver::get_merged_data();
			if ( is_object( $theme_json ) && method_exists( $theme_json, 'get_raw_data' ) ) {
				$raw_theme = $theme_json->get_raw_data();
			}
		}

		return array(
			'composer'      => array(
				'version'                    => SMARTCLOUD_COMPOSER_VERSION,
				'execution_contract'         => Abilities::CONTRACT,
				'assembly_format'            => 'root-block-metadata',
				'markup_contract_validation' => null !== $contract,
				'block_ast_extensions'       => ! empty( $policy['block_extensions']['allowed_core_blocks'] )
					|| ! empty( $policy['block_extensions']['allowed_plugin_namespaces'] ),
				'seo_metadata_format'        => 'wordpress-excerpt+yoast-metadesc',
			),
			'theme'         => array(
				'name'       => $theme->get( 'Name' ),
				'version'    => $theme->get( 'Version' ),
				'stylesheet' => $theme->get_stylesheet(),
				'template'   => $theme->get_template(),
			),
			'settings'      => isset( $raw_theme['settings'] ) && is_array( $raw_theme['settings'] )
				? $this->safe_theme_data( $raw_theme['settings'] )
				: array(),
			'styles'        => isset( $raw_theme['styles'] ) && is_array( $raw_theme['styles'] )
				? $this->safe_theme_data( $raw_theme['styles'] )
				: array(),
			'page_types'    => $this->list_page_types(),
			'design_policy' => $policy,
		);
	}

	private function normalize_blueprint( array $blueprint, string $page_type ): array {
		if ( isset( $blueprint['page_type'] ) && $page_type !== sanitize_key( (string) $blueprint['page_type'] ) ) {
			throw new Execution_Exception( 'blueprint_type_mismatch', 'The blueprint page_type does not match its filename.' );
		}

		$policy                         = $this->get_design_policy();
		$blueprint['schema_version']    = isset( $blueprint['schema_version'] ) ? (int) $blueprint['schema_version'] : 1;
		$blueprint['page_type']         = $page_type;
		$composition_mode               = (string) ( $blueprint['composition_mode'] ?? 'document' );
		if ( ! in_array( $composition_mode, array( 'document', 'structured-record' ), true ) ) {
			throw new Execution_Exception( 'invalid_composition_mode', 'Blueprint composition_mode must be document or structured-record.' );
		}
		$blueprint['composition_mode'] = $composition_mode;
		$blueprint['published_update_policy'] = 'proposal-only' === (string) ( $blueprint['published_update_policy'] ?? 'disabled' )
			? 'proposal-only'
			: 'disabled';
		$site_language = (string) ( $policy['content_language'] ?? '' );
		$blueprint_language = $this->normalize_language_tag( $blueprint['content_language'] ?? '', true );
		$allowed_site_languages = (array) ( $policy['localization']['allowed_content_languages'] ?? ( '' !== $site_language ? array( $site_language ) : array() ) );
		if ( '' !== $blueprint_language && '' !== $site_language && ! in_array( '*', $allowed_site_languages, true ) && strtok( strtolower( $blueprint_language ), '-' ) !== strtok( strtolower( $site_language ), '-' ) ) {
			throw new Execution_Exception( 'blueprint_content_language_conflict', 'A Blueprint may narrow but cannot override the Site Contract content language.' );
		}
		$blueprint['content_language'] = '' !== $blueprint_language ? $blueprint_language : $site_language;
		$allowed_blueprint_languages = array_values(
			array_unique(
				array_filter(
					array_map( fn( mixed $tag ): string => $this->normalize_language_allowlist_entry( $tag ), (array) ( $blueprint['allowed_content_languages'] ?? array() ) )
				)
			)
		);
		if ( empty( $allowed_blueprint_languages ) && '' !== $blueprint['content_language'] ) {
			$allowed_blueprint_languages[] = $blueprint['content_language'];
		}
		if ( ! in_array( '*', $allowed_site_languages, true ) && array_diff( $allowed_blueprint_languages, $allowed_site_languages ) ) {
			throw new Execution_Exception( 'blueprint_content_language_not_allowed', 'A Blueprint language must be explicitly allowed by the Site Contract localization policy.' );
		}
		if ( in_array( '*', $allowed_blueprint_languages, true ) && ! in_array( '*', $allowed_site_languages, true ) ) {
			throw new Execution_Exception( 'blueprint_content_language_not_allowed', 'A Blueprint wildcard requires the Site Contract localization wildcard.' );
		}
		$blueprint['allowed_content_languages'] = $allowed_blueprint_languages;
		$blueprint['content_language_enforcement'] = (string) $policy['content_language_enforcement'];
		$blueprint['content_language_mismatch_signals'] = (array) ( $policy['content_language_mismatch_signals'] ?? array() );
		$blueprint['content_language_exceptions'] = array_values(
			array_unique(
				array_merge(
					(array) $policy['content_language_exceptions'],
					$this->bounded_string_list( $blueprint['content_language_exceptions'] ?? array(), 100, 200 )
				)
			)
		);
		$contract_post_type             = (string) ( $policy['post_type_contract'][ $page_type ] ?? '' );
		$blueprint['target_post_type']  = $this->normalize_post_type( $blueprint['target_post_type'] ?? ( '' !== $contract_post_type ? $contract_post_type : 'page' ) );
		$target_template                = isset( $blueprint['target_template'] )
			? $blueprint['target_template']
			: $this->target_template_from_policy( $policy, $page_type, $blueprint['target_post_type'] );
		$blueprint['target_template']   = $this->normalize_target_template( $target_template );
		$blueprint['allowed_patterns']   = $this->pattern_name_list( $blueprint['allowed_patterns'] ?? array() );
		$blueprint['required_sequence']  = $this->pattern_name_list( $blueprint['required_sequence'] ?? array() );
		$blueprint['allowed_blocks']     = $this->block_name_list( $blueprint['allowed_blocks'] ?? array() );
		if ( in_array( 'core/html', $blueprint['allowed_blocks'], true ) ) {
			throw new Execution_Exception( 'blueprint_custom_html_block_forbidden', 'Custom HTML blocks cannot be enabled by a Blueprint.' );
		}
		$blueprint['excerpt_policy']     = Excerpt_Policy::normalize( $blueprint['excerpt_policy'] ?? $blueprint['excerpt'] ?? Excerpt_Policy::OPTIONAL );
		$blueprint['reference_page_ids'] = array_values(
			array_unique(
				array_filter( array_map( 'absint', $blueprint['reference_page_ids'] ?? array() ) )
			)
		);

		if ( 'document' === $composition_mode && empty( $blueprint['allowed_patterns'] ) ) {
			throw new Execution_Exception( 'blueprint_has_no_patterns', 'The blueprint must allow at least one pattern.' );
		}
		if ( 'document' === $composition_mode && empty( $blueprint['required_sequence'] ) ) {
			throw new Execution_Exception( 'blueprint_has_no_sequence', 'The blueprint must define a required pattern sequence.' );
		}
		if ( 'structured-record' === $composition_mode && ( ! empty( $blueprint['allowed_patterns'] ) || ! empty( $blueprint['required_sequence'] ) ) ) {
			throw new Execution_Exception( 'structured_record_patterns_forbidden', 'Structured-record Blueprints cannot declare a Gutenberg pattern composition.' );
		}
		if ( array_diff( $blueprint['required_sequence'], $blueprint['allowed_patterns'] ) ) {
			throw new Execution_Exception( 'blueprint_sequence_not_allowed', 'Every required pattern must also be allowed.' );
		}
		if ( 'document' === $composition_mode && empty( $blueprint['allowed_blocks'] ) ) {
			throw new Execution_Exception( 'blueprint_has_no_blocks', 'The blueprint must allow at least one block type.' );
		}
		if ( 'structured-record' === $composition_mode && ! empty( $blueprint['allowed_blocks'] ) ) {
			throw new Execution_Exception( 'structured_record_blocks_forbidden', 'Structured-record Blueprints cannot declare body blocks.' );
		}
		$markup_contract    = $this->markup_contract_from_policy( $policy );
		if ( '' === $contract_post_type ) {
			throw new Execution_Exception( 'blueprint_post_type_contract_missing', 'The design policy does not define a post type contract for this blueprint.' );
		}
		if ( $blueprint['target_post_type'] !== $contract_post_type ) {
			throw new Execution_Exception( 'blueprint_post_type_contract_mismatch', 'The blueprint target post type does not match the design policy contract.' );
		}
		if ( null !== $markup_contract && 'document' === $composition_mode ) {
			$this->assert_descriptive_string_list(
				$blueprint['layout_contract'] ?? null,
				'invalid_blueprint_layout_contract',
				'The blueprint must expose a non-empty layout_contract when markup-contract validation is active.'
			);
		}
		if ( null !== $markup_contract && 'post' === $blueprint['target_post_type'] ) {
			$this->assert_post_template_contract( $policy['template_contract']['blog_posts'] ?? null, $blueprint['target_template'] );
		}

		foreach ( $blueprint['allowed_patterns'] as $pattern_name ) {
			$namespace = strstr( $pattern_name, '/', true );
			if ( ! in_array( $namespace, $policy['allowed_pattern_namespaces'], true ) ) {
				throw new Execution_Exception( 'blueprint_pattern_namespace_not_allowed', 'The blueprint contains a pattern namespace that is not allowed by the design policy.' );
			}
		}
		$blueprint_constraints = $this->overlay_explicit(
			$policy['constraints'],
			isset( $blueprint['constraints'] ) && is_array( $blueprint['constraints'] )
				? $blueprint['constraints']
				: array()
		);
		$blueprint_constraints['maximum_words'] = max( 1, (int) $blueprint_constraints['maximum_words'] );
		if ( ! empty( $blueprint_constraints['custom_html'] ) ) {
			throw new Execution_Exception( 'blueprint_custom_html_forbidden', 'Custom HTML cannot be enabled by a Blueprint.' );
		}
		$blueprint_constraints['custom_html'] = false;
		if ( 'structured-record' === $composition_mode ) {
			$blueprint_constraints['exactly_one_h1'] = false;
		}
		$blueprint['constraints']  = $blueprint_constraints;
		$blueprint['seo_contract'] = $this->overlay_explicit(
			$policy['seo_contract'],
			isset( $blueprint['seo_contract'] ) && is_array( $blueprint['seo_contract'] )
				? $blueprint['seo_contract']
				: array()
		);
		$blueprint_block_extensions = isset( $blueprint['block_extensions'] ) && is_array( $blueprint['block_extensions'] )
			? $blueprint['block_extensions']
			: array();
		$blueprint['block_extensions'] = $this->normalize_block_extensions(
			$this->overlay_explicit(
				$policy['block_extensions'],
				$blueprint_block_extensions
			)
		);
		if ( ! array_key_exists( 'query_loop_materializer', $blueprint_block_extensions ) ) {
			$blueprint['block_extensions']['query_loop_materializer']['enabled'] = false;
		}

		return $blueprint;
	}

	private function overlay_explicit( array $defaults, array $overrides ): array {
		foreach ( $overrides as $key => $value ) {
			if (
				is_array( $value )
				&& ! array_is_list( $value )
				&& isset( $defaults[ $key ] )
				&& is_array( $defaults[ $key ] )
				&& ! array_is_list( $defaults[ $key ] )
			) {
				$defaults[ $key ] = $this->overlay_explicit( $defaults[ $key ], $value );
				continue;
			}
			$defaults[ $key ] = $value;
		}
		return $defaults;
	}

	private function pattern_name_list( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$result = array();
		foreach ( $value as $name ) {
			$name = strtolower( trim( (string) $name ) );
			if ( preg_match( '#^[a-z0-9-]+/[a-z0-9-]+$#', $name ) ) {
				$result[] = $name;
			}
		}
		return array_values( array_unique( $result ) );
	}

	private function block_name_list( mixed $value ): array {
		return $this->pattern_name_list( $value );
	}

	private function normalize_block_extensions( mixed $value ): array {
		$value = is_array( $value ) ? $value : array();
		$core  = $this->block_name_list( $value['allowed_core_blocks'] ?? array() );
		$core  = array_values(
			array_filter(
				$core,
				static fn( string $name ): bool => str_starts_with( $name, 'core/' )
			)
		);
		$namespaces = $this->slug_list( $value['allowed_plugin_namespaces'] ?? array() );
		$contract   = isset( $value['custom_html_contract'] ) && is_array( $value['custom_html_contract'] )
			? $value['custom_html_contract']
			: array();
		$legacy_passive_text_editor  = 'passive-only' === (string) ( $contract['text_editor_html'] ?? '' );
		$legacy_captioned_image      = 'core-image-figcaption' === (string) ( $contract['image_caption'] ?? '' );
		$text_editor_contract        = $this->normalize_text_editor_contract(
			$value['text_editor_contract'] ?? array(),
			$legacy_passive_text_editor
		);
		$passive_text_editor_html    = array_key_exists( 'passive_text_editor_html', $value )
			? (bool) $value['passive_text_editor_html']
			: ( $legacy_passive_text_editor || ! empty( $text_editor_contract['passive_html_only'] ) );
		$captioned_image             = array_key_exists( 'captioned_media_image_materializer', $value )
			? (bool) $value['captioned_media_image_materializer']
			: $legacy_captioned_image;
		$query_loop                   = $this->normalize_query_loop_materializer( $value['query_loop_materializer'] ?? array() );
		$registered_block_contracts  = $this->normalize_registered_block_contracts( $value['registered_block_contracts'] ?? array() );

		return array(
			'allowed_core_blocks'       => array_values( array_diff( $core, array( 'core/html' ) ) ),
			'allowed_plugin_namespaces' => $namespaces,
			'registered_block_contracts' => $registered_block_contracts,
			'require_registered_blocks' => true,
			'custom_html_contract'      => array(),
			'core_html_javascript'      => false,
			'passive_text_editor_html'  => $passive_text_editor_html,
			'captioned_media_image_materializer' => $captioned_image,
			'query_loop_materializer'   => $query_loop,
			'text_editor_contract'      => $text_editor_contract,
		);
	}

	/**
	 * Normalize declarative contracts for registered third-party blocks.
	 *
	 * These contracts do not grant access on their own. The exact namespace and
	 * block must still be enabled by the Site Contract and selected Blueprint.
	 */
	private function normalize_registered_block_contracts( mixed $value ): array {
		if ( ! is_array( $value ) || count( $value ) > 100 ) {
			return array();
		}

		$result = array();
		foreach ( $value as $block_name => $contract ) {
			$block_name = strtolower( trim( (string) $block_name ) );
			if ( ! preg_match( '#^[a-z0-9-]+/[a-z0-9-]+$#', $block_name ) || str_starts_with( $block_name, 'core/' ) || ! is_array( $contract ) ) {
				continue;
			}

			$rendering = (string) ( $contract['rendering'] ?? 'server' );
			if ( ! in_array( $rendering, array( 'server', 'saved' ), true ) ) {
				continue;
			}

			$attributes = array();
			foreach ( (array) ( $contract['attributes'] ?? array() ) as $attribute => $schema ) {
				$attribute = (string) $attribute;
				if ( ! preg_match( '/^[A-Za-z][A-Za-z0-9_-]{0,127}$/', $attribute ) || ! is_array( $schema ) ) {
					continue;
				}
				$type = (string) ( $schema['type'] ?? '' );
				if ( ! in_array( $type, array( 'string', 'integer', 'number', 'boolean', 'array', 'object' ), true ) ) {
					continue;
				}
				$normalized = array( 'type' => $type );
				if ( isset( $schema['allowed'] ) && is_array( $schema['allowed'] ) && count( $schema['allowed'] ) <= 100 ) {
					$normalized['enum'] = array_values( $schema['allowed'] );
				}
				if ( array_key_exists( 'fixed', $schema ) ) {
					$normalized['const'] = $schema['fixed'];
					$normalized['required'] = true;
				}
				if ( true === ( $schema['required'] ?? false ) ) {
					$normalized['required'] = true;
				}
				if ( isset( $schema['minimum'] ) && is_numeric( $schema['minimum'] ) ) {
					$normalized['minimum'] = (float) $schema['minimum'];
				}
				if ( isset( $schema['maximum'] ) && is_numeric( $schema['maximum'] ) ) {
					$normalized['maximum'] = (float) $schema['maximum'];
				}
				$attributes[ $attribute ] = $normalized;
			}

			$result[ $block_name ] = array(
				'rendering'  => $rendering,
				'attributes' => $attributes,
			);
		}
		ksort( $result );
		return $result;
	}

	private function normalize_query_loop_materializer( mixed $value ): array {
		$value = is_array( $value ) ? $value : array();
		$allowed_orderby = array_values(
			array_intersect(
				array( 'date', 'modified', 'title', 'menu_order' ),
				$this->slug_list( $value['allowed_orderby'] ?? array( 'date' ) )
			)
		);
		$allowed_template_blocks = array_values(
			array_intersect(
				array( 'core/post-title', 'core/post-excerpt', 'core/post-date', 'core/post-featured-image', 'core/post-author-name' ),
				$this->block_name_list( $value['allowed_template_blocks'] ?? array( 'core/post-title' ) )
			)
		);

		return array(
			'enabled'                 => true === ( $value['enabled'] ?? false ),
			'allowed_post_types'      => $this->slug_list( $value['allowed_post_types'] ?? array() ),
			'allowed_taxonomies'      => $this->slug_list( $value['allowed_taxonomies'] ?? array() ),
			'allowed_orderby'         => ! empty( $allowed_orderby ) ? $allowed_orderby : array( 'date' ),
			'allowed_template_blocks' => ! empty( $allowed_template_blocks ) ? $allowed_template_blocks : array( 'core/post-title' ),
			'allowed_sticky_modes'     => array_values(
				array_intersect(
					array( 'include', 'only', 'exclude' ),
					$this->slug_list( $value['allowed_sticky_modes'] ?? array( 'include' ) )
				)
			),
			'max_per_page'            => min( 24, max( 1, absint( $value['max_per_page'] ?? 12 ) ) ),
			'max_offset'              => min( 500, max( 0, absint( $value['max_offset'] ?? 100 ) ) ),
		);
	}

	private function normalize_text_editor_contract( mixed $value, bool $legacy_passive_text_editor ): array {
		$value = is_array( $value ) ? $value : array();

		return array(
			'source'                       => sanitize_text_field( (string) ( $value['source'] ?? 'design-policy.block_extensions' ) ),
			'materialized_block'           => $this->normalize_text_editor_materialized_block( $value['materialized_block'] ?? 'core/freeform' ),
			'passive_html_only'            => array_key_exists( 'passive_html_only', $value )
				? (bool) $value['passive_html_only']
				: $legacy_passive_text_editor,
			'preserve_meaningful_wrappers' => array_key_exists( 'preserve_meaningful_wrappers', $value )
				? (bool) $value['preserve_meaningful_wrappers']
				: true,
			'allowed_examples'             => $this->descriptive_string_list( $value['allowed_examples'] ?? array() ),
			'forbidden_content'            => $this->descriptive_string_list( $value['forbidden_content'] ?? array() ),
		);
	}

	private function normalize_text_editor_materialized_block( mixed $value ): string {
		$block = strtolower( trim( (string) $value ) );
		return 'core/freeform' === $block ? $block : 'core/freeform';
	}

	private function descriptive_string_list( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$result = array();
		foreach ( $value as $item ) {
			$item = sanitize_text_field( (string) $item );
			if ( '' !== $item ) {
				$result[] = $item;
			}
		}
		return array_values( array_unique( $result ) );
	}

	private function bounded_string_list( mixed $value, int $maximum_items, int $maximum_length ): array {
		if ( ! is_array( $value ) || count( $value ) > $maximum_items ) {
			return array();
		}
		$result = array();
		foreach ( $value as $item ) {
			if ( ! is_string( $item ) ) {
				continue;
			}
			$item = trim( wp_strip_all_tags( $item ) );
			if ( '' !== $item && strlen( $item ) <= $maximum_length ) {
				$result[] = $item;
			}
		}
		return array_values( array_unique( $result ) );
	}

	private function normalize_language_tag( mixed $value, bool $allow_empty ): string {
		$value = trim( (string) $value );
		if ( '' === $value && $allow_empty ) {
			return '';
		}
		if ( ! preg_match( '/^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$/', $value ) ) {
			throw new Execution_Exception( 'invalid_content_language', 'content_language must be a valid bounded BCP 47 language tag.' );
		}
		$parts = explode( '-', $value );
		$parts[0] = strtolower( $parts[0] );
		for ( $index = 1; $index < count( $parts ); ++$index ) {
			$parts[ $index ] = 2 === strlen( $parts[ $index ] ) ? strtoupper( $parts[ $index ] ) : $parts[ $index ];
		}
		return implode( '-', $parts );
	}

	private function normalize_language_allowlist_entry( mixed $value ): string {
		return '*' === trim( (string) $value ) ? '*' : $this->normalize_language_tag( $value, true );
	}

	private function slug_list( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		return array_values( array_unique( array_filter( array_map( static fn( mixed $item ): string => sanitize_key( (string) $item ), $value ) ) ) );
	}

	/** @param string[] $allowed_post_types */
	private function content_access_policy( mixed $value, array $allowed_post_types ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$result = array();
		foreach ( $value as $post_type => $rules ) {
			$post_type = sanitize_key( (string) $post_type );
			if ( ! in_array( $post_type, $allowed_post_types, true ) || ! is_array( $rules ) ) {
				continue;
			}
			$result[ $post_type ] = array(
				'discover'     => true === ( $rules['discover'] ?? false ),
				'read'         => true === ( $rules['read'] ?? false ),
				'clone'        => true === ( $rules['clone'] ?? false ),
				'adopt_drafts' => true === ( $rules['adopt_drafts'] ?? false ),
				'propose_updates' => true === ( $rules['propose_updates'] ?? false ),
			);
			if ( $result[ $post_type ]['propose_updates'] ) {
				$result[ $post_type ]['read'] = true;
				$result[ $post_type ]['discover'] = true;
			}
			if ( $result[ $post_type ]['clone'] ) {
				$result[ $post_type ]['read'] = true;
				$result[ $post_type ]['discover'] = true;
			}
			if ( $result[ $post_type ]['read'] || $result[ $post_type ]['adopt_drafts'] ) {
				$result[ $post_type ]['discover'] = true;
			}
		}
		return $result;
	}

	/** @param string[] $allowed_post_types */
	private function content_field_access_policy( mixed $value, array $allowed_post_types ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$result = array();
		foreach ( $value as $post_type => $fields ) {
			$post_type = sanitize_key( (string) $post_type );
			if ( ! in_array( $post_type, $allowed_post_types, true ) || ! is_array( $fields ) ) {
				continue;
			}
			foreach ( $fields as $meta_key => $rules ) {
				$meta_key = trim( (string) $meta_key );
				if (
					'' === $meta_key
					|| '_' === $meta_key[0]
					|| strlen( $meta_key ) > 255
					|| ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.:-]*$/', $meta_key )
					|| ! is_array( $rules )
				) {
					continue;
				}
				$write = true === ( $rules['write'] ?? false );
				$read  = $write || true === ( $rules['read'] ?? false );
				if ( $read ) {
					$normalized = array( 'read' => true, 'write' => $write );
					if ( 'relation' === ( $rules['semantic_type'] ?? '' ) ) {
						$targets = $this->slug_list( $rules['target_post_types'] ?? array() );
						if ( ! empty( $targets ) ) {
							$cardinality = 'one' === ( $rules['cardinality'] ?? '' ) ? 'one' : 'many';
							$normalized += array(
								'semantic_type'    => 'relation',
								'cardinality'      => $cardinality,
								'ordered'          => 'many' === $cardinality && true === ( $rules['ordered'] ?? false ),
								'target_post_types' => $targets,
								'target_post_statuses' => ! empty( $this->slug_list( $rules['target_post_statuses'] ?? array( 'publish' ) ) )
									? $this->slug_list( $rules['target_post_statuses'] ?? array( 'publish' ) )
									: array( 'publish' ),
								'maximum_items'    => 'many' === $cardinality ? min( 100, max( 1, absint( $rules['maximum_items'] ?? 20 ) ) ) : 1,
								'storage'          => array(
									'provider'     => 'native-meta',
									'value_format' => 'post_id',
								),
							);
						}
					}
					$result[ $post_type ][ $meta_key ] = $normalized;
				}
			}
		}
		return $result;
	}

	/** @param string[] $allowed_post_types */
	private function content_taxonomy_access_policy( mixed $value, array $allowed_post_types ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$result = array();
		foreach ( $value as $post_type => $taxonomies ) {
			$post_type = sanitize_key( (string) $post_type );
			if ( ! in_array( $post_type, $allowed_post_types, true ) || ! is_array( $taxonomies ) ) {
				continue;
			}
			foreach ( $taxonomies as $taxonomy => $rules ) {
				$taxonomy = sanitize_key( (string) $taxonomy );
				if ( '' === $taxonomy || ! is_array( $rules ) ) {
					continue;
				}
				$create = true === ( $rules['create'] ?? false );
				$assign = $create || true === ( $rules['assign'] ?? false );
				$search = $assign || true === ( $rules['search'] ?? false );
				if ( ! $search ) {
					continue;
				}
				$parent_policy = $create && 'allowlist' === ( $rules['creation_parent_policy'] ?? '' ) ? 'allowlist' : 'root-only';
				$parent_slugs  = array_slice( $this->slug_list( $rules['creation_parent_slugs'] ?? array() ), 0, 100 );
				$result[ $post_type ][ $taxonomy ] = array(
					'search'                 => true,
					'assign'                 => $assign,
					'create'                 => $create,
					'maximum_items'          => min( 100, max( 1, absint( $rules['maximum_items'] ?? 20 ) ) ),
					'assignment_mode'        => 'append' === ( $rules['assignment_mode'] ?? '' ) ? 'append' : 'replace',
					'creation_parent_policy' => $parent_policy,
					'creation_parent_slugs'  => 'allowlist' === $parent_policy ? $parent_slugs : array(),
				);
			}
		}
		return $result;
	}

	private function post_type_contract( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array( 'page' => 'page' );
		}

		$result = array();
		foreach ( $value as $page_type => $post_type ) {
			$page_type = sanitize_key( (string) $page_type );
			$post_type = sanitize_key( (string) $post_type );
			if ( '' !== $page_type && $this->is_valid_post_type_name( $post_type ) ) {
				$result[ $page_type ] = $post_type;
			}
		}

		return empty( $result ) ? array( 'page' => 'page' ) : $result;
	}

	private function normalize_post_type( mixed $value ): string {
		$value      = trim( (string) $value );
		$post_type  = sanitize_key( $value );
		if ( $value !== $post_type || ! $this->is_valid_post_type_name( $post_type ) ) {
			throw new Execution_Exception( 'invalid_target_post_type', 'The blueprint target post type is invalid.' );
		}
		return $post_type;
	}

	private function is_valid_post_type_name( string $post_type ): bool {
		return 1 === preg_match( '/^[a-z0-9_-]{1,20}$/', $post_type );
	}

	private function normalize_target_template( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			throw new Execution_Exception( 'invalid_target_template', 'The blueprint target template must be an object.' );
		}

		$label    = sanitize_text_field( (string) ( $value['label'] ?? '' ) );
		$has_slug = array_key_exists( 'slug', $value );
		$has_file = array_key_exists( 'file', $value );
		if ( $has_slug === $has_file ) {
			throw new Execution_Exception( 'invalid_target_template', 'The target template must define exactly one slug or hierarchy file.' );
		}

		if ( $has_slug ) {
			$raw_slug = trim( (string) $value['slug'] );
			$slug     = sanitize_key( $raw_slug );
			if ( $raw_slug !== $slug || ! preg_match( '/^[a-z0-9][a-z0-9_-]{0,199}$/', $slug ) ) {
				throw new Execution_Exception( 'invalid_target_template', 'The blueprint target template slug is invalid.' );
			}
			return array(
				'label' => $label,
				'mode'  => 'default' === $slug ? 'default' : 'assigned',
				'slug'  => $slug,
			);
		}

		$file = trim( str_replace( '\\', '/', (string) $value['file'] ) );
		if ( ! preg_match( '#^templates/[a-z0-9][a-z0-9_-]{0,199}\.html$#', $file ) ) {
			throw new Execution_Exception( 'invalid_target_template', 'The blueprint hierarchy template file is invalid.' );
		}
		return array(
			'label' => $label,
			'mode'  => 'hierarchy',
			'file'  => $file,
		);
	}

	private function target_template_from_policy( array $policy, string $page_type, string $post_type ): array {
		$contract = $policy['template_contract'] ?? array();
		if ( ! is_array( $contract ) ) {
			return array( 'slug' => 'default' );
		}

		if ( 'post' === $page_type && 'post' === $post_type ) {
			return $this->target_template_from_contract( $contract['blog_posts'] ?? array() );
		}

		if ( 'page' === $post_type ) {
			return $this->target_template_from_contract( $contract['standard_pages'] ?? array() );
		}

		return $this->target_template_from_contract( $contract['custom_post_types'] ?? array() );
	}

	private function target_template_from_contract( mixed $contract ): array {
		if ( ! is_array( $contract ) ) {
			return array( 'slug' => 'default' );
		}
		if ( isset( $contract['template_slug'] ) ) {
			return array(
				'label' => sanitize_text_field( (string) ( $contract['template_label'] ?? '' ) ),
				'slug'  => sanitize_key( (string) $contract['template_slug'] ),
			);
		}
		if ( isset( $contract['template_file'] ) ) {
			return array(
				'label' => sanitize_text_field( (string) ( $contract['template_label'] ?? '' ) ),
				'file'  => trim( (string) $contract['template_file'] ),
			);
		}
		return array( 'slug' => 'default' );
	}

	private function assert_post_template_contract( mixed $contract, array $target_template ): void {
		if ( ! is_array( $contract ) ) {
			throw new Execution_Exception(
				'blueprint_post_template_contract_mismatch',
				'The design policy must declare an agent-safe post template contract.'
			);
		}

		$template_file = isset( $contract['template_file'] ) ? trim( (string) $contract['template_file'] ) : '';
		$template_slug = isset( $contract['template_slug'] ) ? sanitize_key( (string) $contract['template_slug'] ) : '';
		if ( '' !== $template_file && '' !== $template_slug ) {
			throw new Execution_Exception(
				'blueprint_post_template_contract_mismatch',
				'The design policy post template contract must declare either template_file or template_slug, not both.'
			);
		}

		if ( '' !== $template_file ) {
			if (
				! preg_match( '#^templates/[a-z0-9][a-z0-9_-]{0,199}\.html$#', $template_file )
				|| 'hierarchy' !== ( $target_template['mode'] ?? '' )
				|| $template_file !== ( $target_template['file'] ?? '' )
			) {
				throw new Execution_Exception(
					'blueprint_post_template_contract_mismatch',
					'The post blueprint must use the agent-safe hierarchy template declared by the design policy.'
				);
			}
			return;
		}

		if ( '' !== $template_slug ) {
			$expected_mode = 'default' === $template_slug ? 'default' : 'assigned';
			if (
				$expected_mode !== ( $target_template['mode'] ?? '' )
				|| $template_slug !== ( $target_template['slug'] ?? '' )
			) {
				throw new Execution_Exception(
					'blueprint_post_template_contract_mismatch',
					'The post blueprint must use the agent-safe assigned template declared by the design policy.'
				);
			}
			return;
		}

		throw new Execution_Exception(
			'blueprint_post_template_contract_mismatch',
			'The design policy post template contract must declare template_file or template_slug.'
		);
	}

	private function safe_theme_data( array $data ): array {
		$encoded = wp_json_encode( $data );
		if ( ! is_string( $encoded ) || strlen( $encoded ) > 250000 ) {
			return array( 'truncated' => true );
		}
		$decoded = json_decode( $encoded, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	private function markup_contract_from_policy( array $policy ): ?array {
		if ( ! array_key_exists( 'markup_contract', $policy ) ) {
			return null;
		}
		if ( ! is_array( $policy['markup_contract'] ) ) {
			throw new Execution_Exception( 'pattern_markup_contract_missing', 'The active theme markup_contract must be an object.' );
		}

		$contract = $policy['markup_contract'];
		foreach ( array( 'scope_classes', 'section_classes', 'intro_classes' ) as $key ) {
			$this->assert_contract_class_list( $contract[ $key ] ?? null, $key );
		}

		if ( ! isset( $contract['role_classes'] ) || ! is_array( $contract['role_classes'] ) ) {
			throw new Execution_Exception( 'pattern_markup_contract_missing', 'The active theme markup_contract is missing role_classes.' );
		}
		foreach ( array( 'eyebrow_or_label', 'lead', 'card' ) as $key ) {
			$this->assert_contract_class_list( $contract['role_classes'][ $key ] ?? null, 'role_classes.' . $key );
		}

		$this->assert_descriptive_string_list(
			$contract['rules'] ?? null,
			'pattern_markup_contract_missing',
			'The active theme markup_contract must contain at least one descriptive rule.'
		);

		return $contract;
	}

	private function assert_contract_class_list( mixed $value, string $key ): void {
		if ( ! is_array( $value ) || empty( $value ) || ! array_is_list( $value ) ) {
			throw new Execution_Exception(
				'pattern_markup_contract_missing',
					sprintf( 'The active theme markup_contract field "%s" must be a non-empty class list.', $key ) // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data is returned by an Ability and is not rendered as HTML.
			);
		}

		foreach ( $value as $class_name ) {
			if (
				! is_string( $class_name )
				|| ! preg_match( '/^[a-z][a-z0-9_-]{0,127}$/i', $class_name )
			) {
				throw new Execution_Exception(
					'pattern_markup_contract_missing',
						sprintf( 'The active theme markup_contract field "%s" contains an invalid class token.', $key ) // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data is returned by an Ability and is not rendered as HTML.
				);
			}
		}
	}

	private function assert_descriptive_string_list( mixed $value, string $code, string $message ): void {
		if ( ! is_array( $value ) || empty( $value ) || ! array_is_list( $value ) ) {
			throw new Execution_Exception( $code, $message ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data is returned by an Ability and is not rendered as HTML.
		}
		foreach ( $value as $item ) {
			if ( ! is_string( $item ) || '' === trim( $item ) ) {
				throw new Execution_Exception( $code, $message ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data is returned by an Ability and is not rendered as HTML.
			}
		}
	}
}
