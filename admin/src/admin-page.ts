export const COMPOSER_ADMIN_PAGES = ["overview", "configuration", "blueprints", "proposals", "mcp-access", "providers", "audit"] as const;
export type ComposerAdminPage = typeof COMPOSER_ADMIN_PAGES[number];

export function resolveComposerAdminPage(search: string): ComposerAdminPage {
  const requested = new URLSearchParams(search).get("section");
  return COMPOSER_ADMIN_PAGES.find(page => page === requested) ?? "overview";
}
