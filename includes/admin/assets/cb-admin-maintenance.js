jQuery(document).ready(function($) {
  $('.cb-maintenance-btn').on('click', function() {
    const subaction = $(this).data('action');
    const $btn = $(this);
alert(); return;
    // Disable button while running
    $btn.prop('disabled', true);
    $('#cb-sync-status').text('Running ' + subaction + '...');

    $.ajax({
      url: ajaxurl, // WordPress global for admin-ajax.php
      method: 'POST',
      data: {
        action: 'cb_maintenance_action',
        subaction: subaction
      },
      success: function(response) {
        if (response.success) {
          $('#cb-sync-status').text('Completed: ' + (response.data.message || subaction));
          console.log(response.data.result); // optional debug
        } else {
          $('#cb-sync-status').text('Failed: ' + (response.data.message || 'Unknown error'));
        }
      },
      error: function(xhr) {
        $('#cb-sync-status').text('Error: ' + xhr.statusText);
      },
      complete: function() {
        // Re-enable button
        $btn.prop('disabled', false);
      }
    });
  });
});
