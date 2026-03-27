<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function woo_create_returns_table() {
    global $wpdb;

    $table_name = $wpdb->prefix . 'woo_returns';

    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        order_id BIGINT(20) NOT NULL,
        customer_name VARCHAR(255) NOT NULL,
        customer_email VARCHAR(255) NOT NULL,
        bank_account VARCHAR(255) NOT NULL,
        pdf_path VARCHAR(255) NOT NULL,
        status VARCHAR(20) DEFAULT 'pending' NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP NOT NULL,
        PRIMARY KEY  (id)
    ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
}

// Note: activation is handled in the main plugin file (return-requests-woo.php)

// Function to initialize the encryption key
function woo_return_init_encryption_key() {
    $encryption_key = get_option('woo_return_encryption_key');
    
    if (!$encryption_key) {
        // Generate a new encryption key
        $encryption_key = bin2hex(random_bytes(32)); // 256-bit key
        update_option('woo_return_encryption_key', $encryption_key);
    }
    
    return $encryption_key;
}

// Function to encrypt data
function woo_return_encrypt_data($data) {
    if (empty($data)) {
        return '';
    }
    
    $encryption_key = woo_return_init_encryption_key();
    $iv = random_bytes(16); // 128-bit initialization vector
    
    $encrypted = openssl_encrypt(
        $data,
        'AES-256-CBC',
        hex2bin($encryption_key),
        0,
        $iv
    );
    
    if ( $encrypted === false ) {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( 'WooReturn: Data encryption error: ' . openssl_error_string() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        }
        return '';
    }
    
    // Combine IV with encrypted data
    $encrypted_with_iv = base64_encode($iv . base64_decode($encrypted));
    
    return $encrypted_with_iv;
}

// Function to decrypt data
function woo_return_decrypt_data($encrypted_data) {
    if (empty($encrypted_data)) {
        return '';
    }
    
    $encryption_key = woo_return_init_encryption_key();
    
    // Decode data
    $encrypted_decoded = base64_decode($encrypted_data);
    
    // Get IV from the beginning of encrypted data
    $iv = substr($encrypted_decoded, 0, 16);
    $ciphertext = substr($encrypted_decoded, 16);
    
    // Decrypt data
    $decrypted = openssl_decrypt(
        base64_encode($ciphertext),
        'AES-256-CBC',
        hex2bin($encryption_key),
        0,
        $iv
    );
    
    if ( $decrypted === false ) {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( 'WooReturn: Data decryption error: ' . openssl_error_string() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        }
        return '';
    }
    
    return $decrypted;
}

// Note: key initialization is called by woo_return_activate() in the main plugin file.
