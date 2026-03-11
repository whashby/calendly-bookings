<form method="get" class="cb-filters">
  <input type="hidden" name="page" value="calendly-bookings-scheduled-events" />
  <input type="text" id="filter-name" name="name" value="<?php echo esc_attr($filters['name'] ?? ''); ?>" 
         placeholder="<?php esc_attr_e('Search invitee or event name', 'calendly-bookings'); ?>" />
    <button type="button" id="clear-name" class="button">X</button>

  <select name="status">
    <option value=""><?php esc_html_e('All Statuses', 'calendly-bookings'); ?></option>
    <?php foreach (['active','canceled','completed'] as $status): ?>
      <option value="<?php echo esc_attr($status); ?>" <?php selected($filters['status'] ?? '', $status); ?>>
        <?php echo ucfirst($status); ?>
      </option>
    <?php endforeach; ?>
  </select>

  <input type="date" name="start_date" value="<?php echo esc_attr($filters['start_date'] ?? ''); ?>" />
  <input type="date" name="end_date" value="<?php echo esc_attr($filters['end_date'] ?? ''); ?>" />

  <button class="button"><?php esc_html_e('Filter', 'calendly-bookings'); ?></button>
  <div>
      <p>
          <button type="button" class="button cb-bulk-update"><?php esc_html_e('Bulk Update Status', 'calendly-bookings'); ?></button> | 
          <button type="button" class="button button-primary" id="cb-create-walkin"><?php esc_html_e('Create Walk-in', 'calendly-bookings'); ?></button>
          </p>
  </div>
</form>
