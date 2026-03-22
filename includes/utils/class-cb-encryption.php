<?php
//includes/utils/class-cb-encryption.php
namespace Calendly_Bookings\Utils;

if (!defined('ABSPATH')) exit;

final class CB_Encryption {
    private static $method = 'AES-256-CBC';
    private static $key;
    private static $iv;

    /**
     * Initialize encryption keys.
     * Generates and stores them in the options table if not already set.
     */
    public static function init(): void {
        // Try to load existing key/iv from options
        $key = get_option('cb_encryption_key');
        $iv  = get_option('cb_encryption_iv');

        // If not found, generate new ones
        if (!$key || !$iv) {
            $key = base64_encode(openssl_random_pseudo_bytes(32)); // 256-bit key
            $iv  = base64_encode(openssl_random_pseudo_bytes(16)); // 128-bit IV

            // Persist them in the options table
            update_option('cb_encryption_key', $key, false);
            update_option('cb_encryption_iv', $iv, false);
        }

        // Assign to static properties
        self::$key = base64_decode($key);
        self::$iv  = base64_decode($iv);
    }

    public static function encrypt(string $plaintext): string {
        self::init(); // ensure keys are loaded
        $ciphertext = openssl_encrypt($plaintext, self::$method, self::$key, 0, self::$iv);
        return base64_encode($ciphertext);
    }

    public static function decrypt(string $ciphertext): string {
        self::init(); // ensure keys are loaded
        $decoded = base64_decode($ciphertext);
        return openssl_decrypt($decoded, self::$method, self::$key, 0, self::$iv);
    }
}
