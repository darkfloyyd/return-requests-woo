<?php
if (!defined('ABSPATH')) {
    exit;
}

function woo_return_main_page()
{
    global $wpdb;

    // Returns table
    $table_name = $wpdb->prefix . 'woo_returns';

    // Handle deletion of records older than a year
    if (isset($_POST['delete_old_records'])) {
        check_admin_referer('woo_return_cleanup_nonce');
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $wpdb->query($wpdb->prepare(
            "DELETE FROM `{$table_name}` WHERE created_at < DATE_SUB(NOW(), INTERVAL %d YEAR)",
            1
        ));
        // phpcs:enable
        echo '<div class="updated"><p>' . esc_html__('Records older than a year have been deleted.', 'return-requests-woo') . '</p></div>';
    }

    // Handle deletion of all records after confirmation
    if (isset($_POST['delete_all_records'])) {
        check_admin_referer('woo_return_cleanup_nonce');
        if (isset($_POST['confirm_delete']) && sanitize_text_field(wp_unslash($_POST['confirm_delete'])) === 'potwierdzam') {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table_name is safely built from $wpdb->prefix
            $wpdb->query("TRUNCATE TABLE `{$table_name}`");  // Table name is internally built from $wpdb->prefix, no user input.
            echo '<div class="updated"><p>' . esc_html__('All records have been deleted.', 'return-requests-woo') . '</p></div>';
        } else {
            echo '<div class="error"><p>' . esc_html__('You must type "confirm" to delete all records.', 'return-requests-woo') . '</p></div>';
        }
    }

    // Handle saving pages settings
    if (isset($_POST['woo_return_save_page_settings'])) {
        check_admin_referer('woo_return_page_settings_nonce');
        update_option('woo_return_slug_form', sanitize_title(wp_unslash($_POST['woo_return_slug_form'] ?? 'formularz-zwrotu')));
        update_option('woo_return_slug_items', sanitize_title(wp_unslash($_POST['woo_return_slug_items'] ?? 'wybierz-przedmioty-do-zwrotu')));
        update_option('woo_return_slug_confirm', sanitize_title(wp_unslash($_POST['woo_return_slug_confirm'] ?? 'zwrot-potwierdzony')));
        update_option('woo_return_window_days', absint($_POST['woo_return_window_days'] ?? 14));
        update_option('woo_return_bank_format', sanitize_text_field(wp_unslash($_POST['woo_return_bank_format'] ?? 'polish')));
        echo '<div class="notice notice-success"><p>' . esc_html__('Pages settings saved.', 'return-requests-woo') . '</p></div>';
    }

    // Handle checking and creating required pages
    if (isset($_POST['check_create_pages'])) {
        check_admin_referer('woo_return_pages_nonce');
        $created_pages = woo_return_check_and_create_pages();
        if (empty($created_pages)) {
            echo '<div class="updated"><p>' . esc_html__('All required pages already exist.', 'return-requests-woo') . '</p></div>';
        } else {
            echo '<div class="updated"><p>' . sprintf(
                /* translators: %s: comma-separated list of created page titles */
                esc_html__('The following pages were created: %s.', 'return-requests-woo'),
                esc_html(implode(', ', $created_pages))
            ) . '</p></div>';
        }
    }

    // Handle saving email settings
    if (isset($_POST['woo_return_save_settings'])) {
        check_admin_referer('woo_return_email_settings_nonce');
        update_option('woo_return_admin_email', isset($_POST['woo_return_admin_email']) ? sanitize_email(wp_unslash($_POST['woo_return_admin_email'])) : '');
        update_option('woo_return_from_email', isset($_POST['woo_return_from_email']) ? sanitize_email(wp_unslash($_POST['woo_return_from_email'])) : '');
        update_option('woo_return_customer_subject', isset($_POST['woo_return_customer_subject']) ? sanitize_text_field(wp_unslash($_POST['woo_return_customer_subject'])) : '');
        update_option('woo_return_admin_subject', isset($_POST['woo_return_admin_subject']) ? sanitize_text_field(wp_unslash($_POST['woo_return_admin_subject'])) : '');
        update_option('woo_return_customer_message', isset($_POST['woo_return_customer_message']) ? wp_kses_post(wp_unslash($_POST['woo_return_customer_message'])) : '');
        update_option('woo_return_admin_message', isset($_POST['woo_return_admin_message']) ? wp_kses_post(wp_unslash($_POST['woo_return_admin_message'])) : '');
        update_option('woo_return_email_name', isset($_POST['woo_return_email_name']) ? sanitize_text_field(wp_unslash($_POST['woo_return_email_name'])) : '');
        update_option('woo_return_contact_email', isset($_POST['woo_return_contact_email']) ? sanitize_email(wp_unslash($_POST['woo_return_contact_email'])) : '');
        update_option('woo_return_store_details', isset($_POST['woo_return_store_details']) ? sanitize_textarea_field(wp_unslash($_POST['woo_return_store_details'])) : '');

        echo '<div class="updated"><p>' . esc_html__('Email settings saved.', 'return-requests-woo') . '</p></div>';
    }

    // Handle saving law compliance settings
    if (isset($_POST['woo_return_save_compliance'])) {
        check_admin_referer('woo_return_compliance_nonce');
        update_option('woo_return_compliance_region', sanitize_text_field(wp_unslash($_POST['woo_return_compliance_region'] ?? 'eu')));
        update_option('woo_return_custom_disclaimer', wp_kses_post(wp_unslash($_POST['woo_return_custom_disclaimer'] ?? '')));
        update_option('woo_return_gdpr_compliant', isset($_POST['woo_return_gdpr_compliant']) ? '1' : '0');

        echo '<div class="updated"><p>' . esc_html__('Law compliance settings saved.', 'return-requests-woo') . '</p></div>';
    }

    // Handle saving return confirmation (company address) settings
    if (isset($_POST['woo_return_save_confirmation'])) {
        check_admin_referer('woo_return_confirmation_nonce');
        update_option('woo_return_company_name',    sanitize_text_field(wp_unslash($_POST['woo_return_company_name']    ?? '')));
        update_option('woo_return_company_street',  sanitize_text_field(wp_unslash($_POST['woo_return_company_street']  ?? '')));
        update_option('woo_return_company_city',    sanitize_text_field(wp_unslash($_POST['woo_return_company_city']    ?? '')));
        update_option('woo_return_company_country', sanitize_text_field(wp_unslash($_POST['woo_return_company_country'] ?? '')));
        update_option('woo_return_company_extra',   sanitize_textarea_field(wp_unslash($_POST['woo_return_company_extra'] ?? '')));
        echo '<div class="updated"><p>' . esc_html__('Return Confirmation settings saved.', 'return-requests-woo') . '</p></div>';
    }

    // Get current option values
    $admin_email = get_option('woo_return_admin_email', '');
    $from_email = get_option('woo_return_from_email', 'wordpress@' . wp_parse_url(home_url(), PHP_URL_HOST));
    $customer_subject = get_option('woo_return_customer_subject', '');
    $admin_subject = get_option('woo_return_admin_subject', '');
    $customer_message = get_option('woo_return_customer_message', '');
    $admin_message = get_option('woo_return_admin_message', '');
    $email_name = get_option('woo_return_email_name', '');
    $contact_email = get_option('woo_return_contact_email', 'wordpress@' . wp_parse_url(home_url(), PHP_URL_HOST));
    $system_enabled = get_option('woo_return_system_enabled', '0');
    ?>
    <div class="wrap woo-return-admin">
        <h1><?php esc_html_e('Return Requests WooCommerce', 'return-requests-woo'); ?></h1>
        <p><?php esc_html_e('This plugin enables return management in WooCommerce.', 'return-requests-woo'); ?></p>

        <div class="woo-return-tabs">
            <div class="woo-return-tab active" data-tab="tab-info"><?php esc_html_e('Information', 'return-requests-woo'); ?></div>
            <div class="woo-return-tab" data-tab="tab-pages"><?php esc_html_e('Pages', 'return-requests-woo'); ?></div>
            <div class="woo-return-tab" data-tab="tab-emails"><?php esc_html_e('Email settings', 'return-requests-woo'); ?></div>
            <div class="woo-return-tab" data-tab="tab-security"><?php esc_html_e('Security', 'return-requests-woo'); ?></div>
            <div class="woo-return-tab" data-tab="tab-compliance"><?php esc_html_e('Law Compliance', 'return-requests-woo'); ?></div>
            <div class="woo-return-tab" data-tab="tab-return-confirmation"><?php esc_html_e('Return Confirmation', 'return-requests-woo'); ?></div>
            <div class="woo-return-tab" data-tab="tab-cleanup"><?php esc_html_e('Data management', 'return-requests-woo'); ?></div>
            <div class="woo-return-tab" data-tab="tab-guide"><?php esc_html_e('How does it work?', 'return-requests-woo'); ?></div>
        </div>

        <!-- Tab: Information -->
        <div id="tab-info" class="woo-return-tab-content active">
            <div class="card" style="border-left: 4px solid #007cba;">
                <h2><?php esc_html_e('Return System Status', 'return-requests-woo'); ?></h2>
                <?php wp_nonce_field('woo_return_system_status_nonce', 'woo_return_system_status_nonce_field'); ?>
                <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px; margin-top: 15px;">
                    <label class="woo-return-switch">
                        <input type="checkbox" name="woo_return_system_enabled" id="woo_return_system_enabled" value="1" <?php checked($system_enabled, '1'); ?>>
                        <span class="woo-return-slider"></span>
                    </label>
                    <span id="woo-return-status-text" style="font-weight: 600; font-size: 14px; color: <?php echo $system_enabled === '1' ? '#46b450' : '#dc3232'; ?>;">
                        <?php echo $system_enabled === '1' ? esc_html__('Active', 'return-requests-woo') : esc_html__('Disabled', 'return-requests-woo'); ?>
                    </span>
                </div>
                <p class="description">
                    <?php esc_html_e('When disabled, frontend return forms will be visually disabled, the My Account return button will vanish, and backend processing will be entirely rejected. Existing shortcodes will still render.', 'return-requests-woo'); ?>
                </p>
            </div>

            <div class="card">
                <h2><?php esc_html_e('Plugin operation description', 'return-requests-woo'); ?></h2>
                <p>
                    <?php esc_html_e('The "Return Requests WooCommerce" plugin allows customers to submit order return requests in the online store. Guest users can use a form followed by email verification, while logged-in customers can submit returns directly from the "My Account" panel. The plugin generates a PDF file with return information and emails it to both the store administrator and the customer. Optional Cloudflare Turnstile protection is implemented to prevent SPAM.', 'return-requests-woo'); ?>
                </p>
            </div>

            <div class="card" style="border-left: 4px solid #007cba;">
                <h2><?php esc_html_e('Plugin pages &amp; shortcodes', 'return-requests-woo'); ?></h2>
                <p class="description" style="margin-bottom: 16px;">
                    <?php esc_html_e('The plugin uses three pages with dedicated shortcodes. Use "Check and Create Required Pages" in the Pages tab to create them automatically, or place the shortcodes manually on any page.', 'return-requests-woo'); ?>
                </p>
                <table class="form-table" style="margin-top: 0;">
                    <tr>
                        <th scope="row" style="width: 180px;"><code>[return_form]</code></th>
                        <td>
                            <strong><?php esc_html_e('Return Form', 'return-requests-woo'); ?></strong><br>
                            <span class="description"><?php esc_html_e('Entry point for guests. Customers enter their order number and billing email to receive a one-time verification link.', 'return-requests-woo'); ?></span>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><code>[return_items_form]</code></th>
                        <td>
                            <strong><?php esc_html_e('Select Return Items', 'return-requests-woo'); ?></strong><br>
                            <span class="description"><?php esc_html_e('Secure session-gated page where customers select products to return and provide their bank account number. Requires a valid session or verification token.', 'return-requests-woo'); ?></span>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><code>[return_confirmation]</code></th>
                        <td>
                            <strong><?php esc_html_e('Return Confirmation', 'return-requests-woo'); ?></strong><br>
                            <span class="description"><?php esc_html_e('Confirmation page shown after a successful return submission. Displays order summary and the company address where the package should be sent. Configure company address in the Return Confirmation tab.', 'return-requests-woo'); ?></span>
                        </td>
                    </tr>
                </table>
            </div>

            <div class="card">
                <h2><?php esc_html_e('Required plugins', 'return-requests-woo'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th scope="row"><strong>WooCommerce</strong></th>
                        <td><?php esc_html_e('Required for managing orders and customers.', 'return-requests-woo'); ?></td>
                    </tr>
                </table>
            </div>

            <div class="card" style="margin-top:20px; border-left:4px solid #FFDD00; background:#fffcf0;">
                <h2 style="margin-top:0">☕ <?php esc_html_e('Support Return Requests', 'return-requests-woo'); ?></h2>
                <p class="description" style="margin-bottom:16px; color:#444;">
                    <?php echo wp_kses_post(__('I created Return Requests to give the WordPress community a truly free, native, and bloat-free return management solution. If this plugin saves you time or money, please consider buying me a coffee! It directly helps me maintain the project and develop new features.', 'return-requests-woo')); ?>
                </p>
                <a href="https://buymeacoffee.com/jakubmisiak" target="_blank" rel="noopener noreferrer" class="button" style="background:#FFDD00; color:#000; border-color:#FFDD00; text-shadow:none; font-weight:600; padding:0 16px;">
                    <?php esc_html_e('Buy me a coffee', 'return-requests-woo'); ?>
                </a>
            </div>
        </div>

        <!-- Tab: How does it work -->
        <div id="tab-guide" class="woo-return-tab-content">
            <div class="card">
                <h2><?php esc_html_e('How does it work?', 'return-requests-woo'); ?></h2>
                <p><?php esc_html_e('This plugin provides a comprehensive native return management workflow for WooCommerce, securely handling returns from both authenticated users and guests.', 'return-requests-woo'); ?></p>
                
                <hr>
                
                <h3><?php esc_html_e('1. Configuration and Initialization', 'return-requests-woo'); ?></h3>
                <ul>
                    <li><strong><?php esc_html_e('System Status', 'return-requests-woo'); ?>:</strong> <?php esc_html_e('You can disable the system using the toggle in the Information tab while you configure the plugin, and re-enable it when you are ready to launch.', 'return-requests-woo'); ?></li>
                    <li>
                        <strong><?php esc_html_e('Automated Pages', 'return-requests-woo'); ?>:</strong> <?php esc_html_e('By clicking "Check and Create Required Pages" in the Pages tab, three routing pages are automatically created using shortcodes (Return Form, Select Return Items, Return Confirmation).', 'return-requests-woo'); ?><br>
                        <em><?php esc_html_e('Tip: These automatically created pages are fully customizable. The generated shortcode must remain on the page, but you can add your own content below or above it as needed.', 'return-requests-woo'); ?></em>
                    </li>
                    <li><strong><?php esc_html_e('Law Compliance', 'return-requests-woo'); ?>:</strong> <?php esc_html_e("In the Law Compliance tab, you can select your store's legal region to automatically display the correct consumer protection act disclaimer during the return process. You can also write your own custom legal disclaimer.", 'return-requests-woo'); ?></li>
                </ul>

                <h3><?php esc_html_e('Localization & Translation', 'return-requests-woo'); ?></h3>
                <p>
                    <?php esc_html_e('This plugin is fully translatable on both the frontend and backend. English is the default base language, but Polish (pl_PL) is 100% pre-built out-of-the-box. The plugin automatically grabs your active WordPress language setting to display the proper language if translations exist. Missing translations can be easily added using standard translation plugins like Open World or Loco Translate.', 'return-requests-woo'); ?>
                </p>

                <h3><?php esc_html_e('2. The Flow for Logged-In Customers', 'return-requests-woo'); ?></h3>
                <ol>
                    <li><?php esc_html_e('A logged-in user visits their "My Account" dashboard and navigates to "Orders".', 'return-requests-woo'); ?></li>
                    <li><?php esc_html_e('If an order has the status "Completed" and falls within the statutory return window, a "Return" button natively appears next to the order.', 'return-requests-woo'); ?></li>
                    <li><?php esc_html_e('Once clicked, the plugin generates a secure session token directly on the server without needing an email verification step (since the user identity is already verified by WordPress).', 'return-requests-woo'); ?></li>
                    <li><?php esc_html_e('The user is instantly redirected to the Select Return Items page with their authenticated session active.', 'return-requests-woo'); ?></li>
                </ol>

                <h3><?php esc_html_e('3. The Flow for Guest Customers (Unregistered)', 'return-requests-woo'); ?></h3>
                <ol>
                    <li><?php esc_html_e('A guest user visits the Return Form page.', 'return-requests-woo'); ?></li>
                    <li><?php esc_html_e('They input their Order Number and the Billing Email Address associated with that order.', 'return-requests-woo'); ?></li>
                    <li><?php esc_html_e('The plugin cross-references the database and generates a cryptographically secure 1-hour Return Token tying their request to that specific order.', 'return-requests-woo'); ?></li>
                    <li><?php esc_html_e('An automated email is dispatched to the user containing a one-time verification link.', 'return-requests-woo'); ?></li>
                    <li><?php esc_html_e("Upon clicking the link, the user's browser establishes an authenticated system session using the token, proving they own the email address. They are then securely redirected to the Select Return Items page.", 'return-requests-woo'); ?></li>
                </ol>

                <h3><?php esc_html_e('4. Form Submission and PDF Generation', 'return-requests-woo'); ?></h3>
                <ol>
                    <li><?php esc_html_e('Once the user reaches the Select Return Items page, they select the exact items they wish to return and input their bank account number.', 'return-requests-woo'); ?></li>
                    <li><?php esc_html_e('Optionally, a Cloudflare Turnstile gateway protects the form from automated abuse.', 'return-requests-woo'); ?></li>
                    <li><?php esc_html_e('Upon clicking "Return Selected Items", the request is officially recorded in the custom database table.', 'return-requests-woo'); ?></li>
                    <li><?php esc_html_e("The generative engine constructs a Goods Return Protocol PDF, filling it automatically with the store's details, the customer's billing address, and a compliance declaration.", 'return-requests-woo'); ?></li>
                    <li><?php esc_html_e('The PDF is saved securely to the server.', 'return-requests-woo'); ?></li>
                    <li><?php esc_html_e('Two automated emails are dispatched: A confirmation to the Customer including the attached PDF, and a notification to the Administrator containing the same attached PDF.', 'return-requests-woo'); ?></li>
                    <li><?php esc_html_e("The customer's secure session is flushed and they land on the final Confirmation page.", 'return-requests-woo'); ?></li>
                </ol>

                <h3><?php esc_html_e('5. Email Notifications Overview', 'return-requests-woo'); ?></h3>
                <ul>
                    <li><strong><?php esc_html_e('Verification Email (Guest only):', 'return-requests-woo'); ?></strong> <?php esc_html_e('Sent immediately after a guest submits the initial Return Form to verify their identity via a secure link. E-mail in form needs to match order billing e-mail.', 'return-requests-woo'); ?></li>
                    <li><strong><?php esc_html_e('Customer Confirmation:', 'return-requests-woo'); ?></strong> <?php esc_html_e('Sent after successfully selecting items and generating the Return Protocol PDF. Contains the PDF as an attachment.', 'return-requests-woo'); ?></li>
                    <li><strong><?php esc_html_e('Administrator Notification:', 'return-requests-woo'); ?></strong> <?php esc_html_e('Sent simultaneously to the store owner alerting them of the return, with the Return Protocol PDF attached.', 'return-requests-woo'); ?></li>
                </ul>

                <h3><?php esc_html_e('6. Return Status Management and Order Integration', 'return-requests-woo'); ?></h3>
                <ul>
                    <li><strong><?php esc_html_e('Return List Backend:', 'return-requests-woo'); ?></strong> <?php esc_html_e('All returns arrive with a "Pending" status in the main plugin table. Administrators can use quick action buttons to mark a return as "Completed" or flag it as an "Issue".', 'return-requests-woo'); ?></li>
                    <li><strong><?php esc_html_e('WooCommerce Order Meta Box:', 'return-requests-woo'); ?></strong> <?php esc_html_e('When viewing a specific order from the WooCommerce interface, a dedicated Meta Box will surface the associated Return. Store owners can alter the status from here natively.', 'return-requests-woo'); ?></li>
                    <li><strong><?php esc_html_e('Issue Resolution Notes:', 'return-requests-woo'); ?></strong> <?php esc_html_e('If a return is flagged as an "Issue" through the Order Meta Box, an optional explanation box appears. Submitting an explanation dynamically saves it as a Customer Order Note, updating the buyer automatically via WooCommerce\'s built-in email alerts.', 'return-requests-woo'); ?></li>
                </ul>
            </div>
        </div>

        <!-- Tab: Pages -->
        <div id="tab-pages" class="woo-return-tab-content">
            <div class="card">
                <h2><?php esc_html_e('Plugin pages settings', 'return-requests-woo'); ?></h2>
                <p><?php esc_html_e('Specify the page slugs used by the plugin. Do not change shortcodes on these pages.', 'return-requests-woo'); ?></p>

                <form method="post" action="">
                    <?php wp_nonce_field('woo_return_page_settings_nonce'); ?>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="woo_return_slug_form"><?php esc_html_e('Return form slug:', 'return-requests-woo'); ?></label></th>
                            <td>
                                <input type="text" name="woo_return_slug_form" id="woo_return_slug_form"
                                       value="<?php echo esc_attr(woo_return_get_slug('form')); ?>" class="regular-text">
                                <p class="description"><?php esc_html_e('URL slug for page with [return_form] shortcode. Default: return-form', 'return-requests-woo'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="woo_return_slug_items"><?php esc_html_e('Item selection slug:', 'return-requests-woo'); ?></label></th>
                            <td>
                                <input type="text" name="woo_return_slug_items" id="woo_return_slug_items"
                                       value="<?php echo esc_attr(woo_return_get_slug('items')); ?>" class="regular-text">
                                <p class="description"><?php esc_html_e('URL slug for page with [return_items_form] shortcode. Default: select-return-items', 'return-requests-woo'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="woo_return_slug_confirm"><?php esc_html_e('Return confirmation slug:', 'return-requests-woo'); ?></label></th>
                            <td>
                                <input type="text" name="woo_return_slug_confirm" id="woo_return_slug_confirm"
                                       value="<?php echo esc_attr(woo_return_get_slug('confirm')); ?>" class="regular-text">
                                <p class="description"><?php esc_html_e('URL slug for page with [return_confirmation] shortcode. Default: return-confirmed', 'return-requests-woo'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="woo_return_window_days"><?php esc_html_e('Return time window (days):', 'return-requests-woo'); ?></label></th>
                            <td>
                                <input type="number" name="woo_return_window_days" id="woo_return_window_days"
                                       value="<?php echo esc_attr(woo_return_get_window_days()); ?>" min="1" max="365" class="small-text">
                                <p class="description"><?php esc_html_e('Number of days from the date the order was completed within which the customer can submit a return request. Default: 14 days.', 'return-requests-woo'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="woo_return_bank_format"><?php esc_html_e('Bank account number format:', 'return-requests-woo'); ?></label></th>
                            <td>
                                <select name="woo_return_bank_format" id="woo_return_bank_format">
                                    <option value="polish" <?php selected(woo_return_get_bank_format(), 'polish'); ?>><?php esc_html_e('Polish (26 digits)', 'return-requests-woo'); ?></option>
                                    <option value="iban"   <?php selected(woo_return_get_bank_format(), 'iban'); ?>><?php esc_html_e('IBAN (International)', 'return-requests-woo'); ?></option>
                                    <option value="disabled" <?php selected(woo_return_get_bank_format(), 'disabled'); ?>><?php esc_html_e('Disabled (no validation)', 'return-requests-woo'); ?></option>
                                </select>
                                <p class="description"><?php esc_html_e('Defines how the bank account number provided by the customer is verified.', 'return-requests-woo'); ?></p>
                            </td>
                        </tr>
                    </table>
                    <p>
                        <button type="submit" name="woo_return_save_page_settings" class="button button-primary">
                            <?php esc_html_e('Save pages settings', 'return-requests-woo'); ?>
                        </button>
                    </p>
                </form>

                <hr>
                <h3><?php esc_html_e('Create required pages', 'return-requests-woo'); ?></h3>
                <p><?php esc_html_e('Click the button below to check if required pages exist and recreate them if necessary.', 'return-requests-woo'); ?></p>
                <form method="post" action="">
                    <?php wp_nonce_field('woo_return_pages_nonce'); ?>
                    <p>
                        <button type="submit" name="check_create_pages" class="button button-secondary">
                            <?php esc_html_e('Check and create required pages', 'return-requests-woo'); ?>
                        </button>
                    </p>
                </form>
            </div>
        </div>

        <!-- Tab: Email settings -->
        <div id="tab-emails" class="woo-return-tab-content">
            <div class="card">
                <h2><?php esc_html_e('Email settings', 'return-requests-woo'); ?></h2>
                <p class="description" style="margin-top: -10px; margin-bottom: 20px;">
                    <strong><?php esc_html_e('Note:', 'return-requests-woo'); ?></strong> <?php esc_html_e("The overall layout and styling of these emails are natively managed by WooCommerce to match your store's theme. You only control the text contents here.", 'return-requests-woo'); ?>
                </p>
                <form method="post" action="">
                    <?php wp_nonce_field('woo_return_email_settings_nonce'); ?>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="woo_return_admin_email"><?php esc_html_e('Administrator email:', 'return-requests-woo'); ?></label></th>
                            <td>
                                <input type="email" name="woo_return_admin_email" id="woo_return_admin_email"
                                       value="<?php echo esc_attr($admin_email); ?>"
                                       placeholder="<?php esc_attr_e('Enter administrator email', 'return-requests-woo'); ?>" >
                                <p class="description"><?php esc_html_e('This address receives a copy of every return request with an attached PDF.', 'return-requests-woo'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="woo_return_from_email"><?php esc_html_e('Sender email:', 'return-requests-woo'); ?></label></th>
                            <td>
                                <input type="email" name="woo_return_from_email" id="woo_return_from_email"
                                       value="<?php echo esc_attr($from_email); ?>"
                                       placeholder="<?php esc_attr_e('Enter sender email', 'return-requests-woo'); ?>" required>
                                <p class="description"><?php esc_html_e('Sender address. It must be authenticated by your Mail/SMTP server to avoid spam filters.', 'return-requests-woo'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="woo_return_email_name"><?php esc_html_e('Email sender name:', 'return-requests-woo'); ?></label></th>
                            <td>
                                <input type="text" name="woo_return_email_name" id="woo_return_email_name"
                                       value="<?php echo esc_attr($email_name); ?>"
                                       placeholder="<?php esc_attr_e('e.g. Company / Name', 'return-requests-woo'); ?>">
                                <p class="description"><?php esc_html_e('Display name in email client (e.g. "My Store Returns").', 'return-requests-woo'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="woo_return_contact_email"><?php esc_html_e('Contact email address:', 'return-requests-woo'); ?></label></th>
                            <td>
                                <input type="email" name="woo_return_contact_email" id="woo_return_contact_email"
                                       value="<?php echo esc_attr($contact_email); ?>"
                                       placeholder="<?php esc_attr_e('e.g. contact@yourdomain.com', 'return-requests-woo'); ?>">
                                <p class="description"><?php esc_html_e('Shown to customers in the return form as a contact address.', 'return-requests-woo'); ?></p>
                            </td>
                        </tr>
                    </table>

                    <div class="section-divider"></div>

                    <h3><?php esc_html_e('Email for customer', 'return-requests-woo'); ?></h3>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="woo_return_customer_subject"><?php esc_html_e('Title:', 'return-requests-woo'); ?></label></th>
                            <td>
                                <input type="text" name="woo_return_customer_subject" id="woo_return_customer_subject"
                                       value="<?php echo esc_attr($customer_subject); ?>"
                                       placeholder="<?php esc_attr_e('Email title for customer', 'return-requests-woo'); ?>" >
                                <p class="description"><?php esc_html_e('Use {order_id} as a placeholder for order number.', 'return-requests-woo'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="woo_return_customer_message"><?php esc_html_e('Content:', 'return-requests-woo'); ?></label></th>
                            <td>
                                <textarea name="woo_return_customer_message" id="woo_return_customer_message" rows="4" ><?php echo esc_textarea($customer_message); ?></textarea>
                                <p class="description"><?php esc_html_e('Use {order_id} as a placeholder for order number.', 'return-requests-woo'); ?></p>
                            </td>
                        </tr>
                    </table>

                    <div class="section-divider"></div>

                    <h3><?php esc_html_e('Email for administrator', 'return-requests-woo'); ?></h3>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="woo_return_admin_subject"><?php esc_html_e('Title:', 'return-requests-woo'); ?></label></th>
                            <td>
                                <input type="text" name="woo_return_admin_subject" id="woo_return_admin_subject"
                                       value="<?php echo esc_attr($admin_subject); ?>"
                                       placeholder="<?php esc_attr_e('Email title for administrator', 'return-requests-woo'); ?>" >
                                <p class="description"><?php esc_html_e('Use {order_id} as a placeholder for order number.', 'return-requests-woo'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="woo_return_admin_message"><?php esc_html_e('Content:', 'return-requests-woo'); ?></label></th>
                            <td>
                                <textarea name="woo_return_admin_message" id="woo_return_admin_message" rows="4" ><?php echo esc_textarea($admin_message); ?></textarea>
                                <p class="description"><?php esc_html_e('Use {order_id} as a placeholder for order number.', 'return-requests-woo'); ?></p>
                            </td>
                        </tr>
                    </table>

                    <div class="section-divider"></div>

                    <h3><?php esc_html_e('PDF generation data:', 'return-requests-woo'); ?></h3>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="woo_return_store_details"><?php esc_html_e('Store details:', 'return-requests-woo'); ?></label></th>
                            <td><textarea name="woo_return_store_details" id="woo_return_store_details" rows="5" placeholder="<?php esc_attr_e('e.g. Store Name&#10;Street 123&#10;00-000 City&#10;Phone number', 'return-requests-woo'); ?>"><?php echo esc_textarea(get_option('woo_return_store_details', '')); ?></textarea></td>
                        </tr>
                    </table>

                    <p>
                        <button type="submit" name="woo_return_save_settings" class="button button-primary"><?php esc_html_e('Save settings', 'return-requests-woo'); ?></button>
                    </p>
                </form>
            </div>
        </div>

        <!-- Tab: Security -->
        <div id="tab-security" class="woo-return-tab-content">
            <div class="card">
                <h2><?php esc_html_e('Cloudflare Turnstile (SPAM Protection)', 'return-requests-woo'); ?></h2>
                <form method="post" action="">
                    <?php wp_nonce_field('woo_return_security_nonce'); ?>
                    <?php
                    // Handle saving settings
                    if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['turnstile_settings_submit'])) {
                        check_admin_referer('woo_return_security_nonce');
                        // Save keys to database
                        update_option('turnstile_site_key', isset($_POST['turnstile_site_key']) ? sanitize_text_field(wp_unslash($_POST['turnstile_site_key'])) : '');
                        update_option('turnstile_secret_key', isset($_POST['turnstile_secret_key']) ? sanitize_text_field(wp_unslash($_POST['turnstile_secret_key'])) : '');
                        update_option('turnstile_enable', isset($_POST['turnstile_enable']) ? '1' : '0');

                        echo '<div class="updated"><p>' . esc_html__('Cloudflare Turnstile settings saved.', 'return-requests-woo') . '</p></div>';
                    }

                    // Get current settings
                    $turnstile_site_key = get_option('turnstile_site_key', '');
                    $turnstile_secret_key = get_option('turnstile_secret_key', '');
                    $turnstile_enable = get_option('turnstile_enable', '0');
                    ?>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="turnstile_enable"><?php esc_html_e('Enable Turnstile', 'return-requests-woo'); ?></label></th>
                            <td><input type="checkbox" name="turnstile_enable" id="turnstile_enable" <?php checked($turnstile_enable, '1'); ?>></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="turnstile_site_key"><?php esc_html_e('Site Key:', 'return-requests-woo'); ?></label></th>
                            <td><input type="text" name="turnstile_site_key" id="turnstile_site_key" value="<?php echo esc_attr($turnstile_site_key); ?>" class="regular-text"></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="turnstile_secret_key"><?php esc_html_e('Secret Key:', 'return-requests-woo'); ?></label></th>
                            <td><input type="text" name="turnstile_secret_key" id="turnstile_secret_key" value="<?php echo esc_attr($turnstile_secret_key); ?>" class="regular-text"></td>
                        </tr>
                        <tr>
                            <th scope="row"></th>
                            <td>
                                <button type="submit" name="turnstile_settings_submit" class="button button-primary"><?php esc_html_e('Save Turnstile settings', 'return-requests-woo'); ?></button>
                                <p style="margin-top: 30px;">
                                    <a href="https://developers.cloudflare.com/turnstile/get-started/" target="_blank"><?php esc_html_e('Learn more about Cloudflare Turnstile', 'return-requests-woo'); ?></a>
                                </p>
                            </td>
                        </tr>
                    </table>
                </form>
            </div>
        </div>

        <!-- Tab: Law Compliance -->
        <div id="tab-compliance" class="woo-return-tab-content">
            <div class="card">
                <h2><?php esc_html_e('Law Compliance', 'return-requests-woo'); ?></h2>
                <form method="post" action="">
                    <?php wp_nonce_field('woo_return_compliance_nonce'); ?>
                    <?php
                    $compliance_region = get_option('woo_return_compliance_region', 'eu');
                    $custom_disclaimer = get_option('woo_return_custom_disclaimer', '');
                    ?>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="woo_return_compliance_region"><?php esc_html_e('Compliance Region:', 'return-requests-woo'); ?></label></th>
                            <td>
                                <select name="woo_return_compliance_region" id="woo_return_compliance_region">
                                    <option value="eu" <?php selected($compliance_region, 'eu'); ?>><?php esc_html_e('EU + EEA', 'return-requests-woo'); ?></option>
                                    <option value="pl" <?php selected($compliance_region, 'pl'); ?>><?php esc_html_e('Poland', 'return-requests-woo'); ?></option>
                                    <option value="uk" <?php selected($compliance_region, 'uk'); ?>><?php esc_html_e('United Kingdom', 'return-requests-woo'); ?></option>
                                    <option value="ch" <?php selected($compliance_region, 'ch'); ?>><?php esc_html_e('Switzerland', 'return-requests-woo'); ?></option>
                                    <option value="tr" <?php selected($compliance_region, 'tr'); ?>><?php esc_html_e('Turkey', 'return-requests-woo'); ?></option>
                                    <option value="ua_md" <?php selected($compliance_region, 'ua_md'); ?>><?php esc_html_e('Ukraine / Moldova', 'return-requests-woo'); ?></option>
                                    <option value="us" <?php selected($compliance_region, 'us'); ?>><?php esc_html_e('USA', 'return-requests-woo'); ?></option>
                                    <option value="ca" <?php selected($compliance_region, 'ca'); ?>><?php esc_html_e('Canada', 'return-requests-woo'); ?></option>
                                    <option value="au_nz" <?php selected($compliance_region, 'au_nz'); ?>><?php esc_html_e('Australia / New Zealand', 'return-requests-woo'); ?></option>
                                    <option value="br" <?php selected($compliance_region, 'br'); ?>><?php esc_html_e('Brazil', 'return-requests-woo'); ?></option>
                                    <option value="custom" <?php selected($compliance_region, 'custom'); ?>><?php esc_html_e('Custom', 'return-requests-woo'); ?></option>
                                </select>
                                <p class="description"><?php esc_html_e('Select the appropriate legal framework for the return disclaimer. Based on your selection, specific regulations and return time windows will be shown to users.', 'return-requests-woo'); ?></p>
                            </td>
                        </tr>
                        <tr id="woo_return_custom_disclaimer_row" style="display: <?php echo $compliance_region === 'custom' ? 'table-row' : 'none'; ?>;">
                            <th scope="row"><label for="woo_return_custom_disclaimer"><?php esc_html_e('Custom Disclaimer:', 'return-requests-woo'); ?></label></th>
                            <td>
                                <textarea name="woo_return_custom_disclaimer" id="woo_return_custom_disclaimer" rows="8" class="large-text"><?php echo esc_textarea($custom_disclaimer); ?></textarea>
                                <p class="description"><?php esc_html_e('Enter your custom legal disclaimer. HTML tags are allowed.', 'return-requests-woo'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="woo_return_gdpr_compliant"><?php esc_html_e('Is your store compliant with GDPR regulations?', 'return-requests-woo'); ?></label></th>
                            <td>
                                <?php $gdpr_compliant = get_option('woo_return_gdpr_compliant', '0'); ?>
                                <input type="checkbox" name="woo_return_gdpr_compliant" id="woo_return_gdpr_compliant" value="1" <?php checked($gdpr_compliant, '1'); ?>>
                                <p class="description"><?php esc_html_e('If checked, the return form will explicitly state that personal data processing complies with GDPR regulations.', 'return-requests-woo'); ?></p>
                            </td>
                        </tr>
                    </table>
                    
                    <script>
                        document.addEventListener('DOMContentLoaded', function() {
                            var regionSelect = document.getElementById('woo_return_compliance_region');
                            var customRow = document.getElementById('woo_return_custom_disclaimer_row');
                            if (regionSelect && customRow) {
                                regionSelect.addEventListener('change', function() {
                                    if (this.value === 'custom') {
                                        customRow.style.display = 'table-row';
                                    } else {
                                        customRow.style.display = 'none';
                                    }
                                });
                            }
                        });
                    </script>

                    <p>
                        <button type="submit" name="woo_return_save_compliance" class="button button-primary"><?php esc_html_e('Save compliance settings', 'return-requests-woo'); ?></button>
                    </p>
                </form>
            </div>
        </div>

        <!-- Tab: Return Confirmation -->
        <div id="tab-return-confirmation" class="woo-return-tab-content">
            <div class="card">
                <h2><?php esc_html_e('Return Confirmation Page Settings', 'return-requests-woo'); ?></h2>
                <p class="description"><?php esc_html_e('Configure the company address shown on the Return Confirmation page ([return_confirmation] shortcode). After submitting a return, customers are directed here and shown where to send their package.', 'return-requests-woo'); ?></p>

                <form method="post" action="">
                    <?php wp_nonce_field('woo_return_confirmation_nonce'); ?>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="woo_return_company_name"><?php esc_html_e('Company / Store name:', 'return-requests-woo'); ?></label></th>
                            <td>
                                <input type="text" name="woo_return_company_name" id="woo_return_company_name"
                                       value="<?php echo esc_attr(get_option('woo_return_company_name', '')); ?>"
                                       placeholder="<?php esc_attr_e('e.g. My Store Sp. z o.o.', 'return-requests-woo'); ?>" class="regular-text">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="woo_return_company_street"><?php esc_html_e('Street and number:', 'return-requests-woo'); ?></label></th>
                            <td>
                                <input type="text" name="woo_return_company_street" id="woo_return_company_street"
                                       value="<?php echo esc_attr(get_option('woo_return_company_street', '')); ?>"
                                       placeholder="<?php esc_attr_e('e.g. ul. Kwiatowa 12', 'return-requests-woo'); ?>" class="regular-text">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="woo_return_company_city"><?php esc_html_e('Postal code and city:', 'return-requests-woo'); ?></label></th>
                            <td>
                                <input type="text" name="woo_return_company_city" id="woo_return_company_city"
                                       value="<?php echo esc_attr(get_option('woo_return_company_city', '')); ?>"
                                       placeholder="<?php esc_attr_e('e.g. 00-001 Warszawa', 'return-requests-woo'); ?>" class="regular-text">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="woo_return_company_country"><?php esc_html_e('Country:', 'return-requests-woo'); ?></label></th>
                            <td>
                                <input type="text" name="woo_return_company_country" id="woo_return_company_country"
                                       value="<?php echo esc_attr(get_option('woo_return_company_country', '')); ?>"
                                       placeholder="<?php esc_attr_e('e.g. Poland', 'return-requests-woo'); ?>" class="regular-text">
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="woo_return_company_extra"><?php esc_html_e('Additional instructions:', 'return-requests-woo'); ?></label></th>
                            <td>
                                <textarea name="woo_return_company_extra" id="woo_return_company_extra" rows="3" class="large-text"
                                          placeholder="<?php esc_attr_e('e.g. Please mark the package as RETURN', 'return-requests-woo'); ?>"><?php echo esc_textarea(get_option('woo_return_company_extra', '')); ?></textarea>
                                <p class="description"><?php esc_html_e('Optional extra message shown below the address (e.g. packaging instructions).', 'return-requests-woo'); ?></p>
                            </td>
                        </tr>
                    </table>
                    <p>
                        <button type="submit" name="woo_return_save_confirmation" class="button button-primary"><?php esc_html_e('Save Return Confirmation settings', 'return-requests-woo'); ?></button>
                    </p>
                </form>
            </div>

            <div class="card" style="border-left: 4px solid #007cba; margin-top: 20px;">
                <h3><?php esc_html_e('Preview — [return_confirmation] shortcode', 'return-requests-woo'); ?></h3>
                <p class="description"><?php esc_html_e('This is how the Return Confirmation page looks in the admin context (without a real return token). The actual page shown to customers will include their order details.', 'return-requests-woo'); ?></p>
                <div style="border: 1px solid #ddd; padding: 20px; background: #fafafa; border-radius: 4px; margin-top: 12px;">
                    <?php
                    // Render the shortcode preview — safe because woo_return_confirmation_message
                    // checks is_admin() and returns a placeholder in admin context.
                    echo do_shortcode('[return_confirmation]');
                    ?>
                </div>
            </div>
        </div>

        <!-- Tab: Data management -->
        <div id="tab-cleanup" class="woo-return-tab-content">
            <div class="card">
                <h2><?php esc_html_e('Delete returns', 'return-requests-woo'); ?></h2>
                <p><?php esc_html_e('Warning: Deleted data cannot be restored.', 'return-requests-woo'); ?></p>
                
                <form method="post" action="">
                    <?php wp_nonce_field('woo_return_cleanup_nonce'); ?>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php esc_html_e('Delete old returns', 'return-requests-woo'); ?></th>
                            <td>
                                <button type="submit" name="delete_old_records" class="button button-secondary"><?php esc_html_e('Delete records older than a year', 'return-requests-woo'); ?></button>
                                <p class="description"><?php esc_html_e('Deletes all return records older than a year.', 'return-requests-woo'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Delete all returns', 'return-requests-woo'); ?></th>
                            <td>
                                <input type="text" id="confirm_delete" name="confirm_delete" placeholder="<?php esc_attr_e('Type "confirm"', 'return-requests-woo'); ?>" style="width: 200px; margin-right: 10px;">
                                <button type="submit" name="delete_all_records" class="button button-secondary"><?php esc_html_e('Empty returns list', 'return-requests-woo'); ?></button>
                                <p class="description"><?php esc_html_e('Deletes all return records from the database.', 'return-requests-woo'); ?></p>
                            </td>
                        </tr>
                    </table>
                </form>
            </div>
        </div>
    </div>
    <?php
}

function woo_return_check_and_create_pages()
{
    global $wpdb;

    $pages = array(
        'form' => array(
            'title' => __('Return Form', 'return-requests-woo'),
            'shortcode' => '[return_form]'
        ),
        'items' => array(
            'title' => __('Product Selection Form for Return', 'return-requests-woo'),
            'shortcode' => '[return_items_form]'
        ),
        'confirm' => array(
            'title' => __('Return registered', 'return-requests-woo'),
            'shortcode' => '[return_confirmation]'
        )
    );

    $created_pages = array();

    foreach ($pages as $type => $data) {
        $slug = woo_return_get_slug($type);
        $page = get_page_by_path($slug);
        
        if (!$page) {
            // Check if any published page already contains the shortcode
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $page_with_shortcode = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT ID, post_name FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status = 'publish' AND post_content LIKE %s LIMIT 1",
                    '%' . $wpdb->esc_like( $data['shortcode'] ) . '%'
                )
            );

            if ($page_with_shortcode) {
                // Update the slug in settings to match the found page
                if ($type === 'form') update_option('woo_return_slug_form', $page_with_shortcode->post_name);
                if ($type === 'items') update_option('woo_return_slug_items', $page_with_shortcode->post_name);
                if ($type === 'confirm') update_option('woo_return_slug_confirm', $page_with_shortcode->post_name);
                
                continue;
            }

            wp_insert_post(array(
                'post_title' => $data['title'],
                'post_name' => $slug,
                'post_content' => $data['shortcode'],
                'post_status' => 'publish',
                'post_type' => 'page'
            ));
            $created_pages[] = $data['title'];
        }
    }

    return $created_pages;
}
