<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Integrate "Return" button for logged-in users in the "My Account" panel
 */

// 1. Add action (button) to the orders table
add_filter( 'woocommerce_my_account_my_orders_actions', 'woo_return_add_my_account_orders_action', 10, 2 );
function woo_return_add_my_account_orders_action( $actions, $order ) {
    if ( woo_return_is_order_eligible_for_return( $order ) ) {
        $actions['woo-return'] = array(
            'url'  => wp_nonce_url( add_query_arg( array( 'action' => 'woo_return_order', 'order_id' => $order->get_id() ), wc_get_endpoint_url( 'orders' ) ), 'woo_return_order_' . $order->get_id() ),
            'name' => __( 'Return', 'return-requests-woo' ),
        );
    }
    return $actions;
}

// 2. Add button to single order view
add_action( 'woocommerce_order_details_after_order_table', 'woo_return_add_single_order_action', 10, 1 );
function woo_return_add_single_order_action( $order ) {
    if ( woo_return_is_order_eligible_for_return( $order ) ) {
        $return_url = wp_nonce_url( add_query_arg( array( 'action' => 'woo_return_order', 'order_id' => $order->get_id() ), $order->get_view_order_url() ), 'woo_return_order_' . $order->get_id() );
        echo '<p style="margin-top: 20px;"><a href="' . esc_url( $return_url ) . '" class="button">' . esc_html__( 'Submit return', 'return-requests-woo' ) . '</a></p>';
    }
}

// 3. Handle button redirection (generate token on the fly and enter return form)
add_action( 'template_redirect', 'woo_return_handle_my_account_action' );
function woo_return_handle_my_account_action() {
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $action   = isset( $_GET['action'] ) ? sanitize_text_field( wp_unslash( $_GET['action'] ) ) : '';
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $order_id = isset( $_GET['order_id'] ) ? intval( wp_unslash( $_GET['order_id'] ) ) : 0;

    if ( $action === 'woo_return_order' && $order_id > 0 ) {
        // Check Nonce and Session Permissions
        if ( isset( $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'woo_return_order_' . $order_id ) ) {
            
            $order = wc_get_order( $order_id );
            
            if ( $order && $order->get_user_id() === get_current_user_id() && woo_return_is_order_eligible_for_return( $order ) ) {
                
                // Generate secure session token (identical to email verification handling)
                if ( session_status() == PHP_SESSION_NONE ) {
                    session_start( [ 'cookie_lifetime' => 3600 ] );
                }
                
                $return_token = wp_generate_password( 32, false );
                
                // Fetch customer billing info to pre-fill the missing return parameters
                $phone   = $order->get_billing_phone();
                if ( empty( $phone ) ) {
                    $phone = get_user_meta( $order->get_user_id(), 'billing_phone', true );
                }

                $address = trim( $order->get_billing_address_1() . ' ' . $order->get_billing_address_2() );
                if ( empty( $address ) ) {
                    $address = trim( get_user_meta( $order->get_user_id(), 'billing_address_1', true ) . ' ' . get_user_meta( $order->get_user_id(), 'billing_address_2', true ) );
                }

                // If literally no address exists for this customer anywhere, prevent the return flow so the PDF isn't malformed
                if ( empty( $address ) ) {
                    wc_add_notice( __( 'Missing required contact details (address). Please update them in account settings.', 'return-requests-woo' ), 'error' );
                    wp_safe_redirect( wc_get_endpoint_url( 'orders' ) );
                    exit;
                }

                // Register order in session for the return form
                $_SESSION['return_order_' . $return_token] = [
                    'order_id'  => $order_id,
                    'phone'     => $phone,
                    'address'   => $address,
                    'timestamp' => time()
                ];
                
                // Redirect user to the return page
                $slug_items   = get_option( 'woo_return_slug_items', 'wybierz-przedmioty-do-zwrotu' );
                $redirect_url = add_query_arg( array( 'return_token' => $return_token ), home_url( '/' . $slug_items . '/' ) );
                
                wp_safe_redirect( $redirect_url );
                exit;
            } else {
                wc_add_notice( __( 'Order does not meet return conditions or was manipulated.', 'return-requests-woo' ), 'error' );
            }
        }
    }
}

/**
 * Check if order meets requirements for logistical return
 *
 * @param WC_Order $order
 * @return bool
 */
function woo_return_is_order_eligible_for_return( $order ) {
    if ( ! $order ) {
        return false;
    }

    // Only "completed" allowed
    if ( ! $order->has_status( 'completed' ) ) {
        return false;
    }

    // Check return window (e.g. 14 days default)
    $window_days  = get_option( 'woo_return_window_days', 14 );
    $order_date   = $order->get_date_completed();
    
    if ( ! $order_date ) {
        return false;
    }

    $expiration_timestamp = strtotime( $order_date->date( 'Y-m-d H:i:s' ) . " + {$window_days} days" );
    if ( time() > $expiration_timestamp ) {
        return false;
    }

    // Check if there is already a returned record
    global $wpdb;

    // Direct database verification (status read only), table built by prefix
    $existing = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$wpdb->prefix}woo_returns` WHERE order_id = %d", $order->get_id() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

    if ( $existing > 0 ) {
        return false;
    }

    return true;
}
