// includes/admin/assets/settings.js
jQuery(function($) {
  const $form      = $('#cb-settings-form');
  const $status    = $('#cb-settings-status');
  const $manualBtn = $('#cb-manual-test');

  /**
   * Utility: update status text with success/error indicator
   */
  function setStatus(success, message, fallback) {
    const prefix = success ? '✅' : '❌';
    $status.text(`${prefix} ${message || fallback}`);
  }

  /**
   * Utility: perform a fetch request with JSON handling
   */
  function doRequest(url, options, successMsg, errorMsg) {
    fetch(url, options)
      .then(r => r.json())
      .then(res => setStatus(res.success, res.message, successMsg))
      .catch(() => setStatus(false, null, errorMsg));
  }

  if ($form.length) {
    $form.on('submit', function(e) {
      e.preventDefault();
      $status.text('Saving settings and testing event types…');

      const payload = {
        token: $('#cb_api_token').val().trim(),
        uuid:  $('#cb_user_uuid').val().trim()
      };
      doRequest(
        `${CB_Rest.root}save-settings`,
        {
          method: 'POST',
          headers: {
            'X-WP-Nonce': CB_Rest.nonce,
            'Content-Type': 'application/json'
          },
          body: JSON.stringify(payload)
        },
        'Save failed.',
        'Error saving settings.'
      );
    });
  }

  if ($manualBtn.length) {
    $manualBtn.on('click', function(e) {
      e.preventDefault();
      $status.text('Testing API connectivity…');

      doRequest(
        `${CB_Rest.root}manual-test`,
        {
          method: 'GET',
          headers: { 'X-WP-Nonce': CB_Rest.nonce }
        },
        'Test failed.',
        'Error testing connection.'
      );
    });
  }
});