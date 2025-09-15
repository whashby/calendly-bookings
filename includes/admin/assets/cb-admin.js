/**
 * Calendly Bookings – Admin UI
 * Refactored to use unified CB_API_Proxy endpoints
 *
 * Requires localized vars:
 *   CB_REST       – REST namespace root, e.g. '/wp-json/calendly-bookings/v1/'
 *   CB_REST_NONCE – WP REST API nonce
 */

(function ($) {
    'use strict';

    const apiFetch = (endpoint, options = {}) => {
        const defaults = {
            headers: { 'X-WP-Nonce': CB_REST_NONCE, 'Content-Type': 'application/json' },
            credentials: 'same-origin'
        };
        return fetch(CB_REST + endpoint, { ...defaults, ...options })
            .then(r => r.json());
    };

    const notify = (msg, isError = false) => {
        // Replace with your preferred admin notice system
        alert(msg);
        if (isError) console.error(msg);
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
                    notify(`Synced ${data.count} event type(s) from Calendly`);
                    $(document).trigger('cb:event-types:updated');
                } else {
                    notify('Sync failed: ' + (data.message || 'Unknown error'), true);
                }
            })
            .catch(err => notify('Request failed: ' + err.message, true))
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
                    notify(`Event type "${data.data?.name || uuid}" updated`);
                    $(document).trigger('cb:event-types:row-updated', [uuid]);
                } else {
                    notify('Sync failed: ' + (data.message || 'Unknown error'), true);
                }
            })
            .catch(err => notify('Request failed: ' + err.message, true))
            .finally(() => $btn.prop('disabled', false).text('Sync'));
    });

    /** -------------------------
     *  Scheduled Events – Sync
     *  ------------------------- */
    $(document).on('click', '#cb-sync-now', function (e) {
        e.preventDefault();
        const $btn = $(this).prop('disabled', true).text('Syncing…');

        apiFetch('sync', { method: 'POST' })
            .then(data => {
                if (data.success) {
                    notify(`Synced ${data.events_upserted} upcoming event(s)`);
                    $(document).trigger('cb:scheduled-events:updated');
                } else {
                    notify('Sync failed: ' + (data.message || 'Unknown error'), true);
                }
            })
            .catch(err => notify('Request failed: ' + err.message, true))
            .finally(() => $btn.prop('disabled', false).text('Sync Upcoming Events'));
    });

    /** -------------------------
     *  Availability Fetch
     *  ------------------------- */
    window.cbFetchAvailability = function (uuid, startIso) {
        const params = new URLSearchParams({
            uuid,
            start_iso: startIso || new Date().toISOString()
        });

        return apiFetch(`event-availability?${params.toString()}`, { method: 'GET' })
            .then(data => {
                if (data.success) {
                    return data.data; // array of slots
                }
                throw new Error(data.message || 'Failed to load availability');
            });
    };

    /** -------------------------
     *  WooCommerce – Link/Sync
     *  ------------------------- */
    $(document).on('click', '.cb-wc-link', function (e) {
        e.preventDefault();
        const $btn = $(this).prop('disabled', true).text('Linking…');
        const uuid = $btn.data('uuid');
        const productId = Number($(this).closest('tr').find('.cb-product-id').val()) || '';


        apiFetch(`wc/link?uuid=${encodeURIComponent(uuid)}&product_id=${productId}`, { method: 'POST' })
            .then(data => {
                if (data.success) {
                    notify(`Linked to product ID ${data.product_id}`);
                    $(document).trigger('cb:wc:linked', [uuid, data.product_id]);
                } else {
                    notify('Link failed: ' + (data.message || 'Unknown error'), true);
                }
            })
            .catch(err => notify('Request failed: ' + err.message, true))
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
                    notify(`Created product ID ${data.product_id}`);
                    $(document).trigger('cb:wc:created', [uuid, data.product_id]);
                    location.reload();
                } else {
                    notify('Link failed: ' + (data.message || 'Unknown error'), true);
                }
            })
            .catch(err => notify('Request failed: ' + err.message, true))
            .finally(() => $btn.prop('disabled', false).text('Link'));
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
                    notify(`Product deleted. Link removed.`);
                    $(document).trigger('cb:wc:deleted', [uuid, data.product_id]);
                    location.reload();
                } else {
                    notify('Delete failed: ' + (data.message || 'Unknown error'), true);
                }
            })
            .catch(err => notify('Request failed: ' + err.message, true))
            .finally(() => $btn.prop('disabled', false).text('Link'));
    });

    
    /** -------------------------
     *  WooCommerce – Crerate All Products
     *  ------------------------- */
    $(document).on('click', '#cb-wc-create-all', function (e) {
        e.preventDefault();
        const $btn = $(this).prop('disabled', true).text('Creating…');
        const uuid = $btn.data('uuid');


        apiFetch('wc/create-all', { method: 'POST' })
            .then(data => {
                if (data.success) {
                    notify(`Products created.`);
                    $(document).trigger('cb:wc:created', [uuid, data.product_id]);
                    location.reload();
                } else {
                    notify('Delete failed: ' + (data.message || 'Unknown error'), true);
                }
            })
            .catch(err => notify('Request failed: ' + err.message, true))
            .finally(() => $btn.prop('disabled', false).text('Link'));
    });

    
    /** -------------------------
     *  WooCommerce – Delete All Products
     *  ------------------------- */
    $(document).on('click', '#cb-wc-delete-all', function (e) {
        e.preventDefault();
        const $btn = $(this).prop('disabled', true).text('Deleting…');
        const uuid = $btn.data('uuid');


        apiFetch('wc/delete-all', { method: 'POST' })
            .then(data => {
                if (data.success) {
                    notify(`Products deleted. Links removed.`);
                    $(document).trigger('cb:wc:deleted', [uuid, data.product_id]);
                    location.reload();
                } else {
                    notify('Delete failed: ' + (data.message || 'Unknown error'), true);
                }
            })
            .catch(err => notify('Request failed: ' + err.message, true))
            .finally(() => $btn.prop('disabled', false).text('Link'));
    });

    
	// Clear cache
    $(document).on('click', '#cb-clear-cache', function (e) {
        e.preventDefault();
        const $btn = $(this).prop('disabled', true).text('Clearing…');


        apiFetch('maintenance/clear-cache', { method: 'POST' })
            .then(data => {
                if (data.success) {
                    notify(`Cash cleared.`);
                    //$(document).trigger('cb:cleared', [data.deleted, data.errors]);
                    location.reload();
                } else {
                    notify('Failed to clear cache: ' + (data.message || 'Unknown error'), true);
                }
            })
            .catch(err => notify('Request failed: ' + err.message, true))
            .finally(() => $btn.prop('disabled', false).text('Clear API Cache'));
    });




	// Rebuild links
    $(document).on('click', '#cb-rebuild-links', function (e) {
        e.preventDefault();
        const $btn = $(this).prop('disabled', true).text('Clearing…');


        apiFetch('maintenance/rebuild-links', { method: 'POST' })
            .then(data => {
                if (data.success) {
                    notify(`Rebuild complete.`);
                    //$(document).trigger('cb:cleared', [data.deleted, data.errors]);
                    location.reload();
                } else {
                    notify('Failed to clear cache: ' + (data.message || 'Unknown error'), true);
                }
            })
            .catch(err => notify('Request failed: ' + err.message, true))
            .finally(() => $btn.prop('disabled', false).text('Rebuild Product Links'));
    });



})(jQuery);
