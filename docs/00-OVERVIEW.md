# 00 - Product Overview

**WP ACF JSON Pro** · v1.0 · WordPress 6.5+ · PHP 8.1+ · ACF 6.2+ (Free & Pro) · GPL-2.0-or-later

---

## 1. The problem

Advanced Custom Fields is the de-facto standard for structured content in WordPress, and
its authoring experience has not changed in a decade: you click through a UI, one field at
a time. A thirty-field group with two repeaters and a flexible-content builder is twenty
minutes of clicking, and modifying it later means finding the right row among dozens of
collapsed accordions.

ACF ships two escape hatches and both are blunt:

- **Tools → Import** takes a full field-group export and replaces the group wholesale.
  `acf_import_field_group()` deletes every field not present in the payload. It cannot
  express "add one field" or "make this one required."
- **Local JSON** (`acf-json/`) is a sync mechanism, not an editing one. It is
  whole-file, whole-group, and it has no notion of intent.

Meanwhile every developer now has an LLM that can write JSON perfectly well. The missing
piece is not generation. It is **safe application**.

## 2. The product

> A JSON-driven patch engine for ACF: describe the change you want, see exactly what will
> happen, apply only that, and roll back if you were wrong.

The single sentence that defines correctness:

> **Nothing changes except what the JSON asked for.**

## 3. The pipeline

Every entry point - admin paste box, file upload, REST call, WP-CLI - funnels into one
pipeline. There are no side doors.

```
        ┌──────────────┐
        │   Raw JSON   │  paste · upload · REST · CLI
        └──────┬───────┘
               ▼
        ┌──────────────┐   syntax, encoding, size
        │    Parser    │   → array
        └──────┬───────┘
               ▼
        ┌──────────────┐   schema, operations, field types,
        │  Validator   │   duplicates, nesting depth, cycles
        └──────┬───────┘
               ▼
        ┌──────────────┐   dialect detection, key aliasing,
        │  Normalizer  │   value coercion → ImportPayload
        └──────┬───────┘
               ▼
        ┌──────────────┐   read live ACF, resolve targets
        │   Resolver   │   (key → name → path → label)
        └──────┬───────┘
               ▼
        ┌──────────────┐   recursive tree comparison
        │  Comparator  │   → ChangeSet (+ Conflicts)
        └──────┬───────┘
               ▼
        ┌──────────────┐   human diff / machine JSON
        │   Preview    │   nothing written yet
        └──────┬───────┘
               ▼
        ┌──────────────┐   snapshot → apply → verify
        │    Apply     │   → history row
        └──────┬───────┘
               ▼
        ┌──────────────┐
        │     ACF      │
        └──────────────┘
```

**Phases 1-6 of this pipeline are pure functions over read-only state.** Only `Apply`
writes. That separation is what makes preview trustworthy, rollback possible, and the
whole engine unit-testable without a database.

## 4. Scope - v1.0

### In

| Capability | Notes |
|---|---|
| Operations | `create`, `add`, `update`, `delete`, `merge`, `sync`, `replace`, `move` |
| Targeting | by field key, field name, label, or human path (`["Contact","Social"]`) |
| Nesting | Group, Repeater, Flexible Content (layouts), Clone - unlimited depth |
| Diff | add / update / delete / move / rename / reorder / group-setting changes |
| Conflicts | type mismatch, name change with data, destructive delete, ambiguous target |
| Preview | mandatory before every mutating operation |
| Snapshots | automatic, per batch, full native-ACF fidelity |
| Rollback | one click, restores the exact prior export |
| History | table-backed, per-batch, with the stored ChangeSet |
| Native ACF JSON | read and write ACF's own export format both directions |
| Export | whole group, selected subtree, in either dialect |
| REST API | full parity with the UI, nonce + capability guarded |
| WP-CLI | `wp acfjp validate|plan|apply|export|history|rollback` |
| AI prompt generator | emits a ready-to-paste prompt describing our schema |
| Audit log | who applied what, when, from where |

### Out - do not build these in v1

AI API calls from the plugin · licence keys · cloud account or sync · block generation ·
page-builder integrations · multisite network apply · ACF 6.1 post-type / taxonomy /
options-page registration · field-level permissions · scheduled imports · the
"automatically interpret arbitrary unstructured JSON" idea.

Each of these is a reasonable v2 conversation. None of them makes the core engine more
reliable, and an unreliable core engine kills the product.

## 5. Why AI stays outside the plugin

The plugin never calls an LLM. It publishes a schema and a prompt generator; the developer
uses whatever model they already pay for.

```
ChatGPT ─┐
Claude ──┤
Gemini ──┼──▶  WP ACF JSON Pro schema  ──▶  JSON  ──▶  Plugin
Cursor ──┤
by hand ─┘
```

This is a product decision, not a technical shortcut. It means: no API keys, no per-seat
model costs, no data leaving the site, no vendor lock, no degradation when a provider
changes its pricing - and the plugin is fully functional with zero configuration. The AI
story is "works with your AI", which is stronger than "has an AI built in."

## 6. Competitive position

| | ACF core | ACF Extended | WP ACF JSON Pro |
|---|---|---|---|
| Whole-group import | ✔ | ✔ | ✔ |
| Partial field update | ✘ | ✘ | ✔ |
| Add into an existing group | ✘ | limited | ✔ |
| Path targeting into nested fields | ✘ | ✘ | ✔ |
| Diff preview before apply | ✘ | ✘ | ✔ |
| Conflict resolution UI | ✘ | ✘ | ✔ |
| Snapshot + rollback | ✘ | ✘ | ✔ |
| Dialect-tolerant JSON (AI output) | ✘ | ✘ | ✔ |
| Data-loss detection | ✘ | ✘ | ✔ |
| REST + CLI parity | ✘ | partial | ✔ |

The defensible features are the middle block: **diff, conflicts, rollback**. Everything
else is table stakes or convenience.

## 7. Success criteria for v1.0

The release ships when all seven are demonstrably true.

1. A developer pastes AI-generated JSON and the plugin accepts it without hand-fixing.
2. It resolves a target inside an existing group by human-readable path.
3. It updates only the named settings on the named fields.
4. It adds fields at an arbitrary nesting depth, including inside a flexible layout.
5. Every change is shown in a diff before anything is written.
6. Applying leaves the rest of the field group byte-identical to its prior export.
7. One click restores the prior state exactly.

### The canonical acceptance scenario

Existing ACF:

```
Property  (group_prop)
└── Agent            (group field)
    ├── Name         text
    ├── Phone        text
    └── Email        email     required: false
                               instructions: "Work address only"
```

Input:

```json
{
  "version": "1.0",
  "operation": "update",
  "target": { "field_group": "Property", "path": ["Agent"] },
  "changes": { "email": { "required": true } },
  "add": [ { "name": "whatsapp", "label": "WhatsApp", "type": "text" } ]
}
```

Preview:

```
Property › Agent                                        2 changes

  ~ Email                                                  safe
      required          false → true

  + WhatsApp                                                safe
      type              text
      position          after Email

                                      [ Cancel ]  [ Apply 2 changes ]
```

After applying, `acf_prepare_field_group_for_export(group_prop)` must differ from the
pre-apply export in exactly two places: `Email.required` flipped to `1`, and one appended
field object. `Email.instructions` is still `"Work address only"`. Every `key` on every
pre-existing field is unchanged. That assertion is the v1 regression test.
