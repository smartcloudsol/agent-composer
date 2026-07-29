import { Alert, Badge, Box, Button, Card, Code, Group, Modal, SimpleGrid, Stack, Table, Text, TextInput, Title } from "@mantine/core";
import { IconEdit, IconFileDescription, IconPlus, IconShieldCheck, IconTrash } from "@tabler/icons-react";
import { __ } from "@wordpress/i18n";
import { useEffect, useState } from "react";
import { applyEntityChanges, loadDiscovery } from "../api";
import type { ConfigEntity, EntityChange, ProviderDiscovery } from "../api";
import { SectionHeading } from "../AdminUi";
import { formatTimestamp } from "../admin-utils";
import GuidedEntityEditor from "../EntityEditor";
import type { SectionProps } from "../feature-contract";

const TEXT_DOMAIN = "smartcloud-agent-composer";

export function ConfigurationBlueprintsPanel({ selectedSet, selectedId, run, refreshSets, setNotice, setEntityChangesPending, showDocs }: SectionProps) {
  const baseEntities = selectedSet?.entities || [];
  const [editorEntityId, setEditorEntityId] = useState<string | null>(null);
  const [editorDirty, setEditorDirty] = useState(false);
  const [discardOpened, setDiscardOpened] = useState(false);
  const [deleteTarget, setDeleteTarget] = useState<ConfigEntity | null>(null);
  const [addOpened, setAddOpened] = useState(false);
  const [discardAllOpened, setDiscardAllOpened] = useState(false);
  const [stagedUpdates, setStagedUpdates] = useState<Record<string, Record<string, unknown>>>({});
  const [stagedCreates, setStagedCreates] = useState<ConfigEntity[]>([]);
  const [stagedDeletes, setStagedDeletes] = useState<Set<string>>(new Set());
  const [page, setPage] = useState(1);
  const [newKey, setNewKey] = useState("");
  const [discovery, setDiscovery] = useState<ProviderDiscovery | null>(null);
  const entityIdentity = (entity: Pick<ConfigEntity, "type" | "key">) => `${entity.type}:${entity.key}`;
  const entities = [
    ...baseEntities.map((entity) => {
      const payload = stagedUpdates[entityIdentity(entity)];
      return payload ? { ...entity, payload, label: typeof payload.label === "string" ? payload.label : entity.label } : entity;
    }),
    ...stagedCreates
  ];
  const stagedCount = Object.keys(stagedUpdates).length + stagedCreates.length + stagedDeletes.size;
  const configEntity = entities.find((entity) => entity.type === "config-set") || null;
  const siteContractEntity = entities.find((entity) => entity.type === "site-contract") || null;
  const blueprints = entities.filter((entity) => entity.type === "blueprint");
  const pageSize = 8;
  const pageCount = Math.max(1, Math.ceil(blueprints.length / pageSize));
  const safePage = Math.min(page, pageCount);
  const visible = blueprints.slice((safePage - 1) * pageSize, safePage * pageSize);
  const editorEntity = entities.find((entity) => String(entity.id) === editorEntityId) || null;
  const immutable = selectedSet?.lifecycle === "active";

  useEffect(() => { loadDiscovery().then(setDiscovery).catch(() => undefined); }, []);
  useEffect(() => {
    if (stagedCount === 0) return;
    const protect = (event: BeforeUnloadEvent) => { event.preventDefault(); };
    window.addEventListener("beforeunload", protect);
    return () => window.removeEventListener("beforeunload", protect);
  }, [stagedCount]);
  const requestEditorClose = () => {
    if (editorDirty) setDiscardOpened(true);
    else setEditorEntityId(null);
  };

  const markPending = () => setEntityChangesPending(true);
  const entityStatus = (entity: ConfigEntity): "added" | "modified" | "deleted" | null => entity.id < 0
    ? "added"
    : stagedDeletes.has(entityIdentity(entity))
      ? "deleted"
      : stagedUpdates[entityIdentity(entity)]
        ? "modified"
        : null;
  const clearStaged = () => {
    setStagedUpdates({});
    setStagedCreates([]);
    setStagedDeletes(new Set());
    setEntityChangesPending(false);
  };
  const duplicateNewKey = entities.some((entity) => entity.type === "blueprint" && entity.key === newKey.trim().toLowerCase());

  const stageEditorPayload = async (entity: ConfigEntity, payload: Record<string, unknown>) => {
    if (entity.id < 0) {
      setStagedCreates((items) => items.map((item) => item.id === entity.id ? { ...item, payload, label: typeof payload.label === "string" ? payload.label : item.label } : item));
      markPending();
    } else {
      const identity = entityIdentity(entity);
      const original = baseEntities.find((item) => entityIdentity(item) === identity);
      setStagedUpdates((items) => {
        const next = { ...items };
        if (original && stableJson(original.payload) === stableJson(payload)) delete next[identity];
        else next[identity] = payload;
        setEntityChangesPending(Object.keys(next).length + stagedCreates.length + stagedDeletes.size > 0);
        return next;
      });
    }
    setEditorDirty(false);
    setEditorEntityId(null);
  };

  const applyStaged = async () => {
    const changes: EntityChange[] = [];
    for (const entity of baseEntities) {
      const identity = entityIdentity(entity);
      if (stagedDeletes.has(identity)) changes.push({ action: "delete", type: entity.type, key: entity.key, entity_revision: entity.entity_revision, content_hash: entity.content_hash });
      else if (stagedUpdates[identity]) changes.push({ action: "update", type: entity.type, key: entity.key, payload: stagedUpdates[identity], entity_revision: entity.entity_revision, content_hash: entity.content_hash });
    }
    for (const entity of stagedCreates) changes.push({ action: "create", type: entity.type, key: entity.key, payload: entity.payload });
    let applied = false;
    await run(async () => {
      const result = await applyEntityChanges(selectedId, changes);
      applied = true;
      clearStaged();
      await refreshSets(selectedId);
      setNotice(`${__("Modifications applied as one Config Set changeset", TEXT_DOMAIN)}: ${result.summary.created} ${__("added", TEXT_DOMAIN)}, ${result.summary.updated} ${__("updated", TEXT_DOMAIN)}, ${result.summary.deleted} ${__("deleted", TEXT_DOMAIN)}.`);
    });
    if (applied) setPage(1);
  };

  return <Stack gap="md">
    <SectionHeading title={__("Configuration & blueprints", TEXT_DOMAIN)} description={__("Review the selected Config Set manifest and its single Site Contract, then manage the page-type Blueprints governed by them.", TEXT_DOMAIN)} icon={<IconFileDescription size={21} />} />
    {!selectedSet && <Alert color="yellow">{__("Select or create a config set first.", TEXT_DOMAIN)}</Alert>}
    {selectedSet && <>
      <Card withBorder radius="md" p="md" bg="blue.0">
        <Group justify="space-between" align="flex-start" wrap="wrap">
          <div>
            <Text size="xs" tt="uppercase" fw={700} c="blue.8">{__("Current editing context", TEXT_DOMAIN)}</Text>
            <Title order={3} mt={3}>{selectedSet.label}</Title>
            <Group gap="xs" mt={6}><Code>{selectedSet.config_set}</Code><Badge color={immutable ? "teal" : "blue"} variant="light">{selectedSet.lifecycle}</Badge></Group>
          </div>
          <Text size="sm" maw={430}>{immutable
            ? __("This active Config Set is read-only. Clone it from Config sets before making changes.", TEXT_DOMAIN)
            : __("Edits, additions, and deletions are staged locally. Apply the complete changeset below to create at most one new revision for each affected entity, then validate the Config Set before activation.", TEXT_DOMAIN)}</Text>
        </Group>
      </Card>
      {selectedSet.lifecycle === "active" && <Alert color="blue" icon={<IconShieldCheck size={18} />}>{__("Active configuration is shown in a readable, read-only form. Clone the Config Set to create an editable copy.", TEXT_DOMAIN)}</Alert>}
      <SimpleGrid cols={{ base: 1, md: 2 }}>
        <RequiredEntityCard
          title={__("Config Set settings", TEXT_DOMAIN)}
          description={__("Identity, lifecycle mode, fallback policy, preset origin, and the entity membership of this configuration version.", TEXT_DOMAIN)}
          entity={configEntity}
          immutable={immutable}
          status={configEntity ? entityStatus(configEntity) : null}
          edit={(entity) => { setEditorDirty(false); setEditorEntityId(String(entity.id)); }}
        />
        <RequiredEntityCard
          title={__("Site Contract", TEXT_DOMAIN)}
          description={__("The one site-wide design, content, security, accessibility, media, and post-type policy inherited by every Blueprint.", TEXT_DOMAIN)}
          entity={siteContractEntity}
          immutable={immutable}
          status={siteContractEntity ? entityStatus(siteContractEntity) : null}
          edit={(entity) => { setEditorDirty(false); setEditorEntityId(String(entity.id)); }}
        />
      </SimpleGrid>

      <Group justify="space-between" align="flex-end" wrap="wrap" gap="sm">
        <div><Title order={3}>{`${__("Blueprints", TEXT_DOMAIN)} (${blueprints.length})`}</Title>
          <Text size="sm" c="dimmed" mt={4}>{__("Each Blueprint defines one page type, its target, approved patterns and blocks, content rules, and excerpt policy.", TEXT_DOMAIN)}</Text></div>
        <Button leftSection={<IconPlus size={16} />} disabled={immutable} onClick={() => setAddOpened(true)}>{__("Add blueprint", TEXT_DOMAIN)}</Button>
      </Group>

      <>
        <EntityTable entities={visible} immutable={immutable} selectedId={editorEntityId} status={entityStatus}
          edit={(entity) => { setEditorDirty(false); setEditorEntityId(String(entity.id)); }} requestDelete={setDeleteTarget}
          undoDelete={(entity) => { setStagedDeletes((items) => { const next = new Set(items); next.delete(entityIdentity(entity)); setEntityChangesPending(Object.keys(stagedUpdates).length + stagedCreates.length + next.size > 0); return next; }); }} />
        <Card withBorder radius="md" p="xs">
          <Group justify="space-between" wrap="wrap">
            <Button size="xs" variant="default" disabled={safePage <= 1} onClick={() => setPage(1)}>{__("First", TEXT_DOMAIN)}</Button>
            <Button size="xs" variant="default" disabled={safePage <= 1} onClick={() => setPage((value) => Math.max(1, value - 1))}>{__("Previous", TEXT_DOMAIN)}</Button>
            <Text size="sm" fw={600}>{`${__("Page", TEXT_DOMAIN)} ${safePage} / ${pageCount}`}</Text>
            <Button size="xs" variant="default" disabled={safePage >= pageCount} onClick={() => setPage((value) => Math.min(pageCount, value + 1))}>{__("Next", TEXT_DOMAIN)}</Button>
            <Button size="xs" variant="default" disabled={safePage >= pageCount} onClick={() => setPage(pageCount)}>{__("Last", TEXT_DOMAIN)}</Button>
          </Group>
        </Card>
      </>

      <Modal opened={Boolean(editorEntity)} onClose={requestEditorClose} title={editorEntity ? `${immutable ? __("View", TEXT_DOMAIN) : __("Edit", TEXT_DOMAIN)}: ${editorEntity.label || editorEntity.key}` : ""} size="xl" centered>
        {editorEntity && <Stack gap="sm">
          <Alert color={immutable ? "blue" : "yellow"}>{immutable
            ? __("This is a read-only view of the active Config Set.", TEXT_DOMAIN)
            : __("Changes remain local to this dialog. Stage change returns them to the pending changeset; nothing is written to WordPress until Apply modifications is selected below.", TEXT_DOMAIN)}</Alert>
          <GuidedEntityEditor key={`${editorEntity.id}:${editorEntity.content_hash}`} selected={editorEntity} immutable={immutable}
            blocks={discovery?.registered_blocks || []} onDirtyChange={setEditorDirty}
            help={showDocs}
            save={(payload) => stageEditorPayload(editorEntity, payload)} />
        </Stack>}
      </Modal>

      <Modal opened={discardOpened} onClose={() => setDiscardOpened(false)} title={__("Discard unsaved changes?", TEXT_DOMAIN)} centered>
        <Stack gap="md"><Text>{__("The changes in this editor have not been saved to the Config Set. Closing now will discard them.", TEXT_DOMAIN)}</Text>
          <Group justify="flex-end"><Button variant="default" onClick={() => setDiscardOpened(false)}>{__("Continue editing", TEXT_DOMAIN)}</Button>
            <Button color="red" onClick={() => { setDiscardOpened(false); setEditorDirty(false); setEditorEntityId(null); }}>{__("Discard changes", TEXT_DOMAIN)}</Button></Group>
        </Stack>
      </Modal>

      <Modal opened={Boolean(deleteTarget)} onClose={() => setDeleteTarget(null)} title={__("Delete configuration entity?", TEXT_DOMAIN)} centered>
        <Stack gap="md"><Text>{deleteTarget ? `${deleteTarget.label || deleteTarget.key} (${deleteTarget.key})` : ""}</Text>
          <Alert color="red">{__("This stages the entity for deletion. It remains unchanged in WordPress until Apply modifications. Export the set first if you need a portable recovery copy.", TEXT_DOMAIN)}</Alert>
          <Group justify="flex-end"><Button variant="default" onClick={() => setDeleteTarget(null)}>{__("Cancel", TEXT_DOMAIN)}</Button>
            <Button color="red" leftSection={<IconTrash size={16} />} onClick={() => { if (!deleteTarget) return; const target = deleteTarget; if (target.id < 0) setStagedCreates((items) => { const next = items.filter((item) => item.id !== target.id); setEntityChangesPending(Object.keys(stagedUpdates).length + next.length + stagedDeletes.size > 0); return next; }); else { const identity = entityIdentity(target); setStagedUpdates((items) => { const next = { ...items }; delete next[identity]; return next; }); setStagedDeletes((items) => new Set(items).add(identity)); markPending(); } setDeleteTarget(null); }}>{__("Stage deletion", TEXT_DOMAIN)}</Button></Group>
        </Stack>
      </Modal>

      <Modal opened={addOpened} onClose={() => setAddOpened(false)} title={__("Add blueprint to selected Config Set", TEXT_DOMAIN)} centered>
        <Stack gap="md"><Alert color="blue">{`${selectedSet.label} (${selectedSet.config_set})`}</Alert>
          <TextInput label={__("Stable key", TEXT_DOMAIN)} description={__("Lowercase page type identifier used inside this Config Set.", TEXT_DOMAIN)} value={newKey} onChange={(event) => setNewKey(event.currentTarget.value)} placeholder="page" />
          <Group justify="flex-end"><Button variant="default" onClick={() => setAddOpened(false)}>{__("Cancel", TEXT_DOMAIN)}</Button>
            <Button disabled={immutable || !newKey || duplicateNewKey} onClick={() => { const key = newKey.trim().toLowerCase(); const payload = createEntityPayload("blueprint", key); setStagedCreates((items) => [...items, { id: -(Date.now() + items.length), type: "blueprint", key, config_set: selectedId, label: key, payload, entity_revision: 0, modified_gmt: "", modified_by: 0, content_hash: "", active: false }]); setNewKey(""); setAddOpened(false); markPending(); }}>{__("Stage blueprint", TEXT_DOMAIN)}</Button></Group>
          {duplicateNewKey && <Alert color="red">{__("An entity with this type and stable key already exists in the changeset.", TEXT_DOMAIN)}</Alert>}
        </Stack>
      </Modal>

      <Card withBorder radius="md" p="md" bg={stagedCount > 0 ? "yellow.0" : undefined} style={{ position: "sticky", bottom: 12, zIndex: 20 }}>
        <Group justify="space-between" align="center" wrap="wrap">
          <div><Text fw={700}>{stagedCount > 0 ? __("Pending Config Set modifications", TEXT_DOMAIN) : __("No pending modifications", TEXT_DOMAIN)}</Text>
            <Text size="sm" c="dimmed">{`${stagedCreates.length} ${__("added", TEXT_DOMAIN)} · ${Object.keys(stagedUpdates).length} ${__("modified", TEXT_DOMAIN)} · ${stagedDeletes.size} ${__("marked for deletion", TEXT_DOMAIN)}`}</Text></div>
          <Group><Button variant="default" disabled={stagedCount === 0} onClick={() => setDiscardAllOpened(true)}>{__("Discard", TEXT_DOMAIN)}</Button>
            <Button disabled={stagedCount === 0} onClick={() => void applyStaged()}>{__("Apply modifications", TEXT_DOMAIN)}</Button></Group>
        </Group>
      </Card>

      <Modal opened={discardAllOpened} onClose={() => setDiscardAllOpened(false)} title={__("Discard all staged modifications?", TEXT_DOMAIN)} centered>
        <Stack gap="md"><Text>{__("No entity revision has been created yet. This removes every local edit, addition, and pending deletion from the current changeset.", TEXT_DOMAIN)}</Text>
          <Group justify="flex-end"><Button variant="default" onClick={() => setDiscardAllOpened(false)}>{__("Keep changes", TEXT_DOMAIN)}</Button>
            <Button color="red" onClick={() => { clearStaged(); setDiscardAllOpened(false); }}>{__("Discard all", TEXT_DOMAIN)}</Button></Group>
        </Stack>
      </Modal>
    </>}
  </Stack>;
}
function RequiredEntityCard({ title, description, entity, immutable, status, edit }: { title: string; description: string; entity: ConfigEntity | null; immutable: boolean; status: "added" | "modified" | "deleted" | null; edit: (entity: ConfigEntity) => void }) {
  return <Card withBorder radius="md" p="md"><Stack gap="sm" h="100%">
    <div><Title order={3}>{title}</Title><Text size="sm" c="dimmed" mt={4}>{description}</Text></div>
    {!entity ? <Alert color="red">{__("This required entity is missing. Restore or recreate a valid Config Set before activation.", TEXT_DOMAIN)}</Alert> : <>
      <Box p="sm" bg="var(--mantine-color-gray-0)" style={{ borderRadius: "var(--mantine-radius-sm)", flex: 1 }}>
        <Text fw={700}>{entity.label || entity.key}</Text>
        <Group gap="xs" mt={6} wrap="wrap"><Code>{entity.key}</Code><Text size="sm" c="dimmed">{`${__("Revision", TEXT_DOMAIN)} ${entity.entity_revision || __("New", TEXT_DOMAIN)}`}</Text>
          <Badge color={status === "modified" ? "yellow" : entity.active ? "teal" : "gray"} variant="light">{status === "modified" ? __("Modified", TEXT_DOMAIN) : entity.active ? __("Active", TEXT_DOMAIN) : __("Editable", TEXT_DOMAIN)}</Badge></Group>
      </Box>
      <Button variant="default" leftSection={<IconEdit size={16} />} onClick={() => edit(entity)}>{immutable ? __("View settings", TEXT_DOMAIN) : __("Edit settings", TEXT_DOMAIN)}</Button>
    </>}
  </Stack></Card>;
}

function EntityTable({ entities, selectedId, immutable, status, edit, requestDelete, undoDelete }: { entities: ConfigEntity[]; selectedId: string | null; immutable: boolean; status: (entity: ConfigEntity) => "added" | "modified" | "deleted" | null; edit: (entity: ConfigEntity) => void; requestDelete: (entity: ConfigEntity) => void; undoDelete: (entity: ConfigEntity) => void }) {
  return <Card withBorder radius="md" p={0} style={{ height: 430, overflow: "auto" }}><Table.ScrollContainer minWidth={780}><Table striped highlightOnHover stickyHeader>
    <Table.Thead><Table.Tr><Table.Th>{__("Entity", TEXT_DOMAIN)}</Table.Th><Table.Th>{__("Type", TEXT_DOMAIN)}</Table.Th><Table.Th>{__("Revision", TEXT_DOMAIN)}</Table.Th><Table.Th>{__("Modified", TEXT_DOMAIN)}</Table.Th><Table.Th>{__("Status", TEXT_DOMAIN)}</Table.Th><Table.Th>{__("Action", TEXT_DOMAIN)}</Table.Th></Table.Tr></Table.Thead>
    <Table.Tbody>{entities.length === 0 ? <Table.Tr><Table.Td colSpan={6}><Text c="dimmed" ta="center" py="xl">{__("This Config Set has no entities in this category.", TEXT_DOMAIN)}</Text></Table.Td></Table.Tr> : entities.map((entity) => { const staged = status(entity); return <Table.Tr key={entity.id} bg={staged === "deleted" ? "var(--mantine-color-red-light)" : String(entity.id) === selectedId ? "var(--mantine-color-blue-light)" : undefined}>
      <Table.Td><Text fw={String(entity.id) === selectedId ? 700 : 500}>{entity.label || entity.key}</Text><Code>{entity.key}</Code></Table.Td>
      <Table.Td>{formatEntityType(entity.type)}</Table.Td><Table.Td>{entity.entity_revision || __("New", TEXT_DOMAIN)}</Table.Td><Table.Td>{formatTimestamp(entity.modified_gmt)}</Table.Td>
      <Table.Td><Badge color={staged === "deleted" ? "red" : staged === "added" ? "teal" : staged === "modified" ? "yellow" : entity.active ? "teal" : "gray"} variant="light">{staged === "deleted" ? __("Pending deletion", TEXT_DOMAIN) : staged === "added" ? __("Pending add", TEXT_DOMAIN) : staged === "modified" ? __("Modified", TEXT_DOMAIN) : entity.active ? __("Active", TEXT_DOMAIN) : __("Editable", TEXT_DOMAIN)}</Badge></Table.Td>
      <Table.Td><Group gap="xs" wrap="nowrap"><Button size="xs" variant={String(entity.id) === selectedId ? "filled" : "default"} leftSection={<IconEdit size={14} />} disabled={staged === "deleted"} onClick={() => edit(entity)}>{immutable ? __("View", TEXT_DOMAIN) : __("Edit", TEXT_DOMAIN)}</Button>
        {staged === "deleted" ? <Button size="xs" variant="subtle" onClick={() => undoDelete(entity)}>{__("Undo", TEXT_DOMAIN)}</Button> : <Button size="xs" variant="subtle" color="red" leftSection={<IconTrash size={14} />} disabled={immutable || entity.type === "config-set"} onClick={() => requestDelete(entity)}>{__("Delete", TEXT_DOMAIN)}</Button>}</Group></Table.Td>
    </Table.Tr>; })}</Table.Tbody>
  </Table></Table.ScrollContainer></Card>;
}

function formatEntityType(type: string): string {
  const labels: Record<string, string> = {
    "config-set": __("Config Set", TEXT_DOMAIN),
    "site-contract": __("Site Contract", TEXT_DOMAIN),
    blueprint: __("Blueprint", TEXT_DOMAIN),
    component: __("Component", TEXT_DOMAIN),
    "style-mapping": __("Style mapping", TEXT_DOMAIN),
    "provider-policy": __("Provider policy", TEXT_DOMAIN)
  };
  return labels[type] || type.replaceAll("-", " ").replace(/^./, (first) => first.toUpperCase());
}

function createEntityPayload(type: string, key: string): Record<string, unknown> {
  if (type === "blueprint") {
    return {
      schema_version: "1.0",
      entity_type: "blueprint",
      page_type: key.replace(/^blueprint:/, ""),
      label: key,
      purpose: "",
      target_post_type: "page",
      visual_variant: "page",
      excerpt_policy: "optional",
      allowed_patterns: [],
      required_sequence: [],
      allowed_blocks: ["core/group", "core/heading", "core/paragraph"],
      constraints: { exactly_one_h1: true, inline_css: false, custom_html: false, shortcodes: false, external_embeds: false, theme_presets_only: true, maximum_words: 2200 },
      layout_contract: [],
      content_contract: []
    };
  }
  return { schema_version: "1.0", entity_type: type, key, label: key };
}

function stableJson(value: unknown): string {
  if (Array.isArray(value)) return `[${value.map(stableJson).join(",")}]`;
  if (value && typeof value === "object") {
    const entries = Object.entries(value as Record<string, unknown>)
      .sort(([left], [right]) => left.localeCompare(right))
      .map(([key, item]) => `${JSON.stringify(key)}:${stableJson(item)}`);
    return `{${entries.join(",")}}`;
  }
  return JSON.stringify(value) ?? "undefined";
}
