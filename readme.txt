=== WP ACF JSON Pro ===
Contributors: harshprajapati
Tags: acf, advanced custom fields, json, developer, fields
Requires at least: 6.5
Tested up to: 6.8
Requires PHP: 8.1
Stable tag: 1.0.1
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
* **Conflict detection.** Changing a field's type, renaming a field that holds
  content, deleting something with data in it - each stops and asks, with the
  trade-offs spelled out. It never guesses.
* **Snapshot and rollback.** Every batch is snapshotted first. One click restores
  the exact prior configuration. If a write fails verification, it rolls back on
  its own and tells you nothing was changed.
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
   Plugins → Add New.
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

1. Import screen with JSON editor and prompt generator
2. Diff preview showing exactly what will change
3. Conflict resolution with the trade-offs spelled out
4. History with one-click rollback
5. Dashboard showing which field groups are editable

== Changelog ==

= 1.0.1 =
* Fixed: adding a field produced a spurious "Does not match the required format" warning about its key. A new field has no key yet - the engine mints one - so there was nothing to validate.
* Import screen rewritten around numbered steps, so it is clear the AI prompt goes to your AI and its reply comes back into the JSON box.
* Added "Insert an example", which fills the editor with a working payload aimed at a field group that actually exists on your site.
* The JSON editor no longer shows a parse error before you have typed anything.
* Added read-only mode (`define( 'ACFJP_READ_ONLY', true );`) for trying the plugin on a site where no writes should be possible.
* Added Diagnostics: run the full engine self-test against your own ACF install from the admin, or with `wp acfjp self-test`.

= 1.0.0 =
* Initial release.
* Operations: create, add, update, delete, move, merge, sync, replace.
* Diff preview, conflict detection, snapshots and rollback.
* Nested Group, Repeater, Flexible Content and Clone support.
* Native ACF JSON compatibility in both directions.
* REST API and WP-CLI with full UI parity.
* AI prompt generator.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
