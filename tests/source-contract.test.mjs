import assert from "node:assert/strict";
import crypto from "node:crypto";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";
import { fileURLToPath } from "node:url";

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const read = (relative) => fs.readFileSync(path.join(root, relative), "utf8");

test("WordPress identifiers use the approved Composer prefix and role", () => {
  const sources = [
    "smartcloud-agent-composer.php",
    "src/Infrastructure/WordPress/Activation.php",
    "src/Infrastructure/WordPress/EntityPostType.php"
  ].map(read).join("\n");
  assert.match(sources, /smartcloud_composer/);
  assert.match(sources, /smartcloud_agent/);
  assert.doesNotMatch(sources, /\bscac_/i);
  assert.doesNotMatch(sources, /wpsuite_agent'\s*;/);
  assert.ok("smartcloud_composer".length <= 20, "WordPress post type identifier exceeds 20 characters");
});

test("WP Suite preset contains every one of the 15 baseline page types", () => {
  const preset = JSON.parse(read("presets/wpsuite/page-types.json"));
  const ids = preset.blueprints.map((blueprint) => blueprint.id).sort();
  assert.equal(ids.length, 15);
  assert.deepEqual(ids, [
    "agency", "architecture", "case-study", "comparison", "deployment-access",
    "docs-shell", "page", "platform", "post", "product", "product-ai-kit",
    "product-flow", "product-gatey", "product-publisher", "solution"
  ]);
  assert.ok(preset.blueprints.every(({ excerpt }) => ["required", "optional", "disabled"].includes(excerpt)));
});

test("WordPress admin build exposes the complete public feature source and externalizes Mantine", () => {
  const rootPackage = JSON.parse(read("package.json"));
  const packageJson = JSON.parse(read("admin/package.json"));
  const webpack = read("admin/webpack.config.cjs");
  const app = read("admin/src/App.tsx");
  assert.deepEqual(rootPackage.workspaces, ["core", "admin", "tests"]);
  assert.match(rootPackage.scripts.build, /build:wp/);
  assert.match(packageJson.scripts["build:wp"], /WPSUITE_PREMIUM=true/);
  assert.match(packageJson.scripts["build:wp"], /--webpack-copy-php/);
  assert.match(app, /\.\/features/);
  assert.doesNotMatch(app, /function ConfigSetsPanel|function (?:EntityPanel|ConfigurationBlueprintsPanel)/);
  assert.match(read("admin/src/features/ConfigSetsPanel.tsx"), /export function ConfigSetsPanel/);
  assert.match(read("admin/src/features/ConfigurationBlueprintsPanel.tsx"), /export function ConfigurationBlueprintsPanel/);
  assert.match(webpack, /"@mantine\/core": "WpSuiteMantine"/);
  assert.match(read("admin/php/admin.php"), /add_submenu_page/);
  assert.doesNotMatch(read("admin/php/admin.php"), /add_menu_page/);
  assert.match(read("smartcloud-agent-composer.php"), /hub-loader\.php/);
});

test("public core has no REST transport or application store responsibility", () => {
  const coreSource = fs
    .readdirSync(path.join(root, "core", "src"))
    .filter((filename) => filename.endsWith(".ts"))
    .map((filename) => read(`core/src/${filename}`))
    .join("\n");
  assert.doesNotMatch(coreSource, /apiFetch|\bfetch\s*\(|restUrl|ComposerApiClient|createComposerStore/);
  assert.match(read("admin/src/api.ts"), /apiFetch/);
});

test("WordPress.org readme discloses every optional shared Hub service", () => {
  const readme = read("readme.txt");
  assert.match(readme, /== External Services ==/);
  for (const service of ["WP Suite platform connection", "Amazon Cognito", "Stripe", "Provider-owned WordPress Abilities"]) {
    assert.match(readme, new RegExp(service));
  }
});

test("uninstall cleanup is packaged and preserves ordinary content drafts", () => {
  const uninstall = read("uninstall.php");
  const assembler = read("../wpsuite-plugins/scripts/assemble.mjs");
  assert.match(assembler, /"uninstall\.php"/);
  assert.match(uninstall, /smartcloud_composer_audit/);
  assert.match(uninstall, /remove_role\( 'smartcloud_agent' \)/);
  assert.match(uninstall, /_smartcloud_composer_preview/);
  assert.doesNotMatch(uninstall, /post_type'\s*=>\s*array\(\s*'post',\s*'page'/);
});

test("Composer execution contract is checksum-pinned and canonical names are frozen", () => {
  const manifest = JSON.parse(read("src/Execution/execution-manifest.json"));
  assert.equal(manifest.contract, "smartcloud-agent-composer-execution");
  assert.equal(manifest.baseline, "0.6.8");
  for (const [filename, expected] of Object.entries(manifest.files)) {
    const digest = `sha256:${crypto.createHash("sha256").update(read(`src/Execution/${filename}`)).digest("hex")}`;
    assert.equal(digest, expected, `${filename} differs from its pinned execution contract`);
  }
  const surface = JSON.parse(read("tests/fixtures/execution-0.6.8-ability-surface.json"));
  const aliases = read("src/Integration/Abilities/ExecutionAbilityAliases.php");
  assert.equal(surface.operations.length, 18);
  for (const alias of surface.preferred_aliases) {
    assert.match(aliases, new RegExp(alias.replaceAll("-", "\\-")));
  }
});

test("configuration lifecycle is nonce and capability protected with conflict-safe entity writes", () => {
  const controller = read("src/Infrastructure/WordPress/ConfigurationController.php");
  const repository = read("src/Infrastructure/Persistence/WordPressConfigurationRepository.php");
  const manager = read("src/Application/Configuration/ConfigSetManager.php");
  const receipts = read("src/Application/Configuration/ValidationReceiptService.php");
  for (const route of ["config-sets", "clone", "entities", "validate", "activate", "rollback", "diff", "export", "imports", "backup", "audit", "discovery"]) {
    assert.match(controller, new RegExp(route));
  }
  assert.match(controller, /wp_verify_nonce/);
  assert.match(controller, /'args'\s*=>\s*array/);
  assert.match(controller, /'required'\s*=>\s*true/);
  assert.match(controller, /EntityType::all\(\)/);
  assert.match(repository, /ConfigurationConflict/);
  assert.match(repository, /if-match|expected_checksum/i);
  assert.match(controller, /DELETABLE/);
  assert.match(repository, /delete_working_entity/);
  assert.match(controller, /\/changes/);
  assert.match(manager, /START TRANSACTION/);
  assert.match(manager, /ROLLBACK/);
  assert.match(manager, /config-changeset-applied/);
  assert.match(manager, /lock_working_entity/);
  assert.match(manager, /null, false/);
  assert.match(repository, /FOR UPDATE/);
  for (const binding of ["config_hash", "site_id", "user_id", "expires_gmt"]) {
    assert.match(receipts, new RegExp(binding));
  }
});

test("release copy contains no internal milestone or retired theme-contract narrative", () => {
  const content = ["README.md", "CHANGELOG.md", "readme.txt", "admin/src/App.tsx", "src/Application/Execution/ExecutionRuntime.php", "src/Infrastructure/WordPress/StatusController.php"].map(read).join("\n");
  assert.doesNotMatch(content, /\bC[0-9]\b|premium build|community build|legacy theme contract|LegacyThemeContract/i);
  assert.doesNotMatch(read("readme.txt"), /development milestone|not yet (?:the )?final/i);
  assert.match(read("smartcloud-agent-composer.php"), /License:\s+MIT/);
  assert.match(read("../wpsuite-plugins/scripts/assemble.mjs"), /"LICENSE"/);
  assert.match(read("smartcloud-agent-composer.php"), /Version:\s+1\.0\.0/);
  assert.match(read("readme.txt"), /Stable tag:\s+1\.0\.0/);
});

test("complete configuration backups are checksummed, secret-free, inactive, and rollback-safe", () => {
  const backup = read("src/Application/Configuration/ConfigBackupService.php");
  const exporter = read("src/Application/Configuration/ConfigPackageExporter.php");
  const importer = read("src/Application/Configuration/ConfigPackageImporter.php");
  const admin = [read("admin/src/App.tsx"), read("admin/src/features/ConfigSetsPanel.tsx")].join("\n");
  assert.match(backup, /smartcloud-agent-composer-backup/);
  assert.match(backup, /CanonicalJson::checksum/);
  assert.match(backup, /array_reverse\( \$created \)/);
  assert.match(backup, /'active'\s*=>\s*false/);
  assert.match(exporter, /Configuration exports cannot contain secrets/);
  assert.match(importer, /Config packages cannot contain secrets/);
  assert.match(admin, /Export all configuration/);
  assert.match(admin, /site-specific audit chain is intentionally excluded/);
});

test("admin help lists are locally restored without loading global Mantine styles", () => {
  const sidebar = read("admin/src/DocSidebar.tsx");
  const css = read("admin/src/doc-sidebar.css");
  assert.match(sidebar, /wpsuite-doc-sidebar/);
  assert.match(css, /list-style:\s*disc/);
  assert.match(css, /list-style:\s*decimal/);
  assert.doesNotMatch(css, /^(?:ul|ol|li)\s*\{/m);
});

test("onboarding and contextual help explain the governed content-production role", () => {
  const onboarding = read("admin/src/Onboarding.tsx");
  const sidebar = read("admin/src/DocSidebar.tsx");
  for (const source of [onboarding, sidebar]) {
    assert.match(source, /WordPress plugin layer of a governed, agent-assisted content production solution/);
    assert.match(source, /active Config Set/);
    assert.match(source, /active theme's discovered or declared design capabilities/);
    assert.match(source, /validated Gutenberg drafts/);
  }
  assert.match(onboarding, /Site Contract/);
  assert.match(onboarding, /page-type Blueprints/);
  assert.match(sidebar, /Site Contract/);
  assert.match(sidebar, /page-type Blueprints/);
});

test("portable presets prefer an available agent-safe no-title template", () => {
  const presets = read("src/Application/Configuration/PresetService.php");
  assert.match(presets, /\$template\s*=\s*\$this->detected_template\(\s*\$discovery\['registered_templates'\]\s*\)/);
  assert.doesNotMatch(presets, /self::DETECTED\s*===\s*\$preset_id\s*\?\s*\$this->detected_template/);
  assert.match(presets, /array\(\s*'page-no-title',\s*'page'\s*\)/);
});

test("retired internal prototype names are absent from Composer source and public documentation", () => {
  const files = [
    "README.md", "CHANGELOG.md", "readme.txt", "smartcloud-agent-composer.php",
    "admin/src/App.tsx", "admin/src/DocSidebar.tsx", "src/Plugin.php",
    "src/Integration/Mcp/ComposerMcpServer.php", "src/Integration/Abilities/ExecutionAbilityAliases.php"
  ];
  const content = files.map(read).join("\n");
  assert.doesNotMatch(content, /SmartCloud Agent Bridge|smartcloud-agent-bridge|smartcloud-page-designer|smartcloud-agent\//i);
});
