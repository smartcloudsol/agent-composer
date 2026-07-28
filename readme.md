# SmartCloud Agent Composer

SmartCloud Agent Composer is the WordPress plugin layer of a governed, agent-assisted Gutenberg content-production workflow. Administrators define an active Config Set containing a site-wide design and safety contract plus page-type Blueprints. Authenticated agents can then create and revise validated WordPress drafts that fit the active theme's discovered or declared capabilities.

Composer does not publish content, delete ordinary content, edit themes or plugins, upload media, or expose a general-purpose WordPress administration API to an agent.

![WordPress](https://img.shields.io/badge/WordPress-%3E%3D6.9-21759b.svg)
![PHP](https://img.shields.io/badge/PHP-%3E%3D8.1-777bb4.svg)
![Node.js](https://img.shields.io/badge/Node.js-%3E%3D20-339933.svg)
![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)

## Documentation

- Product documentation: https://wpsuite.io/docs/
- Public TypeScript contracts: https://www.npmjs.com/package/@smart-cloud/agent-composer-core
- WordPress plugin source: https://github.com/smartcloudsol/agent-composer

## What Composer does

- Stores revision-capable Config Sets, one Site Contract per set, and page-type Blueprints.
- Discovers the active theme's templates, patterns, registered Gutenberg blocks, and optional presentation manifest.
- Validates complete Gutenberg block trees, approved patterns, post types, templates, excerpt policy, and provider requirements.
- Keeps the active Config Set immutable; changes are staged in an editable copy and applied as an explicit changeset.
- Requires validation before activation or archived-set restoration.
- Exposes governed WordPress Abilities through the WordPress MCP Adapter.
- Creates and updates only agent-owned drafts, with optimistic-concurrency checks.
- Uses existing Media Library images without granting media-upload or deletion capabilities.
- Creates short-lived preview drafts and removes only expired Composer-owned previews.
- Records redacted, tamper-evident audit events in a SHA-256 hash chain.
- Exports and restores checksum-protected configuration packages without secrets or site-specific audit history.

Composer works with standard block themes and can use a compatible subset of their capabilities. SmartCloud Agent Canvas provides a richer first-party design contract, but it is not required.

## Connecting an agent

Composer registers `/wp-json/mcp/smartcloud-agent-composer` after the separate WordPress MCP Adapter is installed and active. A compatible, authenticated MCP client can connect directly, or an OpenAI Connector tunnel can expose the same server to a compatible OpenAI client.

Use a dedicated WordPress user with the `smartcloud_agent` role. That role has governed draft-execution permissions and does not receive publishing, plugin-management, theme-management, user-management, media-upload, or unfiltered-HTML capabilities.

The basic flow is:

1. Install Composer and the WordPress MCP Adapter.
2. Create or import an editable Config Set.
3. Review Theme & providers, then configure the Site Contract and page-type Blueprints.
4. Apply staged changes, validate the complete set, and activate it explicitly.
5. Connect an authenticated MCP client as a dedicated `smartcloud_agent` user.
6. Load the Blueprint and design context, validate the proposed block plan, create a draft, and inspect its preview.

## Repository layout

- `smartcloud-agent-composer.php`: plugin bootstrap and release metadata.
- `src/`: PHP application, domain, WordPress integration, configuration, audit, MCP, and draft-execution code.
- `admin/src/`: shared React/Mantine administration shell and REST client.
- `admin/src/features/`: complete Config Set and Configuration & Blueprints administration workspaces, split out of the shared application shell.
- `admin/php/`: PHP bootstrap copied into the flattened plugin `admin/` directory.
- `admin/dist/`: compiled WordPress-ready JavaScript and CSS.
- `core/`: transport-free TypeScript interfaces and constants published as `@smart-cloud/agent-composer-core`.
- `tools/refresh-execution-manifest.mjs`: regenerates the SHA-256 manifest for the public Composer execution sources.
- `tests/`: PHP, source-contract, WordPress integration, multisite, migration, and uninstall tests.
- `docs/`: architecture and development notes.
- `uninstall.php`: multisite-aware cleanup for Composer-owned configuration, audit, role, cron, receipts, and temporary previews.

REST routes, capabilities, nonces, persistence, and authorization intentionally remain in PHP. The public core npm package contains interfaces and constants only.

## Admin source

Composer has one public feature set and no paid edition. The Config Set lifecycle workspace and the Configuration & Blueprints editor live in separate files under `admin/src/features/` so the application shell remains maintainable, but all shipped feature source is part of this repository.

## Prerequisites

- Node.js 20 or newer
- npm 10 or newer
- PHP 8.1 or newer
- WordPress 6.9 or newer for runtime and integration testing
- Git
- `zip` for release packaging

The WordPress admin bundle externalizes Mantine and uses shared WP Suite Hub assets at runtime. Building the admin source itself requires the npm development dependencies declared in `admin/package.json`; assembling the final WP Suite plugin ZIP additionally requires the sibling Hub workspaces described below.

## Install dependencies

In the product-family workspace, install all JavaScript dependencies from its root:

```bash
cd smartcloud-agent-ready-product-family
npm install
```

For the standalone public repository, install from its root. The root npm workspace links `core/`, `admin/`, and `tests/`, so the admin resolves the local contract package without a global link:

```bash
git clone https://github.com/smartcloudsol/agent-composer.git
cd agent-composer
npm install
```

The published `@smart-cloud/agent-composer-core` package remains available for external consumers; repository builds use the matching local workspace source.

From the standalone repository root, the main checks and profiles are:

```bash
npm run lint
npm run typecheck
npm test
npm run build
```

## Build the public core package

```bash
cd core
npm run lint
npm run typecheck
npm test
npm run build
```

The output is written to `core/dist/` as ESM, CommonJS, and TypeScript declarations. It contains no REST client, persistence, authorization, or WordPress application state.

## Build the admin

Run checks first:

```bash
cd admin
npm run lint
npm run typecheck
```

Build the Vite development artifact:

```bash
npm run build
```

Build the WordPress artifact, including `admin/php/` in `admin/dist/`:

```bash
npm run build:wp
```

The shared build environment still sets `WPSUITE_PREMIUM=true` for compatibility with other WP Suite projects. Composer does not branch on that value and has no separate commercial feature source.

The WordPress build produces:

```text
admin/dist/
  admin.php
  index.js
  index.asset.php
  index.css
  index-rtl.css
```

No Vite manifest is required by the packaged WordPress plugin.

## Run plugin checks

From `tests/`:

```bash
npm test
```

Lint every PHP source file from the project root:

```bash
find . -type f -name '*.php' -not -path '*/node_modules/*' -print0 \
  | xargs -0 -n1 php -l
```

The broader product-family workspace also provides shared contract tests, WordPress version/PHP version matrices, multisite and uninstall coverage, preset runtime tests, and the WP Suite theme migration test.

## Assemble the distributable WordPress plugin

The canonical WP Suite release assembler lives in the surrounding product-family workspace. It builds or consumes the module outputs, merges the shared Hub runtime, verifies required files and PHP syntax, normalizes timestamps, and writes a checksum manifest.

From `smartcloud-agent-ready-product-family/`:

```bash
# Rebuild every required shared project, then assemble all five plugins.
npm run plugins:build

# Or assemble from already verified build outputs.
npm run plugins:assemble
```

Composer's canonical outputs are:

```text
wpsuite-plugins/packages/smartcloud-agent-composer/
wpsuite-plugins/dist/smartcloud-agent-composer-<version>.zip
wpsuite-plugins/release-manifest.json
```

The assembler flattens these source inputs:

- `admin/dist/*` -> packaged `admin/*`
- `admin/php/*` -> packaged `admin/*`
- Composer runtime PHP, `readme.txt`, `LICENSE`, and `uninstall.php` -> plugin root

It also assembles `hub-for-wpsuiteio/` from the separate shared workspaces:

- `common/wpsuite-main/dist/*` -> `hub-for-wpsuiteio/`
- `common/wpsuite-admin/php/*` and `common/wpsuite-admin/dist/*` -> `hub-for-wpsuiteio/`
- `common/wpsuite-*-vendor/dist/*.js` -> `hub-for-wpsuiteio/assets/js/`
- `common/wpsuite-*-vendor/dist/*.css` -> `hub-for-wpsuiteio/assets/css/`

Do not hand-package a release directly from this source directory. The versioned ZIP recorded in `wpsuite-plugins/release-manifest.json` is the canonical development-server and release candidate artifact.

The workspace-only `presets/wpsuite/` migration fixture is not part of the public source repository or the distributable plugin. The two portable presets and the detected-theme starter are implemented by the public PHP preset services under `src/`.

## WordPress.org source-code requirement

WordPress.org requires the complete human-readable source for every compressed JavaScript or CSS file to be included in the plugin or linked from `readme.txt` at a public, maintained location. This repository is intended to provide the complete matching Composer source and build instructions for the distributed admin bundle.

Reference: https://developer.wordpress.org/plugins/wordpress-org/common-issues/#source-code

## External services

Composer's configuration, validation, audit, draft ownership, concurrency, pattern assembly, media lookup, and preview handling run inside WordPress. Depending on administrator configuration, the packaged shared Hub and provider plugins may optionally use:

- provider-owned WordPress Abilities and their separately disclosed services;
- WPSuite.io for optional workspace linking and shared Hub functions;
- Amazon Cognito for optional shared Hub authentication;
- Stripe for an optional shared Hub subscription or purchase flow.

Composer does not download executable PHP at runtime. See `readme.txt` for the complete service-by-service disclosure and privacy information.

## License

MIT. See `LICENSE`.

WordPress and Gutenberg are trademarks of the WordPress Foundation. SmartCloud Agent Composer is not affiliated with, sponsored by, or endorsed by the WordPress Foundation.
