<?php
/**
 * Debug script for Calendly Bookings token generation
 * Run this from WordPress admin or via WP-CLI to test token generation
 */

// Load WordPress
require_once '../../../wp-load.php';

if (!defined('ABSPATH')) {
    die('WordPress not loaded');
}

echo "=== Calendly Bookings Token Debug ===\n\n";

// Check constants
echo "Constants:\n";
echo "CB_LICENSE_OPTION: " . (defined('CB_LICENSE_OPTION') ? CB_LICENSE_OPTION : 'NOT DEFINED') . "\n";
echo "CB_TOKEN_OPTION: " . (defined('CB_TOKEN_OPTION') ? CB_TOKEN_OPTION : 'NOT DEFINED') . "\n";
echo "CB_WORKER_ENDPOINT: " . (defined('CB_WORKER_ENDPOINT') ? CB_WORKER_ENDPOINT : 'NOT DEFINED') . "\n";
echo "LICENSE_SECRET: " . (defined('LICENSE_SECRET') ? 'DEFINED (' . strlen(LICENSE_SECRET) . ' bytes)' : 'NOT DEFINED') . "\n\n";

// Check stored values
$license = get_option(CB_LICENSE_OPTION, '');
$token = get_option(CB_TOKEN_OPTION, '');

echo "Stored Values:\n";
echo "License: " . (!empty($license) ? substr($license, 0, 8) . '...' : 'EMPTY') . "\n";
echo "Token: " . (!empty($token) ? substr($token, 0, 8) . '...' : 'EMPTY') . "\n\n";

// Test worker endpoint
if (!empty($license) && defined('CB_WORKER_ENDPOINT')) {
    echo "Testing worker endpoint...\n";

    $response = wp_remote_post(CB_WORKER_ENDPOINT, [
        'headers' => ['Content-Type' => 'application/json'],
        'body'    => wp_json_encode(['license' => $license]),
        'timeout' => 20,
    ]);

    if (is_wp_error($response)) {
        echo "Worker request failed: " . $response->get_error_message() . "\n";
    } else {
        $status = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        echo "Worker response status: $status\n";
        echo "Worker response: " . substr($body, 0, 200) . "\n";

        $data = json_decode($body, true);
        if (!empty($data['valid']) && !empty($data['token'])) {
            echo "Token received from worker\n";

            if (defined('LICENSE_SECRET')) {
                $decrypted = cb_decrypt_token($data['token'], LICENSE_SECRET);
                echo "Decryption: " . ($decrypted ? 'SUCCESS' : 'FAILED') . "\n";
            } else {
                echo "Cannot decrypt - LICENSE_SECRET not defined\n";
            }
        } else {
            echo "Invalid worker response\n";
        }
    }
} else {
    echo "Cannot test worker - missing license or endpoint\n";
}

echo "\n=== Debug Complete ===\n";