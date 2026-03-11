<?php
if (!defined('ABSPATH')) exit;

$total_pages = ceil($total / $limit);

if ($total_pages > 1): ?>
<div class="tablenav">
    <div class="tablenav-info">
        <?php
        $start = (($page - 1) * $limit) + 1;
        $end   = min($page * $limit, $total);
        printf(
            __('Showing %d–%d of %d events', 'calendly-bookings'),
            $start,
            $end,
            $total
        );
        ?>
    </div>
    <div class="tablenav-pages">
        <?php
        echo paginate_links([
            'base'      => add_query_arg('paged', '%#%'),
            'format'    => '',
            'current'   => $page,
            'total'     => $total_pages,
            'add_args'  => [
                's'         => $filters['s'] ?? '',
                'status'    => $filters['status'] ?? '',
                'start_date'=> $filters['start_date'] ?? '',
                'end_date'  => $filters['end_date'] ?? '',
                'orderby'   => $orderby ?? 'start_time',
                'order'     => $order ?? 'ASC',
            ],
            'prev_text' => __('« Prev', 'calendly-bookings'),
            'next_text' => __('Next »', 'calendly-bookings'),
        ]);
        ?>
    </div>

</div>
<?php endif; ?>
