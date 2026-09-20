import {
  Alert,
  Badge,
  Button,
  Card,
  Code,
  Group,
  List,
  NumberInput,
  Select,
  Skeleton,
  Stack,
  Switch,
  Text,
  TextInput,
  Title
} from "@mantine/core";
import { IconInfoCircle, IconPlus, IconShieldCheck, IconTrash } from "@tabler/icons-react";
import { __ } from "@wordpress/i18n";
import { useEffect, useRef, useState } from "react";
import { loadMcpAccess, saveMcpAccess } from "../api";
import type { McpAccessConfiguration, McpSecuritySettings } from "../api";
import { SectionHeading } from "../AdminUi";
import type { SectionProps } from "../feature-contract";

const TEXT_DOMAIN = "smartcloud-agent-composer";
const roles = [
  { value: "reader", label: __("Reader — inspect only", TEXT_DOMAIN) },
  { value: "contributor", label: __("Contributor — drafts and proposals", TEXT_DOMAIN) },
  { value: "publisher", label: __("Publisher — may request human approval", TEXT_DOMAIN) }
];
type ComposerRole = "reader" | "contributor" | "publisher";
type GroupRoleRow = { id: number; group: string; role: ComposerRole };

export function McpAccessPanel({ showDocs }: Pick<SectionProps, "showDocs">) {
  const [config, setConfig] = useState<McpAccessConfiguration | null>(null);
  const [settings, setSettings] = useState<McpSecuritySettings | null>(null);
  const [busy, setBusy] = useState(true);
  const [message, setMessage] = useState("");
  const [error, setError] = useState("");
  const [groupRows, setGroupRows] = useState<GroupRoleRow[]>([]);
  const nextGroupRowId = useRef(0);

  const rowsFromRoles = (groupRoles: McpSecuritySettings["group_roles"]): GroupRoleRow[] =>
    Object.entries(groupRoles).map(([group, role]) => ({ id: ++nextGroupRowId.current, group, role }));

  useEffect(() => {
    loadMcpAccess().then((value) => {
      setConfig(value);
      setSettings(value.settings);
      setGroupRows(rowsFromRoles(value.settings.group_roles));
    }).catch((reason: unknown) => setError(reason instanceof Error ? reason.message : __("MCP access settings could not be loaded.", TEXT_DOMAIN))).finally(() => setBusy(false));
  }, []);

  if (!settings || !config) return busy ? <McpAccessSkeleton /> : <Alert color="red">{error || __("MCP access settings could not be loaded.", TEXT_DOMAIN)}</Alert>;
  const update = (next: McpSecuritySettings) => { setSettings(next); setMessage(""); setError(""); };
  const updateGroupRows = (rows: GroupRoleRow[]) => {
    setGroupRows(rows);
    update({
      ...settings,
      group_roles: Object.fromEntries(rows.filter((row) => row.group.trim() !== "").map((row) => [row.group, row.role]))
    });
  };
  const manualRequired = settings.identity_provider.prefer_manual || !config.status.provider_configured;
  const resourceUri = settings.oauth_resource_uri.trim().replace(/\/+$/, "");
  const resourceScopes = resourceUri === "" ? [] : ["read", "draft", "propose", "publish.request"].map((scope) => `${resourceUri}/${scope}`);
  const resourceRequired = settings.enforce_scopes && resourceUri === "";

  return <Stack gap="md">
    <SectionHeading title={__("MCP Access", TEXT_DOMAIN)} description={__("Choose who external AI agents may act for and which Composer operations each client may request.", TEXT_DOMAIN)} icon={<IconShieldCheck size={21} />} openDocumentation={() => showDocs()} />
    <Alert icon={<IconInfoCircle size={18} />} color="blue">
      {__("Agents can never publish directly. A Publisher can only create a short-lived request that the human must inspect and confirm for the exact revision.", TEXT_DOMAIN)}
    </Alert>

    <Card withBorder radius="md" p="md"><Stack gap="xs">
      <Group justify="space-between"><Title order={3}>{__("Current protection", TEXT_DOMAIN)}</Title><Badge color={config.status.mode === "OPEN" ? "gray" : "teal"}>{config.status.mode}</Badge></Group>
      <Text size="sm">{config.status.mode === "OPEN"
        ? __("No token is required. Read, draft and proposal tools are available; publish requests are not.", TEXT_DOMAIN)
        : __("A valid Cognito access token, mapped group and allowed OAuth client are required before Composer tools are exposed.", TEXT_DOMAIN)}</Text>
      <Text size="sm" c="dimmed">{__("Readiness:", TEXT_DOMAIN)} {config.status.identity_configured ? __("identity resolved", TEXT_DOMAIN) : __("identity missing", TEXT_DOMAIN)} · {config.status.group_role_count} {__("mapped groups", TEXT_DOMAIN)} · {config.status.client_count} {__("allowed clients", TEXT_DOMAIN)}</Text>
      {config.status.mode === "PROTECTED_REQUIRED" && !config.status.access_ready && <Text c="red" fw={600}>{__("The active Site Contract requires authentication, but the identity, group mapping, or OAuth client allowlist is incomplete. MCP access remains closed until all three are ready.", TEXT_DOMAIN)}</Text>}
    </Stack></Card>

    <Card withBorder radius="md" p="md"><Stack gap="sm">
      <Title order={3}>{__("OAuth resource binding", TEXT_DOMAIN)}</Title>
      <Text size="sm" c="dimmed">{__("Enter the exact canonical resource URI that the external MCP client sends in the OAuth resource parameter. For a ChatGPT tunnel this is the complete tunnel-service /v1/mcp/tunnel_… URL shown in Advanced OAuth settings, not the private WordPress URL and not the tunnel ID alone.", TEXT_DOMAIN)}</Text>
      <TextInput
        required={settings.enforce_scopes}
        label={__("External MCP resource URI", TEXT_DOMAIN)}
        description={__("HTTPS only, without query, fragment, credentials, or a trailing slash. When present, Composer advertises this URI and rejects access tokens whose aud claim does not exactly match it.", TEXT_DOMAIN)}
        placeholder="https://tunnel-service.gateway.unified-0.internal.api.openai.org/v1/mcp/tunnel_…"
        value={settings.oauth_resource_uri}
        error={resourceRequired ? __("Required while OAuth scope enforcement is enabled.", TEXT_DOMAIN) : undefined}
        onChange={(event) => update({ ...settings, oauth_resource_uri: event.currentTarget.value })}
      />
      <Group gap="xs">
        <Badge color={resourceUri ? "teal" : "gray"}>{resourceUri ? __("Audience validation configured", TEXT_DOMAIN) : __("Audience validation not configured", TEXT_DOMAIN)}</Badge>
        {config.status.audience_validation && <Text size="xs" c="dimmed">{__("The saved configuration validates the access-token audience.", TEXT_DOMAIN)}</Text>}
      </Group>
      {resourceScopes.length > 0 && <Alert color="blue" icon={<IconInfoCircle size={18} />}><Stack gap={4}>
        <Text fw={600}>{__("Cognito resource server and App Client scopes", TEXT_DOMAIN)}</Text>
        <Text size="sm">{__("Use the URI above as the Cognito resource-server identifier. Create the four scope names below and allow the resulting full scopes on the dedicated App Client together with openid.", TEXT_DOMAIN)}</Text>
        {resourceScopes.map((scope) => <Code key={scope} block>{scope}</Code>)}
      </Stack></Alert>}
    </Stack></Card>

    <Card withBorder radius="md" p="md"><Stack gap="sm">
      <Title order={3}>{__("Identity provider", TEXT_DOMAIN)}</Title>
      <Text size="sm" c="dimmed">{__("Composer validates Amazon Cognito User Pool access tokens. The resolved values below are the effective runtime configuration, so they also confirm whether automatic detection or the manual fallback is actually working.", TEXT_DOMAIN)}</Text>
      {config.status.identity_configured ? <Alert color="teal"><Stack gap={2}>
        <Text fw={600}>{config.status.identity_source === "provider" ? __("Resolved automatically", TEXT_DOMAIN) : __("Resolved from manual configuration", TEXT_DOMAIN)}</Text>
        <Text size="sm">{__("Effective AWS Region", TEXT_DOMAIN)}: <code>{config.status.region}</code></Text>
        <Text size="sm">{__("Effective User Pool ID", TEXT_DOMAIN)}: <code>{config.status.user_pool_id}</code></Text>
        <Text size="sm">{__("Token issuer", TEXT_DOMAIN)}: <code>{config.status.issuer}</code></Text>
        <Text size="sm">{__("JWKS URL", TEXT_DOMAIN)}: <code>{config.status.jwks_url}</code></Text>
      </Stack></Alert> : <Alert color="red">{__("No valid Cognito provider was resolved. AWS Region and User Pool ID are required before MCP access can be saved.", TEXT_DOMAIN)}</Alert>}
      {settings.identity_provider.prefer_manual && config.status.provider_configured && <Alert color="yellow">{__("An automatic provider is available, but the manual override is active.", TEXT_DOMAIN)} {config.status.provider_user_pool_id} ({config.status.provider_region})</Alert>}
      <Switch label={__("Use manual configuration instead", TEXT_DOMAIN)} description={__("Turn this on only when this Composer must use a different User Pool from the automatically detected provider.", TEXT_DOMAIN)} checked={settings.identity_provider.prefer_manual} onChange={(event) => update({ ...settings, identity_provider: { ...settings.identity_provider, prefer_manual: event.currentTarget.checked } })} />
      <TextInput required={manualRequired} disabled={!manualRequired} label={__("AWS Region", TEXT_DOMAIN)} description={manualRequired ? __("Required because no automatic provider is active. Example: eu-central-1. Not a secret.", TEXT_DOMAIN) : __("The automatically resolved provider is active. Turn on manual configuration to override it.", TEXT_DOMAIN)} value={settings.identity_provider.manual.region} onChange={(event) => update({ ...settings, identity_provider: { ...settings.identity_provider, manual: { ...settings.identity_provider.manual, region: event.currentTarget.value } } })} />
      <TextInput required={manualRequired} disabled={!manualRequired} label={__("User Pool ID", TEXT_DOMAIN)} description={manualRequired ? __("Required because no automatic provider is active. Composer derives the issuer and JWKS URL from it.", TEXT_DOMAIN) : __("The automatically resolved provider is active. Turn on manual configuration to override it.", TEXT_DOMAIN)} value={settings.identity_provider.manual.user_pool_id} onChange={(event) => update({ ...settings, identity_provider: { ...settings.identity_provider, manual: { ...settings.identity_provider.manual, user_pool_id: event.currentTarget.value } } })} />
    </Stack></Card>

    <Card withBorder radius="md" p="md"><Stack gap="sm">
      <Title order={3}>{__("Cognito groups", TEXT_DOMAIN)}</Title>
      <Text size="sm" c="dimmed">{__("Enter the exact, case-sensitive Cognito User Pool group name found in the access token's cognito:groups claim. Map groups, not individual users; if a person belongs to several mapped groups, the highest mapped role wins.", TEXT_DOMAIN)}</Text>
      <List size="sm" withPadding spacing={2}>
        <List.Item>{__("Reader can inspect Composer configuration and content but cannot create drafts.", TEXT_DOMAIN)}</List.Item>
        <List.Item>{__("Contributor can also create governed drafts and update proposals.", TEXT_DOMAIN)}</List.Item>
        <List.Item>{__("Publisher can additionally request human publication approval, but can never publish directly.", TEXT_DOMAIN)}</List.Item>
      </List>
      {groupRows.map((row) => <Group key={row.id} align="end" wrap="nowrap">
        <TextInput style={{ flex: 1 }} label={__("Cognito group name", TEXT_DOMAIN)} description={__("Exact value, for example registered or content-editors.", TEXT_DOMAIN)} placeholder="registered" value={row.group} onChange={(event) => updateGroupRows(groupRows.map((item) => item.id === row.id ? { ...item, group: event.currentTarget.value } : item))} />
        <Select style={{ flex: 1 }} label={__("Composer role", TEXT_DOMAIN)} description={__("Maximum authority granted by this group before the OAuth client ceiling is applied.", TEXT_DOMAIN)} data={roles} value={row.role} onChange={(value) => updateGroupRows(groupRows.map((item) => item.id === row.id ? { ...item, role: (value || "reader") as ComposerRole } : item))} />
        <Button variant="subtle" color="red" aria-label={__("Remove group", TEXT_DOMAIN)} onClick={() => updateGroupRows(groupRows.filter((item) => item.id !== row.id))}><IconTrash size={16} /></Button>
      </Group>)}
      <Button variant="default" leftSection={<IconPlus size={16} />} onClick={() => updateGroupRows([...groupRows, { id: ++nextGroupRowId.current, group: "", role: "reader" }])}>{__("Add group", TEXT_DOMAIN)}</Button>
    </Stack></Card>

    <Card withBorder radius="md" p="md"><Stack gap="sm">
      <Title order={3}>{__("OAuth clients", TEXT_DOMAIN)}</Title>
      <Text size="sm" c="dimmed">{__("Give every security-relevant AI agent its own public Cognito App Client. Use Authorization Code with PKCE, do not generate a client secret, and copy the external agent's exact callback URL into Cognito. The Client ID entered below must be the ID Cognito creates, not its display name.", TEXT_DOMAIN)}</Text>
      <Alert color="blue" icon={<IconInfoCircle size={18} />}><Stack gap={4}>
        <Text fw={600}>{__("External AI client setup order", TEXT_DOMAIN)}</Text>
        <List size="sm" withPadding spacing={2}>
          <List.Item>{__("Copy the callback URL displayed by ChatGPT or the selected AI client.", TEXT_DOMAIN)}</List.Item>
          <List.Item>{__("Create a public Cognito User Pool App Client: authorization-code flow, PKCE, no client secret, and that exact callback URL.", TEXT_DOMAIN)}</List.Item>
          <List.Item>{__("Enter its generated Client ID here and choose a maximum Composer role. This ceiling can only reduce the user's group role.", TEXT_DOMAIN)}</List.Item>
          <List.Item>{__("In the AI client, use the Cognito hosted-domain /oauth2/authorize and /oauth2/token endpoints, token endpoint authentication method none, and openid. When scope enforcement is enabled, Composer discovery adds the exact resource-bound scopes shown above.", TEXT_DOMAIN)}</List.Item>
        </List>
      </Stack></Alert>
      {settings.clients.map((client, index) => <Card key={index} withBorder p="sm"><Stack gap="xs">
        <Group grow><TextInput label={__("Client name", TEXT_DOMAIN)} description={__("A local human-readable label, for example ChatGPT — staging. It is not sent to Cognito.", TEXT_DOMAIN)} placeholder={__("ChatGPT — staging", TEXT_DOMAIN)} value={client.label} onChange={(event) => { const clients = [...settings.clients]; clients[index] = { ...client, label: event.currentTarget.value }; update({ ...settings, clients }); }} />
          <TextInput label={__("Cognito App Client ID", TEXT_DOMAIN)} description={__("Paste the generated ID from Cognito. Do not enter a client secret here.", TEXT_DOMAIN)} placeholder="1example23456789" value={client.client_id} onChange={(event) => { const clients = [...settings.clients]; clients[index] = { ...client, client_id: event.currentTarget.value }; update({ ...settings, clients }); }} /></Group>
        <Group align="end"><Select style={{ flex: 1 }} label={__("Maximum role", TEXT_DOMAIN)} description={__("Safety ceiling for this AI client; the lower of this and the user's group role wins.", TEXT_DOMAIN)} data={roles} value={client.role_ceiling} onChange={(value) => { const clients = [...settings.clients]; clients[index] = { ...client, role_ceiling: (value || "reader") as "reader" | "contributor" | "publisher" }; update({ ...settings, clients }); }} />
          <TextInput style={{ flex: 2 }} label={__("Allowed Composer scopes", TEXT_DOMAIN)} description={__("Space-separated canonical names. Leave empty while scope enforcement is off. Supported: composer.read composer.draft composer.propose composer.publish.request.", TEXT_DOMAIN)} placeholder="composer.read composer.draft" value={client.scopes.join(" ")} onChange={(event) => { const clients = [...settings.clients]; clients[index] = { ...client, scopes: event.currentTarget.value.split(/\s+/).filter(Boolean) }; update({ ...settings, clients }); }} />
          <Button variant="subtle" color="red" onClick={() => update({ ...settings, clients: settings.clients.filter((_, item) => item !== index) })}><IconTrash size={16} /></Button></Group>
      </Stack></Card>)}
      <Button variant="default" leftSection={<IconPlus size={16} />} onClick={() => update({ ...settings, clients: [...settings.clients, { label: "", client_id: "", role_ceiling: "reader", scopes: [] }] })}>{__("Add OAuth client", TEXT_DOMAIN)}</Button>
      <Select label={__("Unknown OAuth clients", TEXT_DOMAIN)} description={__("Deny is recommended: a token from the right User Pool is still rejected unless its client_id is listed above. The alternative removes the per-client safety ceiling and trusts only the user's mapped group role.", TEXT_DOMAIN)} data={[{ value: "deny", label: __("Deny unknown clients", TEXT_DOMAIN) }, { value: "human-role", label: __("Allow with human role", TEXT_DOMAIN) }]} value={settings.unknown_client_policy} onChange={(value) => update({ ...settings, unknown_client_policy: value === "human-role" ? "human-role" : "deny" })} />
      <Switch label={__("Enforce Composer OAuth scopes", TEXT_DOMAIN)} description={__("Requires the external MCP resource URI, a matching Cognito resource server, and the resource-bound scopes on the App Client. Composer validates aud before it normalizes those exact scopes to the canonical client policy names.", TEXT_DOMAIN)} checked={settings.enforce_scopes} onChange={(event) => update({ ...settings, enforce_scopes: event.currentTarget.checked })} />
      <Text size="xs" c="dimmed">{__("Group role and client ceiling remain mandatory. Scope enforcement adds another least-privilege boundary; it does not replace either one.", TEXT_DOMAIN)}</Text>
    </Stack></Card>

    <Card withBorder radius="md" p="md"><Stack gap="sm">
      <Title order={3}>{__("Human approval", TEXT_DOMAIN)}</Title>
      <Text size="sm">{__("Explicit human confirmation is always required and cannot be disabled.", TEXT_DOMAIN)}</Text>
      <NumberInput label={__("Approval expiry (minutes)", TEXT_DOMAIN)} description={__("How long a human-approved publish request remains valid for the exact reviewed revision. Allowed range: 2–60 minutes; 15 minutes is recommended. Expiry never enables direct agent publishing.", TEXT_DOMAIN)} min={2} max={60} value={Math.round(settings.approval_ttl_seconds / 60)} onChange={(value) => update({ ...settings, approval_ttl_seconds: Math.max(2, Math.min(60, Number(value) || 15)) * 60 })} />
    </Stack></Card>

    <Group><Button disabled={resourceRequired} loading={busy} onClick={() => { setBusy(true); saveMcpAccess(settings).then((value) => { setConfig(value); setSettings(value.settings); setGroupRows(rowsFromRoles(value.settings.group_roles)); setMessage(__("MCP access settings saved.", TEXT_DOMAIN)); }).catch((reason: unknown) => setError(reason instanceof Error ? reason.message : __("MCP access settings could not be saved.", TEXT_DOMAIN))).finally(() => setBusy(false)); }}>{__("Save MCP access", TEXT_DOMAIN)}</Button>{message && <Text c="teal" fw={600}>{message}</Text>}{error && <Text c="red" fw={600}>{error}</Text>}</Group>
  </Stack>;
}

function McpAccessSkeleton() {
  return <Stack gap="md" aria-busy="true" aria-label={__("Loading MCP access settings", TEXT_DOMAIN)}>
    <Group gap="xs"><Skeleton circle height={28} /><Skeleton height={28} width="36%" /></Group>
    <Skeleton height={17} width="72%" />
    {Array.from({ length: 4 }, (_, index) => <Card key={index} withBorder radius="md" p="md"><Stack gap="sm"><Skeleton height={22} width="34%" /><Skeleton height={15} width="88%" /><Skeleton height={38} /></Stack></Card>)}
  </Stack>;
}
