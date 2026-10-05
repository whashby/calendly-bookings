jQuery(function($) {
  // Create Product modal
  $(document).on('click', '.cb-create-btn', function(e) {
    e.preventDefault();
    const uuid = $(this).data('uuid');
    const name = $(this).data('name');
    $('#cb-create-event-uuid').val(uuid);
    $('#cb-create-product-name').val(name);
    tb_show('Create Product', '#TB_inline?inlineId=cb-create-modal', false);
  });

  // Link Product modal
  $(document).on('click', '.cb-link-btn', function(e) {
    e.preventDefault();
    const uuid = $(this).data('uuid');
    $('#cb-link-event-uuid').val(uuid);
    tb_show('Link Product', '#TB_inline?inlineId=cb-link-modal', false);
  });

  // Manage Product modal
  $(document).on('click', '.cb-manage-btn', function(e) {
    e.preventDefault();
    tb_show('Manage Product', '#TB_inline?inlineId=cb-manage-modal', false);
  });

  // Handle Create form submission
  $('#cb-create-form').on('submit', function(e) {
    e.preventDefault();
    const payload = $(this).serialize();

    fetch(`${CB_REST.root}wc/create-product`, {
      method: 'POST',
      headers: {
        'X-WP-Nonce': CB_REST.nonce,
        'Content-Type': 'application/x-www-form-urlencoded'
      },
      body: payload
    })
    .then(r => r.json())
    .then(res => {
      if (res.success) {
        location.reload();
      } else {
        alert(res.message || res.data?.message || 'Error creating product.');
      }
    });
  });

  // Handle Link form submission
  $('#cb-link-form').on('submit', function(e) {
    e.preventDefault();
    const payload = $(this).serialize();

    fetch(`${CB_REST.root}wc/link-product`, {
      method: 'POST',
      headers: {
        'X-WP-Nonce': CB_REST.nonce,
        'Content-Type': 'application/x-www-form-urlencoded'
      },
      body: payload
    })
    .then(r => r.json())
    .then(res => {
      if (res.success) {
        location.reload();
      } else {
        alert(res.message || res.data?.message || 'Error linking product.');
      }
    });
  });
  $(document).on('click', '.cb-unlink', function(e) {
    e.preventDefault();
    const uuid = $(this).data('uuid');
    if (!uuid || !confirm('Unlink this WooCommerce product from the Calendly event?')) return;
    fetch(`${CB_REST.root}wc/unlink-product`, {
      method: 'POST',
      headers: { 'X-WP-Nonce': CB_REST.nonce, 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({ uuid: uuid })
    }).then(r => r.json()).then(res => {
      if (res.success) location.reload();
      else alert(res.message || res.data?.message || 'Unable to unlink product.');
    });
  });

});
