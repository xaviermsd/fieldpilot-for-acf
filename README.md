# WP ACF JSON Pro

> **A declarative, target-scoped configuration patch engine for Advanced Custom Fields (ACF Free & PRO).**  
> Diff before you apply. Snapshot before you write. Roll back whenever you need. Zero runtime dependencies.

[![Version](https://img.shields.io/badge/Version-1.0.11-blue.svg?style=flat-square)](https://github.com/xaviermsd/wp-json-pro)
[![PHP Version](https://img.shields.io/badge/PHP-8.1%20--%208.4-777bb4.svg?style=flat-square&logo=php&logoColor=white)](https://www.php.net/)
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
  - [In-Memory Diff Simulation & Target Scope Breadcrumbs](#1-in-memory-diff-simulation--target-scope-breadcrumbs)
  - [Pre-Apply Safety Checklist & Interactive Guard](#2-pre-apply-safety-checklist--interactive-guard)
  - [1-Click ACF Configuration JSON Export](#3-1-click-acf-configuration-json-export)
  - [Optimistic Concurrency Fingerprinting](#4-optimistic-concurrency-fingerprinting)
  - [Pre-Mutation Snapshotting & Automatic Compensation](#5-pre-mutation-snapshotting--automatic-compensation)
  - [Post-Apply Verification & Untouched Sibling Protection](#6-post-apply-verification--untouched-sibling-protection)
  - [Validated Rollback & Audit Journal](#7-validated-rollback--audit-journal)
  - [Clear Product Boundary Disclaimer](#8-clear-product-boundary-disclaimer)
  - [Read-Only Guard](#9-read-only-guard)
- [Interactive Admin Workflow (3-Tab Navigation)](#interactive-admin-workflow-3-tab-navigation)
  - [Tab 1: AI Prompt Builder & Custom Field Generator](#tab-1-ai-prompt-builder--custom-field-generator)
  - [Tab 2: JSON Editor & Direct Import](#tab-2-json-editor--direct-import)
  - [Tab 3: Schema & Operations Guide](#tab-3-schema--operations-guide)
- [Interactive Custom Field Builder & Multi-Field Workflow](#interactive-custom-field-builder--multi-field-workflow)
- [The 4 ACF Tabs & JSON Property Mapping Guide](#the-4-acf-tabs--json-property-mapping-guide)
  - [1. General Tab](#1-general-tab)
  - [2. Validation Tab](#2-validation-tab)
  - [3. Presentation Tab](#3-presentation-tab)
  - [4. Conditional Logic Tab](#4-conditional-logic-tab)
- [Complete 36 ACF Field Types Reference](#complete-36-acf-field-types-reference)
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
   Pre-Mutation Snapshot (SnapshotStore: saves complete prior field tree before any write)
      │
      ▼
   Apply Mutation (Writer: official ACF APIs only, ordered parent-first mapping)
      │  └── If write fails: Automatic immediate snapshot restoration
      ▼
   Post-Apply Verification (Verifier: re-reads stored ACF state, asserts diff integrity)
      │
      ▼
   Audit Journal Recorded (Timestamped event with 1-click rollback available)
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
- **Target Isolation Guarantee**: Indicates that mutations are strictly isolated to the target locus and all unrelated sibling branches remain protected and untouched.
- **Categorized Change Chips**: Summary metrics (`+ Added`, `~ Modified`, `- Deleted`, `🛡️ Protected Outside Target: 0 touched`).
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
- Before any database write begins, the engine creates a full, byte-compatible internal snapshot of the ACF configuration including all fields and subfields.
- If `Writer::write()` encounters any unexpected error or exception, the engine triggers immediate automatic rollback (`Engine::rollbackAfterFailure()`) and restores the pre-mutation snapshot.

### 6. Post-Apply Verification & Untouched Sibling Protection
After writing, `Verifier::verify()` re-reads the raw data directly from ACF and verifies:
- All updated properties match the plan.
- All new fields and subfields are present with correct keys and parents.
- All deleted fields are gone.
- Flexible Content layout bindings (`parent_layout`, `menu_order`) are intact.
- **Sibling branches outside the target locus are verified to be byte-identical.**

### 7. Validated Rollback & Audit Journal
Every change is recorded in the journal table (`wp_acfjp_journal`). You can inspect past diffs and restore previous states with 1 click from **ACF JSON Pro -> History** or via `wp acfjp rollback <id>`. Rollback restores complete field trees byte-for-byte.

### 8. Clear Product Boundary Disclaimer
WP ACF JSON Pro's internal snapshots protect ACF field group configurations. They are not a replacement for a full WordPress site/database backup. Developers should maintain regular backups of their WordPress database, media, and server files.

### 9. Read-Only Guard
Lock down production environments completely against UI and API mutations while keeping previews active:
```php
// In wp-config.php
define( 'ACFJP_READ_ONLY', true );
```

---

## Interactive Admin Workflow (3-Tab Navigation)

The main admin interface (**ACF JSON Pro -> Import JSON**) features a segmented 3-tab layout synchronized with URL hashes (`#ai`, `#editor`, `#guide`):

### Tab 1: AI Prompt Builder & Custom Field Generator (Default)
- Zero external API keys needed; zero monthly cost.
- **Target Field Group Selector**: Embeds your live field names, keys, and types directly into the prompt so the AI never hallucinates non-existent field names.
- **Interactive 4-Tab Custom Field Builder**: Configure General, Validation, Presentation, and Conditional Logic across all 36 ACF types and click `+ Append Field to Queue`.
- **Bulk Multi-Field Quick Add**: Click quick chips (`+ Text`, `+ Repeater`, `+ Image`, `+ WYSIWYG`, `+ Select`) to append multiple field specifications on separate lines without overwriting.
- **Smart Step Guidance & Animations**: Pulsing ready states guide developers seamlessly from queuing fields $\to$ generating the AI prompt $\to$ launching the AI assistant $\to$ pasting back the response.
- **1-Click AI Launchers**: Direct new-tab links to ChatGPT, Claude, Gemini, and Cursor.
- **"Paste AI Response & Switch to Editor"**: Strips markdown code fences (````json ... ````) and jumps straight to the preview.

### Tab 2: JSON Editor & Direct Import
- Monospace JSON editor with real-time linting (CodeMirror integrated).
- **1-Click "Paste from Clipboard"** button.
- **"✨ Insert Template..."** picker with categorized templates covering **all 36 ACF field types** and common patch operations (`Add New Field`, `Update Existing Field`, `Add Repeater`, `Create New Field Group`, etc.).
- `.json` file uploader.
- Instant in-memory **"Preview Changes"** and **"Validate Only"** actions with standardized button alignments.
- Interactive list of all database field groups with 1-click key copy buttons.

### Tab 3: Schema & Operations Guide
- Visual reference grid explaining all 8 patch operations (`add`, `update`, `create`, `move`, `delete`, `merge`, `sync`, `replace`) with safety ratings and behavior.

---

## Interactive Custom Field Builder & Multi-Field Workflow

The **Custom Field Builder** in Tab 1 allows developers and content architects to construct multi-field patch specifications without writing JSON by hand:

1. **Exact Custom Field Names**: Enter project-specific field labels/names (e.g. `Featured Hero Video`, `Director Bio`, `Corporate Brochure`). The engine normalizes labels to valid ACF identifiers (`^[a-z_][a-z0-9_]*$`).
2. **All 36 ACF Field Types Across 6 Categories**: Select from all 36 ACF field types organized by category (`Basic & Text`, `Content & Media`, `Choice Fields`, `Relational & Objects`, `Layout & Structure`, `jQuery & Pickers`).
3. **4-Tab Deep Configuration**: Set General parameters (return format, choices, sub-fields, layouts), Validation constraints (required, min/max, mime types), Presentation settings (width, instructions, placeholder, prepend/append), and Conditional Logic rules.
4. **Bulk Multi-Field Quick Append**: Click `+ Append Field to Queue` or press `Enter` to add multiple specifications to the intent box line by line without overwriting previous selections.
5. **Dynamic Step Transitions**: Button states illuminate when fields are queued to direct the user toward generating the prompt.
6. **1-Click AI Launchers**: Generate an AI-optimized prompt and send it to **ChatGPT**, **Claude**, **Gemini**, or **Cursor** with one click.
7. **Paste AI Response & Switch**: Paste the generated JSON directly into the importer; code fences (````json ... ````) are stripped automatically and diff preview is triggered instantly.

---

## The 4 ACF Tabs & JSON Property Mapping Guide

In ACF's native admin modal, every field's configuration is divided into **4 distinct tabs**: **General**, **Validation**, **Presentation**, and **Conditional Logic**. WP ACF JSON Pro maps these 1-to-1 into declarative JSON properties.

### 1. General Tab
Defines the field's fundamental identity, data storage, and return shape.

| ACF UI Setting | JSON Key | Type | Description / Accepted Values |
|---|---|---|---|
| Field Label | `label` | string | The human-readable label shown in the admin editor. |
| Field Name | `name` | string | The programmatic meta key (`^[a-z_][a-z0-9_]*$`). |
| Field Type | `type` | string | Any of the 36 supported ACF field types. |
| Default Value | `default_value` | mixed | Initial value for new posts/terms. |
| Return Format | `return_format` | string | Format returned by `get_field()` (e.g. `'array'`, `'url'`, `'id'`, `'object'`). |
| Choices | `choices` | object | Key-value options for select, checkbox, radio, button_group (e.g. `{"draft": "Draft", "published": "Published"}`). |
| Sub Fields | `sub_fields` | array | Array of nested field definitions for `repeater` and `group`. |
| Layouts | `layouts` | array | Array of layout definitions for `flexible_content`. |
| Button Label | `button_label` | string | Custom button text (e.g. `"Add Slide"`, `"Add Section"`). |

### 2. Validation Tab
Defines data validation constraints enforced before post saving.

| ACF UI Setting | JSON Key | Type | Description / Accepted Values |
|---|---|---|---|
| Required? | `required` | int / bool | `1` (true) or `0` (false). Prevents saving empty values. |
| Character Limit | `maxlength` | int | Maximum character count for text and textarea fields. |
| Minimum Value | `min` | int / float | Minimum allowable value for number, range, gallery, or relationship. |
| Maximum Value | `max` | int / float | Maximum allowable value for number, range, gallery, or relationship. |
| Step Size | `step` | int / float | Step increment for number and range fields. |
| Allowed File Types | `mime_types` | string | Comma-separated list of file extensions (e.g. `"jpg,jpeg,png,webp,pdf"`). |
| Minimum Dimensions | `min_width`, `min_height` | int | Minimum pixel dimensions for images. |
| Maximum Dimensions | `max_width`, `max_height` | int | Maximum pixel dimensions for images. |
| File Size Limit | `min_size`, `max_size` | string / int | Minimum/maximum file size (e.g. `"2MB"`, `"500KB"`). |

### 3. Presentation Tab
Controls admin visual styling, width, column wrappers, and instructional copy.

| ACF UI Setting | JSON Key | Type | Description / Accepted Values |
|---|---|---|---|
| Instructions | `instructions` | string | Contextual guidance shown to editors below the field label. |
| Placeholder Text | `placeholder` | string | Placeholder shown inside text, number, email, url, or select inputs. |
| Prepend Text | `prepend` | string | Visual prefix inside the input field (e.g. `"$"` or `"https://"`). |
| Append Text | `append` | string | Visual suffix inside the input field (e.g. `"USD"`, `"%"`, or `"px"`). |
| Number of Rows | `rows` | int | Visual line height for textarea fields (e.g. `4`, `8`). |
| New Lines Handling | `new_lines` | string | Formatting for textareas: `'wpautop'`, `'br'`, or `''`. |
| Wrapper Attributes | `wrapper` | object | Container DOM styling: `{"width": "50", "class": "custom-col", "id": "custom-id"}`. |

### 4. Conditional Logic Tab
Controls dynamic display rules based on values of other fields within the same group.

| ACF UI Setting | JSON Key | Type | Description / Accepted Values |
|---|---|---|---|
| Conditional Rules | `conditional_logic` | array | 2D array of rule groups (AND within group, OR between groups). |

#### Conditional Logic JSON Structure:
```json
"conditional_logic": [
  [
    {
      "field": "display_hero_banner",
      "operator": "==",
      "value": "1"
    },
    {
      "field": "hero_type",
      "operator": "==",
      "value": "video"
    }
  ]
]
```

---

## Complete 36 ACF Field Types Reference

WP ACF JSON Pro provides complete first-class support for all 36 ACF field types across Free and PRO editions:

| Category | Type | Common General Settings | Validation Settings | Presentation Settings |
|---|---|---|---|---|
| **Basic & Text** | `text` | `default_value` | `required`, `maxlength` | `placeholder`, `prepend`, `append`, `wrapper` |
| | `textarea` | `default_value`, `new_lines` | `required`, `maxlength` | `rows`, `placeholder`, `wrapper` |
| | `number` | `default_value`, `step` | `required`, `min`, `max` | `placeholder`, `prepend`, `append`, `wrapper` |
| | `range` | `default_value`, `step` | `required`, `min`, `max` | `prepend`, `append`, `wrapper` |
| | `email` | `default_value` | `required` | `placeholder`, `prepend`, `append`, `wrapper` |
| | `url` | `default_value` | `required` | `placeholder`, `wrapper` |
| | `password` | `default_value` | `required` | `placeholder`, `wrapper` |
| **Content & Media** | `wysiwyg` | `toolbar`, `media_upload`, `tabs`, `delay` | `required` | `instructions`, `wrapper` |
| | `image` | `return_format`, `preview_size`, `library` | `required`, `min_width`, `max_width`, `min_height`, `max_height`, `min_size`, `max_size`, `mime_types` | `instructions`, `wrapper` |
| | `file` | `return_format`, `library` | `required`, `min_size`, `max_size`, `mime_types` | `instructions`, `wrapper` |
| | `gallery` | `return_format`, `library`, `insert` | `required`, `min`, `max`, `min_width`, `max_width`, `min_size`, `max_size`, `mime_types` | `instructions`, `wrapper` |
| | `oembed` | `width`, `height` | `required` | `instructions`, `wrapper` |
| | `icon_picker` | `return_format` | `required` | `instructions`, `wrapper` |
| **Choice Fields** | `select` | `choices`, `default_value`, `allow_null`, `multiple`, `ui`, `ajax` | `required` | `placeholder`, `instructions`, `wrapper` |
| | `checkbox` | `choices`, `default_value`, `layout`, `toggle`, `allow_custom`, `save_custom` | `required` | `instructions`, `wrapper` |
| | `radio` | `choices`, `default_value`, `other_choice`, `save_other_choice`, `layout` | `required` | `instructions`, `wrapper` |
| | `button_group` | `choices`, `default_value`, `allow_null`, `layout` | `required` | `instructions`, `wrapper` |
| | `true_false` | `default_value`, `ui`, `ui_on_text`, `ui_off_text`, `message` | `required` | `instructions`, `wrapper` |
| **Relational & Objects** | `link` | `return_format` (`array`, `url`) | `required` | `instructions`, `wrapper` |
| | `post_object` | `post_type`, `taxonomy`, `return_format`, `multiple`, `allow_null` | `required` | `instructions`, `wrapper` |
| | `page_link` | `post_type`, `taxonomy`, `allow_null`, `multiple`, `allow_archives` | `required` | `instructions`, `wrapper` |
| | `relationship` | `post_type`, `taxonomy`, `filters`, `return_format` | `required`, `min`, `max` | `instructions`, `wrapper` |
| | `taxonomy` | `taxonomy`, `field_type`, `add_term`, `save_terms`, `load_terms`, `return_format` | `required` | `instructions`, `wrapper` |
| | `user` | `role`, `return_format`, `multiple`, `allow_null` | `required` | `instructions`, `wrapper` |
| **Layout & Structure** | `repeater` | `layout`, `button_label`, `collapsed`, `sub_fields` | `required`, `min`, `max` | `instructions`, `wrapper` |
| | `group` | `layout`, `sub_fields` | `required` | `instructions`, `wrapper` |
| | `flexible_content` | `button_label`, `layouts` (`[ { name, label, display, sub_fields } ]`) | `required`, `min`, `max` | `instructions`, `wrapper` |
| | `accordion` | `open`, `multi_expand`, `endpoint` | N/A | `instructions`, `wrapper` |
| | `tab` | `placement`, `endpoint` | N/A | `instructions`, `wrapper` |
| | `message` | `message`, `new_lines`, `esc_html` | N/A | `instructions`, `wrapper` |
| | `clone` | `clone` (`["field_key"]`), `display`, `prefix_label`, `prefix_name` | N/A | `instructions`, `wrapper` |
| **jQuery & Pickers** | `google_map` | `center_lat`, `center_lng`, `zoom`, `height` | `required` | `instructions`, `wrapper` |
| | `date_picker` | `display_format`, `return_format`, `first_day` | `required` | `placeholder`, `instructions`, `wrapper` |
| | `date_time_picker` | `display_format`, `return_format`, `first_day` | `required` | `placeholder`, `instructions`, `wrapper` |
| | `time_picker` | `display_format`, `return_format` | `required` | `placeholder`, `instructions`, `wrapper` |
| | `color_picker` | `default_value`, `enable_opacity` | `required` | `instructions`, `wrapper` |

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
wp acfjp plan /path/to/patch.json

# Apply a patch (prompts for confirmation if destructive)
wp acfjp apply /path/to/patch.json

# Apply in non-interactive CI/CD with explicit confirmation
wp acfjp apply /path/to/patch.json --confirm --yes

# Export a field group as clean JSON
wp acfjp export "Company Profile" --output=./acf-exports/company.json

# View change history
wp acfjp history --limit=10

# Roll back a change by journal entry ID
wp acfjp rollback 42

# Run diagnostic self-test
wp acfjp self-test
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
