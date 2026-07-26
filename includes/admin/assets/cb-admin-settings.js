jQuery(document).ready(function($) {

  // --- Save Credentials ---
  $('#cb-submit').on('click', function(e) {
    e.preventDefault();

    const apiKey  = $('#cb_api_key').val();
    const uuid    = $('#cb_user_uuid').val();
    const license = $('#cb_license_key').val();

    $.post(cb_admin.ajaxurl, {
      action: 'cb_save_credentials',
      api_key: apiKey,
      user_uuid: uuid,
      license_key: license,
      nonce: cb_admin.nonce
    }, function(response) {
      alert(response.message || (response.success ? 'Credentials saved successfully.' : 'Save failed.'));
    });
  });

  // --- Test Connection ---
  $('#cb-test-connection').on('click', function() {
    const apiKey  = $('#cb_api_key').val();
    const uuid    = $('#cb_user_uuid').val();
    const license = $('#cb_license_key').val();

    $.post(cb_admin.ajaxurl, {
      action: 'cb_test_connection',
      api_key: apiKey,
      user_uuid: uuid,
      license_key: license,
      nonce: cb_admin.nonce
    }, function(response) {
      alert(response.message || (response.success ? 'Connection and license authenticated.' : 'Test failed.'));
    });
  });

  // --- Validate License ---
  $('#cb-validate-license').on('click', function() {
    const license = $('#cb_license_key').val();

    $.post(cb_admin.ajaxurl, {
      action: 'cb_validate_license',
      license_key: license,
      nonce: cb_admin.nonce
    }, function(response) {
      alert(response.message || (response.success ? 'License validated successfully.' : 'Validation failed.'));
    });
  });

  $('#cb-save-settings').on('click', function(e) {
    e.preventDefault();
    const fields = getSelectedFields('#cb-sales-general');
    const filetype = $('#cb_report_filetype').val();
    const start = $('#cb_report_start').val();
    const end = $('#cb_report_end').val();

    $.post(cb_admin.ajaxurl, {
      action: 'cb_save_report_settings',
      fields: fields,
      filetype: filetype,
      start_date: start,
      end_date: end,
      nonce: cb_admin.nonce
    }, function(response) {
      alert(response.message || (response.success ? 'Settings saved.' : 'Save failed.'));
    });
  });


  // --- Reports ---
  function renderReports(reports) {
    let html = '';
    if (!reports || reports.length === 0) {
      html = '<tr><td colspan="7">No reports available</td></tr>';
    } else {
      html = ``;
      reports.forEach(report => {
        html += `
          <tr>
            <td><input type="checkbox" class="cb-report-select" data-id="${report.id}"></td>
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

    // Bind select-all
    $('#cb-select-all-reports').on('change', function() {
      const checked = $(this).is(':checked');
      $('.cb-report-select').prop('checked', checked);
    });
  }

  function loadReports() {
    $.post(cb_admin.ajaxurl, { action: 'cb_get_reports', nonce: cb_admin.nonce }, function(response) {
      if (response.success) {
        renderReports(response.data);
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
          alert(response.data.message || 'Report generated successfully.');
          renderReports(response.data.reports || []);
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

  // Tab switching
  $('#cb-report-tabs .nav-tab').on('click', function(e) {
    e.preventDefault();
    $('#cb-report-tabs .nav-tab').removeClass('nav-tab-active');
    $(this).addClass('nav-tab-active');
    $('.cb-report-tab-panel').removeClass('active').hide();
    const target = $(this).attr('href');
    $(target).addClass('active').show();
  });

  // Delete report
  $(document).on('click', '.cb-delete-report', function() {
    e.preventDefault();
    const reportId = $(this).data('id');
    $.post(cb_admin.ajaxurl, {
      action: 'cb_delete_report',
      report_id: reportId,
      nonce: cb_admin.nonce
    }, function(response) {
      if (response.success) {
        alert(response.data.message || 'Report deleted.');
        loadReports();
      } else {
        alert('Failed to delete report: ' + response.data.message);
      }
    });
  });

  // --- Bulk delete ---
  $('#cb-delete-selected').on('click', function() {
    const ids = $('.cb-report-select:checked').map(function(){ return $(this).data('id'); }).get();
    if (!ids.length) { alert('No reports selected.'); return; }
    $.post(cb_admin.ajaxurl, { action: 'cb_bulk_delete_reports', report_ids: ids, nonce: cb_admin.nonce }, function(response) {
      if (response.success) {
        alert(response.data.message);
        renderReports(response.data.reports);
      }
    });
  });

  // --- Bulk download ---
  $('#cb-download-selected').on('click', function() {
    const ids = $('.cb-report-select:checked').map(function(){ return $(this).data('id'); }).get();
    if (!ids.length) { alert('No reports selected.'); return; }
    const form = $('<form>', { method: 'POST', action: cb_admin.ajaxurl });
    form.append($('<input>', { type: 'hidden', name: 'action', value: 'cb_bulk_download_reports' }));
    form.append($('<input>', { type: 'hidden', name: 'report_ids', value: ids.join(',') }));
    form.append($('<input>', { type: 'hidden', name: 'nonce', value: cb_admin.nonce }));
    $('body').append(form);
    form.submit();
  });

  // --- Preview Print/Download ---
  $('#cb-print-report').on('click', function() {
    const content = $('#cb-report-preview-content').html();
    const summary = $('#cb-report-summary').html();
    const win = window.open('', '', 'height=800,width=600');
    win.document.write('<html><head><title>Report Preview</title></head><body>');
    win.document.write(content + '<h3>Summary</h3>' + summary);
    win.document.write('</body></html>');
    win.document.close();
    win.print();
  });

  $('#cb-download-preview').on('click', function() {
    const blob = new Blob(
      [$('#cb-report-preview-content').html() + "\n\nSummary:\n" + $('#cb-report-summary').text()],
      { type: 'text/plain;charset=utf-8' }
    );
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = 'report-preview.txt';
    link.click();
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
          $('#cb-individual-section').css('opacity', 0.5).find('input, select').prop('disabled', true);
          updateBadge('cb_master_sync', true);
        } else {
          $('#cb_master_sync').prop('checked', false);
          $('#cb-individual-section').css('opacity', 1).find('input, select').prop('disabled', false);
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
          const nextRun = job.next_run ? new Date(job.next_run*1000          ) : '—';
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
        alert(response.message);
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
        alert(response.message);
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
        alert(response.message);
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
        alert(response.message);
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

  // --- Initialize ---
  refreshCronList();
  loadReports();
  $('.cb-report-tab-panel').hide();
  $('#cb-sales-general').show().addClass('active');
});