<?php
namespace Calendly_Bookings;

if (!defined('ABSPATH')) exit;

final class CB_Installer {
    // Increment this when you change any table schema
    private const SCHEMA_VERSION = '1.0.0';
    private const OPTION_KEY     = 'cb_schema_version';

    public static function init(): void {
        // Run on activation
        register_activation_hook(CB_Constants::plugin_file(), [__CLASS__, 'activate']);
        // Run on every load to catch upgrades
        add_action('plugins_loaded', [__CLASS__, 'maybe_upgrade']);
    }

    public static function activate(): void {
        self::maybe_upgrade(true);
    }

    public static function maybe_upgrade(bool $force = false): void {
        $installed_version = get_option(self::OPTION_KEY);

        if ($force || $installed_version !== self::SCHEMA_VERSION) {
            self::create_or_update_tables();
            update_option(self::OPTION_KEY, self::SCHEMA_VERSION);
        }
    }

    private static function create_or_update_tables(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset_collate = $wpdb->get_charset_collate();

        // cb_scheduled_events
        $sql1 = "CREATE TABLE {$wpdb->prefix}cb_scheduled_events (
            uuid        varchar(64)   NOT NULL,
            name        text          NOT NULL,
            status      varchar(50)   NOT NULL,
            start_time  datetime      NOT NULL,
            end_time    datetime      DEFAULT NULL,
            location    text          DEFAULT NULL,
            payload     longtext      DEFAULT NULL,
            created_at  timestamp     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at  timestamp     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (uuid),
            KEY idx_start_time (start_time),
            KEY idx_status (status)
        ) ENGINE=InnoDB {$charset_collate};";
        dbDelta($sql1);

        // cb_event_types
        $sql2 = "CREATE TABLE {$wpdb->prefix}cb_event_types (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            uuid CHAR(36) NOT NULL,
            name VARCHAR(255) NOT NULL,
            duration INT NOT NULL,
            uri TEXT NOT NULL,
            product_id BIGINT UNSIGNED DEFAULT NULL,
            meta LONGTEXT DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uuid (uuid)
        ) {$charset_collate};";
        dbDelta($sql2);

        // cb_audit_log
        $sql3 = "CREATE TABLE {$wpdb->prefix}cb_audit_log (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            timestamp DATETIME NOT NULL,
            user_id BIGINT UNSIGNED NULL,
            action_type VARCHAR(50) NOT NULL,
            object_type VARCHAR(50) NOT NULL,
            object_id VARCHAR(100) NOT NULL,
            details LONGTEXT NULL,
            ip_address VARCHAR(45) NULL,
            PRIMARY KEY (id),
            KEY action_type (action_type),
            KEY object_type (object_type),
            KEY timestamp (timestamp)
        ) {$charset_collate};";
        dbDelta($sql3);
    }
}
