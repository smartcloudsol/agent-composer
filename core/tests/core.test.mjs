import assert from "node:assert/strict";
import { spawnSync } from "node:child_process";
import { cpSync, mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from "node:fs";
import { tmpdir } from "node:os";
import { dirname, join } from "node:path";
import { fileURLToPath } from "node:url";
import { createRequire } from "node:module";
import test from "node:test";
import * as core from "../dist/index.js";

const require = createRequire(import.meta.url);
const coreRoot = join(dirname(fileURLToPath(import.meta.url)), "..");

test("core exposes only shared runtime constants", () => {
  assert.equal(core.CONTRACT_VERSION, "1.0.0-rc.1");
  assert.equal(core.LOCALIZATION_PROVIDER_CONTRACT_VERSION, "1.0.0");
  assert.equal(core.ENTITY_TYPES.length, 7);
  assert.deepEqual(core.EXCERPT_POLICIES, ["required", "optional", "disabled"]);
  assert.deepEqual(core.PUBLISHED_UPDATE_POLICIES, ["disabled", "proposal-only"]);
  assert.deepEqual(core.CONTENT_PROPOSAL_STATES, [
    "working",
    "ready-for-review",
    "merged",
    "rejected",
    "superseded",
  ]);
  assert.deepEqual(core.LOCALIZATION_PROVIDER_OPERATIONS, [
    "get-localization-capabilities",
    "list-content-languages",
    "resolve-localized-content",
    "preview-localized-proposal",
    "validate-localized-proposal",
    "assign-draft-language",
    "link-draft-translations",
    "attach-draft-to-translation-group",
  ]);
});

test("core exports no HTTP client or application store", () => {
  assert.equal("ComposerApiClient" in core, false);
  assert.equal("createComposerStore" in core, false);
  assert.equal("validateConfigPackage" in core, false);
});

test("published declarations compile for a clean NodeNext consumer", () => {
  const fixtureRoot = mkdtempSync(join(tmpdir(), "agent-composer-core-nodenext-"));
  const packageRoot = join(fixtureRoot, "node_modules", "@smart-cloud", "agent-composer-core");

  try {
    mkdirSync(packageRoot, { recursive: true });
    cpSync(join(coreRoot, "dist"), join(packageRoot, "dist"), { recursive: true });
    cpSync(join(coreRoot, "package.json"), join(packageRoot, "package.json"));
    writeFileSync(
      join(fixtureRoot, "index.ts"),
      `import { CONTRACT_VERSION } from "@smart-cloud/agent-composer-core";\n` +
        `import type { CreateContentProposalInput, GetRenderedPreviewInput, RenderedPreviewDocument, RenderedPreviewResult, SiteContractDesignPolicy } from "@smart-cloud/agent-composer-core";\n` +
        `const input = {} as CreateContentProposalInput;\n` +
        `const previewInput = { post_id: 42, expected_modified_gmt: "2026-09-10T10:00:00Z", expected_revision: "123e4567-e89b-12d3-a456-426614174000" } satisfies GetRenderedPreviewInput;\n` +
        `const previewDocument = {} as RenderedPreviewDocument;\n` +
        `const previewResult = {} as RenderedPreviewResult;\n` +
        `const policy = {} as SiteContractDesignPolicy;\n` +
        `void [CONTRACT_VERSION, input, previewInput, previewDocument, previewResult, policy];\n`,
    );
    writeFileSync(
      join(fixtureRoot, "tsconfig.json"),
      JSON.stringify(
        {
          compilerOptions: {
            module: "NodeNext",
            moduleResolution: "NodeNext",
            noEmit: true,
            strict: true,
            skipLibCheck: false,
          },
          include: ["index.ts"],
        },
        null,
        2,
      ),
    );

    const result = spawnSync(process.execPath, [require.resolve("typescript/lib/tsc.js"), "-p", fixtureRoot], {
      cwd: fixtureRoot,
      encoding: "utf8",
    });

    assert.equal(result.status, 0, `${result.stdout}${result.stderr}`);
    const declarationIndex = readFileSync(join(packageRoot, "dist", "index.d.ts"), "utf8");
    const declarationTypes = readFileSync(join(packageRoot, "dist", "types.d.ts"), "utf8");
    assert.match(declarationIndex, /from "\.\/constants\.js"/);
    assert.match(declarationIndex, /GetRenderedPreviewInput/);
    assert.match(declarationIndex, /RenderedPreviewAsset/);
    assert.match(declarationIndex, /RenderedPreviewWarning/);
    assert.match(declarationIndex, /RenderedPreviewDocument/);
    assert.match(declarationIndex, /RenderedPreviewResult/);
    assert.match(declarationTypes, /contract_version: "3"/);
    assert.match(declarationTypes, /fidelity: "static"/);
    assert.match(declarationTypes, /source_format: "rendered-html"/);
    assert.match(declarationTypes, /rendered_preview_token: string/);
    assert.match(declarationTypes, /mime_type: "text\/html"/);
  } finally {
    rmSync(fixtureRoot, { recursive: true, force: true });
  }
});
