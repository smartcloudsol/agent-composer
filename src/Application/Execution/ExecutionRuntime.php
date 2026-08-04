<?php

namespace SmartCloud\AgentComposer\Application\Execution;

use SmartCloud\AgentComposer\Application\Preview\PreviewDraftService;
use SmartCloud\AgentComposer\Execution\Abilities;
use SmartCloud\AgentComposer\Execution\Ability_Provider_Registry;
use SmartCloud\AgentComposer\Execution\Audit_Logger;
use SmartCloud\AgentComposer\Execution\Block_Catalog;
use SmartCloud\AgentComposer\Execution\Block_Tree_Service;
use SmartCloud\AgentComposer\Execution\Config_Repository;
use SmartCloud\AgentComposer\Execution\Content_Field_Materializer;
use SmartCloud\AgentComposer\Execution\Draft_Service;
use SmartCloud\AgentComposer\Execution\Markup_Contract_Validator;
use SmartCloud\AgentComposer\Execution\Page_Validator;
use SmartCloud\AgentComposer\Execution\Pattern_Assembler;
use SmartCloud\AgentComposer\Execution\Pattern_Repository;
use SmartCloud\AgentComposer\Execution\Query_Loop_Materializer;
use SmartCloud\AgentComposer\Execution\Remote_Media_Ingestor;
use SmartCloud\AgentComposer\Execution\Semantic_Slot_Materializer;
use SmartCloud\AgentComposer\Execution\Content_Language_Validator;
use SmartCloud\AgentComposer\Execution\Target_Resolver;
use SmartCloud\AgentComposer\Infrastructure\Persistence\ActiveConfigurationSource;
use SmartCloud\AgentComposer\Infrastructure\Persistence\AuditTable;
use SmartCloud\AgentComposer\Infrastructure\Persistence\WordPressConfigurationRepository;
use SmartCloud\AgentComposer\Integration\Abilities\ExecutionAbilityAliases;
use SmartCloud\AgentComposer\Integration\Mcp\ComposerMcpServer;

final class ExecutionRuntime {
	private readonly Ability_Provider_Registry $providers;
	private readonly Pattern_Repository $patterns;
	private readonly Draft_Service $drafts;
	private readonly Abilities $abilities;
	private readonly ExecutionAbilityAliases $aliases;
	private readonly PreviewDraftService $previews;
	private readonly ComposerMcpServer $mcp;

	public function __construct() {
		$repository         = new WordPressConfigurationRepository();
		$active_source      = new ActiveConfigurationSource( $repository );
		$config             = new Config_Repository( $active_source );
		$this->providers    = new Ability_Provider_Registry();
		$targets            = new Target_Resolver( $config );
		$markup             = new Markup_Contract_Validator( $config );
		$this->patterns     = new Pattern_Repository( $config, $markup );
		$catalog            = new Block_Catalog( $config, $this->providers );
		$trees              = new Block_Tree_Service( $catalog, $config, $this->providers );
		$slots              = new Semantic_Slot_Materializer();
		$language           = new Content_Language_Validator( $config );
		$validator          = new Page_Validator( $config, $catalog, $trees, $slots, $language );
		$assembler          = new Pattern_Assembler( $config, $this->patterns, $slots );
		$this->drafts       = new Draft_Service( $assembler, $validator, $targets, $trees, $config, $language );
		$audit_table        = new AuditTable();
		$audit              = new Audit_Logger( $audit_table );
		$query_loops        = new Query_Loop_Materializer( $config );
		$content_fields     = new Content_Field_Materializer( $config, $this->drafts, $language );
		$remote_media       = new Remote_Media_Ingestor( $config, $this->drafts );
		$this->abilities    = new Abilities( $config, $this->drafts, $audit, $this->patterns, $this->providers, $query_loops, $content_fields, $slots, $remote_media );
		$this->aliases      = new ExecutionAbilityAliases( $this->abilities );
		$this->previews     = new PreviewDraftService( $this->abilities );
		$this->mcp          = new ComposerMcpServer( $this->providers );
	}

	public function hooks(): void {
		add_action( 'init', array( $this->patterns, 'register_approved_patterns' ), 20 );
		add_action( 'wp_abilities_api_init', array( $this->abilities, 'register' ), 20 );
		add_action( 'wp_abilities_api_init', array( $this->aliases, 'register' ), 30 );
		add_action( 'wp_abilities_api_init', array( $this->previews, 'register_ability' ), 30 );
		add_action( 'wp_abilities_api_init', array( $this->providers, 'reset' ), 999 );
		add_action( 'mcp_adapter_init', array( $this->mcp, 'register' ) );
		add_action( 'post_updated', array( $this->drafts, 'rotate_revision' ), 10, 3 );
		add_action( PreviewDraftService::CLEANUP_HOOK, array( $this->previews, 'cleanup' ) );
	}
}
