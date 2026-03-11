jQuery(function($) {
  const $form    = $('#cb-settings-form');
  const $status  = $('#cb-admin-notices');
  const $testBtn = $('#cb-test-connection');
  const $saveBtn = $('#cb-save-settings');
  const $apiKey  = $('#cb_api_key');
  const $uuid    = $('#cb_uuid');
  const $runSync = $('#cb-run-sync');
  const $syncRes = $('#cb-sync-result');

  let lastTestSuccess = false;

  function setStatus(success, message) {
    const prefix = success ? '✅' : '❌';
    $status.html(
      `<div class="notice ${success ? 'notice-success' : 'notice-error'}"><p>${prefix} ${message}</p></div>`
    );
  }

  function toggleSaveVisibility() {
    if ($apiKey.val().trim() || $uuid.val().trim()) {
      $saveBtn.show();
    } else {
      $saveBtn.hide();
    }
  }

  function doRequest(url, options, successMsg, errorMsg) {
    fetch(url, options)
      .then(r => r.json())
      .then(res => {
        if (res.success) {
          setStatus(true, res.message || successMsg);
          lastTestSuccess = true;
          if ($apiKey.val().trim() || $uuid.val().trim()) {
            $saveBtn.show();
          }
        } else {
          setStatus(false, res.message || errorMsg);
          lastTestSuccess = false;
          $saveBtn.hide();
        }
      })
      .catch(() => {
        setStatus(false, errorMsg);
        lastTestSuccess = false;
        $saveBtn.hide();
      });
  }

  // Watch inputs for changes
  $apiKey.on('input', toggleSaveVisibility);
  $uuid.on('input', toggleSaveVisibility);

  // Test connection
  $testBtn.on('click', function(e) {
    e.preventDefault();
    setStatus(true, 'Testing API connectivity…');

    const apiKeyInput = $apiKey.val().trim()|| null;;
    const uuidInput   = $uuid.val().trim()|| null;;

    // Send only changed fields; backend fills in missing ones
    doRequest(
      `${CB_REST.root}manual-test`,
      {
        method: 'POST',
        headers: {
          'X-WP-Nonce': CB_REST.nonce,
          'Content-Type': 'application/json'
        },
        body: JSON.stringify({ api_key: apiKeyInput, uuid: uuidInput })
      },
      'Connection successful.',
      'Error testing connection.'
    );
  });

  // Save settings
  $form.on('submit', function(e) {
    e.preventDefault();

    if (!lastTestSuccess) {
      setStatus(false, 'Please test connection successfully before saving.');
      return false;
    }

    const payload = {};
    const apiKeyInput = $apiKey.val().trim();
    const uuidInput   = $uuid.val().trim();

    if (apiKeyInput) payload.api_key = apiKeyInput;
    if (uuidInput) payload.uuid = uuidInput;

    if ($.isEmptyObject(payload)) {
      setStatus(false, 'No changes to save.');
      return false;
    }

    setStatus(true, 'Saving settings…');

    doRequest(
      `${CB_REST.root}save-settings`,
      {
        method: 'POST',
        headers: {
          'X-WP-Nonce': CB_REST.nonce,
          'Content-Type': 'application/json'
        },
        body: JSON.stringify(payload)
      },
      'Settings saved successfully.',
      'Error saving settings.'
    );
  });

  // Run Sync Now
  $runSync.on('click', function(e) {
    e.preventDefault();
    $syncRes.text('Running sync...');

    fetch(`${CB_REST.root}sync`, {
      method: 'POST',
      headers: {
        'X-WP-Nonce': CB_REST.nonce,
        'Content-Type': 'application/json'
      }
    })
    .then(r => r.json())
    .then(res => {
      if (res.success) {
        if (res.last_sync && res.last_sync.time) {
          const count = res.last_sync.count || 0;
          $syncRes.text(`Last sync: ${res.last_sync.time} (${count} events)`);
        } else {
          $syncRes.text('Sync completed successfully.');
        }
      } else {
        $syncRes.text('Error: ' + (res.message || 'Unknown error'));
      }
    })
    .catch(() => {
      $syncRes.text('Error running sync.');
    });
  });
});
