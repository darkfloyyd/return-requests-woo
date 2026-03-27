<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Add meta box
add_action( 'add_meta_boxes', 'woo_return_add_order_meta_box', 10, 2 );

function woo_return_add_order_meta_box( $post_type, $post ) {
    $screen = class_exists( '\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController' ) && wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class )->custom_orders_table_usage_is_enabled()
        ? wc_get_page_screen_id( 'shop-order' )
        : 'shop_order';

    if ( $post_type === $screen || $post_type === 'shop_order' || $post_type === 'woocommerce_page_wc-orders' ) {
        add_meta_box(
            'woo_return_order_meta_box',
            esc_html__( 'Return Request Status', 'return-requests-woo' ),
            'woo_return_render_order_meta_box',
            $post_type,
            'side',
            'high'
        );
    }
}

function woo_return_render_order_meta_box( $post_or_order_object ) {
    global $wpdb;
    
    // Get order ID depending on HPOS or CPT
    $order_id = ( $post_or_order_object instanceof WP_Post ) ? $post_or_order_object->ID : ( is_object( $post_or_order_object ) && method_exists( $post_or_order_object, 'get_id' ) ? $post_or_order_object->get_id() : 0 );
    
    if ( ! $order_id ) {
        return;
    }

    $table_name = $wpdb->prefix . 'woo_returns';
    
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $return = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `" . esc_sql( $table_name ) . "` WHERE order_id = %d LIMIT 1", $order_id ) );

    if ( ! $return ) {
        echo '<p>' . esc_html__( 'No return associated with this order.', 'return-requests-woo' ) . '</p>';
        return;
    }

    $status = property_exists($return, 'status') ? $return->status : 'pending';
    
    // Add nonce for security
    wp_nonce_field( 'woo_return_save_order_meta_box', 'woo_return_order_meta_box_nonce' );
    ?>
    <p>
        <strong><?php esc_html_e( 'Status:', 'return-requests-woo' ); ?></strong><br>
        <select name="woo_return_status" id="woo_return_status" style="width: 100%;">
            <option value="pending" <?php selected( $status, 'pending' ); ?>><?php esc_html_e( 'Pending', 'return-requests-woo' ); ?></option>
            <option value="completed" <?php selected( $status, 'completed' ); ?>><?php esc_html_e( 'Completed', 'return-requests-woo' ); ?></option>
            <option value="issue" <?php selected( $status, 'issue' ); ?>><?php esc_html_e( 'Issue', 'return-requests-woo' ); ?></option>
        </select>
    </p>
    
    <div id="woo_return_issue_note_container" style="<?php echo $status === 'issue' ? 'display:block;' : 'display:none;'; ?>">
        <p>
            <strong><?php esc_html_e( 'Issue description (will be sent as an order note to customer):', 'return-requests-woo' ); ?></strong><br>
            <textarea name="woo_return_issue_note" id="woo_return_issue_note" rows="3" style="width: 100%;"></textarea>
            <span class="description" style="display:block; margin-top:5px;"><?php esc_html_e( 'Optional. Leave blank to just change the status without sending a note.', 'return-requests-woo' ); ?></span>
        </p>
    </div>
    
    <p>
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=woo-return-requests&action=view&id=' . $return->id ) ); ?>" target="_blank"><?php esc_html_e( 'View full return details', 'return-requests-woo' ); ?></a>
    </p>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        var statusSelect = document.getElementById('woo_return_status');
        var noteContainer = document.getElementById('woo_return_issue_note_container');
        if (statusSelect && noteContainer) {
            statusSelect.addEventListener('change', function() {
                if (this.value === 'issue') {
                    noteContainer.style.display = 'block';
                } else {
                    noteContainer.style.display = 'none';
                }
            });
        }
    });
    </script>
    <?php
}

// Hook for saving meta box (CPT)
add_action( 'save_post_shop_order', 'woo_return_save_order_meta_box', 10, 2 );
// Hook for saving meta box (HPOS)
add_action( 'woocommerce_process_shop_order_meta', 'woo_return_save_order_meta_box', 10, 2 );

function woo_return_save_order_meta_box( $post_id, $post = null ) {
    // Check if nonce is set
    if ( ! isset( $_POST['woo_return_order_meta_box_nonce'] ) ) {
        return;
    }
    
    // Verify nonce
    if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['woo_return_order_meta_box_nonce'] ) ), 'woo_return_save_order_meta_box' ) ) {
        return;
    }

    if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
        return;
    }

    if ( ! current_user_can( 'edit_shop_order', $post_id ) ) {
        return;
    }

    if ( isset( $_POST['woo_return_status'] ) ) {
        global $wpdb;
        $order_id = $post_id;
        $table_name = $wpdb->prefix . 'woo_returns';
        $new_status = sanitize_text_field( wp_unslash( $_POST['woo_return_status'] ) );

        if ( in_array( $new_status, ['pending', 'completed', 'issue'], true ) ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->update(
                $table_name,
                [ 'status' => $new_status ],
                [ 'order_id' => $order_id ],
                [ '%s' ],
                [ '%d' ]
            );
            
            // Add note if it's an issue
            if ( $new_status === 'issue' && ! empty( $_POST['woo_return_issue_note'] ) ) {
                $note = sanitize_textarea_field( wp_unslash( $_POST['woo_return_issue_note'] ) );
                $order = wc_get_order( $order_id );
                if ( $order ) {
                    // add_order_note($note, $is_customer_note = 0, $added_by_user = false)
                    // We set is_customer_note to 1 so the customer can see it
                    $order->add_order_note( $note, 1, false );
                }
            }

            // Sync with actual Woo order status
            $order = wc_get_order( $order_id );
            if ( $order && $order->get_status() !== 'return-' . $new_status ) {
                remove_action( 'woocommerce_order_status_changed', 'woo_return_sync_status_to_db', 10 );
                /* translators: %s: status name */
                $order->update_status( 'return-' . $new_status, sprintf( __( 'Return status updated to %s.', 'return-requests-woo' ), $new_status ) );
                add_action( 'woocommerce_order_status_changed', 'woo_return_sync_status_to_db', 10, 4 );
            }
        }
    }
}
