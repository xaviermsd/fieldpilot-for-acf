=== WP ACF JSON Pro ===
Contributors: harshprajapati
Tags: acf, advanced custom fields, json, developer, fields
Requires at least: 6.5
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 1.0.7
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Build, import, update and manage ACF field structures with JSON. Diff before you apply. Roll back when you are wrong.

== Description ==

Advanced Custom Fields has not changed how you author fields in a decade: you click
through a UI, one field at a time. Meanwhile every developer has an AI that writes
JSON perfectly well. The missing piece was never generation - it was **safe
application**.

WP ACF JSON Pro is a configuration patch engine for ACF. Paste JSON, see exactly
what will change, apply only that, and roll back if you were wrong.

The rule the whole plugin is built around:

> **Nothing changes except what the JSON asked for.**

Set one field to required and that is the only byte that moves. Instructions,
conditional logic, wrapper classes, field keys and every other field in the group
come out byte-identical.

= What it does =

* **Partial updates.** Change one setting on one field. ACF's own importer can only
  replace a whole field group; this changes precisely what you named.
* **Diff preview.** Every change is shown before anything is written - added,
  removed, moved, and every setting that differs, old value to new.
* **Target Isolation & Sibling Protection.** Target any node in a nested ACF tree
  (Repeater subfield, Flexible Content layout) with guarantees that sibling branches
  are untouched.
* **Conflict detection.** Changing a field's type, renaming a field that holds
  content, deleting something with data in it - each stops and asks, with the
  trade-offs spelled out. It never guesses.
* **Snapshot and rollback.** Every batch is snapshotted with its complete field tree.
  One click restores the exact prior configuration. If a write fails verification,
  it rolls back automatically.
* **Interactive Custom Field Builder.** Type exact field names, choose from all 36
  ACF field types, select presentation width (100%, 50%, 33%, 25%), toggle required,
  add instructions, and append multi-field specifications in bulk.
* **Deep 4-Tab Settings Mapping.** Full parameter mapping for General, Validation,
  Presentation, and Conditional Logic across all 36 ACF field types.
* **Deep nesting.** Groups, Repeaters, Flexible Content layouts and Clone fields,
  at any depth, targeted by human path: `["Agent", "Contact", "Social"]`.
* **Reads sloppy JSON.** AI output uses `group` for `field_group`, `description` for
  `instructions`, `true` where ACF wants `1`. All of it is normalised rather than
  rejected, so you paste instead of hand-fixing.
* **Reads native ACF exports** too, in both directions.
* **REST API and WP-CLI** with full parity, so deployments can do everything the UI
  can.
* **Knows what it cannot touch.** Field groups registered in PHP, or loaded from
  `acf-json` and not yet synced, are physically unpatchable. The plugin detects them
  up front and says so, instead of silently writing orphaned rows.

= AI, without an AI dependency =

The plugin never calls a language model. It publishes a schema and generates a
ready-to-paste prompt - including your field group's current structure, so the model
stops inventing field names - and you use whatever AI you already pay for.

No API keys. No per-seat model cost. No site data leaving your server. No vendor to
be locked to. It works fully with zero configuration, and it keeps working when a
provider changes its pricing.

= Requirements =

WordPress 6.5+, PHP 8.1+, ACF 6.2+ (free or PRO). On ACF 6.8+ payloads are validated
against ACF's own shipped field-type schemas, so validation tracks ACF automatically.
Repeater, Flexible Content, Clone and Gallery require ACF PRO; payloads using them on
a free install are refused with a clear message rather than a cryptic error.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/wp-acf-json-pro/`, or install it through
   Plugins -> Add New.
2. Activate it. ACF must already be active.
3. Go to **ACF JSON Pro** in the admin menu.

== Frequently Asked Questions ==

= Will this overwrite my existing fields? =

No. An `update` changes only the settings your JSON names. Every other setting, and
every other field, is preserved exactly. Field keys are never regenerated, so no
existing content is ever orphaned by an update.

= What happens if something goes wrong? =

Every batch is snapshotted before the first write, then verified afterwards by
re-reading what was saved. If the result does not match the plan, the snapshot is
restored automatically and you are told nothing was changed. You can also roll back
any past change manually from the History screen.

= Can I delete fields? =

Yes, but deliberately. The plugin scans for stored content first - including values
inside repeaters, which a name-based search would miss - and a delete that would
orphan content requires explicit confirmation.

= Why can't it edit my field group? =

If a group is registered in PHP with `acf_add_local_field_group()`, it does not live
in the database, so nothing can be written to it. If it comes from an `acf-json` file
that has not been synced, the same applies. The Dashboard labels every group so you
know in advance; for JSON groups, sync them into the database first.

= Does it work with ACF free? =

Yes, for every field type ACF free provides. Repeater, Flexible Content, Clone and
Gallery are ACF PRO features.

= Does it need an API key? =

No. The plugin contains no AI integration at all - it generates prompts for whichever
AI you already use.

== Screenshots ==

1. Import screen with JSON editor, Custom Field Builder, and prompt generator
2. Diff preview showing exact target locus, scope isolation, and changes
3. Conflict resolution with the trade-offs spelled out
4. History with one-click rollback
5. Dashboard showing which field groups are editable

== Changelog ==

= 1.0.7 =
* Added: Complete 4-tab parameter mapping (General, Validation, Presentation, Conditional Logic) and comprehensive specifications across all 36 ACF field types.
* Added: Width and instructions controls in the Interactive Custom Field Builder with Enter key shortcuts.
* Enhanced: Detailed user guide and complete JSON property mapping documentation in README.md and readme.txt.

= 1.0.6 =
* Added: Interactive Custom Field Builder in AI tab allowing developers to specify project-specific field names, choose from all 36 ACF types, toggle required flags, and append specs into prompt.
* Enhanced: AI prompt generator with deep parameter rules, return formats, choice schemas, and bulk multi-field addition examples.

= 1.0.5 =
* Fixed: SchemaValidator now accepts 0/1 integer booleans alongside boolean true/false to prevent false warning notices with ACF internal storage format.

= 1.0.4 =
* Added: Multi-field bulk append without overwriting when clicking quick idea chips.
* Added: Categorized quick dropdown covering all 36 ACF field types.
* Added: Quick "Clear text" action in AI prompt tab.

= 1.0.3 =
* Fixed: TreeReader exportArray now populates complete field trees via acf_get_fields prior to export, guaranteeing byte-for-byte snapshot and rollback integrity.
* Enhanced: Standardized button heights, flex vertical centering, Dashicons alignment, and AI launcher pills.

= 1.0.2 =
* Fixed: Diagnostics SelfTest export method to prevent TypeError on live ACF installs.
* Added: Tab navigation URL hash synchronization (#editor, #ai, #guide).

= 1.0.1 =
* Fixed: adding a field produced a spurious warning about its key.
* Import screen rewritten around numbered steps and tabs.
* Added "Insert an example" template picker.
* Added read-only mode and Diagnostics Self-Test runner.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.0.7 =
Upgrade recommended for all users to get full 4-tab settings mapping, 36 ACF field type specifications, and Custom Field Builder controls.
