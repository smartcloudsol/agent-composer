import { Card, Group, Text, ThemeIcon, Title } from "@mantine/core";
import type { ReactNode } from "react";

export function SectionHeading({ title, description, icon }: { title: string; description: string; icon: ReactNode }) {
  return <div><Group gap="xs"><ThemeIcon variant="light" color="blue">{icon}</ThemeIcon><Title order={2}>{title}</Title></Group><Text mt="xs">{description}</Text></div>;
}

export function StatusCard({ label, value, icon }: { label: string; value: string; icon: ReactNode }) {
  return <Card withBorder radius="md" p="lg"><Group gap="xs"><ThemeIcon variant="light" color="blue" size="sm">{icon}</ThemeIcon><Text fw={700}>{label}</Text></Group>
    <Text mt="sm" c="dimmed" style={{ overflowWrap: "anywhere" }}>{value}</Text></Card>;
}
