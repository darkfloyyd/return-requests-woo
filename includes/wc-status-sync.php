<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * WooCommerce Status Synchronization
 *
 * Registers custom order statuses corresponding to the return states
 * and ensures parity between the WooCommerce order status and the custom wco_returns table.
 */

// Register the custom order statuses
add_action( 'init', 'woo_return_register_order_statuses' );
function woo_return_register_order_statuses() {
    register_post_status( 'wc-return-pending', array(
        'label'                     => _x( 'Return (pending)', 'Order status', 'return-requests-woo' ),
        'public'                    => true,
        'exclude_from_search'       => false,
        'show_in_admin_all_list'    => true,
        'show_in_admin_status_list' => true,
        /* translators: %s: count */
        'label_count'               => _n_noop( 'Return (pending) <span class="count">(%s)</span>', 'Returns (pending) <span class="count">(%s)</span>', 'return-requests-woo' )
    ) );

    register_post_status( 'wc-return-completed', array(
        'label'                     => _x( 'Return (completed)', 'Order status', 'return-requests-woo' ),
        'public'                    => true,
        'exclude_from_search'       => false,
        'show_in_admin_all_list'    => true,
        'show_in_admin_status_list' => true,
        /* translators: %s: count */
        'label_count'               => _n_noop( 'Return (completed) <span class="count">(%s)</span>', 'Returns (completed) <span class="count">(%s)</span>', 'return-requests-woo' )
    ) );

    register_post_status( 'wc-return-issue', array(
        'label'                     => _x( 'Return (issue)', 'Order status', 'return-requests-woo' ),
        'public'                    => true,
        'exclude_from_search'       => false,
        'show_in_admin_all_list'    => true,
        'show_in_admin_status_list' => true,
        /* translators: %s: count */
        'label_count'               => _n_noop( 'Return (issue) <span class="count">(%s)</span>', 'Returns (issue) <span class="count">(%s)</span>', 'return-requests-woo' )
    ) );
}

// Add the custom order statuses to the WooCommerce status list
add_filter( 'wc_order_statuses', 'woo_return_add_order_statuses' );
function woo_return_add_order_statuses( $order_statuses ) {
    $new_order_statuses = array();

    foreach ( $order_statuses as $key => $status ) {
        $new_order_statuses[ $key ] = $status;
        if ( 'wc-completed' === $key || 'wc-refunded' === $key ) {
            $new_order_statuses['wc-return-pending']   = _x( 'Return (pending)', 'Order status', 'return-requests-woo' );
            $new_order_statuses['wc-return-completed'] = _x( 'Return (completed)', 'Order status', 'return-requests-woo' );
            $new_order_statuses['wc-return-issue']     = _x( 'Return (issue)', 'Order status', 'return-requests-woo' );
        }
    }

    return $new_order_statuses;
}

// Hook into order status changes from WooCommerce to the plugin DB
add_action( 'woocommerce_order_status_changed', 'woo_return_sync_status_to_db', 10, 4 );
function woo_return_sync_status_to_db( $order_id, $from_status, $to_status, $order ) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'woo_returns';

    $plugin_status = '';
    if ( $to_status === 'return-pending' ) {
        $plugin_status = 'pending';
    } elseif ( $to_status === 'return-completed' ) {
        $plugin_status = 'completed';
    } elseif ( $to_status === 'return-issue' ) {
        $plugin_status = 'issue';
    }

    if ( ! empty( $plugin_status ) ) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->update(
            $table_name,
            array( 'status' => $plugin_status ),
            array( 'order_id' => $order_id ),
            array( '%s' ),
            array( '%d' )
        );
    }
}
