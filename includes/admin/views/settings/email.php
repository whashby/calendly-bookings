<?php

namespace Calendly_Bookings\Admin\Views\Settings;

if (!defined('ABSPATH')) {
    exit;
}

use Calendly_Bookings\CB_Constants;
?>
<form method="post" action="options.php">
  <?php settings_fields(CB_Constants::OPT_GROUP); ?>
  <?php do_settings_sections(CB_Constants::OPT_GROUP); ?>

  <h2>Email Template Customization</h2>
  <table class="form-table">
    <tr>
      <th scope="row"><label for="cb_email_header">Email Header HTML</label></th>
      <td>
        <?php
        wp_editor(
          get_option('cb_email_header'),
          'cb_email_header',
          [
            'textarea_name' => 'cb_email_header',
            'textarea_rows' => 6,
            'media_buttons' => true,
            'tinymce'       => true,
            'quicktags'     => true,
          ]
        );
        ?>
      </td>
    </tr>
    <tr>
      <th scope="row"><label for="cb_email_body">Email Body HTML</label></th>
      <td>
        <?php
        wp_editor(
          get_option('cb_email_body'),
          'cb_email_body',
          [
            'textarea_name' => 'cb_email_body',
            'textarea_rows' => 12,
            'media_buttons' => true,
            'tinymce'       => true,
            'quicktags'     => true,
          ]
        );
        ?>
      </td>
    </tr>
    <tr>
      <th scope="row"><label for="cb_email_footer">Email Footer HTML</label></th>
      <td>
        <?php
        wp_editor(
          get_option('cb_email_footer'),
          'cb_email_footer',
          [
            'textarea_name' => 'cb_email_footer',
            'textarea_rows' => 6,
            'media_buttons' => true,
            'tinymce'       => true,
            'quicktags'     => true,
          ]
        );
        ?>
      </td>
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
