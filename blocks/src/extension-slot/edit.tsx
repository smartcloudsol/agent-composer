import {
  useBlockProps,
  useInnerBlocksProps,
} from "@wordpress/block-editor";
import { createBlock, getBlockType } from "@wordpress/blocks";
import { useDispatch, useSelect } from "@wordpress/data";
import { useEffect, useState } from "@wordpress/element";
import { __, sprintf } from "@wordpress/i18n";
import {
  composerMetadata,
  createUserBlockId,
  flattenBlocks,
  isValidUserBlockId,
  type EditorBlock,
  withComposerMetadata,
} from "./identity";

interface ExtensionSlotAttributes {
  slotId?: string;
  allowedBlocks?: string[];
  allowedPatterns?: string[];
  patternOccurrences?: Record<string, { min?: number; max?: number | null }>;
  minBlocks?: number;
  maxBlocks?: number;
}

interface BlockEditorSelector {
  getBlocks(rootClientId?: string): EditorBlock[];
  canInsertBlockType(blockName: string, rootClientId: string): boolean;
}

interface BlockEditorDispatcher {
  updateBlockAttributes(
    clientId: string,
    attributes: Record<string, unknown>,
  ): void;
  insertBlock(
    block: EditorBlock,
    index?: number,
    rootClientId?: string,
    updateSelection?: boolean,
  ): void;
}

export default function Edit({
  attributes,
  clientId,
}: {
  attributes: ExtensionSlotAttributes;
  clientId: string;
}) {
  const slotId = attributes.slotId?.trim() || "extension-slot";
  const allowedBlocks = Array.isArray(attributes.allowedBlocks)
    ? attributes.allowedBlocks
    : [];
  const allowedPatterns = Array.isArray(attributes.allowedPatterns)
    ? attributes.allowedPatterns
    : [];
  const editorAllowedBlocks = allowedPatterns.length > 0
    ? [...new Set([...allowedBlocks, "core/block"])]
    : allowedBlocks;
  const minimum = Math.max(0, attributes.minBlocks ?? 0);
  const maximum =
    typeof attributes.maxBlocks === "number"
      ? Math.max(0, attributes.maxBlocks)
      : null;
  const { documentBlocks, insertableBlocks, slotBlocks } = useSelect(
    (select) => {
      const editor = select("core/block-editor") as BlockEditorSelector;
      return {
        documentBlocks: editor.getBlocks(),
        insertableBlocks: allowedBlocks.filter((blockName) =>
          editor.canInsertBlockType(blockName, clientId),
        ),
        slotBlocks: editor.getBlocks(clientId),
      };
    },
    [allowedBlocks, clientId],
  );
  const { insertBlock, updateBlockAttributes } = useDispatch(
    "core/block-editor",
  ) as BlockEditorDispatcher;
  const [isChoosingBlock, setIsChoosingBlock] = useState(false);

  useEffect(() => {
    const idCounts = new Map<string, number>();
    for (const block of flattenBlocks(documentBlocks)) {
      const userBlockId = composerMetadata(block.attributes).userBlockId;
      if (isValidUserBlockId(userBlockId)) {
        idCounts.set(userBlockId, (idCounts.get(userBlockId) ?? 0) + 1);
      }
    }

    for (const block of flattenBlocks(slotBlocks)) {
      const current = composerMetadata(block.attributes);
      const duplicate =
        isValidUserBlockId(current.userBlockId) &&
        (idCounts.get(current.userBlockId) ?? 0) > 1;
      const userBlockId =
        isValidUserBlockId(current.userBlockId) && !duplicate
          ? current.userBlockId
          : createUserBlockId();
      if (
        current.ownership === "USER" &&
        current.slotId === slotId &&
        current.userBlockId === userBlockId
      ) {
        continue;
      }
      updateBlockAttributes(
        block.clientId,
        withComposerMetadata(block.attributes, {
          ...current,
          ownership: "USER",
          slotId,
          userBlockId,
        }),
      );
    }
  }, [documentBlocks, slotBlocks, slotId, updateBlockAttributes]);

  const count = slotBlocks.length;
  const atMaximum = null !== maximum && count >= maximum;
  const belowMinimum = count < minimum;
  const blockProps = useBlockProps();
  const innerBlocksProps = useInnerBlocksProps(
    { className: "smartcloud-agent-composer-slot__content" },
    {
      allowedBlocks: editorAllowedBlocks,
      renderAppender: () => null,
      templateLock: false,
    },
  );

  const capacity =
    null === maximum
      ? sprintf(
          __("%d content block(s)", "smartcloud-agent-composer"),
          count,
        )
      : sprintf(
          __("%1$d of %2$d blocks", "smartcloud-agent-composer"),
          count,
          maximum,
        );

  return (
    <div {...blockProps}>
      <div className="smartcloud-agent-composer-slot__header">
        <span>{sprintf(__("Additional content: %s", "smartcloud-agent-composer"), slotId)}</span>
        <span className="smartcloud-agent-composer-slot__count">
          {capacity}
        </span>
      </div>
      <div {...innerBlocksProps} />
      {!atMaximum && (
        <div className="smartcloud-agent-composer-slot__appender">
          {0 === count && (
            <span className="smartcloud-agent-composer-slot__empty-hint">
              {__(
                "Add an approved content block inside this section.",
                "smartcloud-agent-composer",
              )}
            </span>
          )}
          <button
            type="button"
            className="smartcloud-agent-composer-slot__add"
            aria-expanded={isChoosingBlock}
            onPointerDown={(event) => event.stopPropagation()}
            onClick={(event) => {
              event.stopPropagation();
              setIsChoosingBlock(!isChoosingBlock);
            }}
          >
            {__("+ Add block", "smartcloud-agent-composer")}
          </button>
          {isChoosingBlock && (
            <div
              className="smartcloud-agent-composer-slot__choices"
              role="group"
              aria-label={__("Approved blocks", "smartcloud-agent-composer")}
            >
              {insertableBlocks.map((blockName) => (
                <button
                  type="button"
                  key={blockName}
                  onPointerDown={(event) => event.stopPropagation()}
                  onClick={(event) => {
                    event.stopPropagation();
                    insertBlock(
                      createBlock(blockName) as EditorBlock,
                      slotBlocks.length,
                      clientId,
                      true,
                    );
                    setIsChoosingBlock(false);
                  }}
                >
                  {getBlockType(blockName)?.title ?? blockName}
                </button>
              ))}
              {0 === insertableBlocks.length && (
                <span>
                  {__(
                    "No approved block is currently available.",
                    "smartcloud-agent-composer",
                  )}
                </span>
              )}
            </div>
          )}
        </div>
      )}
      {atMaximum && (
        <div className="smartcloud-agent-composer-slot__limit">
          {__(
            "This additional-content section has reached its block limit.",
            "smartcloud-agent-composer",
          )}
        </div>
      )}
      {belowMinimum && (
        <div className="smartcloud-agent-composer-slot__warning">
          {sprintf(
            __("Add at least %d block(s) to satisfy this slot contract.", "smartcloud-agent-composer"),
            minimum,
          )}
        </div>
      )}
    </div>
  );
}
