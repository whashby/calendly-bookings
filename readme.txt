=== Calendly Bookings ===
Contributors: whashby
Tags: calendly, bookings, scheduling, woocommerce, appointments
Requires at least: 5.2
Tested up to: 6.5
Requires PHP: 8.0
Stable tag: 6.9.207
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A CMS-style layer on top of Calendly that syncs events, invitees, and WooCommerce products into WordPress.

== Description ==

Calendly Bookings turns your WordPress site into a structured bookings console for Calendly:

- Syncs event types, available times, scheduled events, and invitees into custom tables.
- Maps event types to WooCommerce products for paid bookings.
- Provides dashboards for admins and account owners.
- Handles webhooks, and background sync.

Built for performance, observability, and safety in production environments.

== Installation ==

1. Upload the plugin ZIP via **Plugins → Add New → Upload Plugin**.
2. Activate the plugin.
3. Go to **Calendly Bookings → Settings** and add your Calendly API token.
4. Map event types to WooCommerce products if needed.
5. The background sync will run every 5 minutes.

== Frequently Asked Questions ==

= Does this replace Calendly? =

No. This plugin sits on top of Calendly and uses its API and webhooks.

= Does it require WooCommerce? =

WooCommerce is only required if you want to map event types to products and take payments.

== Changelog ==

= 6.9.2 =
* Initial public release of the GitHub‑driven updater and background sync engine.
