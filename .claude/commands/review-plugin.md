---
description: Read-only code review of a diff — routes to the security and attribution auditors and reports a gate verdict. Never edits, commits or merges.
argument-hint: "[--staged | <ref> | <paths...>]"
allowed-tools: Bash, Read, Grep, Glob, Agent
---

Review a UTM Attribution change and report what is wrong with it. **This command changes
nothing.**

Unlike `/finish-release`, this runs on any branch at any time, including a dirty working
tree. Use it while the change is still small.

## 0. This command is read-only

You may run `git diff`, `git log`, `git show`, `git status`, `grep`, `phpcs`, and read files.
You may **not** `Edit`, `Write`, `git add`, `git commit`, `git stash`, `git checkout`, or
apply any fix an agent suggests. If the user wants a fix, they ask for it in a separate turn.

## 1. Resolve the scope

`$ARGUMENTS` decides what gets reviewed:

| Argument | Diff to review |
|---|---|
| *(none)* | `git diff HEAD` — uncommitted work. If the tree is clean, fall back to `git diff main...HEAD` and say which you used. |
| `--staged` | `git diff --cached` |
| `<ref>` (e.g. `main`, `HEAD~3`, `release/1.2.1`) | `git diff <ref>...HEAD` |
| one or more paths | those files in full, plus `git log -3 --oneline` on each for context |

Print the scope and `git diff --stat` first. If the diff is empty, say so and stop — do not
review the whole repo.

## 2. Route

Read the changed file list and decide which auditors to dispatch. Do not dispatch both by
default.

| The diff touches | Dispatch |
|---|---|
| `class-utm-attribution-admin.php`, `*-list-table.php`, `includes/admin/views/**`, `class-utm-attribution-export.php`, `assets/js/admin.js`, `includes/helpers/utm-attribution-functions.php` (cookie/IP), any `$wpdb` call, any `$_GET` / `$_POST` / `$_COOKIE` / `$_SERVER` read, any capability / nonce change | `security-auditor` |
| `class-utm-attribution-capture.php`, `class-utm-attribution-conversion.php`, `class-utm-attribution-reports.php`, `class-utm-attribution-install.php`, `uninstall.php`, any `woocommerce_*` hook, any added/changed `utm_attribution_*` filter or action, option keys, table schema | `attribution-auditor` |
| only CSS, `readme.txt`, `README.md`, `CLAUDE.md` | **none** — review it yourself against `CLAUDE.md` |

Routes overlap on purpose: a change to Reports SQL dispatches both.

State which agents you are dispatching and why, one line each, before dispatching.

Also run `~/.config/composer/vendor/bin/phpcs --standard=phpcs.xml.dist -q <changed .php files>`
yourself — it is read-only and cheap. Report errors as blocking.

## 3. Dispatch

Dispatch the selected agents **in parallel, in a single message**. Give each one:

- the exact `git diff` command you used, so they can reproduce it
- the changed file list
- any context the user gave about what the change is meant to do

Both are read-only. If an agent returns a suggested patch, **do not apply it.**

## 4. Merge the findings

- **Deduplicate.** Two agents describing the same defect is one finding; keep the better
  failure scenario and note that both flagged it.
- **Resolve conflicts** by reading the code yourself. Do not pass disagreement through.
- **Drop the noise** — style preferences, generic best practice, unmeasured performance
  worries, pre-existing problems untouched by this change. Say how many you dropped and why,
  in one line.
- **Verify every CRITICAL and HIGH yourself.** Open the cited `file:line`. If it does not
  support the claim, downgrade or drop it and say so.

## 5. Report

```
## Review — <scope>

<one paragraph: what this change does, in your own words>

Agents dispatched: <names, and why>

### Blocking
<CRITICAL and HIGH findings, phpcs errors, or "none">

### Non-blocking
<MEDIUM, LOW, INFO>

### Manual verification
<what to click/curl on the local site to confirm behaviour — there is no test suite>

### Not reviewed
<what nobody covered, and why>

### Verdict
BLOCK — <n> blocking findings
or
PROCEED WITH NOTES — <n> non-blocking findings
or
CLEAN — no findings, coverage stated above
```

Each finding:

```
#### [SEVERITY] <title>   (<agent> · <check id if any>)
* **Where:** file.php:123
* **What:** the defect
* **Failure:** concrete sequence and wrong outcome — a number or a state, not an adjective
* **Fix:** specific, respecting the documented architecture
* **Confidence:** High | Medium | Low
```

## 6. Stop

Report and stop. Do not offer to fix, do not stage, do not merge.
