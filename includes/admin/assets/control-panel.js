jQuery(function($) {
  const $root = $('.wrap');

  function postJSON(url, body) {
    return fetch(url, {
      method: 'POST',
      headers: { 'X-WP-Nonce': CB_Rest.nonce, 'Content-Type': 'application/json' },
      body: JSON.stringify(body || {})
    }).then(r => r.json());
  }

  function getJSON(url) {
    return fetch(url, {
      headers: { 'X-WP-Nonce': CB_Rest.nonce }
    }).then(r => r.json());
  }
	
	
  function showNotice(msg, type = 'info') {
    const $notice = $(`<div class="notice notice-${type}"><p>${msg}</p></div>`);
    $('.wrap h1').after($notice);
    return $notice;
  }

  // Bulk Create All Products
  $root.on('click', '.cb-bulk-create', function() {
    if (!confirm('Create products for ALL events without a linked product?')) return;
    const $n = showNotice('Creating all products...');
    postJSON(`${CB_Rest.root}wc/create-all`)
      .then(res => {
        $n.remove();
        alert(res.success ? `Created ${res.created_count} products` : res.message);
        if (res.success) location.reload();
      });
  });

  // Bulk Delete All Products
  $root.on('click', '.cb-bulk-delete', function() {
    if (!confirm('Delete ALL linked products? This cannot be undone.')) return;
    const $n = showNotice('Deleting all products...');
    postJSON(`${CB_Rest.root}wc/delete-all`)
      .then(res => {
        $n.remove();
        alert(res.success ? `Deleted ${res.deleted_count} products` : res.message);
        if (res.success) location.reload();
      });
  });

  // Per-row Sync
  $root.on('click', '.cb-sync', function() {
    const uuid = $(this).data('uuid');
    const $n = showNotice(`Syncing event ${uuid}...`);
    getJSON(`${CB_Rest.root}event-types?uuid=${encodeURIComponent(uuid)}`)
      .then(res => {
        $n.remove();
        alert(res.success ? 'Event synced' : res.message);
        if (res.success) location.reload();
      });
  });

  // Per-row Link (reads Product ID from input)
$root.on('click', '.cb-link', function() {
  const uuid = $(this).data('uuid');
  const pid  = Number($(this).closest('tr').find('.cb-product-id').val());

  if (!uuid) {
    alert('Missing event UUID');
    return;
  }
  if (!pid) {
    alert('Enter a valid Product ID');
    return;
  }
  fetch(`${CB_Rest.root}wc/link`, {
    method: 'POST',
    headers: { 'X-WP-Nonce': CB_Rest.nonce, 'Content-Type': 'application/json' },
    body: JSON.stringify({ uuid, product_id: pid })
  })
  .then(r => r.json())
  .then(res => {
    alert(res.message);
    if (res.success) location.reload();
  });
});

// Create product
$root.on('click', '.cb-create', function() {
  const uuid = $(this).data('uuid');
  fetch(`${CB_Rest.root}wc/create-product`, {
    method: 'POST',
    headers: { 'X-WP-Nonce': CB_Rest.nonce, 'Content-Type': 'application/json' },
    body: JSON.stringify({ uuid })
  })
  .then(r => r.json())
  .then(res => {
    alert(res.message || (res.success ? 'New product created.' : 'Create product failed'));
    if (res.success) location.reload();
  });
});

// Delete product
$root.on('click', '.cb-delete', function() {
  const uuid = $(this).data('uuid');
  if (!confirm('Delete the linked product for this event? This cannot be undone.')) return;
  fetch(`${CB_Rest.root}wc/delete-product`, {
    method: 'POST',
    headers: { 'X-WP-Nonce': CB_Rest.nonce, 'Content-Type': 'application/json' },
    body: JSON.stringify({ uuid })
  })
  .then(r => r.json())
  .then(res => {
    alert(res.message || (res.success ? 'Product deleted.' : 'Delete product failed'));
    if (res.success) location.reload();
  });
});

});
