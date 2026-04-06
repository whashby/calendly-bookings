<?php
namespace Calendly_Bookings\Admin\Views\Settings;

if (!defined('ABSPATH')) {
    exit;
}
?>

<form method="post" action="options.php">
  <?php settings_fields(CB_Constants::OPT_GROUP); ?>
  <?php do_settings_sections(CB_Constants::OPT_GROUP); ?>

  <h2>Email Template Customization</h2>
  <table class="form-table">
    <tr>
      <th scope="row"><label for="cb_email_header">Email Header HTML</label></th>
      <td><textarea id="cb_email_header" name="cb_email_header" rows="4" cols="60"><?php echo esc_textarea(get_option('cb_email_header')); ?></textarea></td>
    </tr>
    <tr>
      <th scope="row"><label for="cb_email_body">Email Body HTML</label></th>
      <td><textarea id="cb_email_body" name="cb_email_body" rows="8" cols="60"><?php echo esc_textarea(get_option('cb_email_body')); ?></textarea></td>
    </tr>
    <tr>
      <th scope="row"><label for="cb_email_footer">Email Footer HTML</label></th>
      <td><textarea id="cb_email_footer" name="cb_email_footer" rows="4" cols="60"><?php echo esc_textarea(get_option('cb_email_footer')); ?></textarea></td>
    </tr>
    <tr>
      <th scope="row">Recipients</th>
      <td>
        <label>To: <input type="text" name="cb_email_to" value="<?php echo esc_attr(get_option('cb_email_to')); ?>" /></label><br/>
        <label>From: <input type="text" name="cb_email_from" value="<?php echo esc_attr(get_option('cb_email_from')); ?>" /></label><br/>
        <label>Reply-To: <input type="text" name="cb_email_reply_to" value="<?php echo esc_attr(get_option('cb_email_reply_to')); ?>" /></label><br/>
        <label>BCC: <input type="text" name="cb_email_bcc" value="<?php echo esc_attr(get_option('cb_email_bcc')); ?>" /></label>
      </td>
    </tr>
  </table>

  <?php submit_button('Save Email Settings'); ?>
  <button type="button" class="button" id="cb-test-email">Send Test Email</button>
  <button type="button" class="button" id="cb-preview-email">Preview Email</button>
</form>
<div id="cb-email-preview"></div>
