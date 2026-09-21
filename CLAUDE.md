# CLAUDE.md - WP ACF JSON Pro

Instructions for any AI agent working in this repository. Read this file first, then
`docs/00-OVERVIEW.md`, then the doc for the layer you are touching.

---

## What this plugin is

A **configuration patch engine for Advanced Custom Fields**, driven by JSON.

It is *not* an importer. The mental model is Git, not "upload a file":

```
Current ACF tree  +  Incoming JSON
          │
          ▼
   Parse → Validate → Normalize → Resolve → Diff → ChangeSet
          │
          ▼
   Preview (human or machine reads the ChangeSet)
          │
          ▼
   Snapshot → Apply → Verify → (auto-rollback on failure)
```

Every feature in the product is a consumer of that one pipeline. If you are adding a
feature that bypasses the pipeline, you are adding it in the wrong place.

---

## The five rules

1. **Never write to the database directly.** All ACF mutation goes through ACF's own
   public API (`acf_update_field`, `acf_update_field_group`, `acf_delete_field`, …).
   The only tables this plugin owns are its own history/log tables. No `$wpdb->update()`
   against `wp_posts` or `wp_postmeta`, ever. Read-only `$wpdb->get_var()` queries for
   data-presence detection are the single documented exception (see `docs/05-DIFF-ENGINE.md`).

2. **Never overwrite what the JSON did not mention.** An `update` that sets
   `{"required": true}` changes exactly one setting. `instructions`, `wrapper`,
   `conditional_logic`, `default_value` and everything else survive byte-for-byte.
   This is the product's core promise; a regression here is a P0 bug.

3. **Never mutate without a snapshot.** `Apply` takes a full native-ACF export of every
   affected field group *before* the first write. If any write fails, restore the
   snapshot and report the failure. No partial application is ever left behind.

4. **JSON is data, never code.** No `eval`, no `unserialize` on imported input, no
   variable-variables, no dynamic class instantiation from a JSON string, no callable
   values pulled out of the payload. Every value that reaches ACF is validated against
   a whitelist or sanitized by type.

5. **Preview and apply must agree.** `Apply` re-computes the diff against live ACF state
   and compares the hash with the plan it was handed. If ACF changed underneath, it
   returns `409 STATE_CHANGED` rather than applying a stale plan.

---

## Coding standards

- **PHP 8.1+.** Use typed properties, constructor promotion, readonly where it fits,
  enums, `never`/`static` return types, first-class callables, named arguments.
- **PSR-4**, namespace root `ACFJP\` → `includes/`. One class per file, file named
  after the class. No procedural code outside `wp-acf-json-pro.php` and `uninstall.php`.
- **WordPress Coding Standards** for naming of hooks, options, meta and DB columns
  (snake_case), **PSR-12 formatting** for PHP structure (braces, spacing, imports).
  Where the two conflict, PSR-12 wins for code layout, WPCS wins for identifiers.
- **No Composer runtime dependencies.** Composer is dev-only (PHPUnit, PHPCS, PHPStan).
  Shipping a vendor directory into a WordPress plugin invites class collisions; if you
  think you need a library, write the 200 lines instead and say why in the PR.
- **Autoloader**: a hand-written PSR-4 SPL autoloader in `includes/Core/Autoloader.php`
  is used at runtime. `vendor/autoload.php` is loaded only when present and only for tests.
- **Every public method has a docblock** with `@param`/`@return`/`@throws`. Private
  methods only when non-obvious.
- **Text domain**: `wp-acf-json-pro`. Every user-facing string is translated.
- **Escaping**: `esc_html__()`, `esc_attr()`, `wp_json_encode()` at the point of output.
  Never build HTML by concatenating unescaped model values.
- **Prefixes**: PHP namespace `ACFJP\`; constants `ACFJP_*`; options `acfjp_*`;
  DB tables `{$wpdb->prefix}acfjp_*`; hooks `acfjp/*`; CSS/JS handles `acfjp-*`;
  REST namespace `wp-acf-json-pro/v1`; JS global `window.ACFJP`.

## Error handling

- Internal failures throw typed exceptions extending `ACFJP\Exceptions\AcfjpException`,
  which carries a machine `code`, a translated `message`, a `context` array and optional
  `suggestions`.
- Exceptions never escape to the browser. The REST layer and the admin controller each
  catch `AcfjpException` and convert it to the structured error envelope in
  `docs/08-REST-API.md`.
- **Never return a bare `false` or `null` to signal failure** where a caller needs to know
  why. `WP_Error` is used only at the REST boundary, for WordPress's benefit.
- Every error code is registered in `includes/Exceptions/ErrorCodes.php` and documented
  in `docs/02-JSON-SCHEMA.md §9`. Adding a code means adding it there too.

## Testing expectations

Nothing is "done" without tests. For each layer:

- Pure units (Parser, Validator, Normalizer, Comparator, PathResolver) get plain PHPUnit
  tests with fixtures - no WordPress bootstrap needed.
- Anything touching ACF gets a WP integration test that creates a real field group,
  runs the operation and asserts the resulting ACF export.
- Every bug fix ships with the failing test that proves it.
- Target: 85%+ line coverage on `JSON/`, `Diff/`, `ACF/Resolver`, `Operations/`.

## Status - read before assuming anything works

The pure layers (`Json/`, `Model/`, `Resolve/`, `Diff/`) are covered by 93 unit and
property tests, 570 assertions, all green, running without WordPress.

`Acf/`, `Apply/`, `Journal/`, `Rest/`, `Cli/` and `Admin/` have **never executed**.
They were written against ACF 6.8.10 source read directly, which makes them
well-informed, not verified. `tools/smoke.php` is the gate that changes that: it
exercises the whole write path against a real WordPress and ACF via
`npm run smoke`. Until that has been run and is green, treat everything in those
namespaces as a first draft.

## Build order

Follow `docs/12-ROADMAP.md` phase by phase. Do not start Phase N+1 while Phase N's
acceptance tests are red. The phases are ordered so that each one is independently
demonstrable.

## Things that are explicitly out of scope for v1

AI API integration, licensing, SaaS/cloud sync, Gutenberg block generation, Elementor,
multisite network-wide apply, ACF post-type/taxonomy/options-page registration (ACF 6.1+),
field-group duplication across sites, and 100% coverage of every ACF field type. Do not
add them. See `docs/00-OVERVIEW.md §5`.

## Document map

| File | Contents |
|---|---|
| `docs/YOUR-NEXT-STEPS.md` | **Start here if you are the human.** Status, division of labour, how to get it running, what to report back. |
| `docs/ARCHITECTURE-REVIEW.md` | **Read this first if you are an agent.** The seven corrections to the original plan, each backed by ACF 6.8.10 source. Three of them prevent silent data corruption. |
| `docs/00-OVERVIEW.md` | Product definition, scope, non-goals, success criteria |
| `docs/11-TESTING.md` | Test tiers, invariants, gates, how to add a case |
| `docs/13-EXTENSIBILITY.md` | Public hooks, filters, the Engine API, REST and WP-CLI |
| `schemas/wp-acf-json-pro-v1.json` | Machine-readable schema (also served to AI and to the prompt generator) |
| `readme.txt` | WordPress.org readme |

Layer-level design notes live in the class docblocks rather than in separate
documents - `Writer`, `TreeReader`, `MutabilityClassifier`, `Comparator` and
`Matcher` each open with the reasoning and the ACF source evidence behind them.
Keep it that way: a design note two directories from the code it describes goes
stale, and the next person to touch `Writer::writeUpdate()` needs to know about
read-merge-write *there*, not in `docs/06-OPERATIONS.md`.
