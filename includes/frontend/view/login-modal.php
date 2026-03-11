<div id="cb-login-modal" style="display:none;">
  <div class="cb-login-box">
    <button type="button" class="cb-close">&times;</button>
    <h3><?php esc_html_e('Login Required', 'calendly-bookings'); ?></h3>
    <form id="cb-login-form" method="post">
      <p>
        <label for="user_login"><?php esc_html_e('Email', 'calendly-bookings'); ?></label>
        <input type="text" name="log" id="user_login" required>
      </p>
      <p>
        <label for="user_pass"><?php esc_html_e('Password', 'calendly-bookings'); ?></label>
        <input type="password" name="pwd" id="user_pass" required>
      </p>
      <p>
        <a href="<?php echo wp_lostpassword_url(); ?>">
            <?php esc_html_e('Forgot your password?', 'calendly-bookings'); ?>
        </a>
      </p>

      <p>
        <button type="submit" class="button button-primary">
          <?php esc_html_e('Login', 'calendly-bookings'); ?>
        </button>
      </p>
    </form>
  </div>
</div>
