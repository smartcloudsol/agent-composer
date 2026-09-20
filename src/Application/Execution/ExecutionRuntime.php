<?php

namespace SmartCloud\AgentComposer\Application\Execution;

use SmartCloud\AgentComposer\Application\Preview\PreviewDraftService;
use SmartCloud\AgentComposer\Domain\Structure\StructureDocumentValidator;
use SmartCloud\AgentComposer\Execution\Abilities;
use SmartCloud\AgentComposer\Execution\Admin_Managed_Document_Service;
use SmartCloud\AgentComposer\Execution\Ability_Provider_Registry;
use SmartCloud\AgentComposer\Execution\Audit_Logger;
use SmartCloud\AgentComposer\Execution\Block_Catalog;
use SmartCloud\AgentComposer\Execution\Block_Tree_Service;
use SmartCloud\AgentComposer\Execution\Blueprint_Migration_Service;
use SmartCloud\AgentComposer\Execution\Bulk_Blueprint_Migration_Service;
use SmartCloud\AgentComposer\Execution\Config_Repository;
use SmartCloud\AgentComposer\Execution\Content_Field_Materializer;
use SmartCloud\AgentComposer\Execution\Draft_Service;
use SmartCloud\AgentComposer\Execution\Markup_Contract_Validator;
use SmartCloud\AgentComposer\Execution\Managed_Document_State;
use SmartCloud\AgentComposer\Execution\Page_Validator;
use SmartCloud\AgentComposer\Execution\Pattern_Assembler;
use SmartCloud\AgentComposer\Execution\Pattern_Repository;
use SmartCloud\AgentComposer\Execution\Query_Loop_Materializer;
use SmartCloud\AgentComposer\Execution\Remote_Media_Ingestor;
use SmartCloud\AgentComposer\Execution\Publisher_Media_Uploader;
use SmartCloud\AgentComposer\Execution\Rendered_Preview_Service;
use SmartCloud\AgentComposer\Execution\Semantic_Slot_Materializer;
use SmartCloud\AgentComposer\Execution\Semantic_Document_Service;
use SmartCloud\AgentComposer\Execution\Structure_Contract_Save_Guard;
use SmartCloud\AgentComposer\Execution\Structure_Editor_Projector;
use SmartCloud\AgentComposer\Execution\Synced_Structural_Pattern_Service;
use SmartCloud\AgentComposer\Execution\Content_Language_Validator;
use SmartCloud\AgentComposer\Execution\Content_Proposal_Service;
use SmartCloud\AgentComposer\Execution\Localization_Provider_Registry;
use SmartCloud\AgentComposer\Execution\Localized_Draft_Service;
use SmartCloud\AgentComposer\Execution\Target_Resolver;
use SmartCloud\AgentComposer\Execution\Taxonomy_Term_Service;
use SmartCloud\AgentComposer\Infrastructure\Persistence\ActiveConfigurationSource;
use SmartCloud\AgentComposer\Infrastructure\Persistence\AuditTable;
use SmartCloud\AgentComposer\Infrastructure\Persistence\PublishApprovalTable;
use SmartCloud\AgentComposer\Infrastructure\Persistence\WordPressConfigurationRepository;
use SmartCloud\AgentComposer\Integration\Abilities\ExecutionAbilityAliases;
use SmartCloud\AgentComposer\Integration\Mcp\ComposerMcpServer;
use SmartCloud\AgentComposer\Security\CognitoJwtValidator;
use SmartCloud\AgentComposer\Security\McpAccessGuard;
use SmartCloud\AgentComposer\Security\McpSecuritySettings;
use SmartCloud\AgentComposer\Security\PublishApprovalService;

final class ExecutionRuntime {
	private readonly Ability_Provider_Registry $providers;
	private readonly Pattern_Repository $patterns;
	private readonly Draft_Service $drafts;
	private readonly Abilities $abilities;
	private readonly ExecutionAbilityAliases $aliases;
	private readonly PreviewDraftService $previews;
	private readonly ComposerMcpServer $mcp;
	private readonly Localization_Provider_Registry $localization;
	private readonly Content_Proposal_Service $proposals;
	private readonly Structure_Contract_Save_Guard $structure_guard;
	private readonly Admin_Managed_Document_Service $admin_documents;
	private readonly McpAccessGuard $mcp_access;
	private readonly McpSecuritySettings $mcp_security_settings;
	private readonly PublishApprovalService $publish_approvals;

	public function __construct() {
		$repository         = new WordPressConfigurationRepository();
		$active_source      = new ActiveConfigurationSource( $repository );
		$config             = new Config_Repository( $active_source );
		$audit_table        = new AuditTable();
		$this->mcp_security_settings = new McpSecuritySettings();
		$this->mcp_access   = new McpAccessGuard( $this->mcp_security_settings, new CognitoJwtValidator(), $config, $audit_table );
		$this->providers    = new Ability_Provider_Registry();
		$this->localization = new Localization_Provider_Registry( $config );
		$targets            = new Target_Resolver( $config );
		$markup             = new Markup_Contract_Validator( $config );
		$this->patterns     = new Pattern_Repository( $config, $markup );
		$catalog            = new Block_Catalog( $config, $this->providers );
		$trees              = new Block_Tree_Service( $catalog, $config, $this->providers );
		$slots              = new Semantic_Slot_Materializer();
		$synced_patterns    = new Synced_Structural_Pattern_Service( $config );
		$language           = new Content_Language_Validator( $config );
		$validator          = new Page_Validator( $config, $catalog, $trees, $slots, $language, new StructureDocumentValidator(), $synced_patterns );
		$document_state     = new Managed_Document_State( $config );
		$this->structure_guard = new Structure_Contract_Save_Guard( $config, $validator, $document_state, $audit_table );
		$editor             = new Structure_Editor_Projector();
		$assembler          = new Pattern_Assembler( $config, $this->patterns, $slots, $editor, $synced_patterns );
		$this->admin_documents = new Admin_Managed_Document_Service( $config, $assembler, $validator, $targets, $document_state, $this->localization, $audit_table );
		$this->drafts       = new Draft_Service( $assembler, $validator, $targets, $trees, $config, $language, $this->localization, $document_state );
		$localized_drafts   = new Localized_Draft_Service( $config, $this->drafts, $this->localization );
		$audit              = new Audit_Logger( $audit_table );
		$this->proposals    = new Content_Proposal_Service( $config, $targets, $validator, $this->localization, $audit_table, $this->drafts, $document_state );
		$migrations         = new Blueprint_Migration_Service( $config, $validator, $document_state, $synced_patterns, $editor, $this->proposals, new StructureDocumentValidator() );
		$bulk_migrations    = new Bulk_Blueprint_Migration_Service( $config, $migrations, $this->localization );
		$query_loops        = new Query_Loop_Materializer( $config );
		$content_fields     = new Content_Field_Materializer( $config, $this->drafts, $language );
		$taxonomy_terms     = new Taxonomy_Term_Service( $config, $this->drafts, $language );
		$remote_media       = new Remote_Media_Ingestor( $config, $this->drafts );
		$publisher_media    = new Publisher_Media_Uploader( $config );
		$rendered_previews  = new Rendered_Preview_Service( $this->drafts );
		$this->publish_approvals = new PublishApprovalService( $this->drafts, $rendered_previews, $this->mcp_security_settings, new PublishApprovalTable(), $audit_table );
		$semantic_documents = new Semantic_Document_Service( $config, $this->drafts, $validator, $this->providers, $synced_patterns );
		$this->abilities    = new Abilities( $config, $this->drafts, $audit, $this->patterns, $this->providers, $query_loops, $content_fields, $taxonomy_terms, $slots, $remote_media, $publisher_media, $this->proposals, $this->localization, $localized_drafts, $rendered_previews, $semantic_documents, $migrations, $bulk_migrations, $this->mcp_access, $this->publish_approvals );
		$this->aliases      = new ExecutionAbilityAliases( $this->abilities );
		$this->previews     = new PreviewDraftService( $this->abilities );
		$this->mcp          = new ComposerMcpServer( $this->providers, $this->localization, $config, $this->mcp_access );
	}

	public function hooks(): void {
		$this->mcp_access->register_hooks();
		$this->admin_documents->register();
		add_filter( 'block_editor_settings_all', array( $this->structure_guard, 'filter_editor_settings' ), 10, 2 );
		add_action( 'init', array( $this->patterns, 'register_approved_patterns' ), 20 );
		add_action( 'wp_abilities_api_init', array( $this->abilities, 'register' ), 20 );
		add_action( 'wp_abilities_api_init', array( $this->aliases, 'register' ), 30 );
		add_action( 'wp_abilities_api_init', array( $this->previews, 'register_ability' ), 30 );
		add_action( 'wp_abilities_api_init', array( $this->providers, 'reset' ), 999 );
		add_action( 'wp_abilities_api_init', array( $this->localization, 'reset' ), 999 );
		add_action( 'mcp_adapter_init', array( $this->mcp, 'register' ) );
		add_action( 'rest_api_init', array( $this->structure_guard, 'register' ), 5 );
		add_action( 'post_updated', array( $this->drafts, 'rotate_revision' ), 10, 3 );
		add_action( PreviewDraftService::CLEANUP_HOOK, array( $this->previews, 'cleanup' ) );
	}

	public function proposals(): Content_Proposal_Service {
		return $this->proposals;
	}

	public function localization(): Localization_Provider_Registry {
		return $this->localization;
	}

	public function mcp_access(): McpAccessGuard {
		return $this->mcp_access;
	}

	public function mcp_security_settings(): McpSecuritySettings {
		return $this->mcp_security_settings;
	}

	public function publish_approvals(): PublishApprovalService {
		return $this->publish_approvals;
	}
}
