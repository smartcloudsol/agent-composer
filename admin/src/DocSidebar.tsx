import { Anchor, Code, Divider, Drawer, List, Stack, Text, Title } from "@mantine/core";
import { __ } from "@wordpress/i18n";
import "./doc-sidebar.css";

type DocPage = "overview" | "configuration" | "blueprints" | "proposals" | "providers" | "audit";

export type DocTopic =
  | "config-set-metadata"
  | "blueprint-identity"
  | "blueprint-template"
  | "blueprint-patterns"
  | "blueprint-blocks"
  | "blueprint-safety"
  | "blueprint-layout"
  | "blueprint-content"
  | "blueprint-migration"
  | "blueprint-references"
  | "site-identity"
  | "site-language"
  | "site-pattern-scope"
  | "site-safety"
  | "site-brand"
  | "site-layout";

interface DocSidebarProps { opened: boolean; close: () => void; page: DocPage; topic?: DocTopic | null; }

const TEXT_DOMAIN = "smartcloud-agent-composer";

export default function DocSidebar({ opened, close, page, topic = null }: DocSidebarProps) {
  return <Drawer classNames={{ content: "wpsuite-doc-sidebar" }} opened={opened} onClose={close}
    title={__("Agent Composer Documentation", TEXT_DOMAIN)} position="right" size="xl" zIndex={999999}>
    <Stack gap="md">
      {topic ? <FieldDocs topic={topic} /> : <>
        {page === "overview" && <OverviewDocs />}
        {page === "configuration" && <LifecycleDocs />}
        {page === "blueprints" && <EntityDocs />}
        {page === "proposals" && <ProposalDocs />}
        {page === "providers" && <ProviderDocs />}
        {page === "audit" && <AuditDocs />}
      </>}
      <Divider />
      <Text size="sm">{__("Full documentation:", TEXT_DOMAIN)}{" "}<Anchor href="https://wpsuite.io/docs/" target="_blank" rel="noreferrer">https://wpsuite.io/docs/</Anchor></Text>
    </Stack>
  </Drawer>;
}

function ProposalDocs() {
  return <>
    <Title order={2}>{__("Content proposal review", TEXT_DOMAIN)}</Title>
    <Text>{__("An agent proposal is a separate draft linked to one published source. Editing and previewing the proposal never changes the public item.", TEXT_DOMAIN)}</Text>
    <List withPadding spacing="xs" mt="md">
      <List.Item>{__("Only ready-for-review proposals can be merged.", TEXT_DOMAIN)}</List.Item>
      <List.Item>{__("Merge requires a human capability and a WordPress REST nonce; it is not an agent Ability.", TEXT_DOMAIN)}</List.Item>
      <List.Item>{__("Any source, proposal revision, Blueprint, or localization conflict stops the merge.", TEXT_DOMAIN)}</List.Item>
    </List>
  </>;
}

function OverviewDocs() {
  return <>
    <Title order={2}>{__("Composer overview", TEXT_DOMAIN)}</Title>
    <Text>{__("SmartCloud Agent Composer is the WordPress plugin layer of a governed, agent-assisted content production solution. It uses the active Config Set and the active theme's discovered or declared design capabilities to produce validated Gutenberg drafts that follow the configured Site Contract and page-type Blueprints.", TEXT_DOMAIN)}</Text>
    <Title order={3} mt="md">{__("Safety boundary", TEXT_DOMAIN)}</Title>
    <List withPadding spacing="xs">
      <List.Item>{__("Agent abilities create and update drafts or separate working proposals; they never merge into published content.", TEXT_DOMAIN)}</List.Item>
      <List.Item>{__("Publishing, normal-content deletion, theme editing, plugin management, and media upload are not exposed.", TEXT_DOMAIN)}</List.Item>
      <List.Item>{__("Configuration mutations require an authenticated administrator, a REST nonce, and the exact Composer capability.", TEXT_DOMAIN)}</List.Item>
      <List.Item>{__("Every write is recorded in the redacted, hash-chained audit log.", TEXT_DOMAIN)}</List.Item>
    </List>
    <Title order={3} mt="md">{__("Execution contract", TEXT_DOMAIN)}</Title>
    <Text>{__("The execution engine is part of Composer and is checksum-pinned so code drift is detected during tests and packaging.", TEXT_DOMAIN)}</Text>
  </>;
}

function LifecycleDocs() {
  return <>
    <Title order={2}>{__("Config set lifecycle", TEXT_DOMAIN)}</Title>
    <List withPadding spacing="xs">
      <List.Item><strong>{__("Editable", TEXT_DOMAIN)}</strong>{__(" is the UI label for the internal working state: an inactive Config Set whose entities can still be changed.", TEXT_DOMAIN)}</List.Item>
      <List.Item><strong>{__("Validated", TEXT_DOMAIN)}</strong>{__(" passed whole-set validation but is still inactive. Editing it returns it to Editable until it is validated again.", TEXT_DOMAIN)}</List.Item>
      <List.Item><strong>{__("Active", TEXT_DOMAIN)}</strong>{__(" is the immutable configuration currently used by draft execution.", TEXT_DOMAIN)}</List.Item>
      <List.Item><strong>{__("Archived", TEXT_DOMAIN)}</strong>{__(" is a previously active configuration. Composer archives it automatically when another validated Config Set becomes active; there is no separate manual archive action.", TEXT_DOMAIN)}</List.Item>
      <List.Item>{__("Archived rows are hidden by default. Enable Show archived to inspect, compare, export, or restore one as active.", TEXT_DOMAIN)}</List.Item>
    </List>
    <Title order={3} mt="md">{__("Starting presets", TEXT_DOMAIN)}</Title>
    <List withPadding spacing="xs">
      <List.Item><strong>{__("Universal Gutenberg", TEXT_DOMAIN)}</strong>{__(" is the smallest and most portable option. Use it for maximum compatibility, a first integration test, or a deliberately minimal page structure.", TEXT_DOMAIN)}</List.Item>
      <List.Item><strong>{__("SmartCloud Recommended", TEXT_DOMAIN)}</strong>{__(" is the default choice for most sites. It adds a portable hero, feature grid, steps, optional FAQ, and closing action while remaining independent of one theme.", TEXT_DOMAIN)}</List.Item>
      <List.Item><strong>{__("Detected Theme Starter", TEXT_DOMAIN)}</strong>{__(" is generated only when the active theme exposes a safe full-page pattern. It preserves more of that theme's visual language, is site-local, and records the theme and capability fingerprints used to create it.", TEXT_DOMAIN)}</List.Item>
      <List.Item>{__("Every choice creates a new inactive, editable Config Set. No preset overwrites or activates configuration.", TEXT_DOMAIN)}</List.Item>
    </List>
    <List type="ordered" withPadding spacing="xs">
      <List.Item>{__("Create an editable Config Set or clone an immutable active set.", TEXT_DOMAIN)}</List.Item>
      <List.Item>{__("Edit independent revisioned entities.", TEXT_DOMAIN)}</List.Item>
      <List.Item>{__("Validate the complete set against current theme and provider capabilities.", TEXT_DOMAIN)}</List.Item>
      <List.Item>{__("Activate only after a fresh site-, user-, expiry-, and checksum-bound validation receipt is issued.", TEXT_DOMAIN)}</List.Item>
      <List.Item>{__("Restore archived as active is the rollback operation: the selected archived set is revalidated, becomes active, and the current active set is archived.", TEXT_DOMAIN)}</List.Item>
      <List.Item>{__("Deactivate Composer clears the active configuration pointer without deleting entities. An inactive Config Set can then be permanently deleted only after its complete stable ID is typed and its current configuration hash still matches.", TEXT_DOMAIN)}</List.Item>
      <List.Item>{__("Config Set deletion removes every nested configuration entity but retains the append-only audit history. Active Config Sets can never be deleted directly.", TEXT_DOMAIN)}</List.Item>
    </List>
    <Title order={3} mt="md">{__("Compare to active", TEXT_DOMAIN)}</Title>
    <Text>{__("The comparison is shown in the direction Active → Selected. Added means the selected set introduces an entity, removed means the selected set omits an active entity, and changed means the same entity has different content. The comparison itself never changes configuration.", TEXT_DOMAIN)}</Text>
    <Title order={3} mt="md">{__("Missing timestamps", TEXT_DOMAIN)}</Title>
    <Text>{__("N/A means the imported source did not contain a usable modification timestamp. It has no effect on checksums, validation, or activation.", TEXT_DOMAIN)}</Text>
    <Title order={3} mt="md">{__("Conflict protection", TEXT_DOMAIN)}</Title>
    <Text>{__("Every save sends the last entity revision and content hash through If-Match. A concurrent change returns HTTP 409 and is never overwritten automatically.", TEXT_DOMAIN)}</Text>
  </>;
}

function EntityDocs() {
  return <>
    <Title order={2}>{__("Configuration and blueprints", TEXT_DOMAIN)}</Title>
    <Text>{__("A Config Set contains exactly one Config Set manifest and exactly one Site Contract. Those two required entities appear as settings cards; the variable-length page-type Blueprint collection appears below them in a paginated list.", TEXT_DOMAIN)}</Text>
    <Title order={3} mt="md">{__("Active configuration", TEXT_DOMAIN)}</Title>
    <Text>{__("Active entities are immutable but remain fully readable. Their controls use normal-contrast read-only fields; clone the Config Set when you need an editable working copy.", TEXT_DOMAIN)}</Text>
    <Title order={3} mt="md">{__("Guided editor", TEXT_DOMAIN)}</Title>
    <Text>{__("The selected Config Set remains visible above its settings and Blueprint list. Edit opens an isolated dialog: Stage change adds the result to one local changeset, while closing an unstaged editor requires explicit discard confirmation. Apply modifications writes the complete changeset, creates at most one new revision for each affected entity, records one changeset audit event, and returns the Config Set to working state until it is validated again.", TEXT_DOMAIN)}</Text>
    <Text>{__("The Blueprint list has a stable viewport and persistent page controls. Blueprint additions, edits, and confirmed deletions remain reversible with Discard until Apply modifications. The required Config Set manifest and Site Contract can be edited but not deleted from this view. Export first when you need a portable recovery copy.", TEXT_DOMAIN)}</Text>
    <Text>{__("The guided editor exposes the fields used most often and preserves every unknown extension field. Advanced JSON source is available for uncommon nested data, but it must be applied back to the form before saving.", TEXT_DOMAIN)}</Text>
    <Title order={3} mt="md">{__("Site Contract", TEXT_DOMAIN)}</Title>
    <Text>{__("Owns site-wide brand, language, content, SEO, media, accessibility, security, layout, and block-extension policy, plus defaults inherited by Blueprints without explicit page-type values.", TEXT_DOMAIN)}</Text>
      <Text>{__("Composer content access is managed in the guided Site Contract editor per registered post type. Discover exposes list metadata, Read permits content analysis, Clone creates a separate agent-owned draft, and Adopt permits an explicit takeover only while the original item is a draft. WordPress capabilities and a matching Blueprint remain mandatory for every operation.", TEXT_DOMAIN)}</Text>
      <Text>{__("Composer field access is a second, field-level gate. Discovery lists only public, single-value, REST-registered fields. Read and Write draft must be enabled explicitly for each key; field writes remain limited to Composer-owned assigned drafts and require fresh concurrency tokens plus confirmation.", TEXT_DOMAIN)}</Text>
      <Text>{__("Composer taxonomy access governs public terms separately for each Blueprint target and registered taxonomy. Search is the base permission, assignment additionally changes only an agent-owned draft, and creation is the narrowest global permission. Creating implies assignment and search; assignment implies search. Maximum terms, append or replace behavior, and hierarchical creation parents are explicit Site Contract policy.", TEXT_DOMAIN)}</Text>
    <Title order={3} mt="md">{__("Blueprint", TEXT_DOMAIN)}</Title>
    <Text>{__("Owns one page type: WordPress target, template assignment, visual variant, allowed patterns and blocks, required pattern order, word boundary, reference sources, and content or migration rules.", TEXT_DOMAIN)}</Text>
    <Text>{__("A Detected Theme Starter is only a safe beginning. Clone or edit its inactive Config Set, add page-type Blueprints, extend their approved pattern and block contracts, and refine the Site Contract to reach the level of integration the active theme and installed providers can actually support.", TEXT_DOMAIN)}</Text>
    <Text>{__("Allowed Blocks searches every Gutenberg block currently registered by WordPress, the active theme, and active plugins. Provider abilities are operations rather than blocks and therefore appear in provider discovery, not in this block selector. Existing saved block names remain visible even when their plugin is temporarily unavailable.", TEXT_DOMAIN)}</Text>
    <Title order={3} mt="md">{__("Excerpt policy", TEXT_DOMAIN)}</Title>
    <List withPadding spacing="xs">
      <List.Item><Code>required</Code> {__("requires a WordPress excerpt containing 80 to 300 characters.", TEXT_DOMAIN)}</List.Item>
      <List.Item><Code>optional</Code> {__("accepts an empty value or an excerpt containing 80 to 300 characters.", TEXT_DOMAIN)}</List.Item>
      <List.Item><Code>disabled</Code> {__("requires the excerpt to remain empty for that blueprint.", TEXT_DOMAIN)}</List.Item>
    </List>
    <Title order={3} mt="md">{__("Patterns", TEXT_DOMAIN)}</Title>
    <List withPadding spacing="xs">
      <List.Item>{__("Pattern markup may come from the active theme or from Composer's portable core-block starter patterns. Ownership and source remain visible in discovery.", TEXT_DOMAIN)}</List.Item>
      <List.Item>{__("Blueprints list which pattern slugs Composer may use and which ordered subset is required.", TEXT_DOMAIN)}</List.Item>
      <List.Item>{__("Theme & providers combines those blueprint references and checks them against the active theme manifest when it declares a pattern inventory.", TEXT_DOMAIN)}</List.Item>
      <List.Item>{__("Composer assembles registered theme patterns, replaces approved slots, and validates the resulting Gutenberg block tree before creating or updating a draft.", TEXT_DOMAIN)}</List.Item>
    </List>
    <Title order={3} mt="md">{__("Discovery snapshot", TEXT_DOMAIN)}</Title>
    <Text>{__("A read-only observation of the current theme and providers. Create a fresh snapshot with Rescan site; do not edit old observations.", TEXT_DOMAIN)}</Text>
    <Title order={3} mt="md">{__("Advanced JSON", TEXT_DOMAIN)}</Title>
    <List withPadding spacing="xs">
      <List.Item>{__("Use it only for nested fields the guided form does not expose.", TEXT_DOMAIN)}</List.Item>
      <List.Item>{__("Choose Apply JSON to form before saving so the guided view and source view agree.", TEXT_DOMAIN)}</List.Item>
      <List.Item>{__("Unknown fields are retained; changing a guided field does not replace the rest of the payload.", TEXT_DOMAIN)}</List.Item>
    </List>
    <Text>{__("Saving any entity invalidates the previous whole-set validation. Active entities are immutable and must be cloned before editing.", TEXT_DOMAIN)}</Text>
  </>;
}

function FieldDocs({ topic }: { topic: DocTopic }) {
  const content: Record<DocTopic, { title: string; intro: string; items: string[]; example?: string }> = {
    "config-set-metadata": {
      title: __("Config Set metadata", TEXT_DOMAIN),
      intro: __("These fields describe where this version came from and how strictly Composer should interpret it. They do not activate the set and do not change its checksum or lifecycle directly.", TEXT_DOMAIN),
      items: [
        __("Label is the human-readable version name.", TEXT_DOMAIN),
        __("Fully contracted means the Site Contract and Blueprints define the complete behavior; universal is intended for a smaller portable contract.", TEXT_DOMAIN),
        __("Strict fallback rejects undeclared theme structures. Allow theme fallback permits safe discovered theme capabilities when the Blueprint does not provide a more specific mapping.", TEXT_DOMAIN),
        __("Source theme values are provenance. Revalidate the Config Set after changing themes even when those fields still match.", TEXT_DOMAIN)
      ]
    },
    "blueprint-identity": {
      title: __("Blueprint identity and purpose", TEXT_DOMAIN),
      intro: __("One Blueprint defines one semantic page type and the WordPress object Composer may create as an agent-owned draft.", TEXT_DOMAIN),
      items: [
        __("Keep the page type key stable, lowercase, and machine-readable, for example landing-page, product, article, or case-study.", TEXT_DOMAIN),
        __("Target post type must already be registered on this site. Use page for ordinary pages or a real CPT slug when the active installation provides one.", TEXT_DOMAIN),
        __("Document mode assembles a governed Gutenberg body. Structured-record mode keeps the body empty and accepts only the title, excerpt, and Site Contract-approved registered fields.", TEXT_DOMAIN),
        __("Public visibility is independent of composition mode: a public structured record can be rendered by a shared template, while a document remains draft-only until a human publishes it.", TEXT_DOMAIN),
        __("Visual variant is a semantic presentation hint, not a CSS class. Use a stable name the theme mapping can understand.", TEXT_DOMAIN),
        __("Purpose should describe the page's editorial job and intended reader outcome rather than visual styling.", TEXT_DOMAIN)
      ],
      example: __("Explain a service clearly, establish trust with factual evidence, and guide the reader to one relevant next step.", TEXT_DOMAIN)
    },
    "blueprint-template": {
      title: __("Target template", TEXT_DOMAIN),
      intro: __("Template assignment controls the WordPress block template used around the generated content.", TEXT_DOMAIN),
      items: [
        __("Hierarchy lets WordPress choose through its normal template hierarchy.", TEXT_DOMAIN),
        __("Assigned pins a registered custom template slug, such as page-no-title, when the Blueprint content owns the page H1.", TEXT_DOMAIN),
        __("Use a no-title template when the assembled pattern sequence already contains the single required H1.", TEXT_DOMAIN)
      ]
    },
    "blueprint-patterns": {
      title: __("Allowed and required patterns", TEXT_DOMAIN),
      intro: __("Allowed patterns form the palette Composer may assemble. Required sequence is the ordered minimum skeleton every candidate must contain.", TEXT_DOMAIN),
      items: [
        __("Patterns and a required sequence apply to document Blueprints. Structured-record Blueprints must leave pattern and block composition empty.", TEXT_DOMAIN),
        __("Use exact registered slugs in namespace/pattern-name form.", TEXT_DOMAIN),
        __("Every required pattern must also be allowed.", TEXT_DOMAIN),
        __("Keep optional sections only in Allowed patterns; put structural essentials such as hero and closing action in Required sequence.", TEXT_DOMAIN),
        __("Theme-owned patterns improve visual fidelity. Composer portable patterns improve portability between themes.", TEXT_DOMAIN)
      ],
      example: "theme-slug/hero\ntheme-slug/features\ntheme-slug/call-to-action"
    },
    "blueprint-blocks": {
      title: __("Allowed blocks", TEXT_DOMAIN),
      intro: __("This allowlist is the final Gutenberg block boundary for the page type. Candidate markup containing another block is rejected.", TEXT_DOMAIN),
      items: [
        __("The selector contains blocks currently registered by WordPress, the theme, and active plugins.", TEXT_DOMAIN),
        __("Include every block used by the allowed patterns, including nested layout blocks such as Group, Columns, and Column.", TEXT_DOMAIN),
        __("A provider ability is an operation, not a block. Provider availability is managed under Theme & providers.", TEXT_DOMAIN)
      ]
    },
    "blueprint-safety": {
      title: __("Blueprint constraints", TEXT_DOMAIN),
      intro: __("Blueprint constraints explicitly override the Site Contract defaults for one page type. Composer reports and validates the resulting Blueprint values without silently tightening or loosening them.", TEXT_DOMAIN),
      items: [
        __("Exactly one H1 prevents missing or duplicated page titles.", TEXT_DOMAIN),
        __("Theme presets only rejects arbitrary inline presentation values and keeps colors, spacing, and typography aligned with the theme.", TEXT_DOMAIN),
        __("Enable shortcodes, inline CSS, or external embeds only when the target site deliberately supports and audits them. Composer never accepts Custom HTML blocks or active script content.", TEXT_DOMAIN),
        __("Maximum words is a validation ceiling, while excerpt policy independently controls the 80 to 300 character WordPress excerpt.", TEXT_DOMAIN),
        __("Advanced Blueprint SEO and block-extension objects also override inherited values; explicit lists replace inherited lists instead of merging by index.", TEXT_DOMAIN)
      ]
    },
    "blueprint-layout": {
      title: __("Layout rules", TEXT_DOMAIN),
      intro: __("Write one observable and enforceable presentation requirement per line. Describe structure and token use, not subjective goals such as make it beautiful.", TEXT_DOMAIN),
      items: [
        __("Refer to approved patterns, semantic regions, heading order, content width, media placement, or theme preset usage.", TEXT_DOMAIN),
        __("Do not paste CSS declarations here. The theme owns presentation and Composer validates the resulting structure.", TEXT_DOMAIN)
      ],
      example: __("Use one full-width hero before the main content sections.\nKeep explanatory media inside the content rail.\nUse only theme preset colors and spacing values.", TEXT_DOMAIN)
    },
    "blueprint-content": {
      title: __("Content rules", TEXT_DOMAIN),
      intro: __("Write one editorial, evidence, SEO, or reader-safety requirement per line. These rules guide planning and are available during candidate validation.", TEXT_DOMAIN),
      items: [
        __("Prefer requirements that can be reviewed in the resulting draft.", TEXT_DOMAIN),
        __("State how claims, calls to action, terminology, links, and source attribution should be handled.", TEXT_DOMAIN),
        __("Global brand rules belong in the Site Contract; keep this list specific to the page type.", TEXT_DOMAIN)
      ],
      example: __("Use one descriptive H1 and a clear heading hierarchy.\nSupport factual claims with an approved source.\nEnd with one relevant next step for the reader.", TEXT_DOMAIN)
    },
    "blueprint-migration": {
      title: __("Migration rules", TEXT_DOMAIN),
      intro: __("These rules apply when existing content is rebuilt into the Blueprint. Leave the list empty when this page type is only used for new content.", TEXT_DOMAIN),
      items: [
        __("Specify what must be preserved, what may be normalized, and how old structures map to approved patterns.", TEXT_DOMAIN),
        __("Preserve supported facts, URLs, attribution, and media relationships unless an explicit rule says otherwise.", TEXT_DOMAIN),
        __("Migration does not authorize publishing or deletion; the result remains an agent-owned draft.", TEXT_DOMAIN)
      ],
      example: __("Preserve the existing slug, topic, and supported factual claims.\nMap legacy sections to the closest approved pattern.\nRetain relevant media and source attribution.", TEXT_DOMAIN)
    },
    "blueprint-references": {
      title: __("Reference URLs", TEXT_DOMAIN),
      intro: __("Reference URLs identify approved source material for this page type. They are context sources, not instructions to copy text or design.", TEXT_DOMAIN),
      items: [
        __("Use stable HTTPS URLs that the configured agent environment is permitted to access.", TEXT_DOMAIN),
        __("Prefer authoritative product, policy, research, or editorial sources.", TEXT_DOMAIN),
        __("A URL does not override the Site Contract, Blueprint constraints, copyright rules, or draft-only boundary.", TEXT_DOMAIN)
      ]
    },
    "site-identity": {
      title: __("Site Contract identity", TEXT_DOMAIN),
      intro: __("The label identifies this contract to people. Policy name and version identify the design-policy revision used by validation and portability workflows.", TEXT_DOMAIN),
      items: [
        __("Use a stable policy name and increment its version when the policy meaning changes.", TEXT_DOMAIN),
        __("Do not put credentials, environment secrets, or private URLs in identity fields.", TEXT_DOMAIN)
      ]
    },
    "site-language": {
      title: __("Languages and global word limit", TEXT_DOMAIN),
      intro: __("The Site Contract content language is authoritative for generated public copy; operator language remains an administrative preference.", TEXT_DOMAIN),
      items: [
        __("Use a BCP 47 tag such as en-US, de-DE, or hu-HU. Every strict execution request must echo the effective value exactly.", TEXT_DOMAIN),
        __("Strict mode rejects known theme fallback copy and blocking substantial language mismatches before any draft write.", TEXT_DOMAIN),
        __("Declare durable brand names, technical terms, citations, and reviewed foreign-language fragments as explicit exceptions.", TEXT_DOMAIN),
        __("A Blueprint may inherit or narrow the Site Contract locale, but it cannot replace it with another primary language.", TEXT_DOMAIN),
        __("The Site Contract word maximum is the inherited default; a Blueprint may define its own page-type ceiling.", TEXT_DOMAIN)
      ]
    },
    "site-pattern-scope": {
      title: __("Pattern namespaces and disallowed blocks", TEXT_DOMAIN),
      intro: __("These lists establish the site-wide pattern and block boundary inherited by every Blueprint.", TEXT_DOMAIN),
      items: [
        __("Allow only namespaces owned by trusted themes, Composer, or approved plugins.", TEXT_DOMAIN),
        __("Disallowed blocks always win over a Blueprint allowlist.", TEXT_DOMAIN),
        __("Revalidate after installing, removing, activating, or changing a block-providing plugin or theme.", TEXT_DOMAIN)
      ]
    },
    "site-safety": {
      title: __("Default safety constraints", TEXT_DOMAIN),
      intro: __("These structural and markup defaults are inherited only when a Blueprint does not define an explicit value.", TEXT_DOMAIN),
      items: [
        __("Keep exactly one H1 and theme presets only enabled for the safest portable default.", TEXT_DOMAIN),
        __("Shortcodes, inline CSS, and external embeds expand the attack and portability surface and should remain disabled unless required. Custom HTML and active script content remain unsupported invariants.", TEXT_DOMAIN),
        __("Each Blueprint may explicitly replace these defaults for its own content type.", TEXT_DOMAIN)
      ]
    },
    "site-brand": {
      title: __("Brand tone and prohibited content", TEXT_DOMAIN),
      intro: __("Tone describes durable writing characteristics. Avoid lists claims, habits, or language that must not appear anywhere in generated drafts.", TEXT_DOMAIN),
      items: [
        __("Use short, testable characteristics such as direct, precise, calm, or technically credible.", TEXT_DOMAIN),
        __("Put unsupported superlatives, invented statistics, manipulative urgency, and prohibited terminology in Avoid.", TEXT_DOMAIN),
        __("Blueprint content rules may add page-specific guidance without replacing these global rules.", TEXT_DOMAIN)
      ],
      example: __("Tone: clear, direct, evidence-led\nAvoid: unsupported guarantees, invented metrics, artificial urgency", TEXT_DOMAIN)
    },
    "site-layout": {
      title: __("Global layout rules", TEXT_DOMAIN),
      intro: __("Global layout rules describe presentational invariants every Blueprint must respect, independently of one page type.", TEXT_DOMAIN),
      items: [
        __("Use semantic requirements such as one content root, theme preset values, consistent content width, and accessible heading order.", TEXT_DOMAIN),
        __("Keep page-specific section order in the Blueprint instead.", TEXT_DOMAIN)
      ],
      example: __("Use one Gutenberg content root.\nUse only active-theme preset colors, typography, and spacing.\nKeep heading levels sequential below the single page H1.", TEXT_DOMAIN)
    }
  };
  const selected = content[topic];
  return <>
    <Title order={2}>{selected.title}</Title>
    <Text>{selected.intro}</Text>
    <List withPadding spacing="xs">{selected.items.map((item) => <List.Item key={item}>{item}</List.Item>)}</List>
    {selected.example && <><Title order={3} mt="md">{__("Example", TEXT_DOMAIN)}</Title><Code block>{selected.example}</Code></>}
  </>;
}

function ProviderDocs() {
  return <>
    <Title order={2}>{__("Theme and provider discovery", TEXT_DOMAIN)}</Title>
    <List withPadding spacing="xs">
      <List.Item>{__("Theme name, version, parent, and optional read-only presentational manifest.", TEXT_DOMAIN)}</List.Item>
      <List.Item>{__("Registered block templates and pattern metadata discovered through WordPress even when the theme has no Composer manifest.", TEXT_DOMAIN)}</List.Item>
      <List.Item>{__("Registered taxonomies per content type, including hierarchy, public UI and REST visibility, and the current user's assignment and creation capabilities.", TEXT_DOMAIN)}</List.Item>
      <List.Item>{__("Provider-owned WordPress Ability profiles and runtime readiness.", TEXT_DOMAIN)}</List.Item>
      <List.Item>{__("Separate theme, provider, and combined site capability fingerprints.", TEXT_DOMAIN)}</List.Item>
      <List.Item>{__("Immutable discovery snapshots created only by an explicit rescan.", TEXT_DOMAIN)}</List.Item>
    </List>
    <Text>{__("Theme manifests cannot contain credentials, executable callbacks, prompts, external URLs, or provider runtime configuration.", TEXT_DOMAIN)}</Text>
  </>;
}

function AuditDocs() {
  return <>
    <Title order={2}>{__("Audit and portability", TEXT_DOMAIN)}</Title>
    <List withPadding spacing="xs">
      <List.Item>{__("Audit inputs are represented by canonical hashes and secret-like values are redacted.", TEXT_DOMAIN)}</List.Item>
      <List.Item>{__("A complete backup contains every config set and nested entity checksum, but no credentials or audit history.", TEXT_DOMAIN)}</List.Item>
      <List.Item>{__("Restore accepts local JSON only, rejects secret-like keys, verifies every checksum, and keeps every set inactive.", TEXT_DOMAIN)}</List.Item>
      <List.Item>{__("A failed backup restore removes every entity created by the complete attempt.", TEXT_DOMAIN)}</List.Item>
      <List.Item>{__("Export a backup before uninstalling if you may want to restore the configuration later.", TEXT_DOMAIN)}</List.Item>
    </List>
    <Title order={3} mt="md">{__("External services", TEXT_DOMAIN)}</Title>
    <Text>{__("Core execution stays in WordPress. The packaged shared WP Suite Hub may optionally connect to WPSuite.io, Amazon Cognito, and Stripe. Provider Abilities may contact only the service disclosed by their own plugin.", TEXT_DOMAIN)}</Text>
  </>;
}
