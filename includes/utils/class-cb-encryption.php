<?php
namespace Calendly_Bookings\Utils;

if (!defined('ABSPATH')) exit;

final class CB_Encryption {
    private static $key;
    private static $iv;
    private const OPTION_KEY = 'cb_encryption_key';
    private const OPTION_IV  = 'cb_encryption_iv';
    private const CIPHER     = 'AES-256-CBC';

    /**
     * Initialize keys from options or generate new ones.
     */
    public static function init() {
        self::$key = get_option(self::OPTION_KEY);
        self::$iv  = get_option(self::OPTION_IV);

        if (empty(self::$key) || empty(self::$iv)) {
            // Generate secure random key and IV
            self::$key = base64_encode(openssl_random_pseudo_bytes(32)); // 256-bit key
            self::$iv  = base64_encode(openssl_random_pseudo_bytes(openssl_cipher_iv_length(self::CIPHER)));

            // Persist in WordPress options
            update_option(self::OPTION_KEY, self::$key, false);
            update_option(self::OPTION_IV, self::$iv, false);
        }

        // Decode for use
        self::$key = base64_decode(self::$key);
        self::$iv  = base64_decode(self::$iv);
    }

    /**
     * Encrypt a string.
     */
    public static function encrypt(string $data): string {
        self::init();
        $encrypted = openssl_encrypt($data, self::CIPHER, self::$key, OPENSSL_RAW_DATA, self::$iv);
        return base64_encode($encrypted);
    }

    /**
     * Decrypt a string.
     */
    public static function decrypt(string $encrypted): string {
        self::init();
        $decoded = base64_decode($encrypted);
        return openssl_decrypt($decoded, self::CIPHER, self::$key, OPENSSL_RAW_DATA, self::$iv);
    }
}
