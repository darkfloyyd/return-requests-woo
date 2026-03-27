<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function woo_returns_render_list_page() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'woo_returns';
    
    // Handle change_status action
    if ( isset( $_GET['action'] ) && $_GET['action'] === 'change_status' && isset( $_GET['id'] ) && isset( $_GET['status'] ) ) {
        $id = intval( $_GET['id'] );
        $new_status = sanitize_text_field( wp_unslash( $_GET['status'] ) );
        if ( isset( $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'change_status_' . $id ) ) {
            if ( in_array( $new_status, ['pending', 'completed', 'issue'], true ) ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $wpdb->update(
                    $table_name,
                    [ 'status' => $new_status ],
                    [ 'id' => $id ],
                    [ '%s' ],
                    [ '%d' ]
                );
                
                // Update WooCommerce Order Status
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                $return_record = $wpdb->get_row( $wpdb->prepare( "SELECT order_id FROM `" . esc_sql( $table_name ) . "` WHERE id = %d LIMIT 1", $id ) );
                if ( $return_record && ! empty( $return_record->order_id ) ) {
                    $order = wc_get_order( $return_record->order_id );
                    if ( $order ) {
                        // Suppress our own hook to avoid redundant DB query feedback
                        remove_action( 'woocommerce_order_status_changed', 'woo_return_sync_status_to_db', 10 );
                        /* translators: %s: status name */
                        $order->update_status( 'return-' . $new_status, sprintf( __( 'Return status changed to %s via Return Requests list.', 'return-requests-woo' ), $new_status ) );
                        add_action( 'woocommerce_order_status_changed', 'woo_return_sync_status_to_db', 10, 4 );
                    }
                }

                wp_safe_redirect( admin_url( 'admin.php?page=return-requests-woo' ) );
                exit;
            }
        }
    }

    // Handle delete_return action
    if ( isset( $_GET['action'] ) && $_GET['action'] === 'delete_return' && isset( $_GET['id'] ) ) {
        $id = intval( $_GET['id'] );
        if ( isset( $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'delete_return_' . $id ) ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->delete( $table_name, [ 'id' => $id ], [ '%d' ] );
        }
        wp_safe_redirect( admin_url( 'admin.php?page=return-requests-woo' ) );
        exit;
    }


    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $returns = $wpdb->get_results( "SELECT * FROM `" . esc_sql( $table_name ) . "` ORDER BY created_at DESC" );
    
    ?>
    <div class="wrap">
        <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
        
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'ID', 'return-requests-woo' ); ?></th>
                    <th><?php esc_html_e( 'Order', 'return-requests-woo' ); ?></th>
                    <th><?php esc_html_e( 'Customer', 'return-requests-woo' ); ?></th>
                    <th><?php esc_html_e( 'Email', 'return-requests-woo' ); ?></th>
                    <th><?php esc_html_e( 'Bank account', 'return-requests-woo' ); ?></th>
                    <th><?php esc_html_e( 'Date', 'return-requests-woo' ); ?></th>
                    <th><?php esc_html_e( 'Status', 'return-requests-woo' ); ?></th>
                    <th><?php esc_html_e( 'Actions', 'return-requests-woo' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php 
                if ( $returns ) : 
                    foreach ( $returns as $return ) : 
                        // Decrypt bank account number before displaying
                        $bank_account = $return->bank_account;
                        if (function_exists('woo_return_decrypt_data') && $bank_account) {
                            $decrypted_account = woo_return_decrypt_data($bank_account);
                            if (!empty($decrypted_account)) {
                                $bank_account = $decrypted_account;
                            }
                        }
                        
                        $status = property_exists($return, 'status') ? $return->status : 'pending';
                        $status_color = match($status) {
                            'completed' => '#46b450',
                            'issue' => '#dc3232',
                            default => '#ffb900'
                        };
                        $status_label = match($status) {
                            'completed' => esc_html__('Completed', 'return-requests-woo'),
                            'issue' => esc_html__('Issue', 'return-requests-woo'),
                            default => esc_html__('Pending', 'return-requests-woo')
                        };
                ?>
                    <tr>
                        <td><?php echo esc_html( $return->id ); ?></td>
                        <td>
                            <a href="<?php echo esc_url( admin_url( 'post.php?post=' . $return->order_id . '&action=edit' ) ); ?>">
                                #<?php echo esc_html( $return->order_id ); ?>
                            </a>
                        </td>
                        <td><?php echo esc_html( $return->customer_name ); ?></td>
                        <td><?php echo esc_html( $return->customer_email ); ?></td>
                        <td><?php echo esc_html( $bank_account ); ?></td>
                        <td><?php echo esc_html( $return->created_at ); ?></td>
                        <td>
                            <span style="display: inline-block; padding: 3px 8px; border-radius: 3px; background-color: <?php echo esc_attr($status_color); ?>; color: #fff; font-size: 11px; font-weight: bold;">
                                <?php echo esc_html($status_label); ?>
                            </span>
                        </td>
                        <td>
                            <?php if ( $return->pdf_path ) : 
                                $download_url = wp_nonce_url( admin_url( 'admin-ajax.php?action=woo_return_download_pdf&return_id=' . $return->id ), 'download_return_pdf_' . $return->id );
                            ?>
                                <a href="<?php echo esc_url( $download_url ); ?>" target="_blank">
                                    <?php esc_html_e( 'Download PDF', 'return-requests-woo' ); ?>
                                </a>
                            <?php endif; ?>
                            <?php if ( $status === 'pending' || $status === 'issue' ) : 
                                $complete_url = wp_nonce_url( admin_url( 'admin.php?page=return-requests-woo&action=change_status&status=completed&id=' . $return->id ), 'change_status_' . $return->id );
                            ?>
                                <br><a href="<?php echo esc_url( $complete_url ); ?>" style="color: #46b450; font-size: 12px;"><?php esc_html_e( 'Mark as completed', 'return-requests-woo' ); ?></a>
                            <?php endif; ?>
                            <?php if ( $status === 'pending' || $status === 'completed' ) : 
                                $issue_url = wp_nonce_url( admin_url( 'admin.php?page=return-requests-woo&action=change_status&status=issue&id=' . $return->id ), 'change_status_' . $return->id );
                            ?>
                                <br><a href="<?php echo esc_url( $issue_url ); ?>" style="color: #dc3232; font-size: 12px;"><?php esc_html_e( 'Mark as issue', 'return-requests-woo' ); ?></a>
                            <?php endif; ?>
                            <?php
                                $delete_url = wp_nonce_url( admin_url( 'admin.php?page=return-requests-woo&action=delete_return&id=' . $return->id ), 'delete_return_' . $return->id );
                                $confirm_msg = esc_js( __( 'Are you sure you want to permanently delete this return record? This action cannot be undone.', 'return-requests-woo' ) );
                            ?>
                            <br><a
                                href="<?php echo esc_url( $delete_url ); ?>"
                                onclick="return confirm('<?php echo $confirm_msg; ?>')"
                                style="color: #a00; font-size: 12px;"><?php esc_html_e( 'Delete', 'return-requests-woo' ); ?></a>
                        </td>
                    </tr>
                <?php 
                    endforeach; 
                else : 
                ?>
                    <tr>
                        <td colspan="7"><?php esc_html_e( 'No returns.', 'return-requests-woo' ); ?></td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}
