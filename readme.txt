=== SmartCloud Agent Composer ===
Contributors: smartcloud
Tags: agents, gutenberg, automation, workflow, abilities
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.3.1
License: MIT
License URI: https://mit-license.org/

Governed configuration, validation, drafts, and human-reviewed update proposals for agent-ready Gutenberg sites.

== Description ==

SmartCloud Agent Composer adds a controlled WordPress layer for agent-assisted Gutenberg workflows. Administrators define versioned Config Sets with site contracts, page-type Blueprints, approved patterns, structured fields, relations, media policy, and safety rules. Agents can create or revise only validated, Composer-owned drafts, or prepare a separate working proposal for a human-reviewed published-content update.

Composer registers its governed Abilities through the separate WordPress MCP Adapter at `/wp-json/mcp/smartcloud-agent-composer`. A compatible authenticated MCP client can connect directly; an OpenAI Connector tunnel is optional and is not bundled.

For remote or multi-user clients, Composer can validate Amazon Cognito access tokens and intersect group roles, per-App-Client role ceilings, granted Composer scopes, and the active content contract. This protected mode does not create shadow WordPress users. The backward-compatible dedicated WordPress user path remains available when MCP protection is Open.

Core operation runs inside WordPress without requiring a WP Suite account, subscription, hosted service, provider plugin, or proprietary theme. Optional integrations are disclosed under **External Services**.

**Key features**

* Draft-only agent execution with separate WordPress authorship and Composer ownership.
* Explicitly validated and activated Config Sets; imports and edits never activate automatically.
* Optimistic concurrency for configuration and draft changes.
* Gutenberg block-tree, pattern, template, post-type, language, excerpt, field, and relation validation.
* Document and structured-record Blueprints, including registered REST-visible fields approved by the Site Contract.
* Human-readable, ordered relation editing constrained by allowed target types, statuses, and cardinality.
* Existing-image assignment, optional bounded Contributor raster ingestion from approved HTTPS hosts, and separately enabled Publisher-only public Media Library image upload with governed SEO metadata.
* Short-lived preview drafts with ownership-checked cleanup.
* Bounded, sanitized static HTML previews with optional inline MCP Apps rendering in compatible clients.
* Redacted, tamper-evident audit events in an append-only SHA-256 hash chain.
* Checksum-protected Config Set lifecycle and active-theme/provider discovery.
* Provider-neutral language discovery that distinguishes authored-language policy from actual site language switching.
* Optional Polylang and WPML draft-language assignment and explicit linking of separately authored translations without publishing them.
* Human review can return the same update proposal for changes with an audited instruction, without creating another draft.
* Versioned Structure Contracts protect semantic Gutenberg structure while keeping approved content and extension slots editable.
* Synced structural patterns use native Pattern Overrides so shared layout can evolve without copying per-page values.
* Single-item and bounded bulk Blueprint migrations produce deterministic previews and separate review proposals instead of rewriting published content.
* Optional or required WP-admin creation policy starts human-created CPT items from a validated managed Blueprint instead of an ungoverned blank document.
* Optional fail-closed Cognito MCP access with public OAuth discovery, PKCE S256, group roles, per-client ceilings, Composer scopes, filtered tools, and redacted audit evidence.
* Publisher-only handoff lets an authorized reviewer inspect and submit another principal's ordinary Composer-owned draft without taking edit ownership.
* Revision-bound inline MCP App approval in compatible clients, with human-only decision controls and a WordPress-admin fallback.

Documentation: https://wpsuite.io/docs/

This plugin is not affiliated with or endorsed by the WordPress Foundation. All trademarks are property of their respective owners.

== Usage Notice ==

Composer does not grant anonymous access or general WordPress administration. Protected MCP access validates signed Cognito access tokens without provisioning WordPress users. In backward-compatible Open mode, the dedicated `smartcloud_agent` role has no publishing, normal-content deletion, plugin, theme, user, arbitrary media-upload, or unfiltered-HTML capabilities. The optional Publisher image tool is available only in protected mode and never grants general WordPress upload access.

The model never publishes agent-created content. An authorized human may approve one exact, locked revision through the inline MCP App or WordPress fallback; any intervening draft change invalidates the request. Composer deletes only expired, Composer-owned temporary previews. Optional remote ingestion is restricted to allowlisted HTTPS hosts and Composer-owned drafts; it is not a general Media Library API.

== Installation ==

1. Install the plugin ZIP through **Plugins -> Add New -> Upload Plugin** and activate it.
2. Open **SmartCloud -> Agent Composer** and review runtime, theme, and provider status.
3. Create or import an inactive Config Set, review its Site Contract and Blueprints, then validate and explicitly activate it.
4. Install and activate the separate WordPress MCP Adapter if an MCP client will use Composer.
5. For remote or multi-user MCP access, configure **MCP Access** with a Cognito User Pool, Hosted UI domain, public authorization-code plus PKCE App Client, exact client callback, external MCP resource URI, group-role mappings, client ceiling, and optional URI-bound Composer scopes. Keep protection Open until the OAuth round trip succeeds.
6. For the backward-compatible Open path only, create a dedicated WordPress user with the `smartcloud_agent` role and store its Application Password in the client secret store.

Composer requires WordPress 6.9 or newer and PHP 8.1 or newer.

== Frequently Asked Questions ==

= Can Composer publish or delete site content? =

The model cannot publish or delete normal content. A Publisher may request human approval for one exact Composer-owned draft revision, including a draft assigned to another Composer principal, without receiving edit ownership. Publication occurs only after an authorized person explicitly approves the still-current revision in the inline MCP App or WordPress fallback. Only expired, Composer-owned temporary previews are removed automatically.

= How does an agent connect? =

Install WordPress MCP Adapter 0.6.1 or newer and connect to `/wp-json/mcp/smartcloud-agent-composer`. For protected remote access, configure Cognito access-token validation and use a public authorization-code plus PKCE client; a firewalled site needs an HTTP Secure MCP Tunnel profile because STDIO cannot forward the OAuth challenge or bearer token. Use the tunnel client's OAuth/DCR HTTP profile for protected access and its remote-no-auth HTTP profile while Composer intentionally remains Open without an identity provider. Open mode may instead use a dedicated authenticated WordPress user. The `get-rendered-preview` tool returns a sanitized content-scoped HTML snapshot. Compatible MCP Apps clients can display it inline, and its bounded local image, WOFF/WOFF2 font, stylesheet, and import bridge does not require direct browser access to a private WordPress origin.

= How does media handling work? =

Composer can search readable Media Library images and assign an existing image as the featured image of a Composer-owned draft. If an administrator explicitly enables remote ingestion and allowlists an exact HTTPS host in the active Site Contract, a Contributor can download one bounded raster image, validate it, store it locally, and assign it to that draft. Separately, a protected Publisher may upload one new immediately public image only when the active Site Contract enables Publisher uploads. That operation requires a semantic SEO slug, title, explicit alt policy, rights and publication confirmations, and bounded validated bytes from base64 or safe HTTPS. Readers and Contributors cannot discover or call it. No Composer role can edit or delete existing Media Library items.

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
   * A protected Publisher may also supply a safe HTTPS image to the separately enabled public-media tool. It uses the same bounded network behavior and requires an explicit rights confirmation, but the Contributor host allowlist does not grant or constrain Publisher authority.

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

= 1.3.1 =
* Dependencies: Bundle WP Suite Hub 2.5.16 so Static Publisher is notified when the shared translation catalog changes.
* Publisher media: Add an explicitly enabled, protected Publisher-only MCP tool for publishing one governed raster image to the Media Library without granting general WordPress upload access.
* SEO and accessibility: Require a semantic filename slug, title, descriptive-or-decorative alt decision, rights confirmation, and immediate-publication confirmation.
* Validation and privacy: Bound base64 or safe HTTPS input, verify signature/MIME/size/dimensions/idempotency, and keep raw bytes and source URLs out of the audit log.

* Operational Abilities: Admit a bounded list of registered external tools to the Composer MCP surface, including the Static Publisher job scheduler and its read-only target, rule, and job-status tools.
* Publisher reads: Classify the explicit Static Publisher read tools at the read boundary while retaining provider-enforced Publisher-only discovery and job-specific status authorization in protected modes.
* MCP surface: Refresh tool-list cache identity while preserving Composer's prohibition on direct agent publication.

= 1.3.0 =
* MCP security boundary: Add optional Cognito access-token validation, group roles, per-client ceilings, optional scopes, filtered discovery, invocation enforcement, principal-bound ownership, and actor-aware audit context on WordPress MCP Adapter hooks.
* Human publication: Add Publisher-only, revision/hash-bound requests, cross-principal read-only handoff, an inline MCP App with app-private human decision tools, and a WordPress-admin fallback; agents never receive direct publication or model-visible approval tools.
* MCP administration: Add a guided MCP Access screen, automatic Cognito provider discovery with manual override, Site Contract-required authenticated mode, and fail-closed status guidance.
* MCP OAuth discovery: Advertise protected-resource metadata, PKCE S256, public-client token authentication, and Cognito authorization-code plus refresh-token grants for direct and HTTP-tunneled clients.
* OAuth resource binding: Configure the exact external MCP resource URI, advertise its URI-bound scopes, and reject access tokens whose audience does not match that resource.
* MCP diagnostics: Record token-free transport denial details, including the stable validation error and whether an Authorization header was present, in the append-only audit chain.
* MCP audit trail: Record accepted MCP requests and completed tool calls without storing bearer values, request arguments, or tool results.
* Structure Contracts: Add independently versioned semantic structure policy, protected editor projection, managed baselines, typed override manifests, drift detection, and machine-readable violations.
* Semantic agent editing: Add contract and document reads plus field, media, and extension-slot operations that use stable semantic IDs instead of serialized Gutenberg paths.
* Synced patterns: Materialize approved local synced patterns with native Pattern Overrides and validate their expanded structure without copying shared markup into each post.
* Versioned migrations: Add exact source/target baselines, historical contract verification, deterministic section and field operations, override-aware rebasing, zero-write previews, and plan-bound update proposals.
* Bulk migrations: Add bounded planning and compatibility reports for up to 100 items per page and idempotent proposal creation for up to 25 explicitly reviewed items.
* Administration and documentation: Clearly separate human Migration guidance from executable migration policy and document the complete what, why, how, and recovery workflow.
* Administration feedback: Keep refreshed content in place, use dimension-preserving skeletons for initial data loads, and show pending state on the button that started each operation.
* Pattern diagnostics: Distinguish registry patterns from synced structural wp_block records and reserve Missing for genuinely unavailable sources.
* Site Contract policy: Treat explicit list values as replacements, so removing a default block prohibition such as `core/embed` no longer leaves a trailing numeric-list entry active.

= 1.2.6 =
* WP Suite Solution workflow: Enable human-reviewed published-content update proposals in the Solution Blueprint and Site Contract.
* WP Suite preset: Add Config Set 8 with Site Contract policy 1.0.46; the imported set remains inactive until validation and explicit activation.
* Rendered preview localization: Preserve the source content language on published-update proposals and recover it from the proposal localization snapshot for existing working copies.

= 1.2.5 =
* Rendered preview localization: Make the draft's authored content language available to frontend render integrations so localized links are not rewritten using the MCP request locale.
* Preview policy: Add a Site Contract switch that keeps rendered HTML preview required by default for existing configurations and allows administrators to make it optional.
* Draft workflow: Keep rendered preview as the recommended default while allowing an explicit user request to skip it.
* Proposal review: Allow an optional-policy update proposal to be submitted with fresh concurrency tokens and no preview token; continue validating any supplied token against the exact current revision.
* MCP discovery: Expose the effective policy through descriptions, input schemas, and runtime capabilities so clients do not repeatedly request a preview when the user opted out.
* WP Suite preset: Set rendered preview to optional and allow existing category/tag assignment for posts plus the four content CPTs in policy 1.0.45.
* Preview backgrounds: Let the recreated frontend body grow with long content, and publish the fix under a v5 resource URI with v1-v4 compatibility aliases.

= 1.2.4 =
* Rendered preview: Return sanitized frontend HTML without Gutenberg serialization comments, and publish the corrected ChatGPT viewer under a fresh MCP Apps resource URI.
* Preview assets: Flatten bounded local CSS imports and proxy local stylesheet images plus WOFF/WOFF2 fonts through private revision-bound assets, with strict path, type, size, cycle, and count guards.
* Preview compatibility: Serve the current viewer through the legacy v1 and v2 resource URIs, and keep exact revision-bound assets readable after proposal submission or closure.
* Preview performance: Cache the authorized revision-bound asset map for 30 minutes, deduplicate repeated host deliveries, and limit concurrent private asset calls so each CSS, image, or font read stays lightweight.
* Preview transport: Read valid top-level MCP resource content even when an adapter also returns an empty compatibility result, and deliver ordered preview CSS through one private resource instead of one call per input stylesheet.
* Preview fidelity: Recreate the frontend body and WordPress content wrappers inside the isolated viewer, preserve safe Gutenberg inline styles, and prioritize explicitly selected theme CSS before fallback scanning.
* Preview layout: Keep long rendered pages and warning lists in bounded scrolling areas instead of stretching the conversation indefinitely.
* Preview cache compatibility: Publish the bounded viewer under a v4 resource URI and keep v1-v3 aliases serving the latest app.
* Preview backgrounds: Let the recreated frontend body grow with long content, and publish the fix under a v5 resource URI with v1-v4 compatibility aliases.
* Proposal review: Require the exact short-lived, agent- and revision-bound token from the final rendered preview before an update proposal can be submitted.
* Blueprints: Enable governed update proposals for the homepage and product pages, and accept the homepage Playground CTA used by the published site.
* Dependencies: Update Agent Composer Core to 1.2.2 and the bundled WP Suite Hub to 2.5.15 with the Amplify preview.3 Authenticator translation corrections.

= 1.2.3 =
* Preview: Reliably initialize the inline MCP Apps viewer, accept ChatGPT tool output, and require a rendered preview after the final draft write.
* Preview assets: Expose bounded, allowlisted same-origin stylesheets and images through the rendered-preview asset tool so compatible clients can display previews when the WordPress site is private.
* Blueprints: Preserve complete Contact details and normalized Flow success actions, allow authored offer actions to remain optional, and govern About page updates through proposals.
* Config Sets: Ship a new 26-Blueprint WP Suite preset for theme 1.0.59 without overwriting an installed working set.
* Dependencies: Update the bundled WP Suite Hub to 2.5.14 with the shared site translation catalog and corrected Amplify translations.

= 1.2.2 =
* Localization: Attach an inspected draft or published item to an empty language slot, and safely merge two exact non-conflicting translation groups without changing publication state.
* Safety: Require content concurrency tokens, complete relationship snapshots, edit permission for every member, provider-side verification, idempotent replay, and verified rollback.
* Blueprints: Add dedicated Contact, Privacy Policy, Terms of Use, and Blog index contracts, expand Flow form and comparison preservation, and enable all five site languages.
* Query Loops: Govern author and sticky include, exclude, or only behavior and preserve localized archive destinations.
* Cloning: Allow an inspected source to be copied into a separately governed target content language while verifying byte-exact source preservation.

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

= 1.3.1 =
Restart MCP and refresh client tools after installing the matching Static Publisher update. In Protected or Protected Required mode, map publish/content-sync operators and their status checks plus Publisher target/rule discovery to Publisher; Contributor remains sufficient for crawl/deploy scheduling and status checks. Existing Config Sets keep Publisher media upload disabled. To enable it, clone the active set, enable **Allow Publisher media uploads**, review the shared MIME and size limits, validate and activate it, then restart MCP and refresh the Publisher connector's tools. No new Cognito resource-server scope is required: the operation reuses the existing resource-bound `publish.request` scope. Readers, Contributors, and Open mode remain unable to upload.

= 1.3.0 =
Install MCP Adapter 0.6.1+. The upgrade adds approval storage/capability but leaves MCP protection Open. Validate and activate a Structure Contract Config Set, synchronize its local patterns, restart MCP, reconnect compatible clients so they discover the inline approval App, and refresh tools. Existing content is not migrated automatically. Before enabling Protected Required, configure a Cognito Hosted UI domain and public code-plus-PKCE client with the exact callback, map groups and client ceilings, migrate any external tunnel from STDIO to HTTP, copy the exact external MCP resource URI into Composer, and complete a test OAuth round trip. Enable scope enforcement only after Cognito uses that same URI as its resource-server identifier and App Client scopes.

= 1.2.6 =
To enable Solution update proposals, activate the bundled theme 1.0.59 Config Set, restart MCP, and refresh tools. The restart also enables rendered-preview language recovery for localized proposals.

= 1.2.5 =
Restart MCP and refresh tools. Existing Config Sets still require rendered previews. To make them optional, clone, validate, and activate a Config Set with design_policy.rendered_preview_policy set to optional. Theme 1.0.68 supports localized preview links.

= 1.2.4 =
Restart MCP and refresh tools/resources to load the v3 preview and token schema. Activate the updated WP Suite Config Set to allow homepage and product-page proposals; existing active Config Sets remain unchanged.

= 1.2.3 =
Import, validate, and explicitly activate the new WP Suite Config Set if the site uses the bundled theme 1.0.59 contract. Existing active Config Sets remain unchanged.

= 1.2.2 =
Install the matching Polylang or WPML bridge update, restart the MCP runtime, refresh its tool catalogue, and activate a Config Set containing the new localized page Blueprints before authoring those pages.

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
