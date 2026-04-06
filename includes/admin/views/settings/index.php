<?php
namespace Calendly_Bookings\Admin\Views\Settings;

if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="wrap">
  <h1>Calendly Bookings Settings</h1>
  <h2 class="nav-tab-wrapper">
    <a href="?page=calendly-bookings-settings&tab=credentials" class="nav-tab <?php echo $active_tab == 'credentials' ? 'nav-tab-active' : ''; ?>">Credentials</a>
    <a href="?page=calendly-bookings-settings&tab=sync" class="nav-tab <?php echo $active_tab == 'sync' ? 'nav-tab-active' : ''; ?>">Sync</a>
    <a href="?page=calendly-bookings-settings&tab=email" class="nav-tab <?php echo $active_tab == 'email' ? 'nav-tab-active' : ''; ?>">Email</a>
    <a href="?page=calendly-bookings-settings&tab=reports" class="nav-tab <?php echo $active_tab == 'reports' ? 'nav-tab-active' : ''; ?>">Reports</a>
  </h2>

  <div class="tab-content">
    <?php
      switch ($active_tab = $_GET['tab'] ?? 'credentials') {
        case 'sync': include_once __DIR__ . '/sync.php'; break;
        case 'email': include_once __DIR__ . '/email.php'; break;
        case 'reports': include_once __DIR__ . '/reports.php'; break;
        default: include_once __DIR__ . '/credentials.php'; break;
      }
    ?>
  </div>
</div>
