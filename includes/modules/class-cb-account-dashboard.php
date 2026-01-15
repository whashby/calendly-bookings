<?php
// includes/modules/cb-account-dashboard.php
namespace Calendly_Bookings\Modules;

use Calendly_Bookings\Modules\CB_API;

class CB_Account_Dashboard {

    public static function init() {
        // Render cards on WooCommerce My Account dashboard
        add_action('woocommerce_account_dashboard', [__CLASS__, 'render_cards'], 1);

        add_action('woocommerce_account_navigation', [__CLASS__, 'hide_woocommerce_account_navigation'], 1, 0);

        // Styles for cards (replace with proper stylesheet if preferred)
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_styles']);
    }

    public static function enqueue_styles() {
        $css = '
.woocommerce-notices-wrapper > :nth-child(2) {
  display: none !important;
}
.hier-cards {
  display: grid;
  gap: 24px;
  grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
  margin-top: 24px;
}

.hier-card {
  background: #fff;
  border: 1px solid #ddd;
  border-radius: 8px;
  box-shadow: 0 2px 6px rgba(0,0,0,0.05);
  overflow: hidden;
  display: flex;
  flex-direction: column;
  max-height: 650px;
}

.hier-card__header {
  padding: 16px;
  background: #f9f9f9;
  border-bottom: 1px solid #eee;
  font-weight: 600;
  font-size: 16px;
}

.hier-card__body {
  padding: 16px;
  flex-grow: 1;
}

.hier-card__body div.list{
  max-height:350px;
  overflow: auto;
  overflow-y: scroll;
}
.hier-card__footer {
  padding: 16px;
  border-top: 1px solid #eee;
  text-align: right;
  background: #fafafa;
}

.hier-card__footer a.button {
  text-decoration: none;
  font-weight: 500;
}

.hier-card__list {
  list-style: none;
  margin: 0;
  padding: 0;
}

.hier-card__list li {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 10px 0;
  border-bottom: 1px dashed #eee;
}

.hier-card__list li:last-child {
  border-bottom: none;
}

.hier-card__meta {
  font-size: 12px;
  color: #666;
  margin-top: 4px;
}

.hier-card__actions {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
  margin-top: 8px;
}

.cb-avatar {
  width: 48px;
  height: 48px;
  border-radius: 50%;
  object-fit: cover;
}

.cb-profile {
  display: grid;
  grid-template-columns: 56px 1fr;
  gap: 12px;
  align-items: center;
}
.hier-card__actions {
  display: flex;
  gap: 8px;
  justify-content: flex-end;
  flex-wrap: wrap;
}

        ';
        wp_register_style('hier-my-account-cards', false);
        wp_enqueue_style('hier-my-account-cards');
        wp_add_inline_style('hier-my-account-cards', $css);
    }

    public static function render_cards() {
        if (!is_user_logged_in()) {
            return;
        }

        $user_id = get_current_user_id();
        $user    = wp_get_current_user();
        $cb_api  = new CB_API();

        // Data sources
        $upcoming = $cb_api->get_scheduled_events(['status' => 'active'], 'my-account', 10);
        $past     = $cb_api->get_scheduled_events(['status' => 'completed'], 'my-account', 10);
        $orders   = self::get_recent_orders($user_id, 5);
		$has_calendly_product = self::user_has_calendly_product($user_id);

        echo '<div class="hier-cards" aria-label="My account dashboard">';

        // Feature 1: Profile Snapshot
		echo self::card(
			'Profile',
			self::profile_snapshot($user),
			[
				[
					'url'   => wc_get_account_endpoint_url('edit-account'),
					'label' => 'Edit Profile',
				],
				[
					'url'   => wc_get_account_endpoint_url('edit-address'),
					'label' => 'Manage Addresses',
				],
			]
		);


        // Feature 2: Upcoming Bookings
        echo self::card(
            'Upcoming bookings',
            self::upcoming_bookings_list($upcoming),
			[
				[
					'url'   => wc_get_account_endpoint_url('orders'),
					'label' => 'View orders',
				],
			],
        );

        // Feature 3: Available services
		echo self::card(
			'Available Services',
			self::render_product_list($has_calendly_product),
			[
				[
					'url'   => wc_get_page_permalink('shop'),
					'label' => 'View All Products',
				],
			],
		);

        // Feature 4: Past Bookings / History
		echo self::card(
            'Past bookings',
            self::past_bookings_table($past),
			[
				[
				'url'   => wc_get_account_endpoint_url('orders'),
            	'label' => 'View orders',
				],
			]
        );

        // Feature 5: Payments & Orders
        echo self::card(
            'Payments & orders',
            self::orders_table($orders),
			[
				[
					'url'   => wc_get_account_endpoint_url('orders'), 
					'label' => 'Manage orders'
				],
			]
        );

        echo '</div>';
    }

    /* ---------------------------
     * Card rendering helpers
     * --------------------------- */

	private static function card(string $title, string $body_html, array $footer_buttons = []): string {
		$footer_html = '';
		if (!empty($footer_buttons)) {
			$footer_html .= '<div class="hier-card__footer"><div class="hier-card__actions">';
			foreach ($footer_buttons as $btn) {
				$footer_html .= sprintf(
					'<a class="button" href="%s">%s</a>',
					esc_url($btn['url']),
					esc_html($btn['label'])
				);
			}
			$footer_html .= '</div></div>';
		}

		return sprintf(
			'<section class="hier-card" aria-labelledby="%1$s-heading">
				<div id="%1$s-heading" class="hier-card__header">%2$s</div>
				<div class="hier-card__body">%3$s</div>
				%4$s
			</section>',
			esc_attr(sanitize_title($title)),
			esc_html($title),
			$body_html,
			$footer_html
		);
	}

    private static function list_items(array $items): string {
        if (empty($items)) {
            return '<p class="hier-card__meta">No items found.</p>';
        }
        $html = '<ul class="hier-card__list" role="list">';
        foreach ($items as $item) {
            $label = $item['label'] ?? '';
            $value = $item['value'] ?? '';
            $meta  = $item['meta'] ?? '';
            $html .= sprintf(
                '<li>
                    <span>%1$s</span>
                    <span>%2$s</span>
                    %3$s
                </li>',
                esc_html($label),
                esc_html($value),
                !empty($meta) ? '<div class="hier-card__meta">'.esc_html($meta).'</div>' : ''
            );
        }
        $html .= '</ul>';
        return $html;
    }

    /* ---------------------------
     * Feature 1: Profile Snapshot
     * --------------------------- */
private static function profile_snapshot(\WP_User $user): string {
    $avatar = get_avatar_url($user->ID);
    $name   = $user->display_name ?: $user->user_login;
    $email  = $user->user_email;

    // Build billing and shipping addresses
    $billing_address = wc()->countries->get_formatted_address(get_user_meta($user->ID, 'billing_address_1', true) ? [
        'first_name' => get_user_meta($user->ID, 'billing_first_name', true),
        'last_name'  => get_user_meta($user->ID, 'billing_last_name', true),
        'company'    => get_user_meta($user->ID, 'billing_company', true),
        'address_1'  => get_user_meta($user->ID, 'billing_address_1', true),
        'address_2'  => get_user_meta($user->ID, 'billing_address_2', true),
        'city'       => get_user_meta($user->ID, 'billing_city', true),
        'state'      => get_user_meta($user->ID, 'billing_state', true),
        'postcode'   => get_user_meta($user->ID, 'billing_postcode', true),
        'country'    => get_user_meta($user->ID, 'billing_country', true),
    ] : []);

    $shipping_address = wc()->countries->get_formatted_address(get_user_meta($user->ID, 'shipping_address_1', true) ? [
        'first_name' => get_user_meta($user->ID, 'shipping_first_name', true),
        'last_name'  => get_user_meta($user->ID, 'shipping_last_name', true),
        'company'    => get_user_meta($user->ID, 'shipping_company', true),
        'address_1'  => get_user_meta($user->ID, 'shipping_address_1', true),
        'address_2'  => get_user_meta($user->ID, 'shipping_address_2', true),
        'city'       => get_user_meta($user->ID, 'shipping_city', true),
        'state'      => get_user_meta($user->ID, 'shipping_state', true),
        'postcode'   => get_user_meta($user->ID, 'shipping_postcode', true),
        'country'    => get_user_meta($user->ID, 'shipping_country', true),
    ] : []);

    // Render profile + addresses
    $html = sprintf(
        '<div class="cb-profile" aria-label="Profile snapshot">
            <img src="%1$s" alt="%2$s avatar" class="cb-avatar" />
            <div>
                <div><strong>%2$s</strong></div>
                <div class="hier-card__meta">%3$s</div>
            </div>
        </div>',
        esc_url($avatar),
        esc_html($name),
        esc_html($email)
    );

    $html .= '<div class="hier-card__row" style="margin-top:12px">';
	$html .= '<hr />';
    $html .= '<div><strong>Billing Address</strong><div class="hier-card__meta">'.(!empty($billing_address) ? wp_kses_post($billing_address) : 'Not set').'</div></div>';
    $html .= '<div><strong>Shipping Address</strong><div class="hier-card__meta">'.(!empty($shipping_address) ? wp_kses_post($shipping_address) : 'Not set').'</div></div>';
    $html .= '</div>';

    return $html;
}

    /* ---------------------------
     * Feature 2: Upcoming Bookings
     * --------------------------- */
    private static function upcoming_bookings_list(array $events): string {
        if (empty($events)) {
            return '<p class="hier-card__meta">No upcoming bookings.</p>';
        }

        $items = array_map(function($ev) {
            $label = $ev['event_name'] ?? 'Booking';
            $start = !empty($ev['start_time']) ? self::format_site_time($ev['start_time']) : '';
            $loc   = $ev['location'] ?? '—';

            $actions = [];
            if (!empty($ev['reschedule_url'])) {
                $actions[] = '<a class="button" href="'.esc_url($ev['reschedule_url']).'">Reschedule</a>';
            }
            if (!empty($ev['cancel_url'])) {
                $actions[] = '<a class="button" href="'.esc_url($ev['cancel_url']).'">Cancel</a>';
            }

            $meta = 'Location: '.$loc;
            if (!empty($actions)) {
                $meta .= ' | <span class="hier-card__actions">'.implode(' ', $actions).'</span>';
            }

            return [
                'label' => $label,
                'value' => $start,
                'meta'  => wp_kses_post($meta),
            ];
        }, $events);

        return self::list_items($items);
    }

	/* ---------------------------
     * Feature 3: Available Products
     * --------------------------- */
	private static function render_product_list(bool $has_calendly_product = false): string {
		
		$args = [
			'limit' => -1,
			'category' => array('meeting','meetings'),
			'status' => 'publish',
			'return' => 'objects',
		];


		$initial_session_id       = '';
		$spiritual_companionship_id = '';
		$other_ids = [];
		// Build product list
		$products = wc_get_products($args);
		
		foreach($products as $product) {
			if( $product->get_name() == "Initial meeting") {
				$initial_session_id = $product->get_id();
			}
			else if( $product->get_name() == "Spiritual Companionship")
				$spiritual_companionship_id = $product->get_id();
			else $other_ids[] .=  $product->get_id();
		}


		$products = [];
			// Hide initial session, show spiritual companionship first
			if ($has_calendly_product) {
				$products[] = wc_get_product($spiritual_companionship_id);
			} else {
			// Show initial session first, hide spiritual companionship
				$products[] = wc_get_product($initial_session_id);
			}
		

		// Add other products
		foreach ($other_ids as $id) {
			$products[] = wc_get_product($id);
		}

		if (empty($products)) {
			return '<p class="hier-card__meta">No products available.</p>';
		}
		$html = '<div class="list"><div class="hier-card__list">
		';
		foreach ($products as $product) {
			if (!$product) continue;

			$img   = $product->get_image('thumbnail');
			$name  = $product->get_name();
			$price = $product->get_price_html();
			$url   = get_permalink($product->get_id());

			$html .= sprintf(
				'<div class="cb-profile" style="margin-bottom:12px">
					<div style="width:56px">%s</div>
					<div>
						<div><a href="%s"><strong>%s</strong></a></div>
						<div class="hier-card__meta">%s</div>
					</div>
				</div>',
				$img,
				esc_url($url),
				esc_html($name),
				wp_kses_post($price)
			);
		}
		$html .= '</div></div>';
		return $html;
	}

	/* ---------------------------
     * Feature 4: Past Bookings
     * --------------------------- */
    private static function past_bookings_table(array $events): string {
        if (empty($events)) {
            return '<p class="hier-card__meta">No past bookings found.</p>';
        }

        $rows = '';
        foreach ($events as $ev) {
            $rows .= sprintf(
                '<tr>
                    <td>%1$s</td>
                    <td>%2$s</td>
                    <td>%3$s</td>
                </tr>',
                esc_html($ev['event_name'] ?? 'Booking'),
                esc_html(!empty($ev['start_time']) ? self::format_site_time($ev['start_time']) : ''),
                esc_html(ucfirst($ev['status'] ?? 'completed'))
            );
        }

        return '
            <div class="wc-table-responsive">
                <table class="shop_table shop_table_responsive cb-table" aria-label="Past bookings">
                    <thead>
                        <tr><th>Event</th><th>Date</th><th>Status</th></tr>
                    </thead>
                    <tbody>'.$rows.'</tbody>
                </table>
            </div>
        ';
    }

    /* ---------------------------
     * Feature 5: Payments & Orders
     * --------------------------- */
    private static function orders_table(array $orders): string {
        if (empty($orders)) {
            return '<p class="hier-card__meta">No orders found.</p>';
        }

        $rows = '';
        foreach ($orders as $order) {
            $rows .= sprintf(
                '<tr>
                    <td>#%1$d</td>
                    <td>%2$s</td>
                    <td>%3$s</td>
                    <td><a class="button" href="%4$s">View</a></td>
                </tr>',
                (int) $order['id'],
                wp_kses_post($order['total']),
                esc_html($order['status']),
                esc_url(wc_get_endpoint_url('view-order', $order['id'], wc_get_page_permalink('myaccount')))
            );
        }

        return '
            <div class="wc-table-responsive">
                <table class="shop_table shop_table_responsive cb-table" aria-label="Payments and orders">
                    <thead>
                        <tr><th>Order ID</th><th>Total</th><th>Status</th><th>Invoice</th></tr>
                    </thead>
                    <tbody>'.$rows.'</tbody>
                </table>
            </div>
        ';
    }

    /* ---------------------------
     * Data helpers
     * --------------------------- */

    private static function get_recent_orders(int $user_id, int $limit = 5): array {
        $orders = wc_get_orders([
            'limit'       => $limit,
            'customer_id' => $user_id,
            'orderby'     => 'date',
            'order'       => 'DESC',
            'status'      => array_keys(wc_get_order_statuses()),
            'return'      => 'objects',
        ]);

        return array_map(function($order) {
            return [
                'id'     => $order->get_id(),
                'total'  => wc_price($order->get_total()),
                'status' => wc_get_order_status_name($order->get_status()),
                'date'   => $order->get_date_created()
                    ? $order->get_date_created()->date_i18n('d M Y H:i')
                    : '',
            ];
        }, $orders ?: []);
    }
	
private static function user_has_calendly_product(int $user_id): bool {
    $orders = wc_get_orders([
        'customer_id' => $user_id,
        'status'      => array_keys(wc_get_order_statuses()),
        'limit'       => -1,
        'return'      => 'ids',
    ]);

    foreach ($orders as $order_id) {
        $order = wc_get_order($order_id);
        foreach ($order->get_items() as $item) {
            $product_id = $item->get_product_id();
            // Check if product is Calendly-linked
            if ($item->get_name() == get_post_meta($product_id, '_cb_event_name', true)) {
                return true;
            }
        }
    }
    return false;
}

    private static function format_site_time(string $utc_datetime): string {
        // Convert UTC to site timezone for display
        $ts = strtotime($utc_datetime);
        if (!$ts) return '';
        return wp_date('M d, Y H:i', $ts);
    }

	public static function hide_woocommerce_account_navigation(): void {
		remove_action('woocommerce_account_navigation', 'woocommerce_account_navigation');
	}


}
