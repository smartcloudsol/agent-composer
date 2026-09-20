export interface EditorBlock {
  clientId: string;
  attributes: Record<string, unknown>;
  innerBlocks: EditorBlock[];
}

export interface ComposerMetadata {
  contractId?: string;
  contractVersion?: number;
  nodeId?: string;
  ownership?: string;
  slotId?: string;
  userBlockId?: string;
  [key: string]: unknown;
}

const USER_BLOCK_ID = /^user-[a-z0-9-]{8,58}$/;

export function isValidUserBlockId(value: unknown): value is string {
  return typeof value === "string" && USER_BLOCK_ID.test(value);
}

export function createUserBlockId(): string {
  if (typeof globalThis.crypto?.randomUUID === "function") {
    return `user-${globalThis.crypto.randomUUID()}`;
  }
  const bytes = new Uint8Array(16);
  if (typeof globalThis.crypto?.getRandomValues === "function") {
    globalThis.crypto.getRandomValues(bytes);
  } else {
    for (let index = 0; index < bytes.length; index += 1) {
      bytes[index] = Math.floor(Math.random() * 256);
    }
  }
  const suffix = Array.from(bytes, (value) => value.toString(16).padStart(2, "0")).join("");
  return `user-${suffix}`;
}

export function flattenBlocks(blocks: EditorBlock[]): EditorBlock[] {
  return blocks.flatMap((block) => [block, ...flattenBlocks(block.innerBlocks ?? [])]);
}

export function composerMetadata(attributes: Record<string, unknown>): ComposerMetadata {
  const metadata = isRecord(attributes.metadata) ? attributes.metadata : {};
  return isRecord(metadata.wpsuiteAgentComposer)
    ? (metadata.wpsuiteAgentComposer as ComposerMetadata)
    : {};
}

export function withComposerMetadata(
  attributes: Record<string, unknown>,
  composer: ComposerMetadata,
): Record<string, unknown> {
  const metadata = isRecord(attributes.metadata) ? attributes.metadata : {};
  return {
    metadata: {
      ...metadata,
      wpsuiteAgentComposer: composer,
    },
  };
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === "object" && value !== null && !Array.isArray(value);
}
