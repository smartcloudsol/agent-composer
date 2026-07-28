import { Accordion, Badge, Button, Card, Code, Group, List, Stack, Text, Title } from "@mantine/core";
import { IconChevronDown, IconChevronUp } from "@tabler/icons-react";
import { __ } from "@wordpress/i18n";
import { useCallback, useState } from "react";

const TEXT_DOMAIN = "smartcloud-agent-composer";
const LOCAL_STORAGE_KEY = "smartcloud_agent_composer_onboarding_collapsed";

function readCollapsed(): boolean {
  try {
    return window.localStorage.getItem(LOCAL_STORAGE_KEY) === "true";
  } catch {
    return false;
  }
}

function storeCollapsed(value: boolean): void {
  try {
    window.localStorage.setItem(LOCAL_STORAGE_KEY, value ? "true" : "false");
  } catch {
    // The tour remains usable when browser storage is unavailable.
  }
}

export default function Onboarding() {
  const [collapsed, setCollapsed] = useState(readCollapsed);
  const toggle = useCallback(() => {
    setCollapsed((current) => {
      const next = !current;
      storeCollapsed(next);
      return next;
    });
  }, []);

  return <Card withBorder radius="md" p="lg">
    <Group justify="space-between" align="flex-start" wrap="wrap" gap="sm">
      <div>
        <Title order={3}>{__("Quick tour", TEXT_DOMAIN)}</Title>
        <Text size="sm" c="dimmed" mt={4}>{__("SmartCloud Agent Composer is the WordPress plugin layer of a governed, agent-assisted content production solution. It uses the active Config Set and the active theme's discovered or declared design capabilities to produce validated Gutenberg drafts that follow the configured site and page contracts.", TEXT_DOMAIN)}</Text>
      </div>
      <Button variant={collapsed ? "light" : "subtle"} onClick={toggle}
        leftSection={collapsed ? <IconChevronDown size={16} /> : <IconChevronUp size={16} />}>
        {collapsed ? __("Show", TEXT_DOMAIN) : __("Collapse", TEXT_DOMAIN)}
      </Button>
    </Group>

    {collapsed ? <Text size="sm" c="dimmed" mt="sm">{__("This tour is collapsed. Choose Show whenever you need the setup outline again.", TEXT_DOMAIN)}</Text> :
      <Accordion variant="separated" mt="md" defaultValue="purpose">
        <Accordion.Item value="purpose">
          <Accordion.Control><Group gap="xs"><Text fw={700}>{__("What Composer does", TEXT_DOMAIN)}</Text><Badge variant="light">{__("Safety", TEXT_DOMAIN)}</Badge></Group></Accordion.Control>
          <Accordion.Panel><Stack gap="sm">
            <Text size="sm">{__("Composer turns the active Config Set, its Site Contract, and page-type Blueprints into a restricted Ability surface for authenticated agent clients. Generated content is assembled from approved theme patterns and blocks, then checked against the active theme's design capabilities, editorial policy, ownership, and concurrency rules before any write.", TEXT_DOMAIN)}</Text>
            <Text size="sm">{__("Agent operations create and update agent-owned WordPress drafts only. Publishing, ordinary-content deletion, media upload, theme or plugin changes, and user administration are outside this boundary.", TEXT_DOMAIN)}</Text>
          </Stack></Accordion.Panel>
        </Accordion.Item>
        <Accordion.Item value="configure">
          <Accordion.Control><Group gap="xs"><Text fw={700}>{__("Prepare the site contract", TEXT_DOMAIN)}</Text><Badge variant="light">{__("Admin", TEXT_DOMAIN)}</Badge></Group></Accordion.Control>
          <Accordion.Panel><List size="sm" spacing="xs" withPadding>
            <List.Item>{__("Start from Universal Gutenberg, SmartCloud Recommended, or a safe Detected Theme Starter; you can also create or clone a Config Set manually.", TEXT_DOMAIN)}</List.Item>
            <List.Item>{__("Preset selection creates an inactive working copy. Review its Site Contract and Blueprints before validation and activation.", TEXT_DOMAIN)}</List.Item>
            <List.Item>{__("Add one Blueprint per page type and choose only templates, patterns, and blocks available on the current site.", TEXT_DOMAIN)}</List.Item>
            <List.Item>{__("Use Theme & providers to rescan local capabilities, then apply, validate, and explicitly activate the Config Set.", TEXT_DOMAIN)}</List.Item>
            <List.Item>{__("Use Audit & portability to export a checksum-protected backup before destructive lifecycle operations or uninstall.", TEXT_DOMAIN)}</List.Item>
          </List></Accordion.Panel>
        </Accordion.Item>
        <Accordion.Item value="connect">
          <Accordion.Control><Group gap="xs"><Text fw={700}>{__("Connect an agent client", TEXT_DOMAIN)}</Text><Badge variant="light">MCP</Badge></Group></Accordion.Control>
          <Accordion.Panel><Stack gap="sm">
            <Text size="sm">{__("Install and activate the separate WordPress MCP Adapter, then authenticate a compatible MCP client as a dedicated user with the smartcloud_agent role. A Connector tunnel may expose the same server to a compatible OpenAI client, but it is optional.", TEXT_DOMAIN)}</Text>
            <Text size="sm">{__("Composer registers its governed server at:", TEXT_DOMAIN)} <Code>/wp-json/mcp/smartcloud-agent-composer</Code></Text>
            <Text size="sm">{__("A typical run loads the Blueprint and design context, lists approved patterns, validates the proposed draft, creates it, and returns a human-reviewable preview.", TEXT_DOMAIN)}</Text>
          </Stack></Accordion.Panel>
        </Accordion.Item>
      </Accordion>}
  </Card>;
}
