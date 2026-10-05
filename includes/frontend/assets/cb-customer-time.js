(function () {
  'use strict';
  const timezone = Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC';
  window.CB_CUSTOMER_TIMEZONE = timezone;
  document.cookie = 'cb_timezone=' + encodeURIComponent(timezone) + '; Path=/; SameSite=Lax' + (location.protocol === 'https:' ? '; Secure' : '');
  function update() {
    document.querySelectorAll('input[name="cb_timezone"]').forEach(input => { input.value = timezone; });
    document.querySelectorAll('[data-cb-time]').forEach(element => {
      const date = new Date(element.dataset.cbTime);
      if (Number.isNaN(date.getTime())) return;
      const mode = element.dataset.cbTimeMode || 'datetime';
      const options = {timeZone: timezone};
      if (mode !== 'time') Object.assign(options, {year: 'numeric', month: 'long', day: 'numeric'});
      if (mode !== 'date') Object.assign(options, {hour: 'numeric', minute: '2-digit', timeZoneName: 'short'});
      element.textContent = new Intl.DateTimeFormat(undefined, options).format(date);
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', update); else update();
  if (window.jQuery) window.jQuery(document.body).on('updated_checkout wc_fragments_refreshed updated_wc_div', update);
})();
