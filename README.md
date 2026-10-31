# FieldPilot - AI & JSON Copilot for ACF

> **A declarative, target-scoped configuration patch engine for Advanced Custom Fields (ACF free and PRO).**  
> Diff before you apply. Snapshot before you write. Roll back when you need to. Zero runtime dependencies.

[![Version](https://img.shields.io/badge/Version-1.1.0-blue.svg?style=flat-square)](https://github.com/xaviermsd/fieldpilot-for-acf)
[![PHP Version](https://img.shields.io/badge/PHP-8.1%2B-777bb4.svg?style=flat-square&logo=php&logoColor=white)](https://www.php.net/)
[![WordPress](https://img.shields.io/badge/WordPress-6.5%20--%207.1-21759b.svg?style=flat-square&logo=wordpress&logoColor=white)](https://wordpress.org/)
[![ACF Compatibility](https://img.shields.io/badge/ACF%20%2F%20PRO-6.2%2B-00a32a.svg?style=flat-square)](https://www.advancedcustomfields.com/)
[![Static Analysis](https://img.shields.io/badge/PHPStan-Level%208%20(0%20errors)-00a32a.svg?style=flat-square)](https://phpstan.org/)
[![Tests](https://img.shields.io/badge/PHPUnit-110%20unit%20%2B%2024%20integration%20tests-brightgreen.svg?style=flat-square)](https://phpunit.de/)
[![License](https://img.shields.io/badge/License-GPLv2%2B-blue.svg?style=flat-square)](https://www.gnu.org/licenses/gpl-2.0.html)

---

## Table of Contents

- [Why FieldPilot?](#why-fieldpilot)
- [The Mental Model & Architecture](#the-mental-model--architecture)
- [Target Resolution Engine](#target-resolution-engine)
- [Supported JSON Patch Operations](#supported-json-patch-operations)
  - [1. add (Safe)](#1-add-safe)
  - [2. update (Safe - Partial Patch)](#2-update-safe---partial-patch)
  - [3. create (Safe - New Field Group)](#3-create-safe---new-field-group)
  - [4. delete (Destructive)](#4-delete-destructive)
  - [5. move (Caution - Relocate / Reorder)](#5-move-caution---relocate--reorder)
  - [6. merge (Additive - Nothing Deleted)](#6-merge-additive---nothing-deleted)
  - [7. sync (Caution - Match the Payload)](#7-sync-caution---match-the-payload)
  - [8. replace (Destructive - Full Rebuild)](#8-replace-destructive---full-rebuild)
- [Defense-in-Depth Safety Systems](#defense-in-depth-safety-systems)
  - [Rollback & Audit Journal](#7-rollback--audit-journal)
  - [Clear Product Boundary Disclaimer](#8-clear-product-boundary-disclaimer)
  - [Read-Only Guard](#9-read-only-guard)
- [Interactive Admin Workflow (3-Tab Navigation)](#interactive-admin-workflow-3-tab-navigation)
  - [Tab 1: Generate with AI](#tab-1-generate-with-ai-default)
  - [Tab 2: JSON Editor & Import](#tab-2-json-editor--import)
  - [Tab 3: Schema & Operations Guide](#tab-3-schema--operations-guide)
- [Field Types That Follow Your ACF](#field-types-that-follow-your-acf)
- [Interactive Custom Field Builder & Multi-Field Workflow](#interactive-custom-field-builder--multi-field-workflow)
- [The 4 ACF Tabs & JSON Property Mapping Guide](#the-4-acf-tabs--json-property-mapping-guide)
  - [1. General Tab](#1-general-tab)
  - [2. Validation Tab](#2-validation-tab)
  - [3. Presentation Tab](#3-presentation-tab)
  - [4. Conditional Logic Tab](#4-conditional-logic-tab)
- [ACF Field Types Reference](#acf-field-types-reference)
- [Visual Tour & Screenshots](#visual-tour--screenshots)
- [REST API Reference](#rest-api-reference)
- [WP-CLI Commands](#wp-cli-commands)
- [Extensibility & Developer Hooks](#extensibility--developer-hooks)
- [Testing & Quality Gates](#testing--quality-gates)
- [Installation & Quickstart](#installation--quickstart)

---

## Why FieldPilot?

For years, authoring and maintaining complex ACF field groups meant clicking through the WordPress admin UI, one field at a time. Native ACF JSON exports exist, but importing them through standard tools is an **all-or-nothing full replacement** that wipes un-exported settings, risks orphan keys, and cannot apply surgical updates.

Meanwhile, modern engineering teams and AI models (ChatGPT, Claude, Gemini, Cursor, Copilot) write JSON configurations effortlessly. The bottleneck was never *generating* JSON - it was **safely validating, scoping, simulating, and applying targeted patches**.

**FieldPilot is a configuration patch engine.** It works like a compiler pipeline:
1. **Target-Scoped**: Modify a single sub-field deep inside a Repeater or Flexible Content layout without touching adjacent branches.
2. **Partial Patches**: Update `required` and `instructions` on one field; every other setting and every sibling field stays as it was, and FieldPilot checks that after every write.
3. **Stable Identity**: Existing ACF field keys (`field_64abc123`) are kept, so the content stored against them stays connected.
4. **Defense-in-Depth**: Preview diffs in memory, detect conflicts, snapshot before writing, and verify afterwards with automatic rollback on any mismatch.

---

## The Mental Model & Architecture

FieldPilot treats an ACF Field Group as a **hierarchical configuration tree**, not a flat database table.

```
JSON Request (Payload / Native ACF Export)
      │
      ▼
   Parse & Normalize (Maps AI aliases, removes code fences, validates syntax)
      │
      ▼
   Resolve Root Field Group (GroupLocator: mutability check, boundary lock)
      │
      ▼
   Build In-Memory ACF Field Tree (TreeReader: hierarchical fields, sub-fields, layouts)
      │
      ▼
   Resolve Exact Target Node (TargetResolver: key -> scoped name -> path -> unique label)
      │
      ▼
   Calculate Scoped Diff (Comparator: calculates ChangeSet relative to Target Locus)
      │
      ▼
   Plan Store & State Fingerprint (SHA-256 stateHash of the current tree)
      │
      ▼
   [PREVIEW DIFF / DRY-RUN] (Displays additions, updates, removals, risk ratings)
      │
      ▼
   Guard Check (stateHash match, capability, read-only mode, apply lock, confirmation)
      │
      ▼
   Pre-Write Snapshot (SnapshotStore: saves the complete prior field tree before any write)
      │
      ▼
   Apply (Writer: official ACF APIs only, parents before children)
      │  └── If anything fails from here on: the snapshot is restored automatically
      ▼
   Post-Apply Verification (Verifier: re-reads stored ACF state, checks every change and every other field)
      │
      ▼
   Audit Journal Recorded (Timestamped entry; roll back from History, newest first)
```

---

## Target Resolution Engine

The target resolution subsystem (`ACFJP\Resolve\TargetResolver`) makes sure an operation applies **only to its intended locus** and never changes sibling subtrees.

### Resolution Priority

Applied in order, never mixed:

1. **Exact ACF Field Key** (`field_64abc123`): Unambiguous by construction, and checked to belong to the active tree.
2. **Field Name within Scope** (`phone` under `contact_information`): What ACF itself uses to address a field.
3. **Hierarchical Path** (`contact_information.phone` or `Contact > Address > City`): Walked segment by segment from root to leaf, by label or name.
4. **Unique Human Label**: Case-insensitive; accepted only when exactly one field matches within the scope.
5. **Otherwise: an error.**

### Hard-Stop Ambiguity Handling

If a requested target matches multiple fields (for example, two sub-fields named `status` under different groups), the engine **hard-stops** with an `AMBIGUOUS_TARGET` error:
- Lists every matching candidate with its full path and ACF key.
- **Zero Guessing**: Never falls back to the root, never picks the first match, and never reorders fields. A target field that does not exist is an error too.

### Deep Container Support

- **Group & Repeater**: Act as container loci for child additions, updates, and reordering.
- **Flexible Content & Layouts**: Targets can name a specific layout with `"layout": "hero_banner"`. New layouts only ever go into the flexible content field you target.
- **Clone Fields**: Traversal is guarded (`TRAVERSES_CLONE`). A field reached through a clone belongs to another group and must be patched in its home definition.

---

## Supported JSON Patch Operations

Every operation is described by the JSON Schema in [`schemas/fieldpilot-for-acf-v1.json`](schemas/fieldpilot-for-acf-v1.json), which is also served at `/wp-json/fieldpilot-for-acf/v1/schema` and printed by `wp acfjp schema`.

### 1. `add` (Safe)
Appends new fields or sub-fields to the specified target. Existing fields stay untouched.

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
Changes **only the settings named**. Settings you leave out keep their current values. `changes` names each field first, then its settings; field group settings go in `group_changes`.

```json
{
  "version": "1.0",
  "operation": "update",
  "target": {
    "field_group": "Company Profile",
    "path": ["contact_info"]
  },
  "changes": {
    "email": {
      "required": 1,
      "instructions": "Official business email address."
    }
  }
}
```

### 3. `create` (Safe - New Field Group)
Builds a brand-new field group and all of its fields from scratch. Rolling it back moves the new group to the trash.

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
Removes the named fields. A pre-flight scan looks for stored content first, including values inside repeaters, and a delete that would orphan content needs explicit confirmation.

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
Relocates existing fields within or across containers while keeping their ACF field keys. A move without a destination stays in its current container.

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

### 6. `merge` (Additive - Nothing Deleted)
Adds the fields the JSON has that the target does not, updates the settings the JSON names on fields that already exist, and never deletes anything.

### 7. `sync` (Caution - Match the Payload)
Makes the field group match the JSON payload: adds new fields, updates changed ones and removes the ones the payload leaves out.

### 8. `replace` (Destructive - Full Rebuild)
Rebuilds the target from the payload. Fields that match keep their keys; fields the payload leaves out are removed.

---

## Defense-in-Depth Safety Systems

FieldPilot is built around a layered safety model, so configuration changes are transparent, predictable and checked.

### 1. In-Memory Diff Simulation & Target Scope Breadcrumbs
Every operation is dry-run through `Engine::plan()`. The preview shows:
- **Hierarchical Target Breadcrumb**: `Root Field Group > Target Container (Repeater / Flexible Content / Group)`.
- **Target Isolated badge**: The change stays inside its target, and the check after the write confirms nothing outside it moved.
- **Change Summary Chips**: `+ N added`, `~ N modified`, `- N deleted` and `Protected outside target: 0 touched`.
- **Proportional Risk Ratings**:
  - **`safe`** (Green): Field additions and setting updates that cannot lose content.
  - **`caution`** (Yellow): Moves, location rule changes, type changes and removals with no stored content.
  - **`destructive`** (Red): Changes that would orphan stored content, such as deleting a field or layout that holds data, or a type change that alters how existing values are stored.

### 2. Pre-Apply Safety Checklist & Interactive Guard
Before anything is written, you explicitly acknowledge the operation:
- `[x] I have reviewed the target scope and diff above.`
- `[x] I understand this operation will write changes to the ACF database.`
- `[x] I acknowledge that this operation contains destructive modifications or deletions.` *(shown for destructive changes)*

The **Apply Changes to Database** button stays disabled until every required box is checked.

### 3. One-Click Configuration Export
An **Export Current Configuration (JSON)** button in the preview toolbar downloads the field group as it is right now (`acf-export-{groupKey}-{date}.json`), so you can commit it to Git before applying anything.

### 4. Optimistic Concurrency Fingerprinting
During `plan()`, a SHA-256 fingerprint (`stateHash`) of the current field group is recorded. When the plan is applied:
- If another developer, deployment or background process changed the group between preview and apply, the check fails with `STATE_CHANGED`.
- Two applies at the same moment cannot both write: the second one is refused.

### 5. Pre-Write Snapshot & Automatic Compensation
- Before the first write, the engine takes a full snapshot of the field group, every field and sub-field included.
- If the write, the verification, the after-snapshot or the history entry fails, the snapshot is restored automatically and the batch is recorded as undone. If the restore itself fails, the History entry says so and offers **Restore snapshot**.

### 6. Post-Apply Verification & Untouched Sibling Protection
After writing, `Verifier::verify()` re-reads the stored data from ACF and checks that:
- Every updated setting matches the plan.
- Every new field and sub-field is present with the right key and parent.
- Every deleted field and layout is gone, and every move landed where it should.
- Flexible Content layout bindings (`parent_layout`) are intact.
- **Every other field in the group is unchanged.**

### 7. Rollback & Audit Journal
Every change is recorded in the journal table (`wp_acfjp_journal`) with its snapshot. Roll back from **FieldPilot -> History** or with `wp acfjp rollback <id>`. Rollbacks go newest first, so rolling back an older change never wipes a later one. A rollback puts the field group back to its previous configuration, setting for setting.

### 8. Clear Product Boundary Disclaimer
FieldPilot's snapshots protect ACF field group configurations. They are not a replacement for a full WordPress site and database backup. Keep regular backups of your database, media and server files.

### 9. Read-Only Guard
Lock a production site against changes from the UI, REST and WP-CLI while previews keep working:
```php
// In wp-config.php
define( 'ACFJP_READ_ONLY', true );
```
Read-only mode can also be switched on under **FieldPilot -> Settings**, or with the `acfjp_read_only` filter.

---

## Interactive Admin Workflow (3-Tab Navigation)

The main admin screen (**FieldPilot -> Import JSON**) has three tabs, kept in sync with the URL hash (`#ai`, `#editor`, `#guide`):

### Tab 1: Generate with AI (Default)
- No API keys and no monthly cost.
- **Target Field Group Selector & Inline Creator**: Embeds your live field names, keys and types into the prompt so the AI stops inventing field names. Click **Add New Field Group** (it turns into **Close**) to create a new ACF field group in place; it is selected straight away and recorded in History.
- **Step 1 Gate**: The builder stays locked until a target group is selected, and the creation form stays open when no groups exist yet.
- **Inline Validation**: Soft red borders and clear messages for required field labels, empty group titles and numeric limits, cleared as you type.
- **4-Tab Custom Field Builder**: Configure General, Validation, Presentation and Conditional Logic for any field type your ACF offers, then click **Append Field to Queue**.
- **Quick-Add Chips**: `+ Repeater`, `+ Required Text`, `+ WYSIWYG`, `+ Image`, `+ Select Dropdown` and more add field requests on separate lines without overwriting. Chips for types your ACF cannot store are disabled.
- **2-Step AI Workflow**: Step 2A (**1. Copy Prompt to Clipboard**, plus links to open ChatGPT, Claude, Gemini or Cursor in a new tab), then Step 2B (**2. Paste AI Response & Switch to Editor**).
- **Paste AI Response & Switch to Editor**: Strips markdown code fences, switches to the editor and runs the preview.

### Tab 2: JSON Editor & Import
- **Side-by-Side Review Layout**: The JSON editor and its action bar on the left; the live diff review (target breadcrumb, summary chips, change list and Pre-Apply Safety Checklist) on the right.
- JSON editor built on WordPress's bundled CodeMirror, with line numbers and bracket matching.
- **Target Field Group Selector**: Choose which field group templates target, right from the editor toolbar.
- **Paste from Clipboard** button.
- **Insert a template...** picker with templates for the field types your ACF offers and for common operations (add, update, add a repeater, create a field group and more).
- **Pulsing Apply Button**: **Apply Changes to Database** lights up once the safety checklist is complete.
- `.json` file upload.
- **Preview Changes & Review Diff** and **Validate Only** actions.
- A list of every database field group, with click-to-copy group keys.

### Tab 3: Schema & Operations Guide
- A reference grid for all 8 operations (`add`, `update`, `create`, `move`, `delete`, `merge`, `sync`, `replace`) with their safety ratings and behavior, plus a field type guide that shows which types this site can use.

---

## Visual 10-Step Guided Onboarding Tour

FieldPilot includes a lightweight interactive tour with no external libraries:
- **Target Spotlight**: Walks new users through field group health on the Dashboard, field authoring, AI prompts, diff previews, conflict resolution and rollback in History.
- **Sample Rows**: On a fresh site with no field groups or history, sample rows show how things look without creating real records.
- **Restart Anytime**: Click **Restart Guided Tour** on the Dashboard or the Settings screen.

---

## Settings & WordPress.org Compliant Uninstall

FieldPilot follows the WordPress.org plugin directory guidelines:
- **No Deactivation Interception**: Deactivating the plugin is never blocked, interrupted or redirected.
- **Your Data, Your Call**: Under **FieldPilot -> Settings -> On uninstall**, tick **Delete all FieldPilot data when the plugin is deleted** to have it cleaned up. It is off by default, so reinstalling brings your history back.
- **Scoped `uninstall.php`**: When that box is ticked, deleting the plugin removes FieldPilot's own settings, history and snapshot tables, temporary previews, guided tour state and scheduled cleanup. Your field groups belong to ACF and are never touched. On multisite, each site's own setting decides for that site.

---

## Field Types That Follow Your ACF

The builder, quick-add chips, templates, field type guide and AI prompt all read from one list of the 36 ACF field types and follow the ACF edition installed:

- **ACF PRO**: all 36 types.
- **ACF free**: the 32 free types. Repeater, Flexible Content, Clone and Gallery are shown disabled with "Requires ACF PRO", and payloads that use them are refused with `FIELD_TYPE_REQUIRES_PRO`.
- **Icon Picker** needs ACF 6.3 or newer.
- On ACF 6.8 and newer, new fields are also checked against ACF's own field type schemas, and anything unexpected is flagged as a warning in the preview.

---

## Interactive Custom Field Builder & Multi-Field Workflow

The **Custom Field Builder** in Tab 1 lets developers and content architects build multi-field requests without writing JSON by hand:

1. **Your Own Field Names**: Enter project-specific labels (for example `Featured Hero Video`, `Director Bio`, `Corporate Brochure`). Names are normalized to valid ACF identifiers (`^[a-z_][a-z0-9_]*$`).
2. **Field Types in 6 Categories**: `Basic & Text`, `Content & Media`, `Choices`, `Relational`, `Layout & Structure` and `Pickers & Maps`, limited to what your ACF can store.
3. **4-Tab Configuration**: General settings (return format, choices, sub-fields, layouts), Validation (required, min/max, MIME types), Presentation (width, instructions, placeholder, prepend/append) and Conditional Logic rules.
4. **Multi-Field Queue**: Click **Append Field to Queue** or press `Enter` to add each field as its own line in the request, without overwriting earlier ones.
5. **Guided Next Steps**: Buttons light up once fields are queued, pointing you to the prompt.
6. **Links to Open Your AI**: Copy the generated prompt, then open **ChatGPT**, **Claude**, **Gemini** or **Cursor** in a new tab.
7. **Paste AI Response & Switch**: Paste the JSON reply into the importer; code fences are stripped and the diff preview runs straight away.

---

## The 4 ACF Tabs & JSON Property Mapping Guide

In ACF's own field editor, every field's settings are split across **4 tabs**: **General**, **Validation**, **Presentation** and **Conditional Logic**. FieldPilot maps them to JSON properties.

### 1. General Tab
Defines the field's identity, data storage and return shape.

| ACF UI Setting | JSON Key | Type | Description / Accepted Values |
|---|---|---|---|
| Field Label | `label` | string | The human-readable label shown in the admin editor. |
| Field Name | `name` | string | The meta key (`^[a-z_][a-z0-9_]*$`). |
| Field Type | `type` | string | Any ACF field type your edition provides. |
| Default Value | `default_value` | mixed | Initial value for new posts and terms. |
| Return Format | `return_format` | string | Format returned by `get_field()` (for example `'array'`, `'url'`, `'id'`, `'object'`). |
| Choices | `choices` | object | Key-value options for select, checkbox, radio and button_group (for example `{"draft": "Draft", "published": "Published"}`). |
| Sub Fields | `sub_fields` | array | Nested field definitions for `repeater` and `group`. |
| Layouts | `layouts` | array | Layout definitions for `flexible_content`. |
| Button Label | `button_label` | string | Custom button text (for example `"Add Slide"`, `"Add Section"`). |

### 2. Validation Tab
Defines the validation rules ACF enforces before saving.

| ACF UI Setting | JSON Key | Type | Description / Accepted Values |
|---|---|---|---|
| Required? | `required` | int / bool | `1` (true) or `0` (false). Prevents saving empty values. |
| Character Limit | `maxlength` | int | Maximum character count for text and textarea fields. |
| Minimum Value | `min` | int / float | Minimum for number, range, gallery or relationship. Decimals such as `0.01` are kept. |
| Maximum Value | `max` | int / float | Maximum for number, range, gallery or relationship. |
| Step Size | `step` | int / float | Step increment for number and range fields. |
| Allowed File Types | `mime_types` | string | Comma-separated file extensions (for example `"jpg,jpeg,png,webp,pdf"`). |
| Minimum Dimensions | `min_width`, `min_height` | int | Minimum pixel dimensions for images. |
| Maximum Dimensions | `max_width`, `max_height` | int | Maximum pixel dimensions for images. |
| File Size Limit | `min_size`, `max_size` | string / int | Minimum and maximum file size (for example `"2MB"`, `"500KB"`). |

### 3. Presentation Tab
Controls how the field looks in the admin: width, wrapper attributes and instructions.

| ACF UI Setting | JSON Key | Type | Description / Accepted Values |
|---|---|---|---|
| Instructions | `instructions` | string | Guidance shown to editors below the field label. |
| Placeholder Text | `placeholder` | string | Placeholder shown inside text, number, email, url or select inputs. |
| Prepend Text | `prepend` | string | Prefix inside the input (for example `"$"` or `"https://"`). |
| Append Text | `append` | string | Suffix inside the input (for example `"USD"`, `"%"` or `"px"`). |
| Number of Rows | `rows` | int | Visible lines for textarea fields (for example `4`, `8`). |
| New Lines Handling | `new_lines` | string | Formatting for textareas: `'wpautop'`, `'br'` or `''`. |
| Wrapper Attributes | `wrapper` | object | `{"width": "50", "class": "custom-col", "id": "custom-id"}`. Updating only the width keeps the class and id. |

### 4. Conditional Logic Tab
Shows or hides the field based on the values of other fields in the same group.

| ACF UI Setting | JSON Key | Type | Description / Accepted Values |
|---|---|---|---|
| Conditional Rules | `conditional_logic` | array | 2D array of rule groups (AND within a group, OR between groups). |

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

## ACF Field Types Reference

FieldPilot knows all 36 ACF field types. Types marked **PRO** need ACF PRO, and Icon Picker needs ACF 6.3 or newer.

| Category | Type | Common General Settings | Validation Settings | Presentation Settings |
|---|---|---|---|---|
| **Basic & Text** | `text` | `default_value` | `required`, `maxlength` | `placeholder`, `prepend`, `append`, `wrapper` |
| | `textarea` | `default_value`, `new_lines` | `required`, `maxlength` | `rows`, `placeholder`, `wrapper` |
| | `number` | `default_value`, `step` | `required`, `min`, `max` | `placeholder`, `prepend`, `append`, `wrapper` |
| | `range` | `default_value`, `step` | `required`, `min`, `max` | `prepend`, `append`, `wrapper` |
| | `email` | `default_value` | `required` | `placeholder`, `prepend`, `append`, `wrapper` |
| | `url` | `default_value` | `required` | `placeholder`, `wrapper` |
| | `password` | `default_value` | `required` | `placeholder`, `wrapper` |
| **Content & Media** | `image` | `return_format`, `preview_size`, `library` | `required`, `min_width`, `max_width`, `min_height`, `max_height`, `min_size`, `max_size`, `mime_types` | `instructions`, `wrapper` |
| | `file` | `return_format`, `library` | `required`, `min_size`, `max_size`, `mime_types` | `instructions`, `wrapper` |
| | `wysiwyg` | `toolbar`, `media_upload`, `tabs`, `delay` | `required` | `instructions`, `wrapper` |
| | `oembed` | `width`, `height` | `required` | `instructions`, `wrapper` |
| | `gallery` **PRO** | `return_format`, `library`, `insert` | `required`, `min`, `max`, `min_width`, `max_width`, `min_size`, `max_size`, `mime_types` | `instructions`, `wrapper` |
| | `icon_picker` (ACF 6.3+) | `return_format` | `required` | `instructions`, `wrapper` |
| **Choices** | `select` | `choices`, `default_value`, `allow_null`, `multiple`, `ui`, `ajax` | `required` | `placeholder`, `instructions`, `wrapper` |
| | `checkbox` | `choices`, `default_value`, `layout`, `toggle`, `allow_custom`, `save_custom` | `required` | `instructions`, `wrapper` |
| | `radio` | `choices`, `default_value`, `other_choice`, `save_other_choice`, `layout` | `required` | `instructions`, `wrapper` |
| | `button_group` | `choices`, `default_value`, `allow_null`, `layout` | `required` | `instructions`, `wrapper` |
| | `true_false` | `default_value`, `ui`, `ui_on_text`, `ui_off_text`, `message` | `required` | `instructions`, `wrapper` |
| **Relational** | `link` | `return_format` (`array`, `url`) | `required` | `instructions`, `wrapper` |
| | `post_object` | `post_type`, `taxonomy`, `return_format`, `multiple`, `allow_null` | `required` | `instructions`, `wrapper` |
| | `page_link` | `post_type`, `taxonomy`, `allow_null`, `multiple`, `allow_archives` | `required` | `instructions`, `wrapper` |
| | `relationship` | `post_type`, `taxonomy`, `filters`, `return_format` | `required`, `min`, `max` | `instructions`, `wrapper` |
| | `taxonomy` | `taxonomy`, `field_type`, `add_term`, `save_terms`, `load_terms`, `return_format` | `required` | `instructions`, `wrapper` |
| | `user` | `role`, `return_format`, `multiple`, `allow_null` | `required` | `instructions`, `wrapper` |
| **Layout & Structure** | `group` | `layout`, `sub_fields` | `required` | `instructions`, `wrapper` |
| | `repeater` **PRO** | `layout`, `button_label`, `collapsed`, `sub_fields` | `required`, `min`, `max` | `instructions`, `wrapper` |
| | `flexible_content` **PRO** | `button_label`, `layouts` (`[ { name, label, display, sub_fields } ]`) | `required`, `min`, `max` | `instructions`, `wrapper` |
| | `clone` **PRO** | `clone` (`["field_key"]`), `display`, `prefix_label`, `prefix_name` | N/A | `instructions`, `wrapper` |
| | `tab` | `placement`, `endpoint` | N/A | `instructions`, `wrapper` |
| | `accordion` | `open`, `multi_expand`, `endpoint` | N/A | `instructions`, `wrapper` |
| | `message` | `message`, `new_lines`, `esc_html` | N/A | `instructions`, `wrapper` |
| **Pickers & Maps** | `google_map` | `center_lat`, `center_lng`, `zoom`, `height` | `required` | `instructions`, `wrapper` |
| | `date_picker` | `display_format`, `return_format`, `first_day` | `required` | `placeholder`, `instructions`, `wrapper` |
| | `date_time_picker` | `display_format`, `return_format`, `first_day` | `required` | `placeholder`, `instructions`, `wrapper` |
| | `time_picker` | `display_format`, `return_format` | `required` | `placeholder`, `instructions`, `wrapper` |
| | `color_picker` | `default_value`, `enable_opacity` | `required` | `instructions`, `wrapper` |

---

## REST API Reference

Everything is available over REST under `/wp-json/fieldpilot-for-acf/v1`.

### Authentication
- **Browser/Admin UI**: Standard WordPress REST nonce (`X-WP-Nonce`).
- **External Scripts / CI/CD**: WordPress Application Passwords for a user with the `manage_options` capability (change it with the `acfjp_capability` filter).

### Endpoints

| Endpoint | Method | Description |
|---|---|---|
| `/wp-json/fieldpilot-for-acf/v1/validate` | `POST` | Parse and validate JSON without reading ACF state. |
| `/wp-json/fieldpilot-for-acf/v1/plan` | `POST` | Resolve the target, compute the ChangeSet and return the plan with its diff. |
| `/wp-json/fieldpilot-for-acf/v1/apply` | `POST` | Apply a plan by `plan_id`, with optional conflict resolutions and `confirm`. |
| `/wp-json/fieldpilot-for-acf/v1/prompt` | `GET`, `POST` | Generate an AI prompt for a field group (`group_key`, `intent`). |
| `/wp-json/fieldpilot-for-acf/v1/field-groups` | `GET` | List field groups with their mutability classification. |
| `/wp-json/fieldpilot-for-acf/v1/field-groups` | `POST` | Create an empty field group (`title`). It is recorded in History. |
| `/wp-json/fieldpilot-for-acf/v1/field-groups/:key` | `GET` | Fetch a field group tree (`dialect`: `native` or `acfjp`). |
| `/wp-json/fieldpilot-for-acf/v1/history` | `GET` | Query journal entries (`group_key`, `limit`, `offset`). |
| `/wp-json/fieldpilot-for-acf/v1/history/:id/rollback`| `POST` | Roll back a journal entry (newest first). |
| `/wp-json/fieldpilot-for-acf/v1/self-test` | `POST` | Run the diagnostics self-test. |
| `/wp-json/fieldpilot-for-acf/v1/schema` | `GET` | Retrieve the JSON Schema. |

---

## WP-CLI Commands

Apply configuration patches from a terminal or a CI/CD pipeline. Commands that write (`apply`, `create-group`, `rollback`, `self-test`) need a user, for example `--user=admin`. Run `wp help acfjp` for every option.

```bash
# Check a payload without touching ACF
wp acfjp validate /path/to/patch.json

# Show the diff a patch would make
wp acfjp plan /path/to/patch.json

# Apply a patch
wp acfjp apply /path/to/patch.json --user=admin

# Apply a patch that includes destructive changes
wp acfjp apply /path/to/patch.json --confirm --user=admin

# Create an empty field group
wp acfjp create-group "Case Studies" --user=admin

# Export a field group as clean JSON
wp acfjp export "Company Profile" > ./acf-exports/company.json

# List field groups and whether they can be patched
wp acfjp groups

# View change history
wp acfjp history --limit=10

# Roll back a change by journal entry ID (newest first)
wp acfjp rollback 42 --user=admin

# Generate an AI prompt for a field group
wp acfjp prompt --group="Company Profile" --intent="Add a WhatsApp number"

# Print the JSON Schema
wp acfjp schema

# Run the diagnostics self-test
wp acfjp self-test --user=admin
```

---

## Extensibility & Developer Hooks

### Filters

```php
// Change the required capability (default: 'manage_options').
add_filter( 'acfjp_capability', function ( string $cap ): string {
    return 'edit_theme_options';
} );

// Force read-only mode, for example on production.
add_filter( 'acfjp_read_only', function ( bool $read_only ): bool {
    return 'production' === wp_get_environment_type();
} );

// Turn the stored-content scan before risky changes on or off.
add_filter( 'acfjp_data_probe_enabled', '__return_true' );

// How long a preview stays applicable, in seconds (default: 900).
add_filter( 'acfjp_plan_ttl', fn () => 1800 );

// Maximum payload size in bytes (default: 8 MB).
add_filter( 'acfjp_max_payload_bytes', fn () => 16 * MB_IN_BYTES );

// Maximum nesting depth the tree reader descends (default: 32).
add_filter( 'acfjp_max_depth', fn () => 48 );

// Adjust the schema FieldPilot validates a field type against.
add_filter( 'acfjp_field_type_schema', function ( array $schema, string $type ): array {
    return $schema;
}, 10, 2 );
```

History retention (entries kept per field group, and days kept) is set on the Settings screen.

### Actions

| Action | Arguments | Fires |
|---|---|---|
| `acfjp_booted` | `$container` | Once FieldPilot is ready. Add-ons hook here. |
| `acfjp_applied` | `$result` | After a batch is applied and verified. |
| `acfjp_journal_recorded` | `$id`, `$changeSet`, `$status` | After a batch is written to the journal. |
| `acfjp_restored` | `$groupKey`, `$snapshot` | After a field group is restored from a snapshot. |
| `acfjp_audit` | `$event`, `$context`, `$userId` | On a security-relevant event that made no changes. |

---

## Visual Tour & Screenshots

1. **Inline Field Group Creator**: Create a field group in place with **Add New Field Group**; it is selected straight away and the Step 1 gate unlocks.
2. **Custom Field Builder & AI Prompt**: Configure fields across General, Validation, Presentation and Conditional Logic, then copy the prompt and open your AI.
3. **Side-by-Side Review Workbench**: JSON editor, live diff, Target Isolated badge and the Pre-Apply Safety Checklist.
4. **Transparent Conflict Resolver**: Field type changes stop and ask before anything is written (*Keep existing type*, *Change type*, *Add as new field*).
5. **History & Rollback**: Every applied change with its status and snapshot, rolled back newest first.
6. **Field Group Health Dashboard**: See which groups can be edited, and restart the 10-step guided tour anytime.

---

## Testing & Quality Gates

Every release passes these checks before it ships:

- **PHPUnit**: 110 unit tests, plus 24 integration tests run against real WordPress with ACF PRO and ACF free.
- **PHPStan** level 8 with zero errors.
- **WordPress Coding Standards** (PHPCS) with zero issues.
- **Plugin Check** (the WordPress.org review tool): no errors.
- **Diagnostics self-test** (`wp acfjp self-test`) on a live site, which also runs from **FieldPilot -> Diagnostics**.

---

## Installation & Quickstart

1. Download or clone this repository into your WordPress plugins directory:
   ```bash
   cd wp-content/plugins/
   git clone https://github.com/xaviermsd/fieldpilot-for-acf.git fieldpilot-for-acf
   ```
2. Make sure **Advanced Custom Fields** (free or PRO) is active.
3. Activate **FieldPilot** in **Plugins -> Installed Plugins** (or `wp plugin activate fieldpilot-for-acf`).
4. Go to **FieldPilot** in your WordPress admin menu.
5. Use **Generate with AI**, or paste JSON into the **JSON Editor**, to preview and apply your first configuration patch.

---

## License

FieldPilot is open-source software licensed under the [GNU General Public License v2 or later](https://www.gnu.org/licenses/gpl-2.0.html).
