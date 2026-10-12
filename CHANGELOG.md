# Changelog

## 1.4.1 - 2026-10-11

- Serialize audit appends with a permanent per-site database row lock before reading the audit indexes, preserving the existing hash-chain format without a schema migration.
- Retry owned audit transactions for deadlocks and lock timeouts within bounded limits, retaining event identity and rebuilding the predecessor hash; never retry caller-owned transactions or replay content changes.
- Check audit query and commit failures and preserve the original operation error when secondary diagnostic auditing fails.
- Include the requested page type, active configuration and available page types in missing-Blueprint diagnostics.

## 1.3.9 - 2026-10-05

- Restore Composer's external-principal MCP transport on Adapter 0.7.0 using its revision-aware wire processor and array error envelopes.
- Preserve the Adapter 0.6.1 transport path and request-scoped Composer authentication.
- Add a regression test for 2025 and 2026 MCP requests, malformed JSON, and HTTP method behavior.

## 1.3.8 - 2026-10-02

- Share generic editorial, relation, taxonomy assignment, and public term-definition validation between Composer execution and native editor integrations.
- Validate full stored editorial fields, language, and Blueprint structure on partial native and agent saves; preserve browser-editor opt-in and separate human-reviewed agent proposals.
- Reject source/proposal-source relation references, support empty taxonomy replacements, and read back stored field values after writes.
- Respect the active Blueprint's SEO description bounds and keep unchanged legacy term copy intact on unrelated partial term updates.
- Apply the same complete-document validation before classic admin, Quick Edit, and Bulk Edit core writes using their actual signed-in cookie and native nonce. Persist validated pattern refreshes and record managed state only after a successful post update.

## 1.3.3 - 2026-09-24

- Resolve Composer-owned `instanceSlots` during native synced-pattern frontend rendering so saved extension-slot content appears in public bodies and rendered previews.
- Reuse the resolved `WP_Block` instance and its Pattern Overrides context, keeping repeated references isolated while avoiding HTML post-processing.
- Add unit and real WordPress regression coverage for two references to the same synced pattern with distinct instance-slot paragraphs.

## 1.3.2 - 2026-09-23

- Stabilize the rendered-draft and publication-approval MCP Apps with host-bounded shells, fixed chrome, preview-only scrolling, deferred reveal after assets and fonts settle, duplicate-delivery suppression, and v6 resource URIs.
- Add stable synced-pattern instance identities and address semantic field, media, and pattern-owned slot mutations by `pattern_instance_id` plus their semantic field or slot ID.
- Allow repeated instances of the same synced pattern to retain independent native Pattern Overrides while preserving instance-scoped structure validation, comparison, ordering, and fingerprints.
- Add slot-level synced-pattern allowlists and per-pattern occurrence bounds to Structure Contracts, runtime validation, semantic document projection, MCP slot materialization, and the governed Gutenberg slot editor.
- Recursively verify governed synced-pattern references, contract versions, and content hashes with cycle and nesting-depth protection; persist compatibility identities when legacy instances are first updated.
- Refresh the execution manifest, MCP surface revision, regression coverage, and canonical block-editor build for the expanded contract.

## 1.3.1 - 2026-09-20

- Add the optional `upload-media-asset` MCP Ability for protected Publishers, using the existing resource-bound publication scope without granting WordPress's broad `upload_files` capability.
- Require an explicit Site Contract switch, immediate-publication and reuse-rights confirmations, a semantic SEO filename slug, title, and descriptive-or-decorative alt decision for every new asset.
- Accept one bounded base64 image or safe non-redirecting HTTPS source and validate MIME allowlist, file signature, size, dimensions, pixel count, and idempotency before inserting a public Media Library attachment.
- Keep the upload tool hidden and denied for Open mode, Reader, and Contributor actors; existing Contributor remote ingest and Media Library selection behavior is unchanged.
- Exclude raw bytes and source URLs from operation audit input while retaining the source kind and a one-way fingerprint, and advertise the new boundary through runtime capabilities and operator documentation.

- Admit a bounded, registered external operational Ability list to the Composer MCP surface so Static Publisher can expose its governed job scheduler plus read-only target, content-sync rule, and job-status operations without masquerading as a block provider.
- Classify the three explicit Static Publisher read Abilities at the read boundary while allowing the provider to enforce Publisher-only discovery and job-specific status roles in protected modes.
- Refresh the MCP surface revision for tool-list cache invalidation while preserving the existing direct-publication prohibition.

## 1.3.0 - 2026-09-19

- Route Composer MCP requests through an Adapter-compatible stateless HTTP transport so validated Cognito principals no longer fail the Adapter's WordPress-user-backed session creation step.

- Added optional Cognito-backed MCP authentication and role/client/scope authorization on the official MCP Adapter hooks.
- Added principal-bound draft ownership, actor-aware audit context, filtered tool discovery, and invocation enforcement without WordPress user provisioning.
- Added the Publisher-only, revision-bound human publish-approval request flow; direct agent publication remains unavailable.
- Reused the bounded rendered-preview HTML, CSS, image and font asset pipeline in the minimal read-only approval surface.
- Allow a protected Publisher to inspect and submit another principal's ordinary Composer-owned draft for approval without transferring assignment or granting mutation access.
- Add a revision-bound inline MCP App for human approval or rejection; keep its session token and decision tools app-private and retain the WordPress-admin review page as fallback.
- Make Publisher handoff tool routing explicit in the general inspection tool, the dedicated publication inspection tool, the publish request, and the MCP server instructions; return a corrective tool hint if general inspection encounters another principal's Composer draft.
- Let `request-publish` atomically validate and lock the current revision from `post_id` alone while retaining the optional exact concurrency pair, and add an MCP surface revision plus safe tool-list audit evidence for connector refresh diagnosis.
- Refresh the inline approval card from persisted state when reopened, show approved, rejected, expired, or invalidated as terminal UI states with disabled controls, and move new requests to a v2 resource URI while retaining the v1 compatibility alias.
- Require a second, cancellable in-app confirmation for both publication and rejection before invoking the private decision tool, and publish the updated card under a v3 resource URI while retaining v1 and v2 aliases.
- Present that second confirmation as an accessible, keyboard-contained modal over the rendered approval card, and publish it under a v4 resource URI while retaining v1-v3 aliases.
- Show an explicit empty-body state in publication review instead of an unexplained blank preview while continuing to render text-only, media-only, and structured draft bodies normally; publish it under a v5 resource URI with prior aliases retained.
- Added the simple MCP Access administration screen, provider/manual identity selection, and detailed operator documentation.
- Show the effective Cognito Region, User Pool, issuer, JWKS URL, and resolution source; require a complete manual fallback when provider discovery fails.
- Keep Cognito group inputs focused while their names change, make Composer switches visibly interactive, and add field-level plus DocSidebar guidance for public PKCE OAuth clients.
- Add a configurable external OAuth resource URI, advertise exact URI-bound Cognito scopes, require the matching access-token audience, and normalize only those exact external scopes to Composer's canonical authorization names.
- Add standards-based MCP OAuth discovery: protected transports advertise resource metadata and Composer exposes a Cognito-backed authorization-server metadata facade with explicit PKCE S256 support.
- Return no OAuth metadata (404) for an unconfigured Open-mode endpoint so a `sample_mcp_remote_no_auth` HTTP tunnel can become ready, while incomplete Protected Required configurations remain fail-closed with 503.
- Keep the connector on the Composer OAuth facade while retaining `openid` only as a legacy Cognito request scope; do not opt ChatGPT into OIDC user-information discovery or publish an issuer-invalid proxied document.
- Add a consistent section-level documentation button to every Composer administration surface, including direct access to the detailed MCP Access guide.
- Record accepted MCP transport requests and completed Composer tool calls in the append-only audit chain without persisting bearer values, request arguments, or tool results.
- Replace the layout-shifting global administration loader with dimension-preserving initial skeletons and local pending states on the operation that is actually running.
- Distinguish registered theme/plugin patterns from Site Contract-governed synced `wp_block` records in discovery, and report `Missing` only when the source required for that pattern kind is actually unavailable.
- Render audit timestamps in the browser's local time zone with an explicit zone marker while retaining the exact UTC value in event details.

- Made published-content proposal review human-readable for Gutenberg body changes by removing serialization markup, showing only the affected visible content with bounded context, and highlighting removed versus proposed text while retaining rendered preview as the layout authority.

- Add per-CPT `off`, `optional`, and `required` WP-admin creation policy so
  human-created items can start from the same validated Blueprint, synced
  patterns, managed baseline, language, and Structure Contract as agent drafts
  without becoming agent-owned.
- Enforce native Gutenberg saves for every managed document independently from
  agent ownership, and document the theme, pattern, Config Set, and migration
  reconciliation workflow.
- Give Structure Contract extension slots an explicit, capacity-aware approved
  block menu that does not depend on Gutenberg's contextual appender visibility,
  lock undeclared root insertion in managed documents, and keep their native
  preview links on the site's canonical HTTPS scheme.
- Show proposal changes at the exact structured field or taxonomy level with
  human labels, guidance, and side-by-side published and proposed values.
- Treat an explicit monolingual Blueprint language as the authored content
  language when no localization provider is active, independently from the
  WordPress administrative locale.
- Allow proposal-capable agents to inspect only Site Contract-allowlisted
  fields of published proposal sources without granting direct published-post
  editing permission.
- Refresh late-registering component providers before fixing the MCP tool
  surface, so provider blocks remain available regardless of plugin hook order.
- Recognize approved Canvas pattern metadata recursively inside semantic wrapper
  blocks such as AI Kit Knowledge Base sections.

- Add independently versioned Structure Contracts with semantic node ownership,
  protected editor projection, managed baselines, typed override manifests,
  drift detection, and machine-readable validation failures.
- Add semantic contract/document reads and field, media, and extension-slot
  mutation Abilities that never require raw serialized Gutenberg paths.
- Add WordPress-native synced structural patterns and bounded Pattern Overrides,
  preserving instance content while shared pattern structure changes.
- Add exact, deterministic Blueprint migration definitions, zero-write previews,
  override-aware rebasing, plan-bound update proposals, and administrator-only
  migration capability enforcement.
- Add bounded bulk migration planning and compatibility reporting plus
  idempotent, item-atomic proposal generation without direct published-content
  rewriting or bulk publication.
- Clarify the simple admin language and add detailed configuration, migration,
  troubleshooting, and future external-AI handoff documentation.

## 1.2.6 - 2026-09-13

- Enable human-reviewed published-content update proposals for the WP Suite
  Solution CPT in both its Blueprint and Site Contract access policy.
- Publish the change as the new `wpsuite-site-contract-8-theme-1-0-59`
  working Config Set with Site Contract policy 1.0.46.
- Preserve the localized source language on published-update proposals and
  recover it from the stored proposal context when rendering older working
  copies.

## 1.2.5 - 2026-09-13

- Expose the authored draft language as a request-scoped integration context
  while generating rendered HTML previews, preventing frontend localization
  filters from resolving links against the MCP request language.
- Added the `design_policy.rendered_preview_policy` Site Contract switch with
  fail-closed `required` behavior for existing configurations.
- Made rendered HTML preview the recommended default but skippable for draft
  completion and update-proposal submission when policy is `optional` and the
  user explicitly requests no HTML preview.
- Kept supplied preview tokens revision-bound and validated even in optional
  mode, and exposed the effective policy through MCP descriptions, schemas,
  and runtime capabilities.
- Set the WP Suite development preset to policy 1.0.45 with optional preview
  and existing category/tag assignment for posts plus the four content CPTs.
- Let the recreated frontend body grow with long preview content so body-level
  theme backgrounds remain visible throughout the bounded scrolling viewport.
- Published the corrected viewer under a new v5 resource URI while retaining
  v1-v4 aliases for clients with cached MCP Apps metadata.

## 1.2.4 - 2026-09-10

- Flattened bounded local CSS imports and proxied local stylesheet images plus
  WOFF/WOFF2 fonts through revision-bound preview assets.
- Added import depth, import count, path, MIME, signature, per-file, aggregate,
  and asset-count guards while omitting external or unsupported references.
- Hardened the preview against escaped CSS fetch functions, string-based image
  functions, and passive HTML media elements that could initiate network loads.
- Changed the MCP Apps preview resource to a new cache-key URI so ChatGPT
  refreshes the corrected HTML template instead of reusing a failed lookup.
- Kept the previous v1 and v2 preview resource URIs as compatibility aliases
  that serve the current v3 app for clients with cached tool metadata.
- Allowed revision-bound preview stylesheets and images to finish loading after
  a proposal is submitted or closed, without reopening any write operation.
- Cached each authorized revision's private asset map for 30 minutes so CSS,
  image, and font reads no longer rebuild the rendered preview on every call.
- Deduplicated repeated host deliveries of the same preview, bounded asset
  concurrency, and removed mutable modification timestamps from asset reads.
- Fixed MCP asset parsing when the adapter returns valid top-level content
  beside an empty compatibility result, and combined ordered CSS into one
  private resource instead of issuing one tool call per stylesheet.
- Recreated frontend body and WordPress content wrappers inside the isolated
  viewer, retained safe Gutenberg inline styles, and prioritized explicitly
  selected theme CSS before the compatibility scan and stylesheet limit.
- Kept long rendered pages and warning lists inside bounded, independently
  scrollable areas so an inline preview cannot stretch the conversation page.
- Published the bounded viewer under a new v4 resource URI while retaining
  v1-v3 aliases, preventing clients from reusing the unbounded cached app.
- Made proposal submission require a short-lived, agent- and revision-bound
  token returned only by the final rendered-preview call.
- Marked preview payloads as rendered HTML and removed any residual Gutenberg
  serialization comments before returning the sanitized frontend fragment.
- Enabled governed published-content update proposals for the homepage and
  product Blueprints and accepted the published homepage Playground CTA.
- Updated the public Agent Composer Core contract to 1.2.2 and bundled WP Suite
  Hub 2.5.15 with the Amplify preview.3 Authenticator translation corrections.

## 1.2.3 - 2026-09-09

- Added a private, app-only rendered-preview asset bridge for bounded local
  raster images and sanitized local stylesheets, allowing inline previews to
  retain their assets when the WordPress site is behind a VPN or firewall.
- Isolated preview content and site CSS in a Shadow DOM, removed CSS imports
  and URL references, and bound every opaque asset ID to the exact authorized
  draft revision.
- Fixed inline rendered previews by making the MCP Apps resource readable with
  no input, completing the required UI initialization handshake, and supporting
  ChatGPT's tool-output compatibility path.
- Made the final rendered preview an explicit post-write workflow step in MCP
  server and draft mutation descriptions while retaining URL preview fallback.
- Updated the WP Suite Site Contract to policy 1.0.43, recognizing Contact
  channel cards as a valid markup role without changing their theme styling.
- Added an MCP registration regression test for the governed translation-group
  wrappers while keeping direct localization-provider mutations private.

## 1.2.2 - 2026-09-08

- Added provider-neutral abilities to attach inspected draft or published
  content to an empty translation slot and to merge two exact, disjoint
  translation groups without changing any publication state.
- Added content and relationship concurrency checks, per-member edit checks,
  provider-side exact-map verification, idempotent replay, and verified
  Polylang/WPML rollback behavior.
- Added dedicated Contact, Privacy Policy, Terms of Use, and Blog index
  Blueprints and expanded the Flow form and comparison-table contracts.
- Added governed Query Loop author and sticky modes and target-language cloning.
- Updated the WP Suite Site Contract to support English, Hungarian, German,
  Spanish, and French content, formal address, meaning-preserving translation,
  native terminology, and localized internal-link destinations.

## 1.2.1 - 2026-09-07

- Added a read-only `get-rendered-preview` Ability that returns bounded, sanitized static HTML for an authorized Composer-owned draft with fresh validation and concurrency metadata.
- Added an MCP Apps `text/html;profile=mcp-app` resource so compatible ChatGPT clients can display the rendered draft directly in the conversation.
- Restricted embedded preview assets to the WordPress site and uploads origins, removed active markup and form controls, and kept preview rendering content-scoped without shortcodes, frontend JavaScript, or template parts.
- Refreshed the Composer admin dependency chain and public core contract while retaining React and React DOM 18.3.1 for WordPress compatibility.

## 1.2.0 - 2026-09-02

- Added durable, agent-owned working proposals for updates to published content with human-only review, merge, and rejection.
- Added optimistic source and proposal concurrency checks, transactional merge locks, governed metadata and taxonomy projection, and exact post-merge verification.
- Added a provider-neutral localization contract and manifest-driven admin selector, plus separately installable WPML and Polylang bridges for existing translations and separately authored multilingual drafts; TranslatePress remains outside this release.
- Added Site Contract and Blueprint controls for proposal access and BCP 47 language allowlists.
- Added a dedicated Content proposals administration review surface and proposal-aware status fields.
- Fixed the public core declaration imports for clean NodeNext TypeScript consumers.
- Added `list-supported-content-languages`, which distinguishes authoring policy from provider-backed language switching and localized-draft linking.
- Added immutable BCP 47 language metadata for newly created Composer drafts.
- Added confirmation-gated, optimistic-concurrency protected linking of two or more separately authored Composer-owned drafts.
- Added wildcard language policy support at the Site Contract and Blueprint levels.
- Extended the optional Polylang and WPML bridges to assign configured languages and persist an exact translation relationship without generating copy or publishing content.
- Added cleanup for expired Composer localization and idempotency option locks.
- Fixed published-content proposals so Blueprint language wildcards are honored consistently with draft creation.
- Added confirmation-gated attachment of one Composer-owned draft to an empty language slot in an existing Polylang or WPML translation group. Existing members are preserved regardless of post status, and no content is published.
- Added a human-only request-changes workflow that reopens the same submitted or rejected proposal with a fresh edit revision, an audited reason visible to the assigned agent, and another explicit submission cycle.
- Distinguished Composer new-content drafts from update proposals in ordinary post and custom-post-type lists, hid proposal working and audit copies there by default, and added an explicit list filter that can reveal them with clear state labels.
- Focused Content proposals on ready-for-review work by default and added optional working, rejected, and merged/archive visibility, debounced ID/slug/title search, pagination, proposal and source IDs, and compact language flags.
- Added lossless migration guidance for responsive comparison and data tables through the constrained passive `core/freeform` contract; `core/html` remains forbidden.
- Fixed stale long-running MCP post caches so a proposal created after a human merge uses the committed live source as its concurrency baseline instead of an older revision.
- Moved every proposal working, review, and archive copy to a collision-free internal slug, preserved its intended target slug for editing, audit, and search, prevented proposal copies from being published independently, and kept the live canonical stable during later Gutenberg edits.

## 1.1.2 - 2026-08-26

- Declared compatibility with WordPress 7.1.

## 1.1.1 - 2026-08-14

- Added an atomic, expiring option-backed idempotency lock for WordPress installations using SQLite while retaining MySQL advisory locks.

## 1.1.0 - 2026-08-13

- Added an explicit taxonomy contract and separate term search, creation, draft assignment, and inspection abilities.
- Added exact-slug reuse, confirmation-gated idempotent creation, standalone archive descriptions, and bounded hierarchical parent allowlists.
- Added optimistic-concurrency protected append or replace assignments for Composer-owned drafts only.
- Added dedicated Composer term capabilities without granting broad native term editing or deletion permissions.
- Added registered-taxonomy discovery and guided Site Contract controls for each Blueprint target post type.
- Added concrete MCP input and output schemas plus runtime workflow hints for the complete search-before-create sequence.

## 1.0.4 - 2026-08-13

- Added explicit relation lookup, write, and verification workflow hints to approved field contracts.
- Added concrete MCP output schemas for field contracts, relation-target search, editable-content discovery, and its canonical compatibility alias.
- Clarified that `search-relation-targets` is the Composer operation for resolving titles or slugs to WordPress post IDs.
- Clarified that `list-content-drafts` is limited to editable or adoptable content and cannot resolve relation targets.
- Changed editable-content totals and pagination to apply after Composer policy and WordPress capability filtering.

## 1.0.0 - 2026-07-28

- Fixed guided rule-list editing so spaces and new lines remain available while typing, and added pointer feedback to enabled switches.
- Renamed the bundled shared runtime directory and canonical admin, option, and REST namespace to `smartcloud-wpsuite`, with legacy aliases for rolling upgrades.
- Added optimistic-concurrency protected featured-image assignment for Composer-owned drafts.
- Added optional, Site Contract-governed remote raster ingestion from exact administrator-approved HTTPS hosts.
- Added generic relation-target discovery and ordered relation writes constrained by the active Config Set.
- Added the Composer plugin bootstrap with PHP 8.1 minimum.
- Added versioned configuration entities in one hidden, revision-enabled CPT.
- Added the `smartcloud_agent` role and separated Composer capabilities.
- Added canonical package import and explicit activation receipt boundary.
- Added expanded, redacted, hash-chained SQL audit events.
- Added the frozen Composer execution contract and existing-draft metadata fallback.
- Added the complete 15-page-type WP Suite preset inventory.
- Added a transport-free public TypeScript interface/constants core and React-Vite-Mantine WordPress admin build.
- Added the checksum-pinned Composer execution layer.
- Made explicitly activated Composer configuration authoritative while discovering active-theme capabilities separately.
- Added canonical Composer draft abilities and the Composer MCP endpoint.
- Added real short-lived preview drafts with scheduled cleanup.
- Added shared SmartCloud Hub menu integration, the established responsive admin layout, and contextual DocSidebar help.
- Replaced missing-Ability probes with the WordPress 6.9 `wp_has_ability()` existence API so first registration is notice-free.
- Added config-set create, clone, edit, diff, validation, activation, rollback, import, export, discovery, and audit REST workflows.
- Added complete JSON configuration backup and atomic inactive restore so uninstall does not require rebuilding configuration from scratch.
- Added explicit REST request schemas and pre-execution validation for configuration mutations.
- Added a reproducible WordPress 7.0.2 multisite lifecycle and network-uninstall matrix covering PHP 8.1 through 8.4.
- Added checksum/revision `If-Match` conflicts and site/user/expiry/checksum-bound validation receipts.
- Added guided entity-specific Config Set, Site Contract, and blueprint editors; raw JSON remains an advanced source view for uncommon extension fields.
- Clarified comparison direction as Active to Selected, replaced ambiguous rollback controls with a confirmed archived-set restore flow, and display missing imported timestamps as `N/A`.
- Reworked entity management around an always-visible Config Set context, fixed-height paginated lists, modal staging, unsaved-change protection, confirmed deletion, and atomic Apply or Discard changesets that create at most one revision per affected entity.
- Added the complete server-registered Gutenberg block catalog to blueprint allowlist editing, including Gatey, SmartCloud Flow, SmartCloud AI-Kit, theme, and core blocks when active.
- Removed unpublished theme-contract migration and theme-file configuration fallback; explicitly activated Composer configuration is now the only execution authority.
- Standardized the plugin license as MIT and added the license to the canonical package.
- Added the checksum-built WP Suite Config Set v2 preset and verified its 15 blueprints with the stripped child-theme presentational manifest in an isolated WordPress integration test.
- Expanded the WordPress.org readme with MCP access, active-theme compatibility, External Services, privacy, and build disclosures.
- Enforced required, optional, and disabled excerpt policies consistently across validation, draft creation, updates, inspection, and preview.
- Refresh the aggregate Config Set checksum and latest entity modification time after entity changes, and show successful admin operations as top-right Mantine notifications.
- Ordered the main contract tabs by hierarchy and moved the read-only effective pattern inventory to Theme & providers.
- Added a general quick start covering a stock block theme, local configuration, direct MCP access, the optional Connector tunnel, and draft-only verification.
- Added a collapsible, localStorage-backed first-run Quick tour to the Composer admin.
- Added independent registered-pattern and block-template discovery for ordinary block themes without a Composer manifest.
- Added the three public preset paths: Universal Gutenberg, SmartCloud Recommended, and a safety-filtered Detected Theme Starter. Selection always creates an inactive working Config Set with origin and capability fingerprints.
- Added purpose, structure, portability, and recommendation guidance to the preset chooser and its contextual documentation.
- Added portable Composer-owned core-block patterns and runtime assembly coverage for Universal and Recommended across PHP 8.1 through 8.4.
- Added explicit document and structured-record Blueprint modes. Structured records accept zero sections, retain an empty Gutenberg body, and write only Site Contract-approved registered fields alongside governed draft metadata.
- Added strict BCP 47 content-language enforcement with request echoing, declared exceptions, fallback fingerprint rejection, and blocking checks for substantial mismatches.
- Added deterministic Canvas semantic-slot materialization with structured action and media values, required-slot failures, optional-block removal, and Gutenberg parse/serialize verification.
- Reject Blueprint template targets that declare both an assigned slug and a hierarchy file during Config Set validation, before activation can issue a receipt.
- Added explicit Composer deactivation and complete inactive Config Set deletion with typed stable-ID confirmation, optimistic configuration hashes, a shared lifecycle lock, atomic entity removal, and retained audit history.
- Built-in presets now derive their advisory BCP 47 content language from the current WordPress site locale instead of assuming any concrete language.
- Made every guided Blueprint constraint an explicit page-type override of its Site Contract default, including H1, inline CSS, shortcode, external-embed, theme-preset, and word-ceiling rules; Custom HTML and active script content remain unsupported invariants.
