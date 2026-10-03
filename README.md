# UTM Attribution for WooCommerce

**UTM Attribution for WooCommerce** records how each visitor reached your store — UTM-tagged campaign, search engine, social network, referring site or direct — and credits their WooCommerce orders to that visit. Visits, conversions and revenue per campaign are reported in wp-admin. All data lives in two tables in your own database. There is no third-party service, no front-end tracking script and no CDN, and Chart.js is bundled.

---

## 🚀 Key Features

- **Visit capture**: Saves `utm_source`, `utm_medium`, `utm_campaign`, `utm_term` and `utm_content` from tagged URLs. Untagged traffic is classified from the referrer as organic, social, referral or direct.
- **Clean data**: Bots, crawlers, uptime monitors, HTTP tools, shop staff, sitemaps, `/.well-known/` files, 404 pages, feeds, AJAX and non-GET requests are skipped. Repeat requests from the same browser and source without cookies within 30 minutes count once, and reloading a tagged link within 30 minutes doesn't create a duplicate. Links from domains you list on the Settings page (and their subdomains) count as internal, not as referrals. An optional `utm_site_id` deduplicates individual clicks.
- **Settings page**: **UTM Attribution → Settings** controls traffic filtering, internal domains, the attribution window, user stitching, IP hashing and data retention.
- **Last-touch order attribution**: A signed, HttpOnly cookie remembers the visit, and checkout (classic and block) saves it on the order. Conversions are recorded at `processing`/`completed`, even when the status changes through a payment webhook, an admin or cron.
- **Accurate revenue**: Net of refunds; cancelled, refunded and failed orders are excluded; store currency only.
- **Reports**: Dashboard with visits, conversions, conversion rate and revenue, a chart and top campaigns. Date presets or a custom range in the site timezone. Paginated Visits and Conversions lists.
- **CSV export**: Visits, conversions and campaigns, protected against spreadsheet formula injection.
- **Privacy**: IPs are never stored, only a salted SHA-256 hash or nothing. Landing URLs keep only the path and UTM parameters. The plugin works with the WordPress personal-data export and erase tools, and IP hash, user agent, referrer and user ID are removed after 365 days.
- **WooCommerce HPOS compatible**, with filters and actions for customization (see below).

## 🛠 Installation

1. Upload the `utm-attribution-for-woocommerce` directory to your `/wp-content/plugins/` directory.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Ensure **WooCommerce** is installed and active.
4. Navigate to **UTM Attribution** in the admin sidebar to view your reports.

## 💻 Developer Customization

The plugin is designed to be extensible. Below are some common filters you can use.

Values saved on **UTM Attribution → Settings** are the defaults these filters receive, so a code filter always wins over the Settings page.

### Change Cookie Lifetime
Default is 30 days.
```php
add_filter( 'utm_attribution_cookie_lifetime_days', function() {
    return 60; // 60 days
} );
```

### Adjust Conversion Statuses
By default, orders with 'processing' or 'completed' status are recorded as conversions.
```php
add_filter( 'utm_attribution_conversion_order_statuses', function() {
    return array( 'completed' ); // Only 'completed'
} );
```

### Disable IP Hashing
Stops storing the salted IP hash. No IP data is stored at all; it does not switch to raw IPs.
```php
add_filter( 'utm_attribution_enable_ip_hashing', '__return_false' );
```

### Modify User Capability
Change who can view the reports dashboard.
```php
add_filter( 'utm_attribution_user_capability', function() {
    return 'edit_pages';
} );
```

### Attribute Orders Without a Cookie
When an order has no visit cookie or stored visit, fall back to the customer's most recent visit while logged in, within the cookie lifetime before the order was placed.
```php
add_filter( 'utm_attribution_enable_user_stitching', '__return_true' );
```

### Skip Capture for Certain Requests
Runs last, after every built-in rule. Bots, monitors, shop staff, non-page URLs, non-GET requests, `wc-ajax`, 404s and feeds are skipped by default.
```php
add_filter( 'utm_attribution_skip_capture', function( $skip, $user_agent ) {
    return $skip || false !== stripos( $user_agent, 'MyMonitor' );
}, 10, 2 );
```

### Bot User-Agent Patterns
Case-insensitive regex fragments, joined with `|` inside `#…#i`, so escape any `#`. Plain text entered on the Settings page is already included (matched as a substring). An invalid pattern is ignored rather than blocking visits.
```php
add_filter( 'utm_attribution_bot_ua_patterns', function( $patterns ) {
    $patterns[] = 'mymonitor';
    $patterns[] = '^acme-fetcher/';
    return $patterns;
} );
```

### Non-Page URL Paths
Same format, matched against the URL path only. Paths entered on the Settings page match the start of the path. Defaults cover `/.well-known/`, sitemaps, `.txt/.xml/.json/.ico/.webmanifest` files and feeds.
```php
add_filter( 'utm_attribution_noise_path_patterns', function( $patterns ) {
    $patterns[] = '^/internal-api/';
    return $patterns;
} );
```

### Exclude Users
Logged-in users who can `manage_woocommerce` are skipped while "Exclude shop staff" is on.
```php
add_filter( 'utm_attribution_exclude_user', function( $exclude, $user_id ) {
    return $exclude || user_can( $user_id, 'edit_posts' );
}, 10, 2 );
```

### Internal Domains
Links from these domains and their subdomains are not recorded as referrals. Your own site's host is always internal; subdomains of it are not, unless you list them. The filter receives the domains entered on the Settings page.
```php
add_filter( 'utm_attribution_internal_domains', function( $domains ) {
    $domains[] = 'example-blog.com';
    return $domains;
} );
```

### Personal Data Retention
Visits older than 365 days have their IP hash, user agent, referrer and user ID removed daily. Campaign totals are kept. Return `0` to keep it indefinitely.
```php
add_filter( 'utm_attribution_pii_retention_days', function() {
    return 90;
} );
```

### Actions
```php
// Fires once the plugin has loaded all its classes.
add_action( 'utm_attribution_loaded', function() {} );

// Fires after an order is recorded as a conversion.
add_action( 'utm_attribution_conversion_recorded', function( $conversion_id, $visit_id, $order ) {
    // e.g. push to your CRM.
}, 10, 3 );

// Fires after visits are deleted by the Tools cleanup ('cleanup') or the Visits bulk action ('bulk').
add_action( 'utm_attribution_visits_deleted', function( $visit_ids, $context ) {}, 10, 2 );
```

### Cleaning Up Past Noise
**UTM Attribution → Settings → Tools** scans stored visits with the current filters (bots, uptime monitors, non-page URLs, cookieless repeat visits, staff), lets you download the rows as CSV, and deletes them in the background through Action Scheduler. Visits linked to a conversion or referenced by any order are never deleted. The Visits list also has a **Delete** bulk action with the same protection.

Anyone allowed by `utm_attribution_user_capability` can delete visits, so do not lower it to a role you do not trust.

## 📊 Database Schema

The plugin creates two tables on activation and updates them automatically after plugin updates:
- `{prefix}utm_attribution_visits`: One row per recorded visit (UTM, organic, social, referral or direct).
- `{prefix}utm_attribution_conversions`: Records orders linked to visits.

## ⚖️ License

Distributed under the GPLv2 or later license. See `LICENSE` for more information.
