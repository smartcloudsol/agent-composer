import {
  ActionIcon,
  Accordion,
  Alert,
  Button,
  Card,
  Checkbox,
  Code,
  Group,
  MultiSelect,
  NumberInput,
  Select,
  SimpleGrid,
  Stack,
  Switch,
  TagsInput,
  Text,
  Textarea,
  TextInput,
  Title,
  Tooltip
} from "@mantine/core";
import { IconHelpCircle } from "@tabler/icons-react";
import { __ } from "@wordpress/i18n";
import { useEffect, useState } from "react";
import type { ConfigEntity, ProviderDiscovery } from "./api";
import type { DocTopic } from "./DocSidebar";

const TEXT_DOMAIN = "smartcloud-agent-composer";
type Payload = Record<string, unknown>;

interface EntityEditorProps {
  selected: ConfigEntity;
  immutable: boolean;
  save: (payload: Payload) => Promise<void>;
  blocks?: ProviderDiscovery["registered_blocks"];
  postTypes?: ProviderDiscovery["registered_post_types"];
  blueprints?: ConfigEntity[];
  onDirtyChange?: (dirty: boolean) => void;
  help?: (topic: DocTopic) => void;
}

export default function EntityEditor({ selected, immutable, save, blocks = [], postTypes = [], blueprints = [], onDirtyChange, help }: EntityEditorProps) {
  const [payload, setPayload] = useState<Payload>(selected.payload);
  const [json, setJson] = useState(JSON.stringify(selected.payload, null, 2));
  const [jsonError, setJsonError] = useState("");
  const formattedPayload = JSON.stringify(payload, null, 2);
  const sourcePending = json !== formattedPayload;
  const dirty = JSON.stringify(payload) !== JSON.stringify(selected.payload) || sourcePending;

  useEffect(() => { onDirtyChange?.(dirty); }, [dirty, onDirtyChange]);

  const update = (path: string[], value: unknown) => {
    const next = setNested(payload, path, value);
    setPayload(next);
    setJson(JSON.stringify(next, null, 2));
    setJsonError("");
  };

  const applyJson = () => {
    try {
      const parsed: unknown = JSON.parse(json);
      if (!parsed || typeof parsed !== "object" || Array.isArray(parsed)) throw new Error();
      setPayload(parsed as Payload);
      setJson(JSON.stringify(parsed, null, 2));
      setJsonError("");
    } catch {
      setJsonError(__("Enter a valid JSON object before applying the source view.", TEXT_DOMAIN));
    }
  };

  return <Card withBorder radius="md" p="md"><Stack gap="md">
    <Group justify="space-between" align="flex-start" wrap="wrap">
      <div><Text fw={700}>{selected.label}</Text><Text size="sm" c="dimmed">{`${entityTypeLabel(selected.type)} · ${__("revision", TEXT_DOMAIN)} ${selected.entity_revision}`}</Text></div>
      <Code>{selected.content_hash}</Code>
    </Group>
    {selected.type === "blueprint" && <BlueprintFields payload={payload} update={update} immutable={immutable} blocks={blocks} help={help} />}
    {selected.type === "site-contract" && <SiteContractFields payload={payload} update={update} immutable={immutable} help={help} postTypes={postTypes} blueprints={blueprints} />}
    {selected.type === "config-set" && <ConfigSetFields payload={payload} update={update} immutable={immutable} help={help} />}
    {["component", "style-mapping", "provider-policy"].includes(selected.type) &&
      <GenericFields payload={payload} update={update} immutable={immutable} type={selected.type} />}
    {selected.type === "discovery" && <Alert color="blue">{__("Discovery snapshots are read-only observations. Run a site rescan to create a new snapshot instead of editing this entity.", TEXT_DOMAIN)}</Alert>}

    <Accordion variant="contained">
      <Accordion.Item value="advanced-json">
        <Accordion.Control>{__("Advanced JSON source", TEXT_DOMAIN)}</Accordion.Control>
        <Accordion.Panel><Stack gap="sm">
          <Text size="sm" c="dimmed">{__("Use this only for fields that are not exposed by the guided editor. Apply the source to refresh the form before saving.", TEXT_DOMAIN)}</Text>
          <Textarea aria-label={__("Advanced entity JSON", TEXT_DOMAIN)} value={json} onChange={(event) => setJson(event.currentTarget.value)} autosize minRows={12} maxRows={32} readOnly={immutable || selected.type === "discovery"} styles={{ input: { fontFamily: "monospace" } }} />
          {jsonError && <Alert color="red">{jsonError}</Alert>}
          <Group><Button variant="default" disabled={immutable || selected.type === "discovery"} onClick={applyJson}>{__("Apply JSON to form", TEXT_DOMAIN)}</Button></Group>
        </Stack></Accordion.Panel>
      </Accordion.Item>
    </Accordion>

    <Group>
      <Button disabled={immutable || selected.type === "discovery" || !dirty || sourcePending || Boolean(jsonError)} onClick={() => save(payload)}>{__("Stage change", TEXT_DOMAIN)}</Button>
      <Text size="sm" c={dirty ? "orange.8" : "dimmed"}>{immutable ? __("Clone the active set to edit this entity.", TEXT_DOMAIN) : sourcePending ? __("Apply the Advanced JSON source before staging, or restore it to match the form.", TEXT_DOMAIN) : dirty ? __("Unstaged changes exist only in this dialog.", TEXT_DOMAIN) : __("No unstaged changes. Applying the final changeset creates the entity revision.", TEXT_DOMAIN)}</Text>
    </Group>
  </Stack></Card>;
}

interface FieldsProps {
  payload: Payload;
  update: (path: string[], value: unknown) => void;
  immutable: boolean;
  help?: (topic: DocTopic) => void;
}

function BlueprintFields({ payload, update, immutable, blocks, help }: FieldsProps & { blocks: ProviderDiscovery["registered_blocks"] }) {
	const compositionMode = stringAt(payload, ["composition_mode"]) || "document";
  const templateFile = stringAt(payload, ["target_template", "file"]);
  const templateSlug = stringAt(payload, ["target_template", "slug"]);
  const savedTemplateMode = stringAt(payload, ["target_template", "mode"]);
  const templateMode = templateFile || savedTemplateMode === "hierarchy" ? "hierarchy" : "assigned";
  const templateValue = templateMode === "hierarchy" ? templateFile : templateSlug;
  const updateTemplate = (mode: "hierarchy" | "assigned", value: string) => {
    const label = stringAt(payload, ["target_template", "label"]);
    update(["target_template"], mode === "hierarchy"
      ? { label, mode, file: value }
      : { label, mode: value === "default" ? "default" : mode, slug: value });
  };
  const switchTemplateMode = (mode: "hierarchy" | "assigned") => {
    if (mode === templateMode) return;
    const converted = mode === "hierarchy"
      ? `templates/${(templateSlug || "single").replace(/^templates\//, "").replace(/\.html$/, "")}.html`
      : (templateFile || "default").replace(/^templates\//, "").replace(/\.html$/, "");
    updateTemplate(mode, converted);
  };
  const constraintFields = [
    ["exactly_one_h1", __("Require exactly one H1", TEXT_DOMAIN)],
    ["inline_css", __("Allow inline CSS", TEXT_DOMAIN)],
    ["shortcodes", __("Allow shortcodes", TEXT_DOMAIN)],
    ["external_embeds", __("Allow external embeds", TEXT_DOMAIN)],
    ["theme_presets_only", __("Require theme presets", TEXT_DOMAIN)]
  ] as const;

  const selectedBlocks = stringsAt(payload, ["allowed_blocks"]);
  const blockOptions = (() => {
	const known = blocks.filter((block) => block.name !== "core/html").map((block) => ({ value: block.name, label: `${block.title || block.name} (${block.name})` }));
    const names = new Set(known.map((item) => item.value));
    return [...known, ...selectedBlocks.filter((name) => !names.has(name)).map((name) => ({ value: name, label: `${name} (${__("saved but not currently registered", TEXT_DOMAIN)})` }))];
  })();

  return <Stack gap="md">
    <EditorIntro title={__("Blueprint", TEXT_DOMAIN)} text={__("Defines one content type: where drafts are created, which patterns and blocks are allowed, their required order, and the content constraints Composer validates.", TEXT_DOMAIN)} />
    <SimpleGrid cols={{ base: 1, sm: 2 }}>
      <TextInput label={__("Display label", TEXT_DOMAIN)} description={fieldDescription(__("Human-readable name shown in Composer.", TEXT_DOMAIN), "blueprint-identity", help)} placeholder={__("Service landing page", TEXT_DOMAIN)} value={stringAt(payload, ["label"])} onChange={(event) => update(["label"], event.currentTarget.value)} readOnly={immutable} />
      <TextInput label={__("Page type key", TEXT_DOMAIN)} description={fieldDescription(__("Stable key used by draft abilities.", TEXT_DOMAIN), "blueprint-identity", help)} placeholder="service" value={stringAt(payload, ["page_type"])} onChange={(event) => update(["page_type"], event.currentTarget.value)} readOnly={immutable} />
      <TextInput label={__("Target post type", TEXT_DOMAIN)} description={fieldDescription(__("Registered WordPress post type that receives the draft.", TEXT_DOMAIN), "blueprint-identity", help)} placeholder="page" value={stringAt(payload, ["target_post_type"])} onChange={(event) => update(["target_post_type"], event.currentTarget.value)} readOnly={immutable} />
	  <Select label={__("Composition mode", TEXT_DOMAIN)} description={fieldDescription(__("Documents assemble a Gutenberg body; structured records keep the body empty and use approved fields.", TEXT_DOMAIN), "blueprint-identity", help)} value={compositionMode}
		data={[{ value: "document", label: __("Document", TEXT_DOMAIN) }, { value: "structured-record", label: __("Structured record", TEXT_DOMAIN) }]}
		onChange={(value) => { if (!value) return; update(["composition_mode"], value); if (value === "structured-record") { update(["allowed_patterns"], []); update(["required_sequence"], []); update(["allowed_blocks"], []); } }} readOnly={immutable} />
      <TextInput label={__("Visual variant", TEXT_DOMAIN)} description={fieldDescription(__("Stable semantic presentation family, not a CSS class.", TEXT_DOMAIN), "blueprint-identity", help)} placeholder="service-detail" value={stringAt(payload, ["visual_variant"])} onChange={(event) => update(["visual_variant"], event.currentTarget.value)} readOnly={immutable} />
      <Select label={__("Excerpt policy", TEXT_DOMAIN)} description={fieldDescription(__("Required, optional, or empty for this page type.", TEXT_DOMAIN), "blueprint-safety", help)} value={stringAt(payload, ["excerpt_policy"]) || stringAt(payload, ["excerpt"]) || "optional"}
        data={[{ value: "required", label: __("Required", TEXT_DOMAIN) }, { value: "optional", label: __("Optional", TEXT_DOMAIN) }, { value: "disabled", label: __("Disabled", TEXT_DOMAIN) }]}
        onChange={(value) => { if (value) { update(["excerpt_policy"], value); } }} readOnly={immutable} />
      <NumberInput label={__("Maximum words", TEXT_DOMAIN)} description={fieldDescription(__("Upper validation boundary for generated body copy.", TEXT_DOMAIN), "blueprint-safety", help)} placeholder="1200" min={1} max={100000} value={numberAt(payload, ["constraints", "maximum_words"])} onChange={(value) => update(["constraints", "maximum_words"], Number(value) || 0)} readOnly={immutable} />
    </SimpleGrid>
    <Textarea label={__("Purpose", TEXT_DOMAIN)} description={fieldDescription(__("Describe the editorial job and intended reader outcome.", TEXT_DOMAIN), "blueprint-identity", help)} placeholder={__("Explain the service, establish trust with evidence, and guide the reader to one relevant next step.", TEXT_DOMAIN)} value={stringAt(payload, ["purpose"])} onChange={(event) => update(["purpose"], event.currentTarget.value)} autosize minRows={2} readOnly={immutable} />
    <HelpHeading title={__("Target template", TEXT_DOMAIN)} topic="blueprint-template" help={help} />
    <SimpleGrid cols={{ base: 1, sm: 3 }}>
      <TextInput label={__("Template label", TEXT_DOMAIN)} description={fieldDescription(__("Human-readable template name.", TEXT_DOMAIN), "blueprint-template", help)} placeholder={__("Page without title", TEXT_DOMAIN)} value={stringAt(payload, ["target_template", "label"])} onChange={(event) => update(["target_template", "label"], event.currentTarget.value)} readOnly={immutable} />
      <Select label={__("Assignment mode", TEXT_DOMAIN)} description={fieldDescription(__("Use hierarchy or pin an assigned template.", TEXT_DOMAIN), "blueprint-template", help)} value={templateMode} data={["hierarchy", "assigned"]} onChange={(value) => { if (value === "hierarchy" || value === "assigned") switchTemplateMode(value); }} readOnly={immutable} />
      <TextInput label={templateMode === "hierarchy" ? __("Template file", TEXT_DOMAIN) : __("Template slug", TEXT_DOMAIN)} description={fieldDescription(templateMode === "hierarchy" ? __("Theme hierarchy file such as templates/single-example.html.", TEXT_DOMAIN) : __("Registered template slug used when assigned.", TEXT_DOMAIN), "blueprint-template", help)} placeholder={templateMode === "hierarchy" ? "templates/single.html" : "page-no-title"} value={templateValue} onChange={(event) => updateTemplate(templateMode, event.currentTarget.value)} readOnly={immutable} />
    </SimpleGrid>
    <TagsInput label={__("Allowed patterns", TEXT_DOMAIN)} description={fieldDescription(compositionMode === "structured-record" ? __("Structured records must leave this empty.", TEXT_DOMAIN) : __("Pattern slugs Composer may use for this page type.", TEXT_DOMAIN), "blueprint-patterns", help)} placeholder="theme-slug/pattern-name" value={stringsAt(payload, ["allowed_patterns"])} onChange={(value) => update(["allowed_patterns"], value)} readOnly={immutable || compositionMode === "structured-record"} clearable />
    <TagsInput label={__("Required pattern sequence", TEXT_DOMAIN)} description={fieldDescription(compositionMode === "structured-record" ? __("Structured records do not have a Gutenberg sequence.", TEXT_DOMAIN) : __("Ordered minimum skeleton; every item must also be allowed.", TEXT_DOMAIN), "blueprint-patterns", help)} placeholder="theme-slug/hero" value={stringsAt(payload, ["required_sequence"])} onChange={(value) => update(["required_sequence"], value)} readOnly={immutable || compositionMode === "structured-record"} clearable />
    <MultiSelect label={__("Allowed blocks", TEXT_DOMAIN)} description={fieldDescription(compositionMode === "structured-record" ? __("Structured records cannot receive body blocks.", TEXT_DOMAIN) : __("Final Gutenberg block allowlist for assembled candidates.", TEXT_DOMAIN), "blueprint-blocks", help)} placeholder={__("Search registered blocks", TEXT_DOMAIN)} value={selectedBlocks} onChange={(value) => update(["allowed_blocks"], value)} data={blockOptions} searchable clearable readOnly={immutable || compositionMode === "structured-record"} nothingFoundMessage={__("No registered block matches this search", TEXT_DOMAIN)} />
    <HelpHeading title={__("Safety and structure constraints", TEXT_DOMAIN)} topic="blueprint-safety" help={help} />
    <SimpleGrid cols={{ base: 1, sm: 2, lg: 3 }}>
      {constraintFields.map(([key, label]) => <Switch key={key} label={label} checked={booleanAt(payload, ["constraints", key])} onChange={(event) => { if (!immutable) update(["constraints", key], event.currentTarget.checked); }} readOnly={immutable} />)}
    </SimpleGrid>
    <LongList label={__("Layout rules", TEXT_DOMAIN)} description={__("One observable, enforceable layout rule per line.", TEXT_DOMAIN)} placeholder={__("Use one full-width hero before the main content sections.\nKeep explanatory media inside the content rail.\nUse only theme preset colors and spacing values.", TEXT_DOMAIN)} topic="blueprint-layout" help={help} value={stringsAt(payload, ["layout_contract"])} change={(value) => update(["layout_contract"], value)} readOnly={immutable} />
    <LongList label={__("Content rules", TEXT_DOMAIN)} description={__("One editorial, evidence, or reader-safety requirement per line.", TEXT_DOMAIN)} placeholder={__("Use one descriptive H1 and a clear heading hierarchy.\nSupport factual claims with an approved source.\nEnd with one relevant next step for the reader.", TEXT_DOMAIN)} topic="blueprint-content" help={help} value={stringsAt(payload, ["content_contract"])} change={(value) => update(["content_contract"], value)} readOnly={immutable} />
    <LongList label={__("Migration rules", TEXT_DOMAIN)} description={__("How existing content is preserved and mapped; leave empty for new content only.", TEXT_DOMAIN)} placeholder={__("Preserve the existing slug, topic, and supported factual claims.\nMap legacy sections to the closest approved pattern.\nRetain relevant media and source attribution.", TEXT_DOMAIN)} topic="blueprint-migration" help={help} value={stringsAt(payload, ["migration_contract"])} change={(value) => update(["migration_contract"], value)} readOnly={immutable} />
    <TagsInput label={__("Reference URLs", TEXT_DOMAIN)} description={fieldDescription(__("Approved context sources; never copied blindly.", TEXT_DOMAIN), "blueprint-references", help)} placeholder="https://example.com/authoritative-source/" value={stringsAt(payload, ["reference_urls"])} onChange={(value) => update(["reference_urls"], value)} readOnly={immutable} clearable />
  </Stack>;
}

function SiteContractFields({ payload, update, immutable, help, postTypes, blueprints }: FieldsProps & { postTypes: ProviderDiscovery["registered_post_types"]; blueprints: ConfigEntity[] }) {
  const constraints = [
    ["exactly_one_h1", __("Require exactly one H1", TEXT_DOMAIN)],
    ["inline_css", __("Allow inline CSS", TEXT_DOMAIN)],
    ["shortcodes", __("Allow shortcodes", TEXT_DOMAIN)],
    ["external_embeds", __("Allow external embeds", TEXT_DOMAIN)],
    ["theme_presets_only", __("Require theme presets", TEXT_DOMAIN)]
  ] as const;
  return <Stack gap="md">
    <EditorIntro title={__("Site Contract", TEXT_DOMAIN)} text={__("Sets site-wide policy and the defaults inherited by Blueprints that do not define explicit page-type values.", TEXT_DOMAIN)} />
    <SimpleGrid cols={{ base: 1, sm: 2 }}>
      <TextInput label={__("Contract label", TEXT_DOMAIN)} description={fieldDescription(__("Human-readable name of the site-wide contract.", TEXT_DOMAIN), "site-identity", help)} placeholder={__("Public site content contract", TEXT_DOMAIN)} value={stringAt(payload, ["label"])} onChange={(event) => update(["label"], event.currentTarget.value)} readOnly={immutable} />
      <TextInput label={__("Policy name", TEXT_DOMAIN)} description={fieldDescription(__("Stable machine-readable design policy name.", TEXT_DOMAIN), "site-identity", help)} placeholder="site-design-policy" value={stringAt(payload, ["design_policy", "policy_name"])} onChange={(event) => update(["design_policy", "policy_name"], event.currentTarget.value)} readOnly={immutable} />
      <TextInput label={__("Policy version", TEXT_DOMAIN)} description={fieldDescription(__("Increment when policy meaning changes.", TEXT_DOMAIN), "site-identity", help)} placeholder="1.0.0" value={stringAt(payload, ["design_policy", "policy_version"])} onChange={(event) => update(["design_policy", "policy_version"], event.currentTarget.value)} readOnly={immutable} />
	  <TextInput label={__("Content language", TEXT_DOMAIN)} description={fieldDescription(__("Authoritative BCP 47 language for public copy. Strict enforcement requires every execution request to echo it.", TEXT_DOMAIN), "site-language", help)} placeholder="en-US" value={stringAt(payload, ["design_policy", "content_language"])} onChange={(event) => update(["design_policy", "content_language"], event.currentTarget.value)} readOnly={immutable} />
	  <Select label={__("Language enforcement", TEXT_DOMAIN)} description={fieldDescription(__("Strict blocks known fallback copy and substantial language mismatches before writing.", TEXT_DOMAIN), "site-language", help)} value={stringAt(payload, ["design_policy", "content_language_enforcement"]) || "advisory"} data={[{ value: "advisory", label: __("Advisory", TEXT_DOMAIN) }, { value: "strict", label: __("Strict", TEXT_DOMAIN) }]} onChange={(value) => { if (value) update(["design_policy", "content_language_enforcement"], value); }} readOnly={immutable} />
      <TextInput label={__("Operator language (optional)", TEXT_DOMAIN)} description={fieldDescription(__("Advisory BCP 47 language for operator-facing instructions when the connected client supports it.", TEXT_DOMAIN), "site-language", help)} placeholder="hu-HU" value={stringAt(payload, ["design_policy", "operator_language"])} onChange={(event) => update(["design_policy", "operator_language"], event.currentTarget.value)} readOnly={immutable} />
	  <NumberInput label={__("Default maximum words", TEXT_DOMAIN)} description={fieldDescription(__("Inherited when a Blueprint does not define its own word ceiling.", TEXT_DOMAIN), "site-language", help)} placeholder="3000" min={1} max={100000} value={numberAt(payload, ["design_policy", "constraints", "maximum_words"])} onChange={(value) => update(["design_policy", "constraints", "maximum_words"], Number(value) || 0)} readOnly={immutable} />
    </SimpleGrid>
	<TagsInput label={__("Approved language exceptions", TEXT_DOMAIN)} description={fieldDescription(__("Brand names, technical terms, citations, or reviewed foreign-language fragments allowed by strict validation.", TEXT_DOMAIN), "site-language", help)} placeholder="SmartCloud" value={stringsAt(payload, ["design_policy", "content_language_exceptions"])} onChange={(value) => update(["design_policy", "content_language_exceptions"], value)} readOnly={immutable} clearable />
	<TagsInput label={__("Language mismatch signals", TEXT_DOMAIN)} description={fieldDescription(__("Optional site-authored word signals for a known unwanted language. Composer contains no built-in language or language-pair vocabulary.", TEXT_DOMAIN), "site-language", help)} placeholder="the" value={stringsAt(payload, ["design_policy", "content_language_mismatch_signals"])} onChange={(value) => update(["design_policy", "content_language_mismatch_signals"], value)} readOnly={immutable} clearable />
    <TagsInput label={__("Allowed pattern namespaces", TEXT_DOMAIN)} description={fieldDescription(__("Trusted namespaces from themes, Composer, or approved plugins.", TEXT_DOMAIN), "site-pattern-scope", help)} placeholder="theme-slug" value={stringsAt(payload, ["design_policy", "allowed_pattern_namespaces"])} onChange={(value) => update(["design_policy", "allowed_pattern_namespaces"], value)} readOnly={immutable} clearable />
    <TagsInput label={__("Disallowed blocks", TEXT_DOMAIN)} description={fieldDescription(__("Site-wide denylist; it overrides Blueprint allowlists.", TEXT_DOMAIN), "site-pattern-scope", help)} placeholder="core/shortcode" value={stringsAt(payload, ["design_policy", "disallowed_blocks"])} onChange={(value) => update(["design_policy", "disallowed_blocks"], value)} readOnly={immutable} clearable />
    <HelpHeading title={__("Remote Media Library ingestion", TEXT_DOMAIN)} topic="site-safety" help={help} />
    <Switch label={__("Allow governed remote image ingestion", TEXT_DOMAIN)} description={__("Requires the dedicated Composer ingest capability and accepts only exact HTTPS hosts below.", TEXT_DOMAIN)} checked={booleanAt(payload, ["design_policy", "remote_media_ingest", "enabled"])} onChange={(event) => { if (!immutable) update(["design_policy", "remote_media_ingest", "enabled"], event.currentTarget.checked); }} readOnly={immutable} />
    <SimpleGrid cols={{ base: 1, sm: 2 }}>
      <TagsInput label={__("Allowed media hosts", TEXT_DOMAIN)} description={__("Exact DNS hostnames only; no scheme, path, port, redirect, or wildcard.", TEXT_DOMAIN)} placeholder="media.example.com" value={stringsAt(payload, ["design_policy", "remote_media_ingest", "allowed_hosts"])} onChange={(value) => update(["design_policy", "remote_media_ingest", "allowed_hosts"], value)} readOnly={immutable} clearable />
      <MultiSelect label={__("Allowed image types", TEXT_DOMAIN)} description={__("Raster image MIME allowlist used after file-content validation.", TEXT_DOMAIN)} value={stringsAt(payload, ["design_policy", "remote_media_ingest", "allowed_mime_types"])} onChange={(value) => update(["design_policy", "remote_media_ingest", "allowed_mime_types"], value)} data={["image/jpeg", "image/png", "image/webp", "image/avif", "image/gif"]} readOnly={immutable} clearable />
      <NumberInput label={__("Maximum image bytes", TEXT_DOMAIN)} description={__("Per-image download ceiling from 1 KB through 25 MB.", TEXT_DOMAIN)} min={1024} max={26214400} value={numberAt(payload, ["design_policy", "remote_media_ingest", "max_bytes"]) || 12582912} onChange={(value) => update(["design_policy", "remote_media_ingest", "max_bytes"], Number(value) || 0)} readOnly={immutable} />
    </SimpleGrid>
    <ContentAccessFields payload={payload} update={update} immutable={immutable} postTypes={postTypes} blueprints={blueprints} />
    <HelpHeading title={__("Global safety constraints", TEXT_DOMAIN)} topic="site-safety" help={help} />
    <SimpleGrid cols={{ base: 1, sm: 2, lg: 3 }}>
      {constraints.map(([key, label]) => <Switch key={key} label={label} checked={booleanAt(payload, ["design_policy", "constraints", key])} onChange={(event) => { if (!immutable) update(["design_policy", "constraints", key], event.currentTarget.checked); }} readOnly={immutable} />)}
    </SimpleGrid>
    <LongList label={__("Brand tone", TEXT_DOMAIN)} description={__("One durable writing characteristic per line.", TEXT_DOMAIN)} placeholder={__("Clear and direct\nEvidence-led\nTechnically credible", TEXT_DOMAIN)} topic="site-brand" help={help} value={stringsAt(payload, ["design_policy", "brand", "tone"])} change={(value) => update(["design_policy", "brand", "tone"], value)} readOnly={immutable} />
    <LongList label={__("Avoid", TEXT_DOMAIN)} description={__("One prohibited claim or writing habit per line.", TEXT_DOMAIN)} placeholder={__("Unsupported guarantees\nInvented statistics\nArtificial urgency", TEXT_DOMAIN)} topic="site-brand" help={help} value={stringsAt(payload, ["design_policy", "brand", "avoid"])} change={(value) => update(["design_policy", "brand", "avoid"], value)} readOnly={immutable} />
    <LongList label={__("Global layout rules", TEXT_DOMAIN)} description={__("Inherited presentation invariants, one per line.", TEXT_DOMAIN)} placeholder={__("Use one Gutenberg content root.\nUse only active-theme preset design values.\nKeep heading levels sequential below the single H1.", TEXT_DOMAIN)} topic="site-layout" help={help} value={stringsAt(payload, ["design_policy", "layout", "rules"])} change={(value) => update(["design_policy", "layout", "rules"], value)} readOnly={immutable} />
  </Stack>;
}

function ContentAccessFields({ payload, update, immutable, postTypes, blueprints }: FieldsProps & { postTypes: ProviderDiscovery["registered_post_types"]; blueprints: ConfigEntity[] }) {
  const policy = objectAt(payload, ["design_policy"]);
  const access = objectAt(policy, ["content_access"]);
  const fieldAccess = objectAt(policy, ["content_field_access"]);
  const taxonomyAccess = objectAt(policy, ["content_taxonomy_access"]);
  const contract = objectAt(policy, ["post_type_contract"]);
  const configured = new Set([...Object.values(contract), ...Object.keys(access), ...Object.keys(fieldAccess), ...Object.keys(taxonomyAccess)].filter((value): value is string => typeof value === "string"));
  const known = new Map(postTypes.map((item) => [item.name, item]));
  for (const name of configured) {
    if (!known.has(name)) known.set(name, { name, label: name, builtin: false, public: false, show_ui: false, show_in_rest: false, supports_editor: false, current_user_can_edit: false, registered_taxonomies: [], registered_meta: [] });
  }

  const setRule = (postType: string, rule: "discover" | "read" | "clone" | "adopt_drafts", checked: boolean, pageTypes: string[]) => {
    const nextPolicy = { ...policy };
    const nextAccess = { ...access };
    const current = objectAt(access, [postType]);
    const nextRules = {
      discover: current.discover === true,
      read: current.read === true,
      clone: current.clone === true,
      adopt_drafts: current.adopt_drafts === true,
      [rule]: checked
    };
    if (rule === "clone" && checked) { nextRules.read = true; nextRules.discover = true; }
    if (rule === "read" && checked) nextRules.discover = true;
    if (rule === "read" && !checked) nextRules.clone = false;
    if (rule === "discover" && !checked) { nextRules.read = false; nextRules.clone = false; nextRules.adopt_drafts = false; }
    if (rule === "adopt_drafts" && checked) nextRules.discover = true;
    nextAccess[postType] = nextRules;
    nextPolicy.content_access = nextAccess;
    const nextContract = { ...contract };
    for (const pageType of pageTypes) nextContract[pageType] = postType;
    nextPolicy.post_type_contract = nextContract;
    update(["design_policy"], nextPolicy);
  };

  const setFieldRule = (postType: string, metaKey: string, rule: "read" | "write", checked: boolean) => {
    const nextPolicy = { ...policy };
    const nextFieldAccess = { ...fieldAccess };
    const postTypeFields = { ...objectAt(fieldAccess, [postType]) };
    const current = objectAt(postTypeFields, [metaKey]);
    const nextRules = {
      read: current.read === true,
      write: current.write === true,
      [rule]: checked
    };
    if (rule === "write" && checked) nextRules.read = true;
    if (rule === "read" && !checked) nextRules.write = false;
    if (!nextRules.read && !nextRules.write) delete postTypeFields[metaKey];
    else postTypeFields[metaKey] = nextRules;
    if (Object.keys(postTypeFields).length) nextFieldAccess[postType] = postTypeFields;
    else delete nextFieldAccess[postType];
    nextPolicy.content_field_access = nextFieldAccess;
    update(["design_policy"], nextPolicy);
  };

  const setAllFieldRules = (postType: string, metaKeys: string[], rule: "read" | "write", checked: boolean) => {
    const nextPolicy = { ...policy };
    const nextFieldAccess = { ...fieldAccess };
    const postTypeFields = { ...objectAt(fieldAccess, [postType]) };
    for (const metaKey of metaKeys) {
      const current = objectAt(postTypeFields, [metaKey]);
      const nextRules = {
        read: current.read === true,
        write: current.write === true,
        [rule]: checked
      };
      if (rule === "write" && checked) nextRules.read = true;
      if (rule === "read" && !checked) nextRules.write = false;
      if (!nextRules.read && !nextRules.write) delete postTypeFields[metaKey];
      else postTypeFields[metaKey] = nextRules;
    }
    if (Object.keys(postTypeFields).length) nextFieldAccess[postType] = postTypeFields;
    else delete nextFieldAccess[postType];
    nextPolicy.content_field_access = nextFieldAccess;
    update(["design_policy"], nextPolicy);
  };

  const setTaxonomyRules = (postType: string, taxonomy: string, patch: Record<string, unknown>) => {
    const nextPolicy = { ...policy };
    const nextTaxonomyAccess = { ...taxonomyAccess };
    const postTypeTaxonomies = { ...objectAt(taxonomyAccess, [postType]) };
    const current = objectAt(postTypeTaxonomies, [taxonomy]);
    const nextRules: Record<string, unknown> = {
      search: current.search === true,
      assign: current.assign === true,
      create: current.create === true,
      maximum_items: typeof current.maximum_items === "number" ? current.maximum_items : 20,
      assignment_mode: current.assignment_mode === "append" ? "append" : "replace",
      creation_parent_policy: current.creation_parent_policy === "allowlist" ? "allowlist" : "root-only",
      creation_parent_slugs: Array.isArray(current.creation_parent_slugs) ? current.creation_parent_slugs : [],
      ...patch
    };
    if (nextRules.create === true) { nextRules.assign = true; nextRules.search = true; }
    if (nextRules.assign === true) nextRules.search = true;
    if (nextRules.assign !== true) nextRules.create = false;
    if (nextRules.search !== true) { nextRules.assign = false; nextRules.create = false; }
    if (nextRules.creation_parent_policy !== "allowlist") nextRules.creation_parent_slugs = [];
    if (nextRules.search === true) postTypeTaxonomies[taxonomy] = nextRules;
    else delete postTypeTaxonomies[taxonomy];
    if (Object.keys(postTypeTaxonomies).length) nextTaxonomyAccess[postType] = postTypeTaxonomies;
    else delete nextTaxonomyAccess[postType];
    nextPolicy.content_taxonomy_access = nextTaxonomyAccess;
    update(["design_policy"], nextPolicy);
  };

  return <Stack gap="sm">
    <div><Title order={4}>{__("Composer content access", TEXT_DOMAIN)}</Title>
      <Text size="sm" c="dimmed" mt={4}>{__("Explicitly grant the active Composer configuration access to existing WordPress content. WordPress user capabilities are still enforced for every item. Published content can be inspected or cloned; direct write access is limited to adopting editable drafts.", TEXT_DOMAIN)}</Text></div>
    {[...known.values()].map((postType) => {
      const matching = blueprints.filter((entity) => entity.type === "blueprint" && stringAt(entity.payload, ["target_post_type"]) === postType.name);
      const pageTypes = matching.map((entity) => stringAt(entity.payload, ["page_type"]) || entity.key).filter(Boolean);
      const safe = postType.public && postType.show_ui && postType.show_in_rest && postType.supports_editor && postType.current_user_can_edit;
      const enabled = !immutable && safe && pageTypes.length > 0;
      const rules = objectAt(access, [postType.name]);
      return <Card key={postType.name} withBorder radius="sm" p="sm"><Stack gap="xs">
        <Group justify="space-between" align="flex-start" wrap="wrap"><div><Text fw={700}>{postType.label}</Text><Code>{postType.name}</Code></div><Text size="xs" c={enabled || immutable ? "dimmed" : "orange.8"}>{pageTypes.length ? `${__("Blueprints", TEXT_DOMAIN)}: ${pageTypes.join(", ")}` : __("Add a Blueprint targeting this post type first.", TEXT_DOMAIN)}</Text></Group>
        {!safe && <Alert color="yellow">{__("Composer requires a public, wp-admin-visible, REST/Gutenberg-enabled post type and the current user's edit capability.", TEXT_DOMAIN)}</Alert>}
        <SimpleGrid cols={{ base: 1, sm: 2, lg: 4 }}>
          <Checkbox label={__("Discover in lists", TEXT_DOMAIN)} description={__("Show metadata without body content.", TEXT_DOMAIN)} checked={rules.discover === true} disabled={!enabled} onChange={(event) => setRule(postType.name, "discover", event.currentTarget.checked, pageTypes)} />
          <Checkbox label={__("Read content", TEXT_DOMAIN)} description={__("Inspect body content for analysis.", TEXT_DOMAIN)} checked={rules.read === true} disabled={!enabled} onChange={(event) => setRule(postType.name, "read", event.currentTarget.checked, pageTypes)} />
          <Checkbox label={__("Clone to Composer draft", TEXT_DOMAIN)} description={__("Copy into a new agent-owned draft.", TEXT_DOMAIN)} checked={rules.clone === true} disabled={!enabled} onChange={(event) => setRule(postType.name, "clone", event.currentTarget.checked, pageTypes)} />
          <Checkbox label={__("Adopt editable drafts", TEXT_DOMAIN)} description={__("Allow explicit takeover of a draft only.", TEXT_DOMAIN)} checked={rules.adopt_drafts === true} disabled={!enabled} onChange={(event) => setRule(postType.name, "adopt_drafts", event.currentTarget.checked, pageTypes)} />
        </SimpleGrid>
        {postType.registered_meta.length > 0 && <Accordion variant="contained" radius="sm">
          <Accordion.Item value="registered-fields">
            <Accordion.Control>{`${__("Composer field access", TEXT_DOMAIN)} (${postType.registered_meta.length})`}</Accordion.Control>
            <Accordion.Panel><Stack gap="xs">
              <Alert color="blue">{__("Only explicitly selected, public, single-value, REST-registered fields become visible to Composer. Writes remain limited to Composer-owned assigned drafts and require optimistic concurrency plus explicit confirmation.", TEXT_DOMAIN)}</Alert>
              <Group gap="xs" wrap="wrap">
                <Button size="compact-xs" variant="light" disabled={!enabled} onClick={() => setAllFieldRules(postType.name, postType.registered_meta.map((field) => field.key), "read", true)}>{__("Select all Read", TEXT_DOMAIN)}</Button>
                <Button size="compact-xs" variant="subtle" disabled={!enabled} onClick={() => setAllFieldRules(postType.name, postType.registered_meta.map((field) => field.key), "read", false)}>{__("Deselect all Read", TEXT_DOMAIN)}</Button>
                <Button size="compact-xs" variant="light" disabled={!enabled} onClick={() => setAllFieldRules(postType.name, postType.registered_meta.map((field) => field.key), "write", true)}>{__("Select all Write draft", TEXT_DOMAIN)}</Button>
                <Button size="compact-xs" variant="subtle" disabled={!enabled} onClick={() => setAllFieldRules(postType.name, postType.registered_meta.map((field) => field.key), "write", false)}>{__("Deselect all Write draft", TEXT_DOMAIN)}</Button>
              </Group>
              {postType.registered_meta.map((field) => {
                const rules = objectAt(fieldAccess, [postType.name, field.key]);
                return <Card key={field.key} withBorder radius="sm" p="xs">
                  <Group justify="space-between" align="flex-start" wrap="wrap">
                    <div><Code>{field.key}</Code><Text size="xs" c="dimmed">{field.description || field.type}</Text></div>
                    <Group gap="md">
                      <Checkbox label={__("Read", TEXT_DOMAIN)} checked={rules.read === true} disabled={!enabled} onChange={(event) => setFieldRule(postType.name, field.key, "read", event.currentTarget.checked)} />
                      <Checkbox label={__("Write draft", TEXT_DOMAIN)} checked={rules.write === true} disabled={!enabled} onChange={(event) => setFieldRule(postType.name, field.key, "write", event.currentTarget.checked)} />
                    </Group>
                  </Group>
                </Card>;
              })}
            </Stack></Accordion.Panel>
          </Accordion.Item>
        </Accordion>}
        {(postType.registered_taxonomies || []).length > 0 && <Accordion variant="contained" radius="sm">
          <Accordion.Item value="registered-taxonomies">
            <Accordion.Control>{`${__("Composer taxonomy access", TEXT_DOMAIN)} (${postType.registered_taxonomies.length})`}</Accordion.Control>
            <Accordion.Panel><Stack gap="xs">
              <Alert color="blue">{__("Search for an existing public term first. Assignment is draft-only; creation is an additional global navigation change and therefore requires both search and assignment. Every operation is still checked against WordPress capabilities.", TEXT_DOMAIN)}</Alert>
              {postType.registered_taxonomies.map((taxonomy) => {
                const rules = objectAt(taxonomyAccess, [postType.name, taxonomy.name]);
                const searchable = taxonomy.public && taxonomy.show_ui && taxonomy.show_in_rest;
                const canSearch = enabled && searchable;
                const canAssign = canSearch && taxonomy.current_user_can_assign;
                const canCreate = canAssign && taxonomy.current_user_can_create;
                const search = rules.search === true;
                const assign = rules.assign === true;
                const create = rules.create === true;
                return <Card key={taxonomy.name} withBorder radius="sm" p="xs"><Stack gap="xs">
                  <Group justify="space-between" align="flex-start" wrap="wrap">
                    <div><Text fw={600}>{taxonomy.label}</Text><Code>{taxonomy.name}</Code></div>
                    <Text size="xs" c="dimmed">{taxonomy.hierarchical ? __("Hierarchical", TEXT_DOMAIN) : __("Flat", TEXT_DOMAIN)}</Text>
                  </Group>
                  {!searchable && <Alert color="yellow">{__("Composer requires a public, wp-admin-visible, REST-visible taxonomy.", TEXT_DOMAIN)}</Alert>}
                  <SimpleGrid cols={{ base: 1, sm: 3 }}>
                    <Checkbox label={__("Search terms", TEXT_DOMAIN)} description={__("Find and reuse existing terms.", TEXT_DOMAIN)} checked={search} disabled={!canSearch} onChange={(event) => setTaxonomyRules(postType.name, taxonomy.name, { search: event.currentTarget.checked })} />
                    <Checkbox label={__("Assign to draft", TEXT_DOMAIN)} description={__("Attach approved terms to an owned draft.", TEXT_DOMAIN)} checked={assign} disabled={!canAssign} onChange={(event) => setTaxonomyRules(postType.name, taxonomy.name, { assign: event.currentTarget.checked })} />
                    <Checkbox label={__("Create terms", TEXT_DOMAIN)} description={__("Create a term only when no suitable term exists.", TEXT_DOMAIN)} checked={create} disabled={!canCreate} onChange={(event) => setTaxonomyRules(postType.name, taxonomy.name, { create: event.currentTarget.checked })} />
                  </SimpleGrid>
                  <SimpleGrid cols={{ base: 1, sm: 2 }}>
                    <NumberInput label={__("Maximum terms", TEXT_DOMAIN)} description={__("Assignment ceiling per draft, from 1 through 100.", TEXT_DOMAIN)} min={1} max={100} value={typeof rules.maximum_items === "number" ? rules.maximum_items : 20} disabled={!canSearch || !search} onChange={(value) => setTaxonomyRules(postType.name, taxonomy.name, { maximum_items: Number(value) || 1 })} />
                    <Select label={__("Assignment mode", TEXT_DOMAIN)} description={__("Append preserves current terms; replace sets the complete selection.", TEXT_DOMAIN)} value={rules.assignment_mode === "append" ? "append" : "replace"} data={[{ value: "append", label: __("Append", TEXT_DOMAIN) }, { value: "replace", label: __("Replace", TEXT_DOMAIN) }]} disabled={!canAssign || !assign} onChange={(value) => { if (value) setTaxonomyRules(postType.name, taxonomy.name, { assignment_mode: value }); }} />
                  </SimpleGrid>
                  {taxonomy.hierarchical && create && <SimpleGrid cols={{ base: 1, sm: 2 }}>
                    <Select label={__("Creation parent policy", TEXT_DOMAIN)} description={__("Create only root terms or under explicitly allowed parent slugs.", TEXT_DOMAIN)} value={rules.creation_parent_policy === "allowlist" ? "allowlist" : "root-only"} data={[{ value: "root-only", label: __("Root only", TEXT_DOMAIN) }, { value: "allowlist", label: __("Parent allowlist", TEXT_DOMAIN) }]} disabled={!canCreate} onChange={(value) => { if (value) setTaxonomyRules(postType.name, taxonomy.name, { creation_parent_policy: value }); }} />
                    {rules.creation_parent_policy === "allowlist" && <TagsInput label={__("Allowed parent slugs", TEXT_DOMAIN)} description={__("Durable existing term slugs accepted as creation parents.", TEXT_DOMAIN)} placeholder="parent-term" value={Array.isArray(rules.creation_parent_slugs) ? rules.creation_parent_slugs.filter((value): value is string => typeof value === "string") : []} disabled={!canCreate} onChange={(value) => setTaxonomyRules(postType.name, taxonomy.name, { creation_parent_slugs: value })} clearable />}
                  </SimpleGrid>}
                </Stack></Card>;
              })}
            </Stack></Accordion.Panel>
          </Accordion.Item>
        </Accordion>}
        {safe && postType.registered_meta.length === 0 && <Text size="xs" c="dimmed">{__("No safe single-value REST-registered custom fields were discovered for this post type.", TEXT_DOMAIN)}</Text>}
      </Stack></Card>;
    })}
  </Stack>;
}

function ConfigSetFields({ payload, update, immutable, help }: FieldsProps) {
  return <Stack gap="md">
    <EditorIntro title={__("Config Set metadata", TEXT_DOMAIN)} text={__("Describes this complete versioned configuration. The lifecycle and checksum are managed by Composer and cannot be edited here.", TEXT_DOMAIN)} />
    <SimpleGrid cols={{ base: 1, sm: 2 }}>
      <TextInput label={__("Label", TEXT_DOMAIN)} description={fieldDescription(__("Human-readable name for this configuration version.", TEXT_DOMAIN), "config-set-metadata", help)} placeholder={__("Production content contract", TEXT_DOMAIN)} value={stringAt(payload, ["label"])} onChange={(event) => update(["label"], event.currentTarget.value)} readOnly={immutable} />
      <Select label={__("Contract mode", TEXT_DOMAIN)} description={fieldDescription(__("Choose complete policy ownership or a smaller universal contract.", TEXT_DOMAIN), "config-set-metadata", help)} value={stringAt(payload, ["mode"]) || null} data={["fully-contracted", "universal"]} onChange={(value) => update(["mode"], value || "")} readOnly={immutable} />
      <TextInput label={__("Source", TEXT_DOMAIN)} description={fieldDescription(__("Provenance label such as preset, import, or manual.", TEXT_DOMAIN), "config-set-metadata", help)} placeholder="manual" value={stringAt(payload, ["source"])} onChange={(event) => update(["source"], event.currentTarget.value)} readOnly={immutable} />
      <Select label={__("Fallback policy", TEXT_DOMAIN)} description={fieldDescription(__("Controls whether safe discovered theme structures may fill gaps.", TEXT_DOMAIN), "config-set-metadata", help)} value={stringAt(payload, ["fallback_policy"]) || null} data={["strict", "allow-theme-fallback"]} onChange={(value) => update(["fallback_policy"], value || "")} readOnly={immutable} />
      <TextInput label={__("Source theme slug", TEXT_DOMAIN)} description={fieldDescription(__("Theme provenance recorded when this set was created.", TEXT_DOMAIN), "config-set-metadata", help)} placeholder="twentytwentyfive" value={stringAt(payload, ["source_theme", "slug"])} onChange={(event) => update(["source_theme", "slug"], event.currentTarget.value)} readOnly={immutable} />
      <TextInput label={__("Source theme version", TEXT_DOMAIN)} description={fieldDescription(__("Theme version used for the recorded capability fingerprint.", TEXT_DOMAIN), "config-set-metadata", help)} placeholder="1.5" value={stringAt(payload, ["source_theme", "version"])} onChange={(event) => update(["source_theme", "version"], event.currentTarget.value)} readOnly={immutable} />
    </SimpleGrid>
  </Stack>;
}

function GenericFields({ payload, update, immutable, type }: FieldsProps & { type: string }) {
  const scalarEntries = Object.entries(payload).filter(([, value]) => ["string", "number", "boolean"].includes(typeof value));
  return <Stack gap="md">
    <EditorIntro title={entityTypeLabel(type)} text={genericDescription(type)} />
    <SimpleGrid cols={{ base: 1, sm: 2 }}>
      {scalarEntries.map(([key, value]) => typeof value === "boolean" ?
        <Switch key={key} label={humanize(key)} checked={value} onChange={(event) => { if (!immutable) update([key], event.currentTarget.checked); }} readOnly={immutable} /> :
        <TextInput key={key} label={humanize(key)} value={String(value)} onChange={(event) => update([key], typeof value === "number" ? Number(event.currentTarget.value) : event.currentTarget.value)} readOnly={immutable} />)}
    </SimpleGrid>
    <Alert color="blue">{__("Nested or vendor-specific fields remain available in Advanced JSON source so the guided editor never discards unknown contract data.", TEXT_DOMAIN)}</Alert>
  </Stack>;
}

function EditorIntro({ title, text }: { title: string; text: string }) {
  return <div><Title order={3}>{title}</Title><Text size="sm" c="dimmed" mt={4}>{text}</Text></div>;
}

function FieldHelp({ topic, help }: { topic: DocTopic; help?: (topic: DocTopic) => void }) {
  if (!help) return null;
  const label = __("Open detailed help for this field", TEXT_DOMAIN);
  return <Tooltip label={label} withArrow>
    <ActionIcon type="button" size="sm" variant="subtle" color="blue" aria-label={label}
      onClick={(event) => { event.preventDefault(); event.stopPropagation(); help(topic); }}>
      <IconHelpCircle size={16} />
    </ActionIcon>
  </Tooltip>;
}

function fieldDescription(text: string, topic: DocTopic, help?: (topic: DocTopic) => void) {
  return <Group gap={4} align="center" wrap="nowrap"><span>{text}</span><FieldHelp topic={topic} help={help} /></Group>;
}

function HelpHeading({ title, topic, help }: { title: string; topic: DocTopic; help?: (topic: DocTopic) => void }) {
  return <Group gap={4} align="center"><Title order={4}>{title}</Title><FieldHelp topic={topic} help={help} /></Group>;
}

function LongList({ label, description, placeholder, topic, help, value, change, readOnly }: { label: string; description: string; placeholder: string; topic: DocTopic; help?: (topic: DocTopic) => void; value: string[]; change: (value: string[]) => void; readOnly: boolean }) {
  return <Textarea label={label} description={fieldDescription(description, topic, help)} placeholder={placeholder} value={value.join("\n")}
    onChange={(event) => change(event.currentTarget.value.split("\n"))}
    onBlur={(event) => change(normalizeLongList(event.currentTarget.value))}
    autosize minRows={3} maxRows={12} readOnly={readOnly} />;
}

function normalizeLongList(value: string): string[] {
  return value.split("\n").map((line) => line.trim()).filter(Boolean);
}

function setNested(source: Payload, path: string[], value: unknown): Payload {
  const result: Payload = { ...source };
  let cursor: Payload = result;
  path.forEach((segment, index) => {
    if (index === path.length - 1) {
      cursor[segment] = value;
      return;
    }
    const child = cursor[segment];
    const next: Payload = child && typeof child === "object" && !Array.isArray(child) ? { ...(child as Payload) } : {};
    cursor[segment] = next;
    cursor = next;
  });
  return result;
}

function at(payload: Payload, path: string[]): unknown {
  let value: unknown = payload;
  for (const segment of path) {
    if (!value || typeof value !== "object" || Array.isArray(value)) return undefined;
    value = (value as Payload)[segment];
  }
  return value;
}

function stringAt(payload: Payload, path: string[]): string {
  const value = at(payload, path);
  return typeof value === "string" || typeof value === "number" ? String(value) : "";
}

function numberAt(payload: Payload, path: string[]): number | "" {
  const value = at(payload, path);
  return typeof value === "number" && Number.isFinite(value) ? value : "";
}

function booleanAt(payload: Payload, path: string[]): boolean {
  return at(payload, path) === true;
}

function objectAt(payload: Payload, path: string[]): Payload {
  const value = at(payload, path);
  return value && typeof value === "object" && !Array.isArray(value) ? value as Payload : {};
}

function stringsAt(payload: Payload, path: string[]): string[] {
  const value = at(payload, path);
  return Array.isArray(value) ? value.filter((item): item is string => typeof item === "string") : [];
}

function entityTypeLabel(type: string): string {
  const labels: Record<string, string> = {
    "config-set": __("Config Set", TEXT_DOMAIN),
    "site-contract": __("Site Contract", TEXT_DOMAIN),
    blueprint: __("Blueprint", TEXT_DOMAIN),
    component: __("Component", TEXT_DOMAIN),
    "style-mapping": __("Style mapping", TEXT_DOMAIN),
    "provider-policy": __("Provider policy", TEXT_DOMAIN),
    discovery: __("Discovery snapshot", TEXT_DOMAIN)
  };
  return labels[type] || humanize(type);
}

function genericDescription(type: string): string {
  const descriptions: Record<string, string> = {
    component: __("Defines a reusable semantic component contract independently of a theme pattern implementation.", TEXT_DOMAIN),
    "style-mapping": __("Maps semantic Composer roles to stable classes, tokens, patterns, or template slots exposed by the active theme.", TEXT_DOMAIN),
    "provider-policy": __("Constrains which provider abilities may serve a component role and whether runtime availability is required.", TEXT_DOMAIN)
  };
  return descriptions[type] || __("Edit the typed top-level fields and use the advanced source only for nested extensions.", TEXT_DOMAIN);
}

function humanize(value: string): string {
  return value.replaceAll("_", " ").replaceAll("-", " ").replace(/^./, (first) => first.toUpperCase());
}
