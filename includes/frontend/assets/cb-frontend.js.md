# Copy of cb-frontend.js

```js
(function ($) {
    'use strict';

    const uuid = CB_REST.uuid;
    // If your PHP localized CB_REST as an object (root/nonce), switch to: CB_REST.root
    const apiFetch = (endpoint, options = {}) => {
        const defaults = {
            headers: { 'X-WP-Nonce': CB_REST.nonce, 'Content-Type': 'application/json' },
            credentials: 'same-origin'
        };
        return fetch(CB_REST.root + endpoint, { ...defaults, ...options }).then(r => r.json());
    };

    const $dateSelect = $('#cb_meeting_date');
    const $timeSelect = $('#cb_meeting_time');

    // In-memory map: { 'YYYY-MM-DD': [{ start_time, scheduling_url, status, ... }] }
    let availabilityByDate = {};

    const toDateKey = (iso) => {
        // Use local date or UTC date depending on your UX; here we use UTC to match Calendly ISO
        const d = new Date(iso);
        // YYYY-MM-DD
        return d.toISOString().slice(0, 10);
    };

    const formatDateLabel = (isoDate) => {
        // Pretty label from 'YYYY-MM-DD'
        const d = new Date(isoDate + 'T00:00:00Z');
        return d.toLocaleDateString(undefined, { month: 'long', day: 'numeric', year: 'numeric' });
    };

    const formatTimeLabel = (iso) => {
        const d = new Date(iso);
        return d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
    };

    const renderDates = (dates) => {
        $dateSelect.empty();
        if (!dates.length) {
            $dateSelect.append('<option value="">' + 'No available dates' + '</option>');
            renderTimes([]);
            return;
        }
        $dateSelect.append('<option value="">' + 'Select a date' + '</option>');
        dates.forEach(dateKey => {
            $dateSelect.append(
                $('<option>', { value: dateKey, text: formatDateLabel(dateKey) })
            );
        });
    };

    const renderTimes = (slots) => {
        $timeSelect.empty();
        if (!Array.isArray(slots) || slots.length === 0) {
            $timeSelect.append('<option value="">' + 'No available times' + '</option>');
            return;
        }
        $timeSelect.append('<option value="">' + 'Select a time' + '</option>');
        // Sort by start_time
        const sorted = [...slots].sort((a, b) => new Date(a.start_time) - new Date(b.start_time));
        sorted.forEach(slot => {
            $timeSelect.append(
                $('<option>', {
                    value: slot.start_time, // you can encode more if needed
                    text: formatTimeLabel(slot.start_time)
                })
            );
        });
    };

    const loadAvailability = () => {
        if (!uuid) {
            console.warn('No UUID provided for availability fetch.');
            renderDates([]);
            return;
        }
        const params = new URLSearchParams({
            uuid,
            start_iso: new Date().toISOString()
        });

        apiFetch(`event-availability?${params.toString()}`, { method: 'GET' })
            .then(data => {
                if (!(data && data.success && Array.isArray(data.data))) {
                    renderDates([]);
                    return;
                }

                // data.data is an array of raw slots: { start_time, scheduling_url, status, ... }
                availabilityByDate = {};
                for (const slot of data.data) {
                    const iso = slot.start_time;
                    if (!iso) continue;
                    const key = toDateKey(iso);
                    if (!availabilityByDate[key]) availabilityByDate[key] = [];
                    availabilityByDate[key].push(slot);
                }

                // Get sorted date keys
                const dates = Object.keys(availabilityByDate).sort((a, b) => a.localeCompare(b));
                renderDates(dates);

                // Auto-populate first date's times
                if (dates.length) renderTimes(availabilityByDate[dates[0]]);
            })
            .catch(err => {
                console.error('Availability request failed:', err);
                renderDates([]);
            });
    };

    // When user changes date, update times
    $dateSelect.on('change', function () {
        const key = $(this).val();
        if (!key) {
            renderTimes([]);
            return;
        }
        renderTimes(availabilityByDate[key] || []);
    });

    // Run on DOM ready
    $(function () {
        loadAvailability();
    });

})(jQuery);
```
