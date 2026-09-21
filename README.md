# WP ACF JSON Pro

> **A declarative, target-scoped configuration patch engine for Advanced Custom Fields (ACF Free & PRO).**  
> Diff before you apply. Snapshot before you write. Roll back whenever you need. Zero runtime dependencies.

[![PHP Version](https://img.shields.io/badge/PHP-8.1%20--%208.3-777bb4.svg?style=flat-square&logo=php&logoColor=white)](https://www.php.net/)
[![WordPress](https://img.shields.io/badge/WordPress-6.5%20--%206.8%2B-21759b.svg?style=flat-square&logo=wordpress&logoColor=white)](https://wordpress.org/)
[![ACF Compatibility](https://img.shields.io/badge/ACF%20%2F%20PRO-6.2%20--%206.8%2B-00a32a.svg?style=flat-square)](https://www.advancedcustomfields.com/)
[![Static Analysis](https://img.shields.io/badge/PHPStan-Level%208%20(0%20errors)-00a32a.svg?style=flat-square)](https://phpstan.org/)
[![Tests](https://img.shields.io/badge/PHPUnit-95%20tests%20%2F%20186%20assertions-brightgreen.svg?style=flat-square)](https://phpunit.de/)
[![License](https://img.shields.io/badge/License-GPLv2%2B-blue.svg?style=flat-square)](https://www.gnu.org/licenses/gpl-2.0.html)

---

## Table of Contents

- [Why WP ACF JSON Pro?](#why-wp-acf-json-pro)
- [The Mental Model & Architecture](#the-mental-model--architecture)
- [Target Resolution Engine](#target-resolution-engine)
- [Supported JSON Patch Operations](#supported-json-patch-operations)
  - [1. add (Safe)](#1-add-safe)
  - [2. update (Safe - Partial Patch)](#2-update-safe---partial-patch)
  - [3. create (Safe - New Field Group)](#3-create-safe---new-field-group)
  - [4. delete (Destructive)](#4-delete-destructive)
  - [5. move (Caution - Relocate / Reorder)](#5-move-caution---relocate--reorder)
  - [6. merge (Safe - Additive Only)](#6-merge-safe---additive-only)
  - [7. sync (Caution - Mirror Parity)](#7-sync-caution---mirror-parity)
  - [8. replace (Destructive - Full Rebuild)](#8-replace-destructive---full-rebuild)
- [Defense-in-Depth Safety Systems](#defense-in-depth-safety-systems)
  - [In-Memory Diff Simulation](#in-memory-diff-simulation)
  - [Optimistic Concurrency Fingerprinting](#optimistic-concurrency-fingerprinting)
  - [Pre-Mutation Snapshotting & Automatic Compensation](#pre-mutation-snapshotting--automatic-compensation)
  - [Post-Apply Verification & Untouched Sibling Protection](#post-apply-verification--untouched-sibling-protection)
  - [Validated Rollback & Audit Journal](#validated-rollback--audit-journal)
  - [Read-Only Guard](#read-only-guard)
- [AI Workflow: Zero API Keys, Zero Vendor Lock-in](#ai-workflow-zero-api-keys-zero-vendor-lock-in)
- [REST API Reference](#rest-api-reference)
- [WP-CLI Commands](#wp-cli-commands)
- [Extensibility & Developer Hooks](#extensibility--developer-hooks)
- [Testing & Quality Gates](#testing--quality-gates)
- [Installation & Quickstart](#installation--quickstart)

---

## Why WP ACF JSON Pro?

For years, authoring and maintaining complex ACF field groups required clicking through the WordPress admin UI, one field at a time. Native ACF JSON exports exist, but importing them through standard tools is an **all-or-nothing full replacement** that wipes un-exported settings, risks orphan keys, and cannot apply surgical updates.

Meanwhile, modern engineering teams and AI models (ChatGPT, Claude, Gemini, Cursor, Copilot) can write JSON configurations effortlessly. The bottleneck was never *generating* JSON - it was **safely validating, scoping, simulating, and applying targeted patches**.

**WP ACF JSON Pro is a configuration patch engine.** It operates as a compiler pipeline:
1. **Target-Scoped**: Modify a single sub-field deep inside a Repeater or Flexible Content layout without risking adjacent branches.
2. **Partial Patches**: Update `required: true` and `instructions: "..."` on one field; untouched properties and sibling fields remain 100% byte-identical.
3. **Deterministic Identity**: Existing ACF field keys (`field_64abc123`) are preserved to safeguard your database postmeta relationships.
4. **Defense-in-Depth**: Preview diffs in memory, detect conflicts, snapshot before mutating, and verify with automatic rollback on discrepancy.

---

## The Mental Model & Architecture

WP ACF JSON Pro treats an ACF Field Group as a **hierarchical configuration tree**, not a flat database table.

```
JSON Request (Payload / Native ACF Export)
      │
      ▼
   Parse & Normalize (Coerces AI aliases, removes code fences, validates syntax)
      │
      ▼
   Resolve Root Field Group (GroupLocator: mutability check, boundary lock)
      │
      ▼
   Build In-Memory ACF Field Tree (TreeReader: hierarchical fields, sub-fields, layouts)
      │
      ▼
   Resolve Exact Target Node (TargetResolver: key -> path -> scoped name -> label)
      │
      ▼
   Calculate Scoped Diff (Comparator: calculates ChangeSet relative to Target Locus)
      │
      ▼
   Plan Store & State Fingerprint (Computes deterministic SHA-256 tree stateHash)
      │
      ▼
   [PREVIEW DIFF / DRY-RUN] (Displays additions, updates, removals, risk ratings)
      │
      ▼
   Guard Check (StateHash match, capability check, explicit confirmation)
      │
      ▼
   Pre-Mutation Snapshot (SnapshotStore: saves prior state before any write)
      │
      ▼
   Apply Mutation (Writer: official ACF APIs only, ordered parent-first mapping)
      │  └── If write fails: Automatic immediate snapshot restoration
      ▼
   Post-Apply Verification (Verifier: re-reads stored ACF state, asserts diff integrity)
      │
      ▼
   Audit Journal Recorded (Timestamped event with rollback available)
```

---

## Target Resolution Engine

The target resolution subsystem (`ACFJP\Resolve\TargetResolver`) guarantees that an operation applies **only to its intended locus** and never mutates sibling subtrees.

### Resolution Priority

1. **Exact ACF Field Key** (`field_64abc123`): Absolute and deterministic. Verified to belong to the active tree.
2. **Field Name within Scope** (`phone` under `contact_information`): Scoped to the parent container.
3. **Hierarchical Path** (`contact_information.phone` or `contact > address > city`): Walked segment by segment from root to leaf.
4. **Unique Human Label**: Case-insensitive; accepted only when 100% unambiguous within the scope.
5. **UI-Selected Target**: Explicitly chosen from the visual tree picker.

### Hard-Stop Ambiguity Handling

If a requested target matches multiple fields (for example, two subfields named `status` under different groups), the engine **hard-stops** with an `AMBIGUOUS_TARGET` error:
- Returns HTTP 422 / structured CLI error.
- Lists all matching candidates with their full hierarchical paths and unique ACF keys.
- **Zero Guessing**: Never falls back to the root, never picks the first match, and never alters array ordering.

### Deep Container Support

- **Group & Repeater**: Act as container loci (`isContainer() === true`) for child additions, updates, and reordering.
- **Flexible Content & Layouts**: Targets can specify a specific layout via `"layout": "hero_banner"`.
- **Clone Fields**: Traversal is guarded (`TRAVERSES_CLONE`). A field reached through a clone belongs to an external group and must be patched in its home definition.

---

## Supported JSON Patch Operations

All patch operations conform to the `wp-acf-json-pro-v1.json` JSON Schema.

### 1. `add` (Safe)
Appends new fields or subfields to the specified target. Existing fields remain untouched.

```json
{
  "version": "1.0",
  "operation": "add",
  "target": {
    "field_group": "Company Profile",
    "path": ["contact_info"]
  },
  "add": [
    {
      "name": "whatsapp_number",
      "label": "WhatsApp Number",
      "type": "text",
      "instructions": "Include international country code.",
      "required": 0
    }
  ]
}
```

### 2. `update` (Safe - Partial Patch)
Modifies **only the specific settings named**. Omitting settings preserves their current values.

```json
{
  "version": "1.0",
  "operation": "update",
  "target": {
    "field_group": "Company Profile",
    "path": "contact_info.email"
  },
  "changes": {
    "required": 1,
    "instructions": "Official business email address."
  }
}
```

### 3. `create` (Safe - New Field Group)
Builds a brand-new field group and all contained fields from scratch.

```json
{
  "version": "1.0",
  "operation": "create",
  "group_changes": {
    "title": "Case Studies",
    "location": [
      [
        {
          "param": "post_type",
          "operator": "==",
          "value": "case_study"
        }
      ]
    ]
  },
  "add": [
    {
      "name": "client_name",
      "label": "Client Name",
      "type": "text",
      "required": 1
    },
    {
      "name": "project_metrics",
      "label": "Key Metrics",
      "type": "repeater",
      "sub_fields": [
        {
          "name": "metric_label",
          "label": "Metric Label",
          "type": "text"
        },
        {
          "name": "metric_value",
          "label": "Metric Value",
          "type": "text"
        }
      ]
    }
  ]
}
```

### 4. `delete` (Destructive)
Removes specified fields. Triggers a pre-flight data probe scanning for stored content and requires explicit confirmation.

```json
{
  "version": "1.0",
  "operation": "delete",
  "target": {
    "field_group": "Company Profile",
    "path": "contact_info"
  },
  "delete": ["legacy_fax_number"]
}
```

### 5. `move` (Caution - Relocate / Reorder)
Relocates existing fields within or across containers while preserving their canonical ACF field keys.

```json
{
  "version": "1.0",
  "operation": "move",
  "target": {
    "field_group": "Company Profile"
  },
  "moves": [
    {
      "field": "social_links",
      "to": { "path": ["footer_section"] },
      "position": "after",
      "anchor": "copyright_text"
    }
  ]
}
```

### 6. `merge` (Safe - Additive Only)
Adds any fields present in the JSON that do not yet exist on site. Never deletes or overwrites existing fields.

### 7. `sync` (Caution - Mirror Parity)
Brings the target field group into exact mirror parity with the payload (adds new, updates modified, and deletes unlisted fields).

### 8. `replace` (Destructive - Full Rebuild)
Rebuilds the entire field group configuration from the provided schema.

---

## Defense-in-Depth Safety Systems

WP ACF JSON Pro is designed around a multi-layered safety model to ensure configuration changes are transparent, deterministic, and verifiable.

### 1. In-Memory Diff Simulation & Target Scope Breadcrumbs
Every operation is dry-run through `Engine::plan()`. The preview screen displays:
- **Hierarchical Target Breadcrumb**: `Root Field Group > Target Container (Repeater / Flexible Content / Group)`.
- **Target Isolation Guarantee**: Clearly indicates that mutations are strictly isolated to the target locus and all unrelated sibling branches remain protected and untouched.
- **Categorized Change Chips**: Instant summary metrics (`+ Added`, `~ Modified`, `- Deleted`, `🛡️ Protected Outside Target: 0 touched`).
- **Proportional Risk Engine**:
  - **`safe`** (Green): Field additions, non-breaking label/instruction updates.
  - **`caution`** (Yellow): Relocations, field type updates.
  - **`destructive`** (Red): Field deletions, layout removals, group replacements.

### 2. Pre-Apply Safety Checklist & Interactive Guard
Before any mutation can occur, developers must explicitly acknowledge the operation:
- `[x] I have reviewed the target scope and diff above.`
- `[x] I understand this operation will write changes to the ACF database.`
- `[x] I acknowledge that this operation contains destructive modifications or deletions.` *(Active on destructive risk or deletions)*
The **Apply Changes** button remains disabled until all required acknowledgements are checked.

### 3. 1-Click ACF Configuration JSON Export
A dedicated **`[ 📥 Export Current Configuration (JSON) ]`** button directly in the preview toolbar allows developers to download an exact copy of the current field group state (`acf-export-{groupKey}-{date}.json`) to their local machine for Git version control before applying any changes.

### 4. Optimistic Concurrency Fingerprinting
During `plan()`, a deterministic SHA-256 fingerprint (`stateHash`) of the current field group state is recorded. When `apply()` is called:
- If another developer, deployment, or background process modified the group between preview and apply, the hash check fails with `STATE_CHANGED`.
- The mutation is blocked to prevent applying stale diffs.

### 5. Pre-Mutation Snapshotting & Automatic Compensation
- Before any database write begins, the engine creates a full, byte-compatible internal snapshot of the ACF configuration.
- If `Writer::write()` encounters any unexpected error or exception, the engine triggers immediate automatic rollback (`Engine::rollbackAfterFailure()`) and restores the pre-mutation snapshot.

### 6. Post-Apply Verification & Untouched Sibling Protection
After writing, `Verifier::verify()` re-reads the raw data directly from ACF and verifies:
- All updated properties match the plan.
- All new fields and subfields are present with correct keys and parents.
- All deleted fields are gone.
- Flexible Content layout bindings (`parent_layout`, `menu_order`) are intact.
- **Sibling branches outside the target locus are verified to be byte-identical.**

### 7. Validated Rollback & Audit Journal
Every change is recorded in the journal table (`wp_acfjp_journal`). You can inspect past diffs and restore previous states with 1 click from **ACF JSON Pro -> History** or via `wp acf-json rollback <id>`. Rollback verifies the target state hash before restoring to ensure newer legitimate changes are not blindly overwritten.

### 8. Clear Product Boundary Disclaimer
WP ACF JSON Pro's internal snapshots protect ACF field group configurations. They are not a replacement for a full WordPress site/database backup. Developers should maintain regular backups of their WordPress database, media, and server files.

### 9. Read-Only Guard
Lock down production environments completely against UI and API mutations while keeping previews active:
```php
// In wp-config.php
define( 'ACFJP_READ_ONLY', true );
```

---

## AI Workflow: Zero API Keys, Zero Vendor Lock-in

WP ACF JSON Pro does not call external AI APIs directly. Instead, it publishes an interactive prompt builder that equips whichever model you use (ChatGPT, Claude, Gemini, Cursor, Copilot) with your site's exact ACF structure:

1. **Select Field Group**: The plugin embeds your live field names, keys, and types into the prompt.
2. **Describe Intent**: e.g., *"Add a repeater named 'Client Reviews' with rating (number 1-5), reviewer name, and testimonial quote"*.
3. **Generate Prompt**: Click to copy with 1-click launcher buttons for ChatGPT, Claude, and Gemini.
4. **Paste AI Reply**: The plugin automatically strips markdown code fences (````json ... ````), formats the payload, and previews the diff instantly.

---

## REST API Reference

All operations are fully accessible over REST under `/wp-json/wp-acf-json-pro/v1`.

### Authentication
- **Browser/Admin UI**: Standard WordPress REST Nonce (`X-WP-Nonce: wp_create_nonce('wp_rest')`).
- **External Scripts / CI/CD**: WordPress Application Passwords or Basic Auth with `manage_options` capability.

### Endpoints

| Endpoint | Method | Description |
|---|---|---|
| `/wp-json/wp-acf-json-pro/v1/validate` | `POST` | Parse and schema-validate JSON without reading ACF state. |
| `/wp-json/wp-acf-json-pro/v1/plan` | `POST` | Resolve target, compute ChangeSet, calculate diff, and return plan. |
| `/wp-json/wp-acf-json-pro/v1/apply` | `POST` | Apply a planned changeset by `plan_id` with optional conflict resolutions. |
| `/wp-json/wp-acf-json-pro/v1/prompt` | `GET` | Generate an AI-optimized prompt for a specific field group. |
| `/wp-json/wp-acf-json-pro/v1/field-groups` | `GET` | List all field groups, their mutability classification, and available field types. |
| `/wp-json/wp-acf-json-pro/v1/field-groups/:key` | `GET` | Fetch raw field group tree and mutability report. |
| `/wp-json/wp-acf-json-pro/v1/history` | `GET` | Query change journal entries. |
| `/wp-json/wp-acf-json-pro/v1/history/:id/rollback`| `POST` | Roll back a historical change set. |
| `/wp-json/wp-acf-json-pro/v1/self-test` | `POST` | Run diagnostics and integration self-tests. |
| `/wp-json/wp-acf-json-pro/v1/schema` | `GET` | Retrieve the active JSON Schema definition. |

---

## WP-CLI Commands

Deploy configuration patches via terminal or automated CI/CD pipelines:

```bash
# Calculate and view diff plan for a JSON patch
wp acf-json plan /path/to/patch.json

# Apply a patch (prompts for confirmation if destructive)
wp acf-json apply /path/to/patch.json

# Apply in non-interactive CI/CD with explicit confirmation
wp acf-json apply /path/to/patch.json --confirm --yes

# Export a field group as clean JSON
wp acf-json export "Company Profile" --output=./acf-exports/company.json

# View change history
wp acf-json history --limit=10

# Roll back a change by journal entry ID
wp acf-json rollback 42

# Run diagnostic self-test
wp acf-json self-test
```

---

## Extensibility & Developer Hooks

### Filters

```php
// Change required capability (default: 'manage_options')
add_filter( 'acfjp/capability', function( string $cap ): string {
    return 'edit_theme_options';
} );

// Enforce read-only mode programmatically
add_filter( 'acfjp/read_only', function( bool $readOnly ): bool {
    return wp_get_environment_type() === 'production';
} );

// Customize journal snapshot retention limit (default: 50)
add_filter( 'acfjp/journal_retention_limit', function( int $limit ): int {
    return 100;
} );
```

---

## Testing & Quality Gates

The plugin includes a comprehensive test matrix adhering to WordPress Core standards and PHP 8.1+ strict typing:

```bash
# Run PHPUnit unit & invariant test suite (95 tests, 186 assertions)
vendor/bin/phpunit --testsuite unit

# Run PHPStan Level 8 static analysis across all 70 plugin files
vendor/bin/phpstan analyse

# Run WordPress Coding Standards (WPCS) linting
vendor/bin/phpcs
```

---

## Installation & Quickstart

1. Download or clone this repository into your WordPress plugins directory:
   ```bash
   cd wp-content/plugins/
   git clone https://github.com/xaviermsd/wp-json-pro.git wp-acf-json-pro
   ```
2. Ensure **Advanced Custom Fields** (Free or PRO) is active.
3. Activate **WP ACF JSON Pro** in **Plugins -> Installed Plugins** (or `wp plugin activate wp-acf-json-pro`).
4. Navigate to **ACF JSON Pro** in your WordPress admin menu.
5. Use the **AI Generator** or paste JSON into the **JSON Editor** to preview and apply your first configuration patch!

---

## License

WP ACF JSON Pro is open-source software licensed under the [GNU General Public License v2 or later](https://www.gnu.org/licenses/gpl-2.0.html).
