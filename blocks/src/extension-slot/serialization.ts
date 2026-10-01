export interface RawBlock {
  blockName: string;
  attrs: Record<string, unknown>;
  innerBlocks: RawBlock[];
  innerHTML: string;
  innerContent: Array<string | null>;
}

function isRecord(value: unknown): value is Record<string, unknown> {
  if (!value || typeof value !== "object" || Array.isArray(value)) {
    return false;
  }
  const prototype: unknown = Object.getPrototypeOf(value);
  return prototype === Object.prototype || prototype === null;
}

function invalid(): never {
  throw new Error("The saved slot content contains an invalid raw block.");
}

/**
 * WordPress's raw parser includes freeform whitespace between block comments.
 * Remove only those separators before counting or serializing instance slots.
 * Reject unsafe content instead of silently losing it during normalization.
 */
export function normalizeRawBlocks(input: unknown): RawBlock[] {
  const ancestors = new Set<object>();
  let count = 0;

  const normalizeList = (value: unknown, depth: number): RawBlock[] => {
    if (!Array.isArray(value) || depth > 100) {
      return invalid();
    }
    const result: RawBlock[] = [];
    for (const item of value) {
      const block = normalizeBlock(item, depth);
      if (block) {
        result.push(block);
      }
    }
    return result;
  };

  const normalizeAttrs = (value: unknown, depth: number): Record<string, unknown> => {
    // PHP represents an empty attribute map as an empty JSON array.
    if (Array.isArray(value) && value.length === 0) {
      return {};
    }
    if (!isRecord(value)) {
      return invalid();
    }
    const attrs = { ...value };
    if (attrs.metadata === undefined) {
      return attrs;
    }
    if (!isRecord(attrs.metadata)) {
      return invalid();
    }
    const metadata = attrs.metadata;
    if (metadata.wpsuiteAgentComposer === undefined) {
      return attrs;
    }
    if (!isRecord(metadata.wpsuiteAgentComposer)) {
      return invalid();
    }
    const composer = metadata.wpsuiteAgentComposer;
    if (composer.instanceSlots === undefined) {
      return attrs;
    }
    if (!isRecord(composer.instanceSlots)) {
      return invalid();
    }
    const slots = Object.fromEntries(Object.entries(composer.instanceSlots)
      .map(([slotId, children]) => [slotId, normalizeList(children, depth + 1)]));
    attrs.metadata = { ...metadata, wpsuiteAgentComposer: { ...composer, instanceSlots: slots } };
    return attrs;
  };

  const normalizeBlock = (value: unknown, depth: number): RawBlock | null => {
    count += 1;
    if (depth > 100 || count > 10000 || !isRecord(value) || ancestors.has(value)) {
      return invalid();
    }
    ancestors.add(value);
    try {
      const { blockName, innerBlocks, innerHTML, innerContent } = value;
      if (!Array.isArray(innerBlocks) || typeof innerHTML !== "string" ||
        !Array.isArray(innerContent)) {
        return invalid();
      }
      let placeholders = 0;
      for (const fragment of innerContent) {
        if (fragment === null) {
          placeholders += 1;
        } else if (typeof fragment !== "string") {
          return invalid();
        }
      }
      if (placeholders !== innerBlocks.length ||
        innerContent.filter((fragment) => fragment !== null).join("") !== innerHTML) {
        return invalid();
      }
      if (blockName === null || blockName === undefined) {
        const emptyAttrs = isRecord(value.attrs) && Object.keys(value.attrs).length === 0 ||
          Array.isArray(value.attrs) && value.attrs.length === 0;
        if (!emptyAttrs || innerBlocks.length !== 0 || !/^\s*$/.test(innerHTML)) {
          return invalid();
        }
        return null;
      }
      if (typeof blockName !== "string" || !/^[a-z0-9-]+\/[a-z0-9-]+$/.test(blockName) ||
        blockName === "core/undefined" || blockName === "core/missing") {
        return invalid();
      }
      const attrs = normalizeAttrs(value.attrs, depth);
      const children: RawBlock[] = [];
      const content: Array<string | null> = [];
      let childIndex = 0;
      let removedSeparator = false;
      for (const fragment of innerContent) {
        if (fragment !== null) {
          content.push(fragment as string);
          continue;
        }
        const rawChild: unknown = innerBlocks[childIndex++];
        const child = normalizeBlock(rawChild, depth + 1);
        if (child) {
          children.push(child);
          content.push(null);
        } else {
          // Keep separator whitespace in the parent's HTML, but retire its
          // placeholder so later children still occupy their original positions.
          content.push((rawChild as Record<string, unknown>).innerHTML as string);
          removedSeparator = true;
        }
      }
      return {
        blockName,
        attrs,
        innerBlocks: children,
        innerHTML: removedSeparator ? content.filter((fragment) => fragment !== null).join("") : innerHTML,
        innerContent: content,
      };
    } finally {
      ancestors.delete(value);
    }
  };

  return normalizeList(input, 0);
}
