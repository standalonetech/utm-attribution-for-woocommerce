# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

WordPress plugin (WooCommerce required, WP 6.4+, PHP 7.4+). It records inbound visits (UTM, referrer, or direct), attributes WooCommerce orders to the visit, and shows reports, list tables, and CSV export in wp-admin. It's plain PHP with no build step, no Composer/npm deps, and no test suite. Chart.js is vendored at `assets/js/chart.min.js`, so it never loads from a CDN.

The plugin lives inside a live local WordPress install at `/var/www/html/terawallet`. Verification is manual against that site.

## Commands

```bash
# Lint (WordPress Coding Standards installed globally; ruleset in phpcs.xml.dist, currently 0 errors).
# phpcs passing does NOT mean Plugin Check passes: its bundled WPCS flags more (e.g. fwrite/fclose). Run both.
~/.config/composer/vendor/bin/phpcs --standard=phpcs.xml.dist .
~/.config/composer/vendor/bin/phpcbf --standard=phpcs.xml.dist <file>   # autofix

# WordPress.org Plugin Check (plugin-check is active on the local site)
wp plugin check utm-attribution-for-woocommerce --path=/var/www/html/terawallet --exclude-directories=bin,.claude,dist --exclude-files=phpcs.xml.dist,CLAUDE.md,.distignore,.gitignore

# PHP syntax check
find . -name '*.php' -exec php -l {} \;

# Inspect data
wp db query "SELECT * FROM wp_utm_attribution_visits ORDER BY id DESC LIMIT 10" --path=/var/www/html/terawallet
```

## Architecture

- **Bootstrap:** `utm-attribution-for-woocommerce.php` loads the singleton `Utm_Attribution` (`includes/class-utm-attribution.php`). That class defines `UTM_ATTRIBUTION_*` constants and `include_once`s every class file. **Each class file instantiates itself at the bottom** (`new Utm_Attribution_Capture();`), so adding a class means adding an include in `includes()`. Admin and list-table classes load only when `is_admin()`.
- **Capture** (`class-utm-attribution-capture.php`, hooked on `wp`): a request with `utm_source` always creates a new visit. Without UTMs, a visit is recorded only when there's no valid attribution cookie and the referrer isn't internal. Referrers are classified as organic (search engines), social, or `referral`. No referrer means `(direct)/(none)`. The optional `utm_site_id` param dedupes visits through a UNIQUE `site_id` column.
- **Cookie:** `utm_attribution_vid` = `{visit_id}|{hmac_sha256(visit_id, wp_salt('auth'))}`, HttpOnly. The helpers in `includes/helpers/utm-attribution-functions.php` are the only place to read or write it.
- **Conversion** (`class-utm-attribution-conversion.php`): hooks `woocommerce_order_status_{status}` for the filtered status list. It reads the visit ID from the cookie, so attribution only works when the status change happens in the customer's own request. Admin or webhook status changes have no cookie unless `utm_attribution_enable_user_stitching` is on. `INSERT IGNORE` plus a UNIQUE `order_id` makes it idempotent.
- **Read side:** `Utm_Attribution_Reports` (static query methods) feeds `Utm_Attribution_Admin` (menu pages, date-range resolution, `wp_localize_script` data for `assets/js/admin.js`) and the view `includes/admin/views/dashboard.php`. `Utm_Attribution_Export` intercepts `admin_init` on `?utm-export=visits|conversions|campaigns`, with a nonce `utm_attribution_export` and a capability check.
- **Schema:** two custom tables are created through `dbDelta` in `Utm_Attribution_Install::get_schema()`. That only runs on the **activation hook**, and nothing compares `utm_attribution_db_version` on load. After a schema change, add an upgrade check, or reactivate the plugin locally. `uninstall.php` drops both tables and the options.

## Conventions

- Direct `$wpdb` queries are used throughout. Keep them `prepare()`d and keep the existing targeted `phpcs:ignore`/`phpcs:disable` annotations (WordPress.DB.*, PluginCheck.*), because the code is meant to pass Plugin Check for WordPress.org.
- Dates are stored in UTC (`current_time( 'mysql', true )`).
- Every public string uses the text domain `utm-attribution-for-woocommerce`. Prefix filters and actions with `utm_attribution_`. The existing public filters are documented in `README.md`; update it when you add a filter.
- Version lives in 3 places: the plugin header in `utm-attribution-for-woocommerce.php`, `Utm_Attribution::$version`, and `Stable tag` in `readme.txt`. `/start-release` bumps all three.
- New changelog entries use `* Category - Sentence.` (Security/New/Fix/Tweak/Performance). All history stays in `readme.txt`.

## Agentic workflow

`.claude/` is version-controlled (except `agent-memory/`, `settings.local.json`).

- **Hook:** `bin/phpcs-hook.sh` runs `php -l` + phpcs on every PHP file Claude writes and **blocks on errors** (exit 2). Keep `phpcs.xml.dist` at 0 errors.
- **Commands:** `/start-release [x.y.z]` (branch `release/*` off `main`, bump version, changelog stub) → `/review-plugin [ref|--staged|paths]` (read-only review, any time) → `/finish-release` (agents + phpcs + Plugin Check + make-pot gate, then merge to `main` and tag) → `/build-dist` (stage `dist/` via `.distignore` for WordPress.org SVN).
- **Agents** (all read-only): `security-auditor` (visitor-data XSS, CSV injection, cookie, SQL, caps, privacy), `attribution-auditor` (invariants A1–A8: capture, conversion, UTC, report math, HPOS, schema upgrades, back-compat), `utm-changelog-writer`, `utm-feature-architect` (opus, specs before building features).
- No QA agent and no CI until a PHPUnit suite exists.
