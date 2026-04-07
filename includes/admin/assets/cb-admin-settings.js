jQuery(document).ready(function($) {

  // --- Connection & Credentials ---
  $('#cb-test-connection').on('click', function() {
    const apiKey  = $('#cb_api_key').val();
    const uuid    = $('#cb_user_uuid').val();
    const license = $('#cb_license_key').val();

    $.post(ajaxurl, {
      action: 'cb_test_connection',
      api_key: apiKey,
      user_uuid: uuid,
      license_key: license
    }, function(response) {
      alert(response.message);
    });
  });

  $('#submit').on('click', function(e) {
    e.preventDefault(); // Prevent form submission

    if (this.value !== 'Save Credentials') {
      const apiKey  = $('#cb_api_key').val();
      const uuid    = $('#cb_user_uuid').val();
      const license = $('#cb_license_key').val();

      $.post(ajaxurl, {
        action: 'cb_save_credentials',
        api_key: apiKey,
        user_uuid: uuid,
        license_key: license
      }, function(response) {
        alert(response.message);
      });
    }
  });

  // --- Sync Helpers ---
  function runSync(action, params = {}) {
    $.post(ajaxurl, { action, ...params }, function(response) {
      alert(response.message);
      refreshCronList();
    });
  }

  $('#cb-sync-all').on('click', () => runSync('cb_sync_all'));
  $('#cb-sync-events').on('click', () => runSync('cb_sync_events'));
  $('#cb-sync-invitees').on('click', () => runSync('cb_sync_invitees', { force: true }));
  $('#cb-sync-event-types').on('click', () => runSync('cb_sync_event_types'));
  $('#cb-sync-locations').on('click', () => runSync('cb_sync_locations'));

  // --- Reports ---
  $('#cb-generate-report').on('click', function() {
    window.location.href = ajaxurl + '?action=cb_generate_report';
  });

  $('#cb-preview-report').on('click', function() {
    $.post(ajaxurl, { action: 'cb_preview_report' }, function(response) {
      if (response.success) {
        $('#cb-report-preview').html(response.html);
      } else {
        alert('Preview failed');
      }
    });
  });

  // --- Refresh Active Cron Jobs Panel ---
  function refreshCronList() {
    $.post(ajaxurl, { action: 'cb_get_active_crons' }, function(response) {
      if (response.success) {
        const crons = response.data;

        // Master sync
        if (crons.master && crons.master.enabled) {
          $('#cb_master_sync').prop('checked', true);
          $('#cb_master_frequency').val(crons.master.frequency).prop('disabled', false);
          $('#cb-individual-section').css('opacity', 0.5)
            .find('input, select').prop('disabled', true);
        } else {
          $('#cb_master_sync').prop('checked', false);
        }

        // Individual syncs
        ['events','invitees','event_types','locations'].forEach(type => {
          if (crons[type] && crons[type].enabled) {
            $(`#cb_sync_${type}`).prop('checked', true);
            $(`#cb_sync_${type}_frequency`).val(crons[type].frequency).prop('disabled', false);
          } else {
            $(`#cb_sync_${type}`).prop('checked', false);
            $(`#cb_sync_${type}_frequency`).prop('disabled', true);
          }
        });

        // Populate Active Cron Jobs panel
        let listHtml = '';
        Object.keys(crons).forEach(type => {
          const job = crons[type];
          const freq = job.frequency || '—';
          const nextRun = job.next_run ? new Date(job.next_run*1000).toLocaleString() : '—';
          listHtml += `<li>${type} → ${freq}, next run: ${nextRun}</li>`;
        });
        $('#cb-cron-list').html(listHtml);
      }
    });
  }

  // --- Sync Toggles ---
  function toggleMasterSync() {
    const masterEnabled = $('#cb_master_sync').is(':checked');
    $('#cb_master_frequency').prop('disabled', !masterEnabled);

    if (masterEnabled) {
      $('#cb-individual-section').css('opacity', 0.5);
      $('#cb-individual-section input, #cb-individual-section select').prop('disabled', true);
      $.post(ajaxurl, { action: 'cb_clear_individual_crons' }, refreshCronList);
    } else {
      $('#cb-individual-section').css('opacity', 1);
      $('.cb-individual-sync').each(function() {
        const enabled = $(this).is(':checked');
        $(`#${this.id}_frequency`).prop('disabled', !enabled);
      });
    }
  }

  function toggleIndividualSync(syncId) {
    const enabled = $(`#${syncId}`).is(':checked');
    $(`#${syncId}_frequency`).prop('disabled', !enabled);

    if (enabled) {
      $.post(ajaxurl, {
        action: 'cb_schedule_individual_sync',
        sync_type: syncId,
        frequency: $(`#${syncId}_frequency`).val()
      }, refreshCronList);
    } else {
      $.post(ajaxurl, {
        action: 'cb_clear_individual_sync',
        sync_type: syncId
      }, refreshCronList);
    }
  }

  $('#cb_master_sync').on('change', toggleMasterSync);
  $('.cb-individual-sync').on('change', function() {
    toggleIndividualSync(this.id);
  });

  // --- Frequency Change Handlers ---
  $('#cb_master_frequency').on('change', function() {
    const frequency = $(this).val();
    if ($('#cb_master_sync').is(':checked')) {
      $.post(ajaxurl, {
        action: 'cb_schedule_master_sync',
        frequency: frequency
      }, function(response) {
        if (response.success) {
          alert('Master sync rescheduled to ' + frequency);
          refreshCronList();
        }
      });
    }
  });

  $('.cb-individual-frequency').on('change', function() {
    const syncId   = $(this).attr('id').replace('_frequency', '');
    const frequency = $(this).val();
    if ($(`#${syncId}`).is(':checked')) {
      $.post(ajaxurl, {
        action: 'cb_schedule_individual_sync',
        sync_type: syncId,
        frequency: frequency
      }, function(response) {
        if (response.success) {
          alert(syncId.replace('cb_sync_', '') + ' sync rescheduled to ' + frequency);
          refreshCronList();
        }
      });
    }
  });

  // --- Initialize ---
  toggleMasterSync();
  refreshCronList();

  // Optional: manual refresh button
  $('#cb-refresh-crons').on('click', refreshCronList);

});
