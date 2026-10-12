# Agent Composer historical upgrade guidance

Full upgrade guidance retained when the WordPress.org summary exceeds its 300-character limit. Read the matching version before updating an older installation.

## 1.3.8

Partial native saves now enforce the same title, excerpt, SEO description, language, and document rules as agent edits. Review and correct invalid existing editorial metadata before editing; omitting content no longer bypasses validation or the published proposal gate. Native consumer plugins can use the shared validators without granting agents additional permissions.

## 1.3.7

Native editing remains blocked until a new, validated Config Set explicitly enables `native_published_edit_policy: browser-editor` on a proposal-only Blueprint. Existing published posts with stale synced-pattern attestations also require exact `native_pattern_revisions` entries in that Config Set; unknown revisions remain blocked and require a reviewed migration.

## 1.3.6

Reload open block editors after updating. Content controls are available in the right Block sidebar's List View tab. Existing per-instance content is retained; no shared-pattern rewrite is required. Registered Blueprint migrations remain proposal-only and require human review before merge. Available blocks remain governed by the site's contract.

## 1.3.4

Restart MCP and refresh client tools and resources to load the v8 preview and publication-review cards with CSP-compatible images. Composer adds a rendered-preview hash to its approval table automatically; pending approval requests created before this update need to be requested again. No content migration is required.

## 1.3.2

Restart MCP and refresh client tools and resources so clients load the v6 preview Apps and the new `field_id` plus `pattern_instance_id` semantic schemas. Existing synced-pattern instances remain readable; Composer persists their compatibility instance ID on the first targeted update. Clone, validate, and activate a new Config Set before using slot `allowed_patterns`, `pattern_occurrences`, or optional pinned `content_hash` values. No content is published or migrated automatically.

## 1.3.1

Restart MCP and refresh client tools after installing the matching Static Publisher update. In Protected or Protected Required mode, map publish/content-sync operators and their status checks plus Publisher target/rule discovery to Publisher; Contributor remains sufficient for crawl/deploy scheduling and status checks. Existing Config Sets keep Publisher media upload disabled. To enable it, clone the active set, enable **Allow Publisher media uploads**, review the shared MIME and size limits, validate and activate it, then restart MCP and refresh the Publisher connector's tools. No new Cognito resource-server scope is required: the operation reuses the existing resource-bound `publish.request` scope. Readers, Contributors, and Open mode remain unable to upload.

## 1.3.0

Install MCP Adapter 0.6.1+. The upgrade adds approval storage/capability but leaves MCP protection Open. Validate and activate a Structure Contract Config Set, synchronize its local patterns, restart MCP, reconnect compatible clients so they discover the inline approval App, and refresh tools. Existing content is not migrated automatically. Before enabling Protected Required, configure a Cognito Hosted UI domain and public code-plus-PKCE client with the exact callback, map groups and client ceilings, migrate any external tunnel from STDIO to HTTP, copy the exact external MCP resource URI into Composer, and complete a test OAuth round trip. Enable scope enforcement only after Cognito uses that same URI as its resource-server identifier and App Client scopes.

