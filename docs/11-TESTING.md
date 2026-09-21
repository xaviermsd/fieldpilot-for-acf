# 11 - Testing

Three tiers, each with a different job. Nothing is "done" without the tier that
covers it.

---

## Running

```bash
# Unit + property. No WordPress, no database. ~20ms.
composer test:unit
vendor/bin/phpunit --testsuite unit
vendor/bin/phpunit --testsuite property

# Integration. Needs a real WordPress + ACF.
npx wp-env start
npx wp-env run tests-cli --env-cwd=wp-content/plugins/wp-acf-json-pro \
    vendor/bin/phpunit --testsuite integration
```

The unit suite deliberately does not boot WordPress. `tests/bootstrap.php` stubs the
handful of WordPress functions the pure layers call - translation, filters, JSON
encoding. That is only possible because of the pure/effect split in
`docs/ARCHITECTURE-REVIEW.md §A`, and it is the payoff for it: the diff engine, the
matcher, the normalizer and the resolver all run in milliseconds on every save.

---

## Tier 1 - Unit

Pure functions over fixtures. The bulk of the suite.

| File | Covers |
|---|---|
| `CoerceTest` | Boolean/int/choice/conditional-logic coercion to ACF's conventions |
| `ParserTest` | Syntax, encoding, size, and the self-correcting error hints |
| `NormalizerTest` | Dialect detection, alias resolution, name slugging, nesting |
| `SettingsDiffTest` | The partial-update guarantee at setting level |
| `MatcherTest` | Key → name → label matching; order independence; ambiguity |
| `TreeTest` | Indexing, parentage, layout membership, paths, state hash |
| `TargetResolverTest` | Path resolution, ambiguity refusal, suggestions |
| `ComparatorTest` | The whole diff engine, including the acceptance scenario |

`ComparatorTest` is the one that matters. It asserts the canonical scenario produces
**exactly two changes**, that the one updated setting is the only one touched, and
that `instructions` on the email field survives untouched.

---

## Tier 2 - Property

`tests/Property/InvariantTest.php` asserts three invariants over **generated** trees
rather than hand-picked examples - 60 seeded iterations each, reproducible from the
iteration number alone.

1. **Preservation.** A single-setting update produces exactly one change naming
   exactly one field and one setting. A patch cannot fan out.
2. **Diff idempotence.** Syncing a tree against a declaration of its own state
   produces nothing. If this fails, every sync churns the whole group and every
   preview shows phantom changes.
3. **Key preservation under reordering.** Shuffling declared order is never read as
   delete-and-recreate - which would mint new keys for every field and orphan all of
   their content.

Hand-written cases prove the engine handles what we thought of. These prove it
handles what we did not.

---

## Tier 3 - Integration

Real WordPress, real ACF, real database. The only place the Writer, Verifier and
Restore are exercised, and the only place content-dependent risk escalation can be.

`tests/Integration/AcceptanceTest.php` holds the regression test for the entire
product:

```
export before → apply → export after
    ⇒ the two documents differ in EXACTLY the two places the payload named
```

Structurally, not semantically. `assertSame()` on the arrays with only `required`
unset. If that goes red, the core promise is broken and the release does not ship.

It also covers the remaining two invariants:

4. **Rollback.** snapshot → arbitrary batch → rollback ⇒ byte-identical export.
5. **Refusal.** A PHP-registered field group is refused, not silently forked into
   orphaned `acf-field` posts (`ARCHITECTURE-REVIEW §B.2`).

### Matrix

Run across ACF 6.2, 6.5 and 6.8, free and PRO, on PHP 8.1-8.4. The 6.2 row proves
graceful degradation when `acf_get_field_json_schema()` does not exist; the PRO rows
prove Repeater, Flexible Content and Clone.

---

## Gates

CI fails below any of these.

| Gate | Threshold |
|---|---|
| Coverage - `Json/`, `Diff/`, `Resolve/` | 90% |
| Coverage - `Apply/` | 85% |
| Coverage - overall | 70% |
| PHPStan | level 8 on `includes/` |
| PHPCS | WordPress-Extra + PSR-12 layout override |
| Custom rule | `acf_import_field_group()` called only from `Restore`, `Writer::writeCreateGroup`, `Operations\Replace`, `Operations\Sync` |

That last one is not a style preference. `acf_import_field_group()` deletes every
field absent from its payload; confining it is what stops a bug in the update path
from destroying a customer's field group.

---

## Adding a diff case

Today: add a method to `ComparatorTest`. The `plan()` helper there takes a tree and a
raw payload and hands back a ChangeSet, so a new case is usually six lines.

**Planned (not yet built):** a fixture runner over

```
tests/fixtures/<case>/current.json            the existing tree
tests/fixtures/<case>/input.json              the payload
tests/fixtures/<case>/expected-changeset.json what the comparator must produce
```

so a new case costs three data files and no PHP. Worth building once the case count
passes ~30 - the cost of adding a case is what decides whether cases get added, and
right now six lines is cheap enough that the runner would be premature.

---

## Writing a test for a bug

Every fix ships with the failing test that proves it. Reproduce first, in the
narrowest tier that can show the bug - if it reproduces in a unit test, it does not
belong in the integration suite, where it will run a thousand times more slowly for
the rest of the project's life.
