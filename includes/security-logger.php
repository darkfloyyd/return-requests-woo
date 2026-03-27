<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class for logging security events of the plugin
 */
class WooReturn_Security_Logger {
    /**
     * Initialization of logging system
     */
    public static function init() {
        // Create log table if it does not exist
        self::create_logs_table();
        
        // Setup weekly cleaning of old logs
        if (!wp_next_scheduled('woo_return_clean_security_logs')) {
            wp_schedule_event(time(), 'weekly', 'woo_return_clean_security_logs');
        }
        
        // Hook for cleaning old logs
        add_action('woo_return_clean_security_logs', [__CLASS__, 'clean_old_logs']);
    }
    
    /**
     * Create security logs table
     */
    public static function create_logs_table() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'woo_return_security_logs';
        
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) !== $table_name ) {
            $charset_collate = $wpdb->get_charset_collate();
            
            $sql = "CREATE TABLE $table_name (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                log_time datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
                severity varchar(10) NOT NULL,
                event_type varchar(50) NOT NULL,
                message text NOT NULL,
                ip_address varchar(100) NOT NULL,
                user_id bigint(20),
                user_agent text,
                request_uri varchar(255),
                PRIMARY KEY (id)
            ) $charset_collate;";
            
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sql);
        }
    }
    
    /**
     * Log security event
     * 
     * @param string $event_type Event type (e.g. 'login_attempt', 'token_verification', etc.)
     * @param string $message Message describing the event
     * @param string $severity Severity level ('info', 'warning', 'error', 'critical')
     * @param array $additional_data Additional data to save (optional)
     */
    public static function log_event($event_type, $message, $severity = 'info', $additional_data = []) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'woo_return_security_logs';
        
        // Get user data
        $user_id = get_current_user_id();
        
        // Get request data
        $ip_address  = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) );
        $user_agent  = sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) );
        $request_uri = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) );
        
        // Prepare data for saving
        $data = [
            'severity' => sanitize_text_field($severity),
            'event_type' => sanitize_text_field($event_type),
            'message' => sanitize_text_field($message),
            'ip_address' => $ip_address,
            'user_id' => $user_id ? $user_id : null,
            'user_agent' => $user_agent,
            'request_uri' => $request_uri
        ];
        
        // Save log to database
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
        $result = $wpdb->insert($table_name, $data);
        // phpcs:enable
        
        if ( $result === false ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( 'WooReturn Security Logger: Error while saving log: ' . $wpdb->last_error ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
            }
        }

        // Critical events go to error_log only in debug mode
        if ( ( $severity === 'critical' || $severity === 'error' ) && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( 'WooReturn Security [' . strtoupper( $severity ) . ']: ' . $event_type . ' - ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        }
    }
    
    /**
     * Clean old logs (older than 90 days)
     */
    public static function clean_old_logs() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'woo_return_security_logs';
        
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM `{$table_name}` WHERE log_time < DATE_SUB(NOW(), INTERVAL %d DAY)",
                90
            )
        );
        // phpcs:enable
    }
    

}

// Logger initialization is called by woo_return_activate() in the main plugin file.

// Helper function
function woo_return_log_security_event($event_type, $message, $severity = 'info', $additional_data = []) {
    if (class_exists('WooReturn_Security_Logger')) {
        WooReturn_Security_Logger::log_event($event_type, $message, $severity, $additional_data);
    }
} 