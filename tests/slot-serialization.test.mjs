import assert from "node:assert/strict";
import test from "node:test";
import { build } from "esbuild";

const { outputFiles } = await build({
  entryPoints: [new URL("../blocks/src/extension-slot/serialization.ts", import.meta.url).pathname],
  bundle: true,
  platform: "node",
  format: "esm",
  write: false,
});
const { normalizeRawBlocks } = await import(`data:text/javascript;base64,${Buffer.from(outputFiles[0].text).toString("base64")}`);

const paragraph = (text) => ({
  blockName: "core/paragraph",
  attrs: {},
  innerBlocks: [],
  innerHTML: `<p>${text}</p>`,
  innerContent: [`<p>${text}</p>`],
});
const separator = (text = "\n\n") => ({
  blockName: null,
  attrs: {},
  innerBlocks: [],
  innerHTML: text,
  innerContent: [text],
});
const freeze = (value) => {
  if (value && typeof value === "object") {
    Object.values(value).forEach(freeze);
    Object.freeze(value);
  }
  return value;
};

test("two parsed paragraphs count as two blocks, excluding raw serialization separators", () => {
  const first = paragraph("First paragraph.");
  const second = paragraph("Second paragraph.");
  const input = freeze([separator(), first, separator(), second, separator("\n")]);
  assert.deepEqual(normalizeRawBlocks(input), [first, second]);
  assert.equal(input.length, 5);
  assert.deepEqual(normalizeRawBlocks([separator(""), { ...separator(), blockName: undefined }]), []);
});

test("list nesting preserves HTML, attributes, and child placeholder order", () => {
  const items = ["One", "Two"].map((text) => ({
    blockName: "core/list-item", attrs: {}, innerBlocks: [],
    innerHTML: `<li>${text}</li>`, innerContent: [`<li>${text}</li>`],
  }));
  const list = {
    blockName: "core/list", attrs: { ordered: false, className: "details" },
    innerBlocks: items,
    innerHTML: '<ul class="wp-block-list">\n\n</ul>',
    innerContent: ['<ul class="wp-block-list">', null, "\n\n", null, "</ul>"],
  };
  assert.deepEqual(normalizeRawBlocks(freeze([list])), [list]);
  const withSeparatorChild = {
    ...list,
    innerBlocks: [items[0], separator(), items[1]],
    innerHTML: '<ul class="wp-block-list"></ul>',
    innerContent: ['<ul class="wp-block-list">', null, null, null, "</ul>"],
  };
  assert.deepEqual(normalizeRawBlocks(freeze([withSeparatorChild])), [list]);
});

test("nested instance slots normalize recursively without mutating metadata or authored content", () => {
  const reference = {
    blockName: "core/block", attrs: { ref: 123 }, innerBlocks: [], innerHTML: "", innerContent: [],
  };
  const metadata = { wpsuiteAgentComposer: { instanceSlots: { body: [paragraph("Inner"), separator()] } } };
  reference.attrs.metadata = metadata;
  const parent = paragraph("Outer");
  parent.attrs = {
    metadata: { name: "Authored", otherPlugin: { flag: true }, wpsuiteAgentComposer: {
      ownership: "USER", instanceSlots: { "body.additional": [separator(), reference, separator()] },
    } },
    content: "Preserved <em>attribute</em>",
  };
  const input = freeze([parent]);
  const before = JSON.stringify(input);
  const output = normalizeRawBlocks(input);
  const normalizedMetadata = output[0].attrs.metadata;
  const nested = normalizedMetadata.wpsuiteAgentComposer.instanceSlots["body.additional"];
  assert.equal(nested.length, 1);
  assert.deepEqual(nested[0].attrs.metadata.wpsuiteAgentComposer.instanceSlots.body, [paragraph("Inner")]);
  assert.equal(output[0].attrs.content, parent.attrs.content);
  assert.deepEqual(normalizedMetadata.otherPlugin, { flag: true });
  assert.equal(normalizedMetadata.name, "Authored");
  assert.equal(JSON.stringify(input), before);
  assert.deepEqual(normalizeRawBlocks(output), output);
});

test("PHP empty attribute arrays normalize to records", () => {
  assert.deepEqual(normalizeRawBlocks([{ ...paragraph("PHP"), attrs: [] }]), [paragraph("PHP")]);
});

test("substantive freeform content and malformed raw shapes fail instead of disappearing", () => {
  const bad = [
    null, {}, "paragraph", { ...paragraph("x"), blockName: "core/undefined" },
    { ...paragraph("x"), blockName: "core/missing" },
    { ...paragraph("x"), blockName: "paragraph" },
    { ...paragraph("x"), blockName: 3 },
    { ...paragraph("x"), attrs: ["invalid"] },
    { ...paragraph("x"), attrs: null },
    { ...paragraph("x"), innerBlocks: {} },
    { ...paragraph("x"), innerContent: [1] },
    { ...paragraph("x"), innerContent: [null] },
    { ...paragraph("x"), innerBlocks: [paragraph("lost")] },
    { ...paragraph("x"), innerHTML: "Different HTML" },
    separator("<p>Unwrapped content</p>"),
    { ...separator(), attrs: { authored: true } },
    { ...separator(), innerBlocks: [paragraph("lost")], innerContent: ["\n\n", null] },
  ];
  for (const block of bad) {
    assert.throws(() => normalizeRawBlocks([block]), /invalid raw block/);
  }
  for (const value of [undefined, null, {}, "text"]) {
    assert.throws(() => normalizeRawBlocks(value), /invalid raw block/);
  }
});

test("invalid or cyclic nested slots cannot silently drop saved content", () => {
  for (const slots of [null, [], { body: "bad" }, { body: [separator("Substantive")] }]) {
    const block = paragraph("Outer");
    block.attrs = { metadata: { wpsuiteAgentComposer: { instanceSlots: slots } } };
    assert.throws(() => normalizeRawBlocks([block]), /invalid raw block/);
  }
  const cyclic = paragraph("Cycle");
  cyclic.attrs = { metadata: { wpsuiteAgentComposer: { instanceSlots: { body: [cyclic] } } } };
  assert.throws(() => normalizeRawBlocks([cyclic]), /invalid raw block/);
});

test("normalization bounds pathological nesting and block counts without rejecting reused values", () => {
  const shared = paragraph("Shared value");
  assert.deepEqual(normalizeRawBlocks([shared, shared]), [shared, shared]);
  assert.throws(() => normalizeRawBlocks(Array(10001).fill(shared)), /invalid raw block/);
  let nested = paragraph("Leaf");
  for (let depth = 0; depth < 102; depth += 1) {
    nested = {
      blockName: "core/group", attrs: {}, innerBlocks: [nested],
      innerHTML: "<div></div>", innerContent: ["<div>", null, "</div>"],
    };
  }
  assert.throws(() => normalizeRawBlocks([nested]), /invalid raw block/);
});
