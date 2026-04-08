<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/../vendor/autoload.php';

function woo_return_generate_pdf( $order, $first_name = '', $last_name = '', $selected_items = array(), $bank_account = '', $phone = '', $address = '' ) {
    $mpdf = new \Mpdf\Mpdf();

    $items = $order->get_items();
    $table_rows = '';
    $index = 1;

    foreach ( $items as $item_id => $item ) {
        if ( ! empty( $selected_items ) && ! in_array( $item_id, $selected_items ) ) {
            continue;
        }

        $table_rows .= "<tr>
                            <td>{$index}</td>
                            <td>" . esc_html( $item->get_name() ) . "</td>
                            <td>{$item->get_quantity()}</td>
                            <td>" . wc_price( $item->get_total() ) . "</td>
                        </tr>";
        $index++;
    }

    // Get store details from settings
    $store_details = nl2br( esc_html( get_option( 'woo_return_store_details', '' ) ) );

    // Set form submission date (wp_date respects WordPress timezone)
    $submission_date = wp_date( 'Y-m-d H:i:s' );

    // Decrypt bank account number if it is encrypted
    $display_bank_account = $bank_account;
    if (function_exists('woo_return_decrypt_data') && !empty($bank_account)) {
        $decrypted_account = woo_return_decrypt_data($bank_account);
        if (!empty($decrypted_account)) {
            $display_bank_account = $decrypted_account;
        }
    }

    // Customer details
    $client_info = "
        <strong>" . esc_html__( 'Customer details:', 'return-requests-woo' ) . "</strong><br>
        " . esc_html__( 'First and Last Name:', 'return-requests-woo' ) . " " . esc_html( $first_name ?: $order->get_billing_first_name() ) . " " . esc_html( $last_name ?: $order->get_billing_last_name() ) . "<br>
        " . esc_html__( 'Address:', 'return-requests-woo' ) . " " . esc_html( $order->get_billing_address_1()) . "<br>" . esc_html( $order->get_billing_postcode() . ' ' . $order->get_billing_city() ) . "<br>
        " . esc_html__( 'Phone:', 'return-requests-woo' ) . " " . esc_html( $phone ?: $order->get_billing_phone() ) . "<br>
        " . esc_html__( 'Email:', 'return-requests-woo' ) . " " . esc_html( $order->get_billing_email() );

    // Compliance logic
    $compliance_region = get_option('woo_return_compliance_region', 'eu');
    $terms_text = esc_html__('Terms and Conditions', 'return-requests-woo');
    $privacy_text = esc_html__('Privacy Policy', 'return-requests-woo');
    $compliance_statement = '';

    if ($compliance_region === 'custom') {
        $custom_disclaimer = get_option('woo_return_custom_disclaimer', '');
        $compliance_statement = wp_kses_post($custom_disclaimer);
    } else {
        switch ($compliance_region) {
            case 'pl':
                $compliance_statement = sprintf(
                    /* translators: 1: Terms And Conditions text, 2: Privacy Policy text */
                    wp_kses_post(__('I declare that I have read the %1$s and %2$s and accept their provisions. I hereby exercise my <strong>right of withdrawal</strong> from the distance contract pursuant to <strong>Art. 27 of the Polish Consumer Rights Act of May 30, 2014</strong>. I commit to returning the selected products within <strong>14 days</strong> of the date of this declaration, at my own expense.', 'return-requests-woo')),
                    $terms_text,
                    $privacy_text
                );
                break;
            case 'uk':
                $compliance_statement = sprintf(
                    /* translators: 1: Terms And Conditions text, 2: Privacy Policy text */
                    wp_kses_post(__('I declare that I have read the %1$s and %2$s and accept their provisions. I hereby exercise my <strong>right to cancel</strong> this distance contract under <strong>Regulation 29 of the Consumer Contracts (Information, Cancellation and Additional Charges) Regulations 2013</strong>. I acknowledge I must return the selected products within <strong>14 days</strong> of this notice, at my own expense.', 'return-requests-woo')),
                    $terms_text,
                    $privacy_text
                );
                break;
            case 'ch':
                $compliance_statement = sprintf(
                    /* translators: 1: Terms And Conditions text, 2: Privacy Policy text */
                    wp_kses_post(__('I declare that I have read the %1$s and %2$s and accept their provisions. I acknowledge that Swiss law does not provide a statutory right of withdrawal for online purchases. I am returning the selected products in accordance with the <strong>voluntary return policy</strong> of the seller, as described in the Terms and Conditions, and I accept that return shipping costs are at my own expense.', 'return-requests-woo')),
                    $terms_text,
                    $privacy_text
                );
                break;
            case 'tr':
                $compliance_statement = sprintf(
                    /* translators: 1: Terms And Conditions text, 2: Privacy Policy text */
                    wp_kses_post(__('I declare that I have read the %1$s and %2$s and accept their provisions. I hereby exercise my <strong>right of withdrawal</strong> (<em>cayma hakk&#305;</em>) from this distance contract pursuant to <strong>Law No. 6502 on the Protection of Consumers</strong> and the <strong>Regulation on Distance Contracts</strong>. I acknowledge that I must return the selected products within <strong>14 days</strong> of this notification, at my own expense.', 'return-requests-woo')),
                    $terms_text,
                    $privacy_text
                );
                break;
            case 'ua_md':
                $compliance_statement = sprintf(
                    /* translators: 1: Terms And Conditions text, 2: Privacy Policy text */
                    wp_kses_post(__('I declare that I have read the %1$s and %2$s and accept their provisions. I hereby exercise my right to return the selected products pursuant to the <strong>Law of Ukraine "On Consumer Protection"</strong> (Law No. 1023-XII) / <strong>Law of the Republic of Moldova No. 105/2003 on Consumer Protection</strong>. I acknowledge that I must return the products within <strong>14 days</strong> of receipt, at my own expense, provided the goods are in their original condition.', 'return-requests-woo')),
                    $terms_text,
                    $privacy_text
                );
                break;
            case 'us':
                $compliance_statement = sprintf(
                    /* translators: 1: Terms And Conditions text, 2: Privacy Policy text */
                    wp_kses_post(__('I declare that I have read the %1$s and %2$s and accept their provisions. I am submitting this return request in accordance with the seller\'s <strong>voluntary return policy</strong> as described in the Terms and Conditions. I acknowledge that return shipping costs are at my own expense unless the product is defective or was shipped in error.', 'return-requests-woo')),
                    $terms_text,
                    $privacy_text
                );
                break;
            case 'ca':
                $compliance_statement = sprintf(
                    /* translators: 1: Terms And Conditions text, 2: Privacy Policy text */
                    wp_kses_post(__('I declare that I have read the %1$s and %2$s and accept their provisions. I am submitting this return request in accordance with applicable <strong>provincial consumer protection legislation</strong> (including, where applicable, the Ontario <em>Consumer Protection Act, 2002</em>) and/or the seller\'s return policy as described in the Terms and Conditions. I acknowledge that return shipping costs are at my own expense.', 'return-requests-woo')),
                    $terms_text,
                    $privacy_text
                );
                break;
            case 'au_nz':
                $compliance_statement = sprintf(
                    /* translators: 1: Terms And Conditions text, 2: Privacy Policy text */
                    wp_kses_post(__('I declare that I have read the %1$s and %2$s and accept their provisions. I acknowledge that my statutory rights under the <strong>Australian Consumer Law (Competition and Consumer Act 2010, Schedule 2)</strong> apply in the case of faulty or non-conforming goods. I am submitting this return request in accordance with the seller\'s <strong>voluntary return policy</strong> as described in the Terms and Conditions. Return shipping costs are at my own expense unless the product is faulty.', 'return-requests-woo')),
                    $terms_text,
                    $privacy_text
                );
                break;
            case 'br':
                $compliance_statement = sprintf(
                    /* translators: 1: Terms And Conditions text, 2: Privacy Policy text */
                    wp_kses_post(__('I declare that I have read the %1$s and %2$s and accept their provisions. I hereby exercise my <strong>right of regret</strong> (<em>direito de arrependimento</em>) pursuant to <strong>Art. 49 of the Brazilian Consumer Protection Code (CDC &mdash; Lei n&ordm; 8.078/1990)</strong>. I acknowledge that I must return the selected products within <strong>7 calendar days</strong> of receipt. Return shipping costs are at my own expense unless otherwise agreed.', 'return-requests-woo')),
                    $terms_text,
                    $privacy_text
                );
                break;
            case 'eu':
            default:
                $compliance_statement = sprintf(
                    /* translators: 1: Terms And Conditions text, 2: Privacy Policy text */
                    wp_kses_post(__('I declare that I have read the %1$s and %2$s and accept their provisions. I hereby exercise my <strong>right of withdrawal</strong> from the distance contract pursuant to <strong>Art. 9 of Directive 2011/83/EU</strong> of 25 October 2011 on consumer rights. I acknowledge that I am responsible for returning the selected products within <strong>14 days</strong> of this notification, at my own expense, in accordance with <strong>Art. 14(1)</strong> of the said Directive.', 'return-requests-woo')),
                    $terms_text,
                    $privacy_text
                );
                break;
        }
    }

    $compliance_statement .= ' <br><br> ' . esc_html__( 'I declare that returned goods are unused and in unaltered condition.', 'return-requests-woo' );

    // PDF file content
    $html = '
        <table width="100%">
            <tr>
                <td width="50%">' . $client_info . '</td>
                <td width="50%" align="right">
                    <strong>' . esc_html__( 'Store details:', 'return-requests-woo' ) . '</strong><br>
                    ' . $store_details . '
                </td>
            </tr>
        </table>
        <h1 style="text-align: center;">' . esc_html__( 'Goods Return Protocol', 'return-requests-woo' ) . '</h1>
        <p>' . esc_html__( 'Order date:', 'return-requests-woo' ) . ' ' . esc_html( $order->get_date_created()->date( 'Y-m-d' ) ) . '</p>
        <p>' . esc_html__( 'Form submission date:', 'return-requests-woo' ) . ' ' . esc_html( $submission_date ) . '</p>
        <hr>
        <h3>' . esc_html__( 'Products to return:', 'return-requests-woo' ) . '</h3>
        <table border="1" cellspacing="0" cellpadding="5" width="100%">
            <thead>
                <tr>
                    <th>' . esc_html__( 'No.', 'return-requests-woo' ) . '</th>
                    <th>' . esc_html__( 'Product', 'return-requests-woo' ) . '</th>
                    <th>' . esc_html__( 'Quantity', 'return-requests-woo' ) . '</th>
                    <th>' . esc_html__( 'Price', 'return-requests-woo' ) . '</th>
                </tr>
            </thead>
            <tbody>
                ' . $table_rows . '
            </tbody>
        </table>
        <br>
        <h3>' . esc_html__( 'Return details:', 'return-requests-woo' ) . '</h3>
        <p>' . esc_html__( 'Bank account number:', 'return-requests-woo' ) . ' ' . esc_html( $display_bank_account ) . '</p>
        <hr>
        <p>' . $compliance_statement . '</p>
        <p>' . esc_html__( 'Customer signature:', 'return-requests-woo' ) . ' ________________________</p>
    ';

    // PDF save path
    $upload_dir = wp_upload_dir();
    $pdf_dir = $upload_dir['basedir'] . '/ReturnsPDF';
    
    // Prevent data hijacking (IDOR) - file name is unguessable
    $random_hash = wp_generate_password( 16, false );
    $file_path = $pdf_dir . '/return-' . $order->get_id() . '_' . $random_hash . '.pdf';

    if ( ! file_exists( $pdf_dir ) ) {
        wp_mkdir_p( $pdf_dir );
    }

    // Protection against file downloads (Apache / LiteSpeed) and directory listings.
    // We check it unconditionally to fix instances where the folder was created by an older plugin version without these security files.
    if ( ! file_exists( $pdf_dir . '/.htaccess' ) ) {
        file_put_contents( $pdf_dir . '/.htaccess', 'deny from all' );
    }
    if ( ! file_exists( $pdf_dir . '/index.php' ) ) {
        file_put_contents( $pdf_dir . '/index.php', '<?php // Silence is golden.' );
    }

    $mpdf->WriteHTML( $html );
    $mpdf->Output( $file_path, \Mpdf\Output\Destination::FILE );

    return $file_path;
}