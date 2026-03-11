<?php 
if ( $_GET['ref'] ):
    $ref = ucwords(implode(' ', explode('-', base64_decode($_GET['ref']))));
?>
<div class="cb-upsell">
  <p><?php esc_html_e($ref.'s are not available again. We recommend booking a Spiritual Companionship session instead.', 'calendly-bookings'); ?></p>
</div>
<?php endif; ?>