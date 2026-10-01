import { createBlock, getBlockType, parse, serialize, serializeRawBlock } from "@wordpress/blocks";
import { parse as parseRaw } from "@wordpress/block-serialization-default-parser";
import { composerMetadata, createUserBlockId, flattenBlocks, isValidUserBlockId, type EditorBlock, withComposerMetadata } from "./identity";
import { normalizeRawBlocks } from "./serialization";

export const SLOT = "smartcloud-agent-composer/extension-slot";
export interface EditorSelector {
  getBlocks(rootClientId?: string): EditorBlock[];
  getBlock(clientId: string): EditorBlock | null;
  getBlockParents(clientId: string): string[];
  getBlockRootClientId(clientId: string): string | null;
  getBlockListSettings(clientId: string): { allowedBlocks?: string[] } | undefined;
  getSettings(): { isPreviewMode?: boolean };
}
export interface EditorDispatch {
  updateBlockAttributes(clientId: string, attributes: Record<string, unknown>): void;
  replaceInnerBlocks(clientId: string, blocks: EditorBlock[], updateSelection?: boolean): void;
  selectBlock(clientId: string): void;
}
export interface SlotContract {
  slotId: string;
  allowedBlocks: string[];
  allowedPatterns: string[];
  patternOccurrences: Record<string, { min?: number; max?: number | null }>;
  minimum: number;
  maximum: number | null;
}
export function record(value: unknown): Record<string, unknown> {
  return value && typeof value === "object" && !Array.isArray(value) ? value as Record<string, unknown> : {};
}
export function contract(attributes: Record<string, unknown>): SlotContract {
  return {
    slotId: String(attributes.slotId ?? ""),
    allowedBlocks: Array.isArray(attributes.allowedBlocks) ? attributes.allowedBlocks as string[] : [],
    allowedPatterns: Array.isArray(attributes.allowedPatterns) ? attributes.allowedPatterns as string[] : [],
    patternOccurrences: record(attributes.patternOccurrences) as SlotContract["patternOccurrences"],
    minimum: typeof attributes.minBlocks === "number" ? Math.max(0, attributes.minBlocks) : 0,
    maximum: typeof attributes.maxBlocks === "number" ? Math.max(0, attributes.maxBlocks) : null,
  };
}
export function patternName(block: EditorBlock): string {
  return String(composerMetadata(block.attributes).patternName ?? "");
}
/** Resolve only instances belonging to the post, never the shared wp_block entity. */
export function instanceOwner(editor: EditorSelector, clientId: string): EditorBlock | null {
  if (editor.getSettings().isPreviewMode) return null;
  const ancestors = editor.getBlockParents(clientId).map((id) => editor.getBlock(id));
  const owners = ancestors.filter((block): block is EditorBlock => !!block && block.name === "core/block");
  const persistedTree = (blocks: EditorBlock[]): EditorBlock[] => blocks.flatMap((block) =>
    [block, ...(block.name === "core/block" ? [] : persistedTree(block.innerBlocks ?? []))]);
  const persisted = persistedTree(editor.getBlocks());
  if (!owners.length || !persisted.some((block) => block.clientId === owners[0].clientId)) return null;
  if (owners.some((owner) => !patternName(owner))) return null;
  // A shared pattern may itself contain references. Only references proven to
  // live in the parent's instance slot payload belong to this post.
  let storageOwner = owners[0];
  for (let index = 1; index < owners.length; index += 1) {
    const owner = owners[index];
    const parentIndex = ancestors.indexOf(owners[index - 1]);
    const ownerIndex = ancestors.indexOf(owner);
    const slot = ancestors.slice(parentIndex + 1, ownerIndex).reverse()
      .find((block) => block?.name === SLOT);
    const slotId = slot?.attributes.slotId;
    const identity = composerMetadata(owner.attributes);
    if (typeof slotId !== "string" || !slotId ||
      (identity.ownership !== undefined && identity.ownership !== "USER") ||
      (identity.slotId !== undefined && identity.slotId !== slotId)) return null;
    try {
      const raw = normalizeRawBlocks(storedBlocks(storageOwner, slotId));
      const candidates: typeof raw = [];
      const visit = (blocks: typeof raw) => {
        for (const block of blocks) {
          if (block.blockName === "core/block") candidates.push(block);
          else visit(block.innerBlocks);
        }
      };
      visit(raw);
      const matches = candidates.filter((block) => {
        const stored = composerMetadata(block.attrs);
        const userIdMatches = isValidUserBlockId(identity.userBlockId) && stored.userBlockId === identity.userBlockId;
        const instanceIdMatches = typeof identity.patternInstanceId === "string" && identity.patternInstanceId.length > 0 &&
          stored.patternInstanceId === identity.patternInstanceId;
        return (stored.ownership === undefined || stored.ownership === "USER") &&
          (stored.slotId === undefined || stored.slotId === slotId) &&
          stored.patternName === identity.patternName && block.attrs.ref === owner.attributes.ref &&
          (userIdMatches || instanceIdMatches);
      });
      if (matches.length !== 1) return null;
      // Trace the next level through the root's persisted payload, rather than
      // trusting metadata from the expanded shared-entity editor tree.
      storageOwner = { ...owner, attributes: matches[0].attrs };
    } catch {
      return null;
    }
  }
  return owners[owners.length - 1];
}
export function nearestSlot(editor: EditorSelector, clientId: string): EditorBlock | null {
  return [...editor.getBlockParents(clientId)].reverse().map((id) => editor.getBlock(id))
    .find((block) => block?.name === SLOT) ?? null;
}
export function storedBlocks(owner: EditorBlock, slotId: string): unknown {
  const object = (value: unknown): Record<string, unknown> => {
    if (!value || typeof value !== "object" || Array.isArray(value) ||
      ![Object.prototype, null].includes(Object.getPrototypeOf(value))) {
      throw new Error("The saved instance slot metadata is invalid.");
    }
    return value as Record<string, unknown>;
  };
  if (owner.attributes.metadata === undefined) return [];
  const metadata = object(owner.attributes.metadata);
  if (metadata.wpsuiteAgentComposer === undefined) return [];
  const composer = object(metadata.wpsuiteAgentComposer);
  if (!Object.hasOwn(composer, "instanceSlots")) return [];
  const slots = object(composer.instanceSlots);
  // Validate all existing payloads before a write can replace their container.
  // Only an absent key represents an empty slot; null is malformed saved data.
  for (const value of Object.values(slots)) normalizeRawBlocks(value);
  return Object.hasOwn(slots, slotId) ? slots[slotId] : [];
}
export function decodeBlocks(raw: unknown): EditorBlock[] {
  const result = parse(normalizeRawBlocks(raw).map((block) => serializeRawBlock(block)).join("")) as EditorBlock[];
  if (flattenBlocks(result).some((block) => !getBlockType(block.name) || block.isValid === false)) {
    throw new Error("The saved slot contains an invalid or unavailable block.");
  }
  return result;
}
export function stampBlocks(blocks: EditorBlock[], slotId: string, identities = new Map<string, string>()): EditorBlock[] {
  const seen = new Set<string>();
  const stamp = (block: EditorBlock): EditorBlock => {
    const current = composerMetadata(block.attributes);
    const remembered = identities.get(block.clientId);
    const candidate = remembered ?? current.userBlockId;
    const sameSlot = remembered !== undefined || !current.slotId || current.slotId === slotId;
    const id = sameSlot && isValidUserBlockId(candidate) && !seen.has(candidate) ? candidate : createUserBlockId();
    identities.set(block.clientId, id);
    seen.add(id);
    return { ...block, attributes: { ...block.attributes, ...withComposerMetadata(block.attributes, {
      ...current, ownership: "USER", slotId, userBlockId: id,
    }) }, innerBlocks: block.name === "core/block" ? [] : (block.innerBlocks ?? []).map(stamp) };
  };
  return blocks.map(stamp);
}
export function encodeBlocks(blocks: EditorBlock[], slotId: string) {
  return normalizeRawBlocks(parseRaw(serialize(stampBlocks(blocks, slotId))));
}
export function slotAttributes(owner: EditorBlock, slotId: string, raw: unknown): Record<string, unknown> {
  storedBlocks(owner, slotId);
  const normalized = normalizeRawBlocks(raw);
  const metadata = record(owner.attributes.metadata);
  const composer = record(metadata.wpsuiteAgentComposer);
  // An explicit empty list overrides defaults. Do not delete the key on empty.
  return { metadata: { ...metadata, wpsuiteAgentComposer: { ...composer,
    instanceSlots: { ...record(composer.instanceSlots), [slotId]: normalized },
  } } };
}
function ownedTree(blocks: EditorBlock[]): EditorBlock[] {
  return blocks.flatMap((block) => [block, ...(block.name === "core/block" ? [] : ownedTree(block.innerBlocks ?? []))]);
}
export function accepts(blocks: EditorBlock[], rules: SlotContract, previous?: EditorBlock[]): boolean {
  if (rules.maximum !== null && blocks.length > rules.maximum) return false;
  // Permit adding/editing towards the minimum in a not-yet-complete draft.
  if (blocks.length < rules.minimum && blocks.length < (previous?.length ?? rules.minimum)) return false;
  const counts: Record<string, number> = {};
  for (const block of ownedTree(blocks)) {
    if (block.isValid === false) return false;
    if (block.name === "core/block") {
      const pattern = patternName(block);
      if (!rules.allowedPatterns.includes(pattern)) return false;
      counts[pattern] = (counts[pattern] ?? 0) + 1;
    } else if (!rules.allowedBlocks.includes(block.name)) return false;
  }
  for (const name of rules.allowedPatterns) {
    const amount = counts[name] ?? 0;
    const bounds = rules.patternOccurrences[name] ?? {};
    const before = previous && ownedTree(previous).filter((block) => patternName(block) === name).length;
    if (typeof bounds.max === "number" && amount > bounds.max) return false;
    if (amount < (bounds.min ?? 0) && amount < (before ?? bounds.min ?? 0)) return false;
  }
  return true;
}
export function newContentBlock(name: string, rules: SlotContract): EditorBlock {
  // Core list requires an item to provide a native editable caret.
  const children = name === "core/list" && rules.allowedBlocks.includes("core/list-item")
    ? [createBlock("core/list-item") as EditorBlock] : [];
  return stampBlocks([createBlock(name, {}, children) as EditorBlock], rules.slotId)[0];
}
