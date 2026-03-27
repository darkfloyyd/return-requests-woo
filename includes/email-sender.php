<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ============================================================
// Email verification and common message template
// ============================================================

/**
 * HTML template for all plugin email messages.
 */
function woo_return_get_email_template( $title, $content ) {
    // If WooCommerce is fully initialized, wrap using its native email styling engine.
    // get_emails() forces WC to load all email classes and register header/footer hooks.
    if ( function_exists( 'WC' ) && is_object( WC()->mailer() ) ) {
        WC()->mailer()->get_emails(); // Loads WC email classes and registers their action hooks

        ob_start();
        do_action( 'woocommerce_email_header', $title, null );
        echo wp_kses_post( $content );
        do_action( 'woocommerce_email_footer', null );
        return ob_get_clean();
    }

    // Fallback if WooCommerce is somehow bypassed
    $year = wp_date( 'Y' );
    $site_name = get_bloginfo( 'name' );
    return '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>' . esc_html( $title ) . '</title>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px; }
            .email-container { border: 1px solid #e5e5e5; border-radius: 5px; padding: 20px; background-color: #f9f9f9; }
            .header { text-align: center; margin-bottom: 20px; padding-bottom: 10px; border-bottom: 1px solid #e5e5e5; }
            .content { margin-bottom: 20px; }
            .button-container { text-align: center; margin: 30px 0; }
            .button { display: inline-block; background-color: #FFF; color: #15c; border: 1px solid #15c; text-decoration: none; padding: 12px 24px; border-radius: 4px; font-weight: bold; }
            .footer { font-size: 12px; text-align: center; margin-top: 30px; color: #777; }
        </style>
    </head>
    <body>
        <div class="email-container">
            <div class="header">
                <h2>' . esc_html( $title ) . '</h2>
            </div>
            <div class="content">
                ' . $content . '
            </div>
            <div class="footer">
                <p>' . esc_html__( 'This message was generated automatically. Please do not reply.', 'return-requests-woo' ) . '</p>
                <p>&copy; ' . esc_html( $year . ' ' . $site_name ) . '. ' . esc_html__( 'All rights reserved.', 'return-requests-woo' ) . '</p>
            </div>
        </div>
    </body>
    </html>';
}

function woo_return_send_email( $email, $subject = '', $message = '' ) {
    if ( empty( $subject ) || empty( $message ) ) {
        return false;
    }

    $from_name  = get_option( 'woo_return_email_name', '' );
    if ( empty( $from_name ) ) {
        $from_name = get_bloginfo( 'name' );
    }
    $from_email = get_option( 'woo_return_return_from_email', '' );
    if ( empty( $from_email ) ) {
        $from_email = get_option( 'woocommerce_email_from_address', 'wordpress@' . wp_parse_url( home_url(), PHP_URL_HOST ) );
    }

    $html_message = woo_return_get_email_template( $subject, $message );

    $headers = array(
        'Content-Type: text/html; charset=UTF-8',
        'From: ' . $from_name . ' <' . $from_email . '>',
    );

    // Route through WC_Email::send() so that style_inline() → Emogrifier
    // is called, which inlines WooCommerce email-styles.php CSS into the HTML.
    // We must set email_type='html' explicitly — a bare WC_Email() has no ID,
    // so get_option('email_type') returns empty → falls back to 'plain' → no CSS inlining.
    if ( function_exists( 'WC' ) && is_object( WC()->mailer() ) ) {
        $wc_email             = new WC_Email();
        $wc_email->email_type = 'html';
        return $wc_email->send( $email, $subject, $html_message, implode( "\r\n", $headers ), array() );
    }

    return (bool) wp_mail( $email, $subject, $html_message, $headers );
}

// Email with PDF
function woo_return_send_email_pdf($order, $pdf_path = null, $customer_email = null) {
    if ( ! $order instanceof WC_Order ) {
        return false;
    }

	// Variables, default values and fetching plugin settings
    $admin_email = get_option('woo_return_admin_email', get_bloginfo('admin_email'));
    $from_email  = get_option('woo_return_from_email', 'wordpress@' . wp_parse_url(home_url(), PHP_URL_HOST)); 
    $from_name   = get_option('woo_return_email_name', ''); 
    if ( empty( $from_name ) ) $from_name = get_bloginfo('name');
    $customer_subject_template = get_option( 'woo_return_customer_subject', '' );
    if ( empty( $customer_subject_template ) ) $customer_subject_template = __( 'Return for order #{order_id}', 'return-requests-woo' );
    
    $admin_subject_template    = get_option( 'woo_return_admin_subject', '' );
    if ( empty( $admin_subject_template ) ) $admin_subject_template = __( 'New return for order #{order_id}', 'return-requests-woo' );
    
    $customer_message_template = get_option( 'woo_return_customer_message', '' );
    if ( empty( $customer_message_template ) ) $customer_message_template = __( 'Your return for order #{order_id} has been registered.', 'return-requests-woo' );
    
    $admin_message_template    = get_option( 'woo_return_admin_message', '' );
    if ( empty( $admin_message_template ) ) $admin_message_template = __( 'Return for order #{order_id} has been submitted.', 'return-requests-woo' );
	
    // Get order number
    $order_id = $order->get_id();
    $customer_email = $customer_email ?: $order->get_billing_email();

    // Replace {order_id} with actual order number in templates
    $customer_subject = str_replace('{order_id}', $order_id, $customer_subject_template);
    $admin_subject    = str_replace('{order_id}', $order_id, $admin_subject_template);
    $customer_message = str_replace('{order_id}', $order_id, $customer_message_template);
    $admin_message    = str_replace('{order_id}', $order_id, $admin_message_template);

    $customer_content = wpautop($customer_message) . '
        <p>' . esc_html__( 'Detailed information can be found in the attached PDF file.', 'return-requests-woo' ) . '</p>
        <p>' . esc_html__( 'Thank you for using our services.', 'return-requests-woo' ) . '</p>';
    $customer_html = woo_return_get_email_template( $customer_subject, $customer_content );

    $admin_content = wpautop($admin_message) . '
        <p>' . esc_html__( 'Detailed information can be found in the attached PDF file.', 'return-requests-woo' ) . '</p>';
    $admin_html = woo_return_get_email_template( $admin_subject, $admin_content );

    // Set message headers
    $headers = [
        'Content-Type: text/html; charset=UTF-8',
        'From: ' . $from_name . ' <' . $from_email . '>'
    ];

    $attachments = [];
    if ($pdf_path && file_exists($pdf_path)) {
        $attachments[] = $pdf_path;
    }

    // Send email to customer
    wp_mail($customer_email, $customer_subject, $customer_html, $headers, $attachments);

    // Send email to administrator
    wp_mail($admin_email, $admin_subject, $admin_html, $headers, $attachments);
}