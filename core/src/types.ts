import type {
  CONTENT_PROPOSAL_STATES,
  ENTITY_TYPES,
  EXCERPT_POLICIES,
  LOCALIZATION_PROVIDER_OPERATIONS,
  PUBLISHED_UPDATE_POLICIES
} from "./constants.js";

export type EntityType = (typeof ENTITY_TYPES)[number];
export type ExcerptPolicy = (typeof EXCERPT_POLICIES)[number];
export type PublishedUpdatePolicy = (typeof PUBLISHED_UPDATE_POLICIES)[number];
export type ContentProposalState = (typeof CONTENT_PROPOSAL_STATES)[number];
export type LocalizationProviderOperation = (typeof LOCALIZATION_PROVIDER_OPERATIONS)[number];
export type Bcp47LanguageTag = string;
export type LanguageAllowlistEntry = Bcp47LanguageTag | "*";
export type IsoDateTime = string;
export type Uuid = string;
export type Sha256Checksum = `sha256:${string}`;
export type Sha256Hex = string;
export type ConfigSetState = "working" | "invalid" | "valid" | "active" | "archived";
export type ProviderOperation = "discover" | "materialize" | "validate" | "transform" | "preview";

export interface ComposerStatus {
  version: string;
  contract_version: string;
  active_config_set: string;
  entity_types: EntityType[];
  php_minimum: "8.1";
  multisite_scope: "per-site";
  execution_boundary: "agent-owned-drafts-only";
  published_content_writable: false;
  proposal_merge_human_only: true;
}

export interface ComposerEntity {
  id: string;
  type: EntityType;
  revision: number;
  payload: Record<string, unknown>;
}

export interface BlueprintPayload extends Record<string, unknown> {
  page_type: string;
  target_post_type?: string;
  target_template?: string;
  /** @deprecated Use target_post_type and target_template. */
  target?: { post_type?: string; template_mode?: string; template?: string };
  excerpt_policy: ExcerptPolicy;
  content_language?: Bcp47LanguageTag;
  content_language_enforcement?: "advisory" | "strict";
  content_language_exceptions?: string[];
  published_update_policy?: PublishedUpdatePolicy;
  allowed_content_languages?: LanguageAllowlistEntry[];
}

export interface NormalizedBlueprintPayload extends BlueprintPayload {
  target_post_type: string;
  published_update_policy: PublishedUpdatePolicy;
  content_language: Bcp47LanguageTag;
  content_language_enforcement: "advisory" | "strict";
  content_language_exceptions: string[];
  allowed_content_languages: LanguageAllowlistEntry[];
}

export interface ContentAccessPolicy extends Record<string, unknown> {
  discover?: boolean;
  read?: boolean;
  clone?: boolean;
  adopt_drafts?: boolean;
  propose_updates?: boolean;
}

export interface LocalizationPolicy extends Record<string, unknown> {
  provider: string;
  allowed_content_languages: LanguageAllowlistEntry[];
}

export interface SiteContractDesignPolicy extends Record<string, unknown> {
  content_language?: string;
  content_language_enforcement?: "advisory" | "strict";
  content_language_mismatch_signals?: string[];
  content_language_exceptions?: string[];
  localization?: LocalizationPolicy;
  content_access?: Record<string, ContentAccessPolicy>;
}

export interface LocalizationProviderManifestDeclaration {
  label: string;
  ability_namespace: string;
  contract_version: "1.0.0";
  plugin_version: string;
  storage_model: string;
  active: boolean;
  ability_names: string[];
  mcp_ability_names: string[];
}

export interface RegisteredLocalizationProviderManifest extends LocalizationProviderManifestDeclaration {
  id: string;
}

/** @deprecated Use RegisteredLocalizationProviderManifest for registry output. */
export type LocalizationProviderManifest = RegisteredLocalizationProviderManifest;

export interface LocalizationCapabilities {
  provider: string;
  active: boolean;
  storage_model: string;
  proposal_relationship_mode: string;
  creates_translations: boolean;
  links_agent_owned_drafts?: boolean;
  attaches_agent_owned_drafts_to_groups?: boolean;
}

export interface LocalizationLanguage {
  language_code: string;
  content_language: string;
  native_name: string;
  active: boolean;
}

export interface LocalizationLanguageList {
  provider: string;
  items: LocalizationLanguage[];
}

export interface SupportedContentLanguagesResult {
  authoring_mode: "any-language" | "provider-languages" | "allowlist";
  authorable_languages: LanguageAllowlistEntry[];
  localization_available: boolean;
  language_switching: boolean;
  draft_linking: boolean;
  draft_group_attachment: boolean;
  provider: string;
  languages: LocalizationLanguage[];
}

export interface ResolveLocalizedContentInput {
  post_id: number;
  post_type: string;
}

export interface ResolveLocalizedContentResult {
  language_code: string;
  content_language: Bcp47LanguageTag;
  locale: string;
  localization_group: string;
  element_type: string;
  source_language_code: string;
  translations: Record<string, number>;
}

export interface LocalizationContentContext extends ResolveLocalizedContentResult {
  provider: string;
}

export interface PreviewLocalizedProposalInput {
  proposal_post_id: number;
  language_code: string;
}

export interface PreviewLocalizedProposalResult {
  post_id: number;
  language_code: string;
  preview_url: string;
}

export interface ValidateLocalizedProposalInput {
  source_post_id: number;
  proposal_post_id: number;
  context: LocalizationContentContext;
}

export interface ValidateLocalizedProposalResult {
  valid: boolean;
  reason: string;
}

export interface AssignDraftLanguageInput {
  post_id: number;
  post_type: string;
  content_language: Bcp47LanguageTag;
}

export interface AssignDraftLanguageResult {
  provider: string;
  post_id: number;
  language_code: string;
  content_language: Bcp47LanguageTag;
  assigned: boolean;
}

export interface LocalizedDraftLinkItem {
  post_id: number;
  content_language: Bcp47LanguageTag;
  expected_modified_gmt: IsoDateTime;
  expected_revision: Uuid;
}

export interface LinkLocalizedDraftsInput {
  page_type: string;
  drafts: LocalizedDraftLinkItem[];
  confirm_link: true;
}

export interface LinkLocalizedDraftsResult {
  provider: string;
  page_type: string;
  post_type: string;
  translations: Record<string, number>;
  items: Array<{
    post_id: number;
    content_language: Bcp47LanguageTag;
    language_code: string;
  }>;
}

export interface AttachLocalizedDraftToGroupInput {
  page_type: string;
  anchor_post_id: number;
  expected_localization_group: string;
  draft: LocalizedDraftLinkItem;
  confirm_attach: true;
}

export interface AttachLocalizedDraftToGroupResult {
  provider: string;
  page_type: string;
  post_type: string;
  localization_group: string;
  translations: Record<string, number>;
  item: {
    post_id: number;
    content_language: Bcp47LanguageTag;
    language_code: string;
  };
  idempotent_replay: boolean;
}

export interface ContentProposalValidationIssue {
  code: string;
  message: string;
  path?: string;
  context?: Record<string, unknown>;
}

export interface ContentProposalValidationReport {
  valid: boolean;
  errors: ContentProposalValidationIssue[];
  warnings: ContentProposalValidationIssue[];
  statistics: {
    word_count: number;
    block_count: number;
    h1_count: number;
    patterns: string[];
    block_names: string[];
  };
  composition_mode: "document";
  content_language: Bcp47LanguageTag;
}

export interface ContentProposalSummary {
  proposal_id: number;
  post_id: number;
  source_post_id: number;
  title: string;
  page_type: string;
  post_type: string;
  state: ContentProposalState;
  modified_gmt: IsoDateTime;
  revision: Uuid;
  base_fingerprint: Sha256Checksum;
  current_source_fingerprint: Sha256Checksum | "";
  conflict: boolean;
  localization: LocalizationContentContext;
  edit_url: string;
  preview_url: string;
  source_edit_url: string;
  idempotent_replay?: true;
}

export interface ContentProposalDetail extends ContentProposalSummary {
  changes: string[];
  validation: ContentProposalValidationReport;
}

export type ContentProposal = ContentProposalSummary | ContentProposalDetail;
export type ContentProposalListState = ContentProposalState | "any";

export interface ContentProposalList {
  items: ContentProposalSummary[];
}

export interface CreateContentProposalInput {
  post_id: number;
  page_type: string;
  content_language: Bcp47LanguageTag;
  expected_modified_gmt: IsoDateTime;
  expected_content_hash: Sha256Hex;
  idempotency_key: string;
  confirm_proposal: true;
}

export interface SubmitContentProposalInput {
  post_id: number;
  expected_modified_gmt: IsoDateTime;
  expected_revision: Uuid;
}

/** Human REST-only input. Merge is deliberately not an agent Ability. */
export interface MergeContentProposalInput extends Omit<SubmitContentProposalInput, "post_id"> {
  confirmation: `merge:${number}:${number}`;
}

/** Human REST-only input. Reject is deliberately not an agent Ability. */
export interface RejectContentProposalInput extends Omit<SubmitContentProposalInput, "post_id"> {
  reason: string;
}

export interface ValidationIssue {
  code: string;
  message: string;
  path?: string;
  severity: "error" | "warning";
}

export interface ValidationReceipt {
  receipt: Sha256Checksum;
  config_set: string;
  config_hash: Sha256Checksum;
  site_id: number;
  user_id: number;
  issued_gmt: string;
  expires_gmt: string;
}

export interface PortableComposerEntity {
  id: string;
  type: EntityType;
  payload: Record<string, unknown>;
  checksum: Sha256Checksum;
}

export interface ProviderAbilityProfile {
  schema_version: "1.0.0-rc.1";
  provider: { id: string; name: string; version: string };
  ability: { name: string; label: string; description: string };
  composer: {
    provider_contract: string;
    component_roles: string[];
    operation: ProviderOperation;
    runtime_required: boolean;
    agent_draft_safe: boolean;
    capability?: string;
  };
}

export interface ThemeTemplateProfile {
  file: string;
  post_types: string[];
}

export interface ThemeSlotProfile {
  allows: string[];
  required?: boolean;
}

export interface ThemePresentationalManifest {
  schema_version: "1.0.0-rc.1";
  theme: { slug: string; version: string; adapter_version?: string };
  tokens: Record<string, string>;
  styles: Record<string, string>;
  templates: Record<string, ThemeTemplateProfile>;
  patterns: string[];
  slots: Record<string, ThemeSlotProfile>;
  components?: Record<string, ThemeComponentProfile>;
  compatibility?: {
    wordpress_minimum?: string;
    core_blocks_only?: boolean;
    frontend_javascript?: boolean;
  };
}

export interface ThemeComponentSlotProfile {
  class: string;
  type: "text" | "heading" | "action" | "media" | "items" | "navigation" | "metadata";
  required?: boolean;
  repeatable?: boolean;
}

export interface ThemeComponentProfile {
  patterns: string[];
  contexts?: string[];
  root_class: string;
  slots: Record<string, ThemeComponentSlotProfile>;
  requires_blocks: string[];
}

export interface ComposerConfigPackage {
  schema_version: "1.0.0-rc.1";
  package: { id: string; exported_gmt: string; source_site: string };
  activation: "working-set-only";
  entities: PortableComposerEntity[];
  checksums: Record<string, Sha256Checksum>;
}

export interface ComposerConfigBackup {
  schema_version: "1.0.0-rc.1";
  kind: "smartcloud-agent-composer-backup";
  activation: "working-set-only";
  created_gmt: string;
  source_site: string;
  active_config_set: string;
  packages: ComposerConfigPackage[];
  checksums: { packages: Sha256Checksum };
}
