# Dashboard widget corrections — 6.12.6

The API Health & Sync widget previously saved manual sync times with a 12-hour h format without an AM/PM marker. Its timestamp option was separate from the options updated by cron. Its API status was a hardcoded OK, and manual sync failures could be reported as success. Those behaviors are corrected.

Each master or individual sync records a UTC Unix timestamp for its last attempt and last successful result, plus errors. Failures preserve the previous successful time. The dashboard and Settings now read actual WordPress cron events through the same schedule provider; enabling an option without a scheduled job does not create a false enabled status. The widget shows frequency, next run, and last success for each background job. All displayed dates use the WordPress site timezone, including fixed-offset settings. Legacy offset timestamps are normalized and marked legacy until a new run records a verified result.

Manual sync and full refresh now use nonce-protected POST requests. Normal sync respects the configured minimum date; full refresh explicitly requests all history. Both return HTTP errors on failure. Widget refreshes require no live Calendly call; the health status describes recorded sync results and does not claim current upstream connectivity. If WP-Cron is disabled, the widget explains that an external scheduler is needed.

All seven widgets were reviewed and updated:

| Widget | Correction |
|---|---|
| API Health & Sync | UTC timestamp recording, correct AM/PM display, shared Settings schedule source, per-job next run and last success, genuine errors, safe manual sync request. |
| Next Available Slots | Displays the exact earliest available UTC slot with remaining capacity, converted to site timezone. Removes invented rounding and the arbitrary 30-minute cutoff. Clearly labels availability as cached. |
| Booking Trends | Applies UTC database range bounds and groups meetings into days in the site timezone. Uses integer chart ticks. |
| Revenue from Meetings | Uses historical linked WooCommerce order-line totals after discounts and refunds, excluding tax. Removes booking count multiplied by today's product price. Deduplicates each order/event-type association; preserves stored booking identity after product relinking. Orders in other currencies are excluded and counted explicitly. |
| Performance | Uses the same booking range and net-sales calculation as the revenue widget. Canceled meetings are excluded; sales require linked processing/completed/refunded orders. |
| Recent Bookings | Shows the ten latest records added, including future meetings, with one row per event and combined invitee names. Avoids duplicate rows from joined invitees and incorrect UTC parsing. |
| Data Integrity | Escapes displayed data, handles request failures, labels the ten-row display limit, and formats timestamps in site timezone. Repair actions are still explicit user actions; no repair actions were run during validation. |

The client no longer initializes performance twice, no longer defines competing API helpers, and no longer depends on an unavailable WordPress notices store. Missing widgets and chart loading errors are handled. Chart.js is pinned to its declared 4.4.0 version and its dependency is explicit. Widgets refresh every minute while the dashboard is visible and after a successful manual sync; selected date ranges are retained.

Validation: 23 dashboard PHP checks and 10 client rendering/request checks passed. These cover a 5:30 PM Barbados display, fixed-offset timezone handling, shared schedule data, recorded failures, preserved successful timestamps, historical line totals and refunds, UTC-midnight grouping, exact quarter-hour slot times, all seven local REST endpoints, matching revenue/performance totals, single initialization, escaped output, and HTTP failure handling. The existing 20 regression checks also passed, and PHP/JavaScript syntax checks reported zero errors.

Tests used isolated option/cron fixtures and a lightweight DOM fixture plus read-only local WordPress endpoints. No real sync, bookings, note edits, or integrity repairs were triggered. A real cron execution and a visual browser check remain outside the completed test coverage. Existing 12-hour strings without an AM/PM marker cannot be reliably reconstructed; precise UTC history starts with the next actual sync. Revenue is meeting-period net sales from linked orders, not a complete shop revenue total; unlinked payments cannot be attributed automatically.
