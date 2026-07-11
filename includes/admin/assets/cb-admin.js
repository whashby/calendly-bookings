jQuery(document).ready(function($) {

  /**
   * Bulk select all checkboxes
   */
  $(document).on('change', '.cb-bulk-select-all', function() {
    const checked = $(this).is(':checked');
    $('.cb-bulk-select').prop('checked', checked);
  });

  /**
   * Admin notices auto-dismiss
   */
  setTimeout(function() {
    $('#cb-admin-notices .notice').fadeOut();
  }, 5000);


  // Handle dismissal of Calendly Bookings update notice
  $(document).on('click', '.cb-update-notice .notice-dismiss', function() {
    $.post(ajaxurl, {
      action: 'cb_dismiss_update_notice',
      nonce: cb_admin.nonce // ensure you localize this nonce in your PHP
    }, function(response) {
      if (response.success) {
        console.log('Update notice dismissed successfully.');
      } else {
        console.warn('Failed to dismiss update notice:', response.data);
      }
    });
  });
});
