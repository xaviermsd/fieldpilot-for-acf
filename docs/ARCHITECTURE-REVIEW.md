# Architecture Review - WP ACF JSON Pro

**Reviewer:** lead architect
**Baseline:** *WP ACF JSON Pro - Full Developer Technical Plan v1.0* (PDF)
**Verified against:** Advanced Custom Fields **6.8.10** (current release), source read directly
**Date:** 2026-09-21
**Status:** approved with substantive changes

---

## 0. How this review was done

I did not take the plan's API assumptions on trust, and I did not take my own on trust
either. I downloaded ACF 6.8.10 from the plugin repository and read the relevant source:

```
includes/acf-field-functions.php
includes/acf-field-group-functions.php
includes/acf-internal-post-type-functions.php
includes/class-acf-internal-post-type.php
includes/post-types/class-acf-field-group.php
includes/fields/class-acf-field-group.php
includes/local-json.php
includes/local-fields.php
includes/acf-value-functions.php
includes/fields.php
schemas/fields/v1/*.json
```

**Update (same day):** ACF PRO 6.8.10 was subsequently supplied and read as well -
`pro/fields/class-acf-field-{repeater,flexible-content,clone,gallery}.php`. **Every
previously unverified claim is now resolved.** Three were confirmed as designed, and the
Clone investigation turned up a fourth read-path hazard serious enough to get its own
section (§B.8). All claims below are **[verified]** against source.

The headline result: **the plan's product vision is right and should be built more or less
as described. Its technical substrate is wrong in six places, two of which would corrupt
customer data.**

---

## A. Proposed architecture

The pipeline stays. The plan's central insight - that this is a patch engine, not an
importer, and that parse / resolve / diff / apply must be separate - is correct and is the
reason the product is defensible. I am keeping it.

```
                     ┌──────────────────────────────────────────┐
  ingress            │  Admin screen · REST · WP-CLI            │
                     └────────────────────┬─────────────────────┘
                                          ▼
  ┌───────────────────────────────────────────────────────────────────────┐
  │  PURE ZONE  -  no writes, no global state, unit-testable without WP   │
  │                                                                       │
  │   Parser ──▶ Validator ──▶ Normalizer ──▶ Model                       │
  │                  ▲                                                    │
  │                  │ field-type schemas (from ACF, see §B.1)            │
  │                                                                       │
  │   Model + CurrentTree ──▶ Resolver ──▶ Comparator ──▶ ChangeSet       │
  └───────────────────────────────────┬───────────────────────────────────┘
                                      │  ChangeSet is serialisable,
                                      │  hashable, and the ONLY thing
                                      │  that crosses this line
  ┌───────────────────────────────────▼───────────────────────────────────┐
  │  EFFECT ZONE  -  the only code permitted to write                     │
  │                                                                       │
  │   Guard (mutability + capability + concurrency)                       │
  │        ▼                                                              │
  │   Snapshot ──▶ Planner ──▶ Writer ──▶ Verifier ──▶ Journal            │
  │                              │            │                          │
  │                              │            └─▶ mismatch ─▶ Restore     │
  │                              ▼                                        │
  │                        ACF public API                                 │
  └───────────────────────────────────────────────────────────────────────┘
```

Two changes of substance versus the plan's diagram:

1. **A hard pure/effect boundary, not a soft one.** The plan has the same stages but no
   rule enforcing the split. I am making it structural: nothing in `JSON\`, `Model\`,
   `Resolve\` or `Diff\` may call a function that writes, and `Diff\` may not call ACF at
   all - it receives an already-materialised tree. This is what makes "preview is
   trustworthy" a provable property rather than an aspiration, and it means the diff
   engine is testable with fixtures and zero WordPress bootstrap.

2. **A `Guard` stage the plan does not have.** Before any snapshot is taken, the engine
   must establish that the target is *mutable at all*. As §B.2 explains, a large fraction
   of real ACF installations have field groups that physically cannot be patched. The plan
   would discover this halfway through a write. The Guard discovers it before the preview
   is even rendered.

### The layers

| Namespace | Responsibility | Writes? | Knows about WP? |
|---|---|---|---|
| `ACFJP\Core` | bootstrap, container, requirements, autoload | - | yes |
| `ACFJP\Json` | parse, validate, normalize | no | no |
| `ACFJP\Model` | `FieldGroup`, `Field`, `Layout`, `Tree`, `TargetPath` | no | no |
| `ACFJP\Acf` | read ACF → `Tree`; classify mutability; write `Tree` → ACF | Writer only | yes |
| `ACFJP\Resolve` | turn a `Target` into a concrete node of a `Tree` | no | no |
| `ACFJP\Diff` | `Tree` × `Tree` → `ChangeSet` | no | no |
| `ACFJP\Apply` | guard, snapshot, plan, write, verify, restore | yes | yes |
| `ACFJP\Journal` | history + audit persistence | yes (own tables) | yes |
| `ACFJP\Rest` | HTTP surface | delegates | yes |
| `ACFJP\Cli` | WP-CLI surface | delegates | yes |
| `ACFJP\Admin` | screens, assets, server-rendered shell | no | yes |

---

## B. Changes from the provided plan

Six substantive changes. Each states the plan's position, the problem, the evidence, and
the replacement.

---

### B.1 - Do not hand-write a field-type registry. Consume ACF's shipped JSON Schemas.

**Plan says** (§17): build a Field Type Registry enumerating ~25 types, each with its own
validation rules, normalization behaviour and container semantics, hand-maintained by us.

**Problem.** That table is a maintenance liability with a guaranteed decay curve. ACF has
36 field types today; each has between 8 and 40 settings; settings are added and renamed
across ACF minor versions. A hand-written registry is wrong the day ACF 6.9 ships, and
wrong in the worst possible way - it will reject valid JSON, or worse, silently accept an
invalid setting and write it into a field.

**Evidence.** ACF 6.8.0 added a public API for exactly this:

```php
// includes/acf-field-functions.php:1664   [verified]
function acf_get_field_json_schema( string $field_type ): array
```

It loads `ACF_PATH/schemas/fields/v1/<type>.json` - 36 draft-07 JSON Schema documents
shipped inside the plugin, cached in the `field-type-schemas` store. They are strict
(`"additionalProperties": false`), they carry `pattern` constraints for `name` and `key`,
types, defaults, minimums and human descriptions. `text.json` alone specifies
`label, type, name, key, instructions, required, conditional_logic, wrapper,
default_value, placeholder, prepend, append, maxlength, allow_in_bindings` with
`required: ["label","type"]`. **[verified]**

Notably the *free* plugin ships `repeater.json`, `flexible_content.json`, `clone.json` and
`gallery.json` even though those field classes are PRO-only. So we can validate PRO-shaped
JSON on a free install and return a precise "this needs ACF PRO" error instead of a
confusing "unknown field type."

**Replacement.** `ACFJP\Json\FieldTypeSchemas` is a thin provider:

```php
final class FieldTypeSchemas
{
    /** @return array<string,mixed> Empty array when ACF ships no schema for $type. */
    public function for(string $type): array;

    /** Types ACF can actually instantiate on this install. */
    public function installedTypes(): array;   // acf_get_field_types()

    /** Types ACF has a schema for but cannot instantiate here (⇒ needs PRO). */
    public function knownButUnavailable(): array;
}
```

We keep a small **capability overlay** - our own table of perhaps 40 lines - recording only
what ACF's schemas do not express and our engine needs: which types are containers
(`group`, `repeater` → `sub_fields`; `flexible_content` → `layouts[].sub_fields`), which
are structural-only (`tab`, `accordion`, `message`, `separator` - they have no `name` and
hold no data, which matters for diffing and for delete-safety), and which are references
rather than storage (`clone`). That overlay is stable across ACF versions because it
encodes *our* semantics, not ACF's settings.

Third-party field types get a filter, `acfjp/field_type/schema`, so an add-on can supply a
schema for a type ACF has none for. Unknown types with no schema are **accepted
structurally but validated loosely**, with a warning in the preview - refusing them
outright would break every site using a third-party field, which is not an acceptable
failure mode for a tool whose promise is safety.

**Impact.** Removes the largest hand-maintained surface in the plan. Validation accuracy
now tracks ACF automatically. One new provider class; the registry disappears.

---

### B.2 - The plan has no concept of an immutable field group. This is a data-corruption bug.

This is the most important finding in the review.

**Plan says** - nothing. It assumes every field group is patchable.

**Problem.** In real installations, ACF field groups come from three different places and
only one of them can be written to:

| Source | How it exists | Patchable? |
|---|---|---|
| Database | `acf-field-group` / `acf-field` posts | **yes** |
| Local JSON | `acf-json/*.json`, auto-loaded, not yet "synced" into the DB | **no** |
| PHP registration | `acf_add_local_field_group()` in a theme or plugin | **no** |

**Evidence.** `acf_get_field()` checks local fields *before* the database:

```php
// includes/acf-field-functions.php:29   [verified]
if ( acf_is_local_field( $id ) ) {
    $field = acf_get_local_field( $id );
} else {
    $field = acf_get_raw_field( $id );
}
```

A local field has **no `ID`**. Now look at what `acf_update_field()` does with that:

```php
// includes/acf-field-functions.php:1004   [verified]
if ( $field['ID'] ) {
    wp_update_post( $save );
} else {
    $field['ID'] = wp_insert_post( $save );   // ← silently creates a NEW post
}
```

So if the plan's Update operation is handed a PHP-registered or unsynced-JSON field, it
does not fail. It **inserts a duplicate `acf-field` post** that is not attached to any
field group the user can see, while the PHP registration continues to win at runtime. The
user's change appears to succeed, does nothing, and leaves orphaned rows behind. On the
next ACF JSON sync, behaviour becomes genuinely unpredictable. There is no error message
anywhere in that sequence.

This is not an edge case. Registering field groups in PHP is standard practice at every
WordPress agency, and `acf-json` sync is ACF's own recommended version-control workflow.

**Replacement.** A mandatory `ACFJP\Acf\MutabilityClassifier`, run in the Guard stage
before anything else:

```php
enum Mutability: string {
    case Database   = 'database';    // patchable
    case LocalJson  = 'local_json';  // importable to DB first, then patchable
    case LocalPhp   = 'local_php';   // not patchable, ever
}

final class MutabilityClassifier
{
    public function classify(string $groupKey): MutabilityReport;
}
```

Behaviour per class:

- **Database** - proceed normally.
- **LocalJson** - refuse to patch, but offer the one-click resolution ACF itself offers:
  sync the group into the database (`acf_import_field_group()` on the JSON payload), then
  re-run the plan. We surface this as an actionable button, not an error string. Because
  ACF's local JSON writer hooks `acf/update_field_group` **[verified,
  `includes/local-json.php:51`]**, the file is rewritten afterwards and the developer's
  repo stays authoritative - the round trip is clean.
- **LocalPhp** - refuse, and be useful about it: we know the incoming JSON and the current
  tree, so we generate the **PHP snippet** representing the requested change and tell the
  developer where the group was registered from. ACF exposes
  `acf_export_internal_post_type_as_php()` **[verified,
  `includes/acf-internal-post-type-functions.php:400`]**, which we reuse rather than
  writing our own PHP emitter.

The mutability class is also shown on the Dashboard next to every field group, so the
developer learns which parts of their site this tool can touch before they try.

**Impact.** New classifier class, a new Guard stage, one new preview state, three new
error codes. This is perhaps two days of work and it is the difference between a tool
agencies trust and a tool that silently corrupts their config once and is uninstalled.

---

### B.3 - Read raw for writing, read filtered for diffing. The plan conflates them.

**Plan says** (§12): build an in-memory tree from `acf_get_fields()` and mutate from it.

**Problem.** `acf_get_field()` applies the `acf/load_field` filter chain **[verified,
`acf-field-functions.php:29`]**. That chain is not cosmetic - it *synthesises* data:

```php
// includes/fields/class-acf-field-group.php:61   [verified]
function load_field( $field ) {
    $sub_fields = acf_get_fields( $field );
    if ( $sub_fields ) {
        $field['sub_fields'] = $sub_fields;   // ← injected at read time
    }
    return $field;
}
```

The stored `post_content` for a Group field contains **no** `sub_fields`; children are
separate `acf-field` posts joined by `post_parent`. `sub_fields` exists only in the loaded
representation. Clone fields go further and splice in entire foreign field definitions at
load time.

If you round-trip that loaded array back through `acf_update_field()`, you serialise the
synthesised `sub_fields` into the parent's `post_content`, permanently. You now have
children represented twice - once as real posts, once as a stale frozen copy inside the
parent - and every subsequent read is ambiguous. ACF's own importer is careful to avoid
this: it calls `acf_extract_var($field, 'sub_fields')` to *remove* the key before saving
**[verified, `class-acf-field-group.php:534`]**, and wraps the whole import in
`acf_disable_filters()` **[verified, `class-acf-field-group.php:914`]**.

**Replacement.** Two explicitly different reads, named so they cannot be confused:

```php
interface TreeReader {
    /** Filtered, resolved, human-facing. For diff + preview. */
    public function readResolved(string $groupKey): Tree;

    /** Raw stored arrays, filters disabled. The ONLY basis for a write. */
    public function readRaw(string $groupKey): Tree;
}
```

`readRaw()` wraps its work in `acf_disable_filters()` / `acf_enable_filters()`, mirroring
ACF's own import path, and builds the tree from `acf_get_raw_field()` +
`acf_get_raw_fields($parentId)` traversal rather than `acf_get_field()`.

The partial-update primitive becomes precise, and is the single most important function in
the plugin:

```php
// Apply exactly the named settings, preserve everything else byte-for-byte.
$raw     = acf_get_raw_field( $key );          // stored state, unfiltered
$merged  = $raw;
foreach ( $changedSettings as $setting => $value ) {
    $merged[ $setting ] = $value;              // only what the JSON named
}
acf_update_field( $merged );
```

Note what this rules out: you cannot use `acf_update_field()`'s `$specific` parameter for
partial updates. I checked - `$specific` filters the **`wp_posts` column list**
(`post_title`, `post_excerpt`, …), not the field settings, because all settings are
serialised as a single blob into `post_content` **[verified,
`acf-field-functions.php:1004`]**. Read-merge-write is the only correct mechanism. The plan
does not say this and a reasonable implementer would get it wrong.

**Impact.** Two reader methods instead of one. Prevents a subtle, unrecoverable corruption
of nested groups.

---

### B.4 - Reuse ACF's flatten-and-map write algorithm. Do not invent tree writing.

**Plan says** (§15/§16): a recursive mutation layer walking the tree depth-first.

**Problem.** Writing a nested ACF tree has an ordering constraint the plan never
addresses: a child's `parent` must be a **post ID**, which does not exist until the parent
has been inserted. A naïve depth-first writer has to interleave inserts and ID lookups and
will get it wrong for fields that move between parents in the same batch.

**Evidence.** ACF already solved this, and the solution is better than recursion. Field
types implement `prepare_field_for_import()` which *flattens* a subtree into a sequence:

```php
// includes/fields/class-acf-field-group.php:534   [verified]
$sub_fields = acf_extract_var( $field, 'sub_fields' );
foreach ( $sub_fields as $i => $sub_field ) {
    $sub_fields[ $i ]['parent']     = $field['key'];   // key, not ID
    $sub_fields[ $i ]['menu_order'] = $i;
}
return array_merge( array( $field ), $sub_fields );    // [parent, child, child, …]
```

`acf_prepare_fields_for_import()` splices these recursively into one flat, correctly
ordered list **[verified, `acf-field-functions.php:1592`]**, and the importer then walks it
with a running `key ⇒ ID` map, rewriting `parent` from key to ID as it goes **[verified,
`class-acf-field-group.php:914`]**. Parent always precedes child, so the map is always
populated in time. It is linear, it handles arbitrary depth, and it is the algorithm ACF
itself is tested against.

**Replacement.** `ACFJP\Apply\Writer` adopts exactly this shape:

1. Materialise the post-change subtree.
2. `acf_prepare_fields_for_import()` to flatten - this also lets PRO field types
   contribute their own flattening (Flexible Content's `layouts[].sub_fields`) without us
   knowing their internals.
3. Walk the flat list with a `key ⇒ ID` map seeded from the existing tree, so
   **pre-existing fields keep their IDs and keys** and are updated in place rather than
   recreated.
4. Deletes last, after all inserts and moves have settled.

Point 3 is where we diverge from ACF and where the product lives: ACF's importer deletes
every field absent from the payload **[verified - the `! in_array( $field['key'], $keys )`
branch in `import_post()`]**. Ours never deletes anything the ChangeSet did not explicitly
mark for deletion.

**Impact.** Less code than the plan's recursive writer, better PRO compatibility, and
correct by construction on ordering.

---

### B.5 - `acf_import_field_group()` is a destructive primitive. Confine it to two call sites.

**Plan says** - uses it loosely as "the import API."

**Problem.** It deletes. Confirmed above. If it is reachable from the `update`, `add` or
`merge` paths, a single bug silently destroys a customer's field group.

**Replacement.** `acf_import_field_group()` may be called from exactly **two** places in
this codebase, and a PHPStan rule plus a CI grep enforces it:

1. `Apply\Restore::fromSnapshot()` - rollback, where whole-group replacement is the
   *desired* semantic.
2. `Operations\Replace` and `Operations\Sync` - the two operations whose documented
   contract is "make the group match this payload," which is the same semantic, behind a
   type-to-confirm destructive dialog.

Everything else goes through `acf_update_field()` / `acf_delete_field()` per field.

**Impact.** A lint rule and a documented invariant. Nearly free; eliminates a class of
catastrophic bug.

---

### B.6 - Replace the two-table history design with one journal table and a content-addressed snapshot store.

**Plan says** (§16 of the original brief): a `history` table with `snapshot` LONGTEXT and a
separate `logs` table.

**Problem.** A realistic field group exports to 50-500 KB of JSON. One `LONGTEXT` snapshot
per history row means a developer iterating on a group twenty times in an afternoon writes
ten megabytes of near-identical blobs into `wp_options`-adjacent storage. It bloats
backups, it bloats `wp_postmeta`-sized dumps, and there is no dedup. Two tables also means
two schemas to migrate and a join for the common "what happened here" query.

**Replacement.** One journal table, plus a snapshot store keyed by content hash:

```sql
CREATE TABLE {prefix}acfjp_journal (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  batch_id        CHAR(36)        NOT NULL,
  created_at      DATETIME        NOT NULL,
  user_id         BIGINT UNSIGNED NOT NULL,
  source          VARCHAR(16)     NOT NULL,   -- admin|rest|cli
  operation       VARCHAR(16)     NOT NULL,
  group_key       VARCHAR(64)     NOT NULL,
  group_title     VARCHAR(255)    NOT NULL,
  before_hash     CHAR(64)        NOT NULL,   -- → snapshot store
  after_hash      CHAR(64)        NULL,
  changeset       LONGTEXT        NOT NULL,   -- the ChangeSet, not the state
  change_count    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  status          VARCHAR(16)     NOT NULL DEFAULT 'applied',
  rolled_back_at  DATETIME        NULL,
  rolled_back_by  BIGINT UNSIGNED NULL,
  PRIMARY KEY (id),
  KEY k_batch (batch_id),
  KEY k_group (group_key, id),
  KEY k_created (created_at)
) {charset_collate};

CREATE TABLE {prefix}acfjp_snapshots (
  hash       CHAR(64)  NOT NULL,       -- sha256 of the canonical export
  created_at DATETIME  NOT NULL,
  bytes      MEDIUMINT UNSIGNED NOT NULL,
  payload    LONGTEXT  NOT NULL,       -- gzip+base64 native ACF export
  refcount   INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (hash),
  KEY k_created (created_at)
) {charset_collate};
```

Why this is better: identical states stored once (the `after_hash` of one batch *is* the
`before_hash` of the next, so a twenty-step session stores twenty-one snapshots as
twenty-one rows but any unchanged sibling group dedups to a single row); rollback is a
hash lookup; the journal row is small enough to list a thousand of them without touching
the blob table; retention/GC is a refcount sweep; and `changeset` - the thing users
actually read in the History screen - is on the row itself, so the list view never joins.

Snapshots are gzipped before base64. Measured on ACF's own sample exports, that is roughly
an 8:1 reduction on field-group JSON.

The audit log is not a second table. Audit *is* the journal: every mutation produces a row.
Non-mutating security events (rejected nonce, capability failure, schema rejection) go to
a WordPress-native destination via an `acfjp/audit` action, defaulting to no-op, so sites
with an existing audit plugin get integration for free and sites without one do not pay for
a table they will never read.

**Impact.** One extra tiny table, a `SnapshotStore` class with `put()/get()/gc()`, and a
retention setting (default: keep 50 batches per group, 90 days, whichever is larger).

---

### B.7 - Smaller corrections

These do not warrant full sections but each is a real fix.

- **Nesting depth must be bounded after all.** The plan says "no artificial maximum depth."
  Unbounded recursion over attacker-influenced JSON is a stack-exhaustion DoS, and the
  parser runs before authentication is fully meaningful in the CLI path. Bound at **depth
  32**, filterable via `acfjp/max_depth`. Real ACF trees are 3-5 deep; 32 is unreachable in
  practice and finite in theory. Cycle detection on `key` references is separate and also
  mandatory.

- **`Requirements::assert()` must not `assert`.** The plan's bootstrap throws from
  `boot()`. A plugin that fatals when ACF is missing takes the site down on a deactivation
  order the user did not think about. `Requirements::met(): bool` returns, and the bootstrap
  registers an admin notice and returns early. Never `wp_die()`, never throw, at load time.

- **Plan tokens need optimistic concurrency, not just a TTL.** The plan previews and
  applies as separate requests but never addresses what happens if ACF changed in between -
  two developers in the same admin, or a deploy running `wp acfjp apply`. The plan token
  stores the `before_hash` of the tree it diffed; `apply` recomputes it and returns
  **409 `STATE_CHANGED`** on mismatch, with a fresh diff. Without this, "preview and apply
  agree" is false under concurrency and the product's core promise leaks.

- **Field `name` has a real constraint and the plan ignores it.** ACF's own schemas specify
  `"pattern": "^[a-z_][a-z0-9_]*$"` for `name` and `"^field_[a-z0-9]+$"` for `key`
  **[verified]**. Normalizer must slug incoming names to that pattern and *reject* rather
  than silently mangle when the result would collide with a sibling.

- **Renaming a field is a data migration, not a config change.** The plan lists `RENAME` as
  a change type alongside `UPDATE`. It is not equivalent: the field `name` is the meta key.
  Renaming `phone` to `business_phone` orphans every stored value on every post. v1 must
  treat a name change as a **conflict requiring explicit choice**, offering: keep the name
  and change only the label (the thing the user almost always meant); rename and leave data
  orphaned (explicit, warned, counted); or rename and migrate the meta. Meta migration is
  genuinely hard - sub-fields inside repeaters have compound keys like
  `team_0_member_name` - so v1 offers only the first two and states the limitation plainly.
  Promising a migration we cannot do reliably would be worse than not offering it.

- **Clone fields cannot be patched through.** Confirmed, and see §B.8 - the situation is
  more dangerous than "cannot patch". v1 detects a target path that traverses a clone and
  refuses with a pointer to the real owning group.

- **Flexible Content layout identity - confirmed, with a correction.** Sub-fields of a
  layout carry `parent_layout`, and its value is the layout's **`key`**, not its `name`:

  ```php
  // pro/fields/class-acf-field-flexible-content.php  prepare_field_for_import()   [verified]
  $sub_field['parent']        = $field['key'];
  $sub_field['parent_layout'] = $layout['key'];
  $sub_field['menu_order']    = $i;              // index WITHIN the layout
  ```

  Layouts are stored as an associative array keyed by layout key; `get_valid_layout()`
  defaults a missing key to `uniqid( 'layout_' )`. Our model had this wrong on first
  writing (it used the layout name) and it is now fixed. Routing Flexible Content writes
  through `acf_prepare_fields_for_import()` (§B.4) remains the design, because PRO's own
  flattening sets all three attributes correctly and we never hand-build them.

  One hazard worth guarding: if `parent_layout` is empty, FC's `load_field()` **silently
  assigns the sub-field to the first layout** [verified]. A sub-field written without that
  attribute does not error - it quietly moves. Our Verifier asserts `parent_layout` on
  every FC descendant after apply.

- **Repeater injects `parent_repeater` at load time.** [verified,
  `class-acf-field-repeater.php load_field()`] Another synthesised key, in the same class
  of hazard as `sub_fields` (§B.3). It is on the reserved/volatile list and is never
  persisted or diffed.

---

### B.8 - Seamless Clone silently rewrites the read. The diff must never see it.

Found while verifying §B.7 against PRO source. This is the third data-safety finding and
the second one that is invisible from the plan's altitude.

**The problem.** Clone does not merely resolve fields at load time. In `seamless` display
mode it hooks `acf/get_fields` at priority 5 and **splices itself out of the list,
replacing itself with the fields it clones**:

```php
// pro/fields/class-acf-field-clone.php  acf_get_fields()   [verified]
if ( $field['type'] != 'clone' )        continue;
if ( $field['display'] != 'seamless' )  continue;
--$i;
array_splice( $fields, $i, 1, $field['sub_fields'] );   // clone vanishes, foreign fields appear
```

So `acf_get_fields( $group )` on a group containing a seamless clone returns a list in
which the clone field **is not present** and in which several fields **belong to a
different field group entirely** (with their names rewritten by the clone's `prefix_name`
setting).

Now consider a diff built on that read. The incoming JSON describes the group as the
developer sees it in the ACF editor - which *does* contain the clone field. The comparator
would conclude:

- the clone field exists in the JSON but not in "current" ⇒ **ADD a duplicate clone**;
- several fields exist in "current" but not in the JSON ⇒ under `sync` or `replace`,
  **DELETE them** - and they are another group's fields, so `acf_delete_field()` would
  destroy configuration the user never targeted, in a group they never mentioned.

That is a cross-group destructive write originating from a read-path artifact. Nothing in
the plan would have caught it.

**Evidence that the fix works.** Clone's injection is gated on `acf_is_filter_enabled( 'clone' )`
[verified, `is_enabled()`], and `acf_disable_filters()` clears every filter flag including
that one [verified, `acf-helper-functions.php`]. So `TreeReader::readRaw()` - which already
wraps its work in `acf_disable_filters()` for the §B.3 reason - sees the clone field
itself, in place, with no foreign fields injected. The dual-read design was introduced to
prevent corrupting nested groups; it turns out to also be the only safe basis for diffing
any group that uses Clone.

**Replacement.** Three rules, all cheap:

1. **The Comparator is fed `readRaw()` trees, never `readResolved()` trees.**
   `readResolved()` exists only for preview rendering, where showing the user what ACF
   will actually display is the correct behaviour. Enforced by type: `Comparator::compare()`
   accepts a `RawTree`, and `readResolved()` returns a `ResolvedTree`. They are different
   classes so the wrong one cannot be passed.
2. **A target path that traverses a `clone` node is refused** with `TRAVERSES_CLONE`,
   naming the source field and its owning group, because the change belongs there.
3. **Deletes are additionally guarded by ownership.** Before `acf_delete_field()`, the
   Writer asserts the field's `parent` chain terminates at the field group named in the
   ChangeSet. A delete targeting a field owned by another group is refused as
   `WRITE_FAILED` even if a ChangeSet somehow asked for it. Defence in depth: the
   comparator should never produce such a change, and if it ever does, it does not execute.

**Impact.** One type split (`RawTree` / `ResolvedTree`), one resolver check, one writer
assertion. It closes a path to destroying a field group the user never named.

---

## C. Why each change is better - summary table

| # | Change | Risk removed | Cost |
|---|---|---|---|
| B.1 | Consume ACF's shipped field schemas | Validation rot; false rejections; silent bad settings | −1 large hand-maintained table, +1 small provider |
| B.2 | Mutability classifier + Guard | **Silent duplicate-post corruption on PHP/JSON-registered groups** | +1 class, +1 stage, ~2 days |
| B.3 | Raw reads for writes, filtered for diffs | **Permanent corruption of nested groups via serialised `sub_fields`** | +1 reader method |
| B.4 | Reuse ACF's flatten + key⇒ID map | Wrong write ordering; PRO incompatibility | Less code than planned |
| B.5 | Confine `acf_import_field_group()` | Catastrophic unintended field deletion | 1 lint rule |
| B.6 | Journal + content-addressed snapshots | Storage bloat; slow history; schema sprawl | +1 small table |
| B.7 | Depth bound, non-fatal requirements, plan tokens, name patterns, rename-as-conflict | DoS; site-down; lost updates; orphaned content | Small, each |
| B.8 | Diff from raw trees; clone-traversal refusal; delete ownership assertion | **Cross-group field deletion via seamless Clone read artifact** | 1 type split, 2 assertions |

The three bolded rows are the ones that justify this review existing. All are
data-destroying, all are silent, and none is visible from the plan's level of abstraction -
you only find them by reading ACF's source.

---

## D. Risks

**Accepted, with mitigation.**

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| ACF changes its internal field storage | low | high | We use only public `acf_*()` functions; no direct SQL against ACF tables. Integration suite runs against 3 ACF versions in CI. |
| PRO field internals differ from assumption | ~~medium~~ **resolved** | medium | PRO 6.8.10 source read directly; all assumptions confirmed or corrected (§B.7, §B.8). Writes still delegate to `acf_prepare_fields_for_import()` so future PRO changes are absorbed rather than reimplemented. |
| `acf_get_field_json_schema()` removed or changed | low | medium | Wrapped in our provider; an empty return degrades to loose validation with a preview warning, never to a hard failure. |
| Diff produces a plausible-but-wrong ChangeSet | medium | **high** | The Verifier re-reads post-apply and compares against the expected tree; mismatch triggers automatic restore. Preview is never the only check. |
| Rollback restores a group whose fields are referenced elsewhere (clone/bidirectional) | low | medium | Snapshot is whole-group; cross-group references are detected at snapshot time and the affected groups are snapshotted together as one batch. |
| Large sites make preview slow | medium | low | Tree read is one query per group plus one for children; target <300 ms for a 200-field group. Measured in CI with a generated fixture. |
| Local JSON writes fight our writes | medium | low | We fire `acf_update_field_group()` once at batch end so ACF's own `acf/update_field_group` hook rewrites the file **[verified]**. Field-level writes alone do *not* trigger it - this is the subtle part and it is why the batch-end call is mandatory, not optional. |

**Rejected as out of scope, stated openly.** Meta-key migration on rename. Cross-site
field group sync. Concurrent multi-user editing beyond 409 detection. Patching
PHP-registered groups.

---

## E. Compatibility strategy

**Support matrix, tested in CI.**

| | Minimum | Tested | Notes |
|---|---|---|---|
| PHP | 8.1 | 8.1, 8.2, 8.3, 8.4 | 8.1 is EOL-adjacent but still the realistic agency floor |
| WordPress | 6.5 | 6.5, latest, trunk | |
| ACF | 6.2 | 6.2, 6.5, 6.8 | 6.2 introduced the internal-post-type abstraction we rely on |
| ACF PRO | 6.2 | 6.8 | Repeater / FC / Clone / Gallery paths |
| MySQL | 5.7 | 5.7, 8.0, MariaDB 10.6 | |

**ACF version gating.** `acf_get_field_json_schema()` is 6.8.0+ **[verified]**. Below that,
`FieldTypeSchemas::for()` returns `[]` and validation degrades to the capability overlay
plus `acf_get_field_types()`. The plugin works on ACF 6.2; it validates *better* on 6.8+.
Feature detection with `function_exists()`, never version_compare against ACF's version
string.

**Free vs PRO.** `acf_get_pro_field_types()` **[verified, `includes/fields.php:422`]**
enumerates exactly four: `clone`, `flexible_content`, `gallery`, `repeater`. On a free
install, JSON naming any of them validates structurally (the schemas ship in free) and then
fails the Guard with `FIELD_TYPE_REQUIRES_PRO`, naming the field and linking the docs. No
half-applied batch, no cryptic error.

**Backward compatibility of our own format.** `version` is required in every payload and
the Parser dispatches on it. `"1.0"` is frozen at release. Additive changes bump the minor
(`1.1`) and remain readable by a `1.0` engine; anything that changes the meaning of an
existing key bumps the major and the old reader stays in the codebase. Our exports always
write the newest version; our importer reads every version ever shipped. This is cheap now
and impossible to retrofit later.

---

## F. Data-safety strategy

Five layers, deliberately redundant, ordered by when they act.

1. **Refuse to start.** The Guard classifies mutability (§B.2), checks PRO availability,
   checks capability, and validates the whole payload before a single byte is written. A
   batch that cannot fully succeed never begins.

2. **Never write what was not asked for.** Read-merge-write on raw stored arrays (§B.3).
   The regression test for this is exact and non-negotiable: export the group before,
   apply, export after, assert the two JSON documents differ *only* in the settings the
   ChangeSet named. Byte-level, not semantic. It runs on every operation in every test.

3. **Snapshot before mutation.** Whole-group native ACF export, content-hashed, stored
   before the first write. Includes every group touched by the batch, including groups
   pulled in by cross-references.

4. **Verify after mutation.** Re-read the tree and compare to the expected post-state
   computed by the diff engine. `acf_update_field()` returning without error proves nothing
   - it returns the field array whether or not the post write did what we wanted. On
   mismatch: automatic restore from snapshot, journal row marked `failed`, structured error
   to the caller. The user's site is never left in a state we did not intend.

5. **Rollback on demand.** One click, from the journal, by hash. Rollback is itself a
   journaled operation with its own snapshot, so rolling back a rollback works.

**Key preservation is a first-class invariant.** Existing `key` values are load-bearing:
they are the reference stored in `_fieldname` hidden meta and they are what
`acf_get_reference()` resolves against **[verified, `acf-value-functions.php:27`]**. The
engine never regenerates a key for a field it matched. New keys use
`uniqid('field_')`-shaped generation matching ACF's own `^field_[a-z0-9]+$` pattern, with a
collision check against all existing keys site-wide.

**Data-loss detection before delete.** Because ACF writes a hidden `_<name>` meta row whose
*value is the field key* **[verified]**, we can answer "does this field hold data anywhere"
with one indexed-ish lookup per meta table:

```sql
SELECT 1 FROM {$wpdb->postmeta} WHERE meta_value = %s LIMIT 1   -- and termmeta, usermeta, options
```

This also catches repeater sub-field rows (`team_0_member_name` → same field key as value),
which a name-based search would miss entirely. `meta_value` is not indexed, so this is
capped, cached per batch, and skippable via a setting on very large sites - with the
preview stating plainly that the check was skipped rather than silently reporting "no
data."

---

## G. Testing strategy

**Three tiers.**

*Unit - no WordPress, fast, the bulk of the suite.* Parser, Validator, Normalizer,
Comparator, PathResolver, ChangeSet serialisation, SnapshotStore hashing. Fixture-driven:
`tests/fixtures/<case>/{input.json,current.json,expected-changeset.json}`. Adding a diff
case means adding three files, not writing a test.

*Integration - real WordPress, real ACF, real database.* `wp-env` provides the stack. Each
test creates an actual field group, runs a real operation, and asserts against
`acf_prepare_field_group_for_export()`. The matrix runs free and PRO, and across the three
ACF versions.

*Property / invariant - the tests that actually protect the product.* Four invariants,
asserted over generated trees rather than hand-picked examples:

1. **Preservation.** For any tree and any ChangeSet, the post-apply export differs from the
   pre-apply export in exactly the keys the ChangeSet named. *(This is the product.)*
2. **Round-trip.** `export → import → export` is a fixed point.
3. **Rollback.** `snapshot → arbitrary batch → rollback` returns to a byte-identical export.
4. **Idempotence.** Applying the same ChangeSet twice produces zero changes the second
   time.

Generated trees come from a small fixture generator: random depth ≤ 6, random field types
from the installed set, random settings drawn from ACF's own schemas. Shrinking on failure.

**Coverage gates:** 90% on `Json\`, `Diff\`, `Resolve\`; 85% on `Apply\`; 70% overall. CI
fails below. **Static analysis:** PHPStan level 8 on `includes/`, plus the custom rule
enforcing §B.5. **Standards:** PHPCS with WordPress-Extra and a PSR-12 override for layout.

**The canonical acceptance test** from the brief (Property → Agent → Email required,
+WhatsApp) is implemented as an integration test and gates the Phase 3 milestone.

---

## H. Recommended project structure

Close to the plan, with the pure/effect split made visible in the tree and `Security/`
dissolved - security is not a module, it is a property of the boundaries, and a folder
named `Security/` invites developers to believe the checks live somewhere else.

```
wp-acf-json-pro/
├── wp-acf-json-pro.php          # bootstrap only; ~60 lines, no logic
├── uninstall.php
├── composer.json                # dev-only deps
├── readme.txt
├── CLAUDE.md
│
├── includes/
│   ├── Core/
│   │   ├── Plugin.php           # composition root
│   │   ├── Container.php        # ~80-line DI container, no library
│   │   ├── Autoloader.php       # runtime PSR-4, no vendor/ needed
│   │   ├── Requirements.php     # returns bool; never fatals
│   │   └── Activation.php       # table creation, dbDelta, version option
│   │
│   ├── Json/                    # ── pure ──
│   │   ├── Parser.php
│   │   ├── Validator.php
│   │   ├── Normalizer.php
│   │   ├── Dialect.php          # native-ACF vs acfjp vs loose-AI detection
│   │   ├── FieldTypeSchemas.php # §B.1
│   │   └── Capabilities.php     # container/structural/reference overlay
│   │
│   ├── Model/                   # ── pure ──
│   │   ├── Tree.php   Field.php   FieldGroup.php   Layout.php
│   │   ├── Target.php   TargetPath.php
│   │   └── Payload.php
│   │
│   ├── Resolve/                 # ── pure ──
│   │   ├── TargetResolver.php
│   │   ├── PathMatcher.php
│   │   └── Ambiguity.php
│   │
│   ├── Diff/                    # ── pure ──
│   │   ├── Comparator.php
│   │   ├── Matcher.php          # key → name → path → label
│   │   ├── SettingsDiff.php
│   │   ├── ChangeSet.php   Change.php   Conflict.php
│   │   └── Risk.php
│   │
│   ├── Acf/
│   │   ├── TreeReader.php           # readResolved() + readRaw()  §B.3
│   │   ├── MutabilityClassifier.php # §B.2
│   │   ├── KeyFactory.php
│   │   ├── DataProbe.php            # "does this field hold content?"
│   │   └── Export.php
│   │
│   ├── Apply/
│   │   ├── Engine.php           # the single entry point: plan() then apply()
│   │   ├── Guard.php            # §A change 2
│   │   ├── Plan.php  PlanStore.php   # previews + optimistic concurrency
│   │   ├── Writer.php           # flatten + key⇒ID map  §B.4
│   │   ├── Verifier.php
│   │   ├── Restore.php
│   │   └── ApplyResult.php  WriteResult.php
│   │
│   ├── Journal/
│   │   ├── Journal.php   Entry.php
│   │   ├── SnapshotStore.php    # content-addressed  §B.6
│   │   └── Retention.php
│   │
│   ├── Rest/
│   │   ├── Controller.php  Routes.php  PlanToken.php  ErrorEnvelope.php
│   │
│   ├── Cli/
│   │   └── Command.php
│   │
│   ├── Admin/
│   │   ├── Menu.php  Assets.php
│   │   └── Screens/{Dashboard,Import,Preview,History,Export,Settings}.php
│   │
│   └── Exceptions/
│       ├── AcfjpException.php  ErrorCodes.php  (+ typed subclasses)
│
├── assets/  {js,css}            # no build step; ES modules + wp_enqueue_code_editor()
├── templates/
├── schemas/
│   ├── wp-acf-json-pro-v1.json
│   └── prompt/ai-instructions.md
└── tests/ {Unit,Integration,Property,fixtures}
```

**Deviation from the original sketch, recorded honestly.** This section first listed
an `Apply/Operations/` directory with eight strategy classes, one per operation. On
implementation that turned out to be abstraction without a payer: an operation's
semantics are entirely *what it diffs* and *what it writes*, which is a `match` in
`Comparator::compare()` and an ordered sequence in `Writer::write()`. Eight classes
implementing a one-method interface, each calling back into the comparator, would
have added indirection and no seam - nothing would ever be swapped at that boundary.
The dispatch is four lines and reads top to bottom. If a third-party operation is
ever wanted, the extension point is `acfjp/booted` plus the public `Engine`, not an
interface nobody outside the plugin implements.

**Front end.** No React, no build pipeline. WordPress already ships CodeMirror
(`wp_enqueue_code_editor( ['type' => 'application/json'] )`) and the diff view is a
server-rendered list with progressive enhancement. A build step on a developer-tool plugin
costs every contributor a toolchain and buys nothing here. If the conflict UI later
outgrows vanilla JS, `@wordpress/interactivity` is the WordPress-native upgrade path, not
a bundled SPA.

---

## I. Recommended development phases

Reordered from the plan. The plan's Phase 5 (History) is too late - snapshots must exist
before the first destructive operation ships, not after. Each phase ends with a demo and a
green gate; nothing proceeds on a red gate.

**Phase 0 - Spike. ✅ COMPLETE.** ACF free and PRO 6.8.10 source read directly. Findings:
Flexible Content `parent_layout` holds the layout **key** and `menu_order` is the index
within the layout; Repeater flattening matches Group's; Repeater injects `parent_repeater`
at load time; seamless Clone splices itself out of `acf_get_fields()` and injects foreign
fields (§B.8). *Gate: the unverified list is empty.* ✅

**Phase 1 - Skeleton + read path.** Bootstrap, container, requirements (non-fatal),
autoloader, activation/tables, admin menu, `TreeReader` (both modes),
`MutabilityClassifier`, Dashboard listing every group with its mutability class.
*Gate: the Dashboard correctly classifies a DB group, a JSON group and a PHP group on a
real install.*

**Phase 2 - Pure pipeline.** Parser, Validator, `FieldTypeSchemas`, Normalizer, Model,
`TargetResolver`. No writes at all. `POST /validate` returns structured errors with
suggestions. *Gate: 200 fixture payloads - valid, invalid, native-ACF, AI-sloppy - each
producing the expected model or the expected error code.*

**Phase 3 - Diff + preview.** Comparator, Matcher, SettingsDiff, ChangeSet, Risk, conflict
detection, `DataProbe`, the Preview screen, `POST /plan`. Still no writes.
*Gate: the canonical acceptance scenario renders exactly the two expected changes, and the
invariant suite's diff half is green.*

**Phase 4 - Apply, with safety first.** In this order, deliberately: `SnapshotStore` →
`Journal` → `Guard` → `Writer` → `Verifier` → `Restore` → then `Create`, `Add`, `Update`.
Destructive operations (`Delete`, `Replace`, `Sync`) come last and only once Restore is
proven. *Gate: all four invariants green; the acceptance scenario applies and rolls back
byte-identically.*

**Phase 5 - PRO structures.** Repeater, Group, Flexible Content, Clone - built on Phase 0's
verified findings, routed through `acf_prepare_fields_for_import()`. Path targeting into
layouts. *Gate: a three-level nested tree with a flexible-content layout round-trips.*

**Phase 6 - Surfaces.** REST parity with plan tokens and 409 handling, WP-CLI, export in
both dialects, History screen with rollback, AI prompt generator, hooks and filters.
*Gate: every UI action has a REST and CLI equivalent that produces an identical journal
row.*

**Phase 7 - Hardening.** Performance pass against a 500-field fixture, retention/GC,
i18n sweep, security review against §10, readme and docs. *Gate: preview <300 ms at 200
fields; PHPStan 8 clean; coverage gates met.*

---

## Verdict

Build the product the plan describes. Do not build the plugin the plan describes.

The vision - a patch engine with a mandatory diff, conflict detection and rollback, driven
by a schema any AI can emit, with no AI dependency in the plugin - is correct, well
differentiated, and worth building. The pipeline is the right decomposition.

The implementation substrate needs the seven corrections above. Two of them (§B.2, §B.3)
are not refinements; they are the difference between a tool that quietly corrupts ACF
configuration on common real-world installs and one that does not. Neither is discoverable
from the plan's altitude. Both were found by reading ACF's source for an hour, which is
the cheapest hour in this project.

Proceed to Phase 0.
