import { BlockContextProvider, useInnerBlocksProps } from "@wordpress/block-editor";
import { useRegistry } from "@wordpress/data";
import { useEffect, useRef, useState } from "@wordpress/element";
import { __, sprintf } from "@wordpress/i18n";
import { composerMetadata, withComposerMetadata, type EditorBlock } from "./identity";
import { accepts, contract, decodeBlocks, encodeBlocks, instanceOwner, slotAttributes, storedBlocks, stampBlocks, type EditorDispatch, type EditorSelector } from "./adapter";

const INSTANCE_CONTEXT = { "pattern/overrides": undefined };

/** A controlled child tree in the main Gutenberg registry; no second editor. */
export default function InstanceSlotEditor({ owner, clientId, attributes }: {
  owner: EditorBlock;
  clientId: string;
  attributes: Record<string, unknown>;
}) {
  const registry = useRegistry();
  const [error, setError] = useState("");
  const identitiesRef = useRef(new Map<string, string>());
  const rules = contract(attributes);
  let raw: unknown;
  try { raw = storedBlocks(owner, rules.slotId); }
  catch { raw = null; }
  const key = JSON.stringify(raw);
  const read = () => {
    try { return { sourceKey: key, key, blocks: decodeBlocks(raw), error: "" }; }
    catch { return { sourceKey: key, key, blocks: [] as EditorBlock[], error: __("The saved content contains invalid blocks. It has been preserved; editing is disabled.", "smartcloud-agent-composer") }; }
  };
  // Registry notifications are synchronous. React state can still contain the
  // previous value when a parent notifies us, so acknowledge writes in a ref
  // before updating the owner. Retain the exact outgoing Gutenberg array.
  /* eslint-disable react-hooks/refs -- Gutenberg's synchronous external-store callbacks require outgoing-array acknowledgement before React commits owner props. This cache is the controlled-store boundary, not independent render state. */
  const cacheRef = useRef<ReturnType<typeof read> | null>(null);
  if (!cacheRef.current) cacheRef.current = read();
  let cache = cacheRef.current;
  // A registry subscription may render before the owner prop acknowledges our
  // outgoing change. Keep its exact array until that acknowledgement arrives.
  if (cache.sourceKey !== key) {
    cache = cache.key === key ? { ...cache, sourceKey: key } : read();
    cacheRef.current = cache;
  }
  const blocks = cache.blocks;
  const change = (next: EditorBlock[]) => {
    const previous = cacheRef.current;
    if (!previous || previous.error) return;
    const editor = registry.select("core/block-editor") as EditorSelector;
    const dispatcher = registry.dispatch("core/block-editor") as EditorDispatch;
    const currentOwner = instanceOwner(editor, clientId);
    if (!currentOwner) return;
    let outgoing: typeof previous | null = null;
    try {
      if (!accepts(next, rules, previous.blocks)) throw new Error("contract");
      const canonical = stampBlocks(next, rules.slotId, identitiesRef.current);
      const encoded = encodeBlocks(canonical, rules.slotId);
      const ownerAttributes = slotAttributes(currentOwner, rules.slotId, encoded);
      // Keep Gutenberg's outgoing array identity: reparsing here loses focus/caret.
      outgoing = { sourceKey: previous.sourceKey, key: JSON.stringify(encoded), blocks: next, error: "" };
      cacheRef.current = outgoing;
      dispatcher.updateBlockAttributes(currentOwner.clientId, ownerAttributes);
      setError("");
    } catch {
      // A dispatch can notify another slot synchronously. Do not roll back a
      // newer callback's accepted content if that dispatch subsequently fails.
      if (outgoing && cacheRef.current === outgoing) cacheRef.current = previous;
      if (cacheRef.current === previous) {
        dispatcher.replaceInnerBlocks(clientId, previous.blocks);
        setError(__("This change is outside the allowed content contract.", "smartcloud-agent-composer"));
      }
    }
  };
  useEffect(() => {
    if (cache.error || cacheRef.current !== cache) return;
    const editor = registry.select("core/block-editor") as EditorSelector;
    const dispatcher = registry.dispatch("core/block-editor") as EditorDispatch;
    const canonical = stampBlocks(cache.blocks, rules.slotId, identitiesRef.current);
    const applyIdentities = (live: EditorBlock[], stamped: EditorBlock[]) => {
      if (cacheRef.current !== cache || live.length !== stamped.length) return;
      live.forEach((block, index) => {
        if (cacheRef.current !== cache) return;
        const target = stamped[index];
        // useBlockSync clones incoming clientIds and restores external IDs in
        // outgoing callbacks. Compare shape here; the cache guard above stops
        // this projection as soon as any callback replaces its source snapshot.
        if (block.name !== target.name) return;
        const metadata = composerMetadata(target.attributes);
        const latest = editor.getBlock(block.clientId);
        if (!latest) return;
        const current = composerMetadata(latest.attributes);
        if (current.ownership !== metadata.ownership || current.slotId !== metadata.slotId || current.userBlockId !== metadata.userBlockId) {
          // Only project identities. A nested slot may already have changed its
          // instanceSlots since this parent rendered; never restore that payload.
          dispatcher.updateBlockAttributes(block.clientId, withComposerMetadata(latest.attributes, {
            ...current, ownership: metadata.ownership, slotId: metadata.slotId, userBlockId: metadata.userBlockId,
          }));
        }
        if (block.name !== "core/block") applyIdentities(block.innerBlocks, target.innerBlocks);
      });
    };
    applyIdentities(editor.getBlocks(clientId), canonical);
  }, [cache, clientId, registry, rules.slotId]);
  const innerProps = useInnerBlocksProps({ className: "smartcloud-agent-composer-slot__content" }, {
    value: blocks, onInput: change, onChange: change,
    allowedBlocks: rules.allowedBlocks, templateLock: false, renderAppender: () => null,
  });
  return <>
    <p className="smartcloud-agent-composer-slot__count">{rules.maximum === null
      ? sprintf(__("%d content block(s)", "smartcloud-agent-composer"), blocks.length)
      : sprintf(__("%1$d of %2$d blocks", "smartcloud-agent-composer"), blocks.length, rules.maximum)}</p>
    <p className="smartcloud-agent-composer-slot__empty-hint">{__("Select this section or one of its blocks, then open the List View tab in the block sidebar to add, remove or reorder content.", "smartcloud-agent-composer")}</p>
    {/* These children belong to the post, not the surrounding pattern's fields.
        Nested core/block instances establish their own override context again. */}
    <BlockContextProvider value={INSTANCE_CONTEXT}><div {...innerProps} /></BlockContextProvider>
    {(cache.error || error) && <p role="alert">{cache.error || error}</p>}
  </>;
  /* eslint-enable react-hooks/refs */
}
