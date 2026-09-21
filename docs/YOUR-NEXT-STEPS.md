# Your Next Steps

A practical guide to what happens now: what only you can do, what I can do, and the
order to do it in.

---

## 1. Where this actually stands

Be clear-eyed about this, because it decides everything below.

| Layer | What it does | Status |
|---|---|---|
| `Json/`, `Model/`, `Resolve/`, `Diff/` | Parse, normalise, resolve targets, compute the diff | **Proven.** 93 tests, 570 assertions, green. Runs without WordPress. |
| `Acf/` | Read ACF, classify mutability, detect content | **Unproven.** Written against ACF 6.8.10 source, never executed. |
| `Apply/` | Guard, snapshot, write, verify, restore | **Unproven.** Never executed. |
| `Journal/` | History and snapshots | **Unproven.** The SQL has never run. |
| `Rest/`, `Cli/`, `Admin/` | The three surfaces | **Unproven.** No page has ever rendered. |

Roughly 40% of the codebase is proven and 60% has never run. That is normal for code
written without an environment, and it is not a comfortable place to stop.

**The single most valuable thing you can do is run the built-in self-test on a real
WordPress, so that other 60% executes for the first time.** It takes about ten
minutes and needs nothing installed beyond the plugin itself. Everything else on
this list is secondary to that.

---

## 2. The division of labour

I can write code, read ACF's source, reason about architecture, write tests and fix
what the tests catch. I cannot run WordPress, click a button, or judge whether the
product feels right.

### Only you can do these

| Task | Why it has to be you |
|---|---|
| **Run it on a real WordPress** | My sandbox has no WordPress at all. Nothing I write executes against ACF until you run it. |
| **Supply ACF PRO** | It is licensed software tied to your account. You gave me the source to read; the environment needs the actual plugin installed. |
| **Report failures back** | I cannot see your terminal. A pasted error is the entire feedback loop. |
| **Judge the UI** | Whether the diff screen is legible, whether the conflict wording lands, whether the flow feels right - that is taste and you have the context. |
| **Test against a real site** | Your own projects have the messy field groups that synthetic fixtures never reproduce. |
| **Product decisions** | Name, licence, price, free vs pro split, distribution. Not engineering questions. |

### I do these, on your report

Fix what the smoke test catches. Add tests for anything that breaks. Extend features.
Tune the UI once you have seen it. Write documentation. Handle PHPStan and PHPCS
findings.

**The loop is:** you run something → you paste what happened → I fix it → repeat.
Your part is mostly running and reporting, not writing.

---

## 3. Step one - install it and run the self-test

**Time: 10 minutes. No Docker, no Node, no WP-CLI required.**

You do not need a local environment. The plugin ships a self-test that runs the
whole engine against your real ACF installation, from the WordPress admin.

That is a better test than a local one anyway. What matters is *your* ACF version,
*your* PHP version, the field types you have installed, and whatever other plugins
filter `acf/update_field` on your site. A pass on a developer's laptop says little
about any of that.

### Install

Zip the `fieldpilot-for-acf` folder and upload it under **Plugins → Add New → Upload**, or
copy it to `wp-content/plugins/` over SFTP. Activate it. ACF must already be active.

If a requirement is missing the plugin stays dormant and shows a notice explaining
why - it will not white-screen the site.

### Run the self-test

**FieldPilot → Diagnostics → Run self-test.**

What it does to your site:

- Creates **one temporary, inactive field group of its own**, then deletes it.
- Every group it creates is tagged, and cleanup only removes tagged groups. It
  never reads, changes or deletes a field group it did not create.
- Touches no posts, no options, no content.
- Removes its own history rows afterwards.

It checks, in order:

1. **Partial update** - the core promise. Sets one field to required, adds one
   field, then compares the before and after exports **byte for byte** and asserts
   that nothing else moved. Instructions, placeholder, sibling fields and every
   existing key must come out identical.
2. **Rollback** - restores a byte-identical configuration.
3. **Conflicts** - a type change blocks until resolved; `keep_existing` preserves
   the type while still applying the non-conflicting label change.
4. **Stale previews** - a plan computed before someone else edited the group is
   refused rather than applied.
5. **PHP-registered groups** - refused, and it counts `acf-field` rows either side
   to prove **no orphaned rows were created**. This is the corruption path from
   `ARCHITECTURE-REVIEW.md §B.2`.
6. **Nested structures** - a three-level repeater and a two-layout flexible content
   field, verifying each layout kept its own sub-fields.
7. **Error quality** - a misspelled field name suggests the real one.

Failures marked **blocking** are the ones that endanger configuration. A blocking
failure means: do not point this at field groups you care about until it is fixed.
A non-blocking failure is usually cosmetic.

If you do have WP-CLI, the same checks run there and exit non-zero on failure, which
makes them usable as a deployment gate:

```bash
wp acfjp self-test
```

### Send me the result

Screenshot the Diagnostics output, or paste the CLI output. That is the whole
feedback loop - I cannot see your site.

**Expect some red on the first run.** Sixty percent of this code is executing for
the first time. Each check names what it expected and what it got, so most failures
are diagnosable from the output alone. Probably one or two rounds.

---

## 4. Optional - preview-only mode

If at some point you want to point the plugin at a site where you do *not* want any
writes possible, add this to `wp-config.php`:

```php
define( 'ACFJP_READ_ONLY', true );
```

Validation, previews and exports keep working; applying and rolling back are
refused at the Guard, before anything is written. There is also a checkbox under
Settings, but the constant is better for a site that matters because a UI slip
cannot reach it.

**This is off by default and does not affect you unless you turn it on.**

---

## 5. Step two - use it like a developer would

**Time: about an hour. Do this after the smoke test is green.**

The smoke test proves the engine. Only you can tell me whether the product is any good.

1. Create a field group in ACF by hand - a realistic one, with a repeater and some
   nesting.
2. Go to **FieldPilot → Import JSON**.
3. Click **Generate an AI prompt**, pick your group, describe a change, copy the
   prompt.
4. Paste it into ChatGPT or Claude. Take whatever JSON comes back - do not clean it up.
5. Paste that into the editor and hit **Preview changes**.

Then tell me:

- Did the AI's raw output import, or did it need hand-fixing? If it needed fixing,
  **send me the exact JSON it produced.** The alias table in `Json/Aliases.php`
  exists precisely for this, and every real example makes it better.
- Is the diff screen readable? Can you tell at a glance what will happen?
- Does the conflict wording explain the trade-off well enough to decide?
- Apply it. Check the group in ACF. Did anything change that should not have?
- Roll it back from History. Did it come back exactly?

Things worth deliberately trying to break: a field group with a Clone field in it; a
group with 100+ fields; JSON wrapped in a markdown fence; a typo'd field name; a
delete of a field that has content on a real post.

---

## 6. Step three - static analysis

**Time: 30 minutes plus however long the fixes take.**

Once the smoke test is green:

```powershell
composer install
vendor\bin\phpstan analyse
vendor\bin\phpcs
```

PHPStan at level 8 on 12,000 lines of first-draft code will find things - mostly
missing null checks on ACF's loosely-typed returns. Paste the output; these are
mechanical fixes and I will work through them.

`composer install` needs network access to Packagist. It failed in my sandbox but
will work fine on your machine.

---

## 7. Step four - a real site

**Only after everything above is green.**

Take a **copy** of a real project - never the live site, and take a database backup
first. Install both plugins. Try a change you would genuinely want to make.

This is where you find out whether the product is useful, as distinct from correct.
Synthetic fixtures never have the field group somebody built in 2019 with three
nested clones and a repeater inside a flexible layout.

---

## 8. Decisions that are yours, not mine

Not urgent, but they shape what gets built next.

- **Free vs paid split.** The obvious line is: import, preview and apply free; history,
  rollback, REST and CLI paid. I have opinions but no stake.
- **Name.** FieldPilot – AI & JSON Copilot for ACF (`fieldpilot-for-acf`). Clean, distinctive, and fully compliant with WordPress.org guidelines.
- **Distribution.** WordPress.org has reach and a review queue; selling direct means
  handling licensing and updates yourself. This affects whether licensing work gets
  scheduled at all.
- **ACF floor.** I set it at 6.2. Raising it to 6.8 buys strict validation from ACF's
  own schemas on every install and drops a compatibility branch; it also drops anyone
  who has not updated.

---

## 9. Working with me from here

Most useful things to send, in order:

1. **Terminal output, verbatim.** Whole error, file, line. Not a summary.
2. **The JSON that misbehaved**, exactly as produced.
3. **What you expected versus what happened**, when it is a judgement call rather than
   an error.
4. **Screenshots**, for anything visual. I can look at images.

You do not need to diagnose anything. "I ran the smoke test, here is the output" is
the perfect message.

---

## 10. Realistic sequence

| # | Step | Your time | Then |
|---|---|---|---|
| 1 | Install the plugin on your site | 5 min | Report if it fatals |
| 2 | Diagnostics → Run self-test | 2 min | **Send me the result** |
| 3 | I fix what it caught | - | Re-run |
| 4 | Repeat 2-3 until green | ~1 hour total | |
| 5 | Use the UI as a developer | 1 hour | Tell me how it felt |
| 6 | PHPStan + PHPCS | 30 min | Send output |

Steps 1 and 2 are the whole critical path. Everything else can wait.

A local wp-env setup is configured in the repo (`npm run env:start`) if you ever
want one for faster iteration, but it is no longer on the critical path.

---

## 11. If something goes badly wrong

The self-test only touches field groups it created, so it cannot damage your existing
configuration. Take a database backup before you start applying *real* changes, though
- not because the plugin is expected to misbehave, but because you will be editing
field groups, and a backup is the difference between an inconvenience and an evening.

To start the environment over from scratch:

```powershell
npm run env:destroy
npm run env:start
```

To see what WordPress is complaining about:

```powershell
npm run logs
```

To run an arbitrary WP-CLI command inside the container:

```powershell
npx wp-env run cli wp plugin list
npx wp-env run cli wp acfjp groups
```

That last one is worth trying once things are running - it lists every field group
and whether this plugin can change it, which is the fastest way to see the mutability
classifier working.
