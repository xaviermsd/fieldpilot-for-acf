=== FieldPilot: AI & JSON Copilot for ACF ===
Contributors: xaviermsd
Donate link: https://profiles.wordpress.org/xaviermsd/
Tags: acf, advanced custom fields, ai, json, developer
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Build ACF field groups with AI prompts and JSON. Built for ACF free and PRO. Preview every change first, undo any time.

== Description ==

Advanced Custom Fields has not changed how you author fields in a decade: you click
through a UI, one field at a time. Meanwhile every developer has an AI that writes
JSON perfectly well. The missing piece was never generation - it was **safe
application**.

FieldPilot builds and changes ACF field groups from JSON: queue the fields you want, copy a
prompt that carries your real field names, paste your AI's reply back (or a native ACF export),
see exactly what will change, apply only that, and roll back if you were wrong.

The rule the whole plugin is built around:

> **Nothing changes except what the JSON asked for.**

Set one field to required and that is the only setting that changes. Instructions,
conditional logic, wrapper classes, field keys and every other field in the group
stay as they were, and FieldPilot checks that after every write.

= What it does =

* **Partial updates.** Change one setting on one field. ACF's own importer can only
  replace a whole field group; this changes precisely what you named.
* **Diff preview.** Every change is shown before anything is written - added,
  removed, moved, and every setting that differs, old value to new.
* **Target isolation.** Aim at any node in a nested ACF tree, such as a repeater
  sub-field or a flexible content layout. Changes stay inside that target, and the
  check after the write confirms nothing else moved.
* **Conflict detection.** Changing a field's type, renaming a field that holds
  content, deleting something with data in it - each stops and asks, with the
  trade-offs spelled out. It never guesses.
* **Snapshot and rollback.** Every batch is snapshotted before the first write. Roll
  changes back from History, newest first, and the field group returns to its
  previous configuration, setting for setting. If a write fails the check, it rolls
  back automatically.
* **Field types that follow your ACF.** The builder, templates, type guide and AI
  prompt offer all 36 field types on ACF PRO and the 32 free ones on ACF free. PRO
  types are shown disabled with the reason, and the engine refuses them on a free
  install.
* **Custom field builder.** Pick a field type, fill in the common settings from
  ACF's four tabs (General, Validation, Presentation, Conditional Logic) and queue
  as many fields as you like. Each one becomes a line in the AI prompt, and your AI
  turns the list into JSON.
* **Deep nesting.** Groups, Repeaters, Flexible Content layouts and Clone fields,
  at any depth, targeted by human path: `["Agent", "Contact", "Social"]`.
* **Reads sloppy JSON.** AI output uses `group` for `field_group`, `description` for
  `instructions`, `true` where ACF wants `1`. All of it is normalised rather than
  rejected, so you paste instead of hand-fixing.
* **Reads native ACF exports**, and exports any field group as clean JSON.
* **Guided tour.** A 10-step walkthrough across the Dashboard, Import and History
  screens. Restart it any time from the Dashboard or Settings.
* **Create field groups in place.** Pick an existing group, or click "Add New Field
  Group" to create one without leaving the screen. It is recorded in History like
  any other change.
* **Your data, your call.** Nothing is removed when you delete the plugin unless you
  ask for it in Settings. Field groups are never touched.
* **REST API and WP-CLI.** Validate, preview, apply, roll back, export, create field
  groups and generate prompts from scripts and deployments. Plugin settings stay on
  the Settings screen.
* **Knows what it cannot touch.** Field groups registered in PHP, or loaded from
  `acf-json` and not yet synced, cannot be patched. The plugin detects them up front
  and says so, instead of silently writing orphaned rows.

= AI, without an AI dependency =

The plugin never calls a language model. It publishes a schema and generates a
ready-to-paste prompt - including your field group's current structure, so the model
stops inventing field names, and only the field types your ACF can store - and you
use whatever AI you already pay for.

No API keys. No per-seat model cost. No site data leaving your server. No vendor to
be locked to. It works fully with zero configuration, and it keeps working when a
provider changes its pricing.

= Requirements =

WordPress 6.5+, PHP 8.1+, ACF 6.2+ (free or PRO). On ACF 6.8 and newer, new fields
are also checked against ACF's own field-type schemas, and anything unexpected is
flagged in the preview. Repeater, Flexible Content, Clone and Gallery need ACF PRO,
and Icon Picker needs ACF 6.3 or newer. Where a type is not available, FieldPilot
shows it disabled and refuses payloads that use it, with a clear message.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/fieldpilot-for-acf/`, or install it through
   Plugins -> Add New.
2. Activate it. ACF must already be active.
3. Go to **FieldPilot** in the admin menu.

== Frequently Asked Questions ==

= Will this overwrite my existing fields? =

No. An `update` changes only the settings your JSON names. Every other setting, and
every other field, is left as it was. Field keys are never regenerated, so no
existing content is ever orphaned by an update.

= What happens if something goes wrong? =

Every batch is snapshotted before the first write, then checked afterwards by
re-reading what was saved: the requested changes must be there, and every other field
in the group must be unchanged. If not, the snapshot is restored automatically and
you are told what happened. You can also roll back past changes from the History
screen, newest first.

= How do I create a new field group if none exist yet? =

Click "Add New Field Group" in the Target Field Group bar on the Import screen, enter
a title, and click "Create & Select". FieldPilot creates the group, selects it and
unlocks the custom field builder and prompt generator. The new group shows up in
History, so you can roll it back.

= What happens to my data when I delete the plugin? =

By default nothing is removed: your settings and history stay in the database in case
you reinstall. To remove them, go to FieldPilot -> Settings, tick "Delete all
FieldPilot data when the plugin is deleted" under "On uninstall", then delete the
plugin from the Plugins screen. That removes FieldPilot's settings, history and
snapshots. Your field groups belong to ACF and are never touched. On multisite, each
site's own setting decides for that site.

= How do I restart the guided tour? =

Click "Restart Guided Tour" on the FieldPilot Dashboard or on the Settings screen.

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

Yes, with the 32 field types ACF free provides. Repeater, Flexible Content, Clone and
Gallery are ACF PRO features: FieldPilot shows them disabled and refuses payloads that
use them.

= Can I use it from WP-CLI? =

Yes. Commands that write need a user, for example
`wp acfjp apply patch.json --user=admin`. A batch with destructive changes also needs
`--confirm`. Run `wp help acfjp` for the full list.

= Does it need an API key? =

No. The plugin contains no AI integration at all - it generates prompts for whichever
AI you already use.

== Screenshots ==

1. Inline ACF Field Group creator with instant selection and safety gate
2. Custom Field Builder and AI prompt generator with links to open your AI
3. Side-by-side review workbench showing the diff preview and Target Isolated scope badge
4. Non-destructive conflict resolution with transparent trade-offs (keep, replace, or duplicate)
5. History screen with snapshots and rollback
6. Dashboard showing field group health, editable database groups, and guided tour launcher

== Changelog ==

= 1.1.0 =
The biggest FieldPilot update so far. It fixes bugs that could change things you did not ask to change, makes the plugin follow your ACF edition, and tightens WordPress.org compliance. Please update.

Data safety:

* Fixed: Adding layouts with a target that was not a flexible content field could write the layout into a field of another field group. Layouts now go only into the flexible content field you target; anything else is refused before writing.
* Fixed: Backslashes in labels, instructions and other text settings were stripped on save and on rollback.
* Fixed: "create" payloads always failed at Apply, including native ACF imports. Creating a group is now journaled, and rolling it back moves the group to the trash.
* Fixed: "create" payloads in the shape the AI prompt and templates use (group_changes and add) were rejected as missing a field group definition.
* Fixed: A partial wrapper update, such as only the width, wiped the field's class and id.
* Fixed: "Keep the original and add a second field" changed the original field's type, and skipping a move still moved the field.
* Fixed: A failure after the write (the check, the after-snapshot or the history entry) left the changes in place without a rollback or a history row. The whole batch is now undone and recorded.
* Fixed: Failure messages now say truthfully whether the automatic rollback worked, and point to "Restore snapshot" when it did not.
* Fixed: Rolling back an older entry silently wiped every later change. Rollbacks now go newest first, and History explains why an older entry has to wait.
* Fixed: "merge" proposed a type and label the JSON never asked for, and min, max and step were cut to whole numbers (0.01 was saved as 0).
* Fixed: Two applies at the same moment could both write. The second one is now refused.
* Fixed: A move without a destination pulled the field out of its container.
* Fixed: Sub-fields of a new layout could get duplicate or missing keys.
* Fixed: A misspelled target field fell back to the group root. It is now an error, and target.field can name a container such as a flexible content field.
* Fixed: History retention kept about twice as many entries as configured.

Field types:

* New: The builder, quick-add chips, templates, type guide and AI prompt all come from one list of the 36 ACF field types and follow the installed ACF. On ACF free, Repeater, Flexible Content, Clone and Gallery are shown disabled with "Requires ACF PRO", and Icon Picker needs ACF 6.3 or newer. The type guide now includes Icon Picker.
* New: The AI prompt lists only the field types your site can store and tells the AI which ones to leave out.

Screens:

* Fixed: JSON file uploads failed on Windows servers.
* Fixed: Generating a prompt failed on sites with plain permalinks.
* Fixed: "Add New Field Group" ignored read-only mode and the capability filter, and was not recorded in History.
* Fixed: The guided tour treated the Export and Diagnostics screens as the Dashboard.
* Fixed: The target pickers on the Import screen offered groups registered in PHP or waiting in acf-json, which FieldPilot cannot change. They now list only groups it can patch; the others stay in the list below with the reason.
* Enhanced: Every History entry shows its status (Applied, Failed - undone, Failed - needs restore, Rolled back, Rollback), with clearer rollback prompts.

WP-CLI and REST:

* New: `wp acfjp create-group` and `wp acfjp schema`.
* New: REST endpoint to create a field group (POST /field-groups).
* Enhanced: CLI commands that write ask for a user (for example --user=admin) instead of failing with a permission error.

Design:

* New: FieldPilot's own look on every screen: the logo in the page title and the admin menu, lime and cyan accents, Sora headings bundled with the plugin (nothing is fetched from a third party), and the logo on the guided tour's welcome card.
* New: WordPress.org banner, icon and six annotated screenshots that walk the workflow: queue fields, ask your AI, paste JSON back, see the diff, apply, undo.

WordPress.org standards:

* Fixed: On multisite, uninstall now cleans each site that asked for it. It removes only FieldPilot's own data and never deletes field groups.
* Fixed: Translations loaded too early when ACF was inactive, which logged a notice on WordPress 6.7 and newer.
* Enhanced: All JavaScript text is translatable, emoji are out of translatable strings, and the code passes WordPress Coding Standards and Plugin Check.
* Enhanced: The readme and the text inside the plugin now describe exactly what the code does.

= 1.0.30 =
* Fixed: Guided tour Step 2 on fresh sites with zero field groups now displays sample preview rows and highlights the status table clearly, avoiding collapsed 0px spotlight line.
* Enhanced: Dashboard screen renders an inline notice and sample preview table demonstrating editable vs locked field groups when no ACF groups exist yet.

= 1.0.29 =
* Enhanced: Updated Guided Onboarding Tour Step 3 to highlight Target Field Group selection and inline creation gate.
* Enhanced: Comprehensive documentation updates across README, FAQs, and developer screenshot guides.
* Fixed: Resolved Plugin Check (PCP) NonceVerification warning on tour step parameter handling.

= 1.0.28 =
* Enhanced: Target Field Group creation UX with toggle button state switching between "+ Add New Field Group" and "Close".
* Enhanced: Persistent creation form when no field groups exist, preventing dead-end UI states on fresh installations.
* Enhanced: Uninstall cleanup is configured only from FieldPilot Settings, in line with WordPress.org guidelines.
* Fixed: Form cancellation and focus state handling when closing field group creation box.

= 1.0.27 =
* Includes the changes from development builds 1.0.23 to 1.0.26.
* Added: Uninstall data cleanup setting in FieldPilot Settings to wipe or preserve settings, history snapshots, and demo data when deleting the plugin.
* Added: Safe, idempotent uninstall routine (uninstall.php) with prefix handling, demo marker checks, and scheduled cron cleanup.
* Added: Direct "Add New Field Group" creation button with instant AJAX ACF group generation and auto-selection.
* Added: Mandatory Step 1 gate requiring an active Target Field Group before unlocking the custom field builder or prompt generator.
* Added: Soft red validation error borders and clear warning messages for required field labels, empty group titles, and validation range limits.
* Fixed: Resolved JavaScript SyntaxError on variable declarations in custom field builder.
* Fixed: Guided tour replay button icon and styling alignment in Settings and Dashboard screens.

= 1.0.22 =
* Fixed: Prevented premature sample diff cleanup and sidebar guide flash when transitioning from Step 8 to Step 9.
* Fixed: Instantly hide previous overlay and spotlight before navigating across screens.

= 1.0.21 =
* Added: Non-revocable, disabled sample row in History table when empty to cleanly demonstrate rollback functionality.
* Fixed: Ensured Step 8 targets conflict box directly instead of falling back to sidebar controls.
* Fixed: Smoother step transitions between Import (Step 8) and History (Step 9).

= 1.0.20 =
* Fixed: Replaced collapsed empty notice in History screen with styled empty card to fix spotlight rendering.
* Fixed: Ensured backward step navigation from History to Import page restores editor tab and diff preview.
* Enhanced: Robust retry and auto-recovery for tour spotlights across all screen transitions.

= 1.0.19 =
* Added: Visual 10-step guided onboarding tour with target spotlight overlay.
* Added: Direct Settings and Schema & Operations Guide action links on Plugins screen.
* Added: Replay tour action on Dashboard and Settings screens.
* Enhanced: Completely eliminated demo data from code, history screen, and UI.
* Enhanced: Polished tour modal styling, close button, and diff review layout.

= 1.0.18 =
* Maintenance release.

= 1.0.17 =
* Maintenance release.

= 1.0.15 =
* Maintenance release.

= 1.0.14 =
* Hardened: Removed --file option and arbitrary file write from WP-CLI export command in favor of standard shell output redirection.

= 1.0.13 =
* Security: Converted Export admin screen to POST method with explicit nonce verification and permission checks.
* Security: Sanitized and validated all components of uploaded JSON file structures before processing.
* Security: Added explicit capability checks to all form and file submission handlers.

= 1.0.12 =
* Added: Side-by-side split review workbench in Tab 2 with live diff preview rendering directly alongside JSON editor.
* Added: Target field group selector in JSON editor toolbar for dynamic template group assignment.
* Added: Glowing pulse animation on Apply button once safety checklist checkboxes are confirmed.
* Enhanced: Step 2 in AI tab split into clear sequential sub-steps (2A: Copy & Open AI, 2B: Paste AI Output).
* Fixed: Cleaned up append button labeling to avoid double plus signs.

= 1.0.11 =
* Added: Prominent bottom action bar (+ Append Field to Queue) directly below the 4 ACF tabs in the Custom Field Builder.
* Added: Instant Enter key triggers on all builder fields to append specifications immediately from any tab.
* Added: Visual indicator badge confirming when fields are added to the queue.

= 1.0.10 =
* Added: In-app Schema & Operations Developer Guide in Tab 3 covering all 8 operations, target scope resolution, the 4 ACF tabs, a field types reference, and template loaders.
* Fixed: Resolved DOM hierarchy and removed duplicate intent textarea in AI Prompt tab.
* Fixed: Prevented WordPress admin footer (#wpfooter) from overlapping content.
* Fixed: Standardized AI steps into full-width sequential workflow and aligned editor action buttons.

= 1.0.9 =
* Added: Template patches for the ACF field types in the JSON Editor template selector across 6 optgroups.
* Enhanced: Reordered workflow tabs so Tab 1 is "1. Generate with AI" (default) and Tab 2 is "2. JSON Editor & Import".
* Enhanced: Interactive step guidance animations (pulsing ready states) when fields are queued to direct the user toward generating prompts and pasting responses.
* Fixed: Standardized button alignments, heights (36px), and flex centering across all action bars.
* Enhanced: AI prompt generator with compound multi-field examples and 4-tab token parsing.

= 1.0.8 =
* Added: 4-tab interactive Custom Field Builder (General, Validation, Presentation, Conditional Logic) with type-adapting controls for return format, choices, sub-fields, layouts, post types, taxonomy, toolbar, button label, min/max/step, allowed MIME types, maxlength, placeholder, prepend, append, textarea rows, wrapper classes, and conditional logic.

= 1.0.7 =
* Added: 4-tab parameter mapping (General, Validation, Presentation, Conditional Logic) in the AI prompt.
* Added: Width and instructions controls in the Interactive Custom Field Builder with Enter key shortcuts.
* Enhanced: Detailed user guide and JSON property mapping documentation in README.md and readme.txt.

= 1.0.6 =
* Added: Interactive Custom Field Builder in AI tab allowing developers to specify project-specific field names, choose a field type, toggle required flags, and append specs into prompt.
* Enhanced: AI prompt generator with deep parameter rules, return formats, choice schemas, and bulk multi-field addition examples.

= 1.0.5 =
* Fixed: SchemaValidator now accepts 0/1 integer booleans alongside boolean true/false to prevent false warning notices with ACF internal storage format.

= 1.0.4 =
* Added: Multi-field bulk append without overwriting when clicking quick idea chips.
* Added: Categorized quick dropdown of ACF field types.
* Added: Quick "Clear text" action in AI prompt tab.

= 1.0.3 =
* Fixed: TreeReader exportArray now loads the complete field tree via acf_get_fields before export, so snapshots and rollbacks include every field.
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

= 1.1.0 =
The biggest FieldPilot update yet: field types that follow your ACF edition, a new look, create-group and schema commands, and fixes for layouts that could land in the wrong field group, lost backslashes and rollbacks that wiped later changes. Please update.

= 1.0.30 =
Upgrade recommended for refined guided tour step 2 and zero-group dashboard preview handling.

= 1.0.29 =
Upgrade recommended for refined guided onboarding tour, updated documentation, and Plugin Check security compliance.

= 1.0.28 =
Upgrade recommended for improved Target Field Group creation UX, zero-group state handling, and WordPress.org compliant uninstall configuration.
