import assert from "node:assert/strict";
import test from "node:test";
import { build } from "esbuild";

const { outputFiles } = await build({
  entryPoints: [new URL("../blocks/src/extension-slot/adapter.ts", import.meta.url).pathname],
  bundle: true, platform: "node", format: "esm", write: false,
  plugins: [{
    name: "wordpress-boundary-shims",
    setup(builder) {
      builder.onResolve({ filter: /^@wordpress\// }, ({ path }) => ({ path, namespace: "wordpress-shim" }));
      builder.onLoad({ filter: /.*/, namespace: "wordpress-shim" }, ({ path }) => ({
        // JSON bridges isolate our adapter's traversal and contracts. Gutenberg's
        // actual HTML parse/serialize behavior is covered by the browser suite.
        contents: path.endsWith("block-serialization-default-parser") ? "export const parse = JSON.parse;" : `
          let nextId = 0;
          export const createBlock = (name, attributes = {}, innerBlocks = []) => ({
            name, attributes, innerBlocks, clientId: 'created-' + (++nextId)
          });
          export const getBlockType = () => ({});
          export const parse = () => { throw new Error("unexpected parse"); };
          const rawBlock = (block) => {
            const tag = { 'core/list': 'ul', 'core/list-item': 'li', 'core/paragraph': 'p', 'core/group': 'div' }[block.name];
            const before = tag ? '<' + tag + '>' : '';
            const after = tag ? '</' + tag + '>' : '';
            const children = block.innerBlocks.map(rawBlock);
            return { blockName: block.name, attrs: block.attributes, innerBlocks: children,
              innerHTML: before + after, innerContent: [before, ...children.map(() => null), after] };
          };
          export const serialize = (blocks) => JSON.stringify(blocks.map(rawBlock));
          export const serializeRawBlock = () => { throw new Error("unexpected serializeRawBlock"); };`,
        loader: "js",
      }));
    },
  }],
});
const { instanceOwner, storedBlocks, slotAttributes, accepts, stampBlocks, encodeBlocks, newContentBlock } = await import(`data:text/javascript;base64,${Buffer.from(outputFiles[0].text).toString("base64")}`);

const metadata = (composer) => ({ metadata: { wpsuiteAgentComposer: composer } });
const reference = (id, composer = {}) => ({
  clientId: id, name: "core/block", innerBlocks: [],
  attributes: { ref: 10, ...metadata({ patternName: "example/pattern", ...composer }) },
});
const slot = (id, slotId) => ({
  clientId: id, name: "smartcloud-agent-composer/extension-slot", innerBlocks: [], attributes: { slotId },
});
const raw = (block) => ({
  blockName: block.name, attrs: block.attributes, innerBlocks: [], innerHTML: "", innerContent: [],
});
const childIdentity = { ownership: "USER", slotId: "body", userBlockId: "user-12345678" };
const editor = (chain, { persisted = [chain[0]], preview = false } = {}) => ({
  getSettings: () => ({ isPreviewMode: preview }),
  getBlockParents: () => chain.map((block) => block.clientId),
  getBlock: (id) => chain.find((block) => block.clientId === id) ?? null,
  getBlocks: () => persisted,
});
const parentWith = (nested, slots = { body: [raw(nested)] }) => reference("root", { instanceSlots: slots });

test("root persisted references own their slots, while previews and detached shared editors do not", () => {
  const root = reference("root");
  assert.equal(instanceOwner(editor([root]), "slot"), root);
  assert.equal(instanceOwner(editor([root], { preview: true }), "slot"), null);
  assert.equal(instanceOwner(editor([root], { persisted: [] }), "slot"), null);
});

test("shared nested references never inherit ownership from a persisted outer reference", () => {
  const nested = reference("shared", childIdentity);
  const root = parentWith(nested);
  assert.equal(instanceOwner(editor([root, nested]), "slot"), null);
  assert.equal(instanceOwner(editor([root, slot("container", "different"), nested]), "slot"), null);
  assert.equal(instanceOwner(editor([reference("root"), slot("container", "body"), nested]), "slot"), null);
  const unnamed = { ...nested, attributes: { ref: 10 } };
  assert.equal(instanceOwner(editor([root, slot("container", "body"), unnamed]), "slot"), null);
});

test("nested instance references require matching persisted USER identity, pattern and reference", () => {
  const nested = reference("nested", childIdentity);
  const root = parentWith(nested);
  const chain = [root, slot("container", "body"), nested];
  assert.equal(instanceOwner(editor(chain), "slot"), nested);
  for (const changed of [
    reference("nested", { ...childIdentity, userBlockId: "user-different" }),
    reference("nested", { ...childIdentity, ownership: "BLUEPRINT" }),
    reference("nested", { ...childIdentity, patternName: "other/pattern" }),
    { ...nested, attributes: { ...nested.attributes, ref: 99 } },
  ]) {
    assert.equal(instanceOwner(editor([root, chain[1], changed]), "slot"), null);
  }
  const ambiguous = parentWith(nested, { body: [raw(nested), raw(nested)] });
  assert.equal(instanceOwner(editor([ambiguous, chain[1], nested]), "slot"), null);
});

test("patternInstanceId also proves identity and references may be wrapped in authored groups", () => {
  const nested = reference("nested", { ownership: "USER", slotId: "body", patternInstanceId: "instance-123" });
  const group = { blockName: "core/group", attrs: {}, innerBlocks: [raw(nested)], innerHTML: "<div></div>", innerContent: ["<div>", null, "</div>"] };
  const root = parentWith(nested, { body: [group] });
  assert.equal(instanceOwner(editor([root, slot("container", "body"), nested]), "slot"), nested);
});

test("legacy seeded references prove ownership from persisted patternInstanceId without USER markers", () => {
  const legacy = reference("legacy", { patternInstanceId: "legacy-seeded-instance" });
  const root = parentWith(legacy);
  const container = slot("container", "body");
  assert.equal(instanceOwner(editor([root, container, legacy]), "slot"), legacy);
  const liveStamped = reference("legacy", { ...childIdentity, patternInstanceId: "legacy-seeded-instance" });
  assert.equal(instanceOwner(editor([root, container, liveStamped]), "slot"), liveStamped);
  for (const conflict of [
    { ownership: "BLUEPRINT" }, { ownership: null }, { slotId: "different" }, { slotId: null },
  ]) {
    const changed = reference("legacy", { patternInstanceId: "legacy-seeded-instance", ...conflict });
    assert.equal(instanceOwner(editor([root, container, changed]), "slot"), null);
    assert.equal(instanceOwner(editor([parentWith(changed), container, legacy]), "slot"), null);
  }
  const missingIdentity = reference("legacy");
  assert.equal(instanceOwner(editor([parentWith(missingIdentity), container, missingIdentity]), "slot"), null);
  assert.equal(instanceOwner(editor([root, legacy]), "slot"), null);
});

test("each nesting level is traced to root storage, not mutable expanded metadata", () => {
  const deepest = reference("deepest", { ...childIdentity, slotId: "inside", userBlockId: "user-87654321" });
  const middle = reference("middle", { ...childIdentity, instanceSlots: { inside: [raw(deepest)] } });
  const root = parentWith(middle);
  const chain = [root, slot("body", "body"), middle, slot("inside", "inside"), deepest];
  assert.equal(instanceOwner(editor(chain), "slot"), deepest);
  const rootWithoutPersistedGrandchild = parentWith(reference("middle", childIdentity));
  assert.equal(instanceOwner(editor([rootWithoutPersistedGrandchild, ...chain.slice(1)]), "slot"), null);
});

test("absent slots and explicit empty overrides are valid without mutating sibling metadata", () => {
  const root = reference("root");
  assert.deepEqual(storedBlocks(root, "body"), []);
  const sibling = [raw(reference("sibling", childIdentity))];
  const source = reference("root", { instanceSlots: { sibling, body: [] }, retained: "value" });
  source.attributes.metadata.otherPlugin = { value: true };
  const before = JSON.stringify(source);
  const result = slotAttributes(source, "body", []);
  assert.deepEqual(result.metadata.wpsuiteAgentComposer.instanceSlots, { sibling, body: [] });
  assert.equal(result.metadata.wpsuiteAgentComposer.instanceSlots.sibling, sibling);
  assert.deepEqual(result.metadata.otherPlugin, { value: true });
  assert.equal(result.metadata.wpsuiteAgentComposer.retained, "value");
  assert.equal(JSON.stringify(source), before);
});

test("malformed slot maps and payloads cannot be treated as empty or overwritten", () => {
  for (const slots of [null, undefined, [], "invalid", { body: null }, { body: undefined }, { sibling: null }, { body: {} }]) {
    const root = reference("root", { instanceSlots: slots });
    assert.throws(() => storedBlocks(root, "body"));
    assert.throws(() => slotAttributes(root, "body", []));
  }
  for (const attributes of [
    { metadata: null }, { metadata: [] },
    { metadata: { wpsuiteAgentComposer: null } },
    { metadata: { wpsuiteAgentComposer: [] } },
  ]) {
    const root = { ...reference("root"), attributes };
    assert.throws(() => storedBlocks(root, "body"));
    assert.throws(() => slotAttributes(root, "body", []));
  }
  assert.throws(() => slotAttributes(reference("root"), "body", null));
});

const content = (id, name = "core/paragraph", innerBlocks = []) => ({
  clientId: id, name, attributes: {}, innerBlocks,
});
const rules = (overrides = {}) => ({
  slotId: "body", allowedBlocks: ["core/paragraph", "core/group", "core/list", "core/list-item"],
  allowedPatterns: ["example/pattern"], patternOccurrences: {}, minimum: 0, maximum: null,
  ...overrides,
});
const identityOf = (block) => block.attributes.metadata.wpsuiteAgentComposer;

test("slot cardinality counts roots, enforcing maximum and minimum without blocking progress", () => {
  const first = content("one");
  const second = content("two");
  const group = content("group", "core/group", [first, second]);
  assert.equal(accepts([group], rules({ maximum: 1 })), true);
  assert.equal(accepts([first, second], rules({ maximum: 1 })), false);
  assert.equal(accepts([], rules({ minimum: 1 })), false);
  assert.equal(accepts([], rules({ minimum: 1 }), []), true);
  assert.equal(accepts([first], rules({ minimum: 2 }), []), true);
  assert.equal(accepts([first], rules({ minimum: 2 }), [first]), true);
  assert.equal(accepts([first], rules({ minimum: 2 }), [first, second]), false);
  assert.equal(accepts([], rules({ maximum: 0 })), true);
  assert.equal(accepts([first], rules({ maximum: 0 })), false);
});

test("nested pattern occurrences enforce the same before and after bounds", () => {
  const nested = reference("nested");
  const before = [content("group", "core/group", [nested])];
  const after = [content("group", "core/group")];
  const required = rules({ patternOccurrences: { "example/pattern": { min: 1, max: 1 } } });
  assert.equal(accepts(before, required), true);
  assert.equal(accepts(after, required, before), false);
  assert.equal(accepts(after, required, after), true);
  assert.equal(accepts([content("group", "core/group", [nested, reference("extra")])], required, before), false);
  assert.equal(accepts([content("group", "core/group", [reference("bad", { patternName: "other/pattern" })])], required), false);
});

test("unapproved and invalid nested blocks cannot bypass an approved root", () => {
  assert.equal(accepts([content("group", "core/group", [content("html", "core/html")])], rules()), false);
  assert.equal(accepts([{ ...content("paragraph"), isValid: false }], rules()), false);
});

test("identity cache preserves native outgoing block identities across repeated edits", () => {
  const identities = new Map();
  const native = [content("list", "core/list", [content("item", "core/list-item")])];
  const before = JSON.stringify(native);
  const first = stampBlocks(native, "body", identities);
  const edited = [{ ...native[0], attributes: { className: "edited" } }];
  const second = stampBlocks(edited, "body", identities);
  assert.equal(identityOf(first[0]).userBlockId, identityOf(second[0]).userBlockId);
  assert.equal(identityOf(first[0].innerBlocks[0]).userBlockId, identityOf(second[0].innerBlocks[0]).userBlockId);
  assert.equal(identityOf(second[0]).ownership, "USER");
  assert.equal(identityOf(second[0].innerBlocks[0]).slotId, "body");
  assert.equal(second[0].clientId, native[0].clientId);
  assert.equal(JSON.stringify(native), before);
});

test("duplicate native identities are repaired once and then remembered", () => {
  const original = { ...content("original"), attributes: metadata(childIdentity) };
  const duplicate = { ...original, clientId: "duplicate" };
  const identities = new Map();
  const first = stampBlocks([original, duplicate], "body", identities);
  const second = stampBlocks([original, duplicate], "body", identities);
  assert.equal(identityOf(first[0]).userBlockId, childIdentity.userBlockId);
  assert.notEqual(identityOf(first[1]).userBlockId, childIdentity.userBlockId);
  assert.equal(identityOf(first[1]).userBlockId, identityOf(second[1]).userBlockId);
});

test("cross-slot paste receives a fresh identity that remains stable before live metadata catches up", () => {
  const pasted = { ...content("paste"), attributes: metadata({ ...childIdentity, slotId: "source" }) };
  const identities = new Map();
  const first = stampBlocks([pasted], "body", identities);
  const second = stampBlocks([pasted], "body", identities);
  assert.notEqual(identityOf(first[0]).userBlockId, childIdentity.userBlockId);
  assert.equal(identityOf(first[0]).userBlockId, identityOf(second[0]).userBlockId);
  assert.equal(identityOf(second[0]).slotId, "body");
});

test("list creation seeds only an allowed list item and encoding preserves its nesting", () => {
  const list = newContentBlock("core/list", rules());
  assert.equal(list.innerBlocks.length, 1);
  assert.equal(list.innerBlocks[0].name, "core/list-item");
  assert.equal(accepts([list], rules({ maximum: 1 })), true);
  const encoded = encodeBlocks([list], "body");
  assert.equal(encoded.length, 1);
  assert.equal(encoded[0].blockName, "core/list");
  assert.equal(encoded[0].innerBlocks[0].blockName, "core/list-item");
  assert.deepEqual(encoded[0].innerContent, ["<ul>", null, "</ul>"]);
  assert.equal(encoded[0].innerBlocks[0].attrs.metadata.wpsuiteAgentComposer.userBlockId,
    identityOf(list.innerBlocks[0]).userBlockId);
  const noItem = newContentBlock("core/list", rules({ allowedBlocks: ["core/list"] }));
  assert.deepEqual(noItem.innerBlocks, []);
  assert.equal(accepts([list], rules({ allowedBlocks: ["core/list"] })), false);
});
