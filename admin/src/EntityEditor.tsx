import {
  ActionIcon,
  Accordion,
  Alert,
  Button,
  Card,
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
  onDirtyChange?: (dirty: boolean) => void;
  help?: (topic: DocTopic) => void;
}

export default function EntityEditor({ selected, immutable, save, blocks = [], onDirtyChange, help }: EntityEditorProps) {
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
    {selected.type === "site-contract" && <SiteContractFields payload={payload} update={update} immutable={immutable} help={help} />}
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
  const constraintFields = [
    ["exactly_one_h1", __("Require exactly one H1", TEXT_DOMAIN)],
    ["inline_css", __("Allow inline CSS", TEXT_DOMAIN)],
    ["custom_html", __("Allow Custom HTML", TEXT_DOMAIN)],
    ["shortcodes", __("Allow shortcodes", TEXT_DOMAIN)],
    ["external_embeds", __("Allow external embeds", TEXT_DOMAIN)],
    ["theme_presets_only", __("Require theme presets", TEXT_DOMAIN)]
  ] as const;

  const selectedBlocks = stringsAt(payload, ["allowed_blocks"]);
  const blockOptions = (() => {
    const known = blocks.map((block) => ({ value: block.name, label: `${block.title || block.name} (${block.name})` }));
    const names = new Set(known.map((item) => item.value));
    return [...known, ...selectedBlocks.filter((name) => !names.has(name)).map((name) => ({ value: name, label: `${name} (${__("saved but not currently registered", TEXT_DOMAIN)})` }))];
  })();

  return <Stack gap="md">
    <EditorIntro title={__("Blueprint", TEXT_DOMAIN)} text={__("Defines one content type: where drafts are created, which patterns and blocks are allowed, their required order, and the content constraints Composer validates.", TEXT_DOMAIN)} />
    <SimpleGrid cols={{ base: 1, sm: 2 }}>
      <TextInput label={__("Display label", TEXT_DOMAIN)} description={fieldDescription(__("Human-readable name shown in Composer.", TEXT_DOMAIN), "blueprint-identity", help)} placeholder={__("Service landing page", TEXT_DOMAIN)} value={stringAt(payload, ["label"])} onChange={(event) => update(["label"], event.currentTarget.value)} readOnly={immutable} />
      <TextInput label={__("Page type key", TEXT_DOMAIN)} description={fieldDescription(__("Stable key used by draft abilities.", TEXT_DOMAIN), "blueprint-identity", help)} placeholder="service" value={stringAt(payload, ["page_type"])} onChange={(event) => update(["page_type"], event.currentTarget.value)} readOnly={immutable} />
      <TextInput label={__("Target post type", TEXT_DOMAIN)} description={fieldDescription(__("Registered WordPress post type that receives the draft.", TEXT_DOMAIN), "blueprint-identity", help)} placeholder="page" value={stringAt(payload, ["target_post_type"])} onChange={(event) => update(["target_post_type"], event.currentTarget.value)} readOnly={immutable} />
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
      <Select label={__("Assignment mode", TEXT_DOMAIN)} description={fieldDescription(__("Use hierarchy or pin an assigned template.", TEXT_DOMAIN), "blueprint-template", help)} value={stringAt(payload, ["target_template", "mode"]) || null} clearable data={["hierarchy", "assigned"]} onChange={(value) => update(["target_template", "mode"], value || "")} readOnly={immutable} />
      <TextInput label={__("Template file or slug", TEXT_DOMAIN)} description={fieldDescription(__("Registered template slug used when assigned.", TEXT_DOMAIN), "blueprint-template", help)} placeholder="page-no-title" value={stringAt(payload, ["target_template", "file"]) || stringAt(payload, ["target_template", "slug"])} onChange={(event) => update(["target_template", "file"], event.currentTarget.value)} readOnly={immutable} />
    </SimpleGrid>
    <TagsInput label={__("Allowed patterns", TEXT_DOMAIN)} description={fieldDescription(__("Pattern slugs Composer may use for this page type.", TEXT_DOMAIN), "blueprint-patterns", help)} placeholder="theme-slug/pattern-name" value={stringsAt(payload, ["allowed_patterns"])} onChange={(value) => update(["allowed_patterns"], value)} readOnly={immutable} clearable />
    <TagsInput label={__("Required pattern sequence", TEXT_DOMAIN)} description={fieldDescription(__("Ordered minimum skeleton; every item must also be allowed.", TEXT_DOMAIN), "blueprint-patterns", help)} placeholder="theme-slug/hero" value={stringsAt(payload, ["required_sequence"])} onChange={(value) => update(["required_sequence"], value)} readOnly={immutable} clearable />
    <MultiSelect label={__("Allowed blocks", TEXT_DOMAIN)} description={fieldDescription(__("Final Gutenberg block allowlist for assembled candidates.", TEXT_DOMAIN), "blueprint-blocks", help)} placeholder={__("Search registered blocks", TEXT_DOMAIN)} value={selectedBlocks} onChange={(value) => update(["allowed_blocks"], value)} data={blockOptions} searchable clearable readOnly={immutable} nothingFoundMessage={__("No registered block matches this search", TEXT_DOMAIN)} />
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

function SiteContractFields({ payload, update, immutable, help }: FieldsProps) {
  const constraints = [
    ["exactly_one_h1", __("Require exactly one H1", TEXT_DOMAIN)],
    ["inline_css", __("Allow inline CSS", TEXT_DOMAIN)],
    ["custom_html", __("Allow Custom HTML", TEXT_DOMAIN)],
    ["shortcodes", __("Allow shortcodes", TEXT_DOMAIN)],
    ["external_embeds", __("Allow external embeds", TEXT_DOMAIN)],
    ["theme_presets_only", __("Require theme presets", TEXT_DOMAIN)]
  ] as const;
  return <Stack gap="md">
    <EditorIntro title={__("Site Contract", TEXT_DOMAIN)} text={__("Sets the global safety, brand, accessibility, media, layout, and content policy inherited by every blueprint in this Config Set.", TEXT_DOMAIN)} />
    <SimpleGrid cols={{ base: 1, sm: 2 }}>
      <TextInput label={__("Contract label", TEXT_DOMAIN)} description={fieldDescription(__("Human-readable name of the site-wide contract.", TEXT_DOMAIN), "site-identity", help)} placeholder={__("Public site content contract", TEXT_DOMAIN)} value={stringAt(payload, ["label"])} onChange={(event) => update(["label"], event.currentTarget.value)} readOnly={immutable} />
      <TextInput label={__("Policy name", TEXT_DOMAIN)} description={fieldDescription(__("Stable machine-readable design policy name.", TEXT_DOMAIN), "site-identity", help)} placeholder="site-design-policy" value={stringAt(payload, ["design_policy", "policy_name"])} onChange={(event) => update(["design_policy", "policy_name"], event.currentTarget.value)} readOnly={immutable} />
      <TextInput label={__("Policy version", TEXT_DOMAIN)} description={fieldDescription(__("Increment when policy meaning changes.", TEXT_DOMAIN), "site-identity", help)} placeholder="1.0.0" value={stringAt(payload, ["design_policy", "policy_version"])} onChange={(event) => update(["design_policy", "policy_version"], event.currentTarget.value)} readOnly={immutable} />
      <TextInput label={__("Content language (optional)", TEXT_DOMAIN)} description={fieldDescription(__("Advisory BCP 47 language exposed to agents for public generated copy; this does not translate content automatically.", TEXT_DOMAIN), "site-language", help)} placeholder="en-US" value={stringAt(payload, ["design_policy", "content_language"])} onChange={(event) => update(["design_policy", "content_language"], event.currentTarget.value)} readOnly={immutable} />
      <TextInput label={__("Operator language (optional)", TEXT_DOMAIN)} description={fieldDescription(__("Advisory BCP 47 language for operator-facing instructions when the connected client supports it.", TEXT_DOMAIN), "site-language", help)} placeholder="hu-HU" value={stringAt(payload, ["design_policy", "operator_language"])} onChange={(event) => update(["design_policy", "operator_language"], event.currentTarget.value)} readOnly={immutable} />
      <NumberInput label={__("Global maximum words", TEXT_DOMAIN)} description={fieldDescription(__("Outer word ceiling inherited by every Blueprint.", TEXT_DOMAIN), "site-language", help)} placeholder="3000" min={1} max={100000} value={numberAt(payload, ["design_policy", "constraints", "maximum_words"])} onChange={(value) => update(["design_policy", "constraints", "maximum_words"], Number(value) || 0)} readOnly={immutable} />
    </SimpleGrid>
    <TagsInput label={__("Allowed pattern namespaces", TEXT_DOMAIN)} description={fieldDescription(__("Trusted namespaces from themes, Composer, or approved plugins.", TEXT_DOMAIN), "site-pattern-scope", help)} placeholder="theme-slug" value={stringsAt(payload, ["design_policy", "allowed_pattern_namespaces"])} onChange={(value) => update(["design_policy", "allowed_pattern_namespaces"], value)} readOnly={immutable} clearable />
    <TagsInput label={__("Disallowed blocks", TEXT_DOMAIN)} description={fieldDescription(__("Site-wide denylist; it overrides Blueprint allowlists.", TEXT_DOMAIN), "site-pattern-scope", help)} placeholder="core/shortcode" value={stringsAt(payload, ["design_policy", "disallowed_blocks"])} onChange={(value) => update(["design_policy", "disallowed_blocks"], value)} readOnly={immutable} clearable />
    <HelpHeading title={__("Global safety constraints", TEXT_DOMAIN)} topic="site-safety" help={help} />
    <SimpleGrid cols={{ base: 1, sm: 2, lg: 3 }}>
      {constraints.map(([key, label]) => <Switch key={key} label={label} checked={booleanAt(payload, ["design_policy", "constraints", key])} onChange={(event) => { if (!immutable) update(["design_policy", "constraints", key], event.currentTarget.checked); }} readOnly={immutable} />)}
    </SimpleGrid>
    <LongList label={__("Brand tone", TEXT_DOMAIN)} description={__("One durable writing characteristic per line.", TEXT_DOMAIN)} placeholder={__("Clear and direct\nEvidence-led\nTechnically credible", TEXT_DOMAIN)} topic="site-brand" help={help} value={stringsAt(payload, ["design_policy", "brand", "tone"])} change={(value) => update(["design_policy", "brand", "tone"], value)} readOnly={immutable} />
    <LongList label={__("Avoid", TEXT_DOMAIN)} description={__("One prohibited claim or writing habit per line.", TEXT_DOMAIN)} placeholder={__("Unsupported guarantees\nInvented statistics\nArtificial urgency", TEXT_DOMAIN)} topic="site-brand" help={help} value={stringsAt(payload, ["design_policy", "brand", "avoid"])} change={(value) => update(["design_policy", "brand", "avoid"], value)} readOnly={immutable} />
    <LongList label={__("Global layout rules", TEXT_DOMAIN)} description={__("Inherited presentation invariants, one per line.", TEXT_DOMAIN)} placeholder={__("Use one Gutenberg content root.\nUse only active-theme preset design values.\nKeep heading levels sequential below the single H1.", TEXT_DOMAIN)} topic="site-layout" help={help} value={stringsAt(payload, ["design_policy", "layout", "rules"])} change={(value) => update(["design_policy", "layout", "rules"], value)} readOnly={immutable} />
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
  return <Textarea label={label} description={fieldDescription(description, topic, help)} placeholder={placeholder} value={value.join("\n")} onChange={(event) => change(event.currentTarget.value.split("\n").map((line) => line.trim()).filter(Boolean))} autosize minRows={3} maxRows={12} readOnly={readOnly} />;
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
