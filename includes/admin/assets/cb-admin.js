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

});
