<?php
namespace Calendly_Bookings\Admin\Views;

if (!defined('ABSPATH')) exit;

$current_user = wp_get_current_user();
$user_email = $current_user->user_email;
$user_roles = $current_user->roles;

if(in_array('administrator', $user_roles)) :   

?>

<div class="wrap">
  <h1><?php esc_html_e('Calendly Bookings', 'calendly-bookings'); ?></h1>
  <p><?php esc_html_e('Manage your Calendly integration, view scheduled events, audit logs, configure settings, and link event types to WooCommerce products.', 'calendly-bookings'); ?></p>

  <div id="cb-admin-notices"></div>

  <div id="cb-dashboard">
    <h2><?php esc_html_e('Overview', 'calendly-bookings'); ?></h2>
    <p><?php esc_html_e('This dashboard provides quick access to your Calendly data and plugin configuration.', 'calendly-bookings'); ?></p>

    <div class="cb-dashboard-cards">
      <div class="cb-card">
<?php   if(in_array( $user_email, ['whashby@gmail.com', 'michael@hierlife.com'])) : 
           if(in_array( $user_email, [/*'whashby@gmail.com',*/ 'michael@hierlife.com'])) : ?>
        <h3><?php esc_html_e('Scheduled Events', 'calendly-bookings'); ?></h3>
        <p><?php esc_html_e('View and manage upcoming and past scheduled events synced from Calendly.', 'calendly-bookings'); ?></p>
        <a href="<?php echo esc_url(admin_url('admin.php?page=calendly-bookings-scheduled-events')); ?>" class="button button-primary">
          <?php esc_html_e('View Events', 'calendly-bookings'); ?>
        </a>
      </div>
<?php       endif; 
            if(in_array( $user_email, ['whashby@gmail.com',])) :?>
      <div class="cb-card">
        <h3><?php esc_html_e('Audit Log', 'calendly-bookings'); ?></h3>
        <p><?php esc_html_e('Review API calls and actions recorded by the system.', 'calendly-bookings'); ?></p>
        <a href="<?php echo esc_url(admin_url('admin.php?page=calendly-bookings-audit-log')); ?>" class="button">
          <?php esc_html_e('View Audit Log', 'calendly-bookings'); ?>
        </a>
      </div>
        <?php endif; ?>

      <div class="cb-card">
        <h3><?php esc_html_e('Settings', 'calendly-bookings'); ?></h3>
        <p><?php esc_html_e('Configure API credentials, sync options, and plugin preferences.', 'calendly-bookings'); ?></p>
        <a href="<?php echo esc_url(admin_url('admin.php?page=calendly-bookings-settings')); ?>" class="button">
          <?php esc_html_e('Configure Settings', 'calendly-bookings'); ?>
        </a>
      </div>
<?php endif; ?>

      <div class="cb-card">
        <h3><?php esc_html_e('WooCommerce Integration', 'calendly-bookings'); ?></h3>
        <p><?php esc_html_e('Create WooCommerce products from Calendly event types, link existing products, or unlink/delete associations.', 'calendly-bookings'); ?></p>
        <a href="<?php echo esc_url(admin_url('admin.php?page=calendly-bookings-products')); ?>" class="button">
          <?php esc_html_e('Manage Products', 'calendly-bookings'); ?>
        </a>
      </div>
    </div>
  </div>
</div>
<?php endif;