import {
  Accordion,
  Alert,
  Badge,
  Box,
  Button,
  Card,
  Code,
  Container,
  FileButton,
  Grid,
  Group,
  Modal,
  NavLink,
  Pagination,
  SimpleGrid,
  Skeleton,
  Stack,
  Table,
  Text,
  Textarea,
  Title
} from "@mantine/core";
import {
  IconAlertTriangle,
  IconBook2,
  IconChecks,
  IconDatabase,
  IconFileDescription,
  IconGitMerge,
  IconHistory,
  IconPlugConnected,
  IconRefresh,
  IconSettings,
  IconShieldCheck
} from "@tabler/icons-react";
import { useDisclosure } from "@mantine/hooks";
import { notifications } from "@mantine/notifications";
import { __ } from "@wordpress/i18n";
import { useEffect, useMemo, useState } from "react";
import { auditUtcDateTime, formatAuditLocalTime } from "./auditTime";
import {
  exportConfigBackup,
  getConfigSet,
  importConfigPackage,
  listConfigSets,
  loadAudit,
  loadComposerStatus,
  loadDiscovery,
  runDiscovery
} from "./api";
import type {
  AuditEvent,
  ComposerRuntimeStatus,
  ConfigEntity,
  ConfigSet,
  ProviderDiscovery
} from "./api";
import DocSidebar from "./DocSidebar";
import type { DocTopic } from "./DocSidebar";
import Onboarding from "./Onboarding";
import { SectionHeading, StatusCard } from "./AdminUi";
import { downloadJson } from "./admin-utils";
import type { Section, SectionProps } from "./feature-contract";
import { ConfigSetsPanel, ConfigurationBlueprintsPanel, ContentProposalsPanel, McpAccessPanel } from "./features";

const TEXT_DOMAIN = "smartcloud-agent-composer";

export function App() {
  const [status, setStatus] = useState<ComposerRuntimeStatus | null>(null);
  const [sets, setSets] = useState<ConfigSet[]>([]);
  const [selectedId, setSelectedId] = useState("");
  const [selectedSet, setSelectedSet] = useState<ConfigSet | null>(null);
  const [loading, setLoading] = useState(true);
  const [pendingAction, setPendingAction] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [section, setSection] = useState<Section>("overview");
  const [entityChangesPending, setEntityChangesPending] = useState(false);
  const [docsOpened, { open: openDocs, close: closeDocs }] = useDisclosure(false);
  const [docsTopic, setDocsTopic] = useState<DocTopic | null>(null);

  const showDocs = (topic: DocTopic | null = null) => {
    setDocsTopic(topic);
    openDocs();
  };

  const refreshSets = async (preferred = selectedId) => {
    const result = await listConfigSets();
    setSets(result.items);
    const next = preferred || result.active || result.items[0]?.config_set || "";
    setSelectedId(next);
    if (next) setSelectedSet(await getConfigSet(next));
    else setSelectedSet(null);
  };

  useEffect(() => {
    let active = true;
    Promise.all([loadComposerStatus(), listConfigSets()])
      .then(async ([runtime, list]) => {
        if (!active) return;
        setStatus(runtime);
        setSets(list.items);
        const initial = list.active || list.items[0]?.config_set || "";
        setSelectedId(initial);
        if (initial) setSelectedSet(await getConfigSet(initial));
      })
      .catch((reason: unknown) => active && setError(errorMessage(reason)))
      .finally(() => active && setLoading(false));
    return () => { active = false; };
  }, []);

  const chooseSet = async (id: string | null) => {
    const value = id || "";
    setSelectedId(value);
    setSelectedSet(null);
    if (!value) return;
    try {
      setSelectedSet(await getConfigSet(value));
    } catch (reason) {
      setError(errorMessage(reason));
    }
  };

  const run = async (operation: () => Promise<void>, action = "composer-operation") => {
    setPendingAction(action);
    setError(null);
    try {
      await operation();
    } catch (reason) {
      setError(errorMessage(reason));
    } finally {
      setPendingAction(null);
    }
  };

  const showNotice = (message: string) => notifications.show({
    title: __("Agent Composer", TEXT_DOMAIN),
    message,
    color: "green"
  });

  const navigation = useMemo(() => [
    { id: "overview" as const, label: __("Overview", TEXT_DOMAIN), icon: IconChecks },
    { id: "configuration" as const, label: __("Config sets", TEXT_DOMAIN), icon: IconSettings },
    { id: "blueprints" as const, label: __("Configuration & blueprints", TEXT_DOMAIN), icon: IconFileDescription },
    { id: "proposals" as const, label: __("Content proposals", TEXT_DOMAIN), icon: IconGitMerge },
    { id: "mcp-access" as const, label: __("MCP Access", TEXT_DOMAIN), icon: IconShieldCheck },
    { id: "providers" as const, label: __("Theme & providers", TEXT_DOMAIN), icon: IconPlugConnected },
    { id: "audit" as const, label: __("Audit & portability", TEXT_DOMAIN), icon: IconHistory }
  ], []);

  const navLinks = navigation.map((item) => (
    <NavLink key={item.id} active={section === item.id} label={item.label} leftSection={<item.icon size={17} />}
      onClick={() => {
        if (entityChangesPending && item.id !== section && !window.confirm(__("Discard the staged Config Set modifications and leave this page?", TEXT_DOMAIN))) return;
        if (item.id !== section) setEntityChangesPending(false);
        setSection(item.id);
      }} color="blue" variant="light" />
  ));

  return (
    <Container size={1440} py="xl" px={{ base: "sm", sm: "md" }}
      style={{ alignSelf: "flex-start", marginInline: 0, width: "100%" }}>
      <Stack gap="md">
        <Card withBorder radius="md" p="lg">
          <Stack gap="sm">
            <Group justify="space-between" align="flex-start" wrap="wrap">
              <div>
                <Title order={1} c="blue.6">{__("SmartCloud Agent Composer", TEXT_DOMAIN)}</Title>
                <Text c="dimmed" mt={6}>{__("Governed Gutenberg drafts and human-reviewed published-content proposals.", TEXT_DOMAIN)}</Text>
              </div>
              {!status && <Badge color="gray" variant="light" size="lg">{__("Checking", TEXT_DOMAIN)}</Badge>}
            </Group>
            <Text>{__("Create, review, validate, activate, compare, export, import, and roll back versioned site contracts without bypassing WordPress permissions or audit controls.", TEXT_DOMAIN)}</Text>
            <Group mt="xs">
              <Button onClick={() => showDocs()} variant="default" leftSection={<IconBook2 size={16} />}>{__("Documentation", TEXT_DOMAIN)}</Button>
            </Group>
          </Stack>
        </Card>

        <Onboarding />

        {error && <Alert icon={<IconAlertTriangle size={18} />} color="red" withCloseButton onClose={() => setError(null)}>{error}</Alert>}

        <Box hiddenFrom="md">
          <Accordion variant="contained" defaultValue="composer-navigation"><Accordion.Item value="composer-navigation">
            <Accordion.Control><Text fw={600}>{__("Agent Composer", TEXT_DOMAIN)}</Text></Accordion.Control>
            <Accordion.Panel><Stack gap={4}>{navLinks}</Stack></Accordion.Panel>
          </Accordion.Item></Accordion>
        </Box>

        <Grid gutter="md" align="flex-start">
          <Grid.Col span={{ base: 12, md: 3 }} visibleFrom="md">
            <Card withBorder radius="md" p="md">
              <Text c="dimmed" fw={600} size="sm" mb="sm">{__("Agent Composer", TEXT_DOMAIN)}</Text>
              <Stack gap={4}>{navLinks}</Stack>
            </Card>
          </Grid.Col>
          <Grid.Col span={{ base: 12, md: 9 }}>
            {loading && <SectionSkeleton />}
            {!loading && status && (
              <SectionContent section={section} status={status} sets={sets} selectedId={selectedId} selectedSet={selectedSet}
                chooseSet={chooseSet} run={run} pendingAction={pendingAction} refreshSets={refreshSets} setNotice={showNotice} setEntityChangesPending={setEntityChangesPending} showDocs={showDocs} />
            )}
          </Grid.Col>
        </Grid>
        <DocSidebar opened={docsOpened} close={() => { closeDocs(); setDocsTopic(null); }} page={section} topic={docsTopic} />
      </Stack>
    </Container>
  );
}

function SectionSkeleton() {
  return <Stack gap="md" aria-label={__("Loading Composer", TEXT_DOMAIN)} aria-busy="true">
    <Group gap="xs"><Skeleton circle height={28} /><Skeleton height={28} width="42%" /></Group>
    <Skeleton height={18} width="68%" />
    <SimpleGrid cols={{ base: 1, sm: 2, lg: 3 }}>
      {Array.from({ length: 6 }, (_, index) => <Card key={index} withBorder radius="md" p="lg"><Stack gap="sm"><Skeleton height={18} width="58%" /><Skeleton height={16} width="82%" /></Stack></Card>)}
    </SimpleGrid>
  </Stack>;
}

function SectionContent(props: SectionProps) {
  if (props.section === "configuration") return <ConfigSetsPanel {...props} />;
  if (props.section === "blueprints") return <ConfigurationBlueprintsPanel key={props.selectedId} {...props} />;
  if (props.section === "proposals") return <ContentProposalsPanel showDocs={props.showDocs} />;
  if (props.section === "mcp-access") return <McpAccessPanel showDocs={props.showDocs} />;
  if (props.section === "providers") return <DiscoveryPanel {...props} />;
  if (props.section === "audit") return <AuditPanel {...props} />;
  return <OverviewPanel {...props} />;
}

function OverviewPanel({ status, sets, showDocs }: SectionProps) {
  return <Stack gap="md">
    <SectionHeading title={__("Overview", TEXT_DOMAIN)} description={__("Current configuration and execution readiness.", TEXT_DOMAIN)} icon={<IconChecks size={21} />} openDocumentation={() => showDocs()} />
    <SimpleGrid cols={{ base: 1, sm: 2, lg: 3 }}>
      <StatusCard label={__("Plugin version", TEXT_DOMAIN)} value={status.version} icon={<IconChecks size={18} />} />
      <StatusCard label={__("Active config", TEXT_DOMAIN)} value={status.active_config_set || __("Not activated", TEXT_DOMAIN)} icon={<IconDatabase size={18} />} />
      <StatusCard label={__("Config sets", TEXT_DOMAIN)} value={String(sets.length)} icon={<IconFileDescription size={18} />} />
      <StatusCard label={__("Content access", TEXT_DOMAIN)} value={__("Governed per post type", TEXT_DOMAIN)} icon={<IconShieldCheck size={18} />} />
      <StatusCard label={__("MCP endpoint", TEXT_DOMAIN)} value={status.mcp_endpoint} icon={<IconPlugConnected size={18} />} />
      <StatusCard label={__("Multisite configuration", TEXT_DOMAIN)} value={__("Per site", TEXT_DOMAIN)} icon={<IconDatabase size={18} />} />
    </SimpleGrid>
  </Stack>;
}

function PatternInventory({ entities, discovery }: { entities: ConfigEntity[]; discovery: ProviderDiscovery | null }) {
  const [page, setPage] = useState(1);
  const registeredPatterns = new Set((discovery?.registered_patterns || []).map((pattern) => pattern.name));
  const syncedRecords = new Map((discovery?.synced_pattern_records || []).map((record) => [record.post_name, record]));
  const manifestPatterns = new Set(Array.isArray(discovery?.theme.manifest.patterns) ? discovery.theme.manifest.patterns.filter((value): value is string => typeof value === "string") : []);
  const siteContract = entities.find((item) => item.type === "site-contract");
  const designPolicy = siteContract?.payload.design_policy && typeof siteContract.payload.design_policy === "object" && !Array.isArray(siteContract.payload.design_policy)
    ? siteContract.payload.design_policy as Record<string, unknown>
    : {};
  const syncedDefinitions = designPolicy.synced_structural_patterns && typeof designPolicy.synced_structural_patterns === "object" && !Array.isArray(designPolicy.synced_structural_patterns)
    ? designPolicy.synced_structural_patterns as Record<string, unknown>
    : {};
  const usage = new Map<string, { allowed: string[]; required: string[]; syncedBy: string[] }>();
  for (const entity of entities.filter((item) => item.type === "blueprint")) {
    const allowed = Array.isArray(entity.payload.allowed_patterns) ? entity.payload.allowed_patterns : [];
    const required = new Set(Array.isArray(entity.payload.required_sequence) ? entity.payload.required_sequence : []);
    const synced = new Set(Array.isArray(entity.payload.synced_patterns) ? entity.payload.synced_patterns : []);
    for (const value of allowed) {
      if (typeof value !== "string") continue;
      const entry = usage.get(value) || { allowed: [], required: [], syncedBy: [] };
      entry.allowed.push(entity.key);
      if (required.has(value)) entry.required.push(entity.key);
      if (synced.has(value)) entry.syncedBy.push(entity.key);
      usage.set(value, entry);
    }
  }
  const rows = [...usage.entries()].sort(([left], [right]) => left.localeCompare(right));
  const pageSize = 10;
  const pageCount = Math.max(1, Math.ceil(rows.length / pageSize));
  const visible = rows.slice((page - 1) * pageSize, page * pageSize);

  return <Stack gap="md">
    <Alert color="blue" title={__("How pattern availability works", TEXT_DOMAIN)}>{__("Registered patterns come from the active theme or a plugin and must appear in the WordPress pattern registry. Synced structural patterns are local WordPress wp_block records governed by the Site Contract; they do not appear in that registry and do not need a theme-manifest declaration. Missing is shown only when the source required for that pattern type is unavailable.", TEXT_DOMAIN)}</Alert>
    {rows.length === 0 ? <Alert color="yellow">{__("No blueprint in this Config Set references a pattern yet.", TEXT_DOMAIN)}</Alert> : <>
      <Table.ScrollContainer minWidth={1040}><Table striped highlightOnHover><Table.Thead><Table.Tr><Table.Th>{__("Pattern slug", TEXT_DOMAIN)}</Table.Th><Table.Th>{__("Pattern kind", TEXT_DOMAIN)}</Table.Th><Table.Th>{__("Allowed by blueprints", TEXT_DOMAIN)}</Table.Th><Table.Th>{__("Required by", TEXT_DOMAIN)}</Table.Th><Table.Th>{__("WordPress availability", TEXT_DOMAIN)}</Table.Th><Table.Th>{__("Theme manifest", TEXT_DOMAIN)}</Table.Th></Table.Tr></Table.Thead>
        <Table.Tbody>{visible.map(([name, item]) => {
          const isSynced = item.syncedBy.length > 0;
          const definition = syncedDefinitions[name];
          const postName = definition && typeof definition === "object" && !Array.isArray(definition) && typeof (definition as Record<string, unknown>).post_name === "string"
            ? (definition as Record<string, unknown>).post_name as string
            : "";
          const record = postName ? syncedRecords.get(postName) : undefined;
          const available = isSynced ? record?.post_status === "publish" : registeredPatterns.has(name);
          const availabilityLabel = available
            ? isSynced ? __("Published synced record", TEXT_DOMAIN) : __("Registered", TEXT_DOMAIN)
            : isSynced && record ? `${__("Unavailable", TEXT_DOMAIN)} (${record.post_status})` : isSynced && !postName ? __("Contract definition missing", TEXT_DOMAIN) : __("Missing", TEXT_DOMAIN);
          return <Table.Tr key={name}>
            <Table.Td><Code>{name}</Code></Table.Td>
            <Table.Td><Badge color={isSynced ? "violet" : "blue"} variant="light">{isSynced ? __("Synced structural", TEXT_DOMAIN) : __("Registered pattern", TEXT_DOMAIN)}</Badge></Table.Td>
            <Table.Td>{item.allowed.join(", ")}</Table.Td>
            <Table.Td>{item.required.length ? item.required.join(", ") : __("Optional only", TEXT_DOMAIN)}</Table.Td>
            <Table.Td><Badge color={available ? "teal" : record ? "yellow" : "red"} variant="light">{availabilityLabel}</Badge></Table.Td>
            <Table.Td><Badge color={isSynced ? "gray" : manifestPatterns.has(name) ? "teal" : "gray"} variant="light">{isSynced ? __("Not applicable", TEXT_DOMAIN) : manifestPatterns.has(name) ? __("Declared", TEXT_DOMAIN) : __("Not declared", TEXT_DOMAIN)}</Badge></Table.Td>
          </Table.Tr>;
        })}</Table.Tbody>
      </Table></Table.ScrollContainer>
      {pageCount > 1 && <Pagination value={Math.min(page, pageCount)} onChange={setPage} total={pageCount} withEdges />}
    </>}
    <Text size="sm" c="dimmed">{__("For registered patterns, the theme manifest may publish titles, descriptions, categories, wrapper classes, slots, and required blocks. Synced structural patterns instead use their Site Contract definition and the publication state of the referenced local wp_block record.", TEXT_DOMAIN)}</Text>
  </Stack>;
}

function DiscoveryPanel({ selectedSet, run, pendingAction, setNotice, showDocs }: SectionProps) {
  const [discovery, setDiscovery] = useState<ProviderDiscovery | null>(null);
  const [discoveryLoading, setDiscoveryLoading] = useState(true);
  useEffect(() => { loadDiscovery().then(setDiscovery).catch(() => undefined).finally(() => setDiscoveryLoading(false)); }, []);
  const contentTypeEligible = (item: ProviderDiscovery["registered_post_types"][number]) =>
    item.public && item.show_ui && item.show_in_rest && item.supports_editor && item.current_user_can_edit;
  return <Stack gap="md">
    <SectionHeading title={__("Theme & providers", TEXT_DOMAIN)} description={__("Inspect the local theme manifest, provider Ability profiles, and runtime readiness.", TEXT_DOMAIN)} icon={<IconPlugConnected size={21} />} openDocumentation={() => showDocs()} />
    <Group><Button variant="default" leftSection={<IconRefresh size={16} />} loading={pendingAction === "rescan-site"} onClick={() => run(async () => { setDiscovery(await runDiscovery()); setNotice(__("Immutable discovery snapshot created.", TEXT_DOMAIN)); }, "rescan-site")}>{__("Rescan site", TEXT_DOMAIN)}</Button></Group>
    {discoveryLoading && !discovery && <DiscoverySkeleton />}
    {discovery && <>
      <SimpleGrid cols={{ base: 1, sm: 2 }}><StatusCard label={__("Theme", TEXT_DOMAIN)} value={`${discovery.theme.name} ${discovery.theme.version}`} icon={<IconSettings size={18} />} />
        <StatusCard label={__("Theme manifest", TEXT_DOMAIN)} value={discovery.theme.manifest_status} icon={<IconFileDescription size={18} />} />
        <StatusCard label={__("Registered patterns", TEXT_DOMAIN)} value={String((discovery.registered_patterns || []).length)} icon={<IconFileDescription size={18} />} />
        <StatusCard label={__("Registered templates", TEXT_DOMAIN)} value={String((discovery.registered_templates || []).length)} icon={<IconFileDescription size={18} />} />
        <StatusCard label={__("Eligible content types", TEXT_DOMAIN)} value={String((discovery.registered_post_types || []).filter(contentTypeEligible).length)} icon={<IconDatabase size={18} />} />
        <StatusCard label={__("Registered blocks", TEXT_DOMAIN)} value={String(discovery.registered_blocks.length)} icon={<IconDatabase size={18} />} />
        <StatusCard label={__("Provider profiles", TEXT_DOMAIN)} value={String(discovery.provider_profiles)} icon={<IconPlugConnected size={18} />} />
        <StatusCard label={__("Capability fingerprint", TEXT_DOMAIN)} value={discovery.site_capability_fingerprint} icon={<IconDatabase size={18} />} /></SimpleGrid>
      {discovery.providers.length === 0 ? <Alert color="yellow">{__("No provider execution manifests are currently available.", TEXT_DOMAIN)}</Alert> :
        discovery.providers.map((provider) => <Card key={provider.id} withBorder radius="md" p="md"><Group justify="space-between"><div><Text fw={700}>{provider.label}</Text><Text size="sm" c="dimmed">{`${provider.contract_version} · ${provider.ability_names.length} abilities`}</Text></div>
          <Badge color={provider.runtime?.runtime_ready ? "teal" : "yellow"}>{provider.runtime?.runtime_ready ? __("Ready", TEXT_DOMAIN) : __("Review", TEXT_DOMAIN)}</Badge></Group></Card>)}
      <Card withBorder radius="md" p="md"><Stack gap="sm">
        <Title order={3}>{__("Registered content types", TEXT_DOMAIN)}</Title>
        <Text size="sm" c="dimmed">{__("These are the public wp-admin content types discovered after the last site scan. Eligible types can receive a matching Blueprint and explicit content or field access in the Site Contract.", TEXT_DOMAIN)}</Text>
        <Table.ScrollContainer minWidth={760}><Table striped highlightOnHover><Table.Thead><Table.Tr>
          <Table.Th>{__("Content type", TEXT_DOMAIN)}</Table.Th><Table.Th>{__("Slug", TEXT_DOMAIN)}</Table.Th><Table.Th>{__("Owner", TEXT_DOMAIN)}</Table.Th><Table.Th>{__("Gutenberg / REST", TEXT_DOMAIN)}</Table.Th><Table.Th>{__("Registered fields", TEXT_DOMAIN)}</Table.Th><Table.Th>{__("Composer", TEXT_DOMAIN)}</Table.Th>
        </Table.Tr></Table.Thead><Table.Tbody>
          {discovery.registered_post_types.map((item) => <Table.Tr key={item.name}>
            <Table.Td><Text fw={600}>{item.label}</Text></Table.Td><Table.Td><Code>{item.name}</Code></Table.Td>
            <Table.Td>{item.builtin ? __("WordPress", TEXT_DOMAIN) : __("Plugin / site", TEXT_DOMAIN)}</Table.Td>
            <Table.Td><Badge color={item.show_in_rest && item.supports_editor ? "teal" : "yellow"} variant="light">{item.show_in_rest && item.supports_editor ? __("Ready", TEXT_DOMAIN) : __("Incomplete", TEXT_DOMAIN)}</Badge></Table.Td>
            <Table.Td>{String(item.registered_meta.length)}</Table.Td>
            <Table.Td><Badge color={contentTypeEligible(item) ? "teal" : "gray"} variant="light">{contentTypeEligible(item) ? __("Eligible", TEXT_DOMAIN) : __("Unavailable", TEXT_DOMAIN)}</Badge></Table.Td>
          </Table.Tr>)}
        </Table.Tbody></Table></Table.ScrollContainer>
      </Stack></Card>
      <PatternInventory entities={selectedSet?.entities || []} discovery={discovery} />
    </>}
  </Stack>;
}

function DiscoverySkeleton() {
  return <SimpleGrid cols={{ base: 1, sm: 2 }} aria-busy="true">
    {Array.from({ length: 8 }, (_, index) => <Card key={index} withBorder radius="md" p="lg"><Stack gap="sm"><Skeleton height={17} width="54%" /><Skeleton height={16} width="76%" /></Stack></Card>)}
  </SimpleGrid>;
}

function AuditPanel({ run, pendingAction, refreshSets, setNotice, showDocs }: SectionProps) {
  const [events, setEvents] = useState<AuditEvent[]>([]);
  const [auditLoading, setAuditLoading] = useState(true);
  const [selectedEvent, setSelectedEvent] = useState<AuditEvent | null>(null);
  const [packageJson, setPackageJson] = useState("");
  const refresh = async () => { const result = await loadAudit(); setEvents(result.items); setAuditLoading(false); };
  useEffect(() => {
    let active = true;
    loadAudit()
      .then((result) => { if (active) setEvents(result.items); })
      .catch(() => undefined)
      .finally(() => { if (active) setAuditLoading(false); });
    return () => { active = false; };
  }, []);
  return <Stack gap="md">
    <SectionHeading title={__("Audit & portability", TEXT_DOMAIN)} description={__("Back up all configuration, restore inactive editable sets, and review redacted hash-chained events.", TEXT_DOMAIN)} icon={<IconHistory size={21} />} openDocumentation={() => showDocs()} />
    <Card withBorder radius="md" p="md"><Stack gap="sm"><Title order={3}>{__("Configuration backup", TEXT_DOMAIN)}</Title>
      <Text size="sm">{__("Download every config set in one checksum-protected JSON bundle. Secrets are rejected and the site-specific audit chain is intentionally excluded.", TEXT_DOMAIN)}</Text>
      <Group><Button variant="default" loading={pendingAction === "export-backup"} onClick={() => run(async () => { const date = new Date().toISOString().slice(0, 10); downloadJson(`smartcloud-agent-composer-backup-${date}.json`, await exportConfigBackup()); setNotice(__("Complete configuration backup exported.", TEXT_DOMAIN)); }, "export-backup")}>{__("Export all configuration", TEXT_DOMAIN)}</Button></Group>
    </Stack></Card>
    <Card withBorder radius="md" p="md"><Stack gap="sm"><Title order={3}>{__("Restore configuration", TEXT_DOMAIN)}</Title>
      <Text size="sm">{__("Choose or paste a single config package or complete backup bundle. External URL downloads are not accepted, secrets are rejected, and restored sets are never activated automatically.", TEXT_DOMAIN)}</Text>
      <Textarea value={packageJson} onChange={(event) => setPackageJson(event.currentTarget.value)} autosize minRows={6} styles={{ input: { fontFamily: "monospace" } }} placeholder="{ ... }" />
      <Group><FileButton accept="application/json,.json" onChange={(file) => { if (file) void file.text().then(setPackageJson); }}>{(props) => <Button variant="default" {...props}>{__("Choose JSON file", TEXT_DOMAIN)}</Button>}</FileButton>
        <Button disabled={!packageJson.trim()} loading={pendingAction === "restore-backup"} onClick={() => run(async () => { const result = await importConfigPackage(parseJsonOrThrow(packageJson)); const restored = "config_set" in result ? [result.config_set] : result.config_sets; setPackageJson(""); await refreshSets(restored[0]); await refresh(); setNotice(restored.length === 1 ? __("Package restored as an inactive editable set.", TEXT_DOMAIN) : __("Backup restored atomically as inactive editable sets.", TEXT_DOMAIN)); }, "restore-backup")}>{__("Validate & restore", TEXT_DOMAIN)}</Button></Group>
    </Stack></Card>
    <Group justify="space-between"><Title order={3}>{__("Latest audit events", TEXT_DOMAIN)}</Title><Button variant="default" size="xs" leftSection={<IconRefresh size={14} />} loading={pendingAction === "refresh-audit"} onClick={() => run(refresh, "refresh-audit")}>{__("Refresh", TEXT_DOMAIN)}</Button></Group>
    <Table.ScrollContainer minWidth={820}><Table striped highlightOnHover><Table.Thead><Table.Tr><Table.Th>{__("Local time", TEXT_DOMAIN)}</Table.Th><Table.Th>{__("Event", TEXT_DOMAIN)}</Table.Th><Table.Th>{__("Outcome", TEXT_DOMAIN)}</Table.Th><Table.Th>{__("Actor", TEXT_DOMAIN)}</Table.Th><Table.Th>{__("Hash", TEXT_DOMAIN)}</Table.Th><Table.Th>{__("Actions", TEXT_DOMAIN)}</Table.Th></Table.Tr></Table.Thead>
      <Table.Tbody>{auditLoading && events.length === 0 ? <AuditRowsSkeleton /> : events.map((event) => <Table.Tr key={event.event_uuid}><Table.Td><time dateTime={auditUtcDateTime(event.created_gmt)} title={`${event.created_gmt} UTC`}>{formatAuditLocalTime(event.created_gmt)}</time></Table.Td><Table.Td>{event.event_type}</Table.Td><Table.Td><Badge color={event.outcome === "success" ? "teal" : "red"}>{event.outcome}</Badge></Table.Td><Table.Td>{event.actor_user_id}</Table.Td><Table.Td><Code>{event.event_hash.slice(0, 16)}…</Code></Table.Td><Table.Td><Button variant="subtle" size="xs" onClick={() => setSelectedEvent(event)}>{__("Details", TEXT_DOMAIN)}</Button></Table.Td></Table.Tr>)}</Table.Tbody>
    </Table></Table.ScrollContainer>
    <Modal opened={selectedEvent !== null} onClose={() => setSelectedEvent(null)} title={__("Audit event details", TEXT_DOMAIN)} size="xl" centered>
      {selectedEvent && <Stack gap="md">
        <SimpleGrid cols={{ base: 1, sm: 2 }}>
          <AuditDetail label={__("Local time", TEXT_DOMAIN)} value={formatAuditLocalTime(selectedEvent.created_gmt)} />
          <AuditDetail label={__("UTC time", TEXT_DOMAIN)} value={`${selectedEvent.created_gmt} UTC`} />
          <AuditDetail label={__("Event", TEXT_DOMAIN)} value={selectedEvent.event_type} />
          <AuditDetail label={__("Outcome", TEXT_DOMAIN)} value={selectedEvent.outcome} />
          <AuditDetail label={__("Actor user ID", TEXT_DOMAIN)} value={String(selectedEvent.actor_user_id)} />
          <AuditDetail label={__("Config Set ID", TEXT_DOMAIN)} value={String(selectedEvent.config_set_id)} />
          <AuditDetail label={__("Entity ID", TEXT_DOMAIN)} value={String(selectedEvent.entity_id)} />
        </SimpleGrid>
        <AuditDetail label={__("Event UUID", TEXT_DOMAIN)} value={selectedEvent.event_uuid} />
        <AuditDetail label={__("Request ID", TEXT_DOMAIN)} value={selectedEvent.request_id} />
        <AuditDetail label={__("Previous hash", TEXT_DOMAIN)} value={selectedEvent.previous_hash || __(
          "Beginning of audit chain",
          TEXT_DOMAIN
        )} />
        <AuditDetail label={__("Event hash", TEXT_DOMAIN)} value={selectedEvent.event_hash} />
        <div>
          <Text size="xs" c="dimmed" fw={600} mb={4}>{__("Redacted context", TEXT_DOMAIN)}</Text>
          <Code block style={{ whiteSpace: "pre-wrap", overflowWrap: "anywhere", maxHeight: 420, overflow: "auto" }}>{JSON.stringify(selectedEvent.context, null, 2)}</Code>
        </div>
      </Stack>}
    </Modal>
  </Stack>;
}

function AuditRowsSkeleton() {
  return <>{Array.from({ length: 6 }, (_, row) => <Table.Tr key={row}>{Array.from({ length: 6 }, (_, column) => <Table.Td key={column}><Skeleton height={14} width={column === 1 ? "72%" : "58%"} /></Table.Td>)}</Table.Tr>)}</>;
}

function AuditDetail({ label, value }: { label: string; value: string }) {
  return <div><Text size="xs" c="dimmed" fw={600} mb={4}>{label}</Text><Code block style={{ whiteSpace: "pre-wrap", overflowWrap: "anywhere" }}>{value}</Code></div>;
}

function parseJson(value: string): Record<string, unknown> | null {
  try { const parsed: unknown = JSON.parse(value); return parsed && typeof parsed === "object" && !Array.isArray(parsed) ? parsed as Record<string, unknown> : null; } catch { return null; }
}

function parseJsonOrThrow(value: string): Record<string, unknown> {
  const parsed = parseJson(value);
  if (!parsed) throw new Error(__("Enter a valid JSON object.", TEXT_DOMAIN));
  return parsed;
}

function errorMessage(reason: unknown): string {
  if (reason instanceof Error) return reason.message;
  if (reason && typeof reason === "object" && "message" in reason) return String(reason.message);
  return __("Composer could not complete the operation.", TEXT_DOMAIN);
}
