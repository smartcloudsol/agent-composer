import crypto from "node:crypto";
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

const composerRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const executionRoot = path.join(composerRoot, "src/Execution");
const files = fs.readdirSync(executionRoot)
  .filter((filename) => filename.endsWith(".php"))
  .sort();

const manifest = {
  contract: "smartcloud-agent-composer-execution",
  files: Object.fromEntries(files.map((filename) => {
    const contents = fs.readFileSync(path.join(executionRoot, filename));
    return [filename, `sha256:${crypto.createHash("sha256").update(contents).digest("hex")}`];
  })),
};

fs.writeFileSync(
  path.join(executionRoot, "execution-manifest.json"),
  `${JSON.stringify(manifest, null, 2)}\n`,
);

process.stdout.write(`Pinned ${files.length} Composer execution files.\n`);
