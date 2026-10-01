---
description: Review the current release branch (code + security + attribution) and, if clean, merge it into main
allowed-tools: Bash, Read, Edit, Agent
---

You are finishing a release branch for **UTM Attribution for WooCommerce**: review it, and
only if it passes, merge it into `main`.

This command must NOT merge anything if the review finds blocking issues or a gate fails.
The review gate is the entire point of this command.

## 1. Preconditions

- `git rev-parse --abbrev-ref HEAD` MUST match `release/*`. Otherwise STOP.
- `git status --porcelain` MUST be empty. Otherwise STOP.
- Extract `<version>` from the branch name.
- `git fetch origin`.

## 2. Compute the diff

- `git diff main...HEAD --stat`, then `git diff main...HEAD`.

## 3. Code review

Review the diff yourself against `CLAUDE.md`, focusing on what the gates and agents don't
cover:

- New class files: included in `Utm_Attribution::includes()` and self-instantiated at the
  bottom of the file; admin-only classes inside the `is_admin()` block.
- New filters/actions prefixed `utm_attribution_` and documented in `README.md`.
- Anything contradicting `CLAUDE.md` — say whether the code or the doc is wrong.
- Debug output, dead code, commented-out blocks.

Do not hand-check what `phpcs.xml.dist` enforces (prepare, escaping, nonce, text domain,
PHP 7.4 compatibility) — step 5 runs it.

## 4. Security & attribution review

Dispatch **in parallel, in a single message**, with the full diff:

- **`security-auditor`** — always, if the diff touches any PHP or `assets/js/admin.js`.
- **`attribution-auditor`** — if the diff touches capture, conversion, reports, install,
  uninstall, any `woocommerce_*` hook, filter/action names, option keys or schema.

Both are read-only. Collect every finding; do not let them apply fixes. Deduplicate, resolve
disagreements by reading the code, and **open the cited `file:line` for every CRITICAL and
HIGH before treating it as blocking.** Drop or downgrade any that the line does not support,
and say so.

## 5. Gates

- `find . -name '*.php' -not -path './dist/*' -exec php -l {} \; | grep -v '^No syntax errors'`
  — any output is blocking.
- `~/.config/composer/vendor/bin/phpcs --standard=phpcs.xml.dist -q --warning-severity=0 .`
  — errors are blocking.
- `wp plugin check utm-attribution-for-woocommerce --path=/var/www/html/terawallet --exclude-directories=bin,.claude,dist --exclude-files=phpcs.xml.dist,CLAUDE.md,.distignore,.gitignore`
  — the excludes are dev files `.distignore` strips (unexcluded, they raise
  `application_detected` errors). ERROR rows are blocking; warnings are reported, not
  blocking. `outdated_tested_upto_header` means bump `Tested up to:` in `readme.txt` after
  testing against the current WordPress.
- `wp i18n make-pot . languages/utm-attribution-for-woocommerce.pot --exclude=dist,bin --path=/var/www/html/terawallet`
  — must succeed; the regenerated file is committed in step 9.

## 6. Version consistency

All must equal `<version>`:
- `Version:` header in `utm-attribution-for-woocommerce.php`
- `public $version` in `includes/class-utm-attribution.php`
- `Stable tag:` in `readme.txt`
- the branch name

Mismatch → STOP.

## 7. Changelog

Dispatch **`utm-changelog-writer`**:

> Write the changelog block and Upgrade Notice for UTM Attribution v<version>.
> Commit range: `main..release/<version>`. Release date: <today, Month D, YYYY>.

Replace the `= <version> (Unreleased) =` block in `readme.txt` with its output, and the
`= <version> =` Upgrade Notice entry with its notice. You own the wording; review before
pasting.

- If the entry still only says `Development in progress.`, and the writer found nothing
  user-visible, use its maintenance bullet — but tell the user.
- The Upgrade Notice body must be **≤ 300 characters** — check it.
- Older entries stay untouched.

## 8. Decision gate

Any surviving CRITICAL/HIGH, any failing gate in step 5/6 → **STOP**. Present an organized
report. Do NOT merge.

Otherwise summarize (what changed, agent results, gate results) and continue.

## 9. Commit finalization

If `git status --porcelain` is non-empty:
- `git add readme.txt languages/utm-attribution-for-woocommerce.pot`
- `git commit -m "chore(release): finalize v<version> changelog and regenerate translations"`
- `git push`

## 10. Merge into main

- `git checkout main && git pull origin main`
- `git merge --no-ff release/<version> -m "Release v<version>"`
- `git tag v<version>`
- `git push origin main && git push origin v<version>`

## 11. Clean up

- `git branch -d release/<version>`
- `git push origin --delete release/<version>`

## 12. Report

- Merge commit hash on `main` and the pushed tag.
- Review summary (code review, agent findings, gates).
- That WordPress.org publishing is separate: run **`/build-dist`** (now on `main`).
