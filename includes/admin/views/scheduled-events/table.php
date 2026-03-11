<table class="widefat fixed striped">
  <thead>
    <tr>
      <th width="20" align="center"><input type="checkbox" class="cb-bulk-select-all"></th>
      <th><?php esc_html_e('Date/Time', 'calendly-bookings'); ?></th>
      <th><?php esc_html_e('Invitee', 'calendly-bookings'); ?></th>
      <th><?php esc_html_e('Event Name', 'calendly-bookings'); ?></th>
      <th><?php esc_html_e('Location', 'calendly-bookings'); ?></th>
      <!--<th><?php esc_html_e('Order #', 'calendly-bookings'); ?></th>-->
      <th><?php esc_html_e('Status', 'calendly-bookings'); ?></th>
      <th><?php esc_html_e('Actions', 'calendly-bookings'); ?></th>
    </tr>
  </thead>
  <tbody>
    <?php if (!empty($events)) : ?>
      <?php foreach ($events as $event) : ?>
        <?php 
          $local = get_date_from_gmt(
              $event['start_time'], 
              get_option('date_format') . ' ' . get_option('time_format')
          );
          $invitee_name = $event['invitee_name'] ? implode('_', explode(' ', $event['invitee_name'])) : '';
        ?>
        <tr data-uuid="<?php echo esc_attr($event['uuid']); ?>">
          <td align="center"><input type="checkbox" class="cb-bulk-select" value="<?php echo esc_attr($event['uuid']); ?>"></td>
          <td><?php echo esc_html($local); ?></td>
          <td>
            <?php if (!empty($event['invitee_email'])) : ?>
              <a href="#"
                 class="cb-view-history"
                 data-invitee="<?php echo esc_attr($invitee_name); ?>" data-uuid="<?php echo esc_attr($event['uuid']); ?>">
                 <?php echo esc_html_e($event['invitee_name']); ?>
              </a>
            <?php else : ?>
              <?php esc_html_e('(Invitee not listed)', 'calendly-bookings'); ?>
            <?php endif; ?>
          </td>
          <td><?php echo esc_html($event['event_name']); ?></td>
          <td><?php echo esc_html($event['location']); ?></td>
          <!--<td><?php echo esc_html($event['order_id']); ?></td>-->
          <td>
              <?php echo esc_html($event['status']); ?>

          </td>
          <td>
            <?php if (in_array($event['status'],['completed','active'])):?>
            <a href="#"
               class="button cb-view-record"
               data-uuid="<?php echo esc_attr($event['uuid']); ?>">
               <?php esc_html_e('View', 'calendly-bookings'); ?>
            </a>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    <?php else : ?>
      <tr><td colspan="7"><?php esc_html_e('No scheduled events found.', 'calendly-bookings'); ?></td></tr>
    <?php endif; ?>
  </tbody>
</table>
