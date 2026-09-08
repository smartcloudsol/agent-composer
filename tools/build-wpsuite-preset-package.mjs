import crypto from "node:crypto";
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const presetRoot = path.join(root, "presets", "wpsuite");
const packagePath = path.join(presetRoot, "wpsuite-site-contract.package.json");

const readJson = (filename) => JSON.parse(fs.readFileSync(filename, "utf8"));
const sortCanonical = (value) => {
  if (Array.isArray(value)) return value.map(sortCanonical);
  if (value === null || typeof value !== "object") return value;
  return Object.fromEntries(
    Object.keys(value).sort().map((key) => [key, sortCanonical(value[key])])
  );
};
const checksum = (value) => `sha256:${crypto.createHash("sha256")
  .update(JSON.stringify(sortCanonical(value)))
  .digest("hex")}`;

const previous = readJson(packagePath);
const pageTypes = readJson(path.join(presetRoot, "page-types.json"));
const siteContract = readJson(path.join(presetRoot, "site-contract.json"));
const configSet = previous.entities.find((entity) => entity.type === "config-set");

if (!configSet) throw new Error("The WP Suite package has no Config Set entity template.");
const themeSlug = configSet.payload?.source_theme?.slug;
const baselinePrefix = `${themeSlug}-`;
if (!themeSlug || !pageTypes.theme_baseline?.startsWith(baselinePrefix)) {
  throw new Error("The page-type theme baseline must match the Config Set theme slug.");
}
configSet.payload.source_theme.version = pageTypes.theme_baseline.slice(baselinePrefix.length);

const blueprintEntities = pageTypes.blueprints
  .map(({ id }) => ({ id, type: "blueprint", payload: readJson(path.join(presetRoot, "blueprints", `${id}.json`)) }))
  .sort((left, right) => left.id.localeCompare(right.id))
  .map((entity) => ({ ...entity, checksum: checksum(entity.payload) }));

const entities = [
  ...blueprintEntities,
  { ...configSet, checksum: checksum(configSet.payload) },
  { id: "contract:site", type: "site-contract", payload: siteContract, checksum: checksum(siteContract) },
];

const output = {
  schema_version: previous.schema_version,
  activation: previous.activation,
  package: previous.package,
  entities,
  checksums: { entities: checksum(entities) },
};

fs.writeFileSync(packagePath, `${JSON.stringify(output, null, 2)}\n`);
process.stdout.write(`Built ${path.relative(root, packagePath)} with ${blueprintEntities.length} Blueprints.\n`);
