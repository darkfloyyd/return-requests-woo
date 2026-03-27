<?php
if (!defined('ABSPATH')) {
    exit;
}

/* Return form for non-logged-in users with email verification */
function woo_return_email_verification_form()
{
    // Skip execution during AJAX requests or in admin context
    if (wp_doing_ajax() || is_admin()) {
        return '<p>[return_form]</p>';
    }

    // Check if system is disabled
    if (get_option('woo_return_system_enabled', '0') !== '1') {
        return '<div class="woocommerce-info zwrot"><p>' . esc_html__('The return system is currently disabled. Please try again later or contact support.', 'return-requests-woo') . '</p></div>';
    }

    // Collect status message from form processing (must happen before ob_start)
    $status_message = '';

    if (isset($_POST['verify_email_submit'])) {
        // Verify nonce first
        if (!isset($_POST['woo_return_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['woo_return_nonce'])), 'woo_return_email_verification_nonce')) {
            return '<p class="woocommerce-error zwrot">' . esc_html__('Form verification error. Please refresh the page and try again.', 'return-requests-woo') . '</p>';
        }

        // Turnstile server-side validation
        $turnstile_enable = get_option('turnstile_enable');
        $turnstile_secret_key = get_option('turnstile_secret_key');
        if ($turnstile_enable && $turnstile_secret_key) {
            $response = wp_remote_post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
                'body' => [
                    'secret' => $turnstile_secret_key,
                    'response' => sanitize_text_field(wp_unslash($_POST['cf-turnstile-response'] ?? '')),
                ],
            ]);
            $response_body = json_decode(wp_remote_retrieve_body($response), true);
            if (empty($response_body['success']) || !$response_body['success']) {
                return '<p class="woocommerce-error zwrot">' . esc_html__('Turnstile verification failed. Please refresh the page and try again.', 'return-requests-woo') . '</p>';
            }
        }

        $status_message = woo_process_email_verification();
    }

    ob_start();
    ?>
    <?php if (!empty($status_message)) : ?>
    <div role="status" aria-live="polite" id="return-form-status">
        <?php echo wp_kses_post($status_message); ?>
    </div>
    <?php else : ?>
    <div role="status" aria-live="polite" id="return-form-status"></div>
    <?php endif; ?>
    <form id="return-email-verification-form"
          method="post" action="" class="wooReturn verification"
          novalidate
          aria-describedby="return-form-instructions">
        <h1><?php esc_html_e('Return Form', 'return-requests-woo'); ?></h1>
        <?php wp_nonce_field('woo_return_email_verification_nonce', 'woo_return_nonce'); ?>
        <div class='griDiv'>

            <div>
                <label for="email"><?php esc_html_e('Email:', 'return-requests-woo'); ?></label>
                <input type="email" name="email" id="email"
                       required aria-required="true" autocomplete="email">
            </div>

            <div>
                <label for="order_id"><?php esc_html_e('Order number:', 'return-requests-woo'); ?></label>
                <input type="text" name="order_id" id="order_id"
                       required aria-required="true" autocomplete="off">
            </div>

            <?php
            $turnstile_enable = get_option('turnstile_enable');
            $turnstile_site_key = get_option('turnstile_site_key');
            if ($turnstile_enable && $turnstile_site_key) {
                echo '<div class="cf-turnstile woo-return-turnstile" data-size="flexible" data-theme="light" data-sitekey="' . esc_attr($turnstile_site_key) . '"></div>';
            }
            ?>

            <button type="submit" name="verify_email_submit" class="woo-return-submit">
                <?php esc_html_e('Send verification link', 'return-requests-woo'); ?>
            </button>
        </div>
    </form>
    <p id="return-form-instructions" class="returnInfo">
        <?php esc_html_e('E-mail must match the order', 'return-requests-woo'); ?>
    </p>
    <?php

    return ob_get_clean();
}

// Register Shortcodes (English Native)
add_shortcode('return_form', 'woo_return_email_verification_form');

/* Handle email verification — returns HTML string instead of echoing */
function woo_process_email_verification()
{
    // Nonce verification
    if (!isset($_POST['woo_return_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['woo_return_nonce'])), 'woo_return_email_verification_nonce')) {
        return '<p class="woocommerce-error zwrot">' . esc_html__('Form verification error. Please refresh the page and try again.', 'return-requests-woo') . '</p>';
    }

    // Get form data
    $email = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
    $order_id = isset($_POST['order_id']) ? intval(wp_unslash($_POST['order_id'])) : 0;

    // Check attempt limits for a given IP address
    $ip_address = sanitize_text_field(wp_unslash($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? ''));
    $limit_key = 'woo_return_email_verify_attempts_' . md5($ip_address);
    $attempts = get_transient($limit_key);

    if ($attempts === false) {
        $attempts = 1;
        set_transient($limit_key, $attempts, HOUR_IN_SECONDS);  // Reset after an hour
    } else {
        $attempts++;
        set_transient($limit_key, $attempts, HOUR_IN_SECONDS);

        // Limit 10 attempts per hour
        if ($attempts > 10) {
            return '<p class="woocommerce-error zwrot">' . esc_html__('Verification attempts limit exceeded. Please try again in an hour.', 'return-requests-woo') . '</p>';
        }
    }

    $order = wc_get_order($order_id);

    global $wpdb;
    $table_name = $wpdb->prefix . 'woo_returns';

    // $table_name is built from $wpdb->prefix only — no user input.
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
    $existing_record = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM `{$table_name}` WHERE order_id = %d",
            $order_id
        )
    );
    // phpcs:enable

    if ($existing_record > 0) {
        return '<p class="woocommerce-info zwrot">' . esc_html__('Return for this order has already been registered.', 'return-requests-woo') . '</p>';
    }

    // Basic order validation
    if (!$order || $order->get_billing_email() !== $email) {
        return '<p class="woocommerce-info zwrot">' . esc_html__('Provided details do not match the order details.', 'return-requests-woo') . '</p>';
    }

    // Order status validation (must be completed)
    if (!$order->has_status('completed')) {
        return '<p class="woocommerce-info zwrot">' . esc_html__('Only completed orders are eligible for return.', 'return-requests-woo') . '</p>';
    }

    // Time window validation (days to return).
    // Law requires 14 days from the moment the order was completed (delivered).
    // date_completed may be null when admin manually changes the status — fallback to date_modified,
    // which WooCommerce updates on every status change. Final fallback: date_created.
    $window_days = get_option('woo_return_window_days', 14);
    $order_date = $order->get_date_completed() ?? $order->get_date_modified() ?? $order->get_date_created();
    if (!$order_date) {
        return '<p class="woocommerce-info zwrot">' . esc_html__('Order date verification error.', 'return-requests-woo') . '</p>';
    }

    $expiration_timestamp = strtotime($order_date->date('Y-m-d H:i:s') . " + {$window_days} days");
    if (time() > $expiration_timestamp) {
        return '<p class="woocommerce-info zwrot">' . esc_html__('The statutory return period for this order has expired.', 'return-requests-woo') . '</p>';
    }

    // Token generation
    $token = bin2hex(random_bytes(16));
    $expiration = time() + 3600;  // 1 hour

    update_option('woo_return_token_' . $token, array(
        'order_id' => $order_id,
        'email' => $email,
        'expires' => $expiration,
    ));

    // Prepare email data
    $verification_url = add_query_arg(
        array('verify_token' => $token),
        home_url('/' . woo_return_get_slug('items') . '/')  // Trailing slash is important
    );

    // translators: %d represents the order ID
    $subject = sprintf(esc_html__('Verification - Order return #%d', 'return-requests-woo'), $order_id);

    $message = '<p>' . esc_html__('We received a return request for your order. To continue the process, please verify your request by clicking the button below:', 'return-requests-woo') . '</p>';

    // Fallback to `#96588a` (WC default purple) or whatever color the store selected
    $base_color = get_option('woocommerce_email_base_color', '#96588a');
    $message .= '<p style="text-align: center; margin: 30px 0;"><a href="' . esc_url($verification_url) . '" style="display: inline-block; background-color: ' . esc_attr($base_color) . '; color: #ffffff; text-decoration: none; padding: 12px 24px; border-radius: 4px; font-weight: bold; border: 1px solid ' . esc_attr($base_color) . ';">'. esc_html__('I confirm the return', 'return-requests-woo') . '</a></p>';
    $message .= '<p>' . esc_html__('If the button does not work, copy and paste the following link into your browser:', 'return-requests-woo') . '</p>';
    $message .= '<p>' . esc_url($verification_url) . '</p>';
    $message .= '<p>' . esc_html__("If you didn't request an order return, please ignore this message.", 'return-requests-woo') . '</p>';

    // Send email
    if (woo_return_send_email($email, $subject, $message)) {
        return '<p class="woocommerce-info zwrot">' . esc_html__('Verification email sent successfully. Please check your inbox.', 'return-requests-woo') . '</p>';
    } else {
        return '<p class="woocommerce-info zwrot">' . esc_html__('A problem occurred while sending the email. Please try again later.', 'return-requests-woo') . '</p>';
    }
}

/* Handle token verification */
add_action('init', 'woo_return_handle_verification', 1);  // Very low priority to execute first

function woo_return_handle_verification()
{
    // Skip execution during AJAX requests or in admin context
    if (wp_doing_ajax() || is_admin()) {
        return;
    }

    // Check if system is disabled
    if (get_option('woo_return_system_enabled', '0') !== '1') {
        return;
    }

    // Check if verification token is in URL
    if (isset($_GET['verify_token'])) {  // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- email verification token, nonce not applicable
        $token = sanitize_text_field(wp_unslash($_GET['verify_token']));  // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('woo_return_handle_verification: Verification token detected in URL: ' . $token);  // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        }

        // Check token verification limits from given IP address
        $ip_address = sanitize_text_field(wp_unslash($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? ''));
        $limit_key = 'woo_return_token_verify_attempts_' . md5($ip_address);
        $attempts = get_transient($limit_key);

        if ($attempts === false) {
            $attempts = 1;
            set_transient($limit_key, $attempts, HOUR_IN_SECONDS);  // Reset after an hour
        } else {
            $attempts++;
            set_transient($limit_key, $attempts, HOUR_IN_SECONDS);

            // Limit 15 attempts per hour for token verification
            if ($attempts > 15) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('woo_return_handle_verification: Token verification attempt limit exceeded from IP: ' . $ip_address);  // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                }

                add_action('wp_footer', function () {
                    echo '<p class="woocommerce-error zwrot">' . esc_html__('Token verification attempts limit exceeded. Please try again in an hour or contact store support.', 'return-requests-woo') . '</p>';
                });
                return;
            }
        }

        $data = get_option('woo_return_token_' . $token);

        if (!$data || time() > $data['expires']) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('woo_return_handle_verification: Token expired or invalid');  // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            }

            add_action('wp_footer', function () {
                echo '<p class="woocommerce-info zwrot">Link weryfikacyjny wygasł lub jest nieprawidłowy. W celach bezpieczeństwa linki weryfikacyjne są jednorazowe. <a href="' . esc_url(home_url('/' . woo_return_get_slug('form') . '/')) . '">Spróbuj ponownie.</a></p>';
            });
            return;
        }

        // Ensure session is active
        if (session_status() == PHP_SESSION_NONE) {
            session_start(['cookie_lifetime' => 3600]);  // Set longer session cookie lifetime
        }
        session_regenerate_id(true);

        // Create new session token
        $tokenSecurity = bin2hex(random_bytes(16));
        $return_token = wp_generate_password(32, false);

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('woo_return_handle_verification: New session token created: ' . $return_token);  // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        }

        // Save order data in session
        $_SESSION['return_order_' . $return_token] = [
            'order_id' => $data['order_id'],
            'phone' => $data['phone'] ?? '',
            'address' => $data['address'] ?? '',
            'timestamp' => time()
        ];

        // Set session variables
        $_SESSION['verification_security'] = $tokenSecurity;
        $_SESSION['verified_token'] = $return_token;
        $_SESSION['via_form'] = $tokenSecurity;

        // Add flag to session that URL token was processed
        $_SESSION['token_processed'] = $token;

        // Do not delete token immediately so it can be reused on page refresh
        // Instead mark as processed
        $data['processed'] = true;
        update_option('woo_return_token_' . $token, $data, false);

        // Save session changes
        session_write_close();

        // Restart session to ensure access to saved data
        session_start(['cookie_lifetime' => 3600]);

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('woo_return_handle_verification: Session saved, data: ' . json_encode([  // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                'verified_token' => sanitize_text_field($_SESSION['verified_token'] ?? 'none'),
                'token_processed' => sanitize_text_field($_SESSION['token_processed'] ?? 'none')
            ]));
        }
    }
}

/* Helper function handling verification token */
function woo_return_process_verification_token($token)
{
    // This function processes verification token directly
    $data = get_option('woo_return_token_' . $token);

    if (!$data) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('woo_return_process_verification_token: No data found for token: ' . $token);  // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        }
        return false;
    }

    // If token was already processed but still exists in database
    if (isset($data['processed']) && $data['processed']) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('woo_return_process_verification_token: Token already processed');  // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        }
        // Delete token after use
        delete_option('woo_return_token_' . $token);
        return true;
    }

    if (time() > $data['expires']) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('woo_return_process_verification_token: Token expired');  // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        }
        return false;
    }

    // Start or assure session is running
    if (session_status() == PHP_SESSION_NONE) {
        session_start(['cookie_lifetime' => 3600]);
    }

    $tokenSecurity = bin2hex(random_bytes(16));
    $_SESSION['verification_security'] = $tokenSecurity;

    // Save order data in session
    $return_token = wp_generate_password(32, false);
    $_SESSION['return_order_' . $return_token] = [
        'order_id' => $data['order_id'],
        'phone' => $data['phone'] ?? '',
        'address' => $data['address'] ?? '',
        'timestamp' => time()
    ];

    // Set session variables
    $_SESSION['verified_token'] = $return_token;
    $_SESSION['via_form'] = $tokenSecurity;

    // Mark token as processed
    $data['processed'] = true;
    update_option('woo_return_token_' . $token, $data, false);

    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('woo_return_process_verification_token: Token processed successfully, session created with return_token: ' . $return_token);  // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
    }

    return true;
}

/* Item selection form */
function woo_return_item_selection_form()
{
    // Skip execution during AJAX requests // If request came from backend (wp-admin or editor) - show code for testing
    if (is_admin() || wp_doing_ajax()) {
        return '<p>[return_items_form]</p>';
    }

    // Check if system is disabled
    if (get_option('woo_return_system_enabled', '0') !== '1') {
        return '<div class="woocommerce-info zwrot"><p>' . esc_html__('The return system is currently disabled. Please try again later or contact support.', 'return-requests-woo') . '</p></div>';
    }

    // Error message when no parameters are present
    if (!isset($_GET['verify_token']) && !isset($_GET['return_token'])) {
        ob_start();
        echo '<div class="woocommerce-info zwrot">';
        echo '<h3>' . esc_html__('Access parameters missing', 'return-requests-woo') . '</h3>';
        echo '<p>' . esc_html__('To access the return form, you must use the link from the verification email or the "Return Order" button in your customer dashboard.', 'return-requests-woo') . '</p>';
        echo '<p><a href="' . esc_url(home_url('/' . woo_return_get_slug('form') . '/')) . '" class="button">' . esc_html__('Go to return form', 'return-requests-woo') . '</a></p>';
        echo '</div>';
        return ob_get_clean();
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'woo_returns';

    // Start session if not active
    if (session_status() == PHP_SESSION_NONE) {
        session_start(['cookie_lifetime' => 3600]);
    }

    // Check if we came from verification link
    $token_from_url = isset($_GET['verify_token']) ? sanitize_text_field(wp_unslash($_GET['verify_token'])) : '';

    // Initialize return token variable
    $return_token = '';

    // DIRECT URL TOKEN HANDLING - if verify_token exists in URL
    if (!empty($token_from_url)) {
        // Check if token exists in WP options
        $data = get_option('woo_return_token_' . $token_from_url);

        if ($data && is_array($data) && isset($data['order_id'])) {
            // Create new session token
            $return_token = wp_generate_password(32, false);
            $tokenSecurity = bin2hex(random_bytes(16));

            $order = wc_get_order($data['order_id']);
            $phone = $data['phone'] ?? '';
            $address = $data['address'] ?? '';

            if ($order) {
                if (empty($phone)) {
                    $phone = $order->get_billing_phone();
                    if (empty($phone)) {
                        $phone = get_user_meta($order->get_user_id(), 'billing_phone', true);
                    }
                }
                if (empty($address)) {
                    $address = trim($order->get_billing_address_1() . ' ' . $order->get_billing_address_2());
                    if (empty($address)) {
                        $address = trim(get_user_meta($order->get_user_id(), 'billing_address_1', true) . ' ' . get_user_meta($order->get_user_id(), 'billing_address_2', true));
                    }
                }
            }

            // Save data in session
            $_SESSION['return_order_' . $return_token] = [
                'order_id' => $data['order_id'],
                'phone' => $phone,
                'address' => $address,
                'timestamp' => time()
            ];

            $_SESSION['verification_security'] = $tokenSecurity;
            $_SESSION['verified_token'] = $return_token;
            $_SESSION['via_form'] = $tokenSecurity;

            // Delete token after use
            delete_option('woo_return_token_' . $token_from_url);
        } else {
            // Check if token was already processed and data is in session
            if (isset($_SESSION['verified_token'])) {
                $return_token = sanitize_text_field($_SESSION['verified_token']);
            } else {
                // Nicer error message
                ob_start();
                echo '<div class="woocommerce-info zwrot">';
                echo '<h3>' . esc_html__('Verification link has expired or is invalid', 'return-requests-woo') . '</h3>';
                echo '<p>' . esc_html__('For security purposes, verification links are one-time use and expire one hour after generation.', 'return-requests-woo') . '</p>';
                echo '<p><a href="' . esc_url(home_url('/' . woo_return_get_slug('form') . '/')) . '" class="button">' . esc_html__('Generate new link', 'return-requests-woo') . '</a></p>';
                echo '</div>';
                return ob_get_clean();
            }
        }
    } else if (isset($_SESSION['verified_token'])) {
        // We came after redirect and have data in session
        $return_token = sanitize_text_field($_SESSION['verified_token']);
    } else if (isset($_GET['return_token'])) {
        // Standard approach with return_token parameter in URL
        $return_token = sanitize_text_field(wp_unslash($_GET['return_token']));
    }

    // Check if we have session data for this token
    $session_data = $_SESSION['return_order_' . $return_token] ?? null;  // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- session data written by this plugin

    if (!$session_data) {
        // Nicer error message
        ob_start();
        echo '<div class="woocommerce-info zwrot">';
        echo '<h3>' . esc_html__('Session expired or invalid', 'return-requests-woo') . '</h3>';
        echo '<p>' . esc_html__('Your session may have expired or the link format is invalid. Please try again.', 'return-requests-woo') . '</p>';
        echo '<p><a href="' . esc_url(home_url('/' . woo_return_get_slug('form') . '/')) . '" class="button">' . esc_html__('Generate new link', 'return-requests-woo') . '</a></p>';
        echo '</div>';
        return ob_get_clean();
    }

    // Rest of the code remains unchanged
    $order_id = intval($session_data['order_id']);
    $phone = sanitize_text_field($session_data['phone']);
    $address = sanitize_textarea_field($session_data['address']);
    $contact_email = get_option('woo_return_contact_email', 'wordpress@' . wp_parse_url(home_url(), PHP_URL_HOST));
    $token = $token_from_url;  // Use original token from URL

    // $table_name is built from $wpdb->prefix only — no user input.
    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
    $existing_record = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM `{$table_name}` WHERE order_id = %d",
            $order_id
        )
    );
    // phpcs:enable

    if ($existing_record > 0) {
        ob_start();
        echo '<div class="woocommerce-info zwrot">';
        echo '<h3>' . esc_html__('Return already registered', 'return-requests-woo') . '</h3>';
        echo '<p>' . esc_html__('Return for this order has already been registered. If you have any questions, please contact customer service.', 'return-requests-woo') . '</p>';
        echo '</div>';
        return ob_get_clean();
    }

    if (!$order_id || empty($address)) {
        ob_start();
        echo '<div class="woocommerce-info zwrot">';
        echo '<h3>' . esc_html__('Missing required contact details', 'return-requests-woo') . '</h3>';
        echo '<p>' . esc_html__('Missing required contact details: address.', 'return-requests-woo') . '</p>';
        echo '</div>';
        return ob_get_clean();
    }

    $order = wc_get_order($order_id);

    if (!$order) {
        ob_start();
        echo '<div class="woocommerce-info zwrot">';
        echo '<h3>' . esc_html__('Order not found', 'return-requests-woo') . '</h3>';
        echo '<p>' . esc_html__('Could not find an order with the provided number. Please check your order number.', 'return-requests-woo') . '</p>';
        echo '<p><a href="' . esc_url(home_url('/' . woo_return_get_slug('form') . '/')) . '" class="button">' . esc_html__('Try again', 'return-requests-woo') . '</a></p>';
        echo '</div>';
        return ob_get_clean();
    }

    $items = $order->get_items();

    ob_start();
    ?>
    <form method="post" action="" class="wooReturn selection" aria-label="<?php esc_attr_e('Product Selection Form for Return', 'return-requests-woo'); ?>">
        <?php wp_nonce_field('woo_return_item_selection_nonce', 'woo_return_nonce'); ?>
        <input type="hidden" name="verify_token" value="<?php echo esc_attr($token); ?>">
        <input type="hidden" name="return_token" value="<?php echo esc_attr($return_token); ?>">

        <fieldset class="woo-return-items-fieldset">
            <legend><?php esc_html_e('Select products to return:', 'return-requests-woo'); ?></legend>
            <?php foreach ($items as $item_id => $item): ?>
                <label class="productOptionToReturn">
                    <input type="checkbox" name="items[]" value="<?php echo esc_attr($item_id); ?>">
                    <span><?php echo esc_html($item->get_name()); ?>
                        (<?php esc_html_e('Quantity:', 'return-requests-woo'); ?> <?php echo esc_html($item->get_quantity()); ?>)
                    </span>
                </label><br>
            <?php endforeach; ?>
        </fieldset>

        <!-- Bank account number field -->
        <h3><?php esc_html_e('Enter bank account number for refund:', 'return-requests-woo'); ?></h3>
        <label for="bank_account"><?php esc_html_e('Bank account number:', 'return-requests-woo'); ?></label>
        <?php
        $bank_format = woo_return_get_bank_format();
        $bank_pattern = '';
        if ($bank_format === 'iban') {
            $bank_placeholder = esc_attr__('PL12 3456 7890 1234 5678 9012 3456', 'return-requests-woo');
            $bank_hint = esc_html__('Account number should contain country code and up to 34 alphanumeric characters', 'return-requests-woo');
        } elseif ($bank_format === 'disabled') {
            $bank_placeholder = esc_attr__('Enter bank account number', 'return-requests-woo');
            $bank_hint = esc_html__('Please enter your valid bank account number', 'return-requests-woo');
        } else {
            // Default "polish"
            $bank_placeholder = esc_attr__('12 3456 7890 1234 5678 9012 3456', 'return-requests-woo');
            $bank_pattern = '^[0-9]{2}[ ]?[0-9]{4}[ ]?[0-9]{4}[ ]?[0-9]{4}[ ]?[0-9]{4}[ ]?[0-9]{4}[ ]?[0-9]{4}$';
            $bank_hint = esc_html__('Account number should contain exactly 26 digits', 'return-requests-woo');
        }
        ?>
        <input type="text" name="bank_account" id="bank_account"
               required aria-required="true"
               aria-describedby="bank-account-hint"
               placeholder="<?php echo esc_attr($bank_placeholder); ?>"
               data-format="<?php echo esc_attr($bank_format); ?>"
               maxlength="40"
               <?php if (!empty($bank_pattern)) echo 'pattern="' . esc_attr($bank_pattern) . '"'; ?>>
        <p id="bank-account-hint" class="description">
            <?php echo esc_html($bank_hint); ?>
        </p>

        <!-- Disclaimer -->
        <div class="disclaimer">
            <?php
            $compliance_region = get_option('woo_return_compliance_region', 'eu');
            $custom_disclaimer = get_option('woo_return_custom_disclaimer', '');

            $terms_link = '<a href="/regulamin/" target="_blank">' . esc_html__('Terms and Conditions', 'return-requests-woo') . '</a>';
            $privacy_link = '<a href="/polityka-prywatnosci/" target="_blank">' . esc_html__('Privacy Policy', 'return-requests-woo') . '</a>';

            if ($compliance_region === 'custom' && !empty($custom_disclaimer)) {
                echo wp_kses_post($custom_disclaimer);
            } else {
                echo '<p>';
                switch ($compliance_region) {
                    case 'pl':
                        echo wp_kses_post( sprintf(
                            /* translators: 1: Terms And Conditions link, 2: Privacy Policy link */
                            __('By clicking "Return selected items", I declare that I have read the %1$s and %2$s and accept their provisions. I hereby exercise my <strong>right of withdrawal</strong> from the distance contract pursuant to <strong>Art. 27 of the Polish Consumer Rights Act of May 30, 2014</strong>. I commit to returning the selected products within <strong>14 days</strong> of the date of this declaration, at my own expense.', 'return-requests-woo'),
                            $terms_link,
                            $privacy_link
                        ) );
                        break;
                    case 'uk':
                        echo wp_kses_post( sprintf(
                            /* translators: 1: Terms And Conditions link, 2: Privacy Policy link */
                            __('By clicking "Return selected items", I declare that I have read the %1$s and %2$s and accept their provisions. I hereby exercise my <strong>right to cancel</strong> this distance contract under <strong>Regulation 29 of the Consumer Contracts (Information, Cancellation and Additional Charges) Regulations 2013</strong>. I acknowledge I must return the selected products within <strong>14 days</strong> of this notice, at my own expense.', 'return-requests-woo'),
                            $terms_link,
                            $privacy_link
                        ) );
                        break;
                    case 'ch':
                        echo wp_kses_post( sprintf(
                            /* translators: 1: Terms And Conditions link, 2: Privacy Policy link */
                            __('By clicking "Return selected items", I declare that I have read the %1$s and %2$s and accept their provisions. I acknowledge that Swiss law does not provide a statutory right of withdrawal for online purchases. I am returning the selected products in accordance with the <strong>voluntary return policy</strong> of the seller, as described in the Terms and Conditions, and I accept that return shipping costs are at my own expense.', 'return-requests-woo'),
                            $terms_link,
                            $privacy_link
                        ) );
                        break;
                    case 'tr':
                        echo wp_kses_post( sprintf(
                            /* translators: 1: Terms And Conditions link, 2: Privacy Policy link */
                            __('By clicking "Return selected items", I declare that I have read the %1$s and %2$s and accept their provisions. I hereby exercise my <strong>right of withdrawal</strong> (<em>cayma hakk&#305;</em>) from this distance contract pursuant to <strong>Law No. 6502 on the Protection of Consumers</strong> and the <strong>Regulation on Distance Contracts</strong>. I acknowledge that I must return the selected products within <strong>14 days</strong> of this notification, at my own expense.', 'return-requests-woo'),
                            $terms_link,
                            $privacy_link
                        ) );
                        break;
                    case 'ua_md':
                        echo wp_kses_post( sprintf(
                            /* translators: 1: Terms And Conditions link, 2: Privacy Policy link */
                            __('By clicking "Return selected items", I declare that I have read the %1$s and %2$s and accept their provisions. I hereby exercise my right to return the selected products pursuant to the <strong>Law of Ukraine "On Consumer Protection"</strong> (Law No. 1023-XII) / <strong>Law of the Republic of Moldova No. 105/2003 on Consumer Protection</strong>. I acknowledge that I must return the products within <strong>14 days</strong> of receipt, at my own expense, provided the goods are in their original condition.', 'return-requests-woo'),
                            $terms_link,
                            $privacy_link
                        ) );
                        break;
                    case 'us':
                        echo wp_kses_post( sprintf(
                            /* translators: 1: Terms And Conditions link, 2: Privacy Policy link */
                            __('By clicking "Return selected items", I declare that I have read the %1$s and %2$s and accept their provisions. I am submitting this return request in accordance with the seller\'s <strong>voluntary return policy</strong> as described in the Terms and Conditions. I acknowledge that return shipping costs are at my own expense unless the product is defective or was shipped in error.', 'return-requests-woo'),
                            $terms_link,
                            $privacy_link
                        ) );
                        break;
                    case 'ca':
                        echo wp_kses_post( sprintf(
                            /* translators: 1: Terms And Conditions link, 2: Privacy Policy link */
                            __('By clicking "Return selected items", I declare that I have read the %1$s and %2$s and accept their provisions. I am submitting this return request in accordance with applicable <strong>provincial consumer protection legislation</strong> (including, where applicable, the Ontario <em>Consumer Protection Act, 2002</em>) and/or the seller\'s return policy as described in the Terms and Conditions. I acknowledge that return shipping costs are at my own expense.', 'return-requests-woo'),
                            $terms_link,
                            $privacy_link
                        ) );
                        break;
                    case 'au_nz':
                        echo wp_kses_post( sprintf(
                            /* translators: 1: Terms And Conditions link, 2: Privacy Policy link */
                            __('By clicking "Return selected items", I declare that I have read the %1$s and %2$s and accept their provisions. I acknowledge that my statutory rights under the <strong>Australian Consumer Law (Competition and Consumer Act 2010, Schedule 2)</strong> apply in the case of faulty or non-conforming goods. I am submitting this return request in accordance with the seller\'s <strong>voluntary return policy</strong> as described in the Terms and Conditions. Return shipping costs are at my own expense unless the product is faulty.', 'return-requests-woo'),
                            $terms_link,
                            $privacy_link
                        ) );
                        break;
                    case 'br':
                        echo wp_kses_post( sprintf(
                            /* translators: 1: Terms And Conditions link, 2: Privacy Policy link */
                            __('By clicking "Return selected items", I declare that I have read the %1$s and %2$s and accept their provisions. I hereby exercise my <strong>right of regret</strong> (<em>direito de arrependimento</em>) pursuant to <strong>Art. 49 of the Brazilian Consumer Protection Code (CDC &mdash; Lei n&ordm; 8.078/1990)</strong>. I acknowledge that I must return the selected products within <strong>7 calendar days</strong> of receipt. Return shipping costs are at my own expense unless otherwise agreed.', 'return-requests-woo'),
                            $terms_link,
                            $privacy_link
                        ) );
                        break;
                    case 'eu':
                    default:
                        echo wp_kses_post( sprintf(
                            /* translators: 1: Terms And Conditions link, 2: Privacy Policy link */
                            __('By clicking "Return selected items", I declare that I have read the %1$s and %2$s and accept their provisions. I hereby exercise my <strong>right of withdrawal</strong> from the distance contract pursuant to <strong>Art. 9 of Directive 2011/83/EU</strong> of 25 October 2011 on consumer rights. I acknowledge that I am responsible for returning the selected products within <strong>14 days</strong> of this notification, at my own expense, in accordance with <strong>Art. 14(1)</strong> of the said Directive.', 'return-requests-woo'),
                            $terms_link,
                            $privacy_link
                        ) );
                        break;
                }
                echo '</p>';
            }
            ?>
            <p>
                <?php esc_html_e('I acknowledge that the refund will be processed exclusively to the provided bank account number.', 'return-requests-woo'); ?>
            </p>
            <p>
                <?php esc_html_e('If you have any questions or concerns regarding the return, please contact our customer service at', 'return-requests-woo'); ?>
                <a href="mailto:<?php echo esc_attr($contact_email); ?>"><?php echo esc_html($contact_email); ?></a>.
            </p>
            <p>
                <?php 
                if (get_option('woo_return_gdpr_compliant', '0') === '1') {
                    esc_html_e('Please be informed that personal data processing complies with GDPR regulations and is based on', 'return-requests-woo');
                } else {
                    esc_html_e('Please be informed that personal data processing is based on', 'return-requests-woo');
                }
                ?>
                <a href="/polityka-prywatnosci/" target="_blank"><?php esc_html_e('Privacy policy', 'return-requests-woo'); ?></a>.
            </p>
        </div>

        <div class="woo-return-submit-row">
            <?php
            $turnstile_enable = get_option('turnstile_enable');
            $turnstile_site_key = get_option('turnstile_site_key');
            if ($turnstile_enable && $turnstile_site_key) {
                echo '<div class="cf-turnstile woo-return-turnstile" data-size="flexible" data-theme="light" data-sitekey="' . esc_attr($turnstile_site_key) . '"></div>';
            }
            ?>
            <button type="submit" name="submit_return_items" class="woo-return-submit-btn">
                <?php esc_html_e('Return selected items', 'return-requests-woo'); ?>
            </button>
        </div>
    </form>
    <?php

    if (isset($_POST['submit_return_items'])) {
        // Weryfikacja nonce
        if (!isset($_POST['woo_return_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['woo_return_nonce'])), 'woo_return_item_selection_nonce')) {
            echo '<p class="woocommerce-error zwrot">' . esc_html__('Form verification error. Please refresh the page and try again.', 'return-requests-woo') . '</p>';
            return '';
        }

        // Turnstile server-side validation
        $turnstile_enable = get_option('turnstile_enable');
        $turnstile_secret_key = get_option('turnstile_secret_key');
        if ($turnstile_enable && $turnstile_secret_key) {
            $response = wp_remote_post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
                'body' => [
                    'secret' => $turnstile_secret_key,
                    'response' => sanitize_text_field(wp_unslash($_POST['cf-turnstile-response'] ?? '')),
                ],
            ]);
            $response_body = json_decode(wp_remote_retrieve_body($response), true);
            if (empty($response_body['success']) || !$response_body['success']) {
                echo '<p class="woocommerce-error zwrot">' . esc_html__('Turnstile verification failed. Please refresh the page and try again.', 'return-requests-woo') . '</p>';
                return '';
            }
        }

        woo_process_selected_items();
    }

    $output = ob_get_clean();
    return $output;
}

add_shortcode('return_items_form', 'woo_return_item_selection_form');

/* Handle selected items */
function woo_process_selected_items()
{
    // Nonce verification
    if (!isset($_POST['woo_return_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['woo_return_nonce'])), 'woo_return_item_selection_nonce')) {
        echo '<p class="woocommerce-error zwrot">' . esc_html__('Form verification error. Please refresh the page and try again.', 'return-requests-woo') . '</p>';

        return;
    }

    if (session_status() == PHP_SESSION_NONE) {
        session_start(['cookie_lifetime' => 3600]);
    }

    // Get session data
    $return_token = isset($_POST['return_token']) ? sanitize_text_field(wp_unslash($_POST['return_token'])) : '';
    $session_data = $_SESSION['return_order_' . $return_token] ?? null;  // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- session data written by this plugin

    if (!$session_data) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('woo_process_selected_items: No session data for token: ' . $return_token);  // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        }
        echo '<p class="woocommerce-info zwrot">' . wp_kses_post(__('Invalid return token or session expired.', 'return-requests-woo')) . ' <a href="' . esc_url(home_url('/' . woo_return_get_slug('form') . '/')) . '">' . esc_html__('Try again.', 'return-requests-woo') . '</a></p>';

        return;
    }

    // Get data from session and POST
    $order_id = intval($session_data['order_id']);
    $phone = sanitize_text_field($session_data['phone']);
    $address = sanitize_textarea_field($session_data['address']);
    $bank_account = isset($_POST['bank_account']) ? sanitize_text_field(wp_unslash($_POST['bank_account'])) : '';
    $selected_items = isset($_POST['items']) && is_array($_POST['items']) ? array_map('sanitize_text_field', wp_unslash($_POST['items'])) : [];

    // Get order
    $order = wc_get_order($order_id);

    if (!$order || empty($selected_items) || empty($bank_account)) {
        echo '<p class="woocommerce-info zwrot">' . esc_html__('Missing required data. Ensure products for return are selected and bank account number is provided.', 'return-requests-woo') . '</p>';
        return;
    }

    // Prevent IDOR - validate if selected product IDs belong to this order
    $valid_item_ids = array_keys($order->get_items());
    foreach ($selected_items as $item_id) {
        if (!in_array($item_id, $valid_item_ids)) {
            if (function_exists('woo_return_log_security_event')) {
                woo_return_log_security_event('idor_attempt', 'Attempt to return items not belonging to order ' . $order_id, 'critical');
            }
            wp_die(esc_html__('Unauthorized operation. Product identity verification failed.', 'return-requests-woo'));
        }
    }

    // Bank account number validation
    $bank_format = woo_return_get_bank_format();
    $clean_account = preg_replace('/\s+/', '', $bank_account);
    $error_message = '';

    if ($bank_format === 'iban') {
        if (strlen($clean_account) < 15 || strlen($clean_account) > 34 || !preg_match('/^[A-Z]{2}[A-Z0-9]+$/i', $clean_account)) {
            $error_message = esc_html__('Invalid IBAN format. Please check your international bank account number.', 'return-requests-woo');
        } else {
            // Format IBAN for saving (groups of 4)
            $clean_account = strtoupper($clean_account);
            $formatted_account = '';
            for ($i = 0; $i < strlen($clean_account); $i += 4) {
                $formatted_account .= substr($clean_account, $i, 4);
                if ($i + 4 < strlen($clean_account)) {
                    $formatted_account .= ' ';
                }
            }
            $bank_account = $formatted_account;
        }
    } elseif ($bank_format === 'disabled') {
        if (empty($clean_account)) {
            $error_message = esc_html__('Bank account number cannot be empty.', 'return-requests-woo');
        } else {
            // Keep original spacing for disabled validation
            $bank_account = trim(sanitize_text_field($bank_account));
        }
    } else {
        // Default "polish"
        if (strlen($clean_account) !== 26 || !preg_match('/^\d+$/', $clean_account)) {
            $error_message = esc_html__('Invalid bank account number format. Number must contain 26 digits.', 'return-requests-woo');
        } else {
            // Format Polish account for saving: 12 3456 7890 ...
            $formatted_account = substr($clean_account, 0, 2) . ' ';
            for ($i = 2; $i < strlen($clean_account); $i += 4) {
                $formatted_account .= substr($clean_account, $i, 4);
                if ($i + 4 < strlen($clean_account)) {
                    $formatted_account .= ' ';
                }
            }
            $bank_account = $formatted_account;
        }
    }

    if (!empty($error_message)) {
        echo '<p class="woocommerce-info zwrot">' . esc_html($error_message) . '</p>';
        return;
    }

    // Encrypt bank account number before saving to database
    if (function_exists('woo_return_encrypt_data')) {
        $encrypted_bank_account = woo_return_encrypt_data($bank_account);
        if (!empty($encrypted_bank_account)) {
            $bank_account = $encrypted_bank_account;
        } else {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('WooReturn: Failed to encrypt bank account number.');  // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            }
        }
    } else {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('WooReturn: Encryption function does not exist - saved bank account number in unencrypted form.');  // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        }
    }

    // Verify contact details
    if (empty($address)) {
        echo '<p class="woocommerce-info zwrot">' . esc_html__('Missing required contact details: address.', 'return-requests-woo') . '</p>';
        return;
    }

    try {
        // Generate PDF
        $file_path = woo_return_generate_pdf($order, '', '', $selected_items, $bank_account, $phone, $address);

        // Add record to database
        global $wpdb;
        $table_name = $wpdb->prefix . 'woo_returns';

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $insert_result = $wpdb->insert(
            $table_name,
            array(
                'order_id' => $order->get_id(),
                'customer_name' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
                'customer_email' => $order->get_billing_email(),
                'bank_account' => $bank_account,
                'pdf_path' => $file_path,
            ),
            array(
                '%d',
                '%s',
                '%s',
                '%s',
                '%s',
            )
        );

        if ($wpdb->last_error) {
            throw new Exception('Database error: ' . $wpdb->last_error);
        }

        // Now we can safely delete session data
        // Clear all session variables related to token
        unset($_SESSION['return_order_' . $return_token]);
        unset($_SESSION['verified_token']);
        unset($_SESSION['via_form']);
        unset($_SESSION['verification_security']);
        unset($_SESSION['token_processed']);

        // Save session changes
        if (session_status() == PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        // Send PDF via email
        $email_result = woo_return_send_email_pdf($order, $file_path, $order->get_billing_email());

        if (!$email_result) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('WooReturn: Failed to send return confirmation email.');  // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            }
        }

        // Generate short-lived confirmation token so the confirmation page
        // can securely show order details without relying on the now-cleared session.
        $conf_token = substr(
            hash_hmac('sha256', $order->get_id() . '|' . $order->get_billing_email(), defined('AUTH_KEY') ? AUTH_KEY : wp_salt()),
            0, 32
        );
        set_transient('woo_return_confirmation_' . $conf_token, $order->get_id(), DAY_IN_SECONDS);

        // Redirect to thank you page with confirmation token in URL
        wp_safe_redirect(
            add_query_arg('ct', rawurlencode($conf_token), home_url('/' . woo_return_get_slug('confirm') . '/'))
        );
        exit;
    } catch (Exception $e) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('Błąd podczas przetwarzania zwrotu: ' . $e->getMessage());  // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        }

        echo '<p class="woocommerce-error zwrot">' . esc_html__('An error occurred while processing the return:', 'return-requests-woo') . ' ' . esc_html($e->getMessage()) . '. ' . esc_html__('Try again or contact store support.', 'return-requests-woo') . '</p>';
        return;
    }
}

/* [return_confirmation] shortcode — securely shows return summary + company address */
function woo_return_confirmation_message()
{
    // Admin / AJAX context: return a descriptive preview placeholder
    if (is_admin() || wp_doing_ajax()) {
        $company_name    = esc_html(get_option('woo_return_company_name',    get_bloginfo('name')));
        $company_street  = esc_html(get_option('woo_return_company_street',  ''));
        $company_city    = esc_html(get_option('woo_return_company_city',    ''));
        $company_country = esc_html(get_option('woo_return_company_country', ''));
        $company_extra   = esc_html(get_option('woo_return_company_extra',   ''));

        ob_start();
        ?>
        <div class="woo-return-confirmation">
            <p><strong><?php esc_html_e('Return Confirmation — live preview', 'return-requests-woo'); ?></strong></p>
            <p><?php esc_html_e('On the frontend, this page displays the return summary and the address below after a return is successfully submitted.', 'return-requests-woo'); ?></p>
            <hr>
            <div class="woo-return-confirmation-section">
                <strong><?php esc_html_e('Send your package to:', 'return-requests-woo'); ?></strong><br>
                <address style="font-style:normal; margin-top:6px; line-height:1.7">
                    <?php if ($company_name)    : ?><strong><?php echo $company_name; ?></strong><br><?php endif; ?>
                    <?php if ($company_street)  : ?><?php echo $company_street; ?><br><?php endif; ?>
                    <?php if ($company_city)    : ?><?php echo $company_city; ?><br><?php endif; ?>
                    <?php if ($company_country) : ?><?php echo $company_country; ?><br><?php endif; ?>
                    <?php if ($company_extra)   : ?><em><?php echo $company_extra; ?></em><?php endif; ?>
                </address>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    // ══════════════════════════════════════════════════════════════
    // FRONTEND — verify access then render full confirmation
    // ══════════════════════════════════════════════════════════════

    $order_id   = 0;
    $authorized = false;

    // Auth path 1: short-lived HMAC confirmation token (?ct=...) — guest flow after redirect
    if (isset($_GET['ct'])) {  // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display; ct is a server-generated HMAC, not user-controlled input
        $ct              = sanitize_text_field(wp_unslash($_GET['ct']));
        $stored_order_id = get_transient('woo_return_confirmation_' . $ct);
        if (!empty($stored_order_id)) {
            $order_id   = absint($stored_order_id);
            $authorized = true;
        }
    }

    // Auth path 2: logged-in user whose email matches the order billing email
    if (!$authorized && is_user_logged_in() && isset($_GET['order_id'])) {  // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display
        $maybe_id = absint(wp_unslash($_GET['order_id']));
        $chk      = wc_get_order($maybe_id);
        if ($chk && $chk->get_billing_email() === wp_get_current_user()->user_email) {
            $order_id   = $maybe_id;
            $authorized = true;
        }
    }

    if (!$authorized || !$order_id) {
        return '<p class="woocommerce-info zwrot">' .
               esc_html__('Return confirmation not found or the link has expired.', 'return-requests-woo') .
               ' <a href="' . esc_url(home_url('/' . woo_return_get_slug('form') . '/')) . '">' .
               esc_html__('Start a new return.', 'return-requests-woo') . '</a></p>';
    }

    $order = wc_get_order($order_id);
    if (!$order) {
        return '<p class="woocommerce-error zwrot">' . esc_html__('Order not found.', 'return-requests-woo') . '</p>';
    }

    // Company address from settings (fallbacks to WC store settings)
    $company_name    = esc_html(get_option('woo_return_company_name',    get_bloginfo('name')));
    $company_street  = esc_html(get_option('woo_return_company_street',  ''));
    $company_city    = esc_html(get_option('woo_return_company_city',    ''));
    $company_country = esc_html(get_option('woo_return_company_country', ''));
    $company_extra   = esc_html(get_option('woo_return_company_extra',   ''));

    ob_start();
    ?>
    <div class="woo-return-confirmation">
        <h2><?php esc_html_e('Return registered successfully', 'return-requests-woo'); ?></h2>

        <div class="woo-return-confirmation-section">
            <h3><?php esc_html_e('Order summary', 'return-requests-woo'); ?></h3>
            <p><strong><?php esc_html_e('Order number:', 'return-requests-woo'); ?></strong>
                #<?php echo esc_html($order->get_order_number()); ?></p>
            <p><strong><?php esc_html_e('Customer:', 'return-requests-woo'); ?></strong>
                <?php echo esc_html($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()); ?></p>
            <p><strong><?php esc_html_e('Email:', 'return-requests-woo'); ?></strong>
                <?php echo esc_html($order->get_billing_email()); ?></p>
        </div>

        <div class="woo-return-confirmation-section">
            <h3><?php esc_html_e('Send your package to:', 'return-requests-woo'); ?></h3>
            <address style="font-style:normal; line-height:1.8;">
                <?php if ($company_name)    : ?><strong><?php echo $company_name; ?></strong><br><?php endif; ?>
                <?php if ($company_street)  : ?><?php echo $company_street; ?><br><?php endif; ?>
                <?php if ($company_city)    : ?><?php echo $company_city; ?><br><?php endif; ?>
                <?php if ($company_country) : ?><?php echo $company_country; ?><br><?php endif; ?>
                <?php if ($company_extra)   : ?><em><?php echo $company_extra; ?></em><?php endif; ?>
            </address>
        </div>

        <p style="margin-top:24px;">
            <a href="<?php echo esc_url(wc_get_account_endpoint_url('orders')); ?>" class="button">
                <?php esc_html_e('Back to My Account', 'return-requests-woo'); ?>
            </a>
        </p>
    </div>
    <?php
    return ob_get_clean();
}

add_shortcode('return_confirmation', 'woo_return_confirmation_message');

