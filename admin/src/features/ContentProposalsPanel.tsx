import { Alert, Badge, Button, Card, Checkbox, Group, Modal, Pagination, Stack, Table, Text, Textarea, TextInput, Title, Tooltip } from "@mantine/core";
import { __ } from "@wordpress/i18n";
import { useEffect, useState } from "react";
import { getContentProposal, listContentProposals, mergeContentProposal, rejectContentProposal, returnContentProposalForChanges } from "../api";
import type { ContentProposalDetail, ContentProposalListState, ContentProposalSummary } from "@smart-cloud/agent-composer-core";

const TEXT_DOMAIN = "smartcloud-agent-composer";
type ReviewProposalDetail = ContentProposalDetail & {
  source_conflict?: boolean;
  localization_conflict?: boolean;
  change_request_reason?: string;
};

export function ContentProposalsPanel() {
  const [items, setItems] = useState<ContentProposalSummary[]>([]);
  const [shownStates, setShownStates] = useState<ContentProposalListState[]>(["ready-for-review"]);
  const [searchInput, setSearchInput] = useState("");
  const [search, setSearch] = useState("");
  const [page, setPage] = useState(1);
  const [total, setTotal] = useState(0);
  const [totalPages, setTotalPages] = useState(1);
  const [selected, setSelected] = useState<ReviewProposalDetail | null>(null);
  const [busy, setBusy] = useState(false);
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
    setBusy(true);
    setError("");
    try {
      const result = await listContentProposals(shownStates, search, page);
      setItems(result.items);
      setTotal(result.total);
      setTotalPages(result.total_pages);
      if (result.page !== page) setPage(result.page);
    }
    catch (reason) { setError(errorMessage(reason)); }
    finally { setBusy(false); }
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
      .catch((reason: unknown) => { if (active) setError(errorMessage(reason)); });
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

  const inspect = async (id: number) => {
    setBusy(true);
    try { setSelected(await getContentProposal(id)); }
    catch (reason) { setError(errorMessage(reason)); }
    finally { setBusy(false); }
  };

  const merge = async () => {
    if (!selected) return;
    setBusy(true);
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
    finally { setBusy(false); }
  };

  const reject = async () => {
    const reason = rejectionReason.trim();
    if (!selected || !reason) return;
    setBusy(true);
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
    finally { setBusy(false); }
  };

  const returnForChanges = async () => {
    const reason = changeRequestReason.trim();
    if (!selected || !reason) return;
    setBusy(true);
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
    finally { setBusy(false); }
  };

  return <Stack gap="md">
    <div><Title order={2}>{__("Content proposals", TEXT_DOMAIN)}</Title><Text c="dimmed">{__("Review agent-owned working copies. Only this human review surface can merge a proposal into published content.", TEXT_DOMAIN)}</Text></div>
    <Alert color="blue">{__("A merge stops if the live source, proposal revision, Blueprint validation, or localization relationship changed.", TEXT_DOMAIN)}</Alert>
    {error && <Alert color="red">{error}</Alert>}
    <Stack gap="xs">
      <Group align="end" justify="space-between">
        <TextInput label={__("Filter proposals", TEXT_DOMAIN)} placeholder={__("ID, slug, or title", TEXT_DOMAIN)} value={searchInput} onChange={(event) => setSearchInput(event.currentTarget.value)} style={{ flex: "1 1 280px", maxWidth: 480 }} />
        <Button variant="default" loading={busy} onClick={() => void refresh()}>{__("Refresh", TEXT_DOMAIN)}</Button>
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
      <Table.Tbody>{items.map((item) => <Table.Tr key={item.proposal_id}><Table.Td>#{item.proposal_id}</Table.Td><Table.Td>{item.title}</Table.Td><Table.Td>{item.localization.content_language ? <Tooltip label={item.localization.content_language}><Text component="span" role="img" aria-label={item.localization.content_language} fz="xl">{languageFlag(item.localization.content_language)}</Text></Tooltip> : "-"}</Table.Td><Table.Td><Badge color={item.conflict ? "red" : item.state === "ready-for-review" ? "blue" : "gray"}>{item.conflict ? __("Conflict", TEXT_DOMAIN) : item.state}</Badge></Table.Td><Table.Td>#{item.source_post_id}</Table.Td><Table.Td><Button size="compact-sm" variant="subtle" onClick={() => void inspect(item.proposal_id)}>{__("Review", TEXT_DOMAIN)}</Button></Table.Td></Table.Tr>)}</Table.Tbody>
    </Table></Table.ScrollContainer>
    {totalPages > 1 && <Group justify="center"><Pagination value={page} onChange={setPage} total={totalPages} /></Group>}
    {selected && <Card withBorder><Stack gap="sm"><Group justify="space-between"><Title order={3}>{selected.title}</Title><Badge>{selected.state}</Badge></Group>
      <Text size="sm">{`${__("Changed fields", TEXT_DOMAIN)}: ${selected.changes.join(", ") || __("None", TEXT_DOMAIN)}`}</Text>
      {selected.source_conflict && <Alert color="red">{__("The governed live content changed after this proposal was created. This proposal cannot be merged automatically.", TEXT_DOMAIN)}</Alert>}
      {selected.localization_conflict && <Alert color="red">{__("The source or proposal language relationship changed after this proposal was created. This proposal cannot be merged automatically.", TEXT_DOMAIN)}</Alert>}
      {selected.conflict && !selected.source_conflict && !selected.localization_conflict && <Alert color="red">{__("The live source or its localization relationship changed. This proposal cannot be merged automatically.", TEXT_DOMAIN)}</Alert>}
      {!selected.validation.valid && <Alert color="red">{__("The proposal no longer passes its Blueprint validation.", TEXT_DOMAIN)}</Alert>}
      {selected.state === "working" && selected.change_request_reason && <Alert color="yellow"><Text fw={600}>{__("Requested changes", TEXT_DOMAIN)}</Text><Text size="sm">{selected.change_request_reason}</Text></Alert>}
      <Group><Button component="a" href={selected.preview_url} target="_blank" variant="default">{__("Preview proposal", TEXT_DOMAIN)}</Button><Button component="a" href={selected.source_edit_url} target="_blank" variant="default">{__("Open live source", TEXT_DOMAIN)}</Button>
        <Button color="green" disabled={selected.state !== "ready-for-review" || selected.conflict || !selected.validation.valid} loading={busy} onClick={() => setMergeConfirmOpened(true)}>{__("Merge to published", TEXT_DOMAIN)}</Button>
        <Button color="yellow" variant="light" disabled={!['ready-for-review', 'rejected'].includes(selected.state) || selected.conflict} onClick={() => { setChangeRequestReason(""); setReturnOpened(true); }}>{selected.state === "rejected" ? __("Reopen for changes", TEXT_DOMAIN) : __("Request changes", TEXT_DOMAIN)}</Button>
        <Button color="red" variant="light" disabled={!['working', 'ready-for-review'].includes(selected.state)} onClick={() => { setRejectionReason(""); setRejectOpened(true); }}>{__("Reject", TEXT_DOMAIN)}</Button></Group>
    </Stack></Card>}
    <Modal opened={mergeConfirmOpened} onClose={() => { if (!busy) setMergeConfirmOpened(false); }} title={__("Merge proposal into published content?", TEXT_DOMAIN)} centered>
      <Stack gap="md">
        <Alert color="yellow">{__("This is the public change point. Composer will update the existing published item only if the proposal, live source, Blueprint, and localization relationship are still unchanged.", TEXT_DOMAIN)}</Alert>
        {selected && <Text>{`${selected.title} → #${selected.source_post_id}`}</Text>}
        <Group justify="flex-end">
          <Button variant="default" disabled={busy} onClick={() => setMergeConfirmOpened(false)}>{__("Cancel", TEXT_DOMAIN)}</Button>
          <Button color="green" loading={busy} onClick={() => void merge()}>{__("Merge to published", TEXT_DOMAIN)}</Button>
        </Group>
      </Stack>
    </Modal>
    <Modal opened={rejectOpened} onClose={() => { if (!busy) setRejectOpened(false); }} title={__("Reject content proposal?", TEXT_DOMAIN)} centered>
      <Stack gap="md">
        <Textarea label={__("Reason for rejection", TEXT_DOMAIN)} description={__("The reason is stored with the proposal for the audit trail.", TEXT_DOMAIN)} value={rejectionReason} onChange={(event) => setRejectionReason(event.currentTarget.value)} minRows={4} maxLength={1000} data-autofocus required />
        <Group justify="flex-end">
          <Button variant="default" disabled={busy} onClick={() => setRejectOpened(false)}>{__("Cancel", TEXT_DOMAIN)}</Button>
          <Button color="red" loading={busy} disabled={!rejectionReason.trim()} onClick={() => void reject()}>{__("Reject proposal", TEXT_DOMAIN)}</Button>
        </Group>
      </Stack>
    </Modal>
    <Modal opened={returnOpened} onClose={() => { if (!busy) setReturnOpened(false); }} title={selected?.state === "rejected" ? __("Reopen proposal for changes?", TEXT_DOMAIN) : __("Return proposal for changes?", TEXT_DOMAIN)} centered>
      <Stack gap="md">
        <Alert color="yellow">{__("The same proposal draft will become editable by its assigned agent again. Its published source will remain unchanged.", TEXT_DOMAIN)}</Alert>
        <Textarea label={__("Requested changes", TEXT_DOMAIN)} description={__("The request is stored in the proposal audit trail and shown on the working proposal.", TEXT_DOMAIN)} value={changeRequestReason} onChange={(event) => setChangeRequestReason(event.currentTarget.value)} minRows={4} maxLength={1000} data-autofocus required />
        <Group justify="flex-end">
          <Button variant="default" disabled={busy} onClick={() => setReturnOpened(false)}>{__("Cancel", TEXT_DOMAIN)}</Button>
          <Button color="yellow" loading={busy} disabled={!changeRequestReason.trim()} onClick={() => void returnForChanges()}>{__("Return for changes", TEXT_DOMAIN)}</Button>
        </Group>
      </Stack>
    </Modal>
  </Stack>;
}
