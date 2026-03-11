(function ($) {
  'use strict';

  const uuid = CB_REST.uuid;

  function apiFetch(endpoint) {
    return fetch(CB_REST.root + endpoint, {
      headers: { 'X-WP-Nonce': CB_REST.nonce },
      credentials: 'same-origin'
    }).then(res => res.json());
  }

  const $dateSelect = $('#cb_meeting_date');
  const $timeSelect = $('#cb_meeting_time');
  const $emailField = $('#cb_email');

  function isValidEmail(email) {
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
  }

  $emailField.on('blur', function() {
    const email = $(this).val();
    if (!isValidEmail(email)) return;

    $.post(CB_REST.root + 'check-user-email', {
      email: email,
      _wpnonce: CB_REST.nonce
    }, function(response) {
      if (response.exists) {
        // Show modal login form
        $('#cb-login-modal').fadeIn();
        $('#cb-login-modal input[name="log"]').val(email); // prefill username/email
      }
    });
  });

  $('#cb-login-form').on('submit', function(e) {
    e.preventDefault();

    $.post(cb_ajax_object.ajaxurl, $(this).serialize() + '&action=cb_login&redirect_to=' + encodeURIComponent(window.location.href), function(response) {
      if (response.success) {
        // Close modal and reload page to continue booking
        $('#cb-login-modal').fadeOut();
        window.location.href = response.data.redirect;
      } else {
        alert(response.data.message);
      }
    });
  });

$(document).on('click', '.cb-close', function() {
  $('#cb-login-modal').fadeOut();
});
  
// Close on background click
$(document).on('click', function(e) {
  if ($(e.target).is('#cb-login-modal')) {
    $('#cb-login-modal').fadeOut();
  }
});

// Close on Esc key
$(document).on('keyup', function(e) {
  if (e.key === "Escape") {
    $('#cb-login-modal').fadeOut();
  }
});

  let availabilityByDate = {};

  function formatDateLabel(iso) {
    if (!iso) return '';
    const d = new Date(iso);
    return d.toLocaleDateString(undefined, {
      weekday: 'short',
      month: 'long',
      day: 'numeric',
      year: 'numeric'
    });
  }

  function formatTimeLabel(iso) {
    if (!iso) return '';
    const d = new Date(iso);
    return new Intl.DateTimeFormat(undefined, {
      hour: 'numeric',
      minute: '2-digit',
      hour12: true
    }).format(d);
  }

function renderDates(dates) {
  $dateSelect.empty();
  $timeSelect.prop('disabled', true); // disable time until a date is chosen

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

$dateSelect.on('change', function () {
  const key = $(this).val();
  if (!key) {
    renderTimes([]);
    $timeSelect.prop('disabled', true); // keep disabled if no date
    return;
  }
  renderTimes(availabilityByDate[key] || []);
  $timeSelect.prop('disabled', false); // enable once a date is chosen
});


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
    if (!uuid) { //No UUID provided for availability fetch.
      return;
    }
    const params = new URLSearchParams({ uuid, start_iso: new Date().toISOString() });
    apiFetch(`event-availability?${params.toString()}`)
      .then(data => {
        if (!(data && data.success && Array.isArray(data.data))) {
          renderDates([]);
          return;
        }
        availabilityByDate = {};
        for (const slot of data.data) {
          const iso = slot.start_time;
          if (!iso) continue;
          const key = new Date(iso).toISOString().slice(0, 10);
          if (!availabilityByDate[key]) availabilityByDate[key] = [];
          availabilityByDate[key].push(slot);
        }
        const dates = Object.keys(availabilityByDate).sort((a, b) => new Date(a) - new Date(b));
        renderDates(dates);
        if (dates.length) renderTimes(availabilityByDate[dates[0]]);
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
      return;
    }
    renderTimes(availabilityByDate[key] || []);
  });

  // Validation before submission
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
