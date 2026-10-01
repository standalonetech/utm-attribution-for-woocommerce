---
description: Create a new release branch with the next plugin version and bump version metadata
argument-hint: "[version]  (optional — defaults to next patch bump)"
allowed-tools: Bash, Read, Edit
---

You are starting a new release branch for **UTM Attribution for WooCommerce**
(`utm-attribution-for-woocommerce`).

The optional argument is a target version: `$ARGUMENTS`

Follow these steps exactly. If any precondition fails, STOP and tell the user — do not
work around it.

## 1. Preconditions

- `git status --porcelain` must be empty. Otherwise STOP: commit or stash first.
- `git rev-parse --abbrev-ref HEAD` must be `main`. Otherwise STOP.

## 2. Sync main

- `git pull origin main`

## 3. Determine the target version

- Read the current version from the `Version:` line in `utm-attribution-for-woocommerce.php`.
- If `$ARGUMENTS` is a valid `x.y.z` string strictly greater than the current version, use
  it. If it is given but not greater, STOP.
- Otherwise compute the next **patch** version (`1.2.0` → `1.2.1`).

## 4. Create the release branch

- `git checkout -b release/<version>`

## 5. Bump the version in all three locations

1. `utm-attribution-for-woocommerce.php` — header line ` * Version: <x.y.z>`.
2. `includes/class-utm-attribution.php` — `public $version = '<x.y.z>';`
   (this feeds the `UTM_ATTRIBUTION_VERSION` constant and asset cache-busting).
3. `readme.txt` — `Stable tag: <x.y.z>`.

## 6. Add changelog and upgrade-notice stubs

All history stays in `readme.txt` (it is well under WordPress.org's ~10 KB guidance; there is
no `changelog.txt`). Insert at the top of the `== Changelog ==` section, directly after the
heading and a blank line:

```
= <version> (Unreleased) =
* Tweak - Development in progress.

```

Insert at the top of `== Upgrade Notice ==`:

```
= <version> =
Development in progress.

```

Do not rewrite older entries — the `**Feature:**` style used up to 1.2.0 is left as-is. New
entries use `* Category - Sentence.` with categories `Security`, `New`, `Fix`, `Tweak`,
`Performance`.

## 7. Commit and push

- `git add utm-attribution-for-woocommerce.php includes/class-utm-attribution.php readme.txt`
- `git commit -m "chore(release): start v<version>"`
- `git push -u origin release/<version>`

## 8. Report

Tell the user:
- previous → new version, and the branch `release/<version>` (pushed).
- To develop on this branch, replace the `Development in progress.` placeholders, and run
  `/finish-release` from this branch when ready.
