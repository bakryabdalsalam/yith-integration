<?php
/*
Plugin Name: WooCommerce Replacement Request
Description: Adds a replacement request feature to WooCommerce orders.
Version: 1.0
Author: Bakry Abdelsalam
*/

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Display the replacement request button on the order view page
function add_request_replacement_button_to_order($order) {
    if ($order->get_status() == 'completed' && !get_post_meta($order->get_id(), '_replacement_request_submitted', true)) {
        $completion_date = $order->get_date_completed();
        if ($completion_date && (time() - $completion_date->getTimestamp()) <= 7 * DAY_IN_SECONDS) {
            ?>
            <a href="#replacement-request-form" class="button"><?php _e('تقديم طلب استبدال', 'woocommerce'); ?></a>
            <?php
        }
    }
}
add_action('woocommerce_order_details_after_order_table', 'add_request_replacement_button_to_order');

// Display the replacement request form on the order view page
function display_replacement_request_form() {
    if (is_wc_endpoint_url('view-order')) {
        $order_id = absint(get_query_var('view-order'));
        $order = wc_get_order($order_id);
        if ($order->get_status() == 'completed' && (time() - $order->get_date_completed()->getTimestamp()) <= 7 * DAY_IN_SECONDS && !get_post_meta($order_id, '_replacement_request_submitted', true)) {
            ?>
            <form id="replacement-request-form" method="post" class="woocommerce-replacement-request-form">
                <h2><?php _e('طلب أستبدال', 'woocommerce'); ?></h2>
                <p>
                    <label for="replacement_message"><?php _e('لماذا تريد أستبدال المنتج؟', 'woocommerce'); ?></label>
                    <textarea name="replacement_message" id="replacement_message" rows="5"></textarea>
                </p>
                <p>
                    <input type="hidden" name="order_id" value="<?php echo esc_attr($order_id); ?>">
                    <button type="submit" name="submit_replacement_request" class="button"><?php _e('أرسال الطلب', 'woocommerce'); ?></button>
                </p>
            </form>
            <?php
        }
    }
}
add_action('woocommerce_account_view-order_endpoint', 'display_replacement_request_form');

// Handle form submission
function handle_replacement_request() {
    if (isset($_POST['submit_replacement_request'])) {
        $order_id = absint($_POST['order_id']);
        $message = sanitize_textarea_field($_POST['replacement_message']);
        $order = wc_get_order($order_id);

        $logger = wc_get_logger();
        $context = array('source' => 'handle_replacement_request');

        // Check if the order status is complete and within the last 7 days
        if ($order->get_status() == 'completed' && (time() - $order->get_date_completed()->getTimestamp()) <= 7 * DAY_IN_SECONDS) {
            // Check if a replacement request has already been submitted
            if (!get_post_meta($order_id, '_replacement_request_submitted', true)) {
                // Save the message as post meta
                update_post_meta($order_id, '_replacement_request_message', $message);
                update_post_meta($order_id, '_replacement_request_submitted', true);

                // Log the message and order ID
                $logger->info('Replacement message and order ID saved.', $context);

                // Create a refund request using the YITH Refund System plugin
                $refund_request_created = create_yith_refund_request($order_id, $message);

                if ($refund_request_created) {
                    // Add meta to refund request post to link it back to the original order
                    update_post_meta($refund_request_created, '_order_id', $order_id);
                    update_post_meta($refund_request_created, '_replacement_request_submitted', true); // Indicate that this refund request is related to a replacement request

                    wc_add_notice(__('Your replacement request has been submitted and a refund request has been created.', 'woocommerce'), 'success');
                    $logger->info('Refund request created successfully.', $context);
                } else {
                    wc_add_notice(__('Your replacement request has been submitted, but there was an issue creating the refund request.', 'woocommerce'), 'error');
                    $logger->error('Failed to create refund request.', $context);
                }
            } else {
                wc_add_notice(__('A replacement request has already been submitted for this order.', 'woocommerce'), 'error');
                $logger->warning('Replacement request already submitted for order ID: ' . $order_id, $context);
            }
        } else {
            wc_add_notice(__('The replacement request period has expired.', 'woocommerce'), 'error');
            $logger->warning('Replacement request period expired for order ID: ' . $order_id, $context);
        }

        wp_redirect(wc_get_account_endpoint_url('orders'));
        exit;
    }
}
add_action('template_redirect', 'handle_replacement_request');

// Function to create a refund request using YITH Refund System plugin
function create_yith_refund_request($order_id, $message) {
    if (class_exists('YITH_Refund_Request')) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return false;
        }

        $refund_request = new YITH_Refund_Request();
        $refund_request->order_id = $order_id;
        $refund_request->whole_order = true; // Assuming the refund is for the entire order
        $refund_request->item_id = 0;
        $refund_request->item_value = 0;
        $refund_request->item_total = $order->get_total();
        $refund_request->tax_value = 0;
        $refund_request->tax_total = 0;
        $refund_request->qty = 0;
        $refund_request->qty_total = $order->get_item_count();
        $refund_request->item_refund_total = $order->get_total();
        $refund_request->tax_refund_total = 0;
        $refund_request->refund_total = $order->get_total();
        $refund_request->refund_id = 0;
        $refund_request->refunded_amount = 0;
        $refund_request->customer_id = $order->get_user_id();
        $refund_request->coupon_id = 0;
        $refund_request->is_closed = false;
        $refund_request->status = 'ywcars-pending';

        $refund_request_id = $refund_request->save();

        if ($refund_request_id) {
            $refund_message = new YITH_Request_Message();
            $refund_message->request = $refund_request_id;
            $refund_message->message = $message;
            $refund_message->author = $refund_request->customer_id;
            $refund_message->save();

            return $refund_request_id; // Return the refund request ID
        }

        return false;
    } else {
        return false;
    }
}

// Display replacement request message in the order admin page
function display_replacement_message_in_admin($order) {
    $message = get_post_meta($order->get_id(), '_replacement_request_message', true);
    if (!empty($message)) {
        echo '<p><strong>' . __('Replacement Request Message:', 'woocommerce') . '</strong><br>' . nl2br(esc_html($message)) . '</p>';
    }
}
add_action('woocommerce_admin_order_data_after_order_details', 'display_replacement_message_in_admin');

// Add a replacement label to the orders table in the admin dashboard
function add_replacement_label_to_order_list($column, $post_id) {
    if ($column === 'order_number') {
        $replacement_request_submitted = get_post_meta($post_id, '_replacement_request_submitted', true);
        if ($replacement_request_submitted) {
            echo ' <span style="color: green;" title="Replacement Requested">طلب أستبدال</span>';
        }
    }
}
add_action('manage_shop_order_posts_custom_column', 'add_replacement_label_to_order_list', 10, 2);

// Add a custom column to the refund requests table
function add_custom_refund_request_columns($columns) {
    $columns['replacement_request'] = __('Replacement Request', 'woocommerce');
    return $columns;
}
add_filter('manage_edit-yith_refund_request_columns', 'add_custom_refund_request_columns');

// Populate the custom column in the refund requests table
function populate_custom_refund_request_column($column, $post_id) {
    if ($column === 'replacement_request') {
        $replacement_request_submitted = get_post_meta($post_id, '_replacement_request_submitted', true); // Check the refund request meta for replacement request
        if ($replacement_request_submitted) {
            echo '<span style="color: green;" title="Replacement Requested">طلب أستبدال</span>';
        }
    }
}
add_action('manage_yith_refund_request_posts_custom_column', 'populate_custom_refund_request_column', 10, 2);

// Add custom CSS to the admin area to style the replacement label
function add_custom_admin_styles() {
    echo '<style>
        .dashicons-yes {
            margin-left: 5px;
        }
    </style>';
}
add_action('admin_head', 'add_custom_admin_styles');

// Add title to YITH refund request order page
function add_replacement_request_title_yith($post) {
    if ($post->post_type == 'yith_refund_request' && get_post_meta($post->ID, '_replacement_request_submitted', true)) {
        echo '<h2>' . __('طلب أستبدال', 'woocommerce') . '</h2>';
    }
}
add_action('add_meta_boxes', function() {
    add_meta_box('replacement_request_title', __('Replacement Request', 'woocommerce'), 'add_replacement_request_title_yith', 'yith_refund_request', 'normal', 'high');
});
?>
