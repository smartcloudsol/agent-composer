import { InspectorControls, useBlockEditingMode } from "@wordpress/block-editor";
import { getBlockType, parse } from "@wordpress/blocks";
import { Button, PanelBody } from "@wordpress/components";
import { useRegistry, useSelect } from "@wordpress/data";
import { useState } from "@wordpress/element";
import { addFilter } from "@wordpress/hooks";
import apiFetch from "@wordpress/api-fetch";
import { __, sprintf } from "@wordpress/i18n";
import type { ComponentType } from "react";
import { composerMetadata, flattenBlocks, type EditorBlock } from "./identity";
import { accepts, contract, decodeBlocks, storedBlocks, instanceOwner, nearestSlot, newContentBlock, patternName, record, SLOT, stampBlocks, type EditorDispatch, type EditorSelector } from "./adapter";

interface BlockEditProps {
  clientId: string;
  name: string;
  attributes: Record<string, unknown>;
  isSelected: boolean;
}
interface PostSelector { getCurrentPostId(): number; getCurrentPostType(): string }

function directSlots(editor: EditorSelector, ownerId: string): string[] {
  const result: string[] = [];
  const visit = (parent: string, depth = 0) => {
    if (depth > 30) return;
    for (const block of editor.getBlocks(parent)) {
      if (block.name === SLOT) result.push(block.clientId);
      else if (block.name !== "core/block") visit(block.clientId, depth + 1);
    }
  };
  visit(ownerId);
  return result;
}

function SlotControls({ slotClientId, selectedId }: { slotClientId: string; selectedId: string }) {
  const registry = useRegistry();
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  const { slot, blocks, container, nestedTypes, postId } = useSelect((select) => {
    const editor = select("core/block-editor") as EditorSelector;
    const selected = editor.getBlock(selectedId);
    const slot = editor.getBlock(slotClientId);
    const rules = contract(slot?.attributes ?? {});
    const candidates = [selected, ...[...editor.getBlockParents(selectedId)].reverse().map((id) => editor.getBlock(id))];
    const container = candidates.find((block) => block && block.clientId !== slotClientId &&
      block.name !== "core/block" && composerMetadata(block.attributes).ownership === "USER" &&
      editor.getBlockParents(block.clientId).includes(slotClientId) &&
      (editor.getBlockListSettings(block.clientId)?.allowedBlocks ?? getBlockType(block.name)?.allowedBlocks)?.length) ?? null;
    const nested = container ? editor.getBlockListSettings(container.clientId)?.allowedBlocks ?? getBlockType(container.name)?.allowedBlocks ?? [] : [];
    return { slot, blocks: editor.getBlocks(slotClientId), container,
      nestedTypes: nested.filter((name) => rules.allowedBlocks.includes(name)),
      postId: (select("core/editor") as PostSelector).getCurrentPostId() };
  }, [slotClientId, selectedId]);
  if (!slot) return null;
  const rules = contract(slot.attributes);
  const atMaximum = rules.maximum !== null && blocks.length >= rules.maximum;
  const current = () => {
    const editor = registry.select("core/block-editor") as EditorSelector;
    const latestSlot = editor.getBlock(slotClientId);
    const owner = instanceOwner(editor, slotClientId);
    if (!latestSlot || !owner || (registry.select("core/editor") as PostSelector).getCurrentPostId() !== postId) throw new Error("unavailable");
    decodeBlocks(storedBlocks(owner, String(latestSlot.attributes.slotId ?? "")));
    return { editor, slot: latestSlot, blocks: editor.getBlocks(slotClientId), rules: contract(latestSlot.attributes) };
  };
  const update = (transform: (items: EditorBlock[]) => EditorBlock[]) => {
    try {
      const latest = current();
      const next = transform(latest.blocks);
      if (!accepts(next, latest.rules, latest.blocks)) throw new Error("contract");
      (registry.dispatch("core/block-editor") as EditorDispatch).replaceInnerBlocks(slotClientId, next);
      setError("");
    } catch {
      setError(__("The change could not be applied within this section's contract. The existing content was kept.", "smartcloud-agent-composer"));
    }
  };
  const add = (name: string) => update((items) => [...items, newContentBlock(name, rules)]);
  const addPattern = async (pattern: string) => {
    if (busy) return;
    setBusy(true);
    try {
      const initial = current();
      const ownerId = instanceOwner(initial.editor, slotClientId)?.clientId;
      const query = new URLSearchParams({ post_id: String(postId), slot_id: rules.slotId, pattern });
      const response = await apiFetch<{ content: string }>({ path: `/smartcloud-agent-composer/v1/editor-slot/pattern-template?${query}` });
      const incoming = parse(response.content) as EditorBlock[];
      const latest = current();
      if (incoming.length !== 1 || patternName(incoming[0]) !== pattern || instanceOwner(latest.editor, slotClientId)?.clientId !== ownerId) throw new Error("pattern");
      update((items) => [...items, ...stampBlocks(incoming, rules.slotId)]);
    } catch {
      setError(__("The approved pattern could not be added. The existing content was kept.", "smartcloud-agent-composer"));
    } finally { setBusy(false); }
  };
  const move = (id: string, direction: number) => update((items) => {
    const index = items.findIndex((item) => item.clientId === id);
    if (index < 0 || index + direction < 0 || index + direction >= items.length) return items;
    const result = [...items];
    [result[index], result[index + direction]] = [result[index + direction], result[index]];
    return result;
  });
  const remove = (id: string) => update((items) => items.filter((item) => item.clientId !== id));
  const canRemove = (id: string) => accepts(blocks.filter((item) => item.clientId !== id), rules, blocks);
  const updateNested = (transform: (items: EditorBlock[]) => EditorBlock[]) => update((items) => {
    if (!container) return items;
    const replace = (nodes: EditorBlock[]): EditorBlock[] => nodes.map((node) => node.clientId === container.clientId
      ? { ...node, innerBlocks: transform(node.innerBlocks) }
      : node.name === "core/block" ? node : { ...node, innerBlocks: replace(node.innerBlocks) });
    return replace(items);
  });
  const moveNested = (id: string, direction: number) => updateNested((items) => {
    const index = items.findIndex((item) => item.clientId === id);
    if (index < 0 || index + direction < 0 || index + direction >= items.length) return items;
    const next = [...items];
    [next[index], next[index + direction]] = [next[index + direction], next[index]];
    return next;
  });
  return <PanelBody title={sprintf(__("Content: %s", "smartcloud-agent-composer"), rules.slotId)} initialOpen>
    <div className="smartcloud-agent-composer-slot-settings">
      <p>{rules.maximum === null ? sprintf(__("%d content block(s)", "smartcloud-agent-composer"), blocks.length)
        : sprintf(__("%1$d of %2$d blocks", "smartcloud-agent-composer"), blocks.length, rules.maximum)}</p>
      {rules.allowedBlocks.filter((name) => {
        const parents = getBlockType(name)?.parent;
        return !parents?.length || parents.includes(SLOT);
      }).map((name) => <Button key={name} variant="secondary" disabled={busy || atMaximum} onClick={() => add(name)}>
        {sprintf(__("Add %s", "smartcloud-agent-composer"), getBlockType(name)?.title ?? name)}
      </Button>)}
      {rules.allowedPatterns.map((pattern) => <Button key={pattern} variant="secondary"
        disabled={busy || atMaximum || (typeof rules.patternOccurrences[pattern]?.max === "number" && blocks.filter((block) => patternName(block) === pattern).length >= rules.patternOccurrences[pattern].max!)}
        onClick={() => void addPattern(pattern)}>{sprintf(__("Add %s", "smartcloud-agent-composer"), pattern)}</Button>)}
      {blocks.map((block, index) => <div className="smartcloud-agent-composer-slot-settings__item" key={block.clientId}>
        <Button variant="tertiary" onClick={() => (registry.dispatch("core/block-editor") as EditorDispatch).selectBlock(block.clientId)}>
          {`${index + 1}. ${patternName(block) || getBlockType(block.name)?.title || block.name}`}
        </Button>
        <div className="smartcloud-agent-composer-slot-settings__actions">
          <Button disabled={busy || index === 0} onClick={() => move(block.clientId, -1)}>{__("Move up", "smartcloud-agent-composer")}</Button>
          <Button disabled={busy || index === blocks.length - 1} onClick={() => move(block.clientId, 1)}>{__("Move down", "smartcloud-agent-composer")}</Button>
          <Button isDestructive disabled={busy || !canRemove(block.clientId)} onClick={() => remove(block.clientId)}>{__("Remove", "smartcloud-agent-composer")}</Button>
        </div>
      </div>)}
      {container && nestedTypes.length > 0 && <div>
        <p>{sprintf(__("Inside %s", "smartcloud-agent-composer"), getBlockType(container.name)?.title ?? container.name)}</p>
        {nestedTypes.map((name) => <Button key={name} disabled={busy} onClick={() => updateNested((items) => [...items, newContentBlock(name, rules)])}>
          {sprintf(__("Add %s", "smartcloud-agent-composer"), getBlockType(name)?.title ?? name)}
        </Button>)}
        {container.innerBlocks.map((child, index) => <div key={child.clientId} className="smartcloud-agent-composer-slot-settings__item">
          <span>{`${index + 1}. ${getBlockType(child.name)?.title ?? child.name}`}</span>
          <div className="smartcloud-agent-composer-slot-settings__actions">
            <Button disabled={busy || index === 0} onClick={() => moveNested(child.clientId, -1)}>{__("Move up", "smartcloud-agent-composer")}</Button>
            <Button disabled={busy || index === container.innerBlocks.length - 1} onClick={() => moveNested(child.clientId, 1)}>{__("Move down", "smartcloud-agent-composer")}</Button>
            <Button isDestructive disabled={busy || container.innerBlocks.length < 2} onClick={() => updateNested((items) => items.filter((item) => item.clientId !== child.clientId))}>{__("Remove", "smartcloud-agent-composer")}</Button>
          </div>
        </div>)}
      </div>}
      {error && <p role="alert">{error}</p>}
      <p>{__("Changes are saved with the post. Shared pattern originals are unchanged.", "smartcloud-agent-composer")}</p>
    </div>
  </PanelBody>;
}

function withSlotSettings(BlockEdit: ComponentType<BlockEditProps>) {
  return function GovernedBlockEdit(props: BlockEditProps) {
    const { mode, slotIds } = useSelect((select) => {
      const editor = select("core/block-editor") as EditorSelector;
      const post = select("core/editor") as PostSelector;
      if (editor.getSettings().isPreviewMode || post.getCurrentPostType() === "wp_block") return { mode: undefined, slotIds: [] };
      const owner = instanceOwner(editor, props.clientId);
      const parentSlot = nearestSlot(editor, props.clientId);
      const metadata = composerMetadata(props.attributes);
      let mode: "default" | "contentOnly" | undefined;
      if (owner && (props.name === SLOT || (parentSlot && metadata.ownership === "USER"))) mode = "default";
      else if (owner && Object.values(record(record(props.attributes.metadata).bindings)).some((binding) => record(binding).source === "core/pattern-overrides")) mode = "contentOnly";
      let slotIds: string[] = [];
      if (props.isSelected) {
        if (props.name === SLOT && owner) slotIds = [props.clientId];
        else {
          if (parentSlot && owner) slotIds.push(parentSlot.clientId);
          const persistedOwner = props.name === "core/block" && !!patternName({ ...props, innerBlocks: [] }) &&
            (owner || flattenBlocks(editor.getBlocks()).some((block) => block.clientId === props.clientId));
          if (persistedOwner) slotIds.push(...directSlots(editor, props.clientId));
        }
      }
      return { mode, slotIds };
    }, [props.clientId, props.name, props.attributes, props.isSelected]);
    useBlockEditingMode(mode);
    return <><BlockEdit {...props} />{slotIds.length > 0 && <InspectorControls group="list">
      {slotIds.map((id) => <SlotControls key={id} slotClientId={id} selectedId={props.clientId} />)}
    </InspectorControls>}</>;
  };
}
addFilter("editor.BlockEdit", "smartcloud-agent-composer/instance-slot-settings", withSlotSettings);
