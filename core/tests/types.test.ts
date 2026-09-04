import type {
  AttachLocalizedDraftToGroupInput,
  BlueprintPayload,
  ComposerConfigBackup,
  ContentProposalDetail,
  CreateContentProposalInput,
  LocalizationProviderManifestDeclaration,
  LinkLocalizedDraftsInput,
  MergeContentProposalInput,
  RegisteredLocalizationProviderManifest,
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
  expected_revision: "123e4567-e89b-12d3-a456-426614174000"
} satisfies SubmitContentProposalInput;

const merge = {
  expected_modified_gmt: submit.expected_modified_gmt,
  expected_revision: submit.expected_revision,
  confirmation: "merge:42:41"
} satisfies MergeContentProposalInput;

declare const detail: ContentProposalDetail;
declare const backup: ComposerConfigBackup;
void [blueprint, registeredProvider, linkDrafts, attachDraft, create, submit, merge, detail.changes, backup.packages];

// @ts-expect-error Published writes only support the proposal-only gate.
const invalidBlueprint: BlueprintPayload = { page_type: "story", excerpt_policy: "optional", published_update_policy: "direct" };

// @ts-expect-error A registry result always includes the normalized provider id.
const invalidProvider: RegisteredLocalizationProviderManifest = provider;

// @ts-expect-error The explicit proposal confirmation must be true.
const invalidCreate: CreateContentProposalInput = { ...create, confirm_proposal: false };

void [invalidBlueprint, invalidProvider, invalidCreate];
