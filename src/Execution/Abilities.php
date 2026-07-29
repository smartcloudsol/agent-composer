<?php
/* SmartCloud Agent Composer execution contract. */

namespace SmartCloud\AgentComposer\Execution;

use SmartCloud\AgentComposer\Integration\Mcp\ComposerMcpServer;

final class Abilities {
	public const CATEGORY = 'smartcloud-agent-composer';
	public const PREFIX   = 'smartcloud-agent-composer/';
	public const CONTRACT = 'smartcloud-agent-composer-execution';

	private Config_Repository $config;
	private Draft_Service $drafts;
	private Audit_Logger $audit;
	private Pattern_Repository $patterns;
	private Ability_Provider_Registry $providers;

	public function __construct(
		Config_Repository $config,
		Draft_Service $drafts,
		Audit_Logger $audit,
		Pattern_Repository $patterns,
		Ability_Provider_Registry $providers
	) {
		$this->config    = $config;
		$this->drafts    = $drafts;
		$this->audit     = $audit;
		$this->patterns  = $patterns;
		$this->providers = $providers;
	}

	public static function names(): array {
		return array(
			self::PREFIX . 'get-page-blueprint',
			self::PREFIX . 'get-design-context',
			self::PREFIX . 'get-runtime-capabilities',
			self::PREFIX . 'list-approved-patterns',
			self::PREFIX . 'read-reference-page',
			self::PREFIX . 'search-media',
			self::PREFIX . 'materialize-media-image',
			self::PREFIX . 'list-content-drafts',
			self::PREFIX . 'insert-or-update-blocks',
			self::PREFIX . 'inspect-draft-for-adoption',
			self::PREFIX . 'adopt-content-draft',
			self::PREFIX . 'validate-content-draft',
			self::PREFIX . 'create-content-draft',
			self::PREFIX . 'validate-page-draft',
			self::PREFIX . 'create-page-draft',
			self::PREFIX . 'update-own-draft',
			self::PREFIX . 'get-draft',
			self::PREFIX . 'get-preview',
		);
	}

	public function register_category(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}
		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'SmartCloud Agent', 'smartcloud-agent-composer' ),
				'description' => __( 'Controlled, draft-only Gutenberg content design abilities.', 'smartcloud-agent-composer' ),
			)
		);
	}

	public function register(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		$this->register_ability(
			'get-page-blueprint',
			'Get page blueprint',
			'Returns the allowed patterns, blocks, sequence, references, and constraints for a page type.',
			$this->page_type_schema(),
			array( $this, 'get_page_blueprint' ),
			true
		);
		$this->register_ability(
			'get-design-context',
			'Get design context',
			'Returns the active theme identity, merged theme.json settings/styles, available page types, and design policy.',
			$this->empty_schema(),
			array( $this, 'get_design_context' ),
			true
		);
		$this->register_ability(
			'get-runtime-capabilities',
			'Get runtime capabilities',
			'Returns Composer, optional MCP transport, and registered product ability-provider availability without exposing configuration secrets.',
			$this->empty_schema(),
			array( $this, 'get_runtime_capabilities' ),
			true
		);
		$this->register_ability(
			'list-approved-patterns',
			'List approved patterns',
			'Lists registered patterns approved for a page type and their required Composer placeholders.',
			$this->page_type_schema(),
			array( $this, 'list_approved_patterns' ),
			true
		);
		$this->register_ability(
			'read-reference-page',
			'Read reference content',
			'Reads an approved published page, post, or custom post type reference, or a draft assigned to this agent, without exposing private metadata.',
			array(
				'type'                 => 'object',
				'properties'           => array(
					'page_type' => $this->string_property( 'Blueprint page type.', 1, 64 ),
					'post_id'   => array( 'type' => 'integer', 'minimum' => 1 ),
				),
				'required'             => array( 'page_type', 'post_id' ),
				'additionalProperties' => false,
			),
			array( $this, 'read_reference_page' ),
			true
		);
		$this->register_ability(
			'search-media',
			'Search existing media',
			'Searches existing image attachments. It cannot upload, edit, or delete media.',
			array(
				'type'                 => 'object',
				'properties'           => array(
					'query'    => $this->string_property( 'Search terms.', 0, 200 ),
					'limit'    => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 20, 'default' => 10 ),
					'mime_type' => array( 'type' => 'string', 'enum' => array( 'image', 'image/jpeg', 'image/png', 'image/webp', 'image/avif', 'image/gif', 'image/svg+xml' ), 'default' => 'image' ),
				),
				'additionalProperties' => false,
			),
			array( $this, 'search_media' ),
			true
		);
		$this->register_ability(
			'materialize-media-image',
			'Materialize media image',
			'Returns one existing image attachment as canonical core/image markup plus a page-type-aware placement Group. It can add a validated Flow gallery trigger without desynchronizing saved HTML from block attributes. It does not save content.',
			$this->media_image_schema(),
			array( $this, 'materialize_media_image' ),
			true
		);
		$this->register_ability(
			'list-content-drafts',
			'List content drafts',
			'Lists editable content items by status, post type, blueprint, and Composer assignment state without returning post content.',
			$this->draft_list_schema(),
			array( $this, 'list_content_drafts' ),
			true
		);
		$this->register_ability(
			'insert-or-update-blocks',
			'Insert, update, or remove draft blocks',
			'Inserts or replaces canonical blocks in a draft assigned to this agent only. Passing an empty blocks array with replace removes the targeted block. Product blocks must come from a registered provider materializer and pass that provider validator.',
			$this->block_update_schema(),
			array( $this, 'insert_or_update_blocks' ),
			false
		);
		$this->register_ability(
			'inspect-draft-for-adoption',
			'Inspect draft for adoption',
			'Checks whether an existing draft matches a selected blueprint and returns concurrency tokens without changing content, author, or ownership.',
			$this->adoption_inspection_schema(),
			array( $this, 'inspect_draft_for_adoption' ),
			true
		);
		$this->register_ability(
			'adopt-content-draft',
			'Adopt content draft',
			'Assigns a validated existing draft to the current SmartCloud agent while preserving its WordPress author and content. Requires an explicit confirmation and optimistic-concurrency tokens.',
			$this->adoption_schema(),
			array( $this, 'adopt_content_draft' ),
			false
		);
		$this->register_ability(
			'validate-content-draft',
			'Validate content draft',
			'Assembles and validates content, the blueprint-specific excerpt policy, and the SEO description without saving. The blueprint fixes the target type and template.',
			$this->candidate_schema( false ),
			array( $this, 'validate_content_draft' ),
			true
		);
		$this->register_ability(
			'create-content-draft',
			'Create content draft',
			'Creates an agent-owned draft with the blueprint-specific WordPress excerpt policy and a Yoast meta description using the target fixed by the blueprint.',
			$this->candidate_schema( true ),
			array( $this, 'create_content_draft' ),
			false
		);
		$this->register_ability(
			'validate-page-draft',
			'Validate content draft (legacy name)',
			'Backward-compatible alias for validate-content-draft.',
			$this->candidate_schema( false ),
			array( $this, 'validate_page_draft' ),
			true
		);
		$this->register_ability(
			'create-page-draft',
			'Create content draft (legacy name)',
			'Backward-compatible alias for create-content-draft; the blueprint fixes whether the draft is a page, post, or approved custom post type.',
			$this->candidate_schema( true ),
			array( $this, 'create_page_draft' ),
			false
		);
		$this->register_ability(
			'update-own-draft',
			'Update assigned content draft',
			'Updates only a draft assigned to this agent, without changing its WordPress author, blueprint, post type, or template. Requires optimistic concurrency.',
			$this->update_schema(),
			array( $this, 'update_own_draft' ),
			false
		);
		$this->register_ability(
			'get-draft',
			'Get assigned content draft',
			'Returns one draft assigned to this agent, including content and its current modification token.',
			$this->post_id_schema(),
			array( $this, 'get_draft' ),
			true
		);
		$this->register_ability(
			'get-preview',
			'Get draft preview',
			'Returns edit and preview URLs plus a fresh validation report for one draft assigned to this agent.',
			$this->post_id_schema(),
			array( $this, 'get_preview' ),
			true
		);
	}

	public function get_page_blueprint( array $input ): array|\WP_Error {
		return $this->execute( 'get-page-blueprint', $input, fn() => $this->config->get_blueprint( (string) $input['page_type'] ) );
	}

	public function get_design_context( array $input = array() ): array|\WP_Error {
		return $this->execute( 'get-design-context', $input, fn() => $this->config->get_theme_context() );
	}

	public function get_runtime_capabilities( array $input = array() ): array|\WP_Error {
		return $this->execute(
			'get-runtime-capabilities',
			$input,
			function (): array {
				$providers  = $this->providers->public_manifests();
				$policy     = $this->config->get_design_policy();
				$extensions = $this->config->get_block_extensions();
				$core       = (array) ( $extensions['allowed_core_blocks'] ?? array() );
				return array(
					'composer'                 => array(
						'name'    => 'SmartCloud Agent Composer',
						'version' => SMARTCLOUD_COMPOSER_VERSION,
						'execution_contract' => self::CONTRACT,
					),
					'abilities_api_available'  => function_exists( 'wp_get_ability' ),
					'block_extensions'         => array(
						'policy_and_blueprint_opt_in_required' => true,
						'core_html_javascript'                 => ! empty( $policy['constraints']['custom_html'] )
							&& in_array( 'core/html', $core, true )
							&& ! in_array( 'core/html', $policy['disallowed_blocks'], true )
							&& ! empty( $extensions['core_html_javascript'] ),
						'passive_text_editor_html'             => in_array( 'core/freeform', $core, true )
							&& ! in_array( 'core/freeform', $policy['disallowed_blocks'], true )
							&& ! empty( $extensions['passive_text_editor_html'] ),
						'captioned_media_image_materializer'   => in_array( 'core/image', $core, true )
							&& ! in_array( 'core/image', $policy['disallowed_blocks'], true )
							&& ! empty( $extensions['captioned_media_image_materializer'] ),
						'text_editor_contract'                 => isset( $extensions['text_editor_contract'] ) && is_array( $extensions['text_editor_contract'] )
							? $extensions['text_editor_contract']
							: array(),
					),
					'draft_lifecycle'         => array(
						'assignment_separate_from_post_author' => true,
						'post_author_preserved_on_update'      => true,
						'explicit_adoption_available'          => true,
						'published_content_writable'           => false,
					),
					'mcp'                     => array(
						'adapter_available' => class_exists( '\\WP\\MCP\\Core\\McpAdapter' ),
						'server_id'         => ComposerMcpServer::SERVER_ID,
						'endpoint'          => ComposerMcpServer::HTTP_ENDPOINT,
						'optional'          => true,
					),
					'component_providers'     => $providers,
					'component_provider_count' => count( $providers ),
					'product_fallbacks'       => false,
				);
			}
		);
	}

	public function list_approved_patterns( array $input ): array|\WP_Error {
		return $this->execute(
			'list-approved-patterns',
			$input,
			function () use ( $input ): array {
				$blueprint = $this->config->get_blueprint( (string) $input['page_type'] );
				$result    = array();
				foreach ( $blueprint['allowed_patterns'] as $name ) {
					$pattern = $this->patterns->resolve_approved( $name, $blueprint );
					if ( ! is_array( $pattern ) ) {
						$result[] = array(
							'name'                 => $name,
							'registered'           => false,
							'wordpress_registered' => false,
							'fields'               => array(),
							'error'                => 'pattern_not_resolvable',
						);
						continue;
					}
					preg_match_all( '/\{\{wpsuite:(text|attr|url|json):([a-z0-9_-]+)\}\}/', (string) ( $pattern['content'] ?? '' ), $matches, PREG_SET_ORDER );
					$fields = array();
					foreach ( $matches as $match ) {
						$fields[ $match[2] ] = $match[1];
					}
					$result[] = array(
						'name'                      => $name,
						'registered'                => true,
						'wordpress_registered'      => ! empty( $pattern['_wordpress_registered'] ),
						'markup_contract_validated' => ! empty( $pattern['_composer_markup_contract_validated'] ),
						'source'                    => sanitize_key( (string) ( $pattern['_composer_source'] ?? 'wordpress-registry' ) ),
						'title'                     => sanitize_text_field( (string) ( $pattern['title'] ?? $name ) ),
						'description'               => sanitize_text_field( (string) ( $pattern['description'] ?? '' ) ),
						'categories'                => array_values( array_map( 'sanitize_key', (array) ( $pattern['categories'] ?? array() ) ) ),
						'fields'                    => $fields,
					);
				}
				return array(
					'page_type'       => $blueprint['page_type'],
					'target_post_type' => $blueprint['target_post_type'],
					'target_template' => $blueprint['target_template'],
					'patterns'        => $result,
				);
			}
		);
	}

	public function read_reference_page( array $input ): array|\WP_Error {
		return $this->execute(
			'read-reference-page',
			$input,
			function () use ( $input ): array {
				$post_id   = absint( $input['post_id'] );
				$blueprint = $this->config->get_blueprint( (string) $input['page_type'] );
				clean_post_cache( $post_id );
				$post = get_post( $post_id );
				if ( ! $post instanceof \WP_Post || ! in_array( $post->post_type, $this->config->get_allowed_post_types(), true ) ) {
					throw new Execution_Exception( 'reference_not_found', 'The reference content does not exist or uses a disallowed post type.' );
				}
				$is_approved_reference = in_array( $post_id, $blueprint['reference_page_ids'], true ) && 'publish' === $post->post_status;
				$assigned_agent_id     = absint( get_post_meta( $post_id, Draft_Service::ASSIGNED_AGENT_META, true ) );
				$is_owned_draft        = 'draft' === $post->post_status
					&& '1' === (string) get_post_meta( $post_id, Draft_Service::OWNED_META, true )
					&& $blueprint['page_type'] === (string) get_post_meta( $post_id, Draft_Service::PAGE_TYPE_META, true )
					&& (
						get_current_user_id() === $assigned_agent_id
						|| ( 0 === $assigned_agent_id && get_current_user_id() === (int) $post->post_author )
					);
				if ( ! $is_approved_reference && ! $is_owned_draft ) {
					throw new Execution_Exception( 'reference_not_allowed', 'This content item is not an approved reference or a draft assigned to this agent.' );
				}
				if ( ! $is_owned_draft && ! current_user_can( 'read_post', $post_id ) ) {
					throw new Execution_Exception( 'reference_read_denied', 'The current user cannot read this reference.' );
				}
				return array(
					'post_id'          => $post_id,
					'post_type'        => $post->post_type,
					'title'            => get_the_title( $post ),
					'slug'             => $post->post_name,
					'status'           => $post->post_status,
					'modified_gmt'     => mysql_to_rfc3339( $post->post_modified_gmt ),
					'content'          => (string) $post->post_content,
					'excerpt'          => wp_strip_all_tags( (string) $post->post_excerpt ),
					'meta_description' => wp_strip_all_tags( (string) get_post_meta( $post_id, Draft_Service::YOAST_METADESC_META, true ) ),
					'block_names'      => $this->collect_block_names( parse_blocks( (string) $post->post_content ) ),
				);
			}
		);
	}

	public function search_media( array $input ): array|\WP_Error {
		return $this->execute(
			'search-media',
			$input,
			function () use ( $input ): array {
				$mime  = sanitize_mime_type( (string) ( $input['mime_type'] ?? 'image' ) );
				$query = new \WP_Query(
					array(
						'post_type'              => 'attachment',
						'post_status'            => 'inherit',
						'post_mime_type'         => 'image' === $mime || '' === $mime ? 'image' : $mime,
						's'                      => sanitize_text_field( (string) ( $input['query'] ?? '' ) ),
						'posts_per_page'         => min( 20, max( 1, absint( $input['limit'] ?? 10 ) ) ),
						'orderby'                => 'date',
						'order'                  => 'DESC',
						'no_found_rows'          => true,
						'update_post_meta_cache' => true,
						'update_post_term_cache' => false,
					)
				);
				$items = array();
				foreach ( $query->posts as $attachment ) {
					if ( ! $attachment instanceof \WP_Post || ! current_user_can( 'read_post', $attachment->ID ) ) {
						continue;
					}
					$metadata = wp_get_attachment_metadata( $attachment->ID );
					$items[]  = array(
						'id'       => $attachment->ID,
						'title'    => get_the_title( $attachment ),
						'alt'      => (string) get_post_meta( $attachment->ID, '_wp_attachment_image_alt', true ),
						'caption'  => wp_strip_all_tags( (string) $attachment->post_excerpt ),
						'mime_type' => (string) $attachment->post_mime_type,
						'url'      => wp_get_attachment_url( $attachment->ID ) ?: '',
						'width'    => is_array( $metadata ) ? absint( $metadata['width'] ?? 0 ) : 0,
						'height'   => is_array( $metadata ) ? absint( $metadata['height'] ?? 0 ) : 0,
					);
				}
				return array( 'items' => $items );
			}
		);
	}

	public function materialize_media_image( array $input ): array|\WP_Error {
		return $this->execute(
			'materialize-media-image',
			$input,
			function () use ( $input ): array {
				$page_type         = sanitize_key( (string) ( $input['page_type'] ?? '' ) );
				$blueprint         = $this->config->get_blueprint( $page_type );
				$extensions        = isset( $blueprint['block_extensions'] ) && is_array( $blueprint['block_extensions'] )
					? $blueprint['block_extensions']
					: array();
				if (
					! in_array( 'core/image', $blueprint['allowed_blocks'], true )
					|| ! in_array( 'core/image', (array) ( $extensions['allowed_core_blocks'] ?? array() ), true )
					|| empty( $extensions['captioned_media_image_materializer'] )
				) {
					throw new Execution_Exception( 'image_block_not_allowed', 'The selected blueprint has not enabled the caption-capable core/image extension.' );
				}

				$attachment_id = absint( $input['attachment_id'] ?? 0 );
				$attachment    = get_post( $attachment_id );
				if (
					! $attachment instanceof \WP_Post
					|| 'attachment' !== $attachment->post_type
					|| ! wp_attachment_is_image( $attachment_id )
				) {
					throw new Execution_Exception( 'image_attachment_not_found', 'The requested Media Library image does not exist.' );
				}
				if ( ! current_user_can( 'read_post', $attachment_id ) ) {
					throw new Execution_Exception( 'image_attachment_read_denied', 'The current agent cannot read this Media Library image.' );
				}

				$size_slug = $this->media_image_size_slug( $input, $page_type );
				$sizes     = array_values( array_unique( array_merge( get_intermediate_image_sizes(), array( 'full' ) ) ) );
				if ( ! in_array( $size_slug, $sizes, true ) ) {
					throw new Execution_Exception( 'image_size_not_available', 'The requested image size is not registered on this WordPress site.' );
				}

				$link_destination = sanitize_key( (string) ( $input['link_destination'] ?? 'none' ) );
				if ( ! in_array( $link_destination, array( 'none', 'media', 'attachment' ), true ) ) {
					throw new Execution_Exception( 'invalid_image_link_destination', 'The image link destination must be none, media, or attachment.' );
				}
				$include_caption = ! array_key_exists( 'include_caption', $input ) || true === $input['include_caption'];
				$image_source    = wp_get_attachment_image_src( $attachment_id, $size_slug );
				if ( ! is_array( $image_source ) || empty( $image_source[0] ) ) {
					throw new Execution_Exception( 'image_markup_unavailable', 'WordPress could not materialize the requested image size.' );
				}
				$image_url       = (string) $image_source[0];
				$image_width     = absint( $image_source[1] ?? 0 );
				$image_height    = absint( $image_source[2] ?? 0 );
				$alt             = wp_strip_all_tags( (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) );
				$title           = wp_strip_all_tags( (string) $attachment->post_title );
				$trigger_classes = $this->media_gallery_trigger_classes( $input['gallery_trigger'] ?? null );
				if ( ! empty( $trigger_classes ) && 'none' !== $link_destination ) {
					throw new Execution_Exception( 'gallery_trigger_link_conflict', 'A Flow gallery trigger image must use link_destination none.' );
				}
				$image_html = '<img src="' . esc_url( $image_url ) . '" alt="' . esc_attr( $alt ) . '"'
					. ' class="wp-image-' . $attachment_id . '"'
					. ( '' !== $title ? ' title="' . esc_attr( $title ) . '"' : '' )
					. '/>';

				if ( 'media' === $link_destination ) {
					$href = wp_get_attachment_url( $attachment_id );
					if ( ! is_string( $href ) || '' === $href ) {
						throw new Execution_Exception( 'image_link_unavailable', 'The image media URL is unavailable.' );
					}
					$image_html = '<a href="' . esc_url( $href ) . '">' . $image_html . '</a>';
				} elseif ( 'attachment' === $link_destination ) {
					$href = get_attachment_link( $attachment_id );
					if ( ! is_string( $href ) || '' === $href ) {
						throw new Execution_Exception( 'image_link_unavailable', 'The image attachment URL is unavailable.' );
					}
					$image_html = '<a href="' . esc_url( $href ) . '">' . $image_html . '</a>';
				}

				$caption      = $this->media_image_caption( (string) $attachment->post_excerpt );
				$caption_html = $include_caption && '' !== $caption
					? '<figcaption class="wp-element-caption">' . $caption . '</figcaption>'
					: '';
				$figure_classes = array_merge( array( 'wp-block-image', 'size-' . $size_slug ), $trigger_classes );
				$inner_html     = '<figure class="' . esc_attr( implode( ' ', $figure_classes ) ) . '">'
					. $image_html
					. $caption_html
					. '</figure>';
				$block_attrs = array(
					'id'              => $attachment_id,
					'sizeSlug'        => $size_slug,
					'linkDestination' => $link_destination,
				);
				if ( ! empty( $trigger_classes ) ) {
					$block_attrs['className'] = implode( ' ', $trigger_classes );
				}
				$block        = array(
					'blockName'    => 'core/image',
					'attrs'        => $block_attrs,
					'innerBlocks'  => array(),
					'innerHTML'    => $inner_html,
					'innerContent' => array( $inner_html ),
				);
				$presentation    = 'post' === $page_type ? 'post-screenshot' : 'landing-artwork';
				$placement_class = 'post' === $page_type ? 'wps-post-media' : 'wps-explanatory-media';
				$placement_block = $this->media_placement_block( $block, $placement_class );

				return array(
					'page_type'        => $page_type,
					'attachment_id'    => $attachment_id,
					'alt'              => $alt,
					'caption'          => wp_strip_all_tags( (string) $attachment->post_excerpt ),
					'caption_included' => '' !== $caption_html,
					'width'            => $image_width,
					'height'           => $image_height,
					'block'            => $block,
					'placement_block'  => $placement_block,
					'placement_blocks' => array( $placement_block ),
					'presentation'     => $presentation,
					'placement_class'  => $placement_class,
					'gallery_trigger_classes' => $trigger_classes,
				);
			}
		);
	}

	public function insert_or_update_blocks( array $input ): array|\WP_Error {
		return $this->execute(
			'insert-or-update-blocks',
			$input,
			fn() => $this->drafts->insert_or_update_blocks( $input )
		);
	}

	public function list_content_drafts( array $input ): array|\WP_Error {
		return $this->execute( 'list-content-drafts', $input, fn() => $this->drafts->list_content_drafts( $input ) );
	}

	public function inspect_draft_for_adoption( array $input ): array|\WP_Error {
		return $this->execute(
			'inspect-draft-for-adoption',
			$input,
			fn() => $this->drafts->inspect_for_adoption( $input )
		);
	}

	public function adopt_content_draft( array $input ): array|\WP_Error {
		return $this->execute(
			'adopt-content-draft',
			$input,
			fn() => $this->drafts->adopt( $input )
		);
	}

	public function validate_page_draft( array $input ): array|\WP_Error {
		return $this->execute( 'validate-page-draft', $input, fn() => $this->drafts->validate_request( $input ) );
	}

	public function validate_content_draft( array $input ): array|\WP_Error {
		return $this->execute( 'validate-content-draft', $input, fn() => $this->drafts->validate_request( $input ) );
	}

	public function create_page_draft( array $input ): array|\WP_Error {
		return $this->execute( 'create-page-draft', $input, fn() => $this->drafts->create( $input ) );
	}

	public function create_content_draft( array $input ): array|\WP_Error {
		return $this->execute( 'create-content-draft', $input, fn() => $this->drafts->create( $input ) );
	}

	public function update_own_draft( array $input ): array|\WP_Error {
		return $this->execute( 'update-own-draft', $input, fn() => $this->drafts->update( $input ) );
	}

	public function get_draft( array $input ): array|\WP_Error {
		return $this->execute(
			'get-draft',
			$input,
			function () use ( $input ): array {
				$post   = $this->drafts->get_owned_draft( absint( $input['post_id'] ) );
				$result = $this->drafts->get( $post->ID );
				$result['content'] = (string) $post->post_content;
				return $result;
			}
		);
	}

	public function get_preview( array $input ): array|\WP_Error {
		return $this->execute( 'get-preview', $input, fn() => $this->drafts->get_preview( absint( $input['post_id'] ) ) );
	}

	public function check_permission( mixed $input = null ): bool {
		return is_user_logged_in()
			&& current_user_can( \SmartCloud\AgentComposer\Infrastructure\WordPress\Activation::CAP_USE )
			&& current_user_can( 'read' )
			&& current_user_can( 'edit_pages' )
			&& current_user_can( 'edit_posts' );
	}

	private function register_ability( string $slug, string $label, string $description, array $input_schema, callable $callback, bool $read_only ): void {
		wp_register_ability(
			self::PREFIX . $slug,
			array(
				'label'               => __( $label, 'smartcloud-agent-composer' ), // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText
				'description'         => __( $description, 'smartcloud-agent-composer' ), // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText
				'category'            => self::CATEGORY,
				'input_schema'        => $input_schema,
				'output_schema'       => array( 'type' => 'object', 'additionalProperties' => true ),
				'execute_callback'    => $callback,
				'permission_callback' => array( $this, 'check_permission' ),
				'meta'                => array(
					'show_in_rest' => false,
					'mcp'          => array( 'public' => false ),
					'annotations' => array(
						'readonly'    => $read_only,
						'destructive' => false,
						'idempotent'  => $read_only || in_array(
							$slug,
							array( 'create-page-draft', 'create-content-draft', 'adopt-content-draft' ),
							true
						),
					),
				),
			)
		);
	}

	private function execute( string $slug, array $input, callable $callback ): array|\WP_Error {
		$operation = self::PREFIX . $slug;
		$this->audit->begin_operation();
		if ( ! $this->check_permission( $input ) ) {
			$this->audit->log( $operation, 'denied', $input, 0, 'permission_denied' );
			return new \WP_Error( 'smartcloud_agent_permission_denied', 'The authenticated WordPress user is not an authorized SmartCloud agent.', array( 'status' => 403, 'request_id' => $this->audit->get_request_id() ) );
		}

		try {
			$result    = $callback();
			$object_id = is_array( $result ) ? absint( $result['post_id'] ?? 0 ) : 0;
			$this->audit->log( $operation, 'success', $input, $object_id );
			if ( is_array( $result ) ) {
				$result['_request_id'] = $this->audit->get_request_id();
			}
			return $result;
		} catch ( Execution_Exception $error ) {
			$post_id  = absint( $input['post_id'] ?? 0 );
			$conflict = in_array(
				$error->get_execution_code(),
				array( 'edit_conflict', 'draft_assigned_to_other_agent' ),
				true
			);
			$this->audit->log( $operation, 'error', $input, $post_id, $error->get_execution_code(), array( 'conflict' => $conflict ) );
			$status = $conflict ? 409 : 400;
			return new \WP_Error( 'smartcloud_agent_' . $error->get_execution_code(), $error->getMessage(), array( 'status' => $status, 'request_id' => $this->audit->get_request_id() ) );
		} catch ( \Throwable $error ) {
			$this->audit->log( $operation, 'error', $input, absint( $input['post_id'] ?? 0 ), 'internal_error' );
			do_action( 'smartcloud_composer_internal_error', $error, $operation );
			return new \WP_Error( 'smartcloud_agent_internal_error', 'The ability failed unexpectedly.', array( 'status' => 500, 'request_id' => $this->audit->get_request_id() ) );
		}
	}

	private function page_type_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array( 'page_type' => $this->string_property( 'Blueprint page type.', 1, 64 ) ),
			'required'             => array( 'page_type' ),
			'additionalProperties' => false,
		);
	}

	private function empty_schema(): array {
		return array( 'type' => 'object', 'properties' => array(), 'additionalProperties' => false );
	}

	public function post_id_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array( 'post_id' => array( 'type' => 'integer', 'minimum' => 1 ) ),
			'required'             => array( 'post_id' ),
			'additionalProperties' => false,
		);
	}

	public function media_image_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'page_type'       => $this->string_property( 'Blueprint page type that explicitly enables core/image.', 1, 64 ),
				'attachment_id'   => array( 'type' => 'integer', 'minimum' => 1 ),
				'size_slug'       => $this->string_property( 'Registered WordPress image size slug. Non-post blueprints always materialize full; posts default to large for the canonical 640px presentation.', 1, 64 ),
				'include_caption' => array( 'type' => 'boolean', 'default' => true ),
				'gallery_trigger' => array(
					'type'                 => 'object',
					'properties'           => array(
						'modal_id'   => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 64, 'pattern' => '^[A-Za-z0-9][A-Za-z0-9_-]*$' ),
						'gallery_id' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 64, 'pattern' => '^[A-Za-z0-9][A-Za-z0-9_-]*$' ),
						'index'      => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100 ),
					),
					'required'             => array( 'modal_id', 'gallery_id', 'index' ),
					'additionalProperties' => false,
				),
				'link_destination' => array(
					'type'    => 'string',
					'enum'    => array( 'none', 'media', 'attachment' ),
					'default' => 'none',
				),
			),
			'required'             => array( 'page_type', 'attachment_id' ),
			'additionalProperties' => false,
		);
	}

	private function media_image_size_slug( array $input, string $page_type ): string {
		if ( 'post' !== $page_type ) {
			return 'full';
		}

		return sanitize_key( (string) ( $input['size_slug'] ?? 'large' ) );
	}

	private function media_image_caption( string $caption ): string {
		// Match core/image's Media Library selection path before RichText saves it.
		$caption = str_replace( array( "\r\n", "\r", "\n" ), '<br>', $caption );

		return trim(
			wp_kses(
				$caption,
				array(
					'a'      => array(
						'href'   => true,
						'rel'    => true,
						'target' => true,
						'title'  => true,
					),
					'br'     => array(),
					'code'   => array(),
					'em'     => array(),
					'mark'   => array(),
					's'      => array(),
					'span'   => array( 'class' => true ),
					'strong' => array(),
					'sub'    => array(),
					'sup'    => array(),
				)
			)
		);
	}

	private function media_gallery_trigger_classes( mixed $value ): array {
		if ( null === $value ) {
			return array();
		}
		if ( ! is_array( $value ) ) {
			throw new Execution_Exception( 'invalid_gallery_trigger', 'gallery_trigger must be an object.' );
		}

		$modal_id   = (string) ( $value['modal_id'] ?? '' );
		$gallery_id = (string) ( $value['gallery_id'] ?? '' );
		$index      = $value['index'] ?? 0;
		foreach ( array( $modal_id, $gallery_id ) as $identifier ) {
			if ( ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/', $identifier ) ) {
				throw new Execution_Exception( 'invalid_gallery_trigger', 'Flow modal and gallery IDs must be stable CSS-safe identifiers.' );
			}
		}
		if ( ! is_int( $index ) || $index < 1 || $index > 100 ) {
			throw new Execution_Exception( 'invalid_gallery_trigger', 'Flow gallery trigger index must be an integer from 1 to 100.' );
		}

		return array(
			'wps-flow-modal-open--' . $modal_id,
			'wps-flow-gallery-target--' . $gallery_id,
			'wps-flow-gallery-index--' . $index,
		);
	}

	private function media_placement_block( array $image_block, string $placement_class ): array {
		$open = '<div class="wp-block-group ' . esc_attr( $placement_class ) . '">';

		return array(
			'blockName'    => 'core/group',
			'attrs'        => array(
				'className' => $placement_class,
				'layout'    => array( 'type' => 'default' ),
			),
			'innerBlocks'  => array( $image_block ),
			'innerHTML'    => $open . '</div>',
			'innerContent' => array( $open, null, '</div>' ),
		);
	}

	public function draft_list_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'status'     => array(
					'type'    => 'string',
					'enum'    => array( 'draft', 'pending', 'future', 'private', 'publish', 'any' ),
					'default' => 'draft',
				),
				'post_type'  => $this->string_property( 'Optional allowed post type filter.', 0, 20 ),
				'page_type'  => $this->string_property( 'Optional Composer blueprint page_type filter.', 0, 64 ),
				'assignment' => array(
					'type'    => 'string',
					'enum'    => array( 'any', 'current-agent', 'unassigned', 'other-agent', 'composer-owned', 'not-composer-owned' ),
					'default' => 'any',
				),
				'search'     => $this->string_property( 'Optional title/content search terms.', 0, 200 ),
				'orderby'    => array(
					'type'    => 'string',
					'enum'    => array( 'modified', 'date', 'title', 'ID' ),
					'default' => 'modified',
				),
				'order'      => array(
					'type'    => 'string',
					'enum'    => array( 'ASC', 'DESC' ),
					'default' => 'DESC',
				),
				'limit'      => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 20 ),
				'offset'     => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 5000, 'default' => 0 ),
			),
			'additionalProperties' => false,
		);
	}

	public function adoption_inspection_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'post_id'   => array( 'type' => 'integer', 'minimum' => 1 ),
				'page_type' => $this->string_property( 'Blueprint page type to bind to the existing draft.', 1, 64 ),
			),
			'required'             => array( 'post_id', 'page_type' ),
			'additionalProperties' => false,
		);
	}

	public function adoption_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'post_id'               => array( 'type' => 'integer', 'minimum' => 1 ),
				'page_type'             => $this->string_property( 'Blueprint page type returned by the adoption inspection.', 1, 64 ),
				'expected_modified_gmt' => array( 'type' => 'string', 'format' => 'date-time' ),
				'expected_content_hash' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ),
				'confirm_adoption'      => array( 'type' => 'boolean', 'enum' => array( true ) ),
			),
			'required'             => array(
				'post_id',
				'page_type',
				'expected_modified_gmt',
				'expected_content_hash',
				'confirm_adoption',
			),
			'additionalProperties' => false,
		);
	}

	public function candidate_schema( bool $for_create ): array {
		$properties = array(
			'page_type'        => $this->string_property( 'Blueprint page type.', 1, 64 ),
			'excerpt'          => $this->string_property( 'WordPress excerpt. Runtime policy is required, optional, or disabled according to the selected blueprint.', 0, 300 ),
			'meta_description' => $this->string_property( 'Yoast SEO meta description: a natural search-result proposition.', 120, 160 ),
			'sections'         => $this->sections_schema(),
		);
		$required = array( 'page_type', 'meta_description', 'sections' );
		if ( $for_create ) {
			$properties['title']           = $this->string_property( 'Content title.', 1, 200 );
			$properties['slug']            = $this->string_property( 'Requested content slug.', 1, 200 );
			$properties['idempotency_key'] = array( 'type' => 'string', 'minLength' => 8, 'maxLength' => 128, 'pattern' => '^[A-Za-z0-9._:-]+$' );
			$required = array_merge( $required, array( 'title', 'slug', 'idempotency_key' ) );
		}
		return array( 'type' => 'object', 'properties' => $properties, 'required' => $required, 'additionalProperties' => false );
	}

	public function update_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'post_id'              => array( 'type' => 'integer', 'minimum' => 1 ),
				'expected_modified_gmt' => array( 'type' => 'string', 'format' => 'date-time' ),
				'expected_revision'     => array( 'type' => 'string', 'format' => 'uuid' ),
				'page_type'            => $this->string_property( 'Must match the immutable draft page type.', 1, 64 ),
				'title'                => $this->string_property( 'Optional replacement title.', 1, 200 ),
				'slug'                 => $this->string_property( 'Optional replacement slug.', 1, 200 ),
				'excerpt'              => $this->string_property( 'Replacement WordPress excerpt. Runtime policy is required, optional, or disabled according to the immutable draft blueprint.', 0, 300 ),
				'meta_description'     => $this->string_property( 'Required replacement or preserved Yoast SEO meta description.', 120, 160 ),
				'sections'             => $this->sections_schema(),
			),
			'required'             => array( 'post_id', 'expected_modified_gmt', 'expected_revision', 'page_type', 'meta_description', 'sections' ),
			'additionalProperties' => false,
		);
	}

	public function block_update_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'post_id'               => array( 'type' => 'integer', 'minimum' => 1 ),
				'expected_modified_gmt' => array( 'type' => 'string', 'format' => 'date-time' ),
				'expected_revision'     => array( 'type' => 'string', 'format' => 'uuid' ),
				'mode'                  => array(
					'type' => 'string',
					'enum' => array( 'append', 'prepend', 'insert-before', 'insert-after', 'replace' ),
				),
				'path'                  => array(
					'type'     => 'array',
					'maxItems' => 24,
					'items'    => array( 'type' => 'integer', 'minimum' => 0 ),
					'default'  => array(),
				),
				'blocks'                => $this->block_tree_schema( true ),
			),
			'required'             => array( 'post_id', 'expected_modified_gmt', 'expected_revision', 'mode', 'blocks' ),
			'additionalProperties' => false,
		);
	}

	private function block_tree_schema( bool $allow_empty = false ): array {
		return array(
			'type'     => 'array',
			'minItems' => $allow_empty ? 0 : 1,
			'maxItems' => 500,
			'items'    => array(
				'type'                 => 'object',
				'properties'           => array(
					'name'         => array( 'type' => 'string', 'pattern' => '^[a-z0-9-]+/[a-z0-9-]+$' ),
					'blockName'    => array( 'type' => 'string', 'pattern' => '^[a-z0-9-]+/[a-z0-9-]+$' ),
					'attributes'   => array( 'type' => 'object', 'additionalProperties' => true ),
					'attrs'        => array( 'type' => 'object', 'additionalProperties' => true ),
					'innerHTML'    => array( 'type' => 'string', 'maxLength' => 250000 ),
					'innerContent' => array(
						'type'     => 'array',
						'maxItems' => 1000,
						'items'    => array( 'type' => array( 'string', 'null' ) ),
					),
					'innerBlocks'  => array(
						'type'     => 'array',
						'maxItems' => 500,
						'items'    => array( 'type' => 'object', 'additionalProperties' => true ),
					),
				),
				'additionalProperties' => false,
			),
		);
	}

	private function sections_schema(): array {
		return array(
			'type'     => 'array',
			'minItems' => 1,
			'maxItems' => 50,
			'items'    => array(
				'type'                 => 'object',
				'properties'           => array(
					'pattern' => array( 'type' => 'string', 'pattern' => '^[a-z0-9-]+/[a-z0-9-]+$' ),
					'fields'  => array(
						'type'                 => 'object',
						'maxProperties'        => 100,
						'additionalProperties' => array( 'type' => array( 'string', 'number', 'integer', 'boolean' ) ),
					),
				),
				'required'             => array( 'pattern', 'fields' ),
				'additionalProperties' => false,
			),
		);
	}

	private function string_property( string $description, int $minimum, int $maximum ): array {
		return array( 'type' => 'string', 'description' => $description, 'minLength' => $minimum, 'maxLength' => $maximum );
	}

	private function collect_block_names( array $blocks ): array {
		$names = array();
		foreach ( $blocks as $block ) {
			if ( ! empty( $block['blockName'] ) ) {
				$names[] = (string) $block['blockName'];
			}
			if ( ! empty( $block['innerBlocks'] ) ) {
				$names = array_merge( $names, $this->collect_block_names( $block['innerBlocks'] ) );
			}
		}
		return array_values( array_unique( $names ) );
	}
}
