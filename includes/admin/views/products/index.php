<?php
namespace Calendly_Bookings\Admin\Views;

if (!defined('ABSPATH')) exit;

global $wpdb;

// Handle unlink action
if (!empty($_GET['cb_unlink'])) {
    $uuid = sanitize_text_field($_GET['cb_unlink']);
    $wpdb->update(
        "{$wpdb->prefix}cb_event_types",
        ['product_id' => null],
        ['uuid' => $uuid],
        ['%d'],
        ['%s']
    );
    add_action('admin_notices', function() {
        echo '<div class="notice notice-success"><p>' . esc_html__('Product unlinked successfully.', 'calendly-bookings') . '</p></div>';
    });
}

// Fetch Calendly event types
$event_types = $wpdb->get_results("
    SELECT uuid, name, duration, description_html AS description, product_id
    FROM {$wpdb->prefix}cb_event_types
    ORDER BY name ASC
");

// Fetch linked products
$linked = $wpdb->get_results("
    SELECT et.uuid, p.id, p.post_title
    FROM {$wpdb->prefix}cb_event_types et
    LEFT JOIN {$wpdb->prefix}posts p ON et.product_id = p.ID
    WHERE p.post_type = 'product'
");

$linked_map = [];
foreach ($linked as $row) {
    $linked_map[$row->uuid] = $row;
}

// Helpers
function cb_format_duration($minutes) {
    $minutes = intval($minutes);
    if ($minutes < 60) {
        return $minutes . 'min';
    }
    $hours = floor($minutes / 60);
    $mins  = $minutes % 60;
    return $hours . 'hr' . ($mins ? ' ' . $mins . 'min' : '');
}

function cb_shorten_description($desc, $maxLen = 600) {
    $clean = wp_strip_all_tags($desc);
    if (strlen($clean) > $maxLen) {
        return mb_substr($clean, 0, $maxLen) . '…';
    }
    return $clean;
}
?>

<div class="wrap">
  <h1><?php esc_html_e('WooCommerce Integration', 'calendly-bookings'); ?></h1>
  <p><?php esc_html_e('Create WooCommerce products from Calendly event types, link existing products, or unlink/delete associations.', 'calendly-bookings'); ?></p>

  <table class="widefat striped">
    <thead>
      <tr>
        <th><?php esc_html_e('Event Name', 'calendly-bookings'); ?></th>
        <th><?php esc_html_e('Duration', 'calendly-bookings'); ?></th>
        <th width="400"><?php esc_html_e('Description', 'calendly-bookings'); ?></th>
        <th><?php esc_html_e('Linked Product', 'calendly-bookings'); ?></th>
        <th><?php esc_html_e('Actions', 'calendly-bookings'); ?></th>
      </tr>
    </thead>
    <tbody>
      <?php if ($event_types): ?>
        <?php foreach ($event_types as $et): ?>
          <tr>
            <td><?php echo esc_html($et->name); ?></td>
            <td><?php echo esc_html(cb_format_duration($et->duration)); ?></td>
            <td><?php echo esc_html(cb_shorten_description($et->description)); ?></td>
            <td>
              <?php if (!empty($linked_map[$et->uuid])): ?>
                <?php echo esc_html($linked_map[$et->uuid]->post_title); ?>
              <?php else: ?>
                <em><?php esc_html_e('Not linked', 'calendly-bookings'); ?></em>
              <?php endif; ?>
            </td>
            <td>
              <?php if (!empty($linked_map[$et->uuid])): ?>
                <a class="button " data-uuid="<?php echo esc_attr($et->uuid); ?>" href="post.php?post=<?php echo esc_attr($et->product_id); ?>&action=edit&classic-editor=1">
                  <?php esc_html_e('Manage Product', 'calendly-bookings'); ?>
                </a>
                <a href="<?php echo esc_url(add_query_arg(['cb_unlink' => $et->uuid])); ?>" class="button cb-unlink">
                  <?php esc_html_e('Unlink', 'calendly-bookings'); ?>
                </a>
              <?php else: ?>
                <button class="button button-primary cb-create-btn"
                        data-uuid="<?php echo esc_attr($et->uuid); ?>"
                        data-name="<?php echo esc_attr($et->name); ?>">
                  <?php esc_html_e('Create Product', 'calendly-bookings'); ?>
                </button>
                <button class="button cb-link-btn" data-uuid="<?php echo esc_attr($et->uuid); ?>">
                  <?php esc_html_e('Link Existing', 'calendly-bookings'); ?>
                </button>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      <?php else: ?>
        <tr><td colspan="5"><?php esc_html_e('No event types found.', 'calendly-bookings'); ?></td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<!-- Modals -->
<div id="cb-create-modal" style="display:none;">
  <div class="cb-modal-content">
    <h2><?php esc_html_e('Create WooCommerce Product', 'calendly-bookings'); ?></h2>
    <form id="cb-create-form">
      <input type="hidden" name="event_uuid" id="cb-create-event-uuid" />
      <p>
        <label><?php esc_html_e('Product Name', 'calendly-bookings'); ?></label><br/>
        <input type="text" name="product_name" id="cb-create-product-name" class="regular-text" />
      </p>
      <p>
        <label><?php esc_html_e('Price', 'calendly-bookings'); ?></label><br/>
        <input type="text" name="product_price" id="cb-create-product-price" />
      </p>
      <p>
        <button type="submit" class="button button-primary"><?php esc_html_e('Create', 'calendly-bookings'); ?></button>
      </p>
    </form>
  </div>
</div>

<div id="cb-link-modal" style="display:none;">
  <div class="cb-modal-content">
    <h2><?php esc_html_e('Link Existing Product', 'calendly-bookings'); ?></h2>
    <form id="cb-link-form">
      <input type="hidden" name="uuid" id="cb-link-event-uuid" />
      <p>
        <label><?php esc_html_e('Select Product', 'calendly-bookings'); ?></label><br/>
        <?php
        $products = $wpdb->get_results("SELECT ID, post_title FROM {$wpdb->prefix}posts WHERE post_type='product' AND post_status='publish'");
        echo '<select name="product_id" id="cb-link-product-id">';
        foreach ($products as $p) {
            echo '<option value="' . esc_attr($p->ID) . '">' . esc_html($p->post_title) . '</option>';
        }
        echo '</select>';
        ?>
      </p>
      <p>
        <button type="submit" class="button button-primary"><?php esc_html_e('Link', 'calendly-bookings'); ?></button>
      </p>
    </form>
  </div>
</div>

<div id="cb-manage-modal" style="display:none;">
  <div class="cb-modal-content">
    <h2><?php esc_html_e('Manage Linked Product', 'calendly-bookings'); ?></h2>
    <p><?php esc_html_e('Use WooCommerce product editor for advanced management.', 'calendly-bookings'); ?></p>
    <p>
      <a href="<?php echo esc_url(admin_url('edit.php?post_type=product')); ?>" class="button button-primary">
        <?php esc_html_e('Open Product List', 'calendly-bookings'); ?>
      </a>
    </p>
  </div>
</div>
