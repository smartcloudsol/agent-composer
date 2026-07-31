=== SmartCloud Agent Composer ===
Contributors: smartcloud
Tags: agents, gutenberg, automation, workflow, abilities
Requires at least: 6.9
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 1.0.0
License: MIT
License URI: https://mit-license.org/

Governed configuration, validation, and draft-only execution for agent-ready Gutenberg sites.

== Description ==

SmartCloud Agent Composer provides a controlled WordPress-native layer for agent-assisted Gutenberg content workflows. It manages versioned site contracts and page blueprints, discovers provider-owned WordPress Abilities, validates content plans, and permits changes only through audited draft operations.

Composer includes its own checksum-pinned execution contract, configuration storage, capabilities, audit log, and MCP endpoint. It does not require another agent workflow plugin.

**What the plugin does:** An administrator defines and activates a Config Set that describes the current site's content types, theme-dependent patterns and templates, permitted Gutenberg blocks, and safety policies. Composer then exposes only governed draft operations. It never gives an agent a general-purpose WordPress administration interface.

**How an agent reaches it:** Composer registers an MCP server at `/wp-json/mcp/smartcloud-agent-composer` when the WordPress MCP Adapter is installed and active. An authenticated MCP client can connect to that endpoint directly, or an OpenAI Connector tunnel can make the same server available to a compatible OpenAI client. The tunnel is optional and is not bundled with Composer.

**How to try it without a Connector tunnel:** Install and activate the WordPress MCP Adapter, create a dedicated WordPress user with the `smartcloud_agent` role, create or import a Config Set in **SmartCloud -> Agent Composer**, review **Theme & providers**, validate and activate the set, then configure an MCP client to authenticate to the WordPress MCP endpoint as that dedicated user. Composer reports transport readiness in its status Ability and keeps all content changes as agent-owned drafts for human preview.

Composer is part of the WP Suite product family by Smart Cloud Solutions, Inc. It keeps WordPress and Gutenberg as the authoring system. Core Composer operation does not require a WP Suite account, subscription, hosted service, or external API. The packaged shared WP Suite Hub can optionally connect the site to WPSuite.io, Amazon Cognito, and Stripe-backed subscription flows.

**Key features**

* **Draft-only execution boundary** - Composer cannot publish or delete normal site content through its agent abilities.
* **Versioned configuration** - Store config sets, site contracts, blueprints, components, style mappings, provider policies, and discovery results as revision-capable WordPress entities.
* **Blueprint-specific excerpts** - Require an 80-to-300-character excerpt, allow it to remain empty, or disable it independently for each page type.
* **Explicit activation** - Imported configuration remains an inactive, editable Config Set until it has been validated and explicitly activated.
* **Safe starting presets** - Copy Universal Gutenberg, SmartCloud Recommended, or a generated Detected Theme Starter into a new inactive, editable Config Set without importing unrelated site-specific contracts.
* **Explicit configuration authority** - Runtime execution uses only the explicitly activated Composer config set; theme capabilities are discovered and validated instead of silently importing configuration from theme files.
* **Gutenberg AST validation** - Validate canonical block trees, registered blocks, approved patterns, saved markup, target post types, templates, and blueprint constraints.
* **Document and structured-record modes** - Assemble governed Gutenberg documents or keep registry-style CPT bodies empty while writing only explicitly approved registered fields.
* **Strict content language** - Require an exact BCP 47 language contract, remove or reject untranslated pattern fallbacks, and allow reviewed brand or technical exceptions.
* **Semantic theme slots** - Materialize theme-declared text, heading, action, and media slots without exposing raw placeholders in patterns inserted manually by editors.
* **Safe draft ownership** - Preserve WordPress authorship, track agent assignment separately, and require optimistic-concurrency tokens for updates and adoption.
* **Provider delegation** - Discover provider-owned Abilities without copying or republishing the provider's business operations.
* **Existing-media materialization** - Search existing image attachments and produce validated core Image block structures without uploading or deleting media.
* **Short-lived previews** - Build real preview drafts and schedule automatic cleanup of Composer-owned temporary previews.
* **Tamper-evident audit history** - Write redacted SQL audit events into an append-only SHA-256 hash chain.
* **Complete config lifecycle** - Create, clone, edit, diff, validate, activate, roll back, export, and import config sets through capability-controlled REST operations.
* **Uninstall-safe portability** - Export every config set into one checksum-protected JSON backup and restore the complete bundle later as inactive, editable sets.
* **Conflict-safe editing** - Require entity revision and `If-Match` checksum tokens so concurrent admin changes return a structured conflict instead of being overwritten.
* **Bound validation receipts** - Bind activation to the exact config checksum, WordPress site, administrator, and expiration time, with a mandatory fresh validation at activation or rollback.
* **Multisite-aware foundation** - Configuration is maintained per site; network-wide preset distribution is not performed.
* **Guided administration** - Composer lives under the shared SmartCloud menu and provides entity-specific forms, staged multi-entity changesets with Apply and Discard controls, confirmed deletion, a complete registered-block selector, an optional advanced JSON source view, explicit Active-to-Selected comparisons, confirmed archived-set restoration, and contextual DocSidebar help.

You can find continuously expanding WP Suite documentation at:
https://wpsuite.io/docs/

This plugin is not affiliated with or endorsed by the WordPress Foundation. All trademarks are property of their respective owners.

== Usage Notice ==

Composer is designed for authenticated, capability-controlled administration and draft execution.

It does not grant anonymous agent access. Agent operations require an authenticated WordPress user with the Composer execution capability. The dedicated `smartcloud_agent` role has draft execution permissions but does not receive publishing, deletion, plugin-management, theme-management, media-upload, or unfiltered-HTML capabilities.

Composer does not publish content, delete normal content, modify theme files, upload media, or activate imported configuration automatically. Temporary preview drafts are the only content Composer deletes automatically, and only after they are marked as Composer-owned previews and have expired.

== Installation ==

1. Upload the plugin ZIP or install it through the WordPress plugin installer.
2. Activate **SmartCloud Agent Composer** on the Plugins screen.
3. Open **WP Admin -> SmartCloud -> Agent Composer** and review runtime, configuration, theme, and provider status.
4. Create or import a Config Set, review the active theme and provider discovery, then validate and explicitly activate the configuration.
5. Assign the `smartcloud_agent` role only to WordPress users that should perform governed draft work. Public examples use placeholders such as `<agent-user>`; your actual username remains environment-specific.

Composer requires WordPress 6.9 or newer and PHP 8.1 or newer.

== Quick Start ==

Composer does not require a proprietary theme, WP Suite account, paid service, provider plugin, or Connector tunnel for its local configuration and validation screens. A standard block theme such as Twenty Twenty-Five is suitable for a minimal review.

**Configure the WordPress-native workflow:**

1. Activate Composer and open **SmartCloud -> Agent Composer**.
2. Choose **Universal Gutenberg**, **SmartCloud Recommended**, or **Detected Theme Starter** to create an inactive, editable Config Set, or create one manually. Presets never overwrite or activate configuration.
3. Open **Theme & providers**, choose **Rescan site**, and inspect the active theme, registered Gutenberg blocks, patterns referenced by the selected Config Set, and any optional provider profiles.
4. In the blueprint, choose the WordPress post type and the patterns and blocks that are actually registered on this site. Save the staged changes with **Apply modifications**.
5. Validate the complete Config Set, review any errors or warnings, and activate it explicitly. Imported and edited sets are never activated automatically.
6. Use **Compare to active**, **Export**, and **Audit & portability** to review checksums, revision-safe changes, and the complete JSON backup flow.

**Choose the starting contract:**

* **Universal Gutenberg** is the smallest, most portable option. It uses three required core-block patterns and is useful for maximum compatibility, first integration tests, and deliberately minimal pages.
* **SmartCloud Recommended** is the default choice for most marketing and information pages. It adds a portable hero, feature grid, steps, optional FAQ, and closing action without depending on one theme.
* **Detected Theme Starter** is generated only when the active theme exposes a safe one-root page pattern without its own H1. Composer supplies the governed page heading, preserves more of that theme's visual language, and keeps the result site-local for review after theme changes.

**Create the first governed agent draft:**

1. Install and activate the separate WordPress MCP Adapter. Composer then registers `/wp-json/mcp/smartcloud-agent-composer` through WordPress.
2. Create a dedicated WordPress user with the `smartcloud_agent` role. Do not use an administrator account for agent execution.
3. Connect an authenticated MCP client directly to the WordPress MCP endpoint. An OpenAI Connector tunnel may expose the same endpoint to a compatible OpenAI client, but the tunnel is optional and is not included with Composer.
4. Ask the client to load the page blueprint and design context, list approved patterns, validate the proposed block plan, create a draft, and load its preview.
5. Confirm that WordPress contains an agent-owned **Draft**, not a published item. Composer exposes no publish operation and does not grant the agent media-upload, plugin, theme, or user-management privileges.

The active Config Set must describe capabilities that the active theme and installed plugins actually provide. Composer can therefore use all or only part of a theme's capabilities, while validation fails closed when a required template, pattern, block, or provider is unavailable.

== Machine-readable resources ==

* Public TypeScript contracts: https://www.npmjs.com/package/@smart-cloud/agent-composer-core
* Plugin source and build instructions: https://github.com/smartcloudsol/agent-composer
* WP Suite documentation: https://wpsuite.io/docs/

== Frequently Asked Questions ==

= Does Composer publish or delete my pages and posts? =

No. Agent-facing execution is restricted to drafts. Composer does not expose publishing or normal-content deletion operations. It may permanently remove only expired, Composer-owned temporary preview drafts.

= Does Composer require another agent workflow plugin? =

No. Composer contains its own checksum-pinned execution contract and operates independently.

= How does Composer prevent concurrent configuration overwrites? =

Each entity has a revision number and canonical content checksum. Saving requires both the last revision and an `If-Match` checksum. If the entity changed after loading, Composer returns HTTP 409 with current and submitted conflict details and does not overwrite either version.

= How does an agent connect to Composer? =

Composer publishes its governed Ability surface through the WordPress MCP server. A compatible client can use that endpoint directly or reach it through an OpenAI Connector tunnel. Composer does not provide anonymous access; WordPress authentication and the assigned Composer capability remain required.

= How does the active theme affect Composer? =

Composer discovers the currently active theme's templates, registered patterns, Gutenberg blocks, and optional presentational manifest. A theme can therefore support the complete contract or only a compatible subset. Config Sets remain stored and explicitly activated in Composer; changing a theme never silently imports or activates configuration.

= How does the blueprint excerpt policy work? =

`required` accepts an excerpt only when it contains 80 to 300 characters. `optional` accepts either an empty excerpt or one containing 80 to 300 characters. `disabled` requires the excerpt to remain empty. The policy is enforced during validation, draft creation, draft update, inspection, and preview. The Yoast meta description remains a separate required field containing 120 to 160 characters.

= What is the difference between document and structured-record mode? =

Document Blueprints require an approved pattern sequence and produce a validated Gutenberg body. Structured-record Blueprints accept zero sections, keep the body empty, and can write only registered REST-visible fields explicitly enabled in the Site Contract. Public visibility remains a separate WordPress post-type concern; a theme may render a public structured record through a shared template.

= Can Composer work without an external service? =

Yes. Configuration, validation, audit, draft ownership, concurrency checks, pattern assembly, media lookup, and preview handling run inside WordPress. A WP Suite Hub connection and provider integrations are optional. See **External Services** for the network calls that become possible when those features are enabled.

= Does Composer upload media? =

No. It can search existing Media Library images and materialize validated core Image blocks. Uploading, editing, and deleting Media Library items are outside its agent ability surface.

= How are preview drafts cleaned up? =

Composer marks real preview drafts with private metadata, stores an expiration time, and schedules a WordPress cron cleanup. Cleanup verifies both Composer ownership and preview markers before deleting an expired preview.

= How can I preserve configuration before uninstalling? =

Open **SmartCloud -> Agent Composer -> Audit & portability** and choose **Export all configuration**. The JSON backup contains every config set with collection and entity checksums. It intentionally excludes credentials and the site-specific audit chain. Restoring the bundle validates it completely, rolls back the whole attempt if any package fails, and leaves all restored sets inactive until you validate and activate one explicitly.

= Does Composer expose provider operations under Composer-owned names? =

No. Provider plugins retain ownership of their business Abilities. Composer may discover, validate, and delegate to those registered Abilities, while recording the provider Ability identity and contract in the execution path.

== Screenshots ==

1. Composer status and execution-boundary overview
2. Config-set lifecycle with an explicit Active-to-Selected comparison
3. Archived configuration validation and restore confirmation
4. Guided page-blueprint editor with excerpt, target, pattern, block, and policy fields
5. Provider discovery and readiness view
6. Audit event details and hash-chain verification

== External Services ==

The Composer execution engine and its plugin-specific admin application use local WordPress APIs. The packaged shared WP Suite Hub and installed provider plugins can optionally perform external network requests depending on configuration. Composer does not load executable PHP code from a remote service.

1. **Provider-owned WordPress Abilities (optional)**
   - **When it applies:** Only when an administrator enables a provider integration and an authenticated agent requests a provider-backed component operation.
   - **What Composer sends:** The validated component input or block subtree required by the selected Ability, together with non-secret execution context defined by that provider's schema.
   - **Where it goes:** Composer invokes the provider Ability inside the same WordPress request. Composer itself does not choose or contact an external endpoint. The provider plugin may subsequently contact a service configured by the site owner.
   - **What to review:** Before enabling a provider, review that plugin's **External Services** section, terms, privacy policy, configured endpoint, and data-retention behavior. Examples include a customer-controlled AWS API, Amazon Cognito, or another endpoint explicitly configured in the provider plugin; these are not contacted by Composer unless the provider itself performs that operation.

2. **WP Suite platform connection (optional; workspace linking and shared Hub features)**
   - **When it applies:** When an administrator uses the packaged shared WP Suite Hub to connect the WordPress site to a WP Suite workspace or enables shared account, configuration, license, or subscription features.
   - **What it is used for:** Workspace linking, shared admin capabilities, account and entitlement status, optional subscription configuration, and related WP Suite platform functions.
   - **What data may be sent:** Minimal site/workspace identifiers, plugin and capability metadata, and authentication/session data required for linking and management. Draft page content is not sent merely by opening the Composer admin screen.
   - **Where it goes:** Secure HTTPS requests from the browser to WP Suite services such as `wpsuite.io` and `api.wpsuite.io`.
   - **Links:**
     - WP Suite Privacy Policy: https://wpsuite.io/privacy-policy
     - WP Suite Terms of Use: https://wpsuite.io/terms-of-use

3. **Amazon Cognito (optional; WP Suite Hub authentication)**
   - **When it applies:** When an administrator signs in through the shared WP Suite Hub or uses a Cognito-protected WP Suite/provider integration.
   - **What it is used for:** User authentication and token-based authorization for subsequent WP Suite or protected provider API requests.
   - **What data may be sent:** Authentication identifiers, session data, and tokens required by the configured Cognito user pool. Composer does not store Cognito passwords in portable configuration packages or audit events.
   - **Links:**
     - AWS Service Terms: https://aws.amazon.com/service-terms/
     - AWS Privacy: https://aws.amazon.com/privacy/

4. **Stripe (optional; subscription or purchase flow)**
   - **When it applies:** Only when an administrator opens an optional WP Suite subscription or purchase flow exposed through the shared Hub.
   - **What it is used for:** Displaying hosted pricing/subscription UI and processing the optional purchase flow.
   - **What data may be sent:** Browser/session data and payment-flow information required by Stripe. Payment card data is handled by Stripe and is not stored by Composer.
   - **Links:**
     - Stripe Consumer Terms: https://stripe.com/legal/consumer
     - Stripe Privacy Policy: https://stripe.com/privacy

The npm package and documentation links above are informational developer resources. Composer does not contact npmjs.com merely because the plugin is installed.

== Privacy ==

Composer stores configuration entities, private execution metadata, validation state, and redacted audit events in the WordPress database. Audit events store a canonical input hash and allowlisted/redacted context rather than page content or credentials. Optional WP Suite Hub authentication and subscription data is handled by the shared Hub, Amazon Cognito, WPSuite.io, and Stripe as described above.

Uninstalling Composer removes its configuration entities, validation state, options, scheduled cleanup task, dedicated role and capabilities, audit table, and Composer-owned temporary preview drafts on every site where it stored data. Export a complete configuration backup first if you may reinstall later. Composer does not delete ordinary page or post drafts created through Composer, because those remain site content under WordPress ownership.

Composer does not store provider API secrets in portable configuration packages. Keys resembling passwords, tokens, authorization values, secrets, or API keys are rejected from package imports and redacted from audit context.

== Trademark Notice ==

WordPress and Gutenberg are trademarks of the WordPress Foundation. Amazon Web Services, AWS, and Amazon Cognito are trademarks of Amazon.com, Inc. or its affiliates.

SmartCloud Agent Composer is an independent project and is not affiliated with, sponsored by, or endorsed by the WordPress Foundation or Amazon Web Services.

== Source & Build ==

Source repository and reproducible build instructions:
https://github.com/smartcloudsol/agent-composer

**Public contract package:**
The transport-free TypeScript interfaces and constants are published as `@smart-cloud/agent-composer-core`.

**WordPress plugin:**
PHP owns REST registration, authorization, persistence, configuration portability, audit, and execution. The standalone React/Vite/Mantine admin owns REST consumption and UI state. The WordPress admin artifact is built with `WPSUITE_PREMIUM=true` and `@wordpress/scripts`, then flattened from `admin/dist` and `admin/php` into the plugin's `admin` directory.

**Execution contract:**
The Composer execution layer is frozen by a manifest containing the SHA-256 checksum of every execution source file. Contract tests verify the complete manifest and public Ability surface before packaging.

The plugin package contains its PHP and JavaScript runtime. Optional Hub and provider features may exchange data with the external services disclosed above, but executable PHP is not downloaded at runtime.

== Changelog ==

= 1.0.0 =
* Added explicit Composer deactivation and complete inactive Config Set deletion with typed stable-ID and current-hash confirmation; active sets can never be deleted directly and audit history remains intact.
* Built-in presets derive their advisory BCP 47 content language from the current WordPress site locale; no concrete language is hardcoded.
* Initial release.
