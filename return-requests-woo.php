<?php

/*
 * Plugin Name:       Return Requests for WooCommerce
 * Plugin URI:        https://github.com/jakubmisiak/return-requests-woo
 * Description:       Comprehensive return management system for WooCommerce. Registers return requests, allows customers to select products and generates PDF with details.
 * Version:           1.0.1
 * Requires at least: 5.9
 * Requires PHP:      8.1
 * Requires Plugins:  woocommerce
 * Author:            Jakub Misiak
 * Author URI:        https://buymeacoffee.com/jakubmisiak
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       return-requests-woo
 * Domain Path:       /languages
 */

if (!defined('ABSPATH')) {
    exit;
}

// Plugin constants
define('WOO_RETURN_VERSION', '1.0.1');
define('WOO_RETURN_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WOO_RETURN_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WOO_RETURN_TEXT_DOMAIN', 'return-requests-woo');

// Check Composer autoloader
if (!file_exists(WOO_RETURN_PLUGIN_DIR . 'vendor/autoload.php')) {
    add_action('admin_notices', function () {
        echo '<div class="notice notice-error"><p><strong>Return Requests for WooCommerce:</strong> '
            . esc_html__('Composer autoloader is missing. Run `composer install` in the plugin directory.', 'return-requests-woo')
            . '</p></div>';
    });
    return;
}

// Load plugin files
require_once WOO_RETURN_PLUGIN_DIR . 'includes/database.php';
require_once WOO_RETURN_PLUGIN_DIR . 'includes/security-logger.php';
require_once WOO_RETURN_PLUGIN_DIR . 'includes/form-handler.php';
require_once WOO_RETURN_PLUGIN_DIR . 'includes/pdf-generator.php';
require_once WOO_RETURN_PLUGIN_DIR . 'includes/email-sender.php';
require_once WOO_RETURN_PLUGIN_DIR . 'includes/admin-menu.php';
require_once WOO_RETURN_PLUGIN_DIR . 'includes/admin-pages/main-page.php';
require_once WOO_RETURN_PLUGIN_DIR . 'includes/admin-pages/list-returns.php';
require_once WOO_RETURN_PLUGIN_DIR . 'includes/my-account-integration.php';
require_once WOO_RETURN_PLUGIN_DIR . 'includes/order-integration.php';
require_once WOO_RETURN_PLUGIN_DIR . 'includes/wc-status-sync.php';

// Load translation domain
// Note: When hosted on WordPress.org, WordPress automatically loads translations.
// load_plugin_textdomain is still needed for installation outside .org (e.g. GitHub).
add_action('plugins_loaded', 'woo_return_load_textdomain');

function woo_return_load_textdomain()
{
    // phpcs:ignore PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound -- Required for non-org distribution translation fallback
    load_plugin_textdomain(
        'return-requests-woo',
        false,
        dirname(plugin_basename(__FILE__)) . '/languages/'
    );
}

// ============================================================
// PDF download & AJAX hooks (Admin only)
// ============================================================
add_action('wp_ajax_woo_return_toggle_system_status', 'woo_return_toggle_system_status_handler');

function woo_return_toggle_system_status_handler()
{
    check_ajax_referer('woo_return_system_status_nonce', 'nonce');

    if (!current_user_can('manage_options')) {
        wp_send_json_error(esc_html__('Unauthorized access.', 'return-requests-woo'));
    }

    $status = (isset($_POST['status']) && sanitize_text_field(wp_unslash($_POST['status'])) === '1') ? '1' : '0';
    update_option('woo_return_system_enabled', $status);

    // Translate dynamic text responses
    $text_active = esc_html__('Active', 'return-requests-woo');
    $text_disabled = esc_html__('Disabled', 'return-requests-woo');

    wp_send_json_success([
        'status' => $status,
        'text' => $status === '1' ? $text_active : $text_disabled,
        'color' => $status === '1' ? '#46b450' : '#dc3232'
    ]);
}

add_action('wp_ajax_woo_return_download_pdf', 'woo_return_download_pdf_handler');

function woo_return_download_pdf_handler()
{
    if (!current_user_can('manage_woocommerce')) {
        wp_die(esc_html__('Unauthorized access.', 'return-requests-woo'));
    }

    $return_id = isset($_GET['return_id']) ? intval($_GET['return_id']) : 0;
    if (!$return_id || !isset($_GET['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'download_return_pdf_' . $return_id)) {
        wp_die(esc_html__('Security check failed.', 'return-requests-woo'));
    }

    global $wpdb;

    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
    $pdf_path = $wpdb->get_var($wpdb->prepare("SELECT pdf_path FROM `{$wpdb->prefix}woo_returns` WHERE id = %d", $return_id));

    if (!$pdf_path || !file_exists($pdf_path)) {
        wp_die(esc_html__('File not found on server.', 'return-requests-woo'));
    }

    $filename = basename($pdf_path);

    header('Content-Description: File Transfer');
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . esc_attr($filename) . '"');
    header('Expires: 0');
    header('Cache-Control: must-revalidate');
    header('Pragma: public');
    header('Content-Length: ' . filesize($pdf_path));
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Direct output stream required for optimal memory performance
    readfile($pdf_path);
    exit;
}

// ============================================================
// Activation and deactivation hooks (ONLY from main file)
// ============================================================

register_activation_hook(__FILE__, 'woo_return_activate');

function woo_return_activate()
{
    woo_create_returns_table();
    woo_return_init_encryption_key();
    WooReturn_Security_Logger::create_logs_table();
    
    // Page creation is no longer automatic. The user can create them manually or via settings.

    // Weekly log cleaning schedule
    if (!wp_next_scheduled('woo_return_clean_security_logs')) {
        wp_schedule_event(time(), 'weekly', 'woo_return_clean_security_logs');
    }

    // First activation flag — show onboarding notice
    update_option('woo_return_show_setup_notice', '1');
    update_option('woo_return_db_version', '1.1.0');
}

add_action('admin_init', 'woo_return_check_db_version');

function woo_return_check_db_version()
{
    $current_version = get_option('woo_return_db_version', '0');
    if (version_compare($current_version, '1.1.0', '<')) {
        woo_create_returns_table();
        update_option('woo_return_db_version', '1.1.0');
    }
}

register_deactivation_hook(__FILE__, 'woo_return_deactivate');

function woo_return_deactivate()
{
    wp_clear_scheduled_hook('woo_return_clean_security_logs');
}

// ============================================================
// Admin notices
// ============================================================

/** Dismissible onboarding notice shown after first activation. */
add_action('admin_notices', 'woo_return_setup_notice');

function woo_return_setup_notice()
{
    if (!current_user_can('manage_options')) {
        return;
    }
    if (!get_option('woo_return_show_setup_notice')) {
        return;
    }
    if (get_option('woo_return_system_enabled', '0') !== '1') {
        return;
    }

    // Handle dismissal
    if (isset($_GET['woo_return_dismiss_notice'])) {
        if (isset($_GET['_wpnonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'woo_return_dismiss_notice')) {
            delete_option('woo_return_show_setup_notice');
            return;
        }
    }

    $settings_url = admin_url('admin.php?page=woo-return-settings');
    $dismiss_url = wp_nonce_url(add_query_arg('woo_return_dismiss_notice', '1'), 'woo_return_dismiss_notice');
    ?>
    <div class="notice notice-info is-dismissible" id="woo-return-setup-notice">
        <h3><?php esc_html_e('Return Requests for WooCommerce plugin is active!', 'return-requests-woo'); ?></h3>
        <p><?php esc_html_e('Required pages were created automatically. Configure the plugin in a few steps:', 'return-requests-woo'); ?></p>
        <ol>
            <li><?php
    printf(
        /* translators: %1$s: opening <a>, %2$s: closing </a> */
        esc_html__('Go to %1$sEmail Settings%2$s and set the administrator email.', 'return-requests-woo'),
        '<a href="' . esc_url($settings_url) . '#tab-emails">',
        '</a>'
    );
    ?></li>
            <li><?php esc_html_e('Customize email titles and content.', 'return-requests-woo'); ?></li>
            <li><?php esc_html_e('(Recommended) Configure Cloudflare Turnstile as spam protection.', 'return-requests-woo'); ?></li>
        </ol>
        <p>
            <a href="#" onclick="document.getElementById('woo-return-setup-notice').style.display='none'; return false;" class="button button-primary"><?php esc_html_e('I understand, close', 'return-requests-woo'); ?></a>
            <a href="<?php echo esc_url($dismiss_url); ?>" class="button button-secondary"><?php esc_html_e('Don\'t show again', 'return-requests-woo'); ?></a>
        </p>
    </div>
    <?php
}

/** Persistent warnings shown on plugin pages when critical settings are missing. */
add_action('admin_notices', 'woo_return_config_warnings');

function woo_return_config_warnings()
{
    $screen = get_current_screen();
    if (!$screen || (strpos($screen->id, 'woo-return') === false && strpos($screen->id, 'return-requests') === false)) {
        return;
    }
    if (!current_user_can('manage_options')) {
        return;
    }

    $warnings = [];

    if (empty(get_option('woo_return_admin_email'))) {
        $warnings[] = sprintf(
            /* translators: %s: URL to email settings tab */
            __('&#9888; Administrator email is not configured. Return notifications will not be sent to the store admin. <a href="%s">Go to Email Settings</a>.', 'return-requests-woo'),
            admin_url('admin.php?page=woo-return-settings#tab-emails')
        );
    }

    $slugs = [
        woo_return_get_slug('form'),
        woo_return_get_slug('items'),
        woo_return_get_slug('confirm'),
    ];
    foreach ($slugs as $slug) {
        if (!get_page_by_path($slug)) {
            $warnings[] = sprintf(
                /* translators: %1$s: page slug, %2$s: settings page URL */
                __('&#9888; Required page "/%1$s/" does not exist. <a href="%2$s">Go to Pages tab</a> to recreate it.', 'return-requests-woo'),
                esc_html($slug),
                admin_url('admin.php?page=woo-return-settings#tab-pages')
            );
        }
    }

    foreach ($warnings as $warning) {
        echo '<div class="notice notice-warning"><p>' . wp_kses_post($warning) . '</p></div>';
    }
}

// ============================================================
// PHP Session
// ============================================================

add_action('init', 'woo_return_start_session', 1);

function woo_return_start_session()
{
    if (wp_doing_ajax() || is_admin()) {
        return;
    }

    $current_url = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '';

    $slug_form = woo_return_get_slug('form');
    $slug_items = woo_return_get_slug('items');
    $slug_confirm = woo_return_get_slug('confirm');

    if (strpos($current_url, '/' . $slug_items) !== false ||
            strpos($current_url, '/' . $slug_form) !== false ||
            strpos($current_url, '/' . $slug_confirm) !== false ||
            isset($_GET['verify_token']) ||  // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- email verification token, nonce not applicable
            isset($_GET['return_token'])) {  // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- signed return token link, nonce not applicable

        if (session_status() === PHP_SESSION_NONE) {
            session_start([
                'cookie_lifetime' => 3600,
                'read_and_close' => false,
            ]);

            if (!isset($_SESSION['woo_return_session'])) {
                $_SESSION['woo_return_session'] = true;
                $_SESSION['woo_return_session_start'] = time();
            }
        }
    }
}

// ============================================================
// Scripts and styles
// ============================================================

add_action('wp_enqueue_scripts', 'woo_return_enqueue_assets');

function woo_return_enqueue_assets()
{
    if (wp_doing_ajax() || is_admin()) {
        return;
    }

    wp_enqueue_style(
        'woo-return-styles',
        WOO_RETURN_PLUGIN_URL . 'assets/css/style.css',
        [],
        WOO_RETURN_VERSION
    );

    wp_enqueue_script(
        'woo-return-form',
        WOO_RETURN_PLUGIN_URL . 'assets/js/return-form.js',
        [],
        WOO_RETURN_VERSION,
        true
    );
    wp_localize_script('woo-return-form', 'wooReturnFormData', [
        'bankErrorMsg' => __('Account number must contain exactly 26 digits!', 'return-requests-woo'),
    ]);
}

add_action('admin_enqueue_scripts', 'woo_return_enqueue_admin_assets');

function woo_return_enqueue_admin_assets($hook)
{
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $page = isset($_GET['page']) ? sanitize_text_field(wp_unslash($_GET['page'])) : '';
    if (empty($page) || (strpos($page, 'return-requests') === false && strpos($page, 'woo-return') === false)) {
        return;
    }

    wp_enqueue_style(
        'woo-return-admin',
        WOO_RETURN_PLUGIN_URL . 'assets/css/admin.css',
        [],
        WOO_RETURN_VERSION
    );
    wp_enqueue_script(
        'woo-return-admin-tabs',
        WOO_RETURN_PLUGIN_URL . 'assets/js/admin-tabs.js',
        ['jquery'],
        WOO_RETURN_VERSION,
        true
    );

    wp_localize_script('woo-return-admin-tabs', 'wooReturnAdmin', [
        'ajax_url' => admin_url('admin-ajax.php')
    ]);
}

// ============================================================
// Cloudflare Turnstile — loading via wp_footer (external CDN)
// Note: wp_enqueue_script() is disallowed for external URLs on WordPress.org.
// Instead we print <script> tag directly via wp_footer.
// ============================================================

add_action('wp_footer', 'woo_return_print_turnstile_script');

function woo_return_print_turnstile_script()
{
    if (wp_doing_ajax() || is_admin()) {
        return;
    }

    if (!get_option('turnstile_enable')) {
        return;
    }

    // Only on plugin pages (check current URL contains a plugin slug)
    $current_url = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '';
    $slug_form = woo_return_get_slug('form');
    $slug_items = woo_return_get_slug('items');

    if (strpos($current_url, '/' . $slug_form) === false &&
            strpos($current_url, '/' . $slug_items) === false) {
        return;
    }

    echo '<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>' . "\n";  // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript, PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- Turnstile requires external CDN; wp_enqueue_script() with external URL is disallowed on WP.org
}

// ============================================================
// Return button in order details
// ============================================================

add_action('woocommerce_order_details_after_order_table', 'woo_return_add_return_button', 10, 1);

function woo_return_add_return_button($order)
{
    if (wp_doing_ajax() || is_admin()) {
        return;
    }

    // Check if system is disabled
    if (get_option('woo_return_system_enabled', '0') !== '1') {
        return;
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'woo_returns';

    // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
    $existing_record = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM `{$table_name}` WHERE order_id = %d",
            $order->get_id()
        )
    );
    // phpcs:enable

    if ($existing_record > 0) {
        echo '<p class="woocommerce-info zwrot">' . esc_html__('Return for this order has already been registered.', 'return-requests-woo') . '</p>';
        return;
    }

    $phone = $order->get_billing_phone();
    $address = $order->get_billing_address_1();
    $order_id = $order->get_id();

    if (empty($phone) || empty($address)) {
        echo '<p class="woocommerce-info zwrot">' . esc_html__('Missing required contact details (phone number or address). Please update them in account settings.', 'return-requests-woo') . '</p>';
        return;
    }

    $order_date = $order->get_date_created();
    $current_date = new DateTime();
    $interval = $order_date->diff($current_date);
    $window_days = woo_return_get_window_days();

    if ($interval->days <= $window_days && $order->get_status() === 'completed') {
        $return_token = wp_generate_password(32, false);

        if (!session_id()) {
            session_start();
        }

        $_SESSION['return_order_' . $return_token] = [
            'order_id' => $order_id,
            'phone' => $phone,
            'address' => $address,
            'timestamp' => time(),
        ];

        $return_url = add_query_arg(
            ['return_token' => $return_token],
            site_url('/' . woo_return_get_slug('items') . '/')
        );

        echo '<a href="' . esc_url($return_url) . '" class="button woo-return-button">' . esc_html__('Order return', 'return-requests-woo') . '</a>';
    }
}

// ============================================================
// Replace page content when it has a verification token
// ============================================================

add_filter('the_content', 'woo_return_replace_content', 999);

function woo_return_replace_content($content)
{
    if (is_admin()) {
        return $content;
    }

    if (!has_shortcode($content, 'return_items_form')) {
        return $content;
    }

    if (isset($_GET['verify_token']) && !empty($_GET['verify_token'])) {  // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- token supplied by plugin email link, not form POST
        require_once WOO_RETURN_PLUGIN_DIR . 'includes/form-handler.php';

        if (function_exists('woo_return_item_selection_form')) {
            $new_content = woo_return_item_selection_form();
            if (!empty($new_content)) {
                return $new_content;
            }
        } else {
            return '<p class="woocommerce-error zwrot">' . esc_html__('Plugin configuration error. Please contact the administrator.', 'return-requests-woo') . '</p>';
        }
    }

    return $content;
}

// ============================================================
// Helper functions — configurable values settings
// ============================================================

/**
 * Returns slug of selected plugin page.
 *
 * @param string $page 'form' | 'items' | 'confirm'
 * @return string
 */
function woo_return_get_slug($page)
{
    $slug = get_option('woo_return_slug_' . $page, '');
    if (!empty($slug)) {
        return $slug;
    }

    $legacy_defaults = array(
        'form' => 'formularz-zwrotu',
        'items' => 'wybierz-przedmioty-do-zwrotu',
        'confirm' => 'zwrot-potwierdzony'
    );

    // Check if the legacy Polish configuration page currently exists on the user's installation
    if (isset($legacy_defaults[$page])) {
        $legacy_page = get_page_by_path($legacy_defaults[$page]);
        if ($legacy_page) {
            return $legacy_defaults[$page];
        }
    }

    $defaults = array(
        'form' => 'return-form',
        'items' => 'select-return-items',
        'confirm' => 'return-confirmed'
    );

    return $defaults[$page] ?? '';
}

/**
 * Returns number of days in which customer can submit a return.
 *
 * @return int
 */
function woo_return_get_window_days()
{
    return absint(get_option('woo_return_window_days', 14));
}

/**
 * Returns selected bank account number validation format.
 *
 * @return string 'polish' | 'iban' | 'disabled'
 */
function woo_return_get_bank_format()
{
    return get_option('woo_return_bank_format', 'polish');
}
