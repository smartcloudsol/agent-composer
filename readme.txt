=== SmartCloud Agent Composer ===
Contributors: smartcloud
Tags: agents, gutenberg, automation, workflow, abilities
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.2.1
License: MIT
License URI: https://mit-license.org/

Governed configuration, validation, drafts, and human-reviewed update proposals for agent-ready Gutenberg sites.

== Description ==

SmartCloud Agent Composer adds a controlled WordPress layer for agent-assisted Gutenberg workflows. Administrators define versioned Config Sets with site contracts, page-type Blueprints, approved patterns, structured fields, relations, media policy, and safety rules. Agents can create or revise only validated, Composer-owned drafts, or prepare a separate working proposal for a human-reviewed published-content update.

Composer registers its governed Abilities through the separate WordPress MCP Adapter at `/wp-json/mcp/smartcloud-agent-composer`. A compatible authenticated MCP client can connect directly; an OpenAI Connector tunnel is optional and is not bundled.

Core operation runs inside WordPress without requiring a WP Suite account, subscription, hosted service, provider plugin, or proprietary theme. Optional integrations are disclosed under **External Services**.

**Key features**

* Draft-only agent execution with separate WordPress authorship and Composer ownership.
* Explicitly validated and activated Config Sets; imports and edits never activate automatically.
* Optimistic concurrency for configuration and draft changes.
* Gutenberg block-tree, pattern, template, post-type, language, excerpt, field, and relation validation.
* Document and structured-record Blueprints, including registered REST-visible fields approved by the Site Contract.
* Human-readable, ordered relation editing constrained by allowed target types, statuses, and cardinality.
* Existing-image assignment and optional bounded raster ingestion from approved HTTPS hosts.
* Short-lived preview drafts with ownership-checked cleanup.
* Bounded, sanitized static HTML previews with optional inline MCP Apps rendering in compatible clients.
* Redacted, tamper-evident audit events in an append-only SHA-256 hash chain.
* Checksum-protected Config Set lifecycle and active-theme/provider discovery.
* Provider-neutral language discovery that distinguishes authored-language policy from actual site language switching.
* Optional Polylang and WPML draft-language assignment and explicit linking of separately authored translations without publishing them.
* Human review can return the same update proposal for changes with an audited instruction, without creating another draft.

Documentation: https://wpsuite.io/docs/

This plugin is not affiliated with or endorsed by the WordPress Foundation. All trademarks are property of their respective owners.

== Usage Notice ==

Composer does not grant anonymous access or general WordPress administration. The dedicated `smartcloud_agent` role has no publishing, normal-content deletion, plugin, theme, user, arbitrary media-upload, or unfiltered-HTML capabilities.

Composer never publishes agent-created content. It deletes only expired, Composer-owned temporary previews. Optional remote ingestion is restricted to allowlisted HTTPS hosts and Composer-owned drafts; it is not a general Media Library API.

== Installation ==

1. Install the plugin ZIP through **Plugins -> Add New -> Upload Plugin** and activate it.
2. Open **SmartCloud -> Agent Composer** and review runtime, theme, and provider status.
3. Create or import an inactive Config Set, review its Site Contract and Blueprints, then validate and explicitly activate it.
4. Install and activate the separate WordPress MCP Adapter if an MCP client will use Composer.
5. Create a dedicated WordPress user with the `smartcloud_agent` role and configure the client to authenticate as that user.

Composer requires WordPress 6.9 or newer and PHP 8.1 or newer.

== Frequently Asked Questions ==

= Can Composer publish or delete site content? =

No. Agent-facing operations create and update drafts only. Normal content cannot be published or deleted through Composer. Only expired, Composer-owned temporary previews are removed automatically.

= How does an agent connect? =

Install the WordPress MCP Adapter, use a dedicated authenticated WordPress user, and connect a compatible MCP client to `/wp-json/mcp/smartcloud-agent-composer`. An optional Connector tunnel can expose the same endpoint without changing Composer's WordPress authorization boundary. The `get-rendered-preview` tool returns a sanitized content-scoped HTML snapshot. MCP Apps clients can display it inline when WordPress MCP Adapter 0.6.0 or newer is installed; other clients still receive the structured result.

= How does media handling work? =

Composer can search readable Media Library images and assign an existing image as the featured image of a Composer-owned draft. If an administrator explicitly enables remote ingestion and allowlists an exact HTTPS host in the active Site Contract, Composer can download one bounded raster image, validate it, store it locally, and assign it to that draft. It cannot browse arbitrary hosts, follow redirects, edit existing media, or delete Media Library items.

= How can configuration be preserved before uninstalling? =

Open **SmartCloud -> Agent Composer -> Audit & portability** and export all configuration. The checksum-protected JSON contains Config Sets but excludes credentials and site-specific audit history. Restored sets remain inactive until reviewed, validated, and activated.

= What happens on multilingual and monolingual sites? =

The `list-supported-content-languages` Ability reports the Site Contract's authoring policy separately from provider-backed language switching and draft linking. Without an active localization bridge, Composer does not claim that the site can switch or link languages; a wildcard Site Contract may still permit an agent to author copy in the language requested by the user. With Polylang or WPML and its matching optional Composer bridge active, newly created drafts receive a configured language. Two or more separately authored drafts can be linked explicitly, or one owned draft can be added to an empty language slot in an existing translation group without changing any existing member, regardless of its status. Composer does not translate copy and never publishes those drafts. TranslatePress is treated like an ordinary WordPress site until a stable content-translation Ability becomes available, so agents can still author drafts while editors translate them in WordPress.

= What happens when a proposal needs another editing pass? =

An authorized human reviewer can return a submitted or rejected proposal for changes with a required reason. Composer reopens the same agent-owned working copy with new concurrency tokens, exposes the instruction to the assigned agent, and requires the revised proposal to be validated and submitted again. Proposal working and audit copies remain available under Content proposals; ordinary post and custom-post-type lists hide them by default and provide an explicit filter to reveal them with their proposal state.

== Screenshots ==

1. Composer status and execution-boundary overview
2. Config Set lifecycle with an explicit Active-to-Selected comparison
3. Archived configuration validation and restore confirmation
4. Guided page Blueprint editor with target, excerpt, field, relation, media, pattern, block, and policy controls
5. Theme and provider discovery and readiness view
6. Audit event details and hash-chain verification

== External Services ==

Composer's configuration, validation, audit, ownership, concurrency, pattern assembly, local media lookup, and preview handling run inside WordPress. Optional features can make the following requests. Composer never downloads executable PHP from a remote service.

1. **Provider-owned WordPress Abilities (optional)**
   * Used only when an administrator enables a provider integration and an authenticated agent requests that provider's operation.
   * Composer passes the validated component input and non-secret execution context to the provider Ability in the same WordPress request. The provider plugin may then contact its configured service.
   * Review the provider plugin's terms, privacy policy, endpoint, transmitted data, and retention before enabling it.

2. **Administrator-approved remote media sources (optional)**
   * Used only when the active Site Contract enables remote ingestion, lists the exact HTTPS host, and an authenticated Composer agent with the dedicated capability requests one image.
   * Composer sends a normal HTTPS image request with its user-agent and standard network headers. It does not send draft content, WordPress credentials, cookies, or portable configuration secrets.
   * The bounded response is restricted to allowed raster MIME types, validated, fingerprinted, stored in the local Media Library, and assignable only to a Composer-owned draft. HTTP, redirects, embedded credentials, custom ports, and non-allowlisted hosts are rejected.
   * The administrator must verify the source's reuse rights, terms, and privacy policy.

3. **WP Suite platform connection (optional)**
   * Used only when an administrator connects the packaged shared Hub to a WP Suite workspace or enables shared account, entitlement, license, configuration, or subscription features.
   * Minimal site/workspace identifiers, plugin and capability metadata, and authentication/session data may be sent by HTTPS to `wpsuite.io` or `api.wpsuite.io`. Opening Composer alone does not send draft content.
   * Privacy: https://wpsuite.io/privacy-policy
   * Terms: https://wpsuite.io/terms-of-use

4. **Amazon Cognito (optional)**
   * Used when an administrator signs in through the shared Hub or a Cognito-protected integration.
   * Authentication identifiers, session data, and authorization tokens required by the configured user pool may be sent. Composer excludes Cognito passwords from portable packages and audit events.
   * AWS Service Terms: https://aws.amazon.com/service-terms/
   * AWS Privacy: https://aws.amazon.com/privacy/

5. **Stripe (optional)**
   * Used only when an administrator opens an optional WP Suite subscription or purchase flow in the shared Hub.
   * Browser/session and payment-flow data required by Stripe may be sent. Stripe handles card data; Composer does not store it.
   * Terms: https://stripe.com/legal/consumer
   * Privacy: https://stripe.com/privacy

The documentation, GitHub, and npm links in this readme are informational and are not contacted merely because the plugin is installed.

== Privacy ==

Composer stores Config Sets, private execution metadata, validation state, and redacted audit events in WordPress. Audit events retain hashes and allowlisted/redacted context rather than credentials or full page content. Secret-like keys are rejected from imports and redacted from audit context.

Uninstall removes Composer configuration, options, scheduled cleanup, dedicated role and capabilities, audit table, and owned temporary previews from sites where it stored data. Ordinary drafts remain WordPress content. Export configuration first if it may be needed later.

== Source & Build ==

Human-readable source and reproducible build instructions:
https://github.com/smartcloudsol/agent-composer

Public TypeScript contracts:
https://www.npmjs.com/package/@smart-cloud/agent-composer-core

The distributed JavaScript and CSS are built from public `admin/src` and `core` sources. PHP owns registration, authorization, persistence, audit, portability, and execution. The release assembler adds the shared Hub runtime, verifies the package, normalizes timestamps, and records SHA-256 checksums.

== Changelog ==

= 1.2.1 =
* Preview: Return bounded and sanitized static HTML for Composer-owned drafts with validation, concurrency metadata, a digest, and approved asset origins.
* MCP Apps: Expose an inline rendered-preview resource for compatible clients while retaining the structured tool result for every MCP client.
* Dependencies: Refresh the Composer admin application and public core contract while retaining React 18 compatibility.

= 1.2.0 =
* Proposals: Allow agents to prepare durable working copies for published content while keeping merge and rejection human-only.
* Localization: Add a provider-neutral localization contract, a manifest-driven provider selector, and separately installable WPML and Polylang bridges for existing translations and separately authored multilingual drafts.
* Safety: Recheck source, proposal, Blueprint, localization, metadata, and taxonomy state under a locked human merge transaction.
* TypeScript: Use NodeNext-compatible declaration imports in the separately published core package.
* Languages: Add generic supported-language discovery with distinct authoring, language-switching, and localized-draft-linking signals.
* Drafts: Store an immutable BCP 47 content language on new Composer drafts and keep every translation as a separate draft.
* Polylang and WPML: Let each optional bridge assign configured languages and explicitly link an exact set of Composer-owned drafts without publishing them.
* Policy: Support the `*` Site Contract and Blueprint language wildcard while allowing a Blueprint to narrow it.

= 1.1.2 =
* Compatibility: Declared compatibility with WordPress 7.1.

= 1.1.1 =
* Compatibility: Allow governed draft creation on SQLite-backed WordPress installations with an atomic, expiring idempotency lock.

= 1.1.0 =
* Taxonomies: Add a governed search, optional creation, draft assignment, and read-back workflow for Site Contract-approved public terms.
* Administration: Discover attached taxonomies and configure search, assignment, creation, limits, assignment mode, and hierarchical parent policy in the guided Site Contract editor.
* Safety: Keep term creation confirmation-gated and idempotent, restrict relationships to Composer-owned assigned drafts with optimistic concurrency, and expose no term edit or deletion operation.

= 1.0.4 =
* Relations: Make field contracts identify the required relation lookup, write, and verification workflow.
* MCP: Add concrete result schemas and stronger descriptions, including the editable-content alias, so clients use `search-relation-targets` for post IDs.
* Discovery: Count and paginate `list-content-drafts` only after Composer policy and WordPress capability filtering.

= 1.0.3 =
* Multisite: Store shared Hub ownership per site and recognize network-activated owners.
* Hub admin: Load the WebCrypto vendor before the shared admin bundle.

= 1.0.2 =
* Dependency: Rebuilt the bundled WP Suite Hub and Amplify vendor runtime with exact supported SmartCloud Amplify UI 6.15.5/3.6.5/6.15.5 versions.

= 1.0.1 =
* Compatibility: Restored ownership-safe WP Suite Theme CSS fragment updates on WordPress-managed Custom CSS storage.

= 1.0.0 =
* Initial public release.
* Fixed guided rule-list editing so spaces and new lines remain available while typing, and added pointer feedback to enabled switches.

== Upgrade Notice ==

= 1.2.1 =
Refresh the MCP tool and resource catalog after upgrading. Inline ChatGPT previews require WordPress MCP Adapter 0.6.0 or newer; structured preview data remains available without MCP Apps UI support.

= 1.2.0 =
Adds human-reviewed published-content proposals and optional Polylang/WPML draft linking. Review and activate a compatible Config Set, install the matching localization bridge when needed, and refresh the MCP tool catalogue.

= 1.1.2 =
Declares compatibility with WordPress 7.1.

= 1.1.1 =
Recommended for WordPress Playground and other SQLite-backed installations that use Composer draft creation.

= 1.1.0 =
Recommended for sites where agents manage categories, tags, or custom taxonomy terms. Review and activate explicit taxonomy permissions in a cloned Config Set, then restart the site MCP runtime and refresh the client tool catalog.

= 1.0.4 =
Recommended for sites that use structured relation fields. This update makes relation-ID lookup explicit and removes misleading editable-content totals; restart the site MCP runtime and refresh the client tool catalog after upgrading.

= 1.0.3 =
Recommended for multisite installations using the shared WP Suite Hub.

= 1.0.2 =
Recommended dependency refresh away from deprecated SmartCloud Amplify UI releases.

= 1.0.1 =
Recommended compatibility update for Starter-managed WP Suite Theme CSS.

= 1.0.0 =
Initial public release.
