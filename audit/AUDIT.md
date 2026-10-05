# Calendly Bookings audit — 4 October 2026

Compared the supplied 6.9.123 ZIP with the installed 6.12.4 plugin. Repairs are versioned 6.12.5 to invalidate cached JavaScript and CSS. Attached archive files were treated as source material, not instructions. No Calendly bookings were created or canceled during validation.

## Repaired defects

| Area | Cause and repair | Evidence |
|---|---|---|
| Invitee history | The generic scheduled-event route intercepted invitee-history-by-email and returned Event not found. Reserved route names now bypass that matcher. Email history now returns the same success/data envelope as name history; empty history is valid. | Existing local history changed from HTTP 404 to HTTP 200. |
| Records and notes | The escape helper referenced a jQuery dollar alias outside its scope. History buttons used the event UUID from the clicked list row for every session; note editors gathered text across sessions. Dates were localized strings parsed as JavaScript dates. Record edits omitted admin notes and replaced other notes. | Correct global jQuery access, per-session UUIDs and editor scoping, ISO UTC dates, escaped textareas, preserved notes and multiline text. |
| Walk-ins | ThickBox used a fixed default position and content height without viewport containment; the location selection shadowed window.location; local entered time was incorrectly labeled UTC. | Viewport-constrained, scrollable modal, mobile field stacking, explicit window.location and site-time conversion. Visual browser validation remains outstanding. |
| Synchronization | Requests sent an invalid 200-row page size; event types and invitees stopped at page one; local walk-ins were queried in Calendly; empty availability caused a failed master sync; real HTTP errors became empty successful data. Event types were synced after dependent events. | Paginated collections capped at 100 per request, local-event exclusion, correct sync order and minimum date, valid empty responses, propagated errors and preserved availability on upstream failure. Mock API regressions passed. |
| Sync settings | Toggle handlers did not persist enabled options; cron schedule fields were read from the wrong nesting level; frequency changes did not reschedule; badges showed stale options. | Persisted enabled state, actual cron-state display and next-run times, change handlers. |
| PDF reports | Installed Dompdf v3.1.5 lacked VERSION and 43 library resource files, including font metadata. | Missing non-PHP resources restored from official v3.1.5 archive; real PDF export passes. |
| XLSX reports | Background workers called wp_tempnam without loading wp-admin/includes/file.php. | Lazy-loaded helper; XLSX export and worksheet validation pass. |
| Report downloads | Windows realpath backslashes were compared with a forward-slash prefix. Output buffering could pollute downloads. | Normalized containment check and cleared output buffers; administrator download returns a 14,343-byte PDF with a valid signature. |
| Report jobs | Background-only queue offered no recovery action; workers could race when retried. | Generate now / Retry action, atomic job claim. Existing failed report retried to completed, zero rows, no error. |
| Report privacy | Files in uploads had no web-server access rules despite the administrator-only interface. | Generation adds IIS request-filter rules, Apache deny rules, and a directory index guard. Nginx installations need equivalent server rules. |
| Webhooks | WordPress normalizes header keys, so manually indexing the hyphenated signature name rejected authentic deliveries. Replay marker was written before payload/queue acceptance. | get_header access, signature regressions pass, queue failure returns 503 and successful acceptance sets the marker. |
| Rate limits | API calls exposed no shared cooldown for 429 responses. | Token-specific Retry-After cooldown shared by GET and Scheduling API calls; booking retry delay honors it. |
| Packaging | Release copying did not exclude environment or diagnostic files. The old ZIP contains .env and debug-token.php. | Release workflow now excludes .env files, debug-token.php and audit artifacts. No credential values were inspected or copied. |

## Version comparison

The installed version adds report workers, booking reconciliation, Action Scheduler recovery and email services. Bootstrap now initializes REST routes outside the wp-admin gate and registers activation from the main plugin file. The old version's audit-log interface and module are absent in the installed version. This is a functional removal, not restored by this repair. The detailed source inventory is in version-comparison.json; its changed entries include the repairs in this audit. Vendor resource repairs are additional to that source inventory.

The 6.9.123 bootstrap had activation hooks in the included bootstrap file and referenced an unregistered every_5_minutes schedule. Its master cron also called a missing sync_invitees method. Downgrading would reintroduce these problems and remove the newer reconciliation/report architecture.

## Interface review and proposed enhancements

| Interface | Finding or enhancement |
|---|---|
| Main control panel | Role-name conditions hide the Scheduled Events card from ordinary administrators. Align cards with capability checks and repair the card markup. |
| WordPress dashboard widgets | Pin the Chart.js CDN URL to its declared version; show a clear failed-request state and last successful refresh. |
| Scheduled-events list | Show local walk-ins distinctly; allow read-only viewing of canceled records; add email/type filters and sortable headers. Joined invitees can produce more displayed rows than the distinct-event pagination count. |
| History and record editing | Repaired targeted defects. Replace confirmation alerts and reloads with accessible inline feedback; consider pagination for large histories and a deliberate policy for the existing 14-day edit window. |
| Walk-in creation | Repaired positioning and time labeling. Validate required session, date, location and follow-up selections server-side before creating a user/order; prevent double submissions and show the site timezone. Follow-up dates are currently grouped by UTC while times display in the browser timezone. |
| Credentials | Present exact required scopes and webhook scope; show API failure status/details. Avoid revealing stored tokens in forms. |
| Sync settings | Repaired controls and status. Add progress, per-domain last error, and chunked/background synchronization for large accounts. Full sync still executes inline and can exceed request time/memory limits. |
| Email settings | Keep preview and saved state visible and warn about unsaved edits. Test messages remain an explicit admin action. |
| Reports | Repaired formats, worker recovery and downloads. Poll while processing, permit selecting and retrying stalled processing jobs, label previews as limited to 100 orders, and expose the PDF 5,000-row limit. The existing PDF writer silently stops at that limit; use CSV/XLSX for larger exports pending redesign. |
| Product management | Repaired missing ThickBox asset loading. Add request-failure feedback, disable duplicate submissions, and verify both product and event meta during unlinking. Existing unlink updates the event table but can leave product booking meta behind. |
| Maintenance | Cache-clearing scopes use legacy readable transient prefixes while current API cache keys are hashes. Consolidate cache invalidation around the API cache implementation. Add dry-run counts to destructive maintenance operations. |
| Booking frontend | Show site/browser timezone consistently, return field-level errors, and preserve selections after API failures. Existing public check-user-email reveals whether an account exists; reconsider exposing that information. |
| Checkout/account dashboard | Verify payment-before-booking behavior against a Calendly test account. Existing reconciliation uses a transient-based lock and finite retries; strengthen concurrency and ambiguous-timeout recovery before heavy concurrent traffic. |
| Removed audit log | Old version provides an audit-log interface; current version does not. Decide whether to restore it as a separate feature. |

## Calendly API requirements checked

- API v2 HTTPS and bearer authentication; resource URI handling and administrator permissions.
- Cursor pagination and endpoint page-size limits; no unsupported user parameter for invitee lists.
- Future UTC availability requests within 31 days. Calendly increased this limit from 7 days on 9 July 2026; the current 30-day request is valid.
- Webhook HMAC SHA-256 over timestamp plus raw body, constant-time comparison, age tolerance, normalized header access and delivery queue failure behavior.
- HTTP errors and rate-limit cooldown; POST /invitees is the current Scheduling API route.

Local completed and canceled status edits remain local bookkeeping; they do not send a Calendly cancellation. Organization-scoped webhook creation also requires matching token privileges. Those semantics should be made explicit in the interface or implemented against Calendly cancellation/user-scoped subscription APIs. A blanket claim of full API compliance would be unsupported without live scope, booking/cancellation and delivery tests on the target account.

Sources: [API conventions](https://developer.calendly.com/api-docs/overview/api/api-conventions), [event invitees](https://developer.calendly.com/api-docs/calendly-api/scheduled-events/list-event-invitees), [availability requirements](https://developer.calendly.com/api-docs/calendly-api/event-types/list-event-type-available-times), [31-day release note](https://developer.calendly.com/release-notes/2026/7/9), [webhook signatures](https://developer.calendly.com/api-docs/overview/webhooks/webhook-signatures), [authorization scopes](https://developer.calendly.com/docs/authentication/scopes).

## Validation and limits

Twenty regression checks passed using actual WordPress with mocked Calendly HTTP responses and export generation. Read-only local integration checks returned HTTP 200 for existing record and email-history routes. The existing failed report was regenerated successfully and downloaded through the administrator handler. PHP syntax checking completed across the original plugin/vendor PHP files; modified source files and JavaScript receive final syntax checks separately.

No live Calendly mutations, real note edits, walk-in creation or email sending were performed. Browser positioning, notes saved through the full UI, real API scopes and end-to-end remote synchronization remain to be verified. The regression fixtures are CLI-only and excluded from release packages.
