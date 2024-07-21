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

// Enqueue necessary scripts and styles
function enqueue_replacement_request_scripts() {
    wp_enqueue_script('jquery');
    wp_enqueue_style('replacement-request-style', plugins_url('replacement-request.css', __FILE__));
    wp_add_inline_style('replacement-request-style', '
        #replacement-request-popup, #replacement-request-popup-whole {
            display: none;
            position: fixed;
            left: 50%;
            top: 50%;
            transform: translate(-50%, -50%);
            background-color: #fff;
            padding: 20px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.5);
            z-index: 9999;
        }
        #replacement-request-popup .close-popup, #replacement-request-popup-whole .close-popup {
            position: absolute;
            top: 10px;
            right: 10px;
            cursor: pointer;
        }
        #replacement-request-popup-overlay {
            display: none;
            position: fixed;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 9998;
        }
    ');
}
add_action('wp_enqueue_scripts', 'enqueue_replacement_request_scripts');

// Display the replacement request button under the product name
function add_request_replacement_button_to_order_items($item_id, $item, $order) {
    if ($order->get_status() == 'completed' && !get_post_meta($order->get_id(), '_replacement_request_submitted_' . $item_id, true)) {
        $completion_date = $order->get_date_completed();
        if ($completion_date && (time() - $completion_date->getTimestamp()) <= 7 * DAY_IN_SECONDS) {
            ?>
            <button class="button replacement-request-button" data-order-id="<?php echo $order->get_id(); ?>" data-item-id="<?php echo $item_id; ?>">
                <?php _e('تقديم طلب استبدال', 'woocommerce'); ?>
            </button>
            <?php
        }
    }
}
add_action('woocommerce_order_item_meta_end', 'add_request_replacement_button_to_order_items', 10, 3);

// Display the replacement request button for the whole order
function add_request_replacement_button_for_whole_order($order) {
    if ($order->get_status() == 'completed' && !get_post_meta($order->get_id(), '_replacement_request_submitted_whole', true)) {
        $completion_date = $order->get_date_completed();
        if ($completion_date && (time() - $completion_date->getTimestamp()) <= 7 * DAY_IN_SECONDS) {
            ?>
            <button class="button replacement-request-button-whole" data-order-id="<?php echo $order->get_id(); ?>">
                <?php _e('أستبدال الطلب بالكامل', 'woocommerce'); ?>
            </button>
            <?php
        }
    }
}
add_action('woocommerce_order_details_after_order_table', 'add_request_replacement_button_for_whole_order');

// Display the replacement request form as a popup
function display_replacement_request_form_popup() {
    ?>
    <div id="replacement-request-popup-overlay"></div>
    <div id="replacement-request-popup">
        <span class="close-popup">&times;</span>
        <form id="replacement-request-form" method="post" class="woocommerce-replacement-request-form">
            <h2><?php _e('طلب أستبدال', 'woocommerce'); ?></h2>
            <p>
                <label for="replacement_message"><?php _e('لماذا تريد أستبدال المنتج؟', 'woocommerce'); ?></label>
                <textarea name="replacement_message" id="replacement_message" rows="5"></textarea>
            </p>
            <p>
                <input type="hidden" name="order_id" id="replacement_order_id">
                <input type="hidden" name="item_id" id="replacement_item_id">
                <button type="submit" name="submit_replacement_request" class="button"><?php _e('أرسال الطلب', 'woocommerce'); ?></button>
            </p>
        </form>
    </div>
    <div id="replacement-request-popup-whole">
        <span class="close-popup">&times;</span>
        <form id="replacement-request-form-whole" method="post" class="woocommerce-replacement-request-form">
            <h2><?php _e('طلب أستبدال الطلب بالكامل', 'woocommerce'); ?></h2>
            <p>
                <label for="replacement_message_whole"><?php _e('لماذا تريد أستبدال الطلب بالكامل؟', 'woocommerce'); ?></label>
                <textarea name="replacement_message_whole" id="replacement_message_whole" rows="5"></textarea>
            </p>
            <p>
                <input type="hidden" name="order_id_whole" id="replacement_order_id_whole">
                <button type="submit" name="submit_replacement_request_whole" class="button"><?php _e('أرسال الطلب', 'woocommerce'); ?></button>
            </p>
        </form>
    </div>
    <script type="text/javascript">
        jQuery(document).ready(function($) {
            $('.replacement-request-button').click(function() {
                var orderId = $(this).data('order-id');
                var itemId = $(this).data('item-id');
                $('#replacement_order_id').val(orderId);
                $('#replacement_item_id').val(itemId);
                $('#replacement-request-popup-overlay, #replacement-request-popup').show();
            });

            $('.replacement-request-button-whole').click(function() {
                var orderId = $(this).data('order-id');
                $('#replacement_order_id_whole').val(orderId);
                $('#replacement-request-popup-overlay, #replacement-request-popup-whole').show();
            });

            $('.close-popup, #replacement-request-popup-overlay').click(function() {
                $('#replacement-request-popup-overlay, #replacement-request-popup, #replacement-request-popup-whole').hide();
            });
        });
    </script>
    <?php
}
add_action('wp_footer', 'display_replacement_request_form_popup');

// Handle form submission for individual items
function handle_replacement_request() {
    if (isset($_POST['submit_replacement_request'])) {
        $order_id = absint($_POST['order_id']);
        $item_id = absint($_POST['item_id']);
        $message = sanitize_textarea_field($_POST['replacement_message']);
        $order = wc_get_order($order_id);

        $logger = wc_get_logger();
        $context = array('source' => 'handle_replacement_request');

        // Check if the order status is complete and within the last 7 days
        if ($order->get_status() == 'completed' && (time() - $order->get_date_completed()->getTimestamp()) <= 7 * DAY_IN_SECONDS) {
            // Check if a replacement request has already been submitted for this item
            if (!get_post_meta($order_id, '_replacement_request_submitted_' . $item_id, true)) {
                // Save the message as post meta
                update_post_meta($order_id, '_replacement_request_message_' . $item_id, $message);
                update_post_meta($order_id, '_replacement_request_submitted_' . $item_id, true);

                // Log the message and order ID
                $logger->info('Replacement message and order item ID saved.', $context);

                // Create a refund request using the YITH Refund System plugin
                $refund_request_created = create_yith_refund_request($order_id, $item_id, $message);

                if ($refund_request_created) {
                    // Add meta to refund request post to link it back to the original order item
                    update_post_meta($refund_request_created, '_order_id', $order_id);
                    update_post_meta($refund_request_created, '_item_id', $item_id);
                    update_post_meta($refund_request_created, '_replacement_request_submitted', true); // Indicate that this refund request is related to a replacement request

                    wc_add_notice(__('Your replacement request has been submitted and a refund request has been created.', 'woocommerce'), 'success');
                    $logger->info('Refund request created successfully.', $context);
                } else {
                    wc_add_notice(__('Your replacement request has been submitted, but there was an issue creating the refund request.', 'woocommerce'), 'error');
                    $logger->error('Failed to create refund request.', $context);
                }
            } else {
                wc_add_notice(__('A replacement request has already been submitted for this product.', 'woocommerce'), 'error');
                $logger->warning('Replacement request already submitted for order item ID: ' . $item_id, $context);
            }
        } else {
            wc_add_notice(__('The replacement request period has expired.', 'woocommerce'), 'error');
            $logger->warning('Replacement request period expired for order item ID: ' . $item_id, $context);
        }

        wp_redirect(wc_get_account_endpoint_url('orders'));
        exit;
    }
}
add_action('template_redirect', 'handle_replacement_request');

// Handle form submission for the whole order
function handle_replacement_request_whole() {
    if (isset($_POST['submit_replacement_request_whole'])) {
        $order_id = absint($_POST['order_id_whole']);
        $message = sanitize_textarea_field($_POST['replacement_message_whole']);
        $order = wc_get_order($order_id);

        $logger = wc_get_logger();
        $context = array('source' => 'handle_replacement_request_whole');

        // Check if the order status is complete and within the last 7 days
        if ($order->get_status() == 'completed' && (time() - $order->get_date_completed()->getTimestamp()) <= 7 * DAY_IN_SECONDS) {
            // Check if a replacement request has already been submitted for this order
            if (!get_post_meta($order_id, '_replacement_request_submitted_whole', true)) {
                // Save the message as post meta
                update_post_meta($order_id, '_replacement_request_message_whole', $message);
                update_post_meta($order_id, '_replacement_request_submitted_whole', true);

                // Log the message and order ID
                $logger->info('Replacement message for whole order saved.', $context);

                // Create a refund request using the YITH Refund System plugin
                $refund_request_created = create_yith_refund_request($order_id, 0, $message); // 0 indicates the whole order

                if ($refund_request_created) {
                    // Add meta to refund request post to link it back to the original order
                    update_post_meta($refund_request_created, '_order_id', $order_id);
                    update_post_meta($refund_request_created, '_replacement_request_submitted', true); // Indicate that this refund request is related to a replacement request

                    wc_add_notice(__('Your replacement request has been submitted and a refund request has been created.', 'woocommerce'), 'success');
                    $logger->info('Refund request created successfully for whole order.', $context);
                } else {
                    wc_add_notice(__('Your replacement request has been submitted, but there was an issue creating the refund request.', 'woocommerce'), 'error');
                    $logger->error('Failed to create refund request for whole order.', $context);
                }
            } else {
                wc_add_notice(__('A replacement request has already been submitted for this order.', 'woocommerce'), 'error');
                $logger->warning('Replacement request already submitted for whole order ID: ' . $order_id, $context);
            }
        } else {
            wc_add_notice(__('The replacement request period has expired.', 'woocommerce'), 'error');
            $logger->warning('Replacement request period expired for whole order ID: ' . $order_id, $context);
        }

        wp_redirect(wc_get_account_endpoint_url('orders'));
        exit;
    }
}
add_action('template_redirect', 'handle_replacement_request_whole');

// Function to create a refund request using YITH Refund System plugin
function create_yith_refund_request($order_id, $item_id, $message) {
    if (class_exists('YITH_Refund_Request')) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return false;
        }

        $refund_request = new YITH_Refund_Request();
        $refund_request->order_id = $order_id;
        $refund_request->whole_order = ($item_id === 0); // True if the refund is for the entire order
        $refund_request->item_id = $item_id;
        $refund_request->item_value = ($item_id === 0) ? 0 : $order->get_item($item_id)->get_total();
        $refund_request->item_total = ($item_id === 0) ? $order->get_total() : $order->get_item($item_id)->get_total();
        $refund_request->tax_value = 0;
        $refund_request->tax_total = 0;
        $refund_request->qty = ($item_id === 0) ? 0 : $order->get_item($item_id)->get_quantity();
        $refund_request->qty_total = ($item_id === 0) ? $order->get_item_count() : $order->get_item($item_id)->get_quantity();
        $refund_request->item_refund_total = ($item_id === 0) ? $order->get_total() : $order->get_item($item_id)->get_total();
        $refund_request->tax_refund_total = 0;
        $refund_request->refund_total = ($item_id === 0) ? $order->get_total() : $order->get_item($item_id)->get_total();
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
    foreach ($order->get_items() as $item_id => $item) {
        $message = get_post_meta($order->get_id(), '_replacement_request_message_' . $item_id, true);
        if (!empty($message)) {
            echo '<p><strong>' . __('Replacement Request Message for ', 'woocommerce') . esc_html($item->get_name()) . ':</strong><br>' . nl2br(esc_html($message)) . '</p>';
        }
    }
    $message_whole = get_post_meta($order->get_id(), '_replacement_request_message_whole', true);
    if (!empty($message_whole)) {
        echo '<p><strong>' . __('Replacement Request Message for Whole Order:', 'woocommerce') . '</strong><br>' . nl2br(esc_html($message_whole)) . '</p>';
    }
}
add_action('woocommerce_admin_order_data_after_order_details', 'display_replacement_message_in_admin');

// Add a replacement label to the orders table in the admin dashboard
function add_replacement_label_to_order_list($column, $post_id) {
    if ($column === 'order_number') {
        $order = wc_get_order($post_id);
        foreach ($order->get_items() as $item_id => $item) {
            $replacement_request_submitted = get_post_meta($post_id, '_replacement_request_submitted_' . $item_id, true);
            if ($replacement_request_submitted) {
                echo ' <span style="color: green;" title="Replacement Requested for Item: ' . esc_attr($item->get_name()) . '">طلب أستبدال (' . esc_html($item->get_name()) . ')</span>';
            }
        }
        $replacement_request_submitted_whole = get_post_meta($post_id, '_replacement_request_submitted_whole', true);
        if ($replacement_request_submitted_whole) {
            echo ' <span style="color: green;" title="Replacement Requested for Whole Order">طلب أستبدال (الطلب بالكامل)</span>';
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
            $order_id = get_post_meta($post_id, '_order_id', true);
            $item_id = get_post_meta($post_id, '_item_id', true);
            $order = wc_get_order($order_id);
            if ($order) {
                if ($item_id == 0) {
                    echo '<span style="color: green;" title="Replacement Requested for Whole Order">طلب أستبدال (الطلب بالكامل)</span>';
                } else {
                    $item = $order->get_item($item_id);
                    if ($item) {
                        echo '<span style="color: green;" title="Replacement Requested for Item: ' . esc_attr($item->get_name()) . '">طلب أستبدال (' . esc_html($item->get_name()) . ')</span>';
                    }
                }
            }
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
        echo '<h1>' . __('طلب أستبدال', 'woocommerce') . '</h1>';
    }
}
add_action('edit_form_top', 'add_replacement_request_title_yith');
