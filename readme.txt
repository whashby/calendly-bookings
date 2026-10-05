=== Calendly Bookings ===
Contributors: whashby
Tags: calendly, bookings, scheduling, woocommerce, appointments
Requires at least: 5.2
Tested up to: 6.5
Requires PHP: 8.3
Stable tag: 7.0.0
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
= 6.12.7 =
* Fix invalid availability start_time parameters with a shared future UTC safety margin.
* Preserve Calendly parameter error details and continue availability sync after individual failures.

= 6.12.6 =
* Correct dashboard timezone formatting, background sync status and widget metrics.

= 6.12.5 =
* Fix record history, notes, walk-in positioning, synchronization and report downloads.

= 6.12.4 =

- Persist complete Calendly booking form data on WooCommerce order line items and order meta during checkout.
- Use persisted booking data as the authoritative source for Scheduling API requests.
- Add booking snapshot/version metadata and stronger missing-input diagnostics.
- Remove legacy frontend Order ID question correlation.
- Fix duplicate payload construction in the booking worker.

= 6.12.3 =
* Fixed single-product meeting Add to Cart regression caused by disabling the WooCommerce submit button before form serialization.
* Added a hidden add-to-cart fallback so WooCommerce receives the product ID reliably.
* Added explicit Hesychia request-a-session terminology and preserved booking terminology for all other meeting products.
* Added Event Type URI fallback to server-side cart validation.
* Added contextual confirmation/email terminology for Hesychia.


= 6.12.3 =
* Fix install/update package layout so WordPress preserves the calendly-bookings plugin basename.
* Add packaging validation to prevent flattened installable ZIPs.


= 6.9.2 =
* Initial public release of the GitHub‑driven updater and background sync engine.

== Changelog ==

= 6.12.1 =
* Merged the payment-first booking/reconciliation architecture with the stable UUID-based Calendly Event Type mapping.
* Meeting products no longer expose direct Add to Cart actions in shop/category grids.
* Added server-side protection so meeting products can only enter the cart through a valid completed booking form.
* Optimized frontend asset loading so Flatpickr is loaded only on meeting product pages.
* Preserved Calendly-compliant availability requests using the Event Type URI, UTC timestamps, and a maximum 30-day window inside Calendly's 31-day limit.
* Reduced frontend/API load with short-lived availability caching and eliminated redundant availability requests in the background sync.
* Reduced WordPress backend load by lazy-loading admin, frontend, REST, and debug modules according to request context.
* Removed automatic Calendly synchronization from the Scheduled Events page; refresh is now explicit.
* Optimized scheduled-event synchronization to resolve meeting locations once per batch instead of once per event.
* Rebuilt email templates with reusable tokens, customer/admin recipients, previews, test delivery, and idempotent webhook-driven notifications.
* Rebuilt reporting with a persistent report table, asynchronous generation, CSV/XLSX/PDF output, secure downloads, retention, previews, and low-memory batch processing.
* Rebuilt the Settings UI so Reports and Email pages no longer instantiate heavyweight editors or perform unnecessary AJAX requests on unrelated settings tabs.
* Kept WooCommerce Order ID out of the customer-facing booking form; it is correlated internally after the order exists.

= 6.10.1 =
- Added a dedicated booking/reconciliation service for WooCommerce → Calendly booking creation.
- Added Action Scheduler queueing and automatic retry/backoff for failed Calendly bookings.
- Added idempotent order-level booking locks and persistent booking lifecycle metadata.
- Added webhook-first customer confirmation data source for the meeting-scheduled endpoint.
- Confirmation pages no longer render appointment details from URL parameters or pre-webhook WooCommerce data.
- Added secure Calendly webhook timestamp/HMAC validation and replay protection.
- Added webhook persistence and reconciliation for invitees, scheduled events, cancellations, and reschedules.
- Added live Calendly Event Type and invitee creation API helpers.
- Corrected the availability method so it returns API data instead of printing it.
- Corrected activation/deactivation hook registration and schema migration handling.
- Replaced release automation with tag-driven, manually initiated semantic versioning to avoid recursive release commits.
