import type { ENTITY_TYPES, EXCERPT_POLICIES } from "./constants";

export type EntityType = (typeof ENTITY_TYPES)[number];
export type ExcerptPolicy = (typeof EXCERPT_POLICIES)[number];
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
}

export interface ComposerEntity {
  id: string;
  type: EntityType;
  revision: number;
  payload: Record<string, unknown>;
}

export interface BlueprintPayload extends Record<string, unknown> {
  page_type: string;
  target?: { post_type?: string; template_mode?: string; template?: string };
  excerpt_policy: ExcerptPolicy;
}

export interface ValidationIssue {
  code: string;
  message: string;
  path?: string;
  severity: "error" | "warning";
}

export interface ValidationReceipt {
  receipt: `sha256:${string}`;
  config_set: string;
  config_hash: `sha256:${string}`;
  site_id: number;
  user_id: number;
  issued_gmt: string;
  expires_gmt: string;
}

export interface PortableComposerEntity {
  id: string;
  type: EntityType;
  payload: Record<string, unknown>;
  checksum: `sha256:${string}`;
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
  checksums: Record<string, `sha256:${string}`>;
}

export interface ComposerConfigBackup {
  schema_version: "1.0.0-rc.1";
  kind: "smartcloud-agent-composer-backup";
  activation: "working-set-only";
  created_gmt: string;
  source_site: string;
  active_config_set: string;
  packages: ComposerConfigPackage[];
  checksums: { packages: `sha256:${string}` };
}
