import { InnerBlocks, useBlockProps } from "@wordpress/block-editor";

export default function Save({
  attributes,
}: {
  attributes: { slotId?: string };
}) {
  return (
    <div
      {...useBlockProps.save({
        "data-composer-extension-slot": attributes.slotId ?? "",
      })}
    >
      <InnerBlocks.Content />
    </div>
  );
}
