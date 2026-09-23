<?php
/* SmartCloud Agent Composer execution contract. */

namespace SmartCloud\AgentComposer\Execution;

use SmartCloud\AgentComposer\Integration\Mcp\ComposerMcpServer;
use SmartCloud\AgentComposer\Security\McpAccessGuard;
use SmartCloud\AgentComposer\Security\PublishApprovalService;

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
	private Publisher_Media_Uploader $publisher_media;
	private Content_Proposal_Service $proposals;
	private Localization_Provider_Registry $localization;
	private Localized_Draft_Service $localized_drafts;
	private Rendered_Preview_Service $rendered_previews;
	private Semantic_Document_Service $semantic_documents;
	private Blueprint_Migration_Service $migrations;
	private Bulk_Blueprint_Migration_Service $bulk_migrations;
	private ?McpAccessGuard $access_guard;
	private ?PublishApprovalService $publish_approvals;

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
		Publisher_Media_Uploader $publisher_media,
		Content_Proposal_Service $proposals,
		Localization_Provider_Registry $localization,
		Localized_Draft_Service $localized_drafts,
		Rendered_Preview_Service $rendered_previews,
		Semantic_Document_Service $semantic_documents,
		Blueprint_Migration_Service $migrations,
		Bulk_Blueprint_Migration_Service $bulk_migrations,
		?McpAccessGuard $access_guard = null,
		?PublishApprovalService $publish_approvals = null
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
		$this->publisher_media = $publisher_media;
		$this->proposals      = $proposals;
		$this->localization   = $localization;
		$this->localized_drafts = $localized_drafts;
		$this->rendered_previews = $rendered_previews;
		$this->semantic_documents = $semantic_documents;
		$this->migrations = $migrations;
		$this->bulk_migrations = $bulk_migrations;
		$this->access_guard = $access_guard;
		$this->publish_approvals = $publish_approvals;
	}

	public static function names(): array {
		return array(
			self::PREFIX . 'get-page-blueprint',
			self::PREFIX . 'get-contract',
			self::PREFIX . 'get-document',
			self::PREFIX . 'set-field',
			self::PREFIX . 'replace-media',
			self::PREFIX . 'insert-slot-block',
			self::PREFIX . 'update-slot-block',
			self::PREFIX . 'move-slot-block',
			self::PREFIX . 'remove-slot-block',
			self::PREFIX . 'validate-proposal',
			self::PREFIX . 'preview-blueprint-migration',
			self::PREFIX . 'create-blueprint-migration-proposal',
			self::PREFIX . 'plan-blueprint-migration',
			self::PREFIX . 'create-blueprint-migration-proposals',
			self::PREFIX . 'get-design-context',
			self::PREFIX . 'get-runtime-capabilities',
			self::PREFIX . 'list-supported-content-languages',
			self::PREFIX . 'list-approved-patterns',
			self::PREFIX . 'read-reference-page',
			self::PREFIX . 'search-media',
			self::PREFIX . 'assign-featured-image',
			self::PREFIX . 'ingest-remote-media',
			self::PREFIX . 'upload-media-asset',
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
			self::PREFIX . 'get-rendered-preview-asset',
			self::PREFIX . 'inspect-publishable-draft',
			self::PREFIX . 'request-publish',
			self::PREFIX . 'open-publish-approval',
			self::PREFIX . 'get-publish-approval-asset',
			self::PREFIX . 'decide-publish-approval',
		);
	}

	public static function resource_names(): array {
		return array(
			self::PREFIX . 'rendered-preview-app',
			self::PREFIX . 'rendered-preview-app-v5',
			self::PREFIX . 'rendered-preview-app-v4',
			self::PREFIX . 'rendered-preview-app-v3',
			self::PREFIX . 'rendered-preview-app-v2',
			self::PREFIX . 'rendered-preview-app-v1',
			self::PREFIX . 'publish-approval-app',
			self::PREFIX . 'publish-approval-app-v5',
			self::PREFIX . 'publish-approval-app-v4',
			self::PREFIX . 'publish-approval-app-v3',
			self::PREFIX . 'publish-approval-app-v2',
			self::PREFIX . 'publish-approval-app-v1',
		);
	}

	public function is_rendered_preview_required(): bool {
		return 'required' === $this->config->get_rendered_preview_policy();
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
		$rendered_preview_required = $this->is_rendered_preview_required();

		$this->register_ability(
			'get-page-blueprint',
			'Get page blueprint',
			'Returns the allowed patterns, blocks, sequence, references, and constraints for a page type.',
			$this->page_type_schema(),
			array( $this, 'get_page_blueprint' ),
			true
		);
		$this->register_ability(
			'get-contract',
			'Get semantic Structure Contract',
			'Returns the exact versioned Structure Contract, semantic field IDs, and extension-slot IDs for an enforced Blueprint. Serialized Gutenberg markup is not exposed.',
			$this->page_type_schema(),
			array( $this, 'get_contract' ),
			true
		);
		$this->register_ability(
			'get-document',
			'Get semantic document',
			'Reads a Composer-owned assigned draft as stable semantic fields and identity-addressed extension-slot blocks. It returns fresh optimistic-concurrency tokens and never exposes full post_content.',
			$this->post_id_schema(),
			array( $this, 'get_document' ),
			true
		);
		$this->register_ability(
			'set-field',
			'Set semantic field',
			'Updates one Structure Contract-declared instance-content field by stable semantic ID. It resolves Gutenberg nesting server-side and uses the same ownership, optimistic-concurrency, and whole-document validation boundary as governed block updates.',
			$this->semantic_field_update_schema(),
			array( $this, 'set_field' ),
			false
		);
		$this->register_ability(
			'replace-media',
			'Replace semantic media',
			'Replaces one Structure Contract-declared core/image field using a readable Media Library attachment ID. Composer resolves the image source, alt text, title, dimensions, and link while preserving the field presentation and caption.',
			$this->semantic_media_update_schema(),
			array( $this, 'replace_media' ),
			false
		);
		$this->register_ability(
			'insert-slot-block',
			'Insert semantic slot block',
			'Inserts one supported core block or approved provider component into a Structure Contract extension slot. Composer assigns stable ownership identity and resolves all Gutenberg paths internally.',
			$this->semantic_slot_insert_schema(),
			array( $this, 'insert_slot_block' ),
			false
		);
		$this->register_ability(
			'update-slot-block',
			'Update semantic slot block',
			'Rematerializes one user-owned extension-slot block by stable user_block_id while preserving that identity and validating the whole managed document.',
			$this->semantic_slot_update_schema(),
			array( $this, 'update_slot_block' ),
			false
		);
		$this->register_ability(
			'move-slot-block',
			'Move semantic slot block',
			'Reorders one direct user-owned extension-slot child by stable identity. Cross-slot moves and physical Gutenberg paths are not accepted.',
			$this->semantic_slot_move_schema(),
			array( $this, 'move_slot_block' ),
			false
		);
		$this->register_ability(
			'remove-slot-block',
			'Remove semantic slot block',
			'Removes one user-owned extension-slot block by stable identity. Slot cardinality and the complete Structure Contract remain enforced atomically.',
			$this->semantic_slot_remove_schema(),
			array( $this, 'remove_slot_block' ),
			false
		);
		$this->register_ability(
			'validate-proposal',
			'Validate semantic content proposal',
			'Validates the exact current published-content proposal revision, including Structure Contract, Blueprint editorial fields, and source conflict status, without submitting or changing it.',
			$this->content_proposal_validate_schema(),
			array( $this, 'validate_proposal' ),
			true
		);
		$this->register_ability(
			'preview-blueprint-migration',
			'Preview Blueprint migration',
			'Calculates one exact, version-gated structural migration for a published managed item. It reports target validation and preserved, rebased, conflicting, review-required, and detached overrides without writing WordPress content.',
			$this->blueprint_migration_preview_schema(),
			array( $this, 'preview_blueprint_migration' ),
			true
		);
		$this->register_ability(
			'create-blueprint-migration-proposal',
			'Create Blueprint migration proposal',
			'Recalculates an exact reviewed migration plan and creates one inactive agent-owned update proposal. It never rewrites the published source; normal preview, submission, and human merge remain mandatory.',
			$this->blueprint_migration_proposal_schema(),
			array( $this, 'create_blueprint_migration_proposal' ),
			false
		);
		$this->register_ability(
			'plan-blueprint-migration',
			'Plan Blueprint migration batch',
			'Classifies one bounded page of exact-baseline published items as automatic, review-required, or incompatible. It returns per-item migration hashes and reports without writing WordPress content.',
			$this->bulk_blueprint_migration_plan_schema(),
			array( $this, 'plan_blueprint_migration' ),
			true
		);
		$this->register_ability(
			'create-blueprint-migration-proposals',
			'Create Blueprint migration proposals in bulk',
			'Recalculates up to 25 explicitly reviewed item plans and creates separate update proposals. Item failures are reported independently, retries are idempotent, and published source content is never rewritten.',
			$this->bulk_blueprint_migration_create_schema(),
			array( $this, 'create_blueprint_migration_proposals' ),
			false
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
			'upload-media-asset',
			'Upload public media asset',
			'Publisher-only publication of one image into the WordPress Media Library. Supply exactly one bounded base64 payload or safe public HTTPS source, plus a meaningful SEO filename slug, title, accessible alternative-text decision, stable idempotency key, and explicit publication and rights confirmations. The returned attachment may then be selected through the normal Composer media and draft tools. Contributors cannot discover or call this ability.',
			$this->publisher_media_upload_schema(),
			array( $this, 'upload_media_asset' ),
			false,
			$this->publisher_media_upload_output_schema()
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
			'Returns content and validation details for ordinary readable site content and for Composer drafts available to the current principal. Do NOT use this tool when a Publisher needs to review a Composer-owned draft assigned to another principal for publication: use inspect-publishable-draft instead. This general inspection never bypasses draft assignment.',
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
			$rendered_preview_required
				? 'Creates a separate agent-owned working copy of one published item when both the Site Contract and Blueprint opt in. The source remains unchanged and merge is not exposed to the agent. This only starts the workflow: after all updates and validation, you MUST call smartcloud-agent-composer/get-rendered-preview with the freshest post_id, modified_gmt, and revision and let the inline rendered HTML preview be delivered. After that preview, you MUST call submit-content-proposal with the same concurrency tokens and the exact rendered_preview_token returned by that preview. Do not report the proposal as ready while its state is working.'
				: 'Creates a separate agent-owned working copy of one published item when both the Site Contract and Blueprint opt in. The source remains unchanged and merge is not exposed to the agent. After all updates and validation, rendered HTML preview is the recommended default. If the user explicitly asks to skip it, submit-content-proposal may be called with the freshest concurrency tokens and without rendered_preview_token. Do not report the proposal as ready while its state is working.',
			$this->content_proposal_create_schema(),
			array( $this, 'create_content_proposal' ),
			false
		);
		$this->register_ability(
			'submit-content-proposal',
			'Submit content proposal for human review',
			$rendered_preview_required
				? 'Validates and freezes an assigned working proposal for a human reviewer. Before calling this tool, MUST call smartcloud-agent-composer/get-rendered-preview after the final proposal write and pass its exact rendered_preview_token. Submission fails when the current proposal revision has not been rendered for this agent. It cannot update published content.'
				: 'Validates and freezes an assigned working proposal for a human reviewer. Rendering the final proposal revision first is the recommended default, but when the user explicitly asks to skip HTML preview this tool accepts the freshest concurrency tokens without rendered_preview_token. If a preview token is supplied, it must attest to the exact current revision. It cannot update published content.',
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
			$rendered_preview_required
				? 'Assembles and validates content, the blueprint-specific excerpt policy, and the SEO description without saving. The blueprint fixes the target type and template. Validation is not preview or submission: after a published-content proposal passes validation and all updates are complete, call get-rendered-preview with its freshest concurrency tokens, then pass that response token to submit-content-proposal.'
				: 'Assembles and validates content, the blueprint-specific excerpt policy, and the SEO description without saving. The blueprint fixes the target type and template. Validation is not preview or submission. Rendered HTML preview remains the recommended default after validation; if the user explicitly asks to skip it, submit the proposal with the freshest concurrency tokens and no preview token.',
			$this->candidate_schema( false ),
			array( $this, 'validate_content_draft' ),
			true
		);
		$this->register_ability(
			'create-content-draft',
			'Create content draft',
			$rendered_preview_required
				? 'Creates an agent-owned draft with the blueprint-specific WordPress excerpt policy and a Yoast meta description using the target fixed by the blueprint. After the final successful draft write, you MUST call smartcloud-agent-composer/get-rendered-preview with the returned post_id, modified_gmt, and revision before reporting completion so the user receives the inline preview.'
				: 'Creates an agent-owned draft with the blueprint-specific WordPress excerpt policy and a Yoast meta description using the target fixed by the blueprint. Rendered HTML preview after the final successful write remains the recommended default. If the user explicitly asks to skip it, completion may be reported using the returned post_id, modified_gmt, and revision without requesting HTML preview.',
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
			$rendered_preview_required
				? 'Backward-compatible alias for create-content-draft; the blueprint fixes whether the draft is a page, post, or approved custom post type. After the final successful draft write, you MUST call smartcloud-agent-composer/get-rendered-preview with the returned post_id, modified_gmt, and revision before reporting completion so the user receives the inline preview.'
				: 'Backward-compatible alias for create-content-draft; the blueprint fixes whether the draft is a page, post, or approved custom post type. Rendered HTML preview after the final successful write remains the recommended default, but it may be skipped when the user explicitly asks for no HTML preview.',
			$this->candidate_schema( true ),
			array( $this, 'create_page_draft' ),
			false
		);
		$this->register_ability(
			'update-own-draft',
			'Update assigned content draft',
			$rendered_preview_required
				? 'Updates only a draft assigned to this agent, without changing its WordPress author, blueprint, post type, or template. Requires optimistic concurrency. After the final successful update, you MUST call smartcloud-agent-composer/get-rendered-preview with the returned post_id, modified_gmt, and revision and let the inline rendered HTML preview be delivered before reporting completion. If assignment_source is published-update-proposal, validate the completed proposal, call that preview tool, then MUST call submit-content-proposal with the same concurrency tokens and the exact rendered_preview_token returned by the preview. Do not submit before previewing, leave a completed proposal in working state, or report it as ready before submission succeeds.'
				: 'Updates only a draft assigned to this agent, without changing its WordPress author, blueprint, post type, or template. Requires optimistic concurrency. Rendered HTML preview after the final successful update remains the recommended default. If the user explicitly asks to skip it, a normal draft may be completed without preview and a published-update-proposal must still be validated and submitted with the freshest concurrency tokens, omitting rendered_preview_token. Do not leave a completed proposal in working state or report it as ready before submission succeeds.',
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
			$rendered_preview_required
				? 'Returns edit and browser preview URLs plus a fresh validation report for one draft assigned to this agent. This URL-only fallback does not display the draft inline; after a final draft write, use smartcloud-agent-composer/get-rendered-preview instead.'
				: 'Returns edit and browser preview URLs plus a fresh validation report for one draft assigned to this agent. This URL-only fallback does not display the draft inline. Rendered HTML preview remains the recommended default after a final write unless the user explicitly asks to skip it.',
			$this->post_id_schema(),
			array( $this, 'get_preview' ),
			true
		);
		$this->register_ability(
			'get-rendered-preview',
			'Get rendered draft preview',
			$rendered_preview_required
				? 'Required final preview step after a successful draft create or update. Call it with the freshest post_id, modified_gmt, and revision after every final write. It returns bounded, sanitized frontend HTML with Gutenberg serialization comments removed and displays that HTML inline in MCP Apps-capable clients. For a published-content proposal, pass its rendered_preview_token unchanged to submit-content-proposal; do not submit first. It does not execute shortcodes, frontend JavaScript, forms, or site template parts.'
				: 'Recommended default preview step after a successful draft create or update. Call it with the freshest post_id, modified_gmt, and revision unless the user explicitly asks to skip HTML preview. It returns bounded, sanitized frontend HTML with Gutenberg serialization comments removed and displays that HTML inline in MCP Apps-capable clients. A returned rendered_preview_token may be passed unchanged to submit-content-proposal. It does not execute shortcodes, frontend JavaScript, forms, or site template parts.',
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
		if ( null !== $this->publish_approvals ) {
			$this->register_ability(
				'inspect-publishable-draft',
				'Inspect draft for publication',
				'Use this tool, not inspect-content-item, whenever a protected Publisher reviews an ordinary Composer-owned draft for publication, especially when another principal created or owns it. This Publisher-only inspection preserves assignment and returns the exact current content, validation, modified_gmt, and revision. It never adopts, edits, or publishes the draft.',
				$this->post_id_schema(),
				array( $this, 'inspect_publishable_draft' ),
				true
			);
			$this->register_ability(
				'request-publish',
				'Request human publication approval',
				'Publisher-only request for a short-lived human review of an ordinary Composer-owned draft, including one assigned to another principal. A post_id is sufficient: Composer validates and locks the exact current revision atomically. If inspect-publishable-draft already returned expected_modified_gmt and expected_revision, pass both for an additional optimistic-concurrency check; never use inspect-content-item for a cross-principal publication handoff. This request never publishes content. The protected inline approval app is displayed in MCP Apps-capable clients, and the model cannot invoke its decision tool.',
				$this->publish_request_schema(),
				array( $this, 'request_publish' ),
				false,
				null,
				array(
					'_meta' => array(
						'ui' => array( 'resourceUri' => ComposerMcpServer::PUBLISH_APPROVAL_RESOURCE_URI ),
						'openai/outputTemplate' => ComposerMcpServer::PUBLISH_APPROVAL_RESOURCE_URI,
						'openai/toolInvocation/invoking' => 'Preparing publication review…',
						'openai/toolInvocation/invoked' => 'Publication review ready',
					),
				)
			);
			$this->register_publish_approval_private_abilities();
			$this->register_publish_approval_resource();
		}
		$this->register_rendered_preview_asset_ability();
		$this->register_rendered_preview_resource();
	}

	public function get_page_blueprint( array $input ): array|\WP_Error {
		return $this->execute( 'get-page-blueprint', $input, fn() => $this->config->get_blueprint( (string) $input['page_type'] ) );
	}

	public function get_contract( array $input ): array|\WP_Error {
		return $this->execute( 'get-contract', $input, fn() => $this->semantic_documents->get_contract( $input ) );
	}

	public function get_document( array $input ): array|\WP_Error {
		return $this->execute( 'get-document', $input, fn() => $this->semantic_documents->get_document( $input ) );
	}

	public function set_field( array $input ): array|\WP_Error {
		return $this->execute( 'set-field', $input, fn() => $this->semantic_documents->set_field( $input ) );
	}

	public function replace_media( array $input ): array|\WP_Error {
		return $this->execute( 'replace-media', $input, fn() => $this->semantic_documents->replace_media( $input ) );
	}

	public function insert_slot_block( array $input ): array|\WP_Error {
		return $this->execute( 'insert-slot-block', $input, fn() => $this->semantic_documents->insert_slot_block( $input ) );
	}

	public function update_slot_block( array $input ): array|\WP_Error {
		return $this->execute( 'update-slot-block', $input, fn() => $this->semantic_documents->update_slot_block( $input ) );
	}

	public function move_slot_block( array $input ): array|\WP_Error {
		return $this->execute( 'move-slot-block', $input, fn() => $this->semantic_documents->move_slot_block( $input ) );
	}

	public function remove_slot_block( array $input ): array|\WP_Error {
		return $this->execute( 'remove-slot-block', $input, fn() => $this->semantic_documents->remove_slot_block( $input ) );
	}

	public function validate_proposal( array $input ): array|\WP_Error {
		return $this->execute( 'validate-proposal', $input, fn() => $this->proposals->validate( $input ) );
	}

	public function preview_blueprint_migration( array $input ): array|\WP_Error {
		return $this->execute( 'preview-blueprint-migration', $input, fn() => $this->migrations->preview( $input ) );
	}

	public function create_blueprint_migration_proposal( array $input ): array|\WP_Error {
		return $this->execute( 'create-blueprint-migration-proposal', $input, fn() => $this->migrations->create_proposal( $input ) );
	}

	public function plan_blueprint_migration( array $input ): array|\WP_Error {
		return $this->execute( 'plan-blueprint-migration', $input, fn() => $this->bulk_migrations->plan( $input ) );
	}

	public function create_blueprint_migration_proposals( array $input ): array|\WP_Error {
		return $this->execute( 'create-blueprint-migration-proposals', $input, fn() => $this->bulk_migrations->create_proposals( $input ) );
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
								'publisher_upload' => array(
									'ability'                       => self::PREFIX . 'upload-media-asset',
									'available'                     => ! empty( $this->config->get_remote_media_ingest_policy()['publisher_upload_enabled'] ),
									'required_role'                 => 'publisher',
									'required_scope'                => 'composer.publish.request',
									'immediately_public_asset'      => true,
									'semantic_slug_required'        => true,
									'sources'                       => array( 'content_base64', 'safe_https_url' ),
									'max_direct_bytes'              => Publisher_Media_Uploader::MAX_DIRECT_BYTES,
									'publication_confirmation_required' => true,
									'rights_confirmation_required'  => true,
								),
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
							'available'      => true,
							'ability'        => self::PREFIX . 'get-rendered-preview',
							'resource_uri'   => ComposerMcpServer::PREVIEW_RESOURCE_URI,
							'scope'          => 'content',
							'fidelity'       => 'static',
							'max_html_bytes' => 500000,
							'policy'         => $this->config->get_rendered_preview_policy(),
							'default_recommended' => true,
							'required_after_final_draft_write' => $this->is_rendered_preview_required(),
							'required_for_proposal_submission' => $this->is_rendered_preview_required(),
							'inline_ui_requires_compatible_host' => true,
							'asset_bridge'   => array(
								'ability'                 => self::PREFIX . 'get-rendered-preview-asset',
								'app_only'                => true,
								'opaque_revision_bound_ids' => true,
								'max_image_bytes'         => 5242880,
								'max_font_bytes'          => 5242880,
								'max_stylesheets'         => 32,
								'max_import_depth'        => 4,
								'max_imported_stylesheets' => 32,
								'max_stylesheet_bytes'    => 524288,
								'max_css_bytes'           => 1048576,
							),
						),
						'transport_optional' => true,
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
				$synced_definitions = (array) ( $this->config->get_design_policy()['synced_structural_patterns'] ?? array() );
				$result    = array();
				foreach ( $blueprint['allowed_patterns'] as $name ) {
					if ( in_array( $name, (array) ( $blueprint['synced_patterns'] ?? array() ), true ) ) {
						$definition = $synced_definitions[ $name ] ?? null;
						$post_name  = is_array( $definition ) ? (string) ( $definition['post_name'] ?? '' ) : '';
						$stored     = '' === $post_name ? null : get_page_by_path( $post_name, OBJECT, 'wp_block' );
						$result[] = array(
							'name'                 => $name,
							'registered'           => $stored instanceof \WP_Post && 'publish' === $stored->post_status,
							'wordpress_registered' => $stored instanceof \WP_Post && 'publish' === $stored->post_status,
							'source'               => 'synced-wp-block',
							'synced'               => true,
							'pattern_version'       => is_array( $definition ) ? (int) ( $definition['version'] ?? 0 ) : 0,
							'fields'                => is_array( $definition ) ? (array) ( $definition['overrides'] ?? array() ) : array(),
							'semantic_slots'        => array(),
							'error'                 => is_array( $definition ) && $stored instanceof \WP_Post && 'publish' === $stored->post_status ? '' : 'synced_pattern_not_resolvable',
						);
						continue;
					}
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
						'synced'                    => false,
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
				$is_owned_draft        = 'draft' === $post->post_status
					&& '1' === (string) get_post_meta( $post_id, Draft_Service::OWNED_META, true )
					&& $blueprint['page_type'] === (string) get_post_meta( $post_id, Draft_Service::PAGE_TYPE_META, true )
					&& $this->drafts->is_assigned_to_current_actor( $post_id );
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

	public function upload_media_asset( array $input ): array|\WP_Error {
		$audit_input = $input;
		$source_kind = '' !== trim( (string) ( $input['content_base64'] ?? '' ) ) ? 'base64' : 'remote';
		$source_reference = 'base64' === $source_kind
			? (string) ( $input['content_base64'] ?? '' )
			: (string) ( $input['source_url'] ?? '' );
		unset( $audit_input['content_base64'], $audit_input['source_url'] );
		$audit_input['source_kind'] = $source_kind;
		$audit_input['source_reference_sha256'] = hash( 'sha256', $source_reference );
		return $this->execute( 'upload-media-asset', $audit_input, fn() => $this->publisher_media->upload( $input ) );
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

	public function get_rendered_preview_asset( array $input ): array|\WP_Error {
		return $this->execute( 'get-rendered-preview-asset', $input, fn() => $this->rendered_previews->get_asset( $input ) );
	}

	public function request_publish( array $input ): array|\WP_Error {
		return $this->execute(
			'request-publish',
			$input,
			fn(): array => null !== $this->publish_approvals
				? $this->publish_approvals->request( $input )
				: throw new Execution_Exception( 'publish_approval_unavailable', 'Publish approval is unavailable.' )
		);
	}

	public function inspect_publishable_draft( array $input ): array|\WP_Error {
		return $this->execute(
			'inspect-publishable-draft',
			$input,
			fn(): array => $this->drafts->inspect_publishable_draft_for_publisher( absint( $input['post_id'] ?? 0 ) )
		);
	}

	public function get_publish_approval_asset( array $input ): array|\WP_Error {
		return $this->execute(
			'get-publish-approval-asset',
			$input,
			function () use ( $input ): array {
				if ( null === $this->publish_approvals ) {
					throw new Execution_Exception( 'publish_approval_unavailable', 'Publish approval is unavailable.' );
				}
				$asset = $this->publish_approvals->asset_from_app(
					sanitize_text_field( (string) ( $input['id'] ?? '' ) ),
					sanitize_text_field( (string) ( $input['token'] ?? '' ) ),
					sanitize_text_field( (string) ( $input['asset_id'] ?? '' ) )
				);
				if ( 'stylesheet' === $asset['kind'] ) {
					return array(
						'type' => 'resource',
						'resource' => array( 'uri' => 'approval-asset://smartcloud-agent-composer/' . $input['asset_id'], 'mimeType' => 'text/css', 'text' => $asset['content'] ),
					);
				}
				if ( 'font' === $asset['kind'] ) {
					return array(
						'type' => 'resource',
						'resource' => array( 'uri' => 'approval-asset://smartcloud-agent-composer/' . $input['asset_id'], 'mimeType' => $asset['mime_type'], 'blob' => base64_encode( $asset['content'] ) ),
					);
				}
				return array( 'type' => 'image', 'results' => $asset['content'], 'mimeType' => $asset['mime_type'] );
			}
		);
	}

	public function open_publish_approval( array $input ): array|\WP_Error {
		return $this->execute(
			'open-publish-approval',
			$input,
			fn(): array => null !== $this->publish_approvals
				? $this->publish_approvals->open_from_app( $input )
				: throw new Execution_Exception( 'publish_approval_unavailable', 'Publish approval is unavailable.' )
		);
	}

	public function decide_publish_approval( array $input ): array|\WP_Error {
		return $this->execute(
			'decide-publish-approval',
			$input,
			fn(): array => null !== $this->publish_approvals
				? $this->publish_approvals->decide_from_app( $input )
				: throw new Execution_Exception( 'publish_approval_unavailable', 'Publish approval is unavailable.' )
		);
	}

	private function publish_request_schema(): array {
		return array(
			'type' => 'object',
			'properties' => array(
				'post_id'               => array( 'type' => 'integer', 'minimum' => 1, 'description' => 'Composer-owned ordinary draft to submit. A protected Publisher may submit a draft assigned to another principal.' ),
				'expected_modified_gmt' => array( 'type' => 'string', 'format' => 'date-time', 'description' => 'Optional exact modified_gmt returned by inspect-publishable-draft. Supply together with expected_revision or omit both.' ),
				'expected_revision'     => array( 'type' => 'string', 'format' => 'uuid', 'description' => 'Optional exact revision returned by inspect-publishable-draft. Supply together with expected_modified_gmt or omit both.' ),
			),
			'required' => array( 'post_id' ),
			'additionalProperties' => false,
		);
	}

	private function publish_approval_asset_schema(): array {
		return array(
			'type' => 'object',
			'properties' => array(
				'id'       => array( 'type' => 'string', 'format' => 'uuid' ),
				'token'    => array( 'type' => 'string', 'pattern' => '^[A-Za-z0-9_-]{43}$' ),
				'asset_id' => array( 'type' => 'string', 'pattern' => '^pa_[A-Za-z0-9_-]{43}$' ),
			),
			'required' => array( 'id', 'token', 'asset_id' ),
			'additionalProperties' => false,
		);
	}

	private function publish_approval_open_schema(): array {
		return array(
			'type' => 'object',
			'properties' => array( 'id' => array( 'type' => 'string', 'format' => 'uuid' ) ),
			'required' => array( 'id' ),
			'additionalProperties' => false,
		);
	}

	private function publish_approval_decision_schema(): array {
		return array(
			'type' => 'object',
			'properties' => array(
				'id'               => array( 'type' => 'string', 'format' => 'uuid' ),
				'token'            => array( 'type' => 'string', 'pattern' => '^[A-Za-z0-9_-]{43}$' ),
				'decision'         => array( 'type' => 'string', 'enum' => array( 'approve', 'reject' ) ),
				'confirm_decision' => array( 'type' => 'boolean', 'enum' => array( true ) ),
			),
			'required' => array( 'id', 'token', 'decision', 'confirm_decision' ),
			'additionalProperties' => false,
		);
	}

	public function check_permission( mixed $input = null ): bool|\WP_Error {
		unset( $input );
		return $this->check_permission_for( self::PREFIX . 'create-content-draft' );
	}

	public function check_permission_for( string $ability_name ): bool|\WP_Error {
		if ( null !== $this->access_guard ) {
			return $this->access_guard->authorize_ability( $ability_name );
		}
		return is_user_logged_in()
			&& current_user_can( \SmartCloud\AgentComposer\Infrastructure\WordPress\Activation::CAP_USE )
			&& current_user_can( \SmartCloud\AgentComposer\Infrastructure\WordPress\Activation::CAP_EXECUTE_DRAFTS )
			&& current_user_can( 'read' )
			&& current_user_can( 'edit_pages' )
			&& current_user_can( 'edit_posts' );
	}

	public function permission_callback_for( string $ability_name ): callable {
		return fn( mixed $input = null ): bool|\WP_Error => $this->check_permission_for( $ability_name );
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
				'permission_callback' => $this->permission_callback_for( self::PREFIX . $slug ),
				'meta'                => array(
					'show_in_rest' => false,
					'mcp'          => array_merge( array( 'public' => false ), $mcp_meta ),
					'annotations' => array(
						'readonly'    => $read_only,
						'destructive' => false,
						'idempotent'  => $read_only || in_array(
							$slug,
							array( 'create-page-draft', 'create-content-draft', 'create-content-proposal', 'create-blueprint-migration-proposal', 'create-blueprint-migration-proposals', 'submit-content-proposal', 'adopt-content-draft', 'assign-featured-image', 'ingest-remote-media', 'upload-media-asset', 'create-taxonomy-term', 'assign-taxonomy-terms', 'link-content-draft-translations', 'attach-content-draft-to-translation-group', 'attach-content-to-translation-group', 'merge-content-translation-groups' ),
							true
						),
					),
				),
			)
		);
	}

	private function register_rendered_preview_asset_ability(): void {
		$name = self::PREFIX . 'get-rendered-preview-asset';
		if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $name ) ) {
			return;
		}
		wp_register_ability(
			$name,
			array(
				'label'               => 'Get rendered preview asset',
				'description'         => 'Private helper used only by the rendered-preview app. It returns one revision-bound local image, WOFF/WOFF2 font, or sanitized stylesheet from the exact authorized draft preview manifest. Do not call it as a standalone agent workflow.',
				'category'            => self::CATEGORY,
				'input_schema'        => $this->rendered_preview_asset_input_schema(),
				'execute_callback'    => array( $this, 'get_rendered_preview_asset' ),
				'permission_callback' => $this->permission_callback_for( $name ),
				'meta'                => array(
					'show_in_rest' => false,
					'mcp'          => array(
						'public' => false,
						'_meta'  => array(
							'ui'                       => array( 'visibility' => array( 'app' ) ),
							'openai/visibility'        => 'private',
							'openai/widgetAccessible'  => true,
						),
					),
					'annotations'  => array(
						'readonly'      => true,
						'destructive'   => false,
						'idempotent'    => true,
						'openWorldHint' => false,
					),
				),
			)
		);
	}

	private function register_publish_approval_private_abilities(): void {
		$abilities = array(
			'open-publish-approval' => array(
				'label'       => 'Open publication approval session',
				'description' => 'Private app-only helper that authenticates the requesting Publisher and returns the short-lived review session to the inline app without exposing it to the model-facing request result.',
				'schema'      => $this->publish_approval_open_schema(),
				'callback'    => array( $this, 'open_publish_approval' ),
				'readonly'    => true,
			),
			'get-publish-approval-asset' => array(
				'label'       => 'Get publication approval preview asset',
				'description' => 'Private app-only helper that returns one exact revision-bound asset for the inline publication review.',
				'schema'      => $this->publish_approval_asset_schema(),
				'callback'    => array( $this, 'get_publish_approval_asset' ),
				'readonly'    => true,
			),
			'decide-publish-approval' => array(
				'label'       => 'Decide publication approval',
				'description' => 'Private app-only human decision for one short-lived, exact revision-bound publication request. It is never exposed to the model.',
				'schema'      => $this->publish_approval_decision_schema(),
				'callback'    => array( $this, 'decide_publish_approval' ),
				'readonly'    => false,
			),
		);
		foreach ( $abilities as $slug => $definition ) {
			$name = self::PREFIX . $slug;
			if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $name ) ) {
				continue;
			}
			$arguments = array(
				'label'               => $definition['label'],
				'description'         => $definition['description'],
				'category'            => self::CATEGORY,
				'input_schema'        => $definition['schema'],
				'execute_callback'    => $definition['callback'],
				'permission_callback' => $this->permission_callback_for( $name ),
				'meta'                => array(
					'show_in_rest' => false,
					'mcp' => array(
						'public' => false,
						'_meta' => array(
							'ui'                      => array( 'visibility' => array( 'app' ) ),
							'openai/visibility'       => 'private',
							'openai/widgetAccessible' => true,
						),
					),
					'annotations' => array(
						'readonly'      => $definition['readonly'],
						'destructive'   => ! $definition['readonly'],
						'idempotent'    => $definition['readonly'],
						'openWorldHint' => false,
					),
				),
			);
			if ( 'get-publish-approval-asset' === $slug ) {
				unset( $arguments['output_schema'] );
			}
			wp_register_ability( $name, $arguments );
		}
	}

	private function register_publish_approval_resource(): void {
		$resources = array(
			self::PREFIX . 'publish-approval-app'    => ComposerMcpServer::PUBLISH_APPROVAL_RESOURCE_URI,
			self::PREFIX . 'publish-approval-app-v5' => ComposerMcpServer::PUBLISH_APPROVAL_RESOURCE_URI_V5,
			self::PREFIX . 'publish-approval-app-v4' => ComposerMcpServer::PUBLISH_APPROVAL_RESOURCE_URI_V4,
			self::PREFIX . 'publish-approval-app-v3' => ComposerMcpServer::PUBLISH_APPROVAL_RESOURCE_URI_V3,
			self::PREFIX . 'publish-approval-app-v2' => ComposerMcpServer::PUBLISH_APPROVAL_RESOURCE_URI_V2,
			self::PREFIX . 'publish-approval-app-v1' => ComposerMcpServer::PUBLISH_APPROVAL_RESOURCE_URI_V1,
		);
		foreach ( $resources as $name => $uri ) {
			if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $name ) ) {
				continue;
			}
			wp_register_ability(
				$name,
				array(
					'label'               => 'Publication approval app',
					'description'         => 'MCP Apps UI resource for exact revision-bound human publication review, terminal status display, and decision.',
					'category'            => self::CATEGORY,
					'output_schema'       => array( 'type' => 'array', 'items' => array( 'type' => 'object', 'additionalProperties' => true ) ),
					'execute_callback'    => fn( array $input = array() ): array => $this->publish_approval_resource_for_uri( $uri, $input ),
					'permission_callback' => $this->permission_callback_for( $name ),
					'meta'                => array(
						'show_in_rest' => false,
						'mcp' => array(
							'public'      => false,
							'type'        => 'resource',
							'uri'         => $uri,
							'name'        => 'Composer publication approval',
							'title'       => 'Composer publication approval',
							'description' => 'Displays an exact locked revision, its current approval status, and human-only actions while pending.',
							'mimeType'    => 'text/html;profile=mcp-app',
							'_meta'       => array( 'ui' => $this->publish_approval_ui_meta() ),
							'annotations' => array( 'audience' => array( 'user' ), 'priority' => 1.0 ),
						),
					),
				)
			);
		}
	}

	public function publish_approval_resource( array $input = array() ): array {
		return $this->publish_approval_resource_for_uri( ComposerMcpServer::PUBLISH_APPROVAL_RESOURCE_URI, $input );
	}

	private function publish_approval_resource_for_uri( string $uri, array $input = array() ): array {
		unset( $input );
		return array(
			array(
				'uri'      => $uri,
				'mimeType' => 'text/html;profile=mcp-app',
				'text'     => $this->publish_approval_app_html(),
				'_meta'    => array( 'ui' => $this->publish_approval_ui_meta() ),
			),
		);
	}

	private function publish_approval_ui_meta(): array {
		return array(
			'prefersBorder' => true,
			'csp' => array( 'connectDomains' => array(), 'resourceDomains' => array(), 'frameDomains' => array() ),
		);
	}

	private function publish_approval_app_html(): string {
		return <<<'HTML'
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>
:root{color-scheme:light dark;font:14px/1.45 system-ui,sans-serif}html,body{margin:0;min-height:0;overflow:hidden}body{padding:16px;background:transparent;color:CanvasText}.approval-app{box-sizing:border-box;height:var(--composer-app-height,680px);display:grid;grid-template-rows:auto minmax(0,1fr) auto;gap:12px;overflow:hidden}.approval-summary{min-width:0}.header{display:flex;justify-content:space-between;gap:12px;align-items:flex-start}.header h2{margin:0;font-size:18px}.status{padding:3px 8px;border-radius:999px;background:color-mix(in srgb,#00a32a 18%,Canvas);font-size:12px;font-weight:700}.status[data-status="rejected"],.status[data-status="invalidated"],.status[data-status="expired"]{background:color-mix(in srgb,#b32d2e 16%,Canvas)}.notice{margin:10px 0;padding:8px 10px;border-left:4px solid #dba617;background:color-mix(in srgb,#dba617 12%,Canvas)}.meta{display:grid;grid-template-columns:repeat(auto-fit,minmax(145px,1fr));gap:6px}.meta div{padding:6px 8px;border:1px solid color-mix(in srgb,CanvasText 14%,transparent);border-radius:8px}.meta small{display:block;color:GrayText}.preview{min-height:0;contain:layout paint style;isolation:isolate;overflow:auto;border:1px solid color-mix(in srgb,CanvasText 16%,transparent);border-radius:12px;background:Canvas}.preview[data-ready="false"]{visibility:hidden}.approval-footer{min-width:0}.actions,.confirm-actions{display:flex;flex-wrap:wrap;justify-content:flex-end;gap:8px}button{border:0;border-radius:7px;padding:9px 14px;font-weight:700;cursor:pointer}button:disabled{cursor:not-allowed;opacity:.55}.reject{color:#b32d2e}.approve{background:#00a32a;color:white}.confirm-action.reject{background:#b32d2e;color:white}.fallback{margin-right:auto;color:LinkText;background:transparent;text-decoration:underline}.confirm-backdrop{position:fixed;inset:0;z-index:1000;display:grid;place-items:center;padding:20px;background:color-mix(in srgb,CanvasText 38%,transparent);backdrop-filter:blur(2px)}.confirm-dialog{box-sizing:border-box;width:min(460px,100%);padding:18px;border:1px solid color-mix(in srgb,#dba617 55%,Canvas);border-radius:12px;background:Canvas;box-shadow:0 18px 48px color-mix(in srgb,CanvasText 28%,transparent)}.confirm-dialog strong{display:block;font-size:16px}.confirm-dialog p{margin:8px 0 0}.message{min-height:1.45em;margin-top:6px;color:GrayText}.error{color:#b32d2e}[hidden]{display:none!important}
</style></head><body><div id="approval-app" class="approval-app"><div class="approval-summary"><header class="header"><div><h2 id="title">Publication review</h2><div id="subtitle"></div></div><span id="status" class="status">Pending</span></header><p class="notice">This decision is bound to the exact revision and content hash shown below. Any draft change invalidates it.</p><section id="meta" class="meta"></section></div><main id="preview" class="preview" data-ready="false" aria-busy="true"></main><div class="approval-footer"><div class="actions"><button id="fallback" class="fallback" type="button">Open WordPress fallback</button><button id="reject" class="reject" type="button">Reject</button><button id="approve" class="approve" type="button">Approve and publish</button></div><div id="message" class="message" aria-live="polite"></div></div><section id="confirmation" class="confirm-backdrop" role="dialog" aria-modal="true" aria-labelledby="confirm-title" aria-describedby="confirm-copy" hidden><div class="confirm-dialog"><strong id="confirm-title"></strong><p id="confirm-copy"></p><div class="confirm-actions"><button id="cancel-decision" type="button">Cancel</button><button id="confirm-decision" class="confirm-action" type="button"></button></div></div></section></div><script>
const app=document.getElementById('approval-app');const preview=document.getElementById('preview');const root=preview.attachShadow({mode:'open'});const title=document.getElementById('title');const subtitle=document.getElementById('subtitle');const statusEl=document.getElementById('status');const meta=document.getElementById('meta');const message=document.getElementById('message');const approve=document.getElementById('approve');const reject=document.getElementById('reject');const fallback=document.getElementById('fallback');const confirmation=document.getElementById('confirmation');const confirmTitle=document.getElementById('confirm-title');const confirmCopy=document.getElementById('confirm-copy');const cancelDecision=document.getElementById('cancel-decision');const confirmDecision=document.getElementById('confirm-decision');const pending=new Map();const blobUrls=new Set();let requestId=1;let generation=0;let current=null;let pendingDecision=null;let confirmationTrigger=null;let lastDocumentKey='';
const chromeCss=':host{display:block;padding:clamp(16px,4vw,40px);color:CanvasText;background:Canvas}:host([dir="rtl"]){direction:rtl}body.smartcloud-composer-preview-document{box-sizing:border-box;width:100%;height:auto!important;overflow:visible!important}img{max-width:100%;height:auto}table{display:block;max-width:100%;overflow:auto}a{color:LinkText}.preview-empty{box-sizing:border-box;display:grid;place-items:center;min-height:240px;padding:32px;text-align:center;color:GrayText}.preview-empty strong{display:block;margin-bottom:6px;color:CanvasText;font-size:16px}';
function post(value){window.parent.postMessage(value,'*')}function request(method,params){const id=requestId++;post({jsonrpc:'2.0',id,method,params});return new Promise((resolve,reject)=>pending.set(id,{resolve,reject}))}function notify(method,params){post({jsonrpc:'2.0',method,...(params===undefined?{}:{params})})}
function setAppHeight(value){const maximum=Number(value);const height=Number.isFinite(maximum)&&maximum>0?Math.min(760,Math.max(320,maximum-32)):680;app.style.setProperty('--composer-app-height',height+'px')}
function resultData(payload){const result=payload?.structuredContent??payload;return result?.document?result:(result?.result??result)}function contentBlocks(payload){for(const candidate of [payload,payload?.result,payload?.result?.result])if(Array.isArray(candidate?.content))return candidate.content;return[]}
function sanitize(html){const parsed=new DOMParser().parseFromString(String(html||''),'text/html');parsed.querySelectorAll('script,iframe,object,embed,base,meta,link,style,form,input,button,textarea,select,audio,video,source,track,picture').forEach(node=>node.remove());parsed.querySelectorAll('*').forEach(node=>{for(const attr of [...node.attributes])if(attr.name.toLowerCase().startsWith('on')||['srcdoc','srcset','poster'].includes(attr.name.toLowerCase()))node.removeAttribute(attr.name)});const fragment=document.createDocumentFragment();fragment.append(...parsed.body.childNodes);return fragment}
function clearBlobs(){for(const url of blobUrls)URL.revokeObjectURL(url);blobUrls.clear()}function decodeBlob(data,mime){const binary=atob(String(data||''));const bytes=new Uint8Array(binary.length);for(let i=0;i<binary.length;i++)bytes[i]=binary.charCodeAt(i);return new Blob([bytes],{type:mime||'application/octet-stream'})}
async function callTool(name,args){if(window.openai?.callTool)return window.openai.callTool(name,args);return request('tools/call',{name,arguments:args})}
async function loadAsset(asset,session){const response=await callTool('smartcloud-agent-composer-get-publish-approval-asset',{id:session.id,token:session.approval_token,asset_id:asset.asset_id});const blocks=contentBlocks(response);if(asset.kind==='stylesheet'){const block=blocks.find(item=>item.type==='resource'&&String(item.resource?.mimeType||'').startsWith('text/css'));return{asset,css:String(block?.resource?.text||'')}}if(asset.kind==='font'){const block=blocks.find(item=>item.type==='resource'&&item.resource?.blob);return{asset,blob:decodeBlob(block?.resource?.blob,block?.resource?.mimeType)}}const block=blocks.find(item=>item.type==='image');return{asset,blob:decodeBlob(block?.data,block?.mimeType)}}
async function loadPool(items,session,token){const results=new Array(items.length);let cursor=0;async function worker(){while(cursor<items.length){const index=cursor++;try{results[index]=await loadAsset(items[index],session)}catch(error){results[index]={asset:items[index],error}}}}await Promise.all(Array.from({length:Math.min(2,items.length)},worker));return token===generation?results:[]}
function statusMessage(state){return state==='approved'?'Published successfully.':state==='rejected'?'Publication request rejected.':state==='expired'?'This publication request expired.':state==='invalidated'?'This publication request was invalidated by a draft or contract change.':''}function showStatus(state){const value=String(state||'pending').toLowerCase();statusEl.textContent=value.charAt(0).toUpperCase()+value.slice(1);statusEl.dataset.status=value;return value}
async function render(payload){const data=resultData(payload);if(!data?.document||!data?.id)return;const doc=data.document;const documentKey=[data.id,doc.revision,doc.sha256].join('|');if(documentKey===lastDocumentKey)return;lastDocumentKey=documentKey;const token=++generation;preview.dataset.ready='false';preview.setAttribute('aria-busy','true');approve.disabled=reject.disabled=true;fallback.hidden=true;confirmation.hidden=true;document.body.removeAttribute('data-modal');pendingDecision=null;confirmationTrigger=null;message.classList.remove('error');message.textContent='Opening the protected approval session…';try{const session=resultData(await callTool('smartcloud-agent-composer-open-publish-approval',{id:data.id}));if(token!==generation)return;if(!session?.status)throw new Error('The approval session could not be opened.');current={...data,...session};const state=showStatus(current.status);const isPending=state==='pending'&&Boolean(current.approval_token);approve.disabled=reject.disabled=!isPending;fallback.hidden=!isPending||!current.approval_url;message.textContent=isPending?'':statusMessage(state)}catch(error){if(token!==generation)return;lastDocumentKey='';message.classList.add('error');message.textContent=error?.message||'The approval session could not be opened.';return}title.textContent=doc.title||'Publication review';subtitle.textContent='WordPress ID '+data.post_id;meta.replaceChildren();for(const [label,value] of [['Revision',doc.revision],['Validation',data.validation?.valid?'Valid':'Invalid'],['Expires',current.expires_gmt+' UTC'],['Assigned creator',current.assigned_principal_id||'Unassigned']]){const item=document.createElement('div');const small=document.createElement('small');small.textContent=label;item.append(small,document.createTextNode(String(value||'')));meta.append(item)}clearBlobs();root.replaceChildren();const base=document.createElement('style');base.textContent=chromeCss;root.append(base);const fragment=sanitize(doc.html);const article=fragment.querySelector('article');const shell=document.createElement('body');shell.className=article?.getAttribute('data-smartcloud-preview-body-classes')||'';shell.classList.add('smartcloud-composer-preview-document');article?.removeAttribute('data-smartcloud-preview-body-classes');const site=document.createElement('div');site.className='wp-site-blocks';const main=document.createElement('main');const content=document.createElement('div');content.className='wp-block-post-content';const hasRenderableBody=String(fragment.textContent||'').trim()!==''||Boolean(fragment.querySelector('img,figure,hr,table,ul,ol,blockquote,pre,details,canvas,svg'));if(hasRenderableBody){content.append(fragment)}else{const empty=document.createElement('div');empty.className='preview-empty';const copy=document.createElement('div');const heading=document.createElement('strong');heading.textContent='No body content';const detail=document.createElement('span');detail.textContent='This draft contains only its WordPress title, shown above.';copy.append(heading,detail);empty.append(copy);content.append(empty)}main.append(content);site.append(main);shell.append(site);root.append(shell);root.host.setAttribute('dir',doc.direction==='rtl'?'rtl':'ltr');const assets=current.approval_token?[...(doc.assets||[])].sort((a,b)=>(a.order||0)-(b.order||0)):[];const loaded=await loadPool(assets,current,token);if(token!==generation)return;const urls=new Map();for(const item of loaded){if(!item)continue;if(item.error){message.textContent='Some preview assets could not be loaded.';continue}if(item.blob){const url=URL.createObjectURL(item.blob);blobUrls.add(url);urls.set(item.asset.asset_id,url);if(item.asset.kind==='image')for(const image of root.querySelectorAll('img[data-smartcloud-preview-asset="'+CSS.escape(item.asset.asset_id)+'"]'))image.src=url}}for(const item of loaded)if(item&&!item.error&&item.css!==undefined){let css=item.css;for(const [id,url] of urls)css=css.split('smartcloud-preview-asset://'+id).join(url);css=css.replace(/smartcloud-preview-asset:\/\/pa_[A-Za-z0-9_-]{43}/g,'data:,');const style=document.createElement('style');style.textContent=css;root.insertBefore(style,shell)}if(document.fonts?.ready)await document.fonts.ready;if(token!==generation)return;await new Promise(resolve=>requestAnimationFrame(resolve));preview.dataset.ready='true';preview.setAttribute('aria-busy','false')}
function askDecision(decision){if(!current?.approval_token)return;confirmationTrigger=document.activeElement;pendingDecision=decision;const publishing=decision==='approve';confirmTitle.textContent=publishing?'Confirm publication':'Confirm rejection';confirmCopy.textContent=publishing?'Publish this exact locked revision now? This cannot be undone from this approval card.':'Reject this publication request? The draft will remain unpublished and unchanged.';confirmDecision.textContent=publishing?'Confirm publication':'Confirm rejection';confirmDecision.className='confirm-action '+(publishing?'approve':'reject');confirmation.hidden=false;document.body.dataset.modal='true';approve.disabled=reject.disabled=true;confirmDecision.disabled=cancelDecision.disabled=false;confirmDecision.focus()}function cancelConfirmation(){if(cancelDecision.disabled)return;pendingDecision=null;confirmation.hidden=true;document.body.removeAttribute('data-modal');if(current?.approval_token)approve.disabled=reject.disabled=false;if(confirmationTrigger instanceof HTMLElement)confirmationTrigger.focus();confirmationTrigger=null}
async function decide(decision){if(!current?.approval_token||decision!==pendingDecision)return;confirmDecision.disabled=cancelDecision.disabled=true;message.classList.remove('error');message.textContent=decision==='approve'?'Publishing the locked revision…':'Rejecting the publication request…';try{const response=await callTool('smartcloud-agent-composer-decide-publish-approval',{id:current.id,token:current.approval_token,decision,confirm_decision:true});const data=resultData(response)||response?.result?.result||response;const state=data?.status||data?.result?.status;if(!['approved','rejected'].includes(state))throw new Error('The decision was not accepted.');current={...current,...data,approval_token:null,approval_url:null};pendingDecision=null;confirmationTrigger=null;confirmation.hidden=true;document.body.removeAttribute('data-modal');showStatus(state);fallback.hidden=true;message.textContent=statusMessage(state)}catch(error){confirmDecision.disabled=cancelDecision.disabled=false;message.classList.add('error');message.textContent=error?.message||'The decision could not be applied.'}}
approve.addEventListener('click',()=>askDecision('approve'));reject.addEventListener('click',()=>askDecision('reject'));cancelDecision.addEventListener('click',cancelConfirmation);confirmDecision.addEventListener('click',()=>decide(pendingDecision));confirmation.addEventListener('keydown',event=>{if(event.key==='Escape'){event.preventDefault();cancelConfirmation();return}if(event.key!=='Tab')return;const first=cancelDecision;const last=confirmDecision;if(event.shiftKey&&document.activeElement===first){event.preventDefault();last.focus()}else if(!event.shiftKey&&document.activeElement===last){event.preventDefault();first.focus()}});fallback.addEventListener('click',()=>{if(!current?.approval_url)return;if(window.openai?.openExternal)window.openai.openExternal({href:current.approval_url});else window.open(current.approval_url,'_blank','noopener,noreferrer')});root.addEventListener('click',event=>{if(event.target.closest('a'))event.preventDefault()},{capture:true});window.addEventListener('pagehide',clearBlobs,{passive:true});window.addEventListener('message',event=>{if(event.source!==window.parent)return;const msg=event.data;if(!msg||msg.jsonrpc!=='2.0')return;if(msg.id!==undefined&&pending.has(msg.id)){const waiter=pending.get(msg.id);pending.delete(msg.id);msg.error?waiter.reject(new Error(msg.error.message||'MCP Apps request failed')):waiter.resolve(msg.result);return}if(msg.method==='ui/notifications/tool-result')render(msg.params)},{passive:true});setAppHeight(window.openai?.maxHeight);if(window.openai?.toolOutput)render(window.openai.toolOutput);window.addEventListener('openai:set_globals',event=>{const globals=event.detail?.globals||{};if(globals.maxHeight!==undefined)setAppHeight(globals.maxHeight);if(globals.toolOutput!==undefined)render(globals.toolOutput)},{passive:true});(async()=>{try{await request('ui/initialize',{appInfo:{name:'Composer publication approval',version:'6.0.0'},appCapabilities:{tools:{}},protocolVersion:'2026-01-26'});notify('ui/notifications/initialized')}catch(error){message.textContent='The approval host bridge is unavailable.'}})();
</script></body></html>
HTML;
	}

	private function register_rendered_preview_resource(): void {
		$resources = array(
			self::PREFIX . 'rendered-preview-app'    => ComposerMcpServer::PREVIEW_RESOURCE_URI,
			self::PREFIX . 'rendered-preview-app-v5' => ComposerMcpServer::PREVIEW_RESOURCE_URI_V5,
			self::PREFIX . 'rendered-preview-app-v4' => ComposerMcpServer::PREVIEW_RESOURCE_URI_V4,
			self::PREFIX . 'rendered-preview-app-v3' => ComposerMcpServer::PREVIEW_RESOURCE_URI_V3,
			self::PREFIX . 'rendered-preview-app-v2' => ComposerMcpServer::PREVIEW_RESOURCE_URI_V2,
			self::PREFIX . 'rendered-preview-app-v1' => ComposerMcpServer::PREVIEW_RESOURCE_URI_V1,
		);
		foreach ( $resources as $name => $uri ) {
			if ( function_exists( 'wp_has_ability' ) && wp_has_ability( $name ) ) {
				continue;
			}
			wp_register_ability(
				$name,
				array(
					'label'               => 'Rendered preview app',
					'description'         => 'MCP Apps UI resource for displaying the latest sanitized Composer draft preview. Versioned legacy URIs remain aliases for cached clients.',
					'category'            => self::CATEGORY,
					'output_schema'       => array( 'type' => 'array', 'items' => array( 'type' => 'object', 'additionalProperties' => true ) ),
					'execute_callback'    => function ( array $input = array() ) use ( $uri ): array {
						unset( $input );
						return $this->rendered_preview_resource_for_uri( $uri );
					},
					'permission_callback' => $this->permission_callback_for( $name ),
					'meta'                => array(
						'show_in_rest' => false,
						'mcp'          => array(
							'public'      => false,
							'type'        => 'resource',
							'uri'         => $uri,
							'name'        => 'Composer rendered preview',
							'title'       => 'Composer rendered preview',
							'description' => 'Displays the latest sanitized static HTML preview returned by get-rendered-preview.',
							'mimeType'    => 'text/html;profile=mcp-app',
							'_meta'       => array( 'ui' => $this->rendered_preview_ui_meta() ),
							'annotations' => array( 'audience' => array( 'user', 'assistant' ), 'priority' => 0.9 ),
						),
					),
				)
			);
		}
	}

	public function rendered_preview_resource( array $input = array() ): array {
		unset( $input );
		return $this->rendered_preview_resource_for_uri( ComposerMcpServer::PREVIEW_RESOURCE_URI );
	}

	private function rendered_preview_resource_for_uri( string $uri ): array {
		return array(
			array(
				'uri'      => $uri,
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
				'resourceDomains' => array(),
				'frameDomains'    => array(),
			),
		);
	}

	private function rendered_preview_app_html(): string {
		return <<<'HTML'
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>
:root{color-scheme:light dark;font:14px/1.5 system-ui,sans-serif}html,body{margin:0;min-height:0;overflow:hidden}body{padding:16px;background:transparent;color:CanvasText}.preview-app{box-sizing:border-box;height:var(--composer-app-height,680px);display:grid;grid-template-rows:auto minmax(0,1fr) auto;gap:12px;overflow:hidden}.meta{display:flex;gap:12px;align-items:center;min-width:0;color:GrayText;font-size:12px}.preview{min-height:0;contain:layout paint style;isolation:isolate;overflow:auto;border:1px solid color-mix(in srgb,CanvasText 16%,transparent);border-radius:12px;background:Canvas}.preview[data-ready="false"]{visibility:hidden}.warnings{box-sizing:border-box;max-height:112px;min-height:1.5em;overflow:hidden;margin:0;padding-left:20px;color:GrayText}.empty{padding:24px;text-align:center;color:GrayText}
</style></head><body><div id="preview-app" class="preview-app"><div class="meta"><strong id="title">Draft preview</strong><span id="details"></span></div><main id="preview" class="preview" data-ready="false" aria-busy="true"></main><ul id="warnings" class="warnings" hidden></ul></div><script>
const app=document.getElementById('preview-app');const preview=document.getElementById('preview');const root=preview.attachShadow({mode:'open'});const title=document.getElementById('title');const details=document.getElementById('details');const warnings=document.getElementById('warnings');const pending=new Map();const blobUrls=new Set();let requestId=1;let generation=0;let lastDocumentKey='';
const chromeCss=':host{display:block;padding:clamp(16px,4vw,40px);color:CanvasText;background:Canvas}:host([dir="rtl"]){direction:rtl}body.smartcloud-composer-preview-document{box-sizing:border-box;width:100%;height:auto!important;overflow:visible!important}img{max-width:100%;height:auto}table{display:block;max-width:100%;overflow:auto}a{color:LinkText}';
function post(message){window.parent.postMessage(message,'*')}
function request(method,params){const id=requestId++;post({jsonrpc:'2.0',id,method,params});return new Promise((resolve,reject)=>pending.set(id,{resolve,reject}))}
function notify(method,params){post({jsonrpc:'2.0',method,...(params===undefined?{}:{params})})}
function setAppHeight(value){const maximum=Number(value);const height=Number.isFinite(maximum)&&maximum>0?Math.min(760,Math.max(320,maximum-32)):680;app.style.setProperty('--composer-app-height',height+'px')}
function safeStyle(value){const css=String(value||'');return /(?:url\s*\(|image-set\s*\(|@import|expression\s*\(|javascript\s*:|data\s*:|behavior\s*:|-moz-binding\s*:)/i.test(css)?'':css}
function sanitize(html){const parsed=new DOMParser().parseFromString(String(html||''),'text/html');parsed.querySelectorAll('script,iframe,object,embed,base,meta,link,style,form,input,button,textarea,select,audio,video,source,track,picture').forEach((node)=>node.remove());parsed.querySelectorAll('*').forEach((node)=>{for(const attr of [...node.attributes]){const name=attr.name.toLowerCase();if(name.startsWith('on')||name==='srcdoc'||name==='srcset'||name==='poster')node.removeAttribute(attr.name);else if(name==='style'){const style=safeStyle(attr.value);if(style)node.setAttribute('style',style);else node.removeAttribute('style')}}});const fragment=document.createDocumentFragment();fragment.append(...parsed.body.childNodes);return fragment}
function clearBlobs(){for(const url of blobUrls)URL.revokeObjectURL(url);blobUrls.clear()}
function resultData(payload){const result=payload?.structuredContent??payload;return result?.document?result:result?.result}
function contentBlocks(payload){for(const candidate of [payload,payload?.result,payload?.result?.result]){if(Array.isArray(candidate?.content))return candidate.content}return[]}
async function callAsset(args){const name='smartcloud-agent-composer-get-rendered-preview-asset';if(window.openai?.callTool)return window.openai.callTool(name,args);return request('tools/call',{name,arguments:args})}
function decodeBlob(data,mimeType){const binary=atob(String(data||''));const bytes=new Uint8Array(binary.length);for(let index=0;index<binary.length;index++)bytes[index]=binary.charCodeAt(index);return new Blob([bytes],{type:mimeType||'application/octet-stream'})}
async function loadAsset(asset,args){const response=await callAsset({...args,asset_id:asset.asset_id});const blocks=contentBlocks(response);if(asset.kind==='stylesheet'){const block=blocks.find((item)=>item.type==='resource'&&String(item.resource?.mimeType||'').startsWith('text/css'));if(!block)throw new Error('Stylesheet asset response was invalid.');return{asset,css:String(block.resource.text||'')}}if(asset.kind==='font'){const block=blocks.find((item)=>item.type==='resource'&&item.resource?.blob);if(!block)throw new Error('Font asset response was invalid.');return{asset,blob:decodeBlob(block.resource.blob,block.resource.mimeType)}}const block=blocks.find((item)=>item.type==='image');if(!block)throw new Error('Image asset response was invalid.');return{asset,blob:decodeBlob(block.data,block.mimeType)}}
async function loadPool(items,args,token){const results=new Array(items.length);let cursor=0;async function worker(){while(cursor<items.length){const index=cursor++;try{results[index]=await loadAsset(items[index],args)}catch(error){results[index]={asset:items[index],error}}} }await Promise.all(Array.from({length:Math.min(2,items.length)},worker));return token===generation?results:[]}
function showWarnings(items){warnings.replaceChildren();for(const warning of items){const item=document.createElement('li');item.textContent=warning.message||warning.code||'Preview warning';warnings.append(item)}warnings.hidden=!warnings.children.length}
async function render(payload){const data=resultData(payload);const doc=data?.document;if(!doc)return;const documentKey=[doc.post_id,doc.revision,doc.sha256].join('|');if(documentKey===lastDocumentKey)return;lastDocumentKey=documentKey;const token=++generation;preview.dataset.ready='false';preview.setAttribute('aria-busy','true');clearBlobs();title.textContent=doc.title||'Draft preview';details.textContent=(doc.content_language||'')+' · '+(doc.byte_length||0)+' bytes';root.replaceChildren();const base=document.createElement('style');base.textContent=chromeCss;root.append(base);const fragment=sanitize(doc.html);const article=fragment.querySelector('article');const shell=document.createElement('body');shell.className=article?.getAttribute('data-smartcloud-preview-body-classes')||'';shell.classList.add('smartcloud-composer-preview-document');article?.removeAttribute('data-smartcloud-preview-body-classes');const site=document.createElement('div');site.className='wp-site-blocks';const main=document.createElement('main');const content=document.createElement('div');content.className='wp-block-post-content';content.append(fragment);main.append(content);site.append(main);shell.append(site);root.append(shell);root.host.setAttribute('dir',doc.direction==='rtl'?'rtl':'ltr');root.host.setAttribute('lang',doc.content_language||'en');const args={post_id:doc.post_id,expected_revision:doc.revision};const assets=[...(doc.assets||[])].sort((left,right)=>(left.order||0)-(right.order||0));const loaded=await loadPool(assets,args,token);if(token!==generation)return;const extraWarnings=[...(doc.warnings||[])];const assetUrls=new Map();for(const result of loaded){if(!result)continue;if(result.error){extraWarnings.push({message:'A preview '+result.asset.kind+' could not be loaded.'});continue}if(result.blob){const url=URL.createObjectURL(result.blob);blobUrls.add(url);assetUrls.set(result.asset.asset_id,url);if(result.asset.kind==='image'){const selector='img[data-smartcloud-preview-asset="'+CSS.escape(result.asset.asset_id)+'"]';for(const image of root.querySelectorAll(selector))image.src=url}}}for(const result of loaded){if(!result||result.error||result.css===undefined)continue;let css=result.css;for(const [assetId,url] of assetUrls)css=css.split('smartcloud-preview-asset://'+assetId).join(url);css=css.replace(/smartcloud-preview-asset:\/\/pa_[A-Za-z0-9_-]{43}/g,'data:,');const style=document.createElement('style');style.media=result.asset.media||'all';style.textContent=css;root.insertBefore(style,shell)}showWarnings(extraWarnings);if(document.fonts?.ready)await document.fonts.ready;if(token!==generation)return;await new Promise(resolve=>requestAnimationFrame(resolve));preview.dataset.ready='true';preview.setAttribute('aria-busy','false')}
root.addEventListener('click',(event)=>{if(event.target.closest('a'))event.preventDefault()},{capture:true});window.addEventListener('pagehide',clearBlobs,{passive:true});
window.addEventListener('message',(event)=>{if(event.source!==window.parent)return;const message=event.data;if(!message||message.jsonrpc!=='2.0')return;if(message.id!==undefined&&pending.has(message.id)){const waiter=pending.get(message.id);pending.delete(message.id);if(message.error)waiter.reject(new Error(message.error.message||'MCP Apps request failed'));else waiter.resolve(message.result);return}if(message.method==='ui/notifications/tool-result')render(message.params)},{passive:true});
setAppHeight(window.openai?.maxHeight);if(window.openai?.toolOutput)render(window.openai.toolOutput);
window.addEventListener('openai:set_globals',(event)=>{const globals=event.detail?.globals||{};if(globals.maxHeight!==undefined)setAppHeight(globals.maxHeight);if(globals.toolOutput!==undefined)render(globals.toolOutput)},{passive:true});
(async()=>{try{await request('ui/initialize',{appInfo:{name:'Composer rendered preview',version:'6.0.0'},appCapabilities:{tools:{}},protocolVersion:'2026-01-26'});notify('ui/notifications/initialized')}catch(error){if(!window.openai?.toolOutput){root.innerHTML='<p class="empty">The preview host bridge is unavailable.</p>';preview.dataset.ready='true';preview.setAttribute('aria-busy','false')}}})();
</script></body></html>
HTML;
	}

	private function execute( string $slug, array $input, callable $callback ): array|\WP_Error {
		$operation = self::PREFIX . $slug;
		$this->audit->begin_operation();
		$permission = $this->check_permission_for( $operation );
		if ( is_wp_error( $permission ) ) {
			$this->audit->log( $operation, 'denied', $input, 0, $permission->get_error_code() );
			return $permission;
		}
		if ( true !== $permission ) {
			$this->audit->log( $operation, 'denied', $input, 0, 'permission_denied' );
			return new \WP_Error( 'smartcloud_agent_permission_denied', 'The current Composer actor is not authorized for this operation.', array( 'status' => 403, 'request_id' => $this->audit->get_request_id() ) );
		}

		try {
			$result    = $callback();
			$object_id = is_array( $result ) ? absint( $result['post_id'] ?? $result['term_id'] ?? $result['attachment_id'] ?? 0 ) : 0;
			$this->audit->log( $operation, 'success', $input, $object_id );
			if ( is_array( $result ) ) {
				$result['_request_id'] = $this->audit->get_request_id();
			}
			return $result;
		} catch ( Execution_Exception $error ) {
			$post_id  = absint( $input['post_id'] ?? 0 );
			$conflict = in_array(
				$error->get_execution_code(),
				array( 'edit_conflict', 'document_read_conflict', 'draft_assigned_to_other_agent', 'taxonomy_term_conflict', 'proposal_creation_conflict', 'proposal_assigned_to_other_agent', 'localization_context_conflict', 'localized_content_group_conflict', 'localized_group_snapshot_conflict', 'localized_group_language_slot_conflict', 'localized_group_member_conflict' ),
				true
			);
			$this->audit->log( $operation, 'error', $input, $post_id, $error->get_execution_code(), array( 'conflict' => $conflict ) );
			$denied = in_array( $error->get_execution_code(), array( 'taxonomy_term_create_denied', 'taxonomy_assignment_denied', 'proposal_create_denied', 'proposal_source_read_denied', 'localization_content_read_denied', 'localized_content_edit_forbidden', 'migration_permission_denied', 'publisher_media_upload_denied' ), true );
			$status = $conflict ? 409 : ( $denied ? 403 : 400 );
			return new \WP_Error(
				'smartcloud_agent_' . $error->get_execution_code(),
				$error->getMessage(),
				array_merge(
					$error->get_execution_data(),
					array( 'status' => $status, 'request_id' => $this->audit->get_request_id() )
				)
			);
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
			'required'             => array( 'post_id', 'expected_modified_gmt', 'expected_revision' ),
			'additionalProperties' => false,
		);
	}

	private function rendered_preview_asset_input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'post_id'               => array( 'type' => 'integer', 'minimum' => 1 ),
				'expected_modified_gmt' => array( 'type' => 'string', 'format' => 'date-time' ),
				'expected_revision'     => array( 'type' => 'string', 'format' => 'uuid' ),
				'asset_id'              => array( 'type' => 'string', 'pattern' => '^pa_[A-Za-z0-9_-]{43}$' ),
			),
			'required'             => array( 'post_id', 'expected_revision', 'asset_id' ),
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
				'rendered_preview_token' => array( 'type' => 'string', 'pattern' => '^pv1\\.[0-9]{10}\\.[A-Za-z0-9_-]{43}$' ),
				'document'    => array(
					'type'                 => 'object',
					'properties'           => array(
						'contract_version' => array( 'type' => 'string', 'enum' => array( '3' ) ),
						'post_id'          => array( 'type' => 'integer', 'minimum' => 1 ),
						'modified_gmt'      => array( 'type' => 'string', 'format' => 'date-time' ),
						'revision'          => array( 'type' => 'string', 'format' => 'uuid' ),
						'title'             => array( 'type' => 'string' ),
						'content_language'  => array( 'type' => 'string', 'minLength' => 2, 'maxLength' => 35 ),
						'direction'         => array( 'type' => 'string', 'enum' => array( 'ltr', 'rtl' ) ),
						'scope'             => array( 'type' => 'string', 'enum' => array( 'content', 'theme-document' ) ),
						'fidelity'          => array( 'type' => 'string', 'enum' => array( 'static' ) ),
						'source_format'     => array( 'type' => 'string', 'enum' => array( 'rendered-html' ) ),
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
									'asset_id'    => array( 'type' => 'string', 'pattern' => '^pa_[A-Za-z0-9_-]{43}$' ),
									'kind'        => array( 'type' => 'string', 'enum' => array( 'image', 'stylesheet', 'font' ) ),
									'mime_type'   => array( 'type' => 'string', 'enum' => array( 'image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif', 'text/css', 'font/woff', 'font/woff2' ) ),
									'byte_length' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 5242880 ),
									'sha256'      => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ),
									'handle'      => array( 'type' => 'string' ),
									'media'       => array( 'type' => 'string' ),
									'order'       => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 15 ),
								),
								'required'             => array( 'asset_id', 'kind', 'mime_type', 'byte_length', 'sha256' ),
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
					'required'             => array( 'contract_version', 'post_id', 'modified_gmt', 'revision', 'title', 'content_language', 'direction', 'scope', 'fidelity', 'source_format', 'mime_type', 'html', 'sha256', 'byte_length', 'assets', 'warnings' ),
					'additionalProperties' => false,
				),
				'_request_id' => array( 'type' => 'string' ),
			),
			'required'             => array( 'post_id', 'edit_url', 'preview_url', 'validation', 'rendered_preview_token', 'document', '_request_id' ),
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

	public function semantic_field_update_schema(): array {
		$field_id = array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 128, 'pattern' => '^[a-z][a-z0-9._-]{0,127}$' );
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'post_id'               => array( 'type' => 'integer', 'minimum' => 1 ),
				'expected_modified_gmt' => array( 'type' => 'string', 'format' => 'date-time' ),
				'expected_revision'     => array( 'type' => 'string', 'format' => 'uuid' ),
				'field_id'              => $field_id,
				'field'                 => $field_id,
				'pattern_instance_id'   => $this->pattern_instance_id_schema(),
				'attribute'             => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 128, 'pattern' => '^[A-Za-z][A-Za-z0-9_-]{0,127}$' ),
				'value'                 => array( 'type' => array( 'string', 'number', 'integer', 'boolean', 'object', 'array', 'null' ) ),
				'confirm_update'        => array( 'type' => 'boolean', 'enum' => array( true ) ),
			),
			'required'             => array( 'post_id', 'expected_modified_gmt', 'expected_revision', 'field_id', 'value', 'confirm_update' ),
			'additionalProperties' => false,
		);
	}

	public function semantic_media_update_schema(): array {
		$field_id = array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 128, 'pattern' => '^[a-z][a-z0-9._-]{0,127}$' );
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'post_id'               => array( 'type' => 'integer', 'minimum' => 1 ),
				'expected_modified_gmt' => array( 'type' => 'string', 'format' => 'date-time' ),
				'expected_revision'     => array( 'type' => 'string', 'format' => 'uuid' ),
				'field_id'              => $field_id,
				'field'                 => $field_id,
				'pattern_instance_id'   => $this->pattern_instance_id_schema(),
				'attachment_id'         => array( 'type' => 'integer', 'minimum' => 1 ),
				'size_slug'             => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 64, 'pattern' => '^[a-z0-9_-]+$' ),
				'confirm_update'        => array( 'type' => 'boolean', 'enum' => array( true ) ),
			),
			'required'             => array( 'post_id', 'expected_modified_gmt', 'expected_revision', 'field_id', 'attachment_id', 'confirm_update' ),
			'additionalProperties' => false,
		);
	}

	public function semantic_slot_insert_schema(): array {
		$schema = $this->semantic_slot_mutation_base_schema();
		$schema['properties']['position'] = array( 'type' => 'string', 'enum' => array( 'start', 'end', 'before', 'after' ), 'default' => 'end' );
		$schema['properties']['reference_user_block_id'] = $this->user_block_id_schema();
		$schema['properties']['block'] = $this->semantic_slot_block_schema();
		$schema['required'][] = 'block';
		return $schema;
	}

	public function semantic_slot_update_schema(): array {
		$schema = $this->semantic_slot_mutation_base_schema();
		$schema['properties']['user_block_id'] = $this->user_block_id_schema();
		$schema['properties']['block'] = $this->semantic_slot_block_schema();
		$schema['required'] = array_merge( $schema['required'], array( 'user_block_id', 'block' ) );
		return $schema;
	}

	public function semantic_slot_move_schema(): array {
		$schema = $this->semantic_slot_mutation_base_schema();
		$schema['properties']['user_block_id'] = $this->user_block_id_schema();
		$schema['properties']['position'] = array( 'type' => 'string', 'enum' => array( 'start', 'end', 'before', 'after' ) );
		$schema['properties']['reference_user_block_id'] = $this->user_block_id_schema();
		$schema['required'] = array_merge( $schema['required'], array( 'user_block_id', 'position' ) );
		return $schema;
	}

	public function semantic_slot_remove_schema(): array {
		$schema = $this->semantic_slot_mutation_base_schema();
		$schema['properties']['user_block_id'] = $this->user_block_id_schema();
		$schema['required'][] = 'user_block_id';
		return $schema;
	}

	private function semantic_slot_mutation_base_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'post_id'               => array( 'type' => 'integer', 'minimum' => 1 ),
				'expected_modified_gmt' => array( 'type' => 'string', 'format' => 'date-time' ),
				'expected_revision'     => array( 'type' => 'string', 'format' => 'uuid' ),
				'slot'                  => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 128, 'pattern' => '^[a-z][a-z0-9._-]{0,127}$' ),
				'pattern_instance_id'   => $this->pattern_instance_id_schema(),
				'confirm_update'        => array( 'type' => 'boolean', 'enum' => array( true ) ),
			),
			'required'             => array( 'post_id', 'expected_modified_gmt', 'expected_revision', 'slot', 'confirm_update' ),
			'additionalProperties' => false,
		);
	}

	private function user_block_id_schema(): array {
		return array( 'type' => 'string', 'minLength' => 13, 'maxLength' => 63, 'pattern' => '^user-[a-z0-9-]{8,58}$' );
	}

	private function pattern_instance_id_schema(): array {
		return array( 'type' => 'string', 'minLength' => 16, 'maxLength' => 66, 'pattern' => '^pattern-[a-z0-9-]{8,58}$' );
	}

	private function semantic_slot_block_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'name'          => array( 'type' => 'string', 'enum' => array( 'core/paragraph', 'core/heading', 'core/button', 'core/image', 'core/separator', 'core/spacer' ) ),
				'attributes'    => array( 'type' => 'object', 'maxProperties' => 50, 'additionalProperties' => true ),
				'content'       => array( 'type' => 'string', 'maxLength' => 250000 ),
				'attachment_id' => array( 'type' => 'integer', 'minimum' => 1 ),
				'size_slug'     => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 64, 'pattern' => '^[a-z0-9_-]+$' ),
				'provider'      => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 64, 'pattern' => '^[a-z0-9][a-z0-9-]{0,63}$' ),
				'component'     => array( 'type' => 'string', 'minLength' => 1, 'maxLength' => 64, 'pattern' => '^[a-z0-9][a-z0-9-]{0,63}$' ),
				'spec'          => array( 'type' => 'object', 'maxProperties' => 200, 'additionalProperties' => true ),
				'pattern'       => array( 'type' => 'string', 'pattern' => '^[a-z0-9-]+/[a-z0-9-]+$' ),
				'pattern_instance_id' => $this->pattern_instance_id_schema(),
				'fields'        => array( 'type' => 'object', 'maxProperties' => 100, 'additionalProperties' => true ),
			),
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

	public function publisher_media_upload_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'content_base64'      => array(
					'type'        => 'string',
					'minLength'   => 4,
					'maxLength'   => 16777216,
					'description' => 'Raw base64 image bytes without a data-URL prefix. Supply this or source_url, never both.',
				),
				'source_url'          => array(
					'type'        => 'string',
					'format'      => 'uri',
					'minLength'   => 10,
					'maxLength'   => 4096,
					'description' => 'Safe public HTTPS image URL without credentials, fragments, redirects, or a custom port. Supply this or content_base64, never both.',
				),
				'mime_type'           => array( 'type' => 'string', 'enum' => array( 'image/jpeg', 'image/png', 'image/webp', 'image/avif', 'image/gif' ) ),
				'slug'                => array(
					'type'        => 'string',
					'minLength'   => 3,
					'maxLength'   => 120,
					'pattern'     => '^[a-z0-9]+(?:-[a-z0-9]+)*$',
					'description' => 'Required meaningful SEO filename stem. Describe the asset; never use an AI vendor name or generated-image placeholder.',
				),
				'title'               => $this->string_property( 'Required concise Media Library title.', 1, 200 ),
				'alt_mode'            => array(
					'type'        => 'string',
					'enum'        => array( 'descriptive', 'decorative' ),
					'description' => 'Choose descriptive for meaningful content or decorative only when an empty alt attribute is appropriate.',
				),
				'alt_text'            => $this->string_property( 'Meaningful alternative text for descriptive media; must be empty for decorative media.', 0, 500 ),
				'caption'             => $this->string_property( 'Optional plain-text Media Library caption.', 0, 1000 ),
				'description'         => $this->string_property( 'Optional plain-text Media Library description.', 0, 4000 ),
				'idempotency_key'     => array( 'type' => 'string', 'minLength' => 8, 'maxLength' => 128, 'pattern' => '^[A-Za-z0-9._:-]+$' ),
				'confirm_publication' => array( 'type' => 'boolean', 'enum' => array( true ), 'description' => 'Explicitly acknowledge that the upload becomes a publicly addressable asset immediately.' ),
				'confirm_rights'      => array( 'type' => 'boolean', 'enum' => array( true ), 'description' => 'Explicitly confirm that the site may store and publish this asset.' ),
			),
			'required'             => array( 'mime_type', 'slug', 'title', 'alt_mode', 'alt_text', 'idempotency_key', 'confirm_publication', 'confirm_rights' ),
			'oneOf'                => array(
				array( 'required' => array( 'content_base64' ) ),
				array( 'required' => array( 'source_url' ) ),
			),
			'additionalProperties' => false,
		);
	}

	public function publisher_media_upload_output_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'attachment_id' => array( 'type' => 'integer', 'minimum' => 1 ),
				'created'       => array( 'type' => 'boolean' ),
				'public_asset'  => array( 'type' => 'boolean', 'enum' => array( true ) ),
				'source_kind'   => array( 'type' => 'string', 'enum' => array( 'base64', 'remote' ) ),
				'slug'          => array( 'type' => 'string' ),
				'file_name'     => array( 'type' => 'string' ),
				'title'         => array( 'type' => 'string' ),
				'alt_text'      => array( 'type' => 'string' ),
				'mime_type'     => array( 'type' => 'string' ),
				'url'           => array( 'type' => 'string', 'format' => 'uri' ),
				'width'         => array( 'type' => 'integer', 'minimum' => 1 ),
				'height'        => array( 'type' => 'integer', 'minimum' => 1 ),
			),
			'required'             => array( 'attachment_id', 'created', 'public_asset', 'source_kind', 'slug', 'file_name', 'title', 'alt_text', 'mime_type', 'url', 'width', 'height' ),
			'additionalProperties' => true,
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

	public function blueprint_migration_preview_schema(): array {
		return array(
			'type' => 'object',
			'properties' => array(
				'post_id'      => array( 'type' => 'integer', 'minimum' => 1 ),
				'page_type'    => array( 'type' => 'string', 'pattern' => '^[a-z0-9-]{1,64}$' ),
				'migration_id' => array( 'type' => 'string', 'pattern' => '^[a-z][a-z0-9-]{0,63}$' ),
			),
			'required' => array( 'post_id', 'page_type', 'migration_id' ),
			'additionalProperties' => false,
		);
	}

	public function blueprint_migration_proposal_schema(): array {
		$schema = $this->blueprint_migration_preview_schema();
		$schema['properties']['content_language'] = $this->string_property( 'Exact approved BCP 47 language of the localized source.', 2, 35 );
		$schema['properties']['expected_modified_gmt'] = array( 'type' => 'string', 'format' => 'date-time' );
		$schema['properties']['expected_content_hash'] = array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' );
		$schema['properties']['expected_migration_plan_hash'] = array( 'type' => 'string', 'pattern' => '^sha256:[a-f0-9]{64}$' );
		$schema['properties']['idempotency_key'] = array( 'type' => 'string', 'minLength' => 8, 'maxLength' => 128, 'pattern' => '^[A-Za-z0-9._:-]+$' );
		$schema['properties']['confirm_proposal'] = array( 'type' => 'boolean', 'enum' => array( true ) );
		$schema['required'] = array_merge(
			$schema['required'],
			array( 'content_language', 'expected_modified_gmt', 'expected_content_hash', 'expected_migration_plan_hash', 'idempotency_key', 'confirm_proposal' )
		);
		return $schema;
	}

	public function bulk_blueprint_migration_plan_schema(): array {
		return array(
			'type' => 'object',
			'properties' => array(
				'page_type'    => array( 'type' => 'string', 'pattern' => '^[a-z0-9-]{1,64}$' ),
				'migration_id' => array( 'type' => 'string', 'pattern' => '^[a-z][a-z0-9-]{0,63}$' ),
				'page'         => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100000, 'default' => 1 ),
				'per_page'     => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 25 ),
			),
			'required' => array( 'page_type', 'migration_id' ),
			'additionalProperties' => false,
		);
	}

	public function bulk_blueprint_migration_create_schema(): array {
		return array(
			'type' => 'object',
			'properties' => array(
				'page_type'    => array( 'type' => 'string', 'pattern' => '^[a-z0-9-]{1,64}$' ),
				'migration_id' => array( 'type' => 'string', 'pattern' => '^[a-z][a-z0-9-]{0,63}$' ),
				'items' => array(
					'type' => 'array',
					'minItems' => 1,
					'maxItems' => 25,
					'items' => array(
						'type' => 'object',
						'properties' => array(
							'post_id'                       => array( 'type' => 'integer', 'minimum' => 1 ),
							'content_language'              => $this->string_property( 'Exact source language returned by bulk migration planning.', 2, 35 ),
							'expected_modified_gmt'         => array( 'type' => 'string', 'format' => 'date-time' ),
							'expected_content_hash'         => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$' ),
							'expected_migration_plan_hash'  => array( 'type' => 'string', 'pattern' => '^sha256:[a-f0-9]{64}$' ),
							'idempotency_key'               => array( 'type' => 'string', 'minLength' => 8, 'maxLength' => 128, 'pattern' => '^[A-Za-z0-9._:-]+$' ),
						),
						'required' => array( 'post_id', 'content_language', 'expected_modified_gmt', 'expected_content_hash', 'expected_migration_plan_hash', 'idempotency_key' ),
						'additionalProperties' => false,
					),
				),
				'confirm_bulk_proposals' => array( 'type' => 'boolean', 'enum' => array( true ) ),
			),
			'required' => array( 'page_type', 'migration_id', 'items', 'confirm_bulk_proposals' ),
			'additionalProperties' => false,
		);
	}

	public function content_proposal_submit_schema(): array {
		$schema = array(
			'type' => 'object',
			'properties' => array(
				'post_id' => array( 'type' => 'integer', 'minimum' => 1 ),
				'expected_modified_gmt' => array( 'type' => 'string', 'format' => 'date-time' ),
				'expected_revision' => array( 'type' => 'string', 'format' => 'uuid' ),
				'rendered_preview_token' => $this->string_property(
					$this->is_rendered_preview_required()
						? 'Exact token returned by get-rendered-preview for this final proposal revision.'
						: 'Optional exact token returned by get-rendered-preview for this final proposal revision. Omit only when the user explicitly requests no HTML preview.',
					58,
					58
				),
			),
			'required' => array( 'post_id', 'expected_modified_gmt', 'expected_revision' ),
			'additionalProperties' => false,
		);
		if ( $this->is_rendered_preview_required() ) {
			$schema['required'][] = 'rendered_preview_token';
		}
		return $schema;
	}

	public function content_proposal_validate_schema(): array {
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
					'pattern_instance_id' => $this->pattern_instance_id_schema(),
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
