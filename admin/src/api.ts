import type { ComposerStatus } from "@smart-cloud/agent-composer-core";
import apiFetch from "@wordpress/api-fetch";

export interface ComposerRuntimeStatus extends ComposerStatus {
  mcp_endpoint: string;
}

export interface ConfigEntity {
  id: number;
  key: string;
  type: string;
  config_set: string;
  label: string;
  payload: Record<string, unknown>;
  entity_revision: number;
  modified_gmt: string;
  modified_by: number;
  content_hash: string;
  active: boolean;
}

export interface ConfigSet extends ConfigEntity {
  lifecycle: "working" | "invalid" | "valid" | "active" | "archived";
  entity_count?: number;
  config_hash: string;
  entities?: ConfigEntity[];
}

export interface ConfigSetList {
  items: ConfigSet[];
  active: string;
}

export interface ValidationIssue {
  code: string;
  message: string;
  path: string;
}

export interface ValidationReport {
  valid: boolean;
  config_set: string;
  config_hash: string;
  entity_count: number;
  page_type_count: number;
  provider_count: number;
  errors: ValidationIssue[];
  warnings: ValidationIssue[];
  receipt: string;
  expires_gmt: string;
}

export interface ConfigDiff {
  from: string;
  to: string;
  summary: { added: number; removed: number; changed: number };
  added: ConfigEntity[];
  removed: ConfigEntity[];
  changed: Array<{ identity: string; before: ConfigEntity; after: ConfigEntity }>;
}

export interface ProviderDiscovery {
  generated_gmt: string;
  theme: {
    name: string;
    stylesheet: string;
    template: string;
    version: string;
    parent_name: string;
    parent_version: string;
    manifest_status: string;
    manifest: Record<string, unknown>;
  };
  providers: Array<{
    id: string;
    label: string;
    plugin_version: string;
    contract_version: string;
    ability_names: string[];
    block_namespaces: string[];
    runtime: Record<string, unknown> | null;
  }>;
  registered_blocks: Array<{
    name: string;
    title: string;
    category: string;
    namespace: string;
    provider: string | null;
  }>;
  registered_patterns: Array<{
    name: string;
    title: string;
    categories: string[];
    block_types: string[];
    source: string;
  }>;
  registered_templates: Array<{
    slug: string;
    title: string;
    source: string;
    theme: string;
  }>;
  provider_profiles: number;
  theme_fingerprint: string;
  provider_fingerprint: string;
  site_capability_fingerprint: string;
}

export interface ComposerPreset {
  id: "universal-gutenberg" | "smartcloud-recommended" | "detected-theme-starter";
  label: string;
  description: string;
  kind: "built-in" | "generated";
  version: string;
  available: boolean;
  availability_reason?: string;
  theme?: { name: string; version: string; fingerprint: string };
  pattern?: { name: string; title: string; block_names: string[] } | null;
}

export interface AuditEvent {
  id: number;
  event_uuid: string;
  created_gmt: string;
  actor_user_id: number;
  event_type: string;
  outcome: string;
  context: Record<string, unknown>;
  event_hash: string;
}

const root = "/smartcloud-agent-composer/v1";

export const loadComposerStatus = (): Promise<ComposerRuntimeStatus> =>
  apiFetch<ComposerRuntimeStatus>({ path: `${root}/status` });

export const listConfigSets = (): Promise<ConfigSetList> =>
  apiFetch<ConfigSetList>({ path: `${root}/config-sets` });

export const getConfigSet = (id: string): Promise<ConfigSet> =>
  apiFetch<ConfigSet>({ path: `${root}/config-sets/${encodeURIComponent(id)}` });

export const createConfigSet = (label: string): Promise<ConfigSet> =>
  apiFetch<ConfigSet>({ path: `${root}/config-sets`, method: "POST", data: { label } });

export const cloneConfigSet = (id: string, label: string): Promise<ConfigSet> =>
  apiFetch<ConfigSet>({ path: `${root}/config-sets/${encodeURIComponent(id)}/clone`, method: "POST", data: { label } });

export const createEntity = (setId: string, type: string, key: string, payload: Record<string, unknown>): Promise<ConfigEntity> =>
  apiFetch<ConfigEntity>({ path: `${root}/config-sets/${encodeURIComponent(setId)}/entities`, method: "POST", data: { type, key, payload } });

export const updateEntity = (entity: ConfigEntity, payload: Record<string, unknown>): Promise<ConfigEntity> =>
  apiFetch<ConfigEntity>({
    path: `${root}/config-sets/${encodeURIComponent(entity.config_set)}/entities/${encodeURIComponent(entity.key)}?type=${encodeURIComponent(entity.type)}`,
    method: "PATCH",
    headers: { "If-Match": entity.content_hash },
    data: { entity_revision: entity.entity_revision, payload }
  });

export const deleteEntity = (entity: ConfigEntity): Promise<{ deleted: true; config_set: string; entity_type: string; entity_key: string }> =>
  apiFetch({
    path: `${root}/config-sets/${encodeURIComponent(entity.config_set)}/entities/${encodeURIComponent(entity.key)}?type=${encodeURIComponent(entity.type)}&entity_revision=${entity.entity_revision}`,
    method: "DELETE",
    headers: { "If-Match": entity.content_hash }
  });

export type EntityChange =
  | { action: "create"; type: string; key: string; payload: Record<string, unknown> }
  | { action: "update"; type: string; key: string; payload: Record<string, unknown>; entity_revision: number; content_hash: string }
  | { action: "delete"; type: string; key: string; entity_revision: number; content_hash: string };

export const applyEntityChanges = (setId: string, changes: EntityChange[]): Promise<{ config_set: ConfigSet; summary: { created: number; updated: number; deleted: number } }> =>
  apiFetch({ path: `${root}/config-sets/${encodeURIComponent(setId)}/changes`, method: "POST", data: { changes } });

export const validateConfigSet = (id: string): Promise<ValidationReport> =>
  apiFetch<ValidationReport>({ path: `${root}/config-sets/${encodeURIComponent(id)}/validate`, method: "POST" });

export const activateConfigSet = (id: string): Promise<{ active: string; previous: string; config_hash: string }> =>
  apiFetch({ path: `${root}/config-sets/${encodeURIComponent(id)}/activate`, method: "POST" });

export const rollbackConfigSet = (id: string): Promise<{ active: string; previous: string; config_hash: string }> =>
  apiFetch({ path: `${root}/config-sets/${encodeURIComponent(id)}/rollback`, method: "POST" });

export const diffConfigSet = (id: string, to: string): Promise<ConfigDiff> =>
  apiFetch<ConfigDiff>({ path: `${root}/config-sets/${encodeURIComponent(id)}/diff?to=${encodeURIComponent(to)}` });

export const exportConfigSet = (id: string): Promise<Record<string, unknown>> =>
  apiFetch({ path: `${root}/config-sets/${encodeURIComponent(id)}/export`, method: "POST" });

export const exportConfigBackup = (): Promise<Record<string, unknown>> =>
  apiFetch({ path: `${root}/backup`, method: "POST" });

export type ConfigImportResult =
  | { config_set: string; active: false }
  | { config_sets: string[]; active: false; source_active_config_set: string };

export const importConfigPackage = (value: Record<string, unknown>): Promise<ConfigImportResult> =>
  apiFetch({ path: `${root}/imports`, method: "POST", data: value });

export const loadAudit = (): Promise<{ items: AuditEvent[] }> =>
  apiFetch({ path: `${root}/audit?limit=100` });

export const loadDiscovery = (): Promise<ProviderDiscovery> =>
  apiFetch({ path: `${root}/discovery` });

export const runDiscovery = (): Promise<ProviderDiscovery> =>
  apiFetch({ path: `${root}/discovery`, method: "POST" });

export const listPresets = (): Promise<{ items: ComposerPreset[] }> =>
  apiFetch({ path: `${root}/presets` });

export const instantiatePreset = (id: ComposerPreset["id"], label: string): Promise<{ preset: string; config_set: ConfigSet; active: false }> =>
  apiFetch({ path: `${root}/presets/${encodeURIComponent(id)}/instantiate`, method: "POST", data: { label } });
