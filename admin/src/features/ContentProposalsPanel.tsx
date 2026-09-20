import { Alert, Badge, Button, Card, Checkbox, Group, Modal, Pagination, Skeleton, Stack, Table, Text, Textarea, TextInput, Title, Tooltip } from "@mantine/core";
import { __ } from "@wordpress/i18n";
import { useEffect, useState } from "react";
import { IconGitMerge } from "@tabler/icons-react";
import { getContentProposal, listContentProposals, mergeContentProposal, rejectContentProposal, returnContentProposalForChanges } from "../api";
import type { ContentProposalChangeDetail, ContentProposalDetail, ContentProposalListState, ContentProposalSummary } from "@smart-cloud/agent-composer-core";
import { SectionHeading } from "../AdminUi";
import type { SectionProps } from "../feature-contract";
import { buildBodyContentReview, type ContentDiffExcerpt } from "./contentProposalDiff";

const TEXT_DOMAIN = "smartcloud-agent-composer";
type ReviewProposalDetail = ContentProposalDetail & {
  source_conflict?: boolean;
  localization_conflict?: boolean;
  change_request_reason?: string;
};

export function ContentProposalsPanel({ showDocs }: Pick<SectionProps, "showDocs">) {
  const [items, setItems] = useState<ContentProposalSummary[]>([]);
  const [shownStates, setShownStates] = useState<ContentProposalListState[]>(["ready-for-review"]);
  const [searchInput, setSearchInput] = useState("");
  const [search, setSearch] = useState("");
  const [page, setPage] = useState(1);
  const [total, setTotal] = useState(0);
  const [totalPages, setTotalPages] = useState(1);
  const [selected, setSelected] = useState<ReviewProposalDetail | null>(null);
  const [pendingAction, setPendingAction] = useState<string | null>(null);
  const [initialLoading, setInitialLoading] = useState(true);
  const busy = pendingAction !== null;
  const [error, setError] = useState("");
  const [mergeConfirmOpened, setMergeConfirmOpened] = useState(false);
  const [rejectOpened, setRejectOpened] = useState(false);
  const [rejectionReason, setRejectionReason] = useState("");
  const [returnOpened, setReturnOpened] = useState(false);
  const [changeRequestReason, setChangeRequestReason] = useState("");

  const errorMessage = (reason: unknown): string => {
    if (reason instanceof Error) return reason.message;
    if (reason && typeof reason === "object" && "message" in reason) return String(reason.message);
    return __("Composer could not complete the proposal operation.", TEXT_DOMAIN);
  };

  const refresh = async () => {
    setPendingAction("refresh-proposals");
    setError("");
    try {
      const result = await listContentProposals(shownStates, search, page);
      setItems(result.items);
      setTotal(result.total);
      setTotalPages(result.total_pages);
      if (result.page !== page) setPage(result.page);
    }
    catch (reason) { setError(errorMessage(reason)); }
    finally { setPendingAction(null); }
  };

  useEffect(() => {
    let active = true;
    listContentProposals(shownStates, search, page)
      .then((result) => {
        if (!active) return;
        setItems(result.items);
        setTotal(result.total);
        setTotalPages(result.total_pages);
        if (result.page !== page) setPage(result.page);
      })
      .catch((reason: unknown) => { if (active) setError(errorMessage(reason)); })
      .finally(() => { if (active) setInitialLoading(false); });
    return () => { active = false; };
  }, [shownStates, search, page]);

  useEffect(() => {
    const timeout = window.setTimeout(() => {
      setPage(1);
      setSearch(searchInput.trim());
    }, 300);
    return () => window.clearTimeout(timeout);
  }, [searchInput]);

  const setOptionalState = (state: ContentProposalListState, checked: boolean) => {
    setPage(1);
    setShownStates((current) => checked
      ? [...new Set([...current, state])]
      : current.filter((candidate) => candidate !== state));
  };

  const languageFlag = (language: string): string => {
    const normalized = language.replaceAll("_", "-");
    const parts = normalized.split("-");
    const explicitRegion = parts.slice(1).find((part) => /^[A-Za-z]{2}$/.test(part));
    const defaultRegions: Record<string, string> = { en: "US", hu: "HU", es: "ES", zh: "CN", de: "DE", fr: "FR", ru: "RU" };
    const region = (explicitRegion || defaultRegions[parts[0]?.toLowerCase() || ""] || "").toUpperCase();
    return /^[A-Z]{2}$/.test(region)
      ? String.fromCodePoint(...[...region].map((character) => character.charCodeAt(0) + 127397))
      : "🌐";
  };

  const changeValue = (value: unknown): string => {
    if (value === null || value === undefined || value === "") return __("No value", TEXT_DOMAIN);
    if (typeof value === "boolean") return value ? __("Yes", TEXT_DOMAIN) : __("No", TEXT_DOMAIN);
    if (typeof value === "string") return value;
    if (typeof value === "number") return String(value);
    try { return JSON.stringify(value, null, 2); }
    catch { return String(value); }
  };

  const contentExcerpt = (value: ContentDiffExcerpt, side: "before" | "after") => (
    <Text size="sm" style={{ whiteSpace: "pre-wrap", overflowWrap: "anywhere", maxWidth: 420 }}>
      {value.hiddenBefore && "… "}{value.leading}
      <Text
        component="mark"
        span
        inherit
        px={3}
        style={{ background: `var(--mantine-color-${side === "before" ? "red" : "green"}-1)`, color: "inherit" }}
      >
        {value.changed || (side === "before" ? __("Removed", TEXT_DOMAIN) : __("Added", TEXT_DOMAIN))}
      </Text>
      {value.trailing}{value.hiddenAfter && " …"}
    </Text>
  );

  const changeCells = (detail: ContentProposalChangeDetail) => {
    if (detail.key === "content" && typeof detail.before === "string" && typeof detail.after === "string") {
      const review = buildBodyContentReview(detail.before, detail.after);
      if (!review.hasVisibleChange) {
        const message = __("The visible text is unchanged. Review the proposal preview for block structure, styling, media, or other presentation changes.", TEXT_DOMAIN);
        return [<Text size="sm" c="dimmed">{message}</Text>, <Text size="sm" c="dimmed">{message}</Text>];
      }
      return [contentExcerpt(review.before, "before"), contentExcerpt(review.after, "after")];
    }
    return (["before", "after"] as const).map((side) => (
      <Text size="sm" style={{ whiteSpace: "pre-wrap", overflowWrap: "anywhere", maxWidth: 420 }}>
        {changeValue(detail[side])}
      </Text>
    ));
  };

  const inspect = async (id: number) => {
    setPendingAction(`inspect-proposal-${id}`);
    try { setSelected(await getContentProposal(id)); }
    catch (reason) { setError(errorMessage(reason)); }
    finally { setPendingAction(null); }
  };

  const merge = async () => {
    if (!selected) return;
    setPendingAction("merge-proposal");
    setError("");
    try {
      const latest = await getContentProposal(selected.proposal_id);
      setSelected(latest);
      if (latest.state !== "ready-for-review") throw new Error(__("Only a proposal that is ready for review can be merged.", TEXT_DOMAIN));
      if (latest.conflict) throw new Error(__("The live source or its localization relationship changed after this proposal was created. Review the source before trying again.", TEXT_DOMAIN));
      if (!latest.validation.valid) throw new Error(__("The proposal no longer passes its Blueprint validation.", TEXT_DOMAIN));
      setSelected(await mergeContentProposal(latest));
      setMergeConfirmOpened(false);
      await refresh();
    }
    catch (reason) { setError(errorMessage(reason)); }
    finally { setPendingAction(null); }
  };

  const reject = async () => {
    const reason = rejectionReason.trim();
    if (!selected || !reason) return;
    setPendingAction("reject-proposal");
    setError("");
    try {
      const latest = await getContentProposal(selected.proposal_id);
      await rejectContentProposal(latest, reason);
      setSelected(await getContentProposal(selected.proposal_id));
      setRejectOpened(false);
      setRejectionReason("");
      await refresh();
    }
    catch (failure) { setError(errorMessage(failure)); }
    finally { setPendingAction(null); }
  };

  const returnForChanges = async () => {
    const reason = changeRequestReason.trim();
    if (!selected || !reason) return;
    setPendingAction("return-proposal");
    setError("");
    try {
      const latest = await getContentProposal(selected.proposal_id);
      if (!["ready-for-review", "rejected"].includes(latest.state)) throw new Error(__("Only a submitted or rejected proposal can be returned for changes.", TEXT_DOMAIN));
      setSelected(await returnContentProposalForChanges(latest, reason));
      setReturnOpened(false);
      setChangeRequestReason("");
      await refresh();
    }
    catch (failure) { setError(errorMessage(failure)); }
    finally { setPendingAction(null); }
  };

  return <Stack gap="md">
    <SectionHeading title={__("Content proposals", TEXT_DOMAIN)} description={__("Review agent-owned working copies. Only this human review surface can merge a proposal into published content.", TEXT_DOMAIN)} icon={<IconGitMerge size={21} />} openDocumentation={() => showDocs()} />
    <Alert color="blue">{__("A merge stops if the live source, proposal revision, Blueprint validation, or localization relationship changed.", TEXT_DOMAIN)}</Alert>
    {error && <Alert color="red">{error}</Alert>}
    <Stack gap="xs">
      <Group align="end" justify="space-between">
        <TextInput label={__("Filter proposals", TEXT_DOMAIN)} placeholder={__("ID, slug, or title", TEXT_DOMAIN)} value={searchInput} onChange={(event) => setSearchInput(event.currentTarget.value)} style={{ flex: "1 1 280px", maxWidth: 480 }} />
        <Button variant="default" loading={pendingAction === "refresh-proposals"} onClick={() => void refresh()}>{__("Refresh", TEXT_DOMAIN)}</Button>
      </Group>
      <Group gap="lg">
        <Text size="sm" fw={600}>{__("Showing ready for review", TEXT_DOMAIN)}</Text>
        <Checkbox label={__("Show working", TEXT_DOMAIN)} checked={shownStates.includes("working")} onChange={(event) => setOptionalState("working", event.currentTarget.checked)} />
        <Checkbox label={__("Show rejected", TEXT_DOMAIN)} checked={shownStates.includes("rejected")} onChange={(event) => setOptionalState("rejected", event.currentTarget.checked)} />
        <Checkbox label={__("Show merged/archived", TEXT_DOMAIN)} checked={shownStates.includes("merged")} onChange={(event) => {
          const checked = event.currentTarget.checked;
          setPage(1);
          setShownStates((current) => checked
            ? [...new Set([...current, "merged" as ContentProposalListState, "superseded" as ContentProposalListState])]
            : current.filter((candidate) => !["merged", "superseded"].includes(candidate)));
        }} />
        <Text size="sm" c="dimmed">{`${total} ${__("matches", TEXT_DOMAIN)}`}</Text>
      </Group>
    </Stack>
    <Table.ScrollContainer minWidth={860}><Table striped highlightOnHover><Table.Thead><Table.Tr><Table.Th>{__("Proposal ID", TEXT_DOMAIN)}</Table.Th><Table.Th>{__("Proposal", TEXT_DOMAIN)}</Table.Th><Table.Th>{__("Language", TEXT_DOMAIN)}</Table.Th><Table.Th>{__("State", TEXT_DOMAIN)}</Table.Th><Table.Th>{__("Source ID", TEXT_DOMAIN)}</Table.Th><Table.Th /></Table.Tr></Table.Thead>
      <Table.Tbody>{initialLoading && items.length === 0 ? <ProposalRowsSkeleton /> : items.map((item) => <Table.Tr key={item.proposal_id}><Table.Td>#{item.proposal_id}</Table.Td><Table.Td>{item.title}</Table.Td><Table.Td>{item.localization.content_language ? <Tooltip label={item.localization.content_language}><Text component="span" role="img" aria-label={item.localization.content_language} fz="xl">{languageFlag(item.localization.content_language)}</Text></Tooltip> : "-"}</Table.Td><Table.Td><Badge color={item.conflict ? "red" : item.state === "ready-for-review" ? "blue" : "gray"}>{item.conflict ? __("Conflict", TEXT_DOMAIN) : item.state}</Badge></Table.Td><Table.Td>#{item.source_post_id}</Table.Td><Table.Td><Button size="compact-sm" variant="subtle" loading={pendingAction === `inspect-proposal-${item.proposal_id}`} onClick={() => void inspect(item.proposal_id)}>{__("Review", TEXT_DOMAIN)}</Button></Table.Td></Table.Tr>)}</Table.Tbody>
    </Table></Table.ScrollContainer>
    {totalPages > 1 && <Group justify="center"><Pagination value={page} onChange={setPage} total={totalPages} /></Group>}
    {selected && <Card withBorder><Stack gap="sm"><Group justify="space-between"><Title order={3}>{selected.title}</Title><Badge>{selected.state}</Badge></Group>
      <div>
        <Text fw={600}>{__("Proposed changes", TEXT_DOMAIN)}</Text>
        <Text size="sm" c="dimmed">{__("Compare the currently published value with the value the proposal would apply. Nothing changes publicly until you merge it.", TEXT_DOMAIN)}</Text>
      </div>
      {selected.change_details?.length ? <div style={{ maxHeight: "65vh", overflow: "auto" }}><Table miw={720} withTableBorder withColumnBorders verticalSpacing="sm"><Table.Thead style={{ position: "sticky", top: 0, zIndex: 1, background: "var(--mantine-color-body)" }}><Table.Tr>
        <Table.Th>{__("Field", TEXT_DOMAIN)}</Table.Th><Table.Th>{__("Currently published", TEXT_DOMAIN)}</Table.Th><Table.Th>{__("Proposed value", TEXT_DOMAIN)}</Table.Th>
      </Table.Tr></Table.Thead><Table.Tbody>{selected.change_details.map((detail) => {
        const cells = changeCells(detail);
        return <Table.Tr key={detail.path}>
          <Table.Td style={{ verticalAlign: "top", minWidth: 220 }}><Text fw={600}>{detail.label || detail.key}</Text><Text size="xs" c="dimmed">{detail.path}</Text>{detail.key === "content" && <Text size="xs" c="dimmed" mt={4}>{__("Only the affected visible content and nearby context are shown. Use Preview proposal to inspect the complete rendered layout.", TEXT_DOMAIN)}</Text>}{detail.description && <Text size="xs" c="dimmed" mt={4}>{detail.description}</Text>}</Table.Td>
          <Table.Td style={{ verticalAlign: "top" }}>{cells[0]}</Table.Td>
          <Table.Td style={{ verticalAlign: "top" }}>{cells[1]}</Table.Td>
        </Table.Tr>;
      })}</Table.Tbody></Table></div> : <Text size="sm">{`${__("Changed fields", TEXT_DOMAIN)}: ${selected.changes.join(", ") || __("None", TEXT_DOMAIN)}`}</Text>}
      {selected.source_conflict && <Alert color="red">{__("The governed live content changed after this proposal was created. This proposal cannot be merged automatically.", TEXT_DOMAIN)}</Alert>}
      {selected.localization_conflict && <Alert color="red">{__("The source or proposal language relationship changed after this proposal was created. This proposal cannot be merged automatically.", TEXT_DOMAIN)}</Alert>}
      {selected.conflict && !selected.source_conflict && !selected.localization_conflict && <Alert color="red">{__("The live source or its localization relationship changed. This proposal cannot be merged automatically.", TEXT_DOMAIN)}</Alert>}
      {!selected.validation.valid && <Alert color="red">{__("The proposal no longer passes its Blueprint validation.", TEXT_DOMAIN)}</Alert>}
      {selected.state === "working" && selected.change_request_reason && <Alert color="yellow"><Text fw={600}>{__("Requested changes", TEXT_DOMAIN)}</Text><Text size="sm">{selected.change_request_reason}</Text></Alert>}
      <Group><Button component="a" href={selected.preview_url} target="_blank" variant="default">{__("Preview proposal", TEXT_DOMAIN)}</Button><Button component="a" href={selected.source_edit_url} target="_blank" variant="default">{__("Open live source", TEXT_DOMAIN)}</Button>
        <Button color="green" disabled={selected.state !== "ready-for-review" || selected.conflict || !selected.validation.valid} onClick={() => setMergeConfirmOpened(true)}>{__("Merge to published", TEXT_DOMAIN)}</Button>
        <Button color="yellow" variant="light" disabled={!['ready-for-review', 'rejected'].includes(selected.state) || selected.conflict} onClick={() => { setChangeRequestReason(""); setReturnOpened(true); }}>{selected.state === "rejected" ? __("Reopen for changes", TEXT_DOMAIN) : __("Request changes", TEXT_DOMAIN)}</Button>
        <Button color="red" variant="light" disabled={!['working', 'ready-for-review'].includes(selected.state)} onClick={() => { setRejectionReason(""); setRejectOpened(true); }}>{__("Reject", TEXT_DOMAIN)}</Button></Group>
    </Stack></Card>}
    <Modal opened={mergeConfirmOpened} onClose={() => { if (!busy) setMergeConfirmOpened(false); }} title={__("Merge proposal into published content?", TEXT_DOMAIN)} centered>
      <Stack gap="md">
        <Alert color="yellow">{__("This is the public change point. Composer will update the existing published item only if the proposal, live source, Blueprint, and localization relationship are still unchanged.", TEXT_DOMAIN)}</Alert>
        {selected && <Text>{`${selected.title} → #${selected.source_post_id}`}</Text>}
        <Group justify="flex-end">
          <Button variant="default" disabled={busy} onClick={() => setMergeConfirmOpened(false)}>{__("Cancel", TEXT_DOMAIN)}</Button>
          <Button color="green" loading={pendingAction === "merge-proposal"} onClick={() => void merge()}>{__("Merge to published", TEXT_DOMAIN)}</Button>
        </Group>
      </Stack>
    </Modal>
    <Modal opened={rejectOpened} onClose={() => { if (!busy) setRejectOpened(false); }} title={__("Reject content proposal?", TEXT_DOMAIN)} centered>
      <Stack gap="md">
        <Textarea label={__("Reason for rejection", TEXT_DOMAIN)} description={__("The reason is stored with the proposal for the audit trail.", TEXT_DOMAIN)} value={rejectionReason} onChange={(event) => setRejectionReason(event.currentTarget.value)} minRows={4} maxLength={1000} data-autofocus required />
        <Group justify="flex-end">
          <Button variant="default" disabled={busy} onClick={() => setRejectOpened(false)}>{__("Cancel", TEXT_DOMAIN)}</Button>
          <Button color="red" loading={pendingAction === "reject-proposal"} disabled={!rejectionReason.trim()} onClick={() => void reject()}>{__("Reject proposal", TEXT_DOMAIN)}</Button>
        </Group>
      </Stack>
    </Modal>
    <Modal opened={returnOpened} onClose={() => { if (!busy) setReturnOpened(false); }} title={selected?.state === "rejected" ? __("Reopen proposal for changes?", TEXT_DOMAIN) : __("Return proposal for changes?", TEXT_DOMAIN)} centered>
      <Stack gap="md">
        <Alert color="yellow">{__("The same proposal draft will become editable by its assigned agent again. Its published source will remain unchanged.", TEXT_DOMAIN)}</Alert>
        <Textarea label={__("Requested changes", TEXT_DOMAIN)} description={__("The request is stored in the proposal audit trail and shown on the working proposal.", TEXT_DOMAIN)} value={changeRequestReason} onChange={(event) => setChangeRequestReason(event.currentTarget.value)} minRows={4} maxLength={1000} data-autofocus required />
        <Group justify="flex-end">
          <Button variant="default" disabled={busy} onClick={() => setReturnOpened(false)}>{__("Cancel", TEXT_DOMAIN)}</Button>
          <Button color="yellow" loading={pendingAction === "return-proposal"} disabled={!changeRequestReason.trim()} onClick={() => void returnForChanges()}>{__("Return for changes", TEXT_DOMAIN)}</Button>
        </Group>
      </Stack>
    </Modal>
  </Stack>;
}

function ProposalRowsSkeleton() {
  return <>{Array.from({ length: 5 }, (_, row) => <Table.Tr key={row}>{Array.from({ length: 6 }, (_, column) => <Table.Td key={column}><Skeleton height={14} width={column === 1 ? "76%" : "56%"} /></Table.Td>)}</Table.Tr>)}</>;
}
