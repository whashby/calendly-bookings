(function ($) {
  'use strict';

  const uuid = CB_REST.uuid;
  const siteTimezone = CB_REST.site_timezone || 'America/Barbados';
  const $emailField = $('#cb_email');

  let availabilityByDate = {};

  // --- Email validation helper ---
  function isValidEmail(email) {
    return /^[^\s@]+@[^\s]+\.[^\s]+$/.test(email);
  }

  // --- Initialize Date Picker ---
  const datePicker = flatpickr("#cb_meeting_date", {
    dateFormat: "Y-m-d",
    minDate: "today",
    enable: [], // will be filled dynamically
    onChange: function(selectedDates, dateStr) {
      if (availabilityByDate[dateStr]) {
        const times = availabilityByDate[dateStr].map(slot => slot.time);
        timePicker.set('enable', times);
      } else {
        timePicker.set('enable', []);
      }
    }
  });

  // --- Initialize Time Picker ---
  const timePicker = flatpickr("#cb_meeting_time", {
    enableTime: true,
    noCalendar: true,
    dateFormat: "H:i",
    time_24hr: true,
    enable: [] // filled when a date is chosen
  });

  // --- Load availability into pickers ---
  function loadAvailability(slots) {
    // slots = [{date:"2026-04-12", time:"09:00"}, {date:"2026-04-12", time:"10:00"}, ...]
    availabilityByDate = {};

    slots.forEach(slot => {
      if (!availabilityByDate[slot.date]) {
        availabilityByDate[slot.date] = [];
      }
      availabilityByDate[slot.date].push(slot);
    });

    // Enable only available dates in datepicker
    datePicker.set('enable', Object.keys(availabilityByDate));
  }

  // --- Fetch availability from REST API ---
function fetchAvailability(startIso) {
  $.ajax({
    url: cb_ajax_object.ajaxurl, // always points to /wp-admin/admin-ajax.php
    method: 'POST',
    dataType: 'json',
    data: {
      action: 'event-availability', // must match PHP handler
      uuid: CB_REST.uuid,
      start_iso: startIso,
      _ajax_nonce: CB_REST.nonce // optional if you want nonce check
    },
    success: function(response) {
      if (response && response.success && Array.isArray(response.data)) {
        const slots = response.data.map(item => {
          const dt = new Date(item.start_time);
          return {
            date: dt.toISOString().split('T')[0],
            time: dt.toISOString().split('T')[1].substring(0,5)
          };
        });
        loadAvailability(slots);
      } else {
        loadAvailability([]);
      }
    },
    error: function(xhr) {
      console.error("Failed to fetch availability", xhr);
      loadAvailability([]);
    }
  });
}


  // --- Initialize ---
  const startIso = new Date().toISOString(); // today as starting point
  fetchAvailability(startIso);

})(jQuery);


/*(function ($) {
  'use strict';

  const uuid = CB_REST.uuid;
  const siteTimezone = CB_REST.site_timezone || 'America/Barbados';
  const $dateSelect = $('#cb_meeting_date');
  const $timeSelect = $('#cb_meeting_time');
  const $locationSelect = $('#cb_meeting_location');
  const $emailField = $('#cb_email');

  let availabilityByDate = {};

  function isValidEmail(email) {
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
  }

  // Email check
  $emailField.on('blur', function () {
    const email = $(this).val();
    if (!isValidEmail(email)) return;

    $.post(CB_REST.root + 'check-user-email', {
      email: email,
      _wpnonce: CB_REST.nonce
    }, function (response) {
      if (response.exists) {
        $('#cb-login-modal').fadeIn();
        $('#cb-login-modal input[name="log"]').val(email);
      }
    });
  });

  // Login form
  $('#cb-login-form').on('submit', function (e) {
    e.preventDefault();
    $.post(cb_ajax_object.ajaxurl, $(this).serialize() + '&action=cb_login&redirect_to=' + encodeURIComponent(window.location.href), function (response) {
      if (response.success) {
        $('#cb-login-modal').fadeOut();
        window.location.href = response.data.redirect;
      } else {
        alert(response.data.message);
      }
    });
  });

  // Modal close handlers
  $(document).on('click', '.cb-close', () => $('#cb-login-modal').fadeOut());
  $(document).on('click', e => { if ($(e.target).is('#cb-login-modal')) $('#cb-login-modal').fadeOut(); });
  $(document).on('keyup', e => { if (e.key === "Escape") $('#cb-login-modal').fadeOut(); });

  // Helpers (timezone-aware)
function formatDateLabel(iso) {
  // Remove time portion if present
  const dateOnly = iso.split('T')[0];

  // Create a Date object using only the date part
  const d = new Date(dateOnly + 'T00:00:00');

  // Format naturally in the site’s timezone
  return new Intl.DateTimeFormat('en-US', {
    weekday: 'short',
    month: 'long',
    day: 'numeric',
    year: 'numeric',
    timeZone: CB_REST.site_timezone // e.g., 'America/Barbados'
  }).format(d);
}
  function formatTimeLabel(iso) {
    const d = new Date(iso);
    return new Intl.DateTimeFormat('en-US', {
      hour: 'numeric',
      minute: '2-digit',
      hour12: true,
      timeZone: siteTimezone
    }).format(d);
  }

  function renderDates(dates) {
    $dateSelect.empty();
    $timeSelect.prop('disabled', true);

    if (!dates.length) {
      $dateSelect.append('<option value="">No available dates</option>');
      renderTimes([]);
      return;
    }

    $dateSelect.append('<option value="">Select a date</option>');
    dates.forEach(dateIso => {
      $dateSelect.append($('<option>', { value: dateIso, text: formatDateLabel(dateIso) }));
    });
  }

  function renderTimes(slots) {
    $timeSelect.empty();
    if (!Array.isArray(slots) || slots.length === 0) {
      $timeSelect.append('<option value="">No available times</option>');
      return;
    }
    $timeSelect.append('<option value="">Select a time</option>');
    const sorted = [...slots].sort((a, b) => new Date(a.start_time) - new Date(b.start_time));
    sorted.forEach(slot => {
      $timeSelect.append($('<option>', { value: slot.start_time, text: formatTimeLabel(slot.start_time) }));
    });
  }

  function loadAvailability() {
    if (!uuid) return;
    const startIso = new Date().toISOString();

    fetch(`/wp-json/calendly-bookings/v1/event-availability?uuid=${uuid}&start_iso=${startIso}`, { credentials: 'same-origin' })
      .then(res => res.json())
      .then(response => {
        if (!(response && response.success && Array.isArray(response.data))) {
          renderDates([]);
          return;
        }
        availabilityByDate = {};
        for (const slot of response.data) {
          const iso = slot.start_time;
          if (!iso) continue;
          const key = new Date(iso).toISOString().slice(0, 10);
          if (!availabilityByDate[key]) availabilityByDate[key] = [];
          availabilityByDate[key].push(slot);
        }
        const dates = Object.keys(availabilityByDate).sort((a, b) => new Date(a) - new Date(b));
        renderDates(dates);
        if (dates.length) renderTimes(availabilityByDate[dates[0]]);

        applyPrefill(); // apply once after rendering
      })
      .catch(err => {
        console.error('Availability request failed:', err);
        renderDates([]);
      });
  }

  $dateSelect.on('change', function () {
    const key = $(this).val();
    if (!key) {
      renderTimes([]);
      $timeSelect.prop('disabled', true);
      return;
    }
    renderTimes(availabilityByDate[key] || []);
    $timeSelect.prop('disabled', false);
  });

  // Prefill logic
  function applyPrefill() {
    if (typeof CB_FOLLOWUP === 'undefined') return;
    const { firstname, lastname, email, date, time, location } = CB_FOLLOWUP;

    if (firstname) $('#cb_firstname').val(firstname);
    if (lastname) $('#cb_lastname').val(lastname);
    if (email) $('#cb_email').val(email);

    if (date && availabilityByDate[date]) {
      $dateSelect.val(date);
      $dateSelect.find(`option[value="${date}"]`).attr('selected', 'selected');
      renderTimes(availabilityByDate[date]);
      $timeSelect.prop('disabled', false);
    }
    if (time) {
      $timeSelect.val(time);
      $timeSelect.find(`option[value="${time}"]`).attr('selected', 'selected');
    }
    if (location) {
      $locationSelect.val(location);
      $locationSelect.find(`option[value="${location}"]`).attr('selected', 'selected');
    }
  }

  // Validation
  $('#calendly-booking-form').on('submit', function (e) {
    e.preventDefault();
    const name = $('#cb_firstname').val() + ' ' + $('#cb_lastname').val();
    const email = $('#cb_email').val();

    $.post(CB_REST.root + 'validate-invitee', {
      name: name,
      email: email,
      _wpnonce: CB_REST.nonce
    }, function (response) {
      if (response.account_exists && !response.logged_in) {
        alert(CB_MESSAGES.account_exists);
        return;
      }
      if (response.has_meeting_order) {
        alert(CB_MESSAGES.meeting_blocked + ' ' + CB_MESSAGES.upsell);
        return;
      }
      e.currentTarget.submit();
    });
  });

  $(function () {
    loadAvailability();
  });

})(jQuery);
*/