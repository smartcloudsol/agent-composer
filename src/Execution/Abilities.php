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
	private Query_Loop_Materializer $query_loops;
	private Content_Field_Materializer $content_fields;
	private Taxonomy_Term_Service $taxonomy_terms;
	private Semantic_Slot_Materializer $semantic_slots;
	private Remote_Media_Ingestor $remote_media;
	private Content_Proposal_Service $proposals;
	private Localization_Provider_Registry $localization;
	private Localized_Draft_Service $localized_drafts;
	private Rendered_Preview_Service $rendered_previews;

	public function __construct(
		Config_Repository $config,
		Draft_Service $drafts,
		Audit_Logger $audit,
		Pattern_Repository $patterns,
		Ability_Provider_Registry $providers,
		Query_Loop_Materializer $query_loops,
		Content_Field_Materializer $content_fields,
		Taxonomy_Term_Service $taxonomy_terms,
		Semantic_Slot_Materializer $semantic_slots,
		Remote_Media_Ingestor $remote_media,
		Content_Proposal_Service $proposals,
		Localization_Provider_Registry $localization,
		Localized_Draft_Service $localized_drafts,
		Rendered_Preview_Service $rendered_previews
	) {
		$this->config    = $config;
		$this->drafts    = $drafts;
		$this->audit     = $audit;
		$this->patterns  = $patterns;
		$this->providers = $providers;
		$this->query_loops = $query_loops;
		$this->content_fields = $content_fields;
		$this->taxonomy_terms = $taxonomy_terms;
		$this->semantic_slots = $semantic_slots;
		$this->remote_media   = $remote_media;
		$this->proposals      = $proposals;
		$this->localization   = $localization;
		$this->localized_drafts = $localized_drafts;
		$this->rendered_previews = $rendered_previews;
	}

	public static function names(): array {
		return array(
			self::PREFIX . 'get-page-blueprint',
			self::PREFIX . 'get-design-context',
			self::PREFIX . 'get-runtime-capabilities',
			self::PREFIX . 'list-supported-content-languages',
			self::PREFIX . 'list-approved-patterns',
			self::PREFIX . 'read-reference-page',
			self::PREFIX . 'search-media',
			self::PREFIX . 'assign-featured-image',
			self::PREFIX . 'ingest-remote-media',
			self::PREFIX . 'materialize-media-image',
			self::PREFIX . 'materialize-query-loop',
			self::PREFIX . 'get-content-field-contract',
			self::PREFIX . 'search-relation-targets',
			self::PREFIX . 'inspect-content-fields',
			self::PREFIX . 'update-content-fields',
			self::PREFIX . 'get-taxonomy-contract',
			self::PREFIX . 'search-taxonomy-terms',
			self::PREFIX . 'create-taxonomy-term',
			self::PREFIX . 'assign-taxonomy-terms',
			self::PREFIX . 'inspect-taxonomy-terms',
			self::PREFIX . 'list-content-drafts',
			self::PREFIX . 'inspect-content-item',
			self::PREFIX . 'clone-content-item',
			self::PREFIX . 'link-content-draft-translations',
			self::PREFIX . 'attach-content-draft-to-translation-group',
			self::PREFIX . 'attach-content-to-translation-group',
			self::PREFIX . 'merge-content-translation-groups',
			self::PREFIX . 'create-content-proposal',
			self::PREFIX . 'submit-content-proposal',
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
			self::PREFIX . 'get-rendered-preview',
		);
	}

	public static function resource_names(): array {
		return array( self::PREFIX . 'rendered-preview-app' );
	}

	public function register_category(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}
		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'SmartCloud Agent', 'smartcloud-agent-composer' ),
				'description' => __( 'Controlled Gutenberg draft and published-content proposal abilities.', 'smartcloud-agent-composer' ),
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
			'list-supported-content-languages',
			'List supported content languages',
			'Distinguishes unrestricted authored language from provider-backed language switching and localized-draft linking.',
			$this->empty_schema(),
			array( $this, 'list_supported_content_languages' ),
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
			'assign-featured-image',
			'Assign featured image',
			'Assigns one readable existing Media Library image as the featured image of a Composer-owned draft. It requires optimistic-concurrency tokens and cannot modify published content.',
			$this->featured_image_assignment_schema(),
			array( $this, 'assign_featured_image' ),
			false
		);
		$this->register_ability(
			'ingest-remote-media',
			'Ingest remote media',
			'Downloads one image from a Site Contract-approved HTTPS host into the WordPress Media Library with bounded size, MIME validation, and idempotency. It may assign the image only to a Composer-owned draft and cannot publish or delete content.',
			$this->remote_media_schema(),
			array( $this, 'ingest_remote_media' ),
			false
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
			'materialize-query-loop',
			'Materialize constrained Query Loop',
			'Returns a canonical core/query block tree using only the post types, taxonomies, sort keys, limits, and template blocks explicitly allowed by the active Site Contract and selected blueprint. It does not save content.',
			$this->query_loop_schema(),
			array( $this, 'materialize_query_loop' ),
			true
		);
		$this->register_ability(
			'get-content-field-contract',
			'Get content field contract',
			'Returns only the registered CPT fields explicitly enabled by the active Site Contract for a selected Blueprint. Relation fields identify the required lookup, write, and verification abilities.',
			$this->page_type_schema(),
			array( $this, 'get_content_field_contract' ),
			true,
			$this->content_field_contract_output_schema()
		);
		$this->register_ability(
			'search-relation-targets',
			'Search relation targets',
			'Resolves human titles or exact slugs to the WordPress post IDs required by relation fields. This is the only Composer ability intended for relation-target ID lookup.',
			$this->relation_target_search_schema(),
			array( $this, 'search_relation_targets' ),
			true,
			$this->relation_target_search_output_schema()
		);
		$this->register_ability(
			'inspect-content-fields',
			'Inspect approved content fields',
			'Reads only explicitly allowed registered fields from an assigned draft or a content item covered by the existing-content read gate.',
			$this->content_field_inspection_schema(),
			array( $this, 'inspect_content_fields' ),
			true
		);
		$this->register_ability(
			'update-content-fields',
			'Update approved content fields',
			'Updates only explicitly writable registered fields on a Composer-owned assigned draft with optimistic concurrency and explicit confirmation.',
			$this->content_field_update_schema(),
			array( $this, 'update_content_fields' ),
			false
		);
		$this->register_ability(
			'get-taxonomy-contract',
			'Get taxonomy contract',
			'Returns only public registered taxonomies explicitly enabled by the active Site Contract for a selected Blueprint, including the required search, optional creation, assignment, and verification workflow.',
			$this->page_type_schema(),
			array( $this, 'get_taxonomy_contract' ),
			true,
			$this->taxonomy_contract_output_schema()
		);
		$this->register_ability(
			'search-taxonomy-terms',
			'Search taxonomy terms',
			'Resolves public term names or exact durable slugs to term IDs in one Site Contract-approved taxonomy. It does not create, edit, delete, or assign terms.',
			$this->taxonomy_search_schema(),
			array( $this, 'search_taxonomy_terms' ),
			true,
			$this->taxonomy_search_output_schema()
		);
		$this->register_ability(
			'create-taxonomy-term',
			'Create taxonomy term',
			'Creates one public term in a Site Contract-approved taxonomy with a durable slug and standalone archive description. It never edits or deletes an existing term.',
			$this->taxonomy_create_schema(),
			array( $this, 'create_taxonomy_term' ),
			false,
			$this->taxonomy_term_output_schema()
		);
		$this->register_ability(
			'assign-taxonomy-terms',
			'Assign taxonomy terms',
			'Assigns existing approved taxonomy terms only to a Composer-owned draft assigned to the current agent, with explicit confirmation and optimistic concurrency.',
			$this->taxonomy_assignment_schema(),
			array( $this, 'assign_taxonomy_terms' ),
			false,
			$this->taxonomy_assignment_output_schema()
		);
		$this->register_ability(
			'inspect-taxonomy-terms',
			'Inspect assigned taxonomy terms',
			'Returns Site Contract-approved taxonomy relationships and fresh concurrency tokens for one Composer-owned draft assigned to the current agent.',
			$this->taxonomy_inspection_schema(),
			array( $this, 'inspect_taxonomy_terms' ),
			true,
			$this->taxonomy_inspection_output_schema()
		);
		$this->register_ability(
			'list-content-drafts',
			'List editable content',
			'Lists only editable or adoptable content by status, post type, Blueprint, and Composer assignment state. A returned update proposal includes its reviewer change_request_reason and must be revised in the same working draft. Never use this ability to resolve relation target IDs; use search-relation-targets instead.',
			$this->draft_list_schema(),
			array( $this, 'list_content_drafts' ),
			true,
			$this->draft_list_output_schema()
		);
		$this->register_ability(
			'inspect-content-item',
			'Inspect existing content item',
			'Returns content and validation details only when the active Site Contract grants read access for the post type and the current WordPress user can read the item.',
			$this->content_inspection_schema(),
			array( $this, 'inspect_content_item' ),
			true
		);
		$this->register_ability(
			'clone-content-item',
			'Clone existing content to a Composer draft',
			'Copies an explicitly readable and cloneable item to a new, independent Composer-owned draft. This is not an update proposal and MUST NOT be used to revise a published canonical item; use create-content-proposal for that workflow. The source is unchanged and optimistic-concurrency tokens are required.',
			$this->content_clone_schema(),
			array( $this, 'clone_content_item' ),
			false
		);
		$this->register_ability(
			'link-content-draft-translations',
			'Link localized content drafts',
			'Links two or more separately authored Composer-owned drafts as translations. It cannot create copy, change published content, or publish a draft.',
			$this->localized_draft_link_schema(),
			array( $this, 'link_content_draft_translations' ),
			false
		);
		$this->register_ability(
			'attach-content-draft-to-translation-group',
			'Attach a localized draft to a translation group',
			'Adds one separately authored Composer-owned draft to an unoccupied language slot in an existing provider translation group. Existing group members and their statuses are preserved, and the draft is not published.',
			$this->localized_draft_group_attachment_schema(),
			array( $this, 'attach_content_draft_to_translation_group' ),
			false
		);
		$this->register_ability(
			'attach-content-to-translation-group',
			'Attach localized content to a translation group',
			'Adds one inspected draft or published content item to an unoccupied language slot in an existing provider translation group. The source item must be unlinked, every existing member and publication status is preserved, and no content is published or rewritten.',
			$this->localized_content_group_attachment_schema(),
			array( $this, 'attach_content_to_translation_group' ),
			false
		);
		$this->register_ability(
			'merge-content-translation-groups',
			'Merge localized content translation groups',
			'Merges two exact, non-conflicting translation-group snapshots without changing content or publication status. Every member must be editable, no occupied language slot is overwritten, and a stale relationship snapshot is rejected.',
			$this->localized_group_merge_schema(),
			array( $this, 'merge_content_translation_groups' ),
			false
		);
		$this->register_ability(
			'create-content-proposal',
			'Create published-content update proposal',
			'Creates a separate agent-owned working copy of one published item when both the Site Contract and Blueprint opt in. The source remains unchanged and merge is not exposed to the agent. This only starts the workflow: after updating and validating the proposal, you MUST call submit-content-proposal with its freshest concurrency tokens so a human can review it. Do not report the proposal as ready while its state is working.',
			$this->content_proposal_create_schema(),
			array( $this, 'create_content_proposal' ),
			false
		);
		$this->register_ability(
			'submit-content-proposal',
			'Submit content proposal for human review',
			'Validates and freezes an assigned working proposal for a human reviewer. It cannot update published content.',
			$this->content_proposal_submit_schema(),
			array( $this, 'submit_content_proposal' ),
			false
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
			'Assembles and validates content, the blueprint-specific excerpt policy, and the SEO description without saving. The blueprint fixes the target type and template. Validation is not submission: after a published-content proposal passes validation and all updates are complete, call submit-content-proposal with its freshest concurrency tokens.',
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
			'Updates only a draft assigned to this agent, without changing its WordPress author, blueprint, post type, or template. Requires optimistic concurrency. If assignment_source is published-update-proposal, updating is not the final step: validate the completed proposal, then MUST call submit-content-proposal with the freshest modified_gmt and revision. Do not leave a completed proposal in working state or report it as ready for human review before submission succeeds.',
			$this->update_schema(),
			array( $this, 'update_own_draft' ),
			false
		);
		$this->register_ability(
			'get-draft',
			'Get assigned content draft',
			'Returns one draft assigned to this agent, including content, its current modification token, proposal state, and any reviewer change_request_reason.',
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
		$this->register_ability(
			'get-rendered-preview',
			'Get rendered draft preview',
			'Renders bounded, sanitized static HTML for one Composer-owned draft and returns preview metadata for MCP Apps. It does not execute shortcodes, frontend JavaScript, forms, or site template parts.',
			$this->rendered_preview_input_schema(),
			array( $this, 'get_rendered_preview' ),
			true,
			$this->rendered_preview_output_schema(),
			array(
				'_meta' => array(
					'ui' => array( 'resourceUri' => ComposerMcpServer::PREVIEW_RESOURCE_URI ),
					'openai/outputTemplate' => ComposerMcpServer::PREVIEW_RESOURCE_URI,
					'openai/toolInvocation/invoking' => 'Rendering draft preview…',
					'openai/toolInvocation/invoked' => 'Draft preview ready',
				),
			)
		);
		$this->register_rendered_preview_resource();
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
				$localization_providers = $this->localization->public_manifests();
				$policy     = $this->config->get_design_policy();
				$extensions = $this->config->get_block_extensions();
				$core       = (array) ( $extensions['allowed_core_blocks'] ?? array() );
				$query_loop = is_array( $extensions['query_loop_materializer'] ?? null )
					? $extensions['query_loop_materializer']
					: array();
				$field_access = is_array( $policy['content_field_access'] ?? null )
					? $policy['content_field_access']
					: array();
				$taxonomy_access = is_array( $policy['content_taxonomy_access'] ?? null )
					? $policy['content_taxonomy_access']
					: array();
				return array(
					'composer'                 => array(
						'name'    => 'SmartCloud Agent Composer',
						'version' => SMARTCLOUD_COMPOSER_VERSION,
						'execution_contract' => self::CONTRACT,
					),
					'abilities_api_available'  => function_exists( 'wp_get_ability' ),
					'block_extensions'         => array(
						'policy_and_blueprint_opt_in_required' => true,
						'passive_text_editor_html'             => in_array( 'core/freeform', $core, true )
							&& ! in_array( 'core/freeform', $policy['disallowed_blocks'], true )
							&& ! empty( $extensions['passive_text_editor_html'] ),
						'captioned_media_image_materializer'   => in_array( 'core/image', $core, true )
							&& ! in_array( 'core/image', $policy['disallowed_blocks'], true )
							&& ! empty( $extensions['captioned_media_image_materializer'] ),
						'query_loop_materializer'              => array(
							'enabled'                 => ! empty( $query_loop['enabled'] ),
							'allowed_post_types'      => array_values( (array) ( $query_loop['allowed_post_types'] ?? array() ) ),
							'allowed_taxonomies'      => array_values( (array) ( $query_loop['allowed_taxonomies'] ?? array() ) ),
							'allowed_orderby'         => array_values( (array) ( $query_loop['allowed_orderby'] ?? array() ) ),
							'allowed_template_blocks' => array_values( (array) ( $query_loop['allowed_template_blocks'] ?? array() ) ),
							'allowed_sticky_modes'     => array_values( (array) ( $query_loop['allowed_sticky_modes'] ?? array() ) ),
							'max_per_page'            => (int) ( $query_loop['max_per_page'] ?? 0 ),
							'max_offset'              => (int) ( $query_loop['max_offset'] ?? 0 ),
						),
						'content_field_materializer'           => array(
							'enabled'                         => ! empty( $field_access ),
							'allowlisted_post_type_count'     => count( $field_access ),
							'registered_rest_fields_required' => true,
							'composer_owned_draft_writes_only' => true,
							'explicit_confirmation_required'  => true,
						),
						'taxonomy_term_workflow'               => array(
							'enabled'                          => ! empty( $taxonomy_access ),
							'allowlisted_post_type_count'      => count( $taxonomy_access ),
							'search_before_create_required'    => true,
							'composer_owned_draft_writes_only' => true,
							'explicit_confirmation_required'   => true,
							'term_edit_supported'              => false,
							'term_delete_supported'            => false,
						),
						'remote_media_ingest'                  => array_merge(
							$this->config->get_remote_media_ingest_policy(),
							array(
								'controlled_ingest_capability_required' => true,
								'composer_owned_draft_assignment_only' => true,
							)
						),
						'text_editor_contract'                 => isset( $extensions['text_editor_contract'] ) && is_array( $extensions['text_editor_contract'] )
							? $extensions['text_editor_contract']
							: array(),
					),
					'draft_lifecycle'         => array(
						'assignment_separate_from_post_author' => true,
						'post_author_preserved_on_update'      => true,
						'explicit_adoption_available'          => true,
						'published_content_writable'           => false,
						'published_update_proposals'           => true,
						'proposal_merge_human_only'            => true,
					),
					'mcp'                     => array(
						'adapter_available' => class_exists( '\\WP\\MCP\\Core\\McpAdapter' ),
						'server_id'         => ComposerMcpServer::SERVER_ID,
						'endpoint'          => ComposerMcpServer::HTTP_ENDPOINT,
						'rendered_preview'  => array(
							'ability'        => self::PREFIX . 'get-rendered-preview',
							'resource_uri'   => ComposerMcpServer::PREVIEW_RESOURCE_URI,
							'scope'          => 'content',
							'fidelity'       => 'static',
							'max_html_bytes' => 500000,
						),
						'optional'          => true,
					),
					'component_providers'     => $providers,
					'component_provider_count' => count( $providers ),
					'localization_providers' => $localization_providers,
					'localization_provider_count' => count( $localization_providers ),
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
						'semantic_slots'            => $this->semantic_slots->slots_for_pattern( $name ),
					);
				}
				return array(
					'page_type'       => $blueprint['page_type'],
					'target_post_type' => $blueprint['target_post_type'],
					'target_template' => $blueprint['target_template'],
					'composition_mode' => $blueprint['composition_mode'],
					'content_language' => $blueprint['content_language'],
					'content_language_enforcement' => $blueprint['content_language_enforcement'],
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

	public function ingest_remote_media( array $input ): array|\WP_Error {
		return $this->execute( 'ingest-remote-media', $input, fn() => $this->remote_media->ingest( $input ) );
	}

	public function assign_featured_image( array $input ): array|\WP_Error {
		return $this->execute(
			'assign-featured-image',
			$input,
			fn() => $this->drafts->assign_featured_image( $input, absint( $input['attachment_id'] ?? 0 ) )
		);
	}

	public function insert_or_update_blocks( array $input ): array|\WP_Error {
		return $this->execute(
			'insert-or-update-blocks',
			$input,
			fn() => $this->drafts->insert_or_update_blocks( $input )
		);
	}

	public function materialize_query_loop( array $input ): array|\WP_Error {
		return $this->execute( 'materialize-query-loop', $input, fn() => $this->query_loops->materialize( $input ) );
	}

	public function get_content_field_contract( array $input ): array|\WP_Error {
		return $this->execute( 'get-content-field-contract', $input, fn() => $this->content_fields->contract( $input ) );
	}

	public function search_relation_targets( array $input ): array|\WP_Error {
		return $this->execute( 'search-relation-targets', $input, fn() => $this->content_fields->search_relation_targets( $input ) );
	}

	public function inspect_content_fields( array $input ): array|\WP_Error {
		return $this->execute( 'inspect-content-fields', $input, fn() => $this->content_fields->inspect( $input ) );
	}

	public function update_content_fields( array $input ): array|\WP_Error {
		return $this->execute( 'update-content-fields', $input, fn() => $this->content_fields->update( $input ) );
	}

	public function get_taxonomy_contract( array $input ): array|\WP_Error {
		return $this->execute( 'get-taxonomy-contract', $input, fn() => $this->taxonomy_terms->contract( $input ) );
	}

	public function search_taxonomy_terms( array $input ): array|\WP_Error {
		return $this->execute( 'search-taxonomy-terms', $input, fn() => $this->taxonomy_terms->search( $input ) );
	}

	public function create_taxonomy_term( array $input ): array|\WP_Error {
		return $this->execute( 'create-taxonomy-term', $input, fn() => $this->taxonomy_terms->create( $input ) );
	}

	public function assign_taxonomy_terms( array $input ): array|\WP_Error {
		return $this->execute( 'assign-taxonomy-terms', $input, fn() => $this->taxonomy_terms->assign( $input ) );
	}

	public function inspect_taxonomy_terms( array $input ): array|\WP_Error {
		return $this->execute( 'inspect-taxonomy-terms', $input, fn() => $this->taxonomy_terms->inspect( $input ) );
	}

	public function list_content_drafts( array $input ): array|\WP_Error {
		return $this->execute( 'list-content-drafts', $input, fn() => $this->drafts->list_content_drafts( $input ) );
	}

	public function inspect_content_item( array $input ): array|\WP_Error {
		return $this->execute( 'inspect-content-item', $input, fn() => $this->drafts->inspect_content_item( $input ) );
	}

	public function clone_content_item( array $input ): array|\WP_Error {
		return $this->execute( 'clone-content-item', $input, fn() => $this->drafts->clone_content_item( $input ) );
	}

	public function list_supported_content_languages( array $input ): array|\WP_Error {
		return $this->execute( 'list-supported-content-languages', $input, fn() => $this->localization->supported_languages() );
	}

	public function link_content_draft_translations( array $input ): array|\WP_Error {
		return $this->execute( 'link-content-draft-translations', $input, fn() => $this->localized_drafts->link( $input ) );
	}

	public function attach_content_draft_to_translation_group( array $input ): array|\WP_Error {
		return $this->execute( 'attach-content-draft-to-translation-group', $input, fn() => $this->localized_drafts->attach_to_group( $input ) );
	}

	public function attach_content_to_translation_group( array $input ): array|\WP_Error {
		return $this->execute( 'attach-content-to-translation-group', $input, fn() => $this->localized_drafts->attach_content_to_group( $input ) );
	}

	public function merge_content_translation_groups( array $input ): array|\WP_Error {
		return $this->execute( 'merge-content-translation-groups', $input, fn() => $this->localized_drafts->merge_groups( $input ) );
	}

	public function create_content_proposal( array $input ): array|\WP_Error {
		return $this->execute( 'create-content-proposal', $input, fn() => $this->proposals->create( $input ) );
	}

	public function submit_content_proposal( array $input ): array|\WP_Error {
		return $this->execute( 'submit-content-proposal', $input, fn() => $this->proposals->submit( $input ) );
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
		return $this->execute( 'validate-page-draft', $input, fn() => $this->validate_candidate( $input ) );
	}

	public function validate_content_draft( array $input ): array|\WP_Error {
		return $this->execute( 'validate-content-draft', $input, fn() => $this->validate_candidate( $input ) );
	}

	public function create_page_draft( array $input ): array|\WP_Error {
		return $this->execute( 'create-page-draft', $input, fn() => $this->create_candidate( $input ) );
	}

	public function create_content_draft( array $input ): array|\WP_Error {
		return $this->execute( 'create-content-draft', $input, fn() => $this->create_candidate( $input ) );
	}

	public function update_own_draft( array $input ): array|\WP_Error {
		return $this->execute( 'update-own-draft', $input, function () use ( $input ): array {
			$fields = $this->content_fields->prepare_values( (string) ( $input['page_type'] ?? '' ), $input['fields'] ?? null, absint( $input['post_id'] ?? 0 ) );
			return $this->drafts->update( $input, $fields );
		} );
	}

	private function validate_candidate( array $input ): array {
		$fields = $this->content_fields->prepare_values( (string) ( $input['page_type'] ?? '' ), $input['fields'] ?? null );
		$result = $this->drafts->validate_request( $input );
		$result['fields'] = $fields;
		return $result;
	}

	private function create_candidate( array $input ): array {
		$fields = $this->content_fields->prepare_values( (string) ( $input['page_type'] ?? '' ), $input['fields'] ?? null );
		return $this->drafts->create( $input, $fields );
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

	public function get_rendered_preview( array $input ): array|\WP_Error {
		return $this->execute( 'get-rendered-preview', $input, fn() => $this->rendered_previews->get( $input ) );
	}

	public function check_permission( mixed $input = null ): bool {
		return is_user_logged_in()
			&& current_user_can( \SmartCloud\AgentComposer\Infrastructure\WordPress\Activation::CAP_USE )
			&& current_user_can( \SmartCloud\AgentComposer\Infrastructure\WordPress\Activation::CAP_EXECUTE_DRAFTS )
			&& current_user_can( 'read' )
			&& current_user_can( 'edit_pages' )
			&& current_user_can( 'edit_posts' );
	}

	private function register_ability( string $slug, string $label, string $description, array $input_schema, callable $callback, bool $read_only, ?array $output_schema = null, array $mcp_meta = array() ): void {
		wp_register_ability(
			self::PREFIX . $slug,
			array(
					'label'               => $label,
					'description'         => $description,
				'category'            => self::CATEGORY,
				'input_schema'        => $input_schema,
				'output_schema'       => $output_schema ?? array( 'type' => 'object', 'additionalProperties' => true ),
				'execute_callback'    => $callback,
				'permission_callback' => array( $this, 'check_permission' ),
				'meta'                => array(
					'show_in_rest' => false,
					'mcp'          => array_merge( array( 'public' => false ), $mcp_meta ),
					'annotations' => array(
						'readonly'    => $read_only,
						'destructive' => false,
						'idempotent'  => $read_only || in_array(
							$slug,
							array( 'create-page-draft', 'create-content-draft', 'create-content-proposal', 'submit-content-proposal', 'adopt-content-draft', 'assign-featured-image', 'ingest-remote-media', 'create-taxonomy-term', 'assign-taxonomy-terms', 'link-content-draft-translations', 'attach-content-draft-to-translation-group', 'attach-content-to-translation-group', 'merge-content-translation-groups' ),
							true
						),
					),
				),
			)
		);
	}

	private function register_rendered_preview_resource(): void {
		$name = self::PREFIX . 'rendered-preview-app';
		if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $name ) ) {
			return;
		}
		wp_register_ability(
			$name,
			array(
				'label'               => 'Rendered preview app',
				'description'         => 'MCP Apps UI resource for displaying a sanitized Composer draft preview.',
				'category'            => self::CATEGORY,
				'input_schema'        => $this->empty_schema(),
				'output_schema'       => array( 'type' => 'array', 'items' => array( 'type' => 'object', 'additionalProperties' => true ) ),
				'execute_callback'    => array( $this, 'rendered_preview_resource' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'meta'                => array(
					'show_in_rest' => false,
					'mcp'          => array(
						'public'      => false,
						'type'        => 'resource',
						'uri'         => ComposerMcpServer::PREVIEW_RESOURCE_URI,
						'name'        => 'Composer rendered preview',
						'title'       => 'Composer rendered preview',
						'description' => 'Displays a sanitized static HTML preview returned by get-rendered-preview.',
						'mimeType'    => 'text/html;profile=mcp-app',
						'_meta'       => array( 'ui' => $this->rendered_preview_ui_meta() ),
						'annotations' => array( 'audience' => array( 'user', 'assistant' ), 'priority' => 0.9 ),
					),
				),
			)
		);
	}

	public function rendered_preview_resource( array $input = array() ): array {
		return array(
			array(
				'uri'      => ComposerMcpServer::PREVIEW_RESOURCE_URI,
				'mimeType' => 'text/html;profile=mcp-app',
				'text'     => $this->rendered_preview_app_html(),
				'_meta'    => array( 'ui' => $this->rendered_preview_ui_meta() ),
			),
		);
	}

	private function rendered_preview_ui_meta(): array {
		return array(
			'prefersBorder' => true,
			'csp'           => array(
				'connectDomains'  => array(),
				'resourceDomains' => Rendered_Preview_Service::allowed_asset_origins(),
				'frameDomains'    => array(),
			),
		);
	}

	private function rendered_preview_app_html(): string {
		return <<<'HTML'
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>
:root{color-scheme:light dark;font:14px/1.5 system-ui,sans-serif}body{margin:0;padding:16px;background:transparent;color:CanvasText}.meta{display:flex;gap:12px;align-items:center;margin:0 0 12px;color:GrayText;font-size:12px}.preview{overflow:auto;padding:clamp(16px,4vw,40px);border:1px solid color-mix(in srgb,CanvasText 16%,transparent);border-radius:12px;background:Canvas}.preview h1{font-size:clamp(1.75rem,4vw,3rem);line-height:1.1}.preview img{max-width:100%;height:auto}.preview table{display:block;max-width:100%;overflow:auto}.preview a{color:LinkText}.warnings{margin:12px 0 0;padding-left:20px;color:GrayText}.empty{padding:24px;text-align:center;color:GrayText}
</style></head><body><div class="meta"><strong id="title">Draft preview</strong><span id="details"></span></div><main id="preview" class="preview"><p class="empty">Waiting for rendered preview data…</p></main><ul id="warnings" class="warnings" hidden></ul><script>
const preview=document.getElementById('preview');const title=document.getElementById('title');const details=document.getElementById('details');const warnings=document.getElementById('warnings');
function sanitize(html){const parsed=new DOMParser().parseFromString(String(html||''),'text/html');parsed.querySelectorAll('script,iframe,object,embed,base,meta,link,style,form,input,button,textarea,select').forEach((node)=>node.remove());parsed.querySelectorAll('*').forEach((node)=>{for(const attr of [...node.attributes]){const name=attr.name.toLowerCase();if(name.startsWith('on')||name==='srcdoc'||name==='style')node.removeAttribute(attr.name)}});return [...parsed.body.childNodes]}
function render(payload){const result=payload?.structuredContent??payload;const data=result?.document?result:result?.result;const doc=data?.document;if(!doc){return}title.textContent=doc.title||'Draft preview';details.textContent=`${doc.content_language||''} · ${doc.byte_length||0} bytes`;preview.replaceChildren(...sanitize(doc.html));preview.setAttribute('dir',doc.direction==='rtl'?'rtl':'ltr');preview.setAttribute('lang',doc.content_language||'en');warnings.replaceChildren();for(const warning of doc.warnings||[]){const item=document.createElement('li');item.textContent=warning.message||warning.code||'Preview warning';warnings.append(item)}warnings.hidden=!warnings.children.length}
preview.addEventListener('click',(event)=>{if(event.target.closest('a'))event.preventDefault()},{capture:true});
window.addEventListener('message',(event)=>{if(event.source!==window.parent)return;const message=event.data;if(!message||message.jsonrpc!=='2.0')return;if(message.method==='ui/notifications/tool-result')render(message.params)},{passive:true});
</script></body></html>
HTML;
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
			$object_id = is_array( $result ) ? absint( $result['post_id'] ?? $result['term_id'] ?? 0 ) : 0;
			$this->audit->log( $operation, 'success', $input, $object_id );
			if ( is_array( $result ) ) {
				$result['_request_id'] = $this->audit->get_request_id();
			}
			return $result;
		} catch ( Execution_Exception $error ) {
			$post_id  = absint( $input['post_id'] ?? 0 );
			$conflict = in_array(
				$error->get_execution_code(),
				array( 'edit_conflict', 'draft_assigned_to_other_agent', 'taxonomy_term_conflict', 'proposal_creation_conflict', 'proposal_assigned_to_other_agent', 'localization_context_conflict', 'localized_content_group_conflict', 'localized_group_snapshot_conflict', 'localized_group_language_slot_conflict', 'localized_group_member_conflict' ),
				true
			);
			$this->audit->log( $operation, 'error', $input, $post_id, $error->get_execution_code(), array( 'conflict' => $conflict ) );
			$denied = in_array( $error->get_execution_code(), array( 'taxonomy_term_create_denied', 'taxonomy_assignment_denied', 'proposal_create_denied', 'proposal_source_read_denied', 'localization_content_read_denied', 'localized_content_edit_forbidden' ), true );
			$status = $conflict ? 409 : ( $denied ? 403 : 400 );
			return new \WP_Error( 'smartcloud_agent_' . $error->get_execution_code(), $error->getMessage(), array( 'status' => $status, 'request_id' => $this->audit->get_request_id() ) );
		} catch ( \Throwable $error ) {
			$this->audit->log( $operation, 'error', $input, absint( $input['post_id'] ?? 0 ), 'internal_error' );
			do_action( 'smartcloud_composer_internal_error', $error, $operation );
			return new \WP_Error( 'smartcloud_agent_internal_error', 'The ability failed unexpectedly.', array( 'status' => 500, 'request_id' => $this->audit->get_request_id() ) );
		}
	}

	private function rendered_preview_input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'post_id'               => array( 'type' => 'integer', 'minimum' => 1 ),
				'expected_modified_gmt' => array( 'type' => 'string', 'format' => 'date-time' ),
				'expected_revision'     => array( 'type' => 'string', 'format' => 'uuid' ),
			),
			'required'             => array( 'post_id' ),
			'additionalProperties' => false,
		);
	}

	private function rendered_preview_output_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'post_id'     => array( 'type' => 'integer', 'minimum' => 1 ),
				'edit_url'    => array( 'type' => 'string' ),
				'preview_url' => array( 'type' => 'string' ),
				'validation'  => array( 'type' => 'object', 'additionalProperties' => true ),
				'document'    => array(
					'type'                 => 'object',
					'properties'           => array(
						'contract_version' => array( 'type' => 'string', 'enum' => array( '1' ) ),
						'post_id'          => array( 'type' => 'integer', 'minimum' => 1 ),
						'modified_gmt'      => array( 'type' => 'string', 'format' => 'date-time' ),
						'revision'          => array( 'type' => 'string', 'format' => 'uuid' ),
						'title'             => array( 'type' => 'string' ),
						'content_language'  => array( 'type' => 'string', 'minLength' => 2, 'maxLength' => 35 ),
						'direction'         => array( 'type' => 'string', 'enum' => array( 'ltr', 'rtl' ) ),
						'scope'             => array( 'type' => 'string', 'enum' => array( 'content', 'theme-document' ) ),
						'fidelity'          => array( 'type' => 'string', 'enum' => array( 'static' ) ),
						'mime_type'         => array( 'type' => 'string', 'enum' => array( 'text/html' ) ),
						'html'              => array( 'type' => 'string', 'maxLength' => 500000 ),
						'sha256'            => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ),
						'byte_length'       => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 500000 ),
						'assets'            => array(
							'type'     => 'array',
							'maxItems' => 250,
							'items'    => array(
								'type'                 => 'object',
								'properties'           => array(
									'kind'   => array( 'type' => 'string', 'enum' => array( 'image', 'stylesheet', 'font' ) ),
									'url'    => array( 'type' => 'string' ),
									'origin' => array( 'type' => 'string' ),
								),
								'required'             => array( 'kind', 'url', 'origin' ),
								'additionalProperties' => false,
							),
						),
						'warnings'          => array(
							'type'     => 'array',
							'maxItems' => 250,
							'items'    => array(
								'type'                 => 'object',
								'properties'           => array(
									'code'       => array( 'type' => 'string' ),
									'message'    => array( 'type' => 'string' ),
									'block_name' => array( 'type' => 'string' ),
								),
								'required'             => array( 'code', 'message' ),
								'additionalProperties' => false,
							),
						),
					),
					'required'             => array( 'contract_version', 'post_id', 'modified_gmt', 'revision', 'title', 'content_language', 'direction', 'scope', 'fidelity', 'mime_type', 'html', 'sha256', 'byte_length', 'assets', 'warnings' ),
					'additionalProperties' => false,
				),
				'_request_id' => array( 'type' => 'string' ),
			),
			'required'             => array( 'post_id', 'edit_url', 'preview_url', 'validation', 'document', '_request_id' ),
			'additionalProperties' => false,
		);
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

	public function localized_draft_link_schema(): array {
		return array(
			'type' => 'object',
			'properties' => array(
				'page_type' => $this->string_property( 'Blueprint page type shared by every draft.', 1, 64 ),
				'drafts' => array(
					'type' => 'array',
					'minItems' => 2,
					'maxItems' => 20,
					'items' => array(
						'type' => 'object',
						'properties' => array(
							'post_id' => array( 'type' => 'integer', 'minimum' => 1 ),
							'content_language' => $this->string_property( 'Immutable BCP 47 language returned with the Composer-owned draft.', 2, 35 ),
							'expected_modified_gmt' => array( 'type' => 'string', 'format' => 'date-time' ),
							'expected_revision' => array( 'type' => 'string', 'format' => 'uuid' ),
						),
						'required' => array( 'post_id', 'content_language', 'expected_modified_gmt', 'expected_revision' ),
						'additionalProperties' => false,
					),
				),
				'confirm_link' => array( 'type' => 'boolean', 'enum' => array( true ) ),
			),
			'required' => array( 'page_type', 'drafts', 'confirm_link' ),
			'additionalProperties' => false,
		);
	}

	public function localized_draft_group_attachment_schema(): array {
		return array(
			'type' => 'object',
			'properties' => array(
				'page_type' => $this->string_property( 'Blueprint page type shared by the group and draft.', 1, 64 ),
				'anchor_post_id' => array( 'type' => 'integer', 'minimum' => 1 ),
				'expected_localization_group' => $this->string_property( 'Exact provider group identifier returned in inspect-content-item localization data before attachment.', 1, 128 ),
				'draft' => array(
					'type' => 'object',
					'properties' => array(
						'post_id' => array( 'type' => 'integer', 'minimum' => 1 ),
						'content_language' => $this->string_property( 'Immutable BCP 47 language returned with the Composer-owned draft.', 2, 35 ),
						'expected_modified_gmt' => array( 'type' => 'string', 'format' => 'date-time' ),
						'expected_revision' => array( 'type' => 'string', 'format' => 'uuid' ),
					),
					'required' => array( 'post_id', 'content_language', 'expected_modified_gmt', 'expected_revision' ),
					'additionalProperties' => false,
				),
				'confirm_attach' => array( 'type' => 'boolean', 'enum' => array( true ) ),
			),
			'required' => array( 'page_type', 'anchor_post_id', 'expected_localization_group', 'draft', 'confirm_attach' ),
			'additionalProperties' => false,
		);
	}

	public function localized_content_group_attachment_schema(): array {
		return array(
			'type' => 'object',
			'properties' => array(
				'page_type' => $this->string_property( 'Blueprint page type shared by the target group and content item.', 1, 64 ),
				'anchor_post_id' => array( 'type' => 'integer', 'minimum' => 1 ),
				'expected_localization_group' => $this->string_property( 'Exact target provider group identifier returned by inspect-content-item.', 1, 128 ),
				'content' => array(
					'type' => 'object',
					'properties' => array(
						'post_id' => array( 'type' => 'integer', 'minimum' => 1 ),
						'content_language' => $this->string_property( 'Exact BCP 47 language returned for the inspected draft or published item.', 2, 35 ),
						'expected_modified_gmt' => array( 'type' => 'string', 'format' => 'date-time' ),
						'expected_content_hash' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ),
					),
					'required' => array( 'post_id', 'content_language', 'expected_modified_gmt', 'expected_content_hash' ),
					'additionalProperties' => false,
				),
				'confirm_attach' => array( 'type' => 'boolean', 'enum' => array( true ) ),
			),
			'required' => array( 'page_type', 'anchor_post_id', 'expected_localization_group', 'content', 'confirm_attach' ),
			'additionalProperties' => false,
		);
	}

	public function localized_group_merge_schema(): array {
		$group = array(
			'type' => 'object',
			'properties' => array(
				'anchor_post_id' => array( 'type' => 'integer', 'minimum' => 1 ),
				'expected_localization_group' => $this->string_property( 'Exact provider group identifier returned by inspect-content-item.', 1, 128 ),
				'translations' => array(
					'type' => 'array',
					'minItems' => 1,
					'maxItems' => 20,
					'items' => array(
						'type' => 'object',
						'properties' => array(
							'language_code' => $this->string_property( 'Exact provider language code returned in the localization translation map.', 1, 35 ),
							'post_id' => array( 'type' => 'integer', 'minimum' => 1 ),
						),
						'required' => array( 'language_code', 'post_id' ),
						'additionalProperties' => false,
					),
				),
			),
			'required' => array( 'anchor_post_id', 'expected_localization_group', 'translations' ),
			'additionalProperties' => false,
		);
		return array(
			'type' => 'object',
			'properties' => array(
				'page_type' => $this->string_property( 'Blueprint page type shared by every group member.', 1, 64 ),
				'target' => $group,
				'source' => $group,
				'confirm_merge' => array( 'type' => 'boolean', 'enum' => array( true ) ),
			),
			'required' => array( 'page_type', 'target', 'source', 'confirm_merge' ),
			'additionalProperties' => false,
		);
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

	public function remote_media_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'source_url'      => array( 'type' => 'string', 'format' => 'uri', 'minLength' => 10, 'maxLength' => 2048 ),
				'idempotency_key' => array( 'type' => 'string', 'minLength' => 8, 'maxLength' => 128, 'pattern' => '^[A-Za-z0-9._:-]+$' ),
				'title'           => $this->string_property( 'Media Library title.', 0, 200 ),
				'alt'             => $this->string_property( 'Accessible alternative text.', 0, 500 ),
				'caption'         => $this->string_property( 'Optional plain-text Media Library caption.', 0, 1000 ),
				'featured_for'    => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_id'               => array( 'type' => 'integer', 'minimum' => 1 ),
						'page_type'             => $this->string_property( 'Immutable Blueprint page type assigned to the draft.', 1, 64 ),
						'expected_modified_gmt' => array( 'type' => 'string', 'format' => 'date-time' ),
						'expected_revision'     => array( 'type' => 'string', 'format' => 'uuid' ),
					),
					'required'             => array( 'post_id', 'page_type', 'expected_modified_gmt', 'expected_revision' ),
					'additionalProperties' => false,
				),
			),
			'required'             => array( 'source_url', 'idempotency_key' ),
			'additionalProperties' => false,
		);
	}

	public function featured_image_assignment_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'post_id'               => array( 'type' => 'integer', 'minimum' => 1 ),
				'page_type'             => $this->string_property( 'Immutable Blueprint page type assigned to the draft.', 1, 64 ),
				'attachment_id'         => array( 'type' => 'integer', 'minimum' => 1 ),
				'expected_modified_gmt' => array( 'type' => 'string', 'format' => 'date-time' ),
				'expected_revision'     => array( 'type' => 'string', 'format' => 'uuid' ),
			),
			'required'             => array( 'post_id', 'page_type', 'attachment_id', 'expected_modified_gmt', 'expected_revision' ),
			'additionalProperties' => false,
		);
	}

	public function query_loop_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'page_type'          => $this->string_property( 'Blueprint page type that explicitly enables the constrained Query Loop blocks.', 1, 64 ),
				'post_type'          => $this->string_property( 'Site Contract-approved public post type.', 1, 64 ),
				'per_page'           => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 24, 'default' => 6 ),
				'offset'             => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 500, 'default' => 0 ),
				'orderby'            => array( 'type' => 'string', 'enum' => array( 'date', 'modified', 'title', 'menu_order' ), 'default' => 'date' ),
				'order'              => array( 'type' => 'string', 'enum' => array( 'ASC', 'DESC' ), 'default' => 'DESC' ),
				'columns'            => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 4, 'default' => 3 ),
				'show_featured_image' => array( 'type' => 'boolean', 'default' => false ),
				'show_date'          => array( 'type' => 'boolean', 'default' => false ),
				'show_excerpt'       => array( 'type' => 'boolean', 'default' => false ),
				'show_author'        => array( 'type' => 'boolean', 'default' => false ),
				'sticky_mode'        => array( 'type' => 'string', 'enum' => array( 'include', 'only', 'exclude' ), 'default' => 'include' ),
				'pagination'         => array( 'type' => 'boolean', 'default' => true ),
				'taxonomy_filters'   => array(
					'type'     => 'array',
					'maxItems' => 5,
					'default'  => array(),
					'items'    => array(
						'type'                 => 'object',
						'properties'           => array(
							'taxonomy' => $this->string_property( 'Site Contract-approved public taxonomy.', 1, 64 ),
							'term_ids' => array(
								'type'     => 'array',
								'minItems' => 1,
								'maxItems' => 20,
								'items'    => array( 'type' => 'integer', 'minimum' => 1 ),
							),
						),
						'required'             => array( 'taxonomy', 'term_ids' ),
						'additionalProperties' => false,
					),
				),
			),
			'required'             => array( 'page_type', 'post_type' ),
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
				'search'     => $this->string_property( 'Optional title, slug, or content search terms.', 0, 200 ),
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

	public function draft_list_output_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'purpose'     => array( 'type' => 'string', 'enum' => array( 'editable-content-discovery' ) ),
				'items'       => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'properties'           => array(
							'post_id'   => array( 'type' => 'integer', 'minimum' => 1 ),
							'title'     => array( 'type' => 'string' ),
							'status'    => array( 'type' => 'string' ),
							'post_type' => array( 'type' => 'string' ),
							'page_type' => array( 'type' => 'string' ),
						),
						'required'             => array( 'post_id', 'title', 'status', 'post_type', 'page_type' ),
						'additionalProperties' => true,
					),
				),
				'count'       => array( 'type' => 'integer', 'minimum' => 0 ),
				'total'       => array( 'type' => 'integer', 'minimum' => 0, 'description' => 'Total records visible after Composer policy and WordPress capability filtering.' ),
				'has_more'    => array( 'type' => 'boolean' ),
				'limit'       => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 50 ),
				'offset'      => array( 'type' => 'integer', 'minimum' => 0 ),
				'filters'     => array( 'type' => 'object', 'additionalProperties' => true ),
				'content_included' => array( 'type' => 'boolean', 'enum' => array( false ) ),
				'relation_target_lookup_supported' => array( 'type' => 'boolean', 'enum' => array( false ) ),
				'relation_target_lookup_ability' => array( 'type' => 'string', 'enum' => array( self::PREFIX . 'search-relation-targets' ) ),
			),
			'required'             => array( 'purpose', 'items', 'count', 'total', 'has_more', 'limit', 'offset', 'filters', 'content_included', 'relation_target_lookup_supported', 'relation_target_lookup_ability' ),
			'additionalProperties' => true,
		);
	}

	public function content_inspection_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'post_id'   => array( 'type' => 'integer', 'minimum' => 1 ),
				'page_type' => $this->string_property( 'Blueprint page type used to validate the existing item.', 1, 64 ),
			),
			'required'             => array( 'post_id', 'page_type' ),
			'additionalProperties' => false,
		);
	}

	public function content_field_inspection_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'post_id'   => array( 'type' => 'integer', 'minimum' => 1 ),
				'page_type' => $this->string_property( 'Blueprint page type whose target and field contract must match the content item.', 1, 64 ),
			),
			'required'             => array( 'post_id', 'page_type' ),
			'additionalProperties' => false,
		);
	}

	public function relation_target_search_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'page_type'     => $this->string_property( 'Blueprint page type whose active Site Contract declares the relation.', 1, 64 ),
				'relation_field' => $this->string_property( 'Registered relation meta key returned by get-content-field-contract.', 1, 191 ),
				'query'         => $this->string_property( 'Optional human title search or exact slug.', 0, 200 ),
				'limit'         => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 20 ),
			),
			'required'             => array( 'page_type', 'relation_field' ),
			'additionalProperties' => false,
		);
	}

	public function relation_target_search_output_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'purpose'        => array( 'type' => 'string', 'enum' => array( 'relation-target-resolution' ) ),
				'page_type'      => array( 'type' => 'string' ),
				'source_post_type' => array( 'type' => 'string' ),
				'relation_field' => array( 'type' => 'string' ),
				'matches'        => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'properties'           => array(
							'id'          => array( 'type' => 'integer', 'minimum' => 1 ),
							'title'       => array( 'type' => 'string' ),
							'slug'        => array( 'type' => 'string' ),
							'post_type'   => array( 'type' => 'string' ),
							'post_status' => array( 'type' => 'string' ),
							'match'       => array( 'type' => 'object', 'additionalProperties' => true ),
						),
						'required'             => array( 'id', 'title', 'slug', 'post_type', 'post_status', 'match' ),
						'additionalProperties' => false,
					),
				),
				'match_count'    => array( 'type' => 'integer', 'minimum' => 0 ),
				'result_id_path' => array( 'type' => 'string', 'enum' => array( 'matches[].id' ) ),
				'next_ability'   => array( 'type' => 'string', 'enum' => array( self::PREFIX . 'update-content-fields' ) ),
			),
			'required'             => array( 'purpose', 'page_type', 'source_post_type', 'relation_field', 'matches', 'match_count', 'result_id_path', 'next_ability' ),
			'additionalProperties' => true,
		);
	}

	public function content_field_contract_output_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'page_type'        => array( 'type' => 'string' ),
				'target_post_type' => array( 'type' => 'string' ),
				'fields'           => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'properties'           => array(
							'key'                => array( 'type' => 'string' ),
							'type'               => array( 'type' => 'string' ),
							'read'               => array( 'type' => 'boolean' ),
							'write'              => array( 'type' => 'boolean' ),
							'semantic_type'      => array( 'type' => 'string' ),
							'execution_workflow' => array( 'type' => 'object', 'additionalProperties' => true ),
						),
						'required'             => array( 'key', 'type', 'read', 'write' ),
						'additionalProperties' => true,
					),
				),
				'write_boundary'   => array( 'type' => 'string' ),
				'delete_supported' => array( 'type' => 'boolean' ),
				'relation_workflow' => array( 'type' => 'object', 'additionalProperties' => true ),
			),
			'required'             => array( 'page_type', 'target_post_type', 'fields', 'write_boundary', 'delete_supported', 'relation_workflow' ),
			'additionalProperties' => true,
		);
	}

	public function content_field_update_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'post_id'               => array( 'type' => 'integer', 'minimum' => 1 ),
				'page_type'             => $this->string_property( 'Immutable Blueprint page type assigned to the draft.', 1, 64 ),
				'expected_modified_gmt' => array( 'type' => 'string', 'format' => 'date-time' ),
				'expected_revision'     => array( 'type' => 'string', 'format' => 'uuid' ),
				'fields'                => array(
					'type'                 => 'object',
					'minProperties'        => 1,
					'maxProperties'        => 100,
					'additionalProperties' => true,
				),
				'confirm_update'        => array( 'type' => 'boolean', 'enum' => array( true ) ),
			),
			'required'             => array( 'post_id', 'page_type', 'expected_modified_gmt', 'expected_revision', 'fields', 'confirm_update' ),
			'additionalProperties' => false,
		);
	}

	public function taxonomy_search_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'page_type' => $this->string_property( 'Blueprint page type whose target declares taxonomy access.', 1, 64 ),
				'taxonomy'  => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 32, 'pattern' => '^[a-z0-9_-]+$' ),
				'query'     => $this->string_property( 'Optional public term-name search or exact durable slug.', 0, 200 ),
				'limit'     => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 20 ),
				'offset'    => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 5000, 'default' => 0 ),
			),
			'required'             => array( 'page_type', 'taxonomy' ),
			'additionalProperties' => false,
		);
	}

	public function taxonomy_create_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'page_type'       => $this->string_property( 'Blueprint page type whose target declares taxonomy creation.', 1, 64 ),
				'content_language' => $this->string_property( 'Exact effective BCP 47 content language returned by the selected Blueprint.', 2, 35 ),
				'taxonomy'        => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 32, 'pattern' => '^[a-z0-9_-]+$' ),
				'name'            => $this->string_property( 'Concise public navigation label and archive title.', 1, 200 ),
				'slug'            => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 200, 'pattern' => '^[a-z0-9]+(?:-[a-z0-9]+)*$' ),
				'description'     => $this->string_property( 'Standalone source-supported archive description.', 1, 2000 ),
				'parent_slug'     => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 200, 'pattern' => '^[a-z0-9]+(?:-[a-z0-9]+)*$' ),
				'confirm_create'  => array( 'type' => 'boolean', 'enum' => array( true ) ),
			),
			'required'             => array( 'page_type', 'content_language', 'taxonomy', 'name', 'slug', 'description', 'confirm_create' ),
			'additionalProperties' => false,
		);
	}

	public function taxonomy_assignment_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'post_id'               => array( 'type' => 'integer', 'minimum' => 1 ),
				'page_type'             => $this->string_property( 'Immutable Blueprint page type assigned to the draft.', 1, 64 ),
				'taxonomy'              => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 32, 'pattern' => '^[a-z0-9_-]+$' ),
				'term_ids'              => array(
					'type'        => 'array',
					'minItems'    => 1,
					'maxItems'    => 100,
					'uniqueItems' => true,
					'items'       => array( 'type' => 'integer', 'minimum' => 1 ),
				),
				'mode'                  => array( 'type' => 'string', 'enum' => array( 'replace', 'append' ), 'default' => 'replace' ),
				'expected_modified_gmt' => array( 'type' => 'string', 'format' => 'date-time' ),
				'expected_revision'     => array( 'type' => 'string', 'format' => 'uuid' ),
				'confirm_assignment'    => array( 'type' => 'boolean', 'enum' => array( true ) ),
			),
			'required'             => array( 'post_id', 'page_type', 'taxonomy', 'term_ids', 'mode', 'expected_modified_gmt', 'expected_revision', 'confirm_assignment' ),
			'additionalProperties' => false,
		);
	}

	public function taxonomy_inspection_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'post_id'   => array( 'type' => 'integer', 'minimum' => 1 ),
				'page_type' => $this->string_property( 'Immutable Blueprint page type assigned to the draft.', 1, 64 ),
				'taxonomy'  => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 32, 'pattern' => '^[a-z0-9_-]+$' ),
			),
			'required'             => array( 'post_id', 'page_type' ),
			'additionalProperties' => false,
		);
	}

	public function taxonomy_contract_output_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'page_type'        => array( 'type' => 'string' ),
				'target_post_type' => array( 'type' => 'string' ),
				'content_language' => array( 'type' => 'string' ),
				'taxonomies'       => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'properties'           => array(
							'taxonomy'     => array( 'type' => 'string' ),
							'label'        => array( 'type' => 'string' ),
							'plural_label' => array( 'type' => 'string' ),
							'description'  => array( 'type' => 'string' ),
							'hierarchical' => array( 'type' => 'boolean' ),
							'search'       => array( 'type' => 'boolean' ),
							'create'       => array( 'type' => 'boolean' ),
							'assign'       => array( 'type' => 'boolean' ),
							'maximum_items' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100 ),
							'assignment_mode' => array( 'type' => 'string', 'enum' => array( 'replace', 'append' ) ),
							'creation_parent_policy' => array( 'type' => 'string', 'enum' => array( 'root-only', 'allowlist' ) ),
							'creation_parent_slugs' => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
							'current_user_can_create' => array( 'type' => 'boolean' ),
							'current_user_can_assign' => array( 'type' => 'boolean' ),
						),
						'required'             => array( 'taxonomy', 'label', 'plural_label', 'description', 'hierarchical', 'search', 'create', 'assign', 'maximum_items', 'assignment_mode', 'creation_parent_policy', 'creation_parent_slugs', 'current_user_can_create', 'current_user_can_assign' ),
						'additionalProperties' => false,
					),
				),
				'write_boundary'        => array( 'type' => 'string', 'enum' => array( 'composer-owned-assigned-draft' ) ),
				'term_edit_supported'   => array( 'type' => 'boolean', 'enum' => array( false ) ),
				'term_delete_supported' => array( 'type' => 'boolean', 'enum' => array( false ) ),
				'workflow'              => array( 'type' => 'object', 'additionalProperties' => true ),
			),
			'required'             => array( 'page_type', 'target_post_type', 'content_language', 'taxonomies', 'write_boundary', 'term_edit_supported', 'term_delete_supported', 'workflow' ),
			'additionalProperties' => true,
		);
	}

	public function taxonomy_search_output_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'purpose'          => array( 'type' => 'string', 'enum' => array( 'taxonomy-term-resolution' ) ),
				'page_type'        => array( 'type' => 'string' ),
				'target_post_type' => array( 'type' => 'string' ),
				'taxonomy'         => array( 'type' => 'string' ),
				'query'            => array( 'type' => 'string' ),
				'matches'          => array( 'type' => 'array', 'items' => $this->taxonomy_term_item_schema( true ) ),
				'match_count'      => array( 'type' => 'integer', 'minimum' => 0 ),
				'limit'            => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 50 ),
				'offset'           => array( 'type' => 'integer', 'minimum' => 0 ),
				'has_more'         => array( 'type' => 'boolean' ),
				'result_id_path'   => array( 'type' => 'string', 'enum' => array( 'matches[].term_id' ) ),
				'next_abilities'   => array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ),
			),
			'required'             => array( 'purpose', 'page_type', 'target_post_type', 'taxonomy', 'query', 'matches', 'match_count', 'limit', 'offset', 'has_more', 'result_id_path', 'next_abilities' ),
			'additionalProperties' => true,
		);
	}

	public function taxonomy_term_output_schema(): array {
		$schema = $this->taxonomy_term_item_schema();
		$schema['properties']['created'] = array( 'type' => 'boolean' );
		$schema['properties']['idempotent_replay'] = array( 'type' => 'boolean' );
		$schema['properties']['next_ability'] = array( 'type' => 'string', 'enum' => array( self::PREFIX . 'assign-taxonomy-terms' ) );
		$schema['required'] = array_merge( $schema['required'], array( 'created', 'idempotent_replay', 'next_ability' ) );
		$schema['additionalProperties'] = true;
		return $schema;
	}

	public function taxonomy_assignment_output_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'post_id'         => array( 'type' => 'integer', 'minimum' => 1 ),
				'page_type'       => array( 'type' => 'string' ),
				'post_type'       => array( 'type' => 'string' ),
				'modified_gmt'    => array( 'type' => 'string', 'format' => 'date-time' ),
				'revision'        => array( 'type' => 'string', 'format' => 'uuid' ),
				'taxonomy'        => array( 'type' => 'string' ),
				'assignment_mode' => array( 'type' => 'string', 'enum' => array( 'replace', 'append' ) ),
				'terms'           => array( 'type' => 'array', 'items' => $this->taxonomy_term_item_schema() ),
				'verify_ability'  => array( 'type' => 'string', 'enum' => array( self::PREFIX . 'inspect-taxonomy-terms' ) ),
			),
			'required'             => array( 'post_id', 'page_type', 'post_type', 'modified_gmt', 'revision', 'taxonomy', 'assignment_mode', 'terms', 'verify_ability' ),
			'additionalProperties' => true,
		);
	}

	public function taxonomy_inspection_output_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'post_id'      => array( 'type' => 'integer', 'minimum' => 1 ),
				'page_type'    => array( 'type' => 'string' ),
				'post_type'    => array( 'type' => 'string' ),
				'modified_gmt' => array( 'type' => 'string', 'format' => 'date-time' ),
				'revision'     => array( 'type' => 'string', 'format' => 'uuid' ),
				'taxonomies'   => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'properties'           => array(
							'taxonomy' => array( 'type' => 'string' ),
							'terms'    => array( 'type' => 'array', 'items' => $this->taxonomy_term_item_schema() ),
						),
						'required'             => array( 'taxonomy', 'terms' ),
						'additionalProperties' => false,
					),
				),
			),
			'required'             => array( 'post_id', 'page_type', 'post_type', 'modified_gmt', 'revision', 'taxonomies' ),
			'additionalProperties' => true,
		);
	}

	private function taxonomy_term_item_schema( bool $with_match = false ): array {
		$properties = array(
			'term_id'     => array( 'type' => 'integer', 'minimum' => 1 ),
			'taxonomy'    => array( 'type' => 'string' ),
			'name'        => array( 'type' => 'string' ),
			'slug'        => array( 'type' => 'string' ),
			'description' => array( 'type' => 'string' ),
			'parent_id'   => array( 'type' => 'integer', 'minimum' => 0 ),
			'count'       => array( 'type' => 'integer', 'minimum' => 0 ),
			'archive_url' => array( 'type' => 'string' ),
		);
		$required = array_keys( $properties );
		if ( $with_match ) {
			$properties['match'] = array( 'type' => 'object', 'additionalProperties' => true );
			$required[] = 'match';
		}
		return array( 'type' => 'object', 'properties' => $properties, 'required' => $required, 'additionalProperties' => false );
	}

	public function content_clone_schema(): array {
		$schema = $this->content_inspection_schema();
		$schema['properties']['expected_modified_gmt'] = array( 'type' => 'string', 'format' => 'date-time' );
		$schema['properties']['expected_content_hash'] = array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' );
		$schema['properties']['idempotency_key'] = $this->string_property( 'Stable key for safe retry of this clone operation.', 1, 128 );
		$schema['properties']['title'] = $this->string_property( 'Optional title for the new draft.', 1, 200 );
		$schema['properties']['slug'] = $this->string_property( 'Optional slug for the new draft.', 0, 200 );
		$schema['properties']['target_content_language'] = $this->string_property( 'Optional Site Contract-approved language to assign to the cloned draft before its content is localized.', 2, 35 );
		$schema['properties']['confirm_clone'] = array( 'type' => 'boolean', 'enum' => array( true ) );
		$schema['required'] = array( 'post_id', 'page_type', 'expected_modified_gmt', 'expected_content_hash', 'idempotency_key', 'confirm_clone' );
		return $schema;
	}

	public function content_proposal_create_schema(): array {
		$schema = $this->content_inspection_schema();
		$schema['properties']['content_language'] = $this->string_property( 'Exact approved BCP 47 language of the localized source.', 2, 35 );
		$schema['properties']['expected_modified_gmt'] = array( 'type' => 'string', 'format' => 'date-time' );
		$schema['properties']['expected_content_hash'] = array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' );
		$schema['properties']['idempotency_key'] = array( 'type' => 'string', 'minLength' => 8, 'maxLength' => 128, 'pattern' => '^[A-Za-z0-9._:-]+$' );
		$schema['properties']['confirm_proposal'] = array( 'type' => 'boolean', 'enum' => array( true ) );
		$schema['required'] = array( 'post_id', 'page_type', 'content_language', 'expected_modified_gmt', 'expected_content_hash', 'idempotency_key', 'confirm_proposal' );
		return $schema;
	}

	public function content_proposal_submit_schema(): array {
		return array(
			'type' => 'object',
			'properties' => array(
				'post_id' => array( 'type' => 'integer', 'minimum' => 1 ),
				'expected_modified_gmt' => array( 'type' => 'string', 'format' => 'date-time' ),
				'expected_revision' => array( 'type' => 'string', 'format' => 'uuid' ),
			),
			'required' => array( 'post_id', 'expected_modified_gmt', 'expected_revision' ),
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
			'content_language' => $this->string_property( 'Exact effective BCP 47 content language returned by the selected Blueprint.', 2, 35 ),
			'excerpt'          => $this->string_property( 'WordPress excerpt. Runtime policy is required, optional, or disabled according to the selected blueprint.', 0, 300 ),
			'meta_description' => $this->string_property( 'Yoast SEO meta description: a natural search-result proposition.', 120, 160 ),
			'sections'         => $this->sections_schema(),
			'fields'           => array( 'type' => 'object', 'maxProperties' => 100, 'additionalProperties' => true ),
		);
		$required = array( 'page_type', 'content_language', 'meta_description', 'sections' );
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
				'content_language'     => $this->string_property( 'Exact effective BCP 47 content language returned by the immutable draft Blueprint.', 2, 35 ),
				'title'                => $this->string_property( 'Optional replacement title.', 1, 200 ),
				'slug'                 => $this->string_property( 'Optional replacement slug.', 1, 200 ),
				'excerpt'              => $this->string_property( 'Replacement WordPress excerpt. Runtime policy is required, optional, or disabled according to the immutable draft blueprint.', 0, 300 ),
				'meta_description'     => $this->string_property( 'Required replacement or preserved Yoast SEO meta description.', 120, 160 ),
				'sections'             => $this->sections_schema(),
				'fields'               => array( 'type' => 'object', 'maxProperties' => 100, 'additionalProperties' => true ),
			),
			'required'             => array( 'post_id', 'expected_modified_gmt', 'expected_revision', 'page_type', 'content_language', 'meta_description', 'sections' ),
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
			'minItems' => 0,
			'maxItems' => 50,
			'items'    => array(
				'type'                 => 'object',
				'properties'           => array(
					'pattern' => array( 'type' => 'string', 'pattern' => '^[a-z0-9-]+/[a-z0-9-]+$' ),
					'fields'  => array(
						'type'                 => 'object',
						'maxProperties'        => 100,
						'additionalProperties' => array(
							'oneOf' => array(
								array( 'type' => array( 'string', 'number', 'integer', 'boolean' ) ),
								array(
									'type'                 => 'object',
									'maxProperties'        => 3,
									'additionalProperties' => false,
									'properties'           => array(
										'label' => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 500 ),
										'url'   => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 2000 ),
										'id'    => array( 'type' => 'integer', 'minimum' => 1 ),
										'alt'   => array( 'type' => 'string', 'maxLength' => 1000 ),
									),
								),
							),
						),
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
