# UTM Attribution for WooCommerce

**UTM Attribution for WooCommerce** is a privacy-first, zero-dependency WordPress plugin that helps you understand which marketing campaigns drive traffic and revenue on your WooCommerce store. It captures standard UTM parameters and attributes orders to the original visit directly within your WordPress database.

---

## 🚀 Key Features

- **Automatic UTM Capture**: Records `utm_source`, `utm_medium`, `utm_campaign`, `utm_term`, and `utm_content`.
- **Order Attribution**: Links WooCommerce orders to the original UTM visit using a secure, signed cookie.
- **Revenue Reporting**: Integrated dashboard with KPI cards (Visits, Conversions, Revenue).
- **Interactive Charts**: Visualize performance trends over time with Chart.js.
- **Privacy Focused**: IP addresses are SHA-256 hashed (salted) by default. No third-party tracking scripts.
- **Developer Friendly**: Highly extensible via WordPress filters and actions.

## 🛠 Installation

1. Upload the `utm-attribution-for-woocommerce` directory to your `/wp-content/plugins/` directory.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Ensure **WooCommerce** is installed and active.
4. Navigate to **UTM Attribution** in the admin sidebar to view your reports.

## 💻 Developer Customization

The plugin is designed to be extensible. Below are some common filters you can use:

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
If you don't need to anonymize visitor IPs (ensure you comply with local privacy laws).
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
Bots, non-GET requests, `wc-ajax`, 404s and feeds are skipped by default.
```php
add_filter( 'utm_attribution_skip_capture', function( $skip, $user_agent ) {
    return $skip || false !== stripos( $user_agent, 'MyMonitor' );
}, 10, 2 );
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
```

## 📊 Database Schema

The plugin creates two optimized tables on activation:
- `{prefix}utm_attribution_visits`: Records all UTM-tagged landings.
- `{prefix}utm_attribution_conversions`: Records orders linked to visits.

## ⚖️ License

Distributed under the GPLv2 or later license. See `LICENSE` for more information.
