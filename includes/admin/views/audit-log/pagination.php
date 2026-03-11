<?php if ($total > $per_page): ?>
  <div class="tablenav">
    <div class="tablenav-pages">
      <?php
      echo paginate_links([
        'base'      => add_query_arg('paged', '%#%'),
        'format'    => '',
        'current'   => $page,
        'total'     => ceil($total / $per_page),
        'add_args'  => $filters,
        'prev_text' => __('« Prev', 'calendly-bookings'),
        'next_text' => __('Next »', 'calendly-bookings'),
      ]);
      ?>
    </div>
  </div>
<?php endif; ?>
