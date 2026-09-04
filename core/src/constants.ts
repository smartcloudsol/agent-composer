export const CONTRACT_VERSION = "1.0.0-rc.1" as const;

export const LOCALIZATION_PROVIDER_CONTRACT_VERSION = "1.0.0" as const;

export const ENTITY_TYPES = [
  "config-set",
  "site-contract",
  "component",
  "style-mapping",
  "blueprint",
  "provider-policy",
  "discovery"
] as const;

export const EXCERPT_POLICIES = ["required", "optional", "disabled"] as const;

export const PUBLISHED_UPDATE_POLICIES = ["disabled", "proposal-only"] as const;

export const CONTENT_PROPOSAL_STATES = [
  "working",
  "ready-for-review",
  "merged",
  "rejected",
  "superseded"
] as const;

export const LOCALIZATION_PROVIDER_OPERATIONS = [
  "get-localization-capabilities",
  "list-content-languages",
  "resolve-localized-content",
  "preview-localized-proposal",
  "validate-localized-proposal",
  "assign-draft-language",
  "link-draft-translations",
  "attach-draft-to-translation-group"
] as const;
