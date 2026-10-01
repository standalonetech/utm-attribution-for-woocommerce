---
description: Package the plugin runtime files into dist/ for the WordPress.org SVN repo
allowed-tools: Bash, Read
---

You are packaging **UTM Attribution for WooCommerce** for the WordPress.org plugin SVN
repository. This only (re)creates `dist/`. It does not touch git, SVN, or version metadata.

## 1. Preconditions

- `git rev-parse --abbrev-ref HEAD`. If not `main`, WARN that `dist/` will reflect the
  current branch and ask the user to confirm.
- `.distignore` must exist. If missing, STOP — it is the source of truth for exclusions.

## 2. Stage dist/

There is no build step — the plugin ships its sources as-is.

- `rm -rf dist && mkdir dist`
- `rsync -a --exclude-from=.distignore --exclude='/dist' ./ dist/`

Expected payload: `utm-attribution-for-woocommerce.php`, `includes/`, `assets/`,
`languages/`, `readme.txt`, `uninstall.php`, `LICENSE`.

## 3. Verify

- `ls -la dist/` so the user can eyeball it.
- These must be absent: `dist/.git`, `dist/.claude`, `dist/bin`, `dist/CLAUDE.md`,
  `dist/README.md`, `dist/phpcs.xml.dist`, `dist/.gitignore`, `dist/.distignore`.
  Any present → STOP and report.
- `dist/` must not appear in `git status --porcelain` (it is gitignored).

## 4. Report

- Payload contents and size (`du -sh dist/`).
- Version being packaged (`Stable tag:` from `readme.txt`).
- Remaining manual SVN steps:

  ```bash
  # from your utm-attribution-for-woocommerce SVN working copy:
  cp -a /var/www/html/terawallet/wp-content/plugins/utm-attribution-for-woocommerce/dist/* trunk/
  svn add trunk/* --force
  svn cp trunk tags/<version>
  svn ci -m "Release <version>"
  ```

- `dist/` is never committed to git.
