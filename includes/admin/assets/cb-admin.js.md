# Copy of cb-admin.js

```js
(function ($) {
    'use strict';

    const apiFetch = (endpoint, options = {}) => {
        const defaults = {
            headers: { 'X-WP-Nonce': CB_REST.nonce, 'Content-Type': 'application/json' },
            credentials: 'same-origin'
        };
        return fetch(CB_REST.root + endpoint, { ...defaults, ...options })
            .then(r => {
                if (!r.ok) throw new Error(`HTTP ${r.status}`);
                return r.json();
            });
    };

    const showNotice = (msg, type = 'success') => {
        const $notice = $(`<div class="notice notice-${type} is-dismissible"><p>${msg}</p></div>`);
        $('#cb-admin-notices').append($notice);
        setTimeout(() => $notice.fadeOut(400, () => $notice.remove()), 5000);
    };

    /** -------------------------
     *  Event Types – Bulk Sync
     *  ------------------------- */
    $(document).on('click', '#cb-refresh-event-types', function (e) {
        e.preventDefault();
        const $btn = $(this).prop('disabled', true).text('Refreshing…');

        apiFetch('event-types/sync', { method: 'POST' })
            .then(data => {
                if (data.success) {
                    showNotice(`Synced ${data.count} event type(s) from Calendly`);
                    $(document).trigger('cb:event-types:updated');
                } else {
                    showNotice('Sync failed: ' + (data.message || 'Unknown error'), 'error');
                }
            })
            .catch(err => showNotice('Request failed: ' + err.message, 'error'))
            .finally(() => $btn.prop('disabled', false).text('Refresh Event Types'));
    });

    /** -------------------------
     *  Event Types – Single Sync
     *  ------------------------- */
    $(document).on('click', '.cb-sync-event-type', function (e) {
        e.preventDefault();
        const $btn = $(this).prop('disabled', true).text('Syncing…');
        const uuid = $btn.data('uuid');

        apiFetch(`event-types/${uuid}/sync`, { method: 'POST' })
            .then(data => {
                if (data.success) {
                    showNotice(`Event type "${data.data?.name || uuid}" updated`);
                    $(document).trigger('cb:event-types:row-updated', [uuid]);
                } else {
                    showNotice('Sync failed: ' + (data.message || 'Unknown error'), 'error');
                }
            })
            .catch(err => showNotice('Request failed: ' + err.message, 'error'))
            .finally(() => $btn.prop('disabled', false).text('Sync'));
    });

    /** -------------------------
     *  Scheduled Events – Sync
     *  ------------------------- */
$(document).on('click', '#cb-sync-now', function (e) {
    e.preventDefault();
    const $btn = $(this).prop('disabled', true).text('Syncing…');

    apiFetch('scheduled-events', { method: 'GET' }) // or POST if registered
        .then(data => {
            if (data && data.success) {
                const upserted = Number(data.events_upserted || 0);
                showNotice(`Synced ${upserted} upcoming event(s)`);
                const $list = $('#cb-scheduled-events-list').empty();

                const events = Array.isArray(data.scheduled_events) ? data.scheduled_events : [];
                if (events.length > 0) {
                    events.forEach(ev => {
                        const name = ev.name || ev.uuid || 'Unnamed';
                        const start = ev.start_time || ev.start_time_utc || 'N/A';
                        const status = ev.status || 'scheduled';
                        $list.append(`<li>${name} — ${start} (${status})</li>`);
                    });
                } else {
                    $list.append('<li>No upcoming events found.</li>');
                }
            } else {
                const msg = (data && data.message) ? data.message : 'Unknown error';
                showNotice('Sync failed: ' + msg, 'error');
            }
        })
        .catch(err => showNotice('Request failed: ' + (err?.message || 'Network error'), 'error'))
        .finally(() => $btn.prop('disabled', false).text('Sync Upcoming Events'));
});

    /** -------------------------
     *  WooCommerce – Link Product
     *  ------------------------- */
    $(document).on('click', '.cb-wc-link', function (e) {
        e.preventDefault();
        const $btn = $(this).prop('disabled', true).text('Linking…');
        const uuid = $btn.data('uuid');
        const productId = Number($(this).closest('tr').find('.cb-product-id').val()) || '';

        apiFetch(`wc/link?uuid=${encodeURIComponent(uuid)}&product_id=${productId}`, { method: 'POST' })
            .then(data => {
                if (data.success) {
                    showNotice(`Linked to product ID ${data.product_id}`);
                    $btn.closest('tr').find('.cb-product-status').text(`Linked (#${data.product_id})`);
                } else {
                    showNotice('Link failed: ' + (data.message || 'Unknown error'), 'error');
                }
            })
            .catch(err => showNotice('Request failed: ' + err.message, 'error'))
            .finally(() => $btn.prop('disabled', false).text('Link'));
    });

    /** -------------------------
     *  WooCommerce – Create Product
     *  ------------------------- */
    $(document).on('click', '.cb-wc-create', function (e) {
        e.preventDefault();
        const $btn = $(this).prop('disabled', true).text('Creating…');
        const uuid = $btn.data('uuid');

        apiFetch(`wc/create-product?uuid=${encodeURIComponent(uuid)}`, { method: 'POST' })
            .then(data => {
                if (data.success) {
                    showNotice(`Created product ID ${data.product_id}`);
                    $btn.closest('tr').find('.cb-product-status').text(`Created (#${data.product_id})`);
                } else {
                    showNotice('Create failed: ' + (data.message || 'Unknown error'), 'error');
                }
            })
            .catch(err => showNotice('Request failed: ' + err.message, 'error'))
            .finally(() => $btn.prop('disabled', false).text('Create'));
    });

    /** -------------------------
     *  WooCommerce – Delete Product
     *  ------------------------- */
    $(document).on('click', '.cb-wc-delete', function (e) {
        e.preventDefault();
        const $btn = $(this).prop('disabled', true).text('Deleting…');
        const uuid = $btn.data('uuid');

        apiFetch(`wc/delete-product?uuid=${encodeURIComponent(uuid)}`, { method: 'POST' })
            .then(data => {
                if (data.success) {
                    showNotice(`Product deleted. Link removed.`);
                    $btn.closest('tr').find('.cb-product-status').text('Unlinked');
                } else {
                    showNotice('Delete failed: ' + (data.message || 'Unknown error'), 'error');
                }
            })
            .catch(err => showNotice('Request failed: ' + err.message, 'error'))
            .finally(() => $btn.prop('disabled', false).text('Delete'));
    });

    /** -------------------------
     *  WooCommerce – Create All Products
     *  ------------------------- */
    $(document).on('click', '#cb-wc-create-all', function (e) {
        e.preventDefault();
        const $btn = $(this).prop('disabled', true).text('Creating…');

        apiFetch('wc/create-all', { method: 'POST' })
            .then(data => {
                if (data.success) {
                    showNotice(`Created ${data.created_count} products for events.`);
                    $('#cb-product-summary').text(`${data.created_count} products created`);
                } else {
                    showNotice('Create-all failed: ' + (data.message || 'Unknown error'), 'error');
                }
            })
            .catch(err => showNotice('Request failed: ' + err.message, 'error'))
            .finally(() => $btn.prop('disabled', false).text('Create All'));
    });
    /** -------------------------
     *  Maintenance – Rebuild Links
     *  ------------------------- */
    $(document).on('click', '#cb-rebuild-links', function (e) {
        e.preventDefault();
        const $btn = $(this).prop('disabled', true).text('Rebuilding…');

        apiFetch('maintenance/rebuild-links', { method: 'POST' })
            .then(data => {
                if (data.success) {
                    const stats = data.stats || {};
                    const processed = stats.processed || 0;
                    const linked = stats.linked || 0;
                    const relinked = stats.relinked || 0;
                    const skipped = stats.skipped || 0;
                    const errors = (stats.errors || []).length;

                    showNotice(`Rebuild complete. Processed ${processed}. Linked ${linked}, relinked ${relinked}, skipped ${skipped}, errors ${errors}.`);

                    $('#cb-links-summary').text(`Linked: ${linked}, Relinked: ${relinked}, Skipped: ${skipped}, Errors: ${errors}`);

                    const $details = $('#cb-links-details').empty();
                    if (Array.isArray(stats.details) && stats.details.length > 0) {
                        stats.details.forEach(d => {
                            const line = [
                                d.uuid ? `UUID: ${d.uuid}` : '',
                                d.action ? `Action: ${d.action}` : '',
                                d.product_id ? `Product: #${d.product_id}` : '',
                                d.reason ? `Reason: ${d.reason}` : ''
                            ].filter(Boolean).join(' | ');
                            $details.append(`<li>${line}</li>`);
                        });
                    } else {
                        $details.append('<li>No details available.</li>');
                    }
                } else {
                    showNotice('Rebuild failed: ' + (data.message || 'Unknown error'), 'error');
                }
            })
            .catch(err => showNotice('Request failed: ' + err.message, 'error'))
            .finally(() => $btn.prop('disabled', false).text('Rebuild Product Links'));
    });

    /** -------------------------
     *  Invitee Details Modal
     *  ------------------------- */
    $(document).on('click', '.cb-modal-link', function (e) {
        e.preventDefault();
        const id = $(this).data('id');

        apiFetch(`dashboard/invitee/${id}`, { method: 'GET' })
            .then(data => {
                const $modal = $('#cb-invitee-modal');
                $modal.find('.cb-modal-body').html(`
                    <strong>Name:</strong> ${data.name}<br>
                    <strong>Email:</strong> ${data.email}<br>
                    <strong>Phone:</strong> ${data.phone}<br>
                    <strong>Notes:</strong> ${data.notes || '—'}
                `);
                $modal.addClass('open');
            })
            .catch(err => showNotice('Failed to load invitee: ' + err.message, 'error'));
    });
    $(document).on('click', '.cb-modal-close', () => $('#cb-invitee-modal').removeClass('open'));

    /** -------------------------
     *  Integrity Widget Fix Now
     *  ------------------------- */
    $(document).on('click', '.cb-fix-uuid', function () {
        const id = $(this).data('id');
        apiFetch('dashboard/fix-missing-uuid', {
            method: 'POST',
            body: JSON.stringify({ id })
        })
        .then(resp => {
            showNotice(resp.message);
            $(document).trigger('cb:integrity:updated');
        })
        .catch(err => showNotice('Fix failed: ' + err.message, 'error'));
    });

    $(document).on('click', '.cb-fix-dup', function () {
        const uuid = $(this).data('uuid');
        apiFetch('dashboard/fix-duplicate', {
            method: 'POST',
            body: JSON.stringify({ uuid })
        })
        .then(resp => {
            showNotice(resp.message);
            $(document).trigger('cb:integrity:updated');
        })
        .catch(err => showNotice('Fix failed: ' + err.message, 'error'));
    });

    /** -------------------------
     *  Performance & Revenue Filters
     *  ------------------------- */
    $(document).on('click', '#cb-perf-1m, #cb-perf-3m, #cb-perf-6m, #cb-perf-all', function () {
        const months = $(this).attr('id') === 'cb-perf-all' ? 0 : Number($(this).attr('id').replace('cb-perf-', '').replace('m',''));
        $(document).trigger('cb:performance:filter', [months]);
    });

    $(document).on('click', '#cb-rev-1m, #cb-rev-3m, #cb-rev-6m, #cb-rev-all', function () {
        const months = $(this).attr('id') === 'cb-rev-all' ? 0 : Number($(this).attr('id').replace('cb-rev-', '').replace('m',''));
        $(document).trigger('cb:revenue:filter', [months]);
    });

    /** -------------------------
     *  Sync Health Widget
     *  ------------------------- */
    $(document).on('click', '#cb-sync-btn', function (e) {
        e.preventDefault();
        const $btn = $(this).prop('disabled', true).text('Syncing…');

        apiFetch('dashboard/sync', { method: 'POST' })
            .then(resp => {
                showNotice(resp.message);
                $(document).trigger('cb:health:updated');
            })
            .catch(err => showNotice('Sync failed: ' + err.message, 'error'))
            .finally(() => $btn.prop('disabled', false).text('Sync Now'));
    });

})(jQuery);

```
