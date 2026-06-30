<?php
namespace Calendly_Bookings\Admin\Views;

if (!defined('ABSPATH')) exit;

$current_user = wp_get_current_user();
$user_roles = $current_user->roles;

// Check if user has CB-specific roles or is administrator
$has_cb_admin_role = in_array('cb_administrator', $user_roles, true);
$has_cb_support_role = in_array('cb_support', $user_roles, true);
$is_admin = in_array('administrator', $user_roles, true);

if($has_cb_admin_role || $has_cb_support_role || $is_admin) :
?>
<div class="wrap">
  <h1><?php esc_html_e('Calendly Bookings', 'calendly-bookings'); ?></h1>
  <p><?php esc_html_e('Manage your Calendly integration, view scheduled events, configure settings, and link event types to WooCommerce products.', 'calendly-bookings'); ?></p>

  <div id="cb-admin-notices"></div>

  <div id="cb-dashboard">
    <h2><?php esc_html_e('Overview', 'calendly-bookings'); ?></h2>
    <p><?php esc_html_e('This dashboard provides quick access to your Calendly data and plugin configuration.', 'calendly-bookings'); ?></p>

    <div class="cb-dashboard-cards">
      <div class="cb-card">
<?php   if($has_cb_admin_role || $has_cb_support_role || $is_admin) :
          if($has_cb_admin_role) : ?>
        <h3><?php esc_html_e('Scheduled Events', 'calendly-bookings'); ?></h3>
        <p><?php esc_html_e('View and manage upcoming and past scheduled events synced from Calendly.', 'calendly-bookings'); ?></p>
        <a href="<?php echo esc_url(admin_url('admin.php?page=calendly-bookings-scheduled-events')); ?>" class="button button-primary">
          <?php esc_html_e('View Events', 'calendly-bookings'); ?>
        </a>
      </div>
<?php       endif;?>


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