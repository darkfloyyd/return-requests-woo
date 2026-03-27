<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Register dashboard tab
add_action( 'admin_menu', 'woo_return_add_admin_menu' );

function woo_return_add_admin_menu() {
    // Main returns menu
    add_menu_page(
        __( 'Return Requests', 'return-requests-woo' ),
        __( 'Return Requests', 'return-requests-woo' ),
        'manage_woocommerce',
        'return-requests-woo',
        'woo_returns_render_list_page',
        'dashicons-update-alt',
        56
    );

    // Add first subpage ("All returns") subordinate to the same slug
    add_submenu_page(
        'return-requests-woo',
        __( 'All returns', 'return-requests-woo' ),
        __( 'All returns', 'return-requests-woo' ),
        'manage_woocommerce',
        'return-requests-woo',
        'woo_returns_render_list_page'
    );

    // Add settings subpage
    add_submenu_page(
        'return-requests-woo',
        __( 'Settings', 'return-requests-woo' ),
        __( 'Settings', 'return-requests-woo' ),
        'manage_woocommerce',
        'woo-return-settings',
        'woo_return_main_page'
    );
}
