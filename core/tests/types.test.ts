import type {
  AttachLocalizedDraftToGroupInput,
  BlueprintPayload,
  ComposerConfigBackup,
  ContentProposalDetail,
  CreateContentProposalInput,
  GetRenderedPreviewInput,
  LocalizationProviderManifestDeclaration,
  LinkLocalizedDraftsInput,
  MergeContentProposalInput,
  RegisteredLocalizationProviderManifest,
  RenderedPreviewDocument,
  RenderedPreviewResult,
  SubmitContentProposalInput
} from "../src/index";

const blueprint = {
  page_type: "story",
  target_post_type: "post",
  excerpt_policy: "optional",
  published_update_policy: "proposal-only",
  content_language: "hu-HU",
  allowed_content_languages: ["hu-HU", "en-US"]
} satisfies BlueprintPayload;

const provider = {
  label: "WPML",
  ability_namespace: "smartcloud-agent-composer-wpml",
  contract_version: "1.0.0",
  plugin_version: "4.7.0",
  storage_model: "separate-posts",
  active: true,
  ability_names: [],
  mcp_ability_names: []
} satisfies LocalizationProviderManifestDeclaration;

const registeredProvider = { ...provider, id: "wpml" } satisfies RegisteredLocalizationProviderManifest;

const linkDrafts = {
  page_type: "story",
  confirm_link: true,
  drafts: [
    {
      post_id: 42,
      content_language: "hu-HU",
      expected_modified_gmt: "2026-09-02T10:01:00Z",
      expected_revision: "123e4567-e89b-12d3-a456-426614174000"
    },
    {
      post_id: 43,
      content_language: "en-US",
      expected_modified_gmt: "2026-09-02T10:02:00Z",
      expected_revision: "123e4567-e89b-12d3-a456-426614174001"
    }
  ]
} satisfies LinkLocalizedDraftsInput;

const attachDraft = {
  page_type: "story",
  anchor_post_id: 41,
  expected_localization_group: "post:group-fingerprint",
  draft: linkDrafts.drafts[0],
  confirm_attach: true
} satisfies AttachLocalizedDraftToGroupInput;

const create = {
  post_id: 41,
  page_type: "story",
  content_language: "hu-HU",
  expected_modified_gmt: "2026-09-02T10:00:00Z",
  expected_content_hash: "a".repeat(64),
  idempotency_key: "proposal:41:v1",
  confirm_proposal: true
} satisfies CreateContentProposalInput;

const submit = {
  post_id: 42,
  expected_modified_gmt: "2026-09-02T10:01:00Z",
  expected_revision: "123e4567-e89b-12d3-a456-426614174000",
  rendered_preview_token: `pv1.${"1".repeat(10)}.${"a".repeat(43)}`
} satisfies SubmitContentProposalInput;

const merge = {
  expected_modified_gmt: submit.expected_modified_gmt,
  expected_revision: submit.expected_revision,
  confirmation: "merge:42:41"
} satisfies MergeContentProposalInput;

const renderedPreviewInput = {
  post_id: 42,
  expected_modified_gmt: submit.expected_modified_gmt,
  expected_revision: submit.expected_revision
} satisfies GetRenderedPreviewInput;

const renderedPreviewDocument = {
  contract_version: "3",
  post_id: 42,
  modified_gmt: submit.expected_modified_gmt,
  revision: submit.expected_revision,
  title: "Rendered proposal",
  content_language: "hu-HU",
  direction: "ltr",
  scope: "content",
  fidelity: "static",
  source_format: "rendered-html",
  mime_type: "text/html",
  html: "<!doctype html><html lang=\"hu\"><body>Preview</body></html>",
  sha256: "a".repeat(64),
  byte_length: 61,
  assets: [
    {
      asset_id: `pa_${"a".repeat(43)}`,
      kind: "stylesheet",
      mime_type: "text/css",
      byte_length: 42,
      sha256: "a".repeat(64),
      handle: "site",
      media: "all",
      order: 0
    },
    {
      asset_id: `pa_${"b".repeat(43)}`,
      kind: "font",
      mime_type: "font/woff2",
      byte_length: 32,
      sha256: "b".repeat(64)
    }
  ],
  warnings: [
    {
      code: "interactive-block-omitted",
      message: "Interactive behavior is unavailable in the static preview.",
      block_name: "smartcloud/example"
    }
  ]
} satisfies RenderedPreviewDocument;

const renderedPreview = {
  post_id: 42,
  edit_url: "https://example.com/wp-admin/post.php?post=42&action=edit",
  preview_url: "https://example.com/?p=42&preview=true",
  validation: {
    valid: true,
    errors: [],
    warnings: [],
    statistics: {
      word_count: 1,
      block_count: 1,
      h1_count: 0,
      patterns: [],
      block_names: ["core/paragraph"]
    },
    composition_mode: "document",
    content_language: "hu-HU"
  },
  rendered_preview_token: submit.rendered_preview_token,
  document: renderedPreviewDocument
} satisfies RenderedPreviewResult;

declare const detail: ContentProposalDetail;
declare const backup: ComposerConfigBackup;
void [
  blueprint,
  registeredProvider,
  linkDrafts,
  attachDraft,
  create,
  submit,
  merge,
  renderedPreviewInput,
  renderedPreviewDocument,
  renderedPreview.document.html,
  detail.changes,
  backup.packages
];

// @ts-expect-error Published writes only support the proposal-only gate.
const invalidBlueprint: BlueprintPayload = { page_type: "story", excerpt_policy: "optional", published_update_policy: "direct" };

// @ts-expect-error A registry result always includes the normalized provider id.
const invalidProvider: RegisteredLocalizationProviderManifest = provider;

// @ts-expect-error The explicit proposal confirmation must be true.
const invalidCreate: CreateContentProposalInput = { ...create, confirm_proposal: false };

// @ts-expect-error Rendered previews are always inert static snapshots.
const invalidRenderedPreview: RenderedPreviewDocument = { ...renderedPreviewDocument, fidelity: "interactive" };

void [invalidBlueprint, invalidProvider, invalidCreate, invalidRenderedPreview];
