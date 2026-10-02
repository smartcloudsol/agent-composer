# Native Editor Governance and Theme Reconciliation

This guide defines how a human-created WordPress item enters the same governed document model as an agent-created draft, and how an operator restores consistency after changing a theme, synced pattern, Blueprint, or Structure Contract.

## One owner for each concern

Composer does not maintain a second copy of the theme. Consistency comes from stable references, validation, and fingerprints rather than bidirectional copying.

| Concern | Authoritative owner |
| --- | --- |
| CPT to page-type mapping, required composition, permissions, slots, migration routes | Active Composer Config Set |
| Shared section block tree and Pattern Override bindings | Versioned local synced `wp_block` definition |
| Instance text, media, visibility, and user-owned slot blocks | Managed post instance |
| Frontend template, `theme.json`, CSS, presets, and block styles | Active theme |

A theme template such as `templates/single-example.html` controls frontend rendering. It does not initialize or enforce the post's Gutenberg body. A post-type block template can seed editor content, but it is still only a creation-time copy and cannot establish Composer's Blueprint baseline, Structure Contract version, override manifest, or audit state. Composer therefore performs the authoritative bootstrap itself.

## WP-admin creation policy

The Site Contract stores `design_policy.admin_creation`, keyed by WordPress post type:

```json
{
  "design_policy": {
    "admin_creation": {
      "examinations": {
        "mode": "required",
        "default_page_type": "examination"
      }
    }
  }
}
```

The default page type must identify a Blueprint whose `target_post_type` is the same object key. Complete Config Set validation rejects cross-CPT mappings.

Modes:

- `off`: WordPress creates ordinary unmanaged records.
- `optional`: ordinary WordPress creation remains available. A caller may explicitly request a nonce-protected managed creation, and an existing draft may be adopted through the governed adoption workflow.
- `required`: the normal WP-admin **Add New** screen is replaced by a Composer-created auto-draft. REST and direct WordPress inserts without a matching managed baseline fail closed.

`required` creation performs one server-side operation:

1. resolve the configured default Blueprint and immutable WordPress target;
2. materialize its minimum required pattern sequence;
3. use native synced-pattern references and their canonical defaults;
4. project Structure Contract locks and extension slots;
5. validate the complete body;
6. create a human-owned `auto-draft` with managed-document metadata;
7. assign the configured content language through the active localization provider;
8. append an audit event and redirect to the ordinary Gutenberg editor.

The managed-document marker is deliberately separate from the agent-owned marker. A human-created item is governed by Composer but is not writable through agent draft abilities unless a separate, authorized assignment or adoption occurs.

If a Blueprint's required unsynced pattern contains unresolved placeholders, or its canonical synced patterns do not validate, Composer creates nothing and shows a fail-closed operator error. Repair and validate the configuration rather than falling back to a blank post.

## Native editor save boundary

For every managed document, the native Gutenberg REST save runs the same page and Structure Contract validator used by Composer abilities. Gutenberg locks provide immediate editor guidance; the server validator is the authority.

- normal content and allowed Pattern Override changes are accepted;
- user blocks remain limited to their declared extension slot;
- protected moves, removals, reparenting, and attributes are rejected;
- a user with `manage_agent_composer_structure` may perform an intentional structural change, which is recorded as drift and audited;
- a published item configured as `proposal-only` requires a separate review proposal by default;
- a Blueprint may explicitly set `native_published_edit_policy: browser-editor` so a cookie-authenticated WordPress editor with a valid REST nonce and published-content editing capability can save through Gutenberg's full Blueprint and Structure Contract validation. Composer/MCP proposal submissions remain separate and mandatory for agent updates.
- when a shared synced pattern has changed, `native_pattern_revisions` may list exact reviewed historical pattern version/hash pairs that a browser editor may re-attest on save. Unknown revisions fail closed; the accepted refresh is audited and cannot change authored overrides or skip structural validation.

Existing unmanaged records are not silently adopted. They remain legacy content until an operator previews and confirms adoption or migration.

## Reconciliation workflow

There is no silent two-way synchronization. Use the following sequence after any privileged change:

```text
Change source
  -> Rescan site
  -> Compare discovered capabilities and active references
  -> Clone the active Config Set when policy or identity changed
  -> Update versions, hashes, mappings, or migration routes
  -> Validate the complete candidate set
  -> Activate with a fresh receipt
  -> Re-evaluate managed documents
  -> Preview and create proposals where migration is required
```

### Presentation-only theme change

Examples: CSS, `theme.json` values, preset values, block styles, or a frontend template change that leaves the body contract intact.

1. Deploy the theme change.
2. Choose **Theme & providers → Rescan site**.
3. Compare the new capability fingerprint and registered resources.
4. Revalidate a cloned Config Set only when declared capabilities changed.
5. No post-content migration is required when all referenced resources and semantic contracts remain compatible.

### Synced pattern internal layout change

When stable semantic field IDs, block types, and Pattern Override bindings remain compatible, treat the change as a new pattern revision:

1. prepare and review the new synced-pattern definition;
2. preserve its stable machine slug and increment its declared version;
3. rescan and validate the candidate Config Set against the local `wp_block` record;
4. preview the affected content types and activate deliberately;
5. verify that representative instance overrides and extension-slot content survive.

The synced reference distributes the internal layout change without copying markup into every post. Instance values remain in native Pattern Overrides.

When semantic IDs, override attributes, section order, or slot boundaries change, create a new Blueprint or Structure Contract version and an explicit migration route. Do not disguise a schema change as a presentation-only pattern edit.

### Direct synced-pattern edit

Restrict pattern editing to users with structural authority. A changed local reference, missing `wp_block`, missing override binding, or incompatible block type must fail runtime validation. The safe choices are:

- restore the accepted pattern revision; or
- adopt the edit as a new version in a cloned Config Set, validate it, activate it, and migrate affected documents when required.

Never overwrite stored pattern hashes, document baselines, or status meta to make the warning disappear.

### Composer configuration change

Active Config Sets are immutable. Clone the active set, make the change, validate the complete set against the current theme and providers, then activate with a fresh receipt. Existing documents retain their exact old baseline and become `MIGRATION_REQUIRED`, `REBASE_REQUIRED`, `OVERRIDE_CONFLICT`, or another explicit status when they cannot remain current safely.

### Emergency rollback

Rollback must restore one coherent combination:

1. restore the previous theme or synced-pattern revision when that resource caused the mismatch;
2. restore the previously active Config Set through the supported rollback action;
3. rescan site capabilities;
4. validate representative managed documents;
5. inspect the audit tail and migration/proposal state before resuming creation.

Do not repair an incident by editing Composer post meta, the active-set option, local synced-pattern references, or pattern IDs directly in the database.

## Change matrix

| Change | Config action | Existing-content action |
| --- | --- | --- |
| CSS, theme presets, compatible frontend template | Rescan; revalidate only if capabilities changed | None |
| Compatible synced-pattern layout revision | Update pattern version/hash in cloned set; validate and activate | Representative override check; normally no per-post rewrite |
| Pattern semantic field or binding change | New pattern and Blueprint/contract version | Preview migration and create proposals |
| Required section order or composition change | New Blueprint version and migration route | Single or bulk proposal migration |
| Structure permissions or slot schema change | New Structure Contract version and migration route | Rebase overrides; review conflicts |
| Direct privileged post structure edit | No automatic config adoption | Mark drift; restore, adopt, or migrate explicitly |
| Direct theme resource removal | Rescan and repair or rollback before activation | New writes fail closed; inspect affected documents |

## Acceptance checks for a governed CPT

Before enabling `required` on production:

1. **Add New** opens a populated managed auto-draft, not a blank body.
2. The draft has the configured template, language, Blueprint, Structure Contract, and `VALID` baseline.
3. Synced Pattern Override text remains editable after save and reload.
4. Protected blocks cannot be moved or removed by an ordinary editor.
5. Allowed extension-slot blocks can be inserted, edited, moved locally, and removed.
6. Disallowed blocks and cross-slot movement fail on save.
7. A direct REST create without a managed baseline fails.
8. A compatible central pattern revision preserves instance overrides.
9. An incompatible revision produces an explicit reconciliation or migration state.
