<?php
/**
 * Plugin Name: Affiliate Coupon Member
 * Description: Adds affiliate partners who can see coupon usage statistics for their assigned coupons.
 * Version: 1.5
 * Author: Muhammad Haris
  * Text Domain:       affiliate_coupon_member
  * Domain Path:       /languages
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * 1. Create Affiliate Partner role
 */
function acd_add_affiliate_role() {
    add_role(
        'affiliate_partner',
        'Affiliate Partner',
        array(
            'read' => true,
        )
    );
}
register_activation_hook(__FILE__, 'acd_add_affiliate_role');

/**
 * 2. Remove Affiliate Partner role on deactivation
 */
function acd_remove_affiliate_role() {
    if (get_role('affiliate_partner')) {
        remove_role('affiliate_partner');
    }
}
register_deactivation_hook(__FILE__, 'acd_remove_affiliate_role');

/**
 * 3. Add Affiliate dropdown to coupon editor
 */
function acd_add_affiliate_dropdown() {
    global $post;
    if ($post->post_type !== 'shop_coupon') {
        return;
    }

    $selected_user = get_post_meta($post->ID, '_affiliate_user_id', true);
    $users = get_users(array('role' => 'affiliate_partner'));

    echo '<div class="options_group">';
    echo '<p class="form-field"><label for="affiliate_user_id">Affiliate Partner</label>';
    echo '<select id="affiliate_user_id" name="affiliate_user_id">';
    echo '<option value="">— None —</option>';

    foreach ($users as $user) {
        printf(
            '<option value="%s" %s>%s (%s)</option>',
            esc_attr($user->ID),
            selected($selected_user, $user->ID, false),
            esc_html($user->display_name),
            esc_html($user->user_email)
        );
    }

    echo '</select></p></div>';
}
add_action('woocommerce_coupon_options', 'acd_add_affiliate_dropdown');

/**
 * 4. Save the affiliate user ID when coupon is saved
 */
function acd_save_affiliate_meta($post_id, $post) {
    if (isset($_POST['affiliate_user_id'])) {
        update_post_meta($post_id, '_affiliate_user_id', intval($_POST['affiliate_user_id']));
    }
}
add_action('save_post_shop_coupon', 'acd_save_affiliate_meta', 10, 2);

function acd_add_cod_check() {
    global $post;

    if ( $post->post_type !== 'shop_coupon' ) {
        return;
    }

    // Load saved value
    $cod_usage = get_post_meta( $post->ID, '_cod_usage', true );

    echo '<div class="options_group">';
    echo '<p class="form-field">
            <label for="cod_usage">Use Coupon on Cash on Delivery (COD): </label>
            <input type="checkbox" name="cod_usage" id="cod_usage" value="yes" ' . checked( $cod_usage, 'yes', false ) . ' />
          </p>';
    echo '</div>';
}
add_action( 'woocommerce_coupon_options', 'acd_add_cod_check' );


/**
 * Save COD Usage Option when coupon is saved
 */

function acd_save_cod_check( $post_id, $post ) {

    if ( $post->post_type !== 'shop_coupon' ) {
        return;
    }

    if ( isset( $_POST['cod_usage'] ) ) {
        update_post_meta( $post_id, '_cod_usage', 'yes' );
    } else {
        delete_post_meta( $post_id, '_cod_usage' );
    }
}
add_action( 'woocommerce_coupon_options_save', 'acd_save_cod_check', 10, 2 );

/**
 * Disable coupon usage on COD unless explicitly allowed.
 */
add_filter('woocommerce_coupon_is_valid', 'acd_disallow_coupon_on_cod', 10, 3);
function acd_disallow_coupon_on_cod( $valid, $coupon, $discount ) {

    // Get selected payment method
    $chosen_payment = WC()->session->get('chosen_payment_method');

    // If not COD — allow coupon
    if ($chosen_payment !== 'cod') {
        return $valid;
    }

    // Check the coupon setting
    $cod_allowed = get_post_meta( $coupon->get_id(), '_cod_usage', true );

    // If coupon is NOT allowed on COD → block it
    if ($cod_allowed !== 'yes') {
        wc_add_notice(
            __('This coupon cannot be used with Cash on Delivery.', 'woocommerce'),
            'error'
        );
        return false;
    }

    return $valid;
}

add_action('woocommerce_checkout_update_order_review', function() {
    wc()->cart->calculate_totals();
});

/**
 * Revalidate coupons immediately when payment method changes
 */
add_action('woocommerce_checkout_after_customer_details', function () {
    ?>
    <script type="text/javascript">
        jQuery(function($) {

            // Re-run validation when payment method changes
            $(document.body).on('change', 'input[name="payment_method"]', function() {
                $(document.body).trigger('update_checkout');
            });

            // When checkout is updated (after validation), check for errors and scroll
            $(document.body).on('checkout_error updated_checkout', function() {

                let $errorBox = $('.woocommerce-error');

                if ($errorBox.length) {
                    // Small delay ensures DOM is updated before scrolling
                    setTimeout(function() {
                        $('html, body').animate({
                            scrollTop: $errorBox.offset().top - 40
                        }, 400);
                    }, 200);
                }
            });

        });
    </script>
    <?php
});



/**
 * 5. Add “My Coupons” page for Affiliate Partners
 */
function acd_add_affiliate_menu() {
    if (current_user_can('affiliate_partner')) {
        add_menu_page(
            'My Coupons',
            'My Coupons',
            'read',
            'affiliate-coupons',
            'acd_affiliate_coupons_page',
            'dashicons-tickets-alt',
            6
        );
    }
}
add_action('admin_menu', 'acd_add_affiliate_menu');

/**
 * 6. Display coupons assigned to the logged-in affiliate
 */
function acd_affiliate_coupons_page() {
    $user_id = get_current_user_id();

    echo '<div class="wrap"><h1>My Coupons</h1>';

    // Filter input
    $search_code = isset($_GET['coupon_search']) ? sanitize_text_field($_GET['coupon_search']) : '';

    // Pagination
    $per_page = 10;
    $paged = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
    $offset = ($paged - 1) * $per_page;

    // Query coupons assigned to this affiliate
    $args = array(
        'post_type' => 'shop_coupon',
        'posts_per_page' => $per_page,
        'offset' => $offset,
        'meta_key' => '_affiliate_user_id',
        'meta_value' => $user_id,
        's' => $search_code,
    );
    $coupons = get_posts($args);

    // Total coupons for pagination
    $total_coupons = count(get_posts(array(
        'post_type' => 'shop_coupon',
        'meta_key' => '_affiliate_user_id',
        'meta_value' => $user_id,
        's' => $search_code,
        'fields' => 'ids',
        'posts_per_page' => -1,
    )));

    // Search form
    echo '<form method="get" style="margin-bottom:20px;">';
    echo '<input type="hidden" name="page" value="affiliate-coupons">';
    echo '<input type="text" name="coupon_search" value="' . esc_attr($search_code) . '" placeholder="Search by coupon code" style="width:200px;margin-right:10px;">';
    echo '<input type="submit" class="button" value="Filter">';
    echo '</form>';

    if (empty($coupons)) {
        echo '<p>No coupons found.</p></div>';
        return;
    }

    echo '<table class="widefat fixed striped">';
    echo '<thead><tr><th>Coupon Code</th><th>Usage Count</th><th>Total Discount</th><th>Total Sales</th></tr></thead><tbody>';

    foreach ($coupons as $coupon_post) {
        $coupon_code = $coupon_post->post_title;
        $coupon = new WC_Coupon($coupon_code);
        $usage_count = $coupon->get_usage_count();
        $total_discount = 0;
        $total_sales = 0;

        // Get all completed or processing orders
        $orders = wc_get_orders(array(
            'limit' => -1,
            'status' => array('wc-completed', 'wc-processing'),
        ));

        foreach ($orders as $order) {
        // Skip COD orders
            if ($order->get_payment_method() === 'cod') {
                continue;
            }
            $used_coupons = $order->get_coupon_codes();
            if (in_array($coupon_code, $used_coupons, true)) {
                $total_sales += floatval($order->get_total());

                // ✅ Robust discount calculation
                $discount_val = 0;
                if ($coupon->is_type('fixed_cart')) {
                    $discount_val = floatval($coupon->get_amount());
                } elseif ($coupon->is_type('percent')) {
                    $discount_val = floatval($order->get_subtotal()) * (floatval($coupon->get_amount()) / 100);
                }
                $total_discount += $discount_val;
            }
        }

        echo '<tr>';
        echo '<td>' . esc_html($coupon_code) . '</td>';
        echo '<td>' . esc_html($usage_count) . '</td>';
        echo '<td>' . wc_price($total_discount) . '</td>';
        echo '<td>' . wc_price($total_sales) . '</td>';
        echo '</tr>';
    }

    echo '</tbody></table>';

    // Pagination
    $total_pages = ceil($total_coupons / $per_page);
    if ($total_pages > 1) {
        $current_url = remove_query_arg('paged');
        echo '<div class="tablenav"><div class="tablenav-pages">';
        for ($i = 1; $i <= $total_pages; $i++) {
            if ($i == $paged) {
                echo '<span class="current">' . $i . '</span> ';
            } else {
                echo '<a href="' . esc_url(add_query_arg('paged', $i, $current_url)) . '">' . $i . '</a> ';
            }
        }
        echo '</div></div>';
    }

    echo '</div>';
}

/**
 * 7. Prevent WooCommerce from blocking affiliate admin access
 */
add_filter('woocommerce_prevent_admin_access', function($prevent_access) {
    if (current_user_can('affiliate_partner')) {
        return false;
    }
    return $prevent_access;
});

/**
 * 8. Redirect affiliate partner on login to Coupons dashboard
 */
add_filter('woocommerce_login_redirect', function($redirect, $user) {
    if (isset($user->roles) && in_array('affiliate_partner', $user->roles)) {
        return admin_url('edit.php?post_type=shop_coupon');
    }
    return $redirect;
}, 10, 2);
