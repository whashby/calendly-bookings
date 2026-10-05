jQuery(function($) {
  const cfg = window.cb_admin || {};
  const ajax = cfg.ajaxurl || window.ajaxurl;

  function request(action, data, onSuccess) {
    data = Object.assign({ action: action, nonce: cfg.nonce }, data || {});
    return $.post(ajax, data).done(function(response) {
      if (response && response.success) {
        if (onSuccess) onSuccess(response.data || response);
      } else {
        const message = response?.data?.message || response?.message || 'Request failed.';
        window.alert(message);
      }
    }).fail(function(xhr) {
      window.alert(xhr.responseJSON?.data?.message || 'Request failed.');
    });
  }

  // Credentials
  $('#cb-submit').on('click', function(e) {
    e.preventDefault();
    request('cb_save_credentials', {
      api_key: $('#cb_api_key').val(),
      user_uuid: $('#cb_user_uuid').val(),
      license_key: $('#cb_license_key').val()
    }, d => window.alert(d.message || 'Saved.'));
  });

  $('#cb-test-connection').on('click', function() {
    request('cb_test_connection', {
      api_key: $('#cb_api_key').val(),
      user_uuid: $('#cb_user_uuid').val(),
      license_key: $('#cb_license_key').val()
    }, d => window.alert(d.message || 'Connection successful.'));
  });

  $('#cb-validate-license').on('click', function() {
    request('cb_validate_license', { license_key: $('#cb_license_key').val() },
      d => window.alert(d.message || 'License validated.'));
  });

  // Email templates — only initialized when the email UI is present.
  if ($('#cb-save-email-settings').length) {
    let activeTemplate = $('.cb-email-template-tab.is-active').data('template');

    function activePanel() {
      return $(`.cb-email-template-panel[data-template-panel="${activeTemplate}"]`);
    }

    function previewEmail() {
      const body = activePanel().find('.cb-email-body').val() || '';
      const subject = activePanel().find('.cb-email-subject').val() || '';
      const frame = activePanel().find('.cb-email-live-preview')[0];
      if (!frame) return;
      const doc = frame.contentDocument || frame.contentWindow.document;
      doc.open();
      doc.write(`<html><body><h3>${$('<div>').text(subject).html()}</h3>${body}</body></html>`);
      doc.close();
    }

    $('.cb-email-template-tab').on('click', function() {
      activeTemplate = $(this).data('template');
      $('.cb-email-template-tab').removeClass('is-active');
      $(this).addClass('is-active');
      $('.cb-email-template-panel').removeClass('is-active');
      $(`.cb-email-template-panel[data-template-panel="${activeTemplate}"]`).addClass('is-active');
      previewEmail();
    });

    $('.cb-email-body,.cb-email-subject').on('input', previewEmail);

    $('.cb-email-token').on('click', function() {
      const token = $(this).data('token');
      const textarea = activePanel().find('.cb-email-body')[0];
      if (!textarea) return;
      const start = textarea.selectionStart || textarea.value.length;
      const end = textarea.selectionEnd || start;
      textarea.value = textarea.value.substring(0, start) + token + textarea.value.substring(end);
      textarea.focus();
      textarea.selectionStart = textarea.selectionEnd = start + token.length;
      previewEmail();
    });

    $('#cb-save-email-settings').on('click', function() {
      const templates = {};
      $('.cb-email-template-panel').each(function() {
        const panel = $(this);
        const key = panel.data('template-panel');
        templates[key] = {
          enabled: panel.find('.cb-email-enabled').is(':checked') ? 1 : 0,
          recipients: panel.find('.cb-email-recipient').val(),
          subject: panel.find('.cb-email-subject').val(),
          body: panel.find('.cb-email-body').val()
        };
      });

      request('cb_save_email_templates', {
        templates: templates,
        email_to: $('#cb_email_to').val(),
        email_from: $('#cb_email_from').val(),
        email_reply_to: $('#cb_email_reply_to').val(),
        email_bcc: $('#cb_email_bcc').val()
      }, d => $('#cb-email-status').text(d.message || 'Saved.'));
    });

    $('#cb-preview-email').on('click', previewEmail);

    $('#cb-test-email').on('click', function() {
      request('cb_test_email', {
        template: activeTemplate,
        to: $('#cb-test-email-recipient').val()
      }, d => $('#cb-email-status').text(d.message || 'Test email sent.'));
    });

    previewEmail();
  }

  // Reports — nothing runs unless the reports panel exists.
  if ($('#cb-report-type').length || $('#cb-generate-report').length) {
    const fieldMap = JSON.parse($('.cb-report-fields').attr('data-field-map') || '{}');

    function renderFieldOptions() {
      const type = $('#cb_report_type').val();
      const fields = fieldMap[type] || fieldMap.sales_general || {};
      const saved = $('.cb-report-field:checked').map(function() { return $(this).val(); }).get();
      let html = '';
      Object.keys(fields).forEach(function(key) {
        const checked = saved.length ? saved.indexOf(key) !== -1 : true;
        html += `<label><input type="checkbox" class="cb-report-field" value="${key}" ${checked ? 'checked' : ''}> ${$('<div>').text(fields[key]).html()}</label>`;
      });
      $('.cb-report-field-grid').html(html);
    }

    function selectedFields() {
      return $('.cb-report-field:checked').map(function() { return $(this).val(); }).get();
    }

    function reportArgs() {
      return {
        start_date: $('#cb_report_start').val(),
        end_date: $('#cb_report_end').val(),
        report_type: $('#cb_report_type').val(),
        file_type: $('#cb_report_filetype').val(),
        fields: selectedFields()
      };
    }

    function renderReports(reports) {
      const $body = $('#cb-report-list');
      if (!reports || !reports.length) {
        $body.html('<tr><td colspan="7">No reports available.</td></tr>');
        return;
      }

      let html = '';
      reports.forEach(r => {
        const label = (r.type || '').replaceAll('_', ' ');
        const action = r.downloadable
          ? `<a class="button button-small" href="${ajax}?action=cb_download_report&report_id=${encodeURIComponent(r.id)}&nonce=${encodeURIComponent(cfg.nonce)}">Download</a>`
          : (['queued', 'failed'].includes(r.status) ? `<button type="button" class="button cb-process-report" data-id="${r.id}">${r.status === 'failed' ? 'Retry' : 'Generate now'}</button>` : '');
        const error = r.error_message ? `<div class="cb-report-error">${$('<div>').text(r.error_message).html()}</div>` : '';
        html += `<tr>
          <td>${$('<div>').text(label).html()}</td>
          <td>${r.start_date} → ${r.end_date}</td>
          <td>${String(r.format || '').toUpperCase()}</td>
          <td>${$('<div>').text(r.status || '').html()}${error}</td>
          <td>${r.row_count || 0}</td>
          <td>${r.created_ts || ''}</td>
          <td>${action} <button type="button" class="button button-small cb-delete-report" data-id="${r.id}">Delete</button></td>
        </tr>`;
      });
      $body.html(html);
    }

    function loadReports() {
      request('cb_get_reports', {}, d => renderReports(d));
    }

    $('#cb_report_type').on('change', renderFieldOptions);

    $('#cb-preview-report').on('click', function() {
      const args = reportArgs();
      if (!args.start_date || !args.end_date) return window.alert('Select a date range.');
      $('#cb-report-status').text('Building preview…');
      request('cb_preview_report', args, d => {
        $('#cb-report-preview-content').html(d.html || '<p>No data.</p>');
        $('#cb-report-summary').html(d.summary || '');
        $('#cb-report-preview-panel').prop('hidden', false);
        $('#cb-report-status').text('');
      });
    });

    $('#cb-generate-report').on('click', function() {
      const args = reportArgs();
      if (!args.start_date || !args.end_date) return window.alert('Select a date range.');
      $('#cb-report-status').text('Queueing report…');
      request('cb_generate_report', args, d => {
        $('#cb-report-status').text(d.message || 'Report queued.');
        renderReports(d.reports || []);
        setTimeout(loadReports, 1200);
      });
    });

    $('#cb-save-report-settings').on('click', function() {
      request('cb_save_report_settings', {
        fields: selectedFields(),
        filetype: $('#cb_report_filetype').val(),
        start_date: $('#cb_report_start').val(),
        end_date: $('#cb_report_end').val()
      }, d => $('#cb-report-status').text(d.message || 'Defaults saved.'));
    });

    $('#cb-refresh-reports').on('click', loadReports);
    $(document).on('click', '.cb-process-report', function() {
      const button = $(this).prop('disabled', true).text('Generating…');
      request('cb_process_report', { report_id: button.data('id') }, renderReports);
    });

    $(document).on('click', '.cb-delete-report', function() {
      const id = $(this).data('id');
      if (!window.confirm('Delete this report?')) return;
      request('cb_delete_report', { report_id: id }, d => renderReports(d.reports || []));
    });

    renderFieldOptions();
    loadReports();
  }

  function siteCronTime(timestamp) {
    const date = new Date(timestamp * 1000);
    try { return date.toLocaleString(undefined, { timeZone: cfg.timezone || 'UTC' }); }
    catch (_) { return new Date(date.getTime() + (cfg.utc_offset || 0) * 60000).toLocaleString(undefined, { timeZone: 'UTC' }); }
  }
  // Sync controls — only query cron state on settings screens that contain it.
  if ($('#cb-cron-list').length) {
    function refreshCronList() {
      request('cb_get_active_crons', {}, function(crons) {
        let html = '';
        Object.keys(crons || {}).forEach(type => {
          const job = crons[type] || {};
          const id = type === 'master' ? 'cb_master_sync' : 'cb_sync_' + (type === 'scheduled_events' ? 'events' : type);
          const control = $('#' + id).prop('checked', !!job.enabled);
          control.closest(type === 'master' ? '.cb-sync-controls' : '.cb-sync-item').find('.cb-status-badge').toggleClass('enabled', !!job.enabled).toggleClass('disabled', !job.enabled).text(job.enabled ? 'Enabled' : 'Disabled');
          if (job.frequency) $('#' + (type === 'master' ? 'cb_master_frequency' : id + '_frequency')).val(job.frequency);
          html += `<li>${$('<div>').text(type).html()} → ${$('<div>').text(job.frequency_label || job.frequency || '—').html()}${job.next_run ? ' — ' + siteCronTime(job.next_run) : ''}</li>`;
        });
        $('#cb-cron-list').html(html);
        $('.cb-individual-sync, .cb-individual-frequency').prop('disabled', $('#cb_master_sync').is(':checked'));
      });
    }

    $('.cb-individual-sync').on('change', function() {
      const id = this.id;
      const enabled = $(this).is(':checked');
      request(enabled ? 'cb_schedule_individual_sync' : 'cb_clear_individual_sync', {
        sync_type: id,
        frequency: $(`#${id}_frequency`).val()
      }, refreshCronList);
    });

    $('#cb_master_sync').on('change', function() {
      const enabled = $(this).is(':checked');
      request(enabled ? 'cb_schedule_master_sync' : 'cb_clear_master_sync', {
        frequency: $('#cb_master_frequency').val()
      }, refreshCronList);
    });

    $('#cb_master_frequency').on('change', function() { if ($('#cb_master_sync').is(':checked')) $('#cb_master_sync').trigger('change'); });
    $('.cb-individual-frequency').on('change', function() { const id = this.id.replace(/_frequency$/, ''); if ($('#' + id).is(':checked')) $('#' + id).trigger('change'); });
    refreshCronList();
  }
});
