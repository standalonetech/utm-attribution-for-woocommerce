=== UTM Attribution for WooCommerce ===
Contributors: standalonetech
Tags: utm, attribution, woocommerce, conversions, campaign tracking
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Requires Plugins: woocommerce
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Stable tag: 1.3.2

See which campaigns, search engines and social sites bring visitors and WooCommerce sales — tracked in your own database, no third-party service.

== Description ==

**UTM Attribution for WooCommerce** records how each visitor reached your store and credits their WooCommerce orders to that visit, so you can see visits, conversions and revenue per campaign right in your WordPress admin. Everything is stored in two tables in your own database. No external analytics account, tracking script or CDN is involved.

= How visits are recorded =

* **UTM-tagged links** — `utm_source`, `utm_medium`, `utm_campaign`, `utm_term` and `utm_content` are saved whenever a visitor lands on a tagged URL.
* **Untagged traffic** — Visitors without UTM tags are classified from the referrer: **organic** (Google, Bing, Yahoo, DuckDuckGo, Baidu, Yandex), **social** (Facebook, X/Twitter, Instagram, LinkedIn, Pinterest, Reddit, Telegram), **referral** (any other site), or **direct**.
* **Noise filtered out** — Bots, crawlers, uptime monitors, HTTP tools, shop staff, sitemaps, `/.well-known/` files, 404 pages, feeds, AJAX and non-GET requests don't create visits. Repeat requests from the same browser and source without cookies within 30 minutes count once, and reloading the same tagged link within 30 minutes doesn't count twice.
* **Click deduplication** — An optional `utm_site_id` parameter lets you give each link click a unique ID so it is recorded only once.

= How orders are attributed =

* The visit is remembered in a signed, HttpOnly cookie (30 days by default). A new UTM click replaces it, so the most recent campaign gets the credit (last-touch).
* At checkout — classic or block checkout — the visit is saved on the order. When the order later reaches **processing** or **completed**, the conversion is recorded, even if that happens through a payment webhook, an admin action or cron.
* Refunds and cancellations are kept in sync: revenue is net of refunds, and cancelled, refunded or failed orders drop out of the reports.
* Optional user stitching can credit logged-in customers' orders to their most recent visit within the cookie lifetime.

= Reports =

* **Dashboard** — Total visits, conversions, conversion rate and revenue, a visits-and-conversions chart, and a top campaigns table.
* **Date ranges** — Today, Last 7 / 30 / 90 days, This Year, or a custom range, all in your site's timezone. Visits are counted by visit date, and conversions and revenue by order date.
* **Visits and Conversions lists** — Paginated tables of every recorded visit and attributed order, with links to the order.
* **CSV export** — Visits, conversions and top campaigns, with spreadsheet formula injection blocked.
* Revenue totals include orders in the store's currency only.

= Privacy =

* IP addresses are never stored. A salted SHA-256 hash is stored instead, or nothing at all if you turn hashing off.
* Landing URLs keep only the page path and UTM parameters. Other query values, such as order keys or emails, are dropped.
* Visit data is included in the WordPress **Export Personal Data** and **Erase Personal Data** tools.
* The IP hash, user agent, referrer and user ID are removed from visits older than 365 days. Campaign totals are kept. The retention period is adjustable.

= Settings =

**UTM Attribution → Settings** lets you turn each traffic filter on or off, add your own user agents, URL paths and internal domains to ignore, and set the attribution window, user stitching, IP hashing and personal data retention.

= Developer friendly =

Filters for bot and path patterns, excluded users, internal domains, the cookie lifetime, which order statuses count as conversions, the user capability, IP hashing, user stitching, capture skipping and data retention, plus actions when the plugin loads and when a conversion is recorded. Compatible with WooCommerce High-Performance Order Storage (HPOS).

== Installation ==

1. Upload the `utm-attribution-for-woocommerce` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Make sure WooCommerce is installed and active.
4. Visit **UTM Attribution** in your WordPress admin menu to view your reports.

== Frequently Asked Questions ==

= Does this plugin require WooCommerce? =

Yes. Orders are attributed through WooCommerce, and the report screens are hidden while WooCommerce is inactive.

= How does the plugin attribute an order to a visit? =

The visit ID is stored in a signed, HttpOnly cookie when the visitor lands. At checkout it is also saved on the order. When the order reaches "processing" or "completed", the plugin links it to that visit, even when the status change comes from a payment gateway webhook or an admin.

= Which campaign gets credit if a customer clicks several links? =

The most recent one. Each new UTM-tagged click replaces the visit stored in the cookie.

= Can I change how long the attribution cookie lasts? =

Yes. Use the `utm_attribution_cookie_lifetime_days` filter:

`add_filter( 'utm_attribution_cookie_lifetime_days', function() { return 60; } );`

= Are IP addresses stored? =

No. A SHA-256 hash of the IP, salted with your site's auth salt, is stored instead. If you don't need it, turn hashing off and no IP data is stored at all:

`add_filter( 'utm_attribution_enable_ip_hashing', '__return_false' );`

= How long is visitor data kept? =

Visits are kept for reporting. Once a visit is 365 days old, its IP hash, user agent, referrer and user ID are removed. Change the period, or return 0 to keep that data:

`add_filter( 'utm_attribution_pii_retention_days', function() { return 90; } );`

= Can I change which order statuses trigger a conversion? =

Yes, use the `utm_attribution_conversion_order_statuses` filter:

`add_filter( 'utm_attribution_conversion_order_statuses', function() { return array( 'completed' ); } );`

== Screenshots ==

1. Dashboard with KPI cards, performance chart, and top campaigns table.
2. Date range filter with preset buttons and a custom date picker.
3. Visits list showing captured UTM parameters for every tagged session.
4. Conversions list showing WooCommerce orders attributed to UTM visits.

== Changelog ==

= 1.3.2 (Unreleased) =
* New - Tools tab scans past visits for bots, monitors, non-page URLs, repeat visits and staff, lets you download them as CSV, and deletes them in the background.
* New - Visits list has a Delete bulk action; visits linked to orders are never deleted.
* New - Action utm_attribution_visits_deleted fires after visits are deleted.

= 1.3.1 (October 2, 2026) =
* New - Settings page under UTM Attribution for traffic filtering, internal domains, attribution window, user stitching, IP hashing and personal data retention.
* New - Filters `utm_attribution_bot_ua_patterns`, `utm_attribution_noise_path_patterns`, `utm_attribution_exclude_user` and `utm_attribution_internal_domains`.
* Tweak - Uptime monitors, more crawlers and HTTP tools, sitemaps, /.well-known/ probes and favicon requests no longer create visits. Existing visits are not changed.
* Tweak - Visits by logged-in administrators and shop managers are no longer recorded.
* Tweak - Repeat visits from the same browser and source without cookies within 30 minutes are merged into one.
* Tweak - Subdomains of the domains you list in Settings count as internal rather than as referrals. Your own site's host matches exactly, as before.
* Performance - Added a database index for the repeat-visit check, installed automatically on update.

= 1.3.0 (October 1, 2026) =
* Security - CSV exports no longer let visitor-supplied UTM values run as spreadsheet formulas.
* Security - A shared `utm_site_id` link can no longer be used to take over another visitor's visit.
* New - Visit data is included in the WordPress personal data export and erase tools.
* New - Personal data on visits older than 365 days is removed daily; change this with the `utm_attribution_pii_retention_days` filter.
* New - Declared compatible with WooCommerce High-Performance Order Storage (HPOS).
* New - `utm_attribution_skip_capture` filter lets developers skip visit capture for specific requests.
* Fix - Orders paid through webhooks (PayPal, bank transfer and others) or completed by an admin are now attributed to their visit. Past orders are unchanged.
* Fix - Dashboard counts conversions and revenue by order date, so orders from earlier visits are no longer missing.
* Fix - Dashboard date ranges and all displayed times now use the site's timezone.
* Fix - Refunds and cancellations now reduce conversions and revenue.
* Fix - Revenue totals only include orders in the store currency.
* Fix - The `utm_attribution_conversion_order_statuses` filter now works when added from a theme or a later-loading plugin.
* Fix - Order links on the Conversions screen open correctly with HPOS enabled.
* Fix - Visit ID links on the Conversions screen now open that visit.
* Fix - Search engines and social sites are matched by exact domain, so netflix.com no longer counts as Twitter.
* Fix - Optional user stitching now only matches visits from before the order, within the cookie lifetime.
* Tweak - Bots, 404 pages, feeds and background requests no longer create visits.
* Tweak - Reloading the same tagged link no longer creates a duplicate visit.
* Tweak - Landing URLs keep only the path and UTM parameters; other query values are no longer stored.
* Tweak - The dashboard now uses the full available screen width.
* Tweak - Database tables update automatically after a plugin update.
* Tweak - Report screens are hidden while WooCommerce is inactive.

= 1.2.0 =
* **Feature:** Added CSV export for Visits, Conversions, and Top Campaigns data.
* **Improvement:** Export buttons on Visits and Conversions list tables.
* **Improvement:** Export button on Dashboard Top Campaigns section with date range support.
* **Security:** CSV exports are protected by nonce verification and capability checks.

= 1.1.0 =
* **Feature:** Added Organic and Social media attribution via referrer detection.
* **Feature:** Added Direct traffic attribution for visitors without UTMs or referrers.
* **Improvement:** Enhanced capture logic to prevent duplicate visits during the same session.

= 1.0.1 =
– **Fix:-** Update chart.js to latest version.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.3.2 =
Clean up past bot visits: UTM Attribution → Settings → Tools scans, previews and removes them in the background. Visits linked to orders are never deleted.

= 1.3.1 =
Far fewer junk visits: uptime monitors, more bots, sitemap and /.well-known/ requests, staff and repeat cookieless visits are no longer recorded. New Settings page under UTM Attribution. Existing data is not changed.

= 1.3.0 =
Security: fixes CSV formula injection and visit takeover through shared links. Webhook and admin-completed orders are now attributed, refunds reduce totals, and personal data on visits older than 365 days is removed daily.

= 1.0.0 =
Initial release.
