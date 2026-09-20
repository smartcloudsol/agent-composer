import { ActionIcon, Card, Group, Text, ThemeIcon, Title, Tooltip } from "@mantine/core";
import { IconHelpCircle } from "@tabler/icons-react";
import { __ } from "@wordpress/i18n";
import type { ReactNode } from "react";

const TEXT_DOMAIN = "smartcloud-agent-composer";

export function SectionHeading({ title, description, icon, openDocumentation }: { title: string; description: string; icon: ReactNode; openDocumentation?: () => void }) {
  const helpLabel = __("Open documentation for this section", TEXT_DOMAIN);
  return <div><Group gap="xs"><ThemeIcon variant="light" color="blue">{icon}</ThemeIcon><Title order={2}>{title}</Title>
    {openDocumentation && <Tooltip label={helpLabel}><ActionIcon variant="subtle" color="blue" aria-label={helpLabel} onClick={openDocumentation}><IconHelpCircle size={18} /></ActionIcon></Tooltip>}
  </Group><Text mt="xs">{description}</Text></div>;
}

export function StatusCard({ label, value, icon }: { label: string; value: string; icon: ReactNode }) {
  return <Card withBorder radius="md" p="lg"><Group gap="xs"><ThemeIcon variant="light" color="blue" size="sm">{icon}</ThemeIcon><Text fw={700}>{label}</Text></Group>
    <Text mt="sm" c="dimmed" style={{ overflowWrap: "anywhere" }}>{value}</Text></Card>;
}
