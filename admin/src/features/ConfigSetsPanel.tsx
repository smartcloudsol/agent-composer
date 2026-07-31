import {
  Alert,
  Badge,
  Box,
  Button,
  Card,
  Checkbox,
  Code,
  Collapse,
  Group,
  Modal,
  Pagination,
  SimpleGrid,
  Stack,
  Table,
  Text,
  TextInput,
  Title
} from "@mantine/core";
import { useDisclosure } from "@mantine/hooks";
import {
  IconAlertTriangle,
  IconChecks,
  IconChevronDown,
  IconDatabase,
  IconFileDescription,
  IconHistory,
  IconPower,
  IconTrash,
  IconSettings
} from "@tabler/icons-react";
import { __ } from "@wordpress/i18n";
import { useEffect, useState } from "react";
import {
  activateConfigSet,
  cloneConfigSet,
  createConfigSet,
  deactivateConfigSet,
  deleteConfigSet,
  diffConfigSet,
  exportConfigSet,
  instantiatePreset,
  listPresets,
  rollbackConfigSet,
  validateConfigSet
} from "../api";
import type { ComposerPreset, ConfigDiff, ConfigSet, ValidationReport } from "../api";
import { SectionHeading, StatusCard } from "../AdminUi";
import { downloadJson, formatTimestamp } from "../admin-utils";
import type { SectionProps } from "../feature-contract";

const TEXT_DOMAIN = "smartcloud-agent-composer";

export function ConfigSetsPanel({ sets, selectedId, selectedSet, chooseSet, run, refreshSets, setNotice, status }: SectionProps) {
  const [label, setLabel] = useState("");
  const [validation, setValidation] = useState<ValidationReport | null>(null);
  const [diff, setDiff] = useState<ConfigDiff | null>(null);
  const [rollbackOpened, setRollbackOpened] = useState(false);
  const [maintenanceAction, setMaintenanceAction] = useState<"deactivate" | "delete" | null>(null);
  const [maintenanceConfirmation, setMaintenanceConfirmation] = useState("");
  const [maintenanceAcknowledged, setMaintenanceAcknowledged] = useState(false);
  const [page, setPage] = useState(1);
  const [presets, setPresets] = useState<ComposerPreset[]>([]);
  const [presetLabel, setPresetLabel] = useState("");
  const [presetsOpened, { toggle: togglePresets }] = useDisclosure(false);
  const [showArchived, setShowArchived] = useState(false);
  const listedSets = showArchived ? sets : sets.filter((set) => set.lifecycle !== "archived");
  const pageSize = 8;
  const pageCount = Math.max(1, Math.ceil(listedSets.length / pageSize));
  const visibleSets = listedSets.slice((page - 1) * pageSize, page * pageSize);
  const activeSet = sets.find((set) => set.config_set === status.active_config_set);

  useEffect(() => { listPresets().then((result) => setPresets(result.items)).catch(() => undefined); }, []);

  return <Stack gap="md">
    <SectionHeading title={__("Config sets", TEXT_DOMAIN)} description={__("Manage the complete validated configuration lifecycle.", TEXT_DOMAIN)} icon={<IconSettings size={21} />} />
    <Card withBorder radius="md" p="md"><Stack gap="sm">
      <Group justify="space-between" align="flex-start" wrap="wrap">
        <Box style={{ flex: "1 1 420px", minWidth: 0 }}><Title order={3}>{__("Start from a preset", TEXT_DOMAIN)}</Title>
          <Text size="sm" c="dimmed" mt={4}>{__("Choose the starting contract that best matches this site. Every option creates a new inactive, editable Config Set that you can review before explicit validation and activation.", TEXT_DOMAIN)}</Text></Box>
        <Button
          variant="subtle"
          onClick={togglePresets}
          aria-expanded={presetsOpened}
          miw={150}
          style={{ flexShrink: 0 }}
          rightSection={<IconChevronDown size={16} style={{ transform: presetsOpened ? "rotate(180deg)" : undefined, transition: "transform 150ms ease" }} />}
        >{presetsOpened ? __("Hide presets", TEXT_DOMAIN) : __("Show presets", TEXT_DOMAIN)}</Button>
      </Group>
      <Collapse in={presetsOpened}>
        <Stack gap="sm" pt="xs">
          <Alert color="blue" variant="light" title={__("Which starting point should I use?", TEXT_DOMAIN)}>
            {__("SmartCloud Recommended is the best general starting point. Choose Universal Gutenberg for the smallest, most portable structure, or Detected Theme Starter when preserving the active theme's own full-page layout matters most.", TEXT_DOMAIN)}
          </Alert>
          <SimpleGrid cols={{ base: 1, lg: 3 }}>
            {presets.map((preset) => {
              const guidance = presetGuidance(preset);
              return <Card key={preset.id} withBorder radius="md" p="md"><Stack gap="sm" h="100%">
              <Group gap={6} mih={22}><Badge variant="light" color={preset.kind === "generated" ? "violet" : "blue"}>{guidance.scope}</Badge>
                {preset.id === "smartcloud-recommended" && <Badge variant="light" color="teal">{__("Recommended", TEXT_DOMAIN)}</Badge>}</Group>
              <Text fw={700}>{preset.label}</Text>
              <Text size="sm" c="dimmed">{preset.description}</Text>
              <Box p="sm" bg="var(--mantine-color-gray-0)" style={{ borderRadius: "var(--mantine-radius-sm)", flex: 1 }}>
                <Stack gap={8}>
                  <div><Text size="xs" fw={700} tt="uppercase" c="dimmed">{__("Best for", TEXT_DOMAIN)}</Text><Text size="sm">{guidance.bestFor}</Text></div>
                  <div><Text size="xs" fw={700} tt="uppercase" c="dimmed">{__("Starting structure", TEXT_DOMAIN)}</Text><Text size="sm">{guidance.structure}</Text></div>
                  <div><Text size="xs" fw={700} tt="uppercase" c="dimmed">{__("Portability", TEXT_DOMAIN)}</Text><Text size="sm">{guidance.portability}</Text></div>
                </Stack>
              </Box>
              {preset.theme && <Text size="xs" c="dimmed">{`${__("Active theme", TEXT_DOMAIN)}: ${preset.theme.name} ${preset.theme.version}`}</Text>}
              {!preset.available && preset.availability_reason && <Alert color="yellow" variant="light" p="sm">{preset.availability_reason}</Alert>}
              <Button variant="default" disabled={!preset.available} onClick={() => run(async () => {
                const result = await instantiatePreset(preset.id, presetLabel);
                setPresetLabel("");
                await refreshSets(result.config_set.config_set);
                setNotice(__("Preset copied to a new inactive, editable Config Set.", TEXT_DOMAIN));
              })}>{preset.available ? __("Create editable set", TEXT_DOMAIN) : __("Unavailable for this theme", TEXT_DOMAIN)}</Button>
            </Stack></Card>;
            })}
          </SimpleGrid>
          <TextInput label={__("Optional name for the new Config Set", TEXT_DOMAIN)} description={__("This changes only the name shown in Composer. Leave it empty to use the preset name; the new set remains inactive and editable.", TEXT_DOMAIN)} value={presetLabel} onChange={(event) => setPresetLabel(event.currentTarget.value)} />
        </Stack>
      </Collapse>
    </Stack></Card>
    <Card withBorder radius="md" p="md"><Stack gap="sm">
      <Group justify="space-between" align="flex-end" wrap="wrap" gap="sm"><div><Title order={3}>{__("Configuration versions", TEXT_DOMAIN)}</Title>
        <Text size="sm" c="dimmed" mt={4}>{__("Choose an editable or validated configuration to inspect, compare, export, or activate. A replaced active configuration is archived automatically.", TEXT_DOMAIN)}</Text></div>
        <Checkbox label={__("Show archived", TEXT_DOMAIN)} checked={showArchived} onChange={(event) => { setShowArchived(event.currentTarget.checked); setPage(1); }} /></Group>
      <Table.ScrollContainer minWidth={760}><Table striped highlightOnHover>
        <Table.Thead><Table.Tr><Table.Th>{__("Name", TEXT_DOMAIN)}</Table.Th><Table.Th>{__("Stable ID", TEXT_DOMAIN)}</Table.Th><Table.Th>{__("Lifecycle", TEXT_DOMAIN)}</Table.Th><Table.Th>{__("Entities", TEXT_DOMAIN)}</Table.Th><Table.Th>{__("Modified", TEXT_DOMAIN)}</Table.Th><Table.Th>{__("Action", TEXT_DOMAIN)}</Table.Th></Table.Tr></Table.Thead>
        <Table.Tbody>{visibleSets.map((set) => <Table.Tr key={set.config_set} bg={set.config_set === selectedId ? "var(--mantine-color-blue-light)" : undefined}>
          <Table.Td><Text fw={set.config_set === selectedId ? 700 : 500}>{set.label}</Text></Table.Td>
          <Table.Td><Code>{set.config_set}</Code></Table.Td>
          <Table.Td><Badge color={set.lifecycle === "active" ? "teal" : set.lifecycle === "archived" ? "gray" : "blue"} variant="light">{formatLifecycle(set.lifecycle)}</Badge></Table.Td>
          <Table.Td>{String(set.entity_count ?? 0)}</Table.Td><Table.Td>{formatTimestamp(set.modified_gmt)}</Table.Td>
          <Table.Td><Button size="xs" variant={set.config_set === selectedId ? "filled" : "default"} onClick={() => void chooseSet(set.config_set)}>{set.config_set === selectedId ? __("Selected", TEXT_DOMAIN) : __("Inspect", TEXT_DOMAIN)}</Button></Table.Td>
        </Table.Tr>)}</Table.Tbody>
      </Table></Table.ScrollContainer>
      {pageCount > 1 && <Pagination value={Math.min(page, pageCount)} onChange={setPage} total={pageCount} withEdges />}
      <Group align="end" wrap="wrap">
        <TextInput label={__("New set or clone label", TEXT_DOMAIN)} value={label} onChange={(event) => setLabel(event.currentTarget.value)} style={{ flex: 1, minWidth: 240 }} />
        <Button variant="default" onClick={() => run(async () => { const created = await createConfigSet(label); setLabel(""); await refreshSets(created.config_set); setNotice(__("Editable Config Set created.", TEXT_DOMAIN)); })}>{__("New", TEXT_DOMAIN)}</Button>
        <Button variant="default" disabled={!selectedId} onClick={() => run(async () => { const cloned = await cloneConfigSet(selectedId, label); setLabel(""); await refreshSets(cloned.config_set); setNotice(__("Immutable copy created.", TEXT_DOMAIN)); })}>{__("Clone", TEXT_DOMAIN)}</Button>
      </Group>
    </Stack></Card>

    {selectedSet && <>
      <SimpleGrid cols={{ base: 1, sm: 2, lg: 4 }}>
        <StatusCard label={__("Lifecycle", TEXT_DOMAIN)} value={formatLifecycle(selectedSet.lifecycle)} icon={<IconChecks size={18} />} />
        <StatusCard label={__("Entities", TEXT_DOMAIN)} value={String(selectedSet.entities?.length || 0)} icon={<IconFileDescription size={18} />} />
        <StatusCard label={__("Config hash", TEXT_DOMAIN)} value={selectedSet.config_hash} icon={<IconDatabase size={18} />} />
        <StatusCard label={__("Modified", TEXT_DOMAIN)} value={formatTimestamp(selectedSet.modified_gmt)} icon={<IconHistory size={18} />} />
      </SimpleGrid>
      {selectedSet.lifecycle === "archived" && <Alert color="blue" title={__("Archived rollback candidate", TEXT_DOMAIN)}>
        {`${__("Restoring this set will revalidate it, make it active, and archive the currently active set", TEXT_DOMAIN)} “${activeSet?.label || status.active_config_set}”.`}
      </Alert>}
      <Group wrap="wrap">
        <Button onClick={() => run(async () => { const report = await validateConfigSet(selectedId); setValidation(report); await refreshSets(selectedId); })}>{__("Validate", TEXT_DOMAIN)}</Button>
        <Button color="teal" disabled={selectedSet.lifecycle === "active"} onClick={() => run(async () => { await activateConfigSet(selectedId); await refreshSets(selectedId); setNotice(__("Config set validated again and activated atomically.", TEXT_DOMAIN)); })}>{__("Activate", TEXT_DOMAIN)}</Button>
        <Button variant="default" disabled={selectedSet.lifecycle !== "archived"} onClick={() => setRollbackOpened(true)}>{__("Restore archived as active", TEXT_DOMAIN)}</Button>
        <Button variant="default" disabled={!status.active_config_set || status.active_config_set === selectedId} onClick={() => run(async () => setDiff(await diffConfigSet(status.active_config_set, selectedId)))}>{__("Compare to active", TEXT_DOMAIN)}</Button>
        <Button variant="default" onClick={() => run(async () => { downloadJson(`${selectedId}.json`, await exportConfigSet(selectedId)); setNotice(__("Checksum-protected package exported.", TEXT_DOMAIN)); })}>{__("Export", TEXT_DOMAIN)}</Button>
        {selectedSet.lifecycle === "active"
          ? <Button variant="outline" color="red" leftSection={<IconPower size={16} />} onClick={() => { setMaintenanceConfirmation(""); setMaintenanceAcknowledged(false); setMaintenanceAction("deactivate"); }}>{__("Deactivate Composer", TEXT_DOMAIN)}</Button>
          : <Button variant="outline" color="red" leftSection={<IconTrash size={16} />} onClick={() => { setMaintenanceConfirmation(""); setMaintenanceAcknowledged(false); setMaintenanceAction("delete"); }}>{__("Delete Config Set", TEXT_DOMAIN)}</Button>}
      </Group>
    </>}

    {validation && <ValidationResult report={validation} />}
    {diff && <ConfigDiffCard diff={diff} sets={sets} />}
    <Modal opened={rollbackOpened} onClose={() => setRollbackOpened(false)} title={__("Restore archived configuration?", TEXT_DOMAIN)} centered>
      <Stack gap="md">
        <Text>{`${__("Rollback target", TEXT_DOMAIN)}: ${selectedSet?.label || selectedId}`}</Text>
        <Text>{`${__("Currently active", TEXT_DOMAIN)}: ${activeSet?.label || status.active_config_set}`}</Text>
        <Alert color="yellow">{__("Composer will validate the archived target against the current theme and providers before changing anything. If validation fails, the active configuration stays unchanged.", TEXT_DOMAIN)}</Alert>
        <Group justify="flex-end"><Button variant="default" onClick={() => setRollbackOpened(false)}>{__("Cancel", TEXT_DOMAIN)}</Button>
          <Button color="teal" onClick={() => run(async () => { await rollbackConfigSet(selectedId); setRollbackOpened(false); await refreshSets(selectedId); setNotice(__("Archived set revalidated and restored as the active configuration.", TEXT_DOMAIN)); })}>{__("Validate and restore", TEXT_DOMAIN)}</Button></Group>
      </Stack>
    </Modal>
    <Modal opened={maintenanceAction !== null} onClose={() => setMaintenanceAction(null)} title={maintenanceAction === "deactivate" ? __("Deactivate Composer configuration?", TEXT_DOMAIN) : __("Permanently delete Config Set?", TEXT_DOMAIN)} centered>
      <Stack gap="md">
        <Alert color="red" icon={<IconAlertTriangle size={18} />}>
          {maintenanceAction === "deactivate"
            ? __("Composer draft execution will have no active configuration until another validated Config Set is activated. No Config Set entity is deleted by this step.", TEXT_DOMAIN)
            : __("Every entity in this inactive Config Set will be permanently deleted. The append-only audit history is retained.", TEXT_DOMAIN)}
        </Alert>
        <Text>{__("Type the complete stable ID to confirm:", TEXT_DOMAIN)} <Code>{selectedId}</Code></Text>
        <TextInput value={maintenanceConfirmation} onChange={(event) => setMaintenanceConfirmation(event.currentTarget.value)} autoComplete="off" />
        <Checkbox checked={maintenanceAcknowledged} onChange={(event) => setMaintenanceAcknowledged(event.currentTarget.checked)} label={maintenanceAction === "deactivate" ? __("I understand that Composer will remain inactive until another Config Set is activated.", TEXT_DOMAIN) : __("I understand that this Config Set and all of its entities will be permanently deleted.", TEXT_DOMAIN)} />
        <Group justify="flex-end"><Button variant="default" onClick={() => setMaintenanceAction(null)}>{__("Cancel", TEXT_DOMAIN)}</Button>
          <Button color="red" disabled={!selectedSet || maintenanceConfirmation !== selectedId || !maintenanceAcknowledged} onClick={() => run(async () => {
            if (!selectedSet || !maintenanceAction) return;
            if (maintenanceAction === "deactivate") {
              await deactivateConfigSet(selectedId, selectedSet.config_hash, maintenanceConfirmation);
              setNotice(__("Composer configuration deactivated. No Config Set entity was deleted.", TEXT_DOMAIN));
            } else {
              await deleteConfigSet(selectedId, selectedSet.config_hash, maintenanceConfirmation);
              setNotice(__("Inactive Config Set and all of its entities were permanently deleted.", TEXT_DOMAIN));
            }
            setMaintenanceAction(null);
            setMaintenanceConfirmation("");
            setMaintenanceAcknowledged(false);
            setValidation(null);
            setDiff(null);
            await refreshSets("");
          })}>{maintenanceAction === "deactivate" ? __("Deactivate", TEXT_DOMAIN) : __("Delete permanently", TEXT_DOMAIN)}</Button></Group>
      </Stack>
    </Modal>
  </Stack>;
}

function presetGuidance(preset: ComposerPreset): { scope: string; bestFor: string; structure: string; portability: string } {
  if (preset.id === "universal-gutenberg") return {
    scope: __("Portable", TEXT_DOMAIN),
    bestFor: __("Maximum compatibility, a first integration test, or sites that need a deliberately small contract.", TEXT_DOMAIN),
    structure: __("Three required core-block patterns: hero, flexible content, and closing action.", TEXT_DOMAIN),
    portability: __("Theme-neutral. It can be moved between block themes and then revalidated on the destination site.", TEXT_DOMAIN)
  };
  if (preset.id === "smartcloud-recommended") return {
    scope: __("Portable", TEXT_DOMAIN),
    bestFor: __("Most marketing and information pages that benefit from a useful structure without depending on one theme.", TEXT_DOMAIN),
    structure: __("Hero, feature grid, steps, optional FAQ, and closing action; all use stable core blocks.", TEXT_DOMAIN),
    portability: __("Theme-neutral and richer than Universal Gutenberg. Revalidate it after moving to another site.", TEXT_DOMAIN)
  };
  return {
    scope: __("Theme-derived", TEXT_DOMAIN),
    bestFor: __("A site whose active theme already provides a safe full-page pattern worth using as the design starting point.", TEXT_DOMAIN),
    structure: preset.pattern
      ? `${__("One theme pattern", TEXT_DOMAIN)}: ${preset.pattern.title}`
      : __("One safe one-root theme pattern without its own H1; Composer supplies the governed page heading.", TEXT_DOMAIN),
    portability: __("Site-local. It records the active theme and capability fingerprints and must be reviewed after theme changes.", TEXT_DOMAIN)
  };
}

function ConfigDiffCard({ diff, sets }: { diff: ConfigDiff; sets: ConfigSet[] }) {
  const label = (id: string) => sets.find((set) => set.config_set === id)?.label || id;
  const total = diff.summary.added + diff.summary.removed + diff.summary.changed;
  return <Card withBorder radius="md" p="md"><Stack gap="sm">
    <Title order={3}>{__("Changes if the selected set becomes active", TEXT_DOMAIN)}</Title>
    <Text>{`${label(diff.from)} → ${label(diff.to)}`}</Text>
    <Alert color="blue">{__("Added means the selected target introduces an entity; removed means it omits an entity that is active now; changed means both contain the entity with different content.", TEXT_DOMAIN)}</Alert>
    <SimpleGrid cols={{ base: 1, sm: 3 }}>
      <StatusCard label={__("Added by selected", TEXT_DOMAIN)} value={String(diff.summary.added)} icon={<IconFileDescription size={18} />} />
      <StatusCard label={__("Removed by selected", TEXT_DOMAIN)} value={String(diff.summary.removed)} icon={<IconFileDescription size={18} />} />
      <StatusCard label={__("Changed by selected", TEXT_DOMAIN)} value={String(diff.summary.changed)} icon={<IconHistory size={18} />} />
    </SimpleGrid>
    {total === 0 ? <Alert color="teal">{__("The selected and active sets contain the same entity content.", TEXT_DOMAIN)}</Alert> : <>
      <DiffItems title={__("Added", TEXT_DOMAIN)} items={diff.added.map((item) => `${item.type}: ${item.key}`)} />
      <DiffItems title={__("Removed", TEXT_DOMAIN)} items={diff.removed.map((item) => `${item.type}: ${item.key}`)} />
      <DiffItems title={__("Changed", TEXT_DOMAIN)} items={diff.changed.map((item) => item.identity)} />
    </>}
  </Stack></Card>;
}

function DiffItems({ title, items }: { title: string; items: string[] }) {
  if (items.length === 0) return null;
  return <div><Text fw={700}>{title}</Text><Stack gap={4} mt={4}>{items.map((item) => <Code key={item} block>{item}</Code>)}</Stack></div>;
}

function ValidationResult({ report }: { report: ValidationReport }) {
  return <Alert color={report.valid ? "teal" : "red"} icon={report.valid ? <IconChecks size={18} /> : <IconAlertTriangle size={18} />}
    title={report.valid ? __("Validation passed", TEXT_DOMAIN) : __("Validation failed", TEXT_DOMAIN)}>
    <Text size="sm">{`${report.entity_count} entities · ${report.page_type_count} blueprints · ${report.provider_count} providers`}</Text>
    {[...report.errors, ...report.warnings].map((issue) => <Text size="sm" key={`${issue.code}:${issue.path}`}>{`${issue.code}${issue.path ? ` (${issue.path})` : ""}: ${issue.message}`}</Text>)}
  </Alert>;
}

function formatLifecycle(value: string): string {
  const labels: Record<string, string> = {
    active: __("Active", TEXT_DOMAIN),
    working: __("Editable", TEXT_DOMAIN),
    valid: __("Validated", TEXT_DOMAIN),
    invalid: __("Needs attention", TEXT_DOMAIN),
    archived: __("Archived", TEXT_DOMAIN)
  };
  return labels[value] || value;
}
