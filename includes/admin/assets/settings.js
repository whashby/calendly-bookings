// includes/admin/assets/settings.js
jQuery(function($) {
  const $form      = $('#cb-settings-form');
  const $status    = $('#cb-settings-status');
  const $manualBtn = $('#cb-manual-test');

  if ($form.length) {
    $form.on('submit', function(e) {
      e.preventDefault();
      $status.text('Saving settings and testing event types…');
      const payload = {
        token: $('#cb_api_token').val().trim(),
        uuid:  $('#cb_user_uuid').val().trim()
      };
      fetch(`${CB_Rest.root}calendly-bookings/v1/save-settings`, {
        method: 'POST',
        headers: { 'X-WP-Nonce': CB_Rest.nonce, 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      })
      .then(r => r.json())
      .then(res => $status.text(res.success ? `✅ ${res.message}` : `❌ ${res.message || 'Save failed.'}`))
      .catch(() => $status.text('❌ Error saving settings.'));
    });
  }

  if ($manualBtn.length) {
    $manualBtn.on('click', function(e) {
      e.preventDefault();
      $status.text('Testing API connectivity…');
      fetch(`${CB_Rest.root}calendly-bookings/v1/manual-test`, {
        method: 'GET',
        headers: { 'X-WP-Nonce': CB_Rest.nonce }
      })
      .then(r => r.json())
      .then(res => $status.text(res.success ? `✅ ${res.message}` : `❌ ${res.message || 'Test failed.'}`))
      .catch(() => $status.text('❌ Error testing connection.'));
    });
  }
});
