=== UTM Attribution for WooCommerce ===
Contributors: standalonetech
Tags: utm, attribution, woocommerce, conversions, campaign tracking
Requires at least: 6.4
Tested up to: 6.9
Requires PHP: 7.4
Requires Plugins: woocommerce
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Stable tag: 1.0.1
Donate link: https://donate.stripe.com/fZeaFydax6NNfjWeVc

Capture UTM parameters, attribute WooCommerce purchases to marketing campaigns, and view conversion reports — all inside your WordPress admin.

== Description ==

**UTM Attribution for WooCommerce** helps you understand exactly which marketing campaigns drive traffic and sales on your WooCommerce store.

It automatically captures standard UTM parameters (`utm_source`, `utm_medium`, `utm_campaign`, `utm_term`, `utm_content`) the moment a visitor lands on your site. When that visitor places an order, the plugin attributes the purchase to the original UTM visit so you can see real revenue per campaign — without any third-party analytics service.

= Key Features =

* **Automatic UTM capture** — Records source, medium, campaign, term, and content on every tagged visit.
* **WooCommerce order attribution** — Links orders to the visit that drove them using a secure, signed cookie.
* **Revenue reporting** — See total visits, conversions, conversion rate, and revenue in one dashboard.
* **Date range filter** — Filter by Today, Last 7 / 30 / 90 Days, This Year, or a custom date range.
* **Performance chart** — Visualise visits and conversions over time with an interactive Chart.js graph.
* **Top campaigns table** — Ranked list of campaigns by visits, conversions, and revenue generated.
* **Visits & Conversions lists** — Paginated admin tables showing every captured visit and attributed order.
* **Deduplication** — Optional `utm_site_id` parameter prevents the same click being recorded twice.
* **Privacy-friendly** — IP addresses are SHA-256 hashed before storage; IP hashing can be disabled via filter.
* **Developer-friendly** — Extensible via WordPress filters (`utm_attribution_user_capability`, `utm_attribution_cookie_lifetime_days`, `utm_attribution_enable_ip_hashing`, etc.).

== Installation ==

1. Upload the `utm-attribution-for-woocommerce` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Make sure WooCommerce is installed and active.
4. Visit **UTM Attribution** in your WordPress admin menu to view your reports.

== Frequently Asked Questions ==

= Does this plugin require WooCommerce? =

Yes. Order attribution relies on WooCommerce order status hooks. The UTM capture and visit recording will still work without WooCommerce, but conversion data will not be collected.

= How does the plugin attribute an order to a visit? =

When a visitor arrives via a UTM-tagged URL, the plugin stores the visit ID in a signed, HttpOnly cookie (valid for 30 days by default). When an order reaches "processing" or "completed" status, the plugin reads that cookie and links the order to the original visit.

= Can I change how long the attribution cookie lasts? =

Yes. Use the `utm_attribution_cookie_lifetime_days` filter:

`add_filter( 'utm_attribution_cookie_lifetime_days', function() { return 60; } );`

= Are IP addresses stored? =

IP addresses are hashed with SHA-256 (salted with your WordPress auth key) before being stored. Raw IPs are never written to the database. You can disable IP hashing entirely:

`add_filter( 'utm_attribution_enable_ip_hashing', '__return_false' );`

= Can I change which order statuses trigger a conversion? =

Yes, use the `utm_attribution_conversion_order_statuses` filter:

`add_filter( 'utm_attribution_conversion_order_statuses', function() { return array( 'completed' ); } );`

== Screenshots ==

1. Dashboard with KPI cards, performance chart, and top campaigns table.
2. Date range filter with preset buttons and a custom date picker.
3. Visits list showing captured UTM parameters for every tagged session.
4. Conversions list showing WooCommerce orders attributed to UTM visits.

== Changelog ==

= 1.0.1 =
– **Fix:-** Update chart.js to latest version.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
