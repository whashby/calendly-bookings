jQuery(function($) {
  const $filters = $('#cb-audit-filters');
  const $logWrap = $('#cb-audit-log');
  const $notices = $('#cb-admin-notices');

  // Filter change triggers REST reload
  $filters.on('change', 'input, select', function() {
    reloadAuditLog($filters.serialize());
  });

  // Pagination links intercepted
  $(document).on('click', '.tablenav-pages a', function(e) {
    e.preventDefault();
    const href = $(this).attr('href');
    const paged = new URLSearchParams(href.split('?')[1]).get('paged');
    const payload = $filters.serialize() + '&paged=' + paged;
    reloadAuditLog(payload);
  });

  function reloadAuditLog(queryString) {
    fetch(`${CB_REST.root}audit-log?${queryString}`, {
      method: 'GET',
      headers: { 'X-WP-Nonce': CB_REST.nonce }
    })
    .then(r => r.json())
    .then(res => {
      if (res.success) {
        $logWrap.html(res.data.html);
      } else {
        $notices.html('<div class="notice notice-error"><p>' + res.data + '</p></div>');
      }
    })
    .catch(() => {
      $notices.html('<div class="notice notice-error"><p>Error loading audit log.</p></div>');
    });
  }

  // Show details in modal
  $(document).on('click', '.cb-details-toggle', function() {
    let details = $(this).data('details');

    // If details is an object, stringify it
    if (typeof details === 'object') {
      details = JSON.stringify(details, null, 2); // pretty-print JSON
    }

    // Populate modal content
    $('#cb-details-text').text(details);

    // Open ThickBox modal
    tb_show('Audit Log Details', '#TB_inline?inlineId=cb-details-modal', false);
  });
});
