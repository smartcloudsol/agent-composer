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

test("WordPress Plugin Checker conventions remain explicit", () => {
  const presets = read("src/Application/Configuration/PresetPatternRegistry.php");
  const persistence = read("src/Infrastructure/Persistence/WordPressConfigurationRepository.php");
  const execution = [
    read("src/Execution/Config_Repository.php"),
    read("src/Execution/Pattern_Repository.php")
  ].join("\n");
  assert.match(presets, /translators: %s: title of the active theme pattern/);
  assert.match(persistence, /WordPress\.Security\.EscapeOutput\.ExceptionNotEscaped/);
  assert.match(execution, /smartcloud_composer_design_policy/);
  assert.match(execution, /smartcloud_composer_pattern_preload_error/);
  assert.doesNotMatch(execution, /wpsuite_agent_composer_(?:design_policy|pattern_preload_error)/);
});

test("workspace-only WP Suite preset contains every one of the 26 current page types", {
  skip: !fs.existsSync(path.join(root, "presets/wpsuite/page-types.json"))
}, () => {
  const preset = JSON.parse(read("presets/wpsuite/page-types.json"));
  const ids = preset.blueprints.map((blueprint) => blueprint.id).sort();
  assert.equal(ids.length, 26);
  assert.deepEqual(ids, [
    "about", "agency", "agent-playground", "ai-agents", "architecture", "blog-index", "case-study", "comparison",
    "contact", "deployment-access", "docs-shell", "home", "page", "platform", "post", "pricing", "privacy-policy", "product",
    "product-agent-canvas", "product-agent-composer", "product-ai-kit", "product-flow", "product-gatey",
    "product-publisher", "solution", "terms-of-use"
  ]);
  assert.ok(preset.blueprints.every(({ excerpt }) => ["required", "optional", "disabled"].includes(excerpt)));
});

test("every WP Suite Blueprint enables every public content language", () => {
  const expected = ["en-US", "hu-HU", "de-DE", "es-ES", "fr-FR"];
  const site = JSON.parse(read("presets/wpsuite/site-contract.json"));
  const pageTypes = JSON.parse(read("presets/wpsuite/page-types.json"));
  const bundle = JSON.parse(read("presets/wpsuite/wpsuite-site-contract.package.json"));
  const bundledSite = bundle.entities.find((entity) => entity.type === "site-contract" && entity.id === "contract:site");
  const bundledConfigSet = bundle.entities.find((entity) => entity.type === "config-set");
  const bundledBlueprints = bundle.entities.filter((entity) => entity.type === "blueprint");
  const expectedIds = pageTypes.blueprints.map(({ id }) => id).sort();

  assert.deepEqual(site.design_policy.localization, {
    provider: "auto",
    allowed_content_languages: expected,
  });
  assert.deepEqual(bundledSite?.payload, site);
  assert.equal(
    `${bundledConfigSet?.payload.source_theme.slug}-${bundledConfigSet?.payload.source_theme.version}`,
    pageTypes.theme_baseline,
    "bundled Config Set theme identity must match the page-type baseline"
  );
  assert.deepEqual(bundledBlueprints.map(({ id }) => id).sort(), expectedIds);

  for (const id of expectedIds) {
    const blueprint = JSON.parse(read(`presets/wpsuite/blueprints/${id}.json`));
    const bundledBlueprint = bundledBlueprints.find((entity) => entity.id === id);
    assert.deepEqual(blueprint.allowed_content_languages, expected, `${id} source languages must match`);
    assert.deepEqual(bundledBlueprint?.payload.allowed_content_languages, expected, `${id} bundled languages must match`);
    assert.deepEqual(bundledBlueprint?.payload, blueprint, `${id} bundled payload must match its source`);
  }

  assert.equal(JSON.parse(read("presets/wpsuite/blueprints/docs-shell.json")).content_language, "en-US");
});

test("every proposal-enabled WP Suite Blueprint has its Site Contract update gate", () => {
  const site = JSON.parse(read("presets/wpsuite/site-contract.json"));
  const pageTypes = JSON.parse(read("presets/wpsuite/page-types.json"));
  for (const { id } of pageTypes.blueprints) {
    const blueprint = JSON.parse(read(`presets/wpsuite/blueprints/${id}.json`));
    if (blueprint.published_update_policy !== "proposal-only") continue;
    assert.equal(
      site.design_policy.content_access?.[blueprint.target_post_type]?.propose_updates,
      true,
      `${id} requires propose_updates for ${blueprint.target_post_type}`
    );
  }
});

test("homepage and product Blueprints allow governed published-content proposals", () => {
  const proposalBlueprints = [
    "home",
    "product",
    "product-agent-canvas",
    "product-agent-composer",
    "product-ai-kit",
    "product-flow",
    "product-gatey",
    "product-publisher",
  ];

  for (const id of proposalBlueprints) {
    const blueprint = JSON.parse(read(`presets/wpsuite/blueprints/${id}.json`));
    assert.equal(
      blueprint.published_update_policy,
      "proposal-only",
      `${id} must permit proposals for its published source content`
    );
  }

  const home = JSON.parse(read("presets/wpsuite/blueprints/home.json"));
  assert.ok(
    home.allowed_patterns.includes("wpsuite/home-playground-cta"),
    "the homepage must accept its existing Playground CTA pattern"
  );
});

test("WP Suite markup roles cover every specialized card grid", () => {
  const site = JSON.parse(read("presets/wpsuite/site-contract.json"));
  const cards = site.design_policy.markup_contract.role_classes.card;
  for (const className of [
    "wps-contact-card",
    "wps-playground-proof-card",
    "wps-price-card",
    "wps-info-card",
  ]) {
    assert.ok(cards.includes(className), `${className} must be an approved card role`);
  }
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
  const proposals = read("admin/src/features/ContentProposalsPanel.tsx");
  assert.match(proposals, /<Modal opened=\{mergeConfirmOpened\}/);
  assert.match(proposals, /<Modal opened=\{rejectOpened\}/);
  assert.match(proposals, /<Modal opened=\{returnOpened\}/);
  assert.match(proposals, /returnContentProposalForChanges/);
  assert.match(proposals, /Showing ready for review/);
  assert.match(proposals, /Show rejected/);
  assert.match(proposals, /Proposal ID/);
  assert.match(proposals, /<Pagination/);
  assert.match(proposals, /languageFlag/);
  assert.doesNotMatch(proposals, /window\.(?:confirm|prompt)/);
  assert.match(read("smartcloud-agent-composer.php"), /hub-loader\.php/);
  assert.match(read("admin/php/admin.php"), /smartcloud-wpsuite\//);
  const hubLoader = read("hub-loader.php");
  assert.match(hubLoader, /SMARTCLOUD_WPSUITE_RUNTIME_DIRECTORY/);
  assert.match(hubLoader, /SMARTCLOUD_WPSUITE_CANONICAL_SLUG/);
  assert.match(hubLoader, /SMARTCLOUD_WPSUITE_LEGACY_SLUG/);
  assert.match(hubLoader, /smartcloud-wpsuite/);
  assert.match(hubLoader, /hub-for-wpsuiteio/);
});

test("WP Suite table-heavy blueprints preserve passive responsive tables without enabling Custom HTML", () => {
  const blueprintIds = ["architecture", "comparison", "product", "product-ai-kit", "product-flow", "product-gatey", "product-publisher"];
  for (const id of blueprintIds) {
    const blueprint = JSON.parse(read(`presets/wpsuite/blueprints/${id}.json`));
    assert.ok(blueprint.allowed_blocks.includes("core/freeform"), `${id} must allow the constrained Text Editor block`);
    assert.ok(!blueprint.allowed_blocks.includes("core/html"), `${id} must keep Custom HTML forbidden`);
    assert.equal(blueprint.block_extensions.passive_text_editor_html, true);
    const contract = blueprint.content_contract.join("\n");
    assert.match(contract, /Preserve existing passive responsive comparison or data tables losslessly/);
    assert.match(contract, /reproduce the same inner markup as core\/freeform/);
    assert.match(contract, /Use core\/table for an ordinary newly authored table/);
  }
});

test("ordinary content lists distinguish drafts and hide proposals behind an explicit filter", () => {
  const adminList = read("src/Infrastructure/WordPress/ContentProposalAdminList.php");
  const plugin = read("src/Plugin.php");
  assert.match(plugin, /new ContentProposalAdminList\(\)/);
  assert.match(adminList, /display_post_states/);
  assert.match(adminList, /Composer new-content draft/);
  assert.match(adminList, /Update proposal - ready for review/);
  assert.match(adminList, /Show update proposals/);
  assert.match(adminList, /restrict_manage_posts/);
  assert.match(adminList, /Drafts total can include Composer update proposals/);
  assert.match(adminList, /'compare' => 'NOT EXISTS'/);
});

test("guided admin exposes the existing-content access gate without requiring JSON editing", () => {
  const editor = read("admin/src/EntityEditor.tsx");
  const discovery = read("src/Application/Configuration/SiteDiscoveryService.php");
  const docs = read("admin/src/DocSidebar.tsx");
  assert.match(editor, /Composer content access/);
  for (const label of ["Discover in lists", "Read content", "Clone to Composer draft", "Adopt editable drafts"]) {
    assert.match(editor, new RegExp(label));
  }
  assert.match(editor, /post_type_contract/);
  assert.match(editor, /content_access/);
  assert.match(editor, /content_field_access/);
  assert.match(editor, /Composer field access/);
  assert.match(editor, /content_taxonomy_access/);
  assert.match(editor, /Composer taxonomy access/);
  for (const label of ["Search terms", "Assign to draft", "Create terms", "Maximum terms", "Assignment mode", "Creation parent policy", "Allowed parent slugs"]) {
    assert.match(editor, new RegExp(label));
  }
  assert.match(editor, /Remote Media Library ingestion/);
  assert.match(editor, /remote_media_ingest/);
  assert.match(editor, /Write draft/);
  for (const label of ["Select all Read", "Deselect all Read", "Select all Write draft", "Deselect all Write draft"]) {
    assert.match(editor, new RegExp(label));
  }
  assert.match(editor, /setAllFieldRules/);
  assert.match(editor, /Add a Blueprint targeting this post type first/);
  assert.match(editor, /updateTemplate/);
  assert.match(editor, /update\(\["target_template"\]/);
  assert.doesNotMatch(editor, /update\(\["target_template", "file"\]/);
  assert.match(discovery, /registered_post_types/);
  assert.match(discovery, /supports_editor/);
  assert.match(discovery, /current_user_can_edit/);
  assert.match(discovery, /registered_meta/);
  assert.match(discovery, /registered_taxonomies/);
  assert.match(discovery, /current_user_can_assign/);
  assert.match(discovery, /current_user_can_create/);
  assert.match(docs, /Composer content access is managed in the guided Site Contract editor/);
  assert.match(docs, /Composer taxonomy access governs public terms/);
});

test("admin checkboxes suppress the WordPress duplicate checkmark and expose pointer cursors", () => {
  const css = read("admin/src/admin.css");
  assert.match(css, /input\[type="checkbox"\]:checked::before/);
  assert.match(css, /content:\s*none\s*!important/);
	assert.match(css, /visibility:\s*hidden\s*!important/);
  assert.match(css, /\.mantine-Checkbox-body:not\(\[data-disabled\]\)/);
  assert.match(css, /cursor:\s*pointer/);
});

test("guided long-list textareas preserve in-progress spaces and new lines", () => {
  const editor = read("admin/src/EntityEditor.tsx");
  assert.match(editor, /onChange=\{\(event\) => change\(event\.currentTarget\.value\.split\("\\n"\)\)\}/);
  assert.match(editor, /onBlur=\{\(event\) => change\(normalizeLongList\(event\.currentTarget\.value\)\)\}/);
  assert.doesNotMatch(editor, /onChange=\{[^\n]+\.trim\(\)[^\n]+\.filter\(Boolean\)/);
});

test("enabled switch controls expose a pointer cursor on their visible track", () => {
  const css = read("admin/src/admin.css");
  assert.match(css, /\.mantine-Switch-input:not\(:disabled\):not\(\[readonly\]\) \+ \.mantine-Switch-track/);
  assert.match(css, /\.mantine-Switch-input:disabled \+ \.mantine-Switch-track/);
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
  assert.equal(fs.existsSync(path.join(root, "uninstall.php")), true);
  assert.match(uninstall, /smartcloud_composer_audit/);
  assert.match(uninstall, /remove_role\( 'smartcloud_agent' \)/);
  assert.match(uninstall, /_smartcloud_composer_preview/);
  assert.doesNotMatch(uninstall, /post_type'\s*=>\s*array\(\s*'post',\s*'page'/);
});

test("Composer execution contract is checksum-pinned and canonical names are frozen", () => {
  const manifest = JSON.parse(read("src/Execution/execution-manifest.json"));
  assert.equal(manifest.contract, "smartcloud-agent-composer-execution");
  for (const [filename, expected] of Object.entries(manifest.files)) {
    const digest = `sha256:${crypto.createHash("sha256").update(read(`src/Execution/${filename}`)).digest("hex")}`;
    assert.equal(digest, expected, `${filename} differs from its pinned execution contract`);
  }
  const surface = JSON.parse(read("tests/fixtures/execution-ability-surface.json"));
  assert.equal(surface.contract, manifest.contract);
  const aliases = read("src/Integration/Abilities/ExecutionAbilityAliases.php");
  assert.equal(surface.operations.length, 41);
  for (const alias of surface.preferred_aliases) {
    assert.match(aliases, new RegExp(alias.replaceAll("-", "\\-")));
  }
});

test("rendered preview exposes a bounded data tool and an MCP Apps UI resource", () => {
  const abilities = read("src/Execution/Abilities.php");
  const server = read("src/Integration/Mcp/ComposerMcpServer.php");
  const renderer = read("src/Execution/Rendered_Preview_Service.php");
  assert.match(abilities, /get-rendered-preview/);
  assert.match(abilities, /get-rendered-preview-asset/);
  assert.match(abilities, /text\/html;profile=mcp-app/);
  assert.match(abilities, /'ui'\s*=>\s*array\(\s*'resourceUri'/);
  assert.match(abilities, /'openai\/outputTemplate'\s*=>\s*ComposerMcpServer::PREVIEW_RESOURCE_URI/);
  assert.match(abilities, /'resourceUri'\s*=>\s*ComposerMcpServer::PREVIEW_RESOURCE_URI/);
  assert.match(server, /rendered-preview\/v3\.html/);
  assert.match(server, /rendered-preview\/v2\.html/);
  assert.match(server, /rendered-preview\/v1\.html/);
  assert.match(abilities, /ui\/initialize/);
  assert.match(abilities, /ui\/notifications\/initialized/);
  assert.match(abilities, /window\.openai\?\.toolOutput/);
  assert.match(abilities, /window\.openai\?\.callTool/);
  assert.match(abilities, /attachShadow/);
  assert.match(abilities, /openai\/widgetAccessible/);
  const resourceRegistration = abilities.slice(
    abilities.indexOf("private function register_rendered_preview_resource"),
    abilities.indexOf("public function rendered_preview_resource")
  );
  assert.doesNotMatch(resourceRegistration, /input_schema/);
  assert.match(server, /\$resources/);
  assert.match(renderer, /do_blocks/);
  assert.match(renderer, /wp_kses_post/);
  assert.match(renderer, /MAX_HTML_BYTES\s*=\s*500000/);
  assert.match(renderer, /smartcloud_composer_rendered_preview_stylesheets/);
  assert.match(renderer, /prepare_stylesheet_css/);
  assert.match(renderer, /MAX_IMPORT_DEPTH\s*=\s*4/);
  assert.match(renderer, /MAX_IMPORTED_STYLESHEETS\s*=\s*32/);
  assert.match(renderer, /smartcloud-preview-asset:\/\//);
  assert.match(renderer, /'woff'\s*=>\s*'font\/woff'/);
  assert.match(renderer, /'woff2'\s*=>\s*'font\/woff2'/);
  assert.match(renderer, /stylesheet_escaped_identifier_neutralized/);
  assert.match(abilities, /asset\.kind==='font'/);
  assert.match(abilities, /audio,video,source,track,picture/);
  assert.match(renderer, /data-smartcloud-preview-asset/);
  assert.match(renderer, /get_owned_draft_for_preview_asset/);
  assert.match(renderer, /get_preview_for_preview_asset/);
  assert.match(renderer, /build_document\(\s*\$post,\s*\$preview_start,\s*\$language\s*\?:\s*'und',\s*false\s*\)/);
  const drafts = read("src/Execution/Draft_Service.php");
  assert.match(drafts, /'ready-for-review',\s*'merged',\s*'rejected',\s*'superseded'/);
  assert.match(drafts, /if\s*\(\s*!\s*\$preview_asset_read\s*\)/);
  assert.doesNotMatch(renderer, /\$assets\[ \$url \]/);
  assert.doesNotMatch(renderer, /do_shortcode|apply_filters\(\s*['"]the_content/);
});

test("relation discovery is unambiguous and editable-content totals are post-filtered", () => {
  const abilities = read("src/Execution/Abilities.php");
  const aliases = read("src/Integration/Abilities/ExecutionAbilityAliases.php");
  const fields = read("src/Execution/Content_Field_Materializer.php");
  const drafts = read("src/Execution/Draft_Service.php");
  assert.match(abilities, /only Composer ability intended for relation-target ID lookup/);
  assert.match(abilities, /Never use this ability to resolve relation target IDs/);
  assert.match(abilities, /relation_target_search_output_schema/);
  assert.match(aliases, /Never use this ability to resolve relation target IDs/);
  assert.match(aliases, /draft_list_output_schema/);
  assert.match(fields, /lookup_required_before_write/);
  assert.match(fields, /never_use_for_lookup/);
  assert.match(fields, /'result_id_path'\s*=>\s*'matches\[\]\.id'/);
  assert.match(drafts, /'purpose'\s*=>\s*'editable-content-discovery'/);
  assert.match(drafts, /'total'\s*=>\s*\$visible_total/);
  assert.match(drafts, /'has_more'\s*=>\s*\$offset \+ count\( \$items \) < \$visible_total/);
  assert.doesNotMatch(drafts, /'total'\s*=>\s*\(int\) \$query->found_posts/);
});

test("draft ability schemas require the effective Blueprint language", () => {
  const abilities = read("src/Execution/Abilities.php");
  const aliases = read("src/Integration/Abilities/ExecutionAbilityAliases.php");
  assert.match(abilities, /'content_language'\s*=>\s*\$this->string_property/);
  assert.match(abilities, /\$required\s*=\s*array\(\s*'page_type',\s*'content_language'/);
  assert.match(aliases, /'candidate-create'\s*=>\s*\$this->abilities->candidate_schema\( true \)/);
  assert.match(aliases, /'candidate-update'\s*=>\s*\$this->abilities->candidate_schema\( false \)/);
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
  const content = ["readme.md", "CHANGELOG.md", "readme.txt", "admin/src/App.tsx", "src/Application/Execution/ExecutionRuntime.php", "src/Infrastructure/WordPress/StatusController.php"].map(read).join("\n");
  assert.doesNotMatch(content, /\bC[0-9]\b|premium build|community build|legacy theme contract|LegacyThemeContract/i);
  assert.doesNotMatch(read("readme.txt"), /development milestone|not yet (?:the )?final/i);
  assert.match(read("smartcloud-agent-composer.php"), /License:\s+MIT/);
  assert.equal(fs.existsSync(path.join(root, "LICENSE")), true);
  assert.match(read("smartcloud-agent-composer.php"), /Version:\s+1\.2\.4/);
  assert.match(read("readme.txt"), /Stable tag:\s+1\.2\.4/);
});

test("localization selection is manifest-driven and the main runtime names no concrete provider", () => {
  const editor = read("admin/src/EntityEditor.tsx");
  const runtime = read("src/Application/Execution/ExecutionRuntime.php");
  const status = read("src/Infrastructure/WordPress/StatusController.php");
  assert.match(editor, /localizationProviders\.map/);
  assert.doesNotMatch(editor, /value:\s*"wpml"|value:\s*"polylang"/i);
  assert.doesNotMatch(runtime, /Wpml|Polylang/i);
  assert.match(status, /localization_providers/);
  assert.equal(fs.existsSync(path.join(root, "src", "Integration", "Localization", "WpmlLocalizationProvider.php")), false);
});

test("draft idempotency locks support MySQL and SQLite without weakening ownership", () => {
  const drafts = read("src/Execution/Draft_Service.php");
  assert.match(drafts, /SELECT GET_LOCK/);
  assert.match(drafts, /SELECT RELEASE_LOCK/);
  assert.match(drafts, /uses_sqlite_database/);
  assert.match(drafts, /add_option\( \$option_name, \$value, '', false \)/);
  assert.match(drafts, /expires_at/);
  assert.match(drafts, /option_name = %s AND option_value = %s/);
  assert.match(drafts, /wp_cache_delete\( \$lock\['name'\], 'options' \)/);
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
    "readme.md", "CHANGELOG.md", "readme.txt", "smartcloud-agent-composer.php",
    "admin/src/App.tsx", "admin/src/DocSidebar.tsx", "src/Plugin.php",
    "src/Integration/Mcp/ComposerMcpServer.php", "src/Integration/Abilities/ExecutionAbilityAliases.php"
  ];
  const content = files.map(read).join("\n");
  assert.doesNotMatch(content, /SmartCloud Agent Bridge|smartcloud-agent-bridge|smartcloud-page-designer|smartcloud-agent\//i);
});
