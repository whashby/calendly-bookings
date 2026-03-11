<form id="cb-audit-filters" method="get">
  <input type="hidden" name="page" value="calendly-bookings-audit-log" />

  <input type="text" name="s" value="<?php echo esc_attr($filters['search'] ?? ''); ?>"
         placeholder="<?php esc_attr_e('Search identifier or details', 'calendly-bookings'); ?>" />

  <select name="level">
    <option value=""><?php esc_html_e('All Levels', 'calendly-bookings'); ?></option>
    <?php foreach (['info','warning','error'] as $lvl): ?>
      <option value="<?php echo esc_attr($lvl); ?>" <?php selected($filters['level'] ?? '', $lvl); ?>>
        <?php echo esc_html(ucfirst($lvl)); ?>
      </option>
    <?php endforeach; ?>
  </select>

  <select name="action">
    <option value=""><?php esc_html_e('All Actions', 'calendly-bookings'); ?></option>
    <?php foreach (\Calendly_Bookings\Modules\CB_Audit_Log::get_distinct('action') as $act): ?>
      <option value="<?php echo esc_attr($act); ?>" <?php selected($filters['action'] ?? '', $act); ?>>
        <?php echo esc_html($act); ?>
      </option>
    <?php endforeach; ?>
  </select>

  <select name="context">
    <option value=""><?php esc_html_e('All Contexts', 'calendly-bookings'); ?></option>
    <?php foreach (\Calendly_Bookings\Modules\CB_Audit_Log::get_distinct('context') as $ctx): ?>
      <option value="<?php echo esc_attr($ctx); ?>" <?php selected($filters['context'] ?? '', $ctx); ?>>
        <?php echo esc_html(ucfirst(str_replace('_',' ',$ctx))); ?>
      </option>
    <?php endforeach; ?>
  </select>

  <label for="cb-filter-date"><?php esc_html_e('Date', 'calendly-bookings'); ?></label>
  <input type="date" id="cb-filter-date" name="date" value="<?php echo esc_attr($filters['date'] ?? ''); ?>" />

  <button class="button"><?php esc_html_e('Filter', 'calendly-bookings'); ?></button>
</form>

<hr>
