(function ($) {
  'use strict';

  const uuid = CB_REST.uuid;
  let availabilityByDate = {};

  const $timeContainer = $('#cb_meeting_time'); // tile container
  const $timeHidden = $('#cb_meeting_time_value'); // hidden input

  // --- Initialize Date Picker ---
  const datePicker = flatpickr("#cb_meeting_date", {
    dateFormat: "Y-m-d",
    minDate: "today",
    enable: [],
    onChange: function(selectedDates, dateStr) {
      populateTimes(dateStr);
    }
  });

  // --- Populate times as tiles ---
  function populateTimes(dateStr) {
    $timeContainer.empty();
    $timeHidden.val('');

    if (availabilityByDate[dateStr]) {
      availabilityByDate[dateStr].forEach(slot => {
        const $tile = $('<div>', {
          class: 'cb-time-tile',
          text: slot.time
        }).data('slot', slot);

        $tile.on('click', function() {
          // Clear previous selection
          $timeContainer.find('.cb-time-tile').removeClass('selected');
          // Mark this one
          $(this).addClass('selected');
          // Store value in hidden input
          $timeHidden.val(slot.time);
          $timeHidden.attr('data-url', slot.scheduling_url);
        });

        $timeContainer.append($tile);
      });
    }
  }

  // --- Load availability into pickers ---
  function loadAvailability(apiData) {
    availabilityByDate = {};

    apiData.forEach(item => {
      if (item.status !== 'available') return;

      const dt = new Date(item.start_time);
      const date = dt.toISOString().split('T')[0];
      const time = dt.toISOString().split('T')[1].substring(0,5); // HH:mm

      if (!availabilityByDate[date]) {
        availabilityByDate[date] = [];
      }
      availabilityByDate[date].push({
        time,
        scheduling_url: item.scheduling_url
      });
    });

    // Enable only available dates in datepicker
    datePicker.set('enable', Object.keys(availabilityByDate));
  }

  // --- Fetch availability via admin-ajax ---
  function fetchAvailability(startIso) {
    $.ajax({
      url: cb_ajax_object.ajaxurl,
      method: 'POST',
      dataType: 'json',
      data: {
        action: 'cb_get_event_availability',
        uuid: CB_REST.uuid,
        start_iso: startIso,
        _ajax_nonce: CB_REST.nonce
      },
      success: function(response) {
        if (response && response.success && Array.isArray(response.data)) {
          loadAvailability(response.data);
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
  const startIso = new Date().toISOString();
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