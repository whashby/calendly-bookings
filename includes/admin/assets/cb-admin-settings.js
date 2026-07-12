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

  $('#cb-submit').on('click', function(e) {
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
  function loadReports() {
    $.post(cb_admin.ajaxurl, { action: 'cb_get_reports', nonce: cb_admin.nonce }, function(response) {
      if (response.success) {
        const reports = response.data;
        let html = '';
        if (reports.length === 0) {
          html = '<tr><td colspan="6">No reports available</td></tr>';
        } else {
          reports.forEach(report => {
            html += `
              <tr>
                <td>${report.date_range}</td>
                <td>${report.file_type.toUpperCase()}</td>
                <td>${report.type.replace('_',' ')}</td>
                <td>${report.fields.join(', ')}</td>
                <td>${new Date(report.created * 1000).toLocaleString()}</td>
                <td>
                  <a href="${report.download_url}" class="button">Download</a>
                  <button class="button cb-delete-report" data-id="${report.id}">Delete</button>
                </td>
              </tr>
            `;
          });
        }
        $('#cb-report-list').html(html);
      }
    });
  }

  // Helper to collect fields
  function getSelectedFields(panel) {
    const fields = [];
    $(`${panel} .cb-report-field:checked`).each(function() {
      fields.push($(this).val());
    });
    return fields;
  }

  // Generic handler for preview/generate
  function handleReportAction(panel, reportType, action) {
    const start = $(`${panel} input[type="date"]`).first().val();
    const end   = $(`${panel} input[type="date"]`).last().val();
    const type  = $(`${panel} select`).val();
    const fields = getSelectedFields(panel);

    if (!start || !end) {
      alert('Please select a start and end date.');
      return;
    }

    $.post(cb_admin.ajaxurl, {
      action: action,
      start_date: start,
      end_date: end,
      file_type: type,
      fields: fields,
      report_type: reportType,
      nonce: cb_admin.nonce
    }, function(response) {
      if (response.success) {
        if (action === 'cb_preview_report') {
          tb_show('Report Preview', '#TB_inline?height=600&width=800&inlineId=cb-report-preview');
          $('#cb-report-preview-content').html(response.data.html);
          $('#cb-report-summary').html(response.data.summary);
        } else {
          alert('Report generated successfully.');
          loadReports();
        }
      } else {
        alert('Failed: ' + response.data.message);
      }
    });
  }

  // Bind buttons per tab
  $('#cb-sales-general #cb-preview-report').on('click', () => handleReportAction('#cb-sales-general','sales_general','cb_preview_report'));
  $('#cb-sales-general #cb-generate-report').on('click', () => handleReportAction('#cb-sales-general','sales_general','cb_generate_report'));

  $('#cb-sales-product #cb-preview-product-report').on('click', () => handleReportAction('#cb-sales-product','sales_product','cb_preview_report'));
  $('#cb-sales-product #cb-generate-product-report').on('click', () => handleReportAction('#cb-sales-product','sales_product','cb_generate_report'));

  $('#cb-discounts-refunds #cb-preview-discount-report').on('click', () => handleReportAction('#cb-discounts-refunds','discounts_refunds','cb_preview_report'));
  $('#cb-discounts-refunds #cb-generate-discount-report').on('click', () => handleReportAction('#cb-discounts-refunds','discounts_refunds','cb_generate_report'));

  $('#cb-sales-statistics #cb-preview-stats-report').on('click', () => handleReportAction('#cb-sales-statistics','sales_statistics','cb_preview_report'));
  $('#cb-sales-statistics #cb-generate-stats-report').on('click', () => handleReportAction('#cb-sales-statistics','sales_statistics','cb_generate_report'));

    // Handle tab clicks
    $('#cb-report-tabs .nav-tab').on('click', function(e) {
      e.preventDefault();

      // Remove active class from all tabs
      $('#cb-report-tabs .nav-tab').removeClass('nav-tab-active');
      // Add active class to clicked tab
      $(this).addClass('nav-tab-active');

      // Hide all panels
      $('.cb-report-tab-panel').removeClass('active').hide();

      // Show the selected panel
      const target = $(this).attr('href');
      $(target).addClass('active').show();
    });
  
  // Manual report generation
  $('#cb-generate-report').on('click', function() {
    const start = $('#cb_report_start').val();
    const end   = $('#cb_report_end').val();
    const type  = $('#cb_report_filetype').val();
    const reportType = $('#cb_report_type').val(); // dropdown or hidden field

    const fields = [];
    $('.cb-report-field:checked').each(function() {
      fields.push($(this).val());
    });

    if (!start || !end) {
      alert('Please select a start and end date.');
      return;
    }

    $.post(cb_admin.ajaxurl, {
      action: 'cb_generate_report',
      start_date: start,
      end_date: end,
      file_type: type,
      fields: fields,
      report_type: reportType,
      nonce: cb_admin.nonce
    }, function(response) {
      if (response.success) {
        alert('Report generated successfully.');
        loadReports();
      } else {
        alert('Failed to generate report: ' + response.data.message);
      }
    });
  });

  // Preview report in Thickbox
  $('#cb-preview-report').on('click', function() {
    const start = $('#cb_report_start').val();
    const end   = $('#cb_report_end').val();
    const type  = $('#cb_report_filetype').val();
    const reportType = $('#cb_report_type').val();

    const fields = [];
    $('.cb-report-field:checked').each(function() {
      fields.push($(this).val());
    });

    if (!start || !end) {
      alert('Please select a start and end date.');
      return;
    }

    $.post(cb_admin.ajaxurl, {
      action: 'cb_preview_report',
      start_date: start,
      end_date: end,
      file_type: type,
      fields: fields,
      report_type: reportType,
      nonce: cb_admin.nonce
    }, function(response) {
      if (response.success) {
        tb_show('Report Preview', '#TB_inline?height=600&width=800&inlineId=cb-report-preview');
        $('#cb-report-preview-content').html(response.html);
        $('#cb-report-summary').html(response.summary);
      } else {
        alert('Preview failed: ' + response.data.message);
      }
    });
  });

  // Delete report
  $(document).on('click', '.cb-delete-report', function() {
    const reportId = $(this).data('id');
    $.post(cb_admin.ajaxurl, {
      action: 'cb_delete_report',
      report_id: reportId,
      nonce: cb_admin.nonce
    }, function(response) {
      if (response.success) {
        alert('Report deleted.');
        loadReports();
      } else {
        alert('Failed to delete report: ' + response.data.message);
      }
    });
  });

  // --- Refresh Active Cron Jobs Panel ---
  function refreshCronList() {
    $.post(cb_admin.ajaxurl, { action: 'cb_get_active_crons', nonce: cb_admin.nonce }, function(response) {
      if (response.success) {
        const crons = response.data;

        // Master sync
        if (crons.master && crons.master.enabled) {
          $('#cb_master_sync').prop('checked', true);

          const freq = crons.master.frequency || $('#cb_master_frequency option:selected').val();
          $('#cb_master_frequency').val(freq).prop('disabled', false);

          $('#cb-individual-section').css('opacity', 0.5)
            .find('input, select').prop('disabled', true);

          updateBadge('cb_master_sync', true);
        } else {
          $('#cb_master_sync').prop('checked', false);
          $('#cb-individual-section').css('opacity', 1)
            .find('input, select').prop('disabled', false);

          updateBadge('cb_master_sync', false);
        }

        // Individual syncs
        ['events','invitees','event_types','locations'].forEach(type => {
          const checkboxId  = `#cb_sync_${type}`;
          const frequencyId = `#cb_sync_${type}_frequency`;
          const enabled = crons[type] && crons[type].enabled;

          $(checkboxId).prop('checked', enabled);
          $(frequencyId).val(crons[type]?.frequency || 'cb_daily').prop('disabled', !enabled);

          updateBadge(`cb_sync_${type}`, enabled);
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

  // --- Master toggle ---
  $('#cb_master_sync').on('change', function() {
    if ($(this).is(':checked')) {
      $.post(cb_admin.ajaxurl, {
        action: 'cb_schedule_master_sync',
        frequency: $('#cb_master_frequency').val(),
        nonce: cb_admin.nonce
      }, function(response) {
        alert(response.data.message);
        refreshCronList();
      });
    } else {
      $.post(cb_admin.ajaxurl, {
        action: 'cb_clear_master_sync',
        nonce: cb_admin.nonce
      }, refreshCronList);
    }
  });

  // --- Individual toggle ---
  $('.cb-individual-sync').on('change', function() {
    const syncId = this.id;
    if ($(this).is(':checked')) {
      $.post(cb_admin.ajaxurl, {
        action: 'cb_schedule_individual_sync',
        sync_type: syncId,
        frequency: $(`#${syncId}_frequency`).val(),
        nonce: cb_admin.nonce
      }, function(response) {
        alert(response.data.message);
        refreshCronList();
      });
    } else {
      $.post(cb_admin.ajaxurl, {
        action: 'cb_clear_individual_sync',
        sync_type: syncId,
        nonce: cb_admin.nonce
      }, refreshCronList);
    }
  });

  // --- Frequency change handlers ---
  $('#cb_master_frequency').on('change', function() {
    if ($('#cb_master_sync').is(':checked')) {
      $.post(cb_admin.ajaxurl, {
        action: 'cb_schedule_master_sync',
        frequency: $(this).val(),
        nonce: cb_admin.nonce
      }, function(response) {
        alert(response.data.message);
        refreshCronList();
      });
    }
  });

  $('.cb-individual-frequency').on('change', function() {
    const syncId = $(this).attr('id').replace('_frequency', '');
    if ($(`#${syncId}`).is(':checked')) {
      $.post(cb_admin.ajaxurl, {
        action: 'cb_schedule_individual_sync',
        sync_type: syncId,
        frequency: $(this).val(),
        nonce: cb_admin.nonce
      }, function(response) {
        alert(response.data.message);
        refreshCronList();
      });
    }
  });

  // --- Helper to update badges ---
  function updateBadge(id, enabled) {
    const badge = $(`#${id}`).closest('.cb-sync-item, .cb-sync-controls').find('.cb-status-badge');
    if (enabled) {
      badge.removeClass('disabled').addClass('enabled').text('Enabled');
    } else {
      badge.removeClass('enabled').addClass('disabled').text('Disabled');
    }
  }
  
  // Create toggle button in admin header
  const toggleBtn = $('<button id="cb-darkmode-toggle" class="button">Toggle Dark Mode</button>');
  $('#cb-report-tabs').before(toggleBtn);

  // Apply saved preference
  if (localStorage.getItem('cb_darkmode') === 'enabled') {
    $('body').addClass('dark-mode');
  }

  // Toggle handler
  $('#cb-darkmode-toggle').on('click', function() {
    $('body').toggleClass('dark-mode');
    if ($('body').hasClass('dark-mode')) {
      localStorage.setItem('cb_darkmode', 'enabled');
      $(this).text('Light Mode');
    } else {
      localStorage.setItem('cb_darkmode', 'disabled');
      $(this).text('Dark Mode');
    }
  });

  // Update button text on load
  if ($('body').hasClass('dark-mode')) {
    $('#cb-darkmode-toggle').text('Light Mode');
  }

  // --- Initialize ---
  refreshCronList();
  loadReports(); // also initialize the reports table
  $('.cb-report-tab-panel').hide();
  $('#cb-sales-general').show().addClass('active');
});
