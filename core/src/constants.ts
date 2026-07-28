export const CONTRACT_VERSION = "1.0.0-rc.1" as const;

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
