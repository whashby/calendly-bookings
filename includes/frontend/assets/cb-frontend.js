(function ($) {
  'use strict';
  const rest = window.CB_REST || {};
  const root = rest.root || '';
  const $form = $('#cb-calendly-form');
  const uuid = rest.uuid || $form.data('event-uuid') || '';
  // Calendly availability requires the full Event Type URI. The UUID remains the
  // canonical product mapping and is converted to the URI exactly as required by
  // GET /event_type_available_times.
  const eventTypeUri = $form.data('event-type-uri') || rest.event_type_uri || (uuid ? 'https://api.calendly.com/event_types/' + uuid : '');
  const nonce = rest.nonce || '';
  const siteTimezone = Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC';
  $form.find('input[name="cb_timezone"]').val(siteTimezone);
  let availabilityByDate = {};
  let datePicker = null;

  function displayTime(iso) {
    return new Intl.DateTimeFormat(undefined, {hour:'numeric', minute:'2-digit', hour12:true, timeZone:siteTimezone, timeZoneName:'short'}).format(new Date(iso));
  }
  function localDateKey(iso) {
    const parts = new Intl.DateTimeFormat('en-CA', {year:'numeric', month:'2-digit', day:'2-digit', timeZone:siteTimezone}).formatToParts(new Date(iso));
    const o = {}; parts.forEach(p => o[p.type] = p.value);
    return `${o.year}-${o.month}-${o.day}`;
  }
  function setAddToCartState(enabled) {
    const $button = $('form.cart .single_add_to_cart_button');
    if (!$button.length) return;
    $button.prop('disabled', !enabled);
    $button.attr('aria-disabled', enabled ? 'false' : 'true');
    $button.toggleClass('cb-booking-disabled', !enabled);
  }
  function showAvailabilityMessage(message) {
    const $container = $('#cb_meeting_time');
    if ($container.length) $container.html('<p class="cb-no-times">' + $('<div>').text(message || 'No available times were found.').html() + '</p>');
  }
  function populateTimes(dateKey) {
    const $container = $('#cb_meeting_time');
    $container.empty();
    $('#cb_meeting_time_value, #cb_meeting_start_iso').val('');
    const slots = availabilityByDate[dateKey] || [];
    if (!slots.length) { $container.append('<p class="cb-no-times">No available times.</p>'); setAddToCartState(false); return; }
    slots.sort((a,b) => new Date(a.start_time) - new Date(b.start_time));
    slots.forEach(slot => {
      const $tile = $('<button type="button" class="cb-time-tile"></button>');
      $tile.text(displayTime(slot.start_time));
      $tile.on('click', function() {
        $container.find('.cb-time-tile').removeClass('selected');
        $(this).addClass('selected');
        $('#cb_meeting_time_value').val(slot.start_time);
        $('#cb_meeting_start_iso').val(slot.start_time);
        setAddToCartState(true);
      });
      $container.append($tile);
    });
  }
  function loadAvailability(items) {
    availabilityByDate = {};
    (items || []).forEach(item => {
      if (!item || (item.status && item.status !== 'available')) return;
      const iso = item.start_time;
      if (!iso) return;
      const key = localDateKey(iso);
      if (!availabilityByDate[key]) availabilityByDate[key] = [];
      availabilityByDate[key].push(item);
    });
    if (datePicker) datePicker.set('enable', Object.keys(availabilityByDate));
    setAddToCartState(false);
    if (Object.keys(availabilityByDate).length) showAvailabilityMessage('Select an available date to see meeting times.');
    else showAvailabilityMessage('No available dates were returned by Calendly.');
  }
  function fetchAvailability(startIso) {
    setAddToCartState(false);
    if (!root || (!uuid && !eventTypeUri)) {
      showAvailabilityMessage('This meeting is not currently connected to a Calendly Event Type.');
      return;
    }
    const request = $.ajax({
      url: root + 'event-availability', method:'GET', dataType:'json',
      data:{event_type_uri:eventTypeUri, uuid:uuid, start_iso:startIso}, headers:{'X-WP-Nonce':nonce}
    });
    request.done(function(response){
      if (response && response.success) loadAvailability(response.data || []);
      else {
        loadAvailability([]);
        showAvailabilityMessage((response && response.message) || 'Calendly availability could not be loaded.');
      }
    });
    request.fail(function(xhr){
      // Compatibility fallback for sites that block the WordPress REST API on the frontend.
      $.ajax({
        url: (window.cb_ajax_object && cb_ajax_object.ajaxurl) || '',
        method:'POST', dataType:'json',
        data:{action:'cb_get_event_availability', uuid:uuid, start_iso:startIso, _ajax_nonce:nonce}
      }).done(function(response){
        if (response && response.success) loadAvailability(response.data || []);
        else {
          loadAvailability([]);
          showAvailabilityMessage((response && response.data && response.data.message) || 'Calendly availability could not be loaded.');
        }
      }).fail(function(fallbackXhr){
        console.error('Calendly availability request failed', xhr, fallbackXhr);
        let message = 'Calendly availability could not be loaded.';
        if (fallbackXhr.responseJSON && fallbackXhr.responseJSON.data && fallbackXhr.responseJSON.data.message) message = fallbackXhr.responseJSON.data.message;
        else if (xhr.responseJSON && xhr.responseJSON.message) message = xhr.responseJSON.message;
        showAvailabilityMessage(message);
        if (datePicker) datePicker.set('enable', []);
      });
    });
  }

  $(function() {
    const $date = $('#cb_meeting_date');
    if (!$date.length) return;
    setAddToCartState(false);
    datePicker = flatpickr($date[0], {
      dateFormat:'Y-m-d', minDate:'today', enable:[], disableMobile:true,
      onChange:function(_, dateStr){ populateTimes(dateStr); }
    });
    fetchAvailability(new Date().toISOString());

    function updateLocationDetails(){
      const $location = $('#cb_meeting_location');
      const $selected = $location.is('select') ? $location.find(':selected') : $location;
      const idx = $selected.data('location-index');
      const kind = $selected.data('location-kind');
      $('#cb_meeting_location_details').val(idx == null ? '' : String(idx));
      const required = kind === 'ask_invitee' || kind === 'outbound_call';
      const $detail = $('#cb_meeting_location_detail_text');
      $detail.prop('required', required).prop('disabled', !required).toggle(required);
      $('label[for="cb_meeting_location_detail_text"]').toggle(required);
    }
    $(document).on('change', '#cb_meeting_location', updateLocationDetails);
    updateLocationDetails();

    $(document).on('submit', 'form.cart', function(e){
      if (!$form.length) return;
      const startIso = $('#cb_meeting_start_iso').val();
      if (!startIso) {
        e.preventDefault();
        alert('Please select an available meeting time before continuing.');
        return false;
      }
      const $button = $(this).find('.single_add_to_cart_button');
      // Do not disable the submit button synchronously here. WooCommerce uses the
      // button's name/value during form submission; disabling it before the browser
      // serializes the form can remove add-to-cart from the request and cause a
      // silent page refresh instead of adding the meeting. A hidden add-to-cart
      // fallback is present in the form, and we only mark the button busy after the
      // native submit has been initiated.
      $button.attr('aria-disabled', 'true').addClass('loading');
      window.setTimeout(function(){ $button.prop('disabled', true); }, 50);
    });
  });
})(jQuery);
