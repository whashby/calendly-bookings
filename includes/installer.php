<?php
/**
 * Plugin schema installer and migrator.
 *
 * Responsible for:
 * - Creating and upgrading all Calendly Booking plugin tables.
 * - Running idempotent, warning-free migrations.
 * - Managing schema versioning via wp_options.
 */
// includes/installer.php
namespace Calendly_Bookings;


if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use Calendly_Bookings\CB_Constants;

final class CB_Installer {

    private const SCHEMA_VERSION = CB_Constants::VERSION;
    private const OPTION_KEY = 'cb_schema_version';
    
    private const PAGE_SLUG = 'meeting-scheduled'; 
    private const PAGE_OPTION = 'cb_meeting_scheduled_page_id';


    public static function init(): void {
        add_action( 'plugins_loaded', [ __CLASS__, 'maybe_run' ] );
        register_uninstall_hook(__FILE__, [__CLASS__, 'uninstall']);
    }

    public static function activate(): void {
        self::run( true );
    }

    public static function maybe_run(): void {
        $stored = get_option( self::OPTION_KEY );

        if ( $stored !== self::SCHEMA_VERSION ) {
            self::run( false );
        }
    }

    /**
     * Core migration runner.
     *
     * @param bool $on_activation Indicates whether this is running from activation hook.
     */
    private static function run( bool $on_activation = false ): void {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $wpdb->hide_errors(); // Avoid leaking notices during activation.

        self::migrate_all_tables();
        self::ensure_foreign_keys();
        self::create_meeting_page();

        update_option( self::OPTION_KEY, self::SCHEMA_VERSION );
    }

    /**
     * Define and migrate all plugin tables.
     */
    private static function migrate_all_tables(): void {

self::migrate_table( 'cb_audit_log', [
    'columns' => [
        "`id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT",
        "`timestamp` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP",
        "`level` ENUM('info','warning','error') NOT NULL DEFAULT 'info'",
        "`action` VARCHAR(64) NOT NULL",
        "`context` VARCHAR(64) NOT NULL",
        "`identifier` VARCHAR(128) DEFAULT NULL",
        "`details` LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`details`))",
    ],
    'keys' => [
        "PRIMARY KEY (`id`)",
        "KEY `idx_context` (`context`)",
        "KEY `idx_identifier` (`identifier`)",
        "KEY `idx_timestamp` (`timestamp`)",
    ],
] );

self::migrate_table( 'cb_event_types', [
    'columns' => [
        "`id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT",
        "`uuid` CHAR(36) NOT NULL",
        "`name` VARCHAR(255) NOT NULL",
        "`duration` INT(10) UNSIGNED NOT NULL DEFAULT 0",
        "`uri` VARCHAR(512) NOT NULL DEFAULT ''",
        "`scheduling_url` VARCHAR(512) NOT NULL DEFAULT ''",
        "`product_id` BIGINT(20) UNSIGNED NOT NULL DEFAULT 0",
        "`description_html` TEXT DEFAULT NULL",
        "`active` TINYINT(1) NOT NULL DEFAULT 1",
        "`meta` LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`meta`))",
        "`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP",
        "`updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
    ],
    'keys' => [
        "PRIMARY KEY (`id`)",
        "UNIQUE KEY `uuid` (`uuid`)",
    ],
] );

self::migrate_table( 'cb_event_type_available_times', [
    'columns' => [
        "`id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT",
        "`event_type_id` BIGINT(20) UNSIGNED NOT NULL",
        "`status` ENUM('available','unavailable') NOT NULL DEFAULT 'available'",
        "`invitees_remaining` INT(10) UNSIGNED NOT NULL DEFAULT 0",
        "`start_time` DATETIME NOT NULL",
        "`scheduling_url` VARCHAR(512) NOT NULL",
        "`created_ts` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP",
        "`updated_ts` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
    ],
    'keys' => [
        "PRIMARY KEY (`id`)",
        "UNIQUE KEY `uniq_event_type_start` (`event_type_id`,`start_time`)",
        "KEY `idx_event_type` (`event_type_id`)",
        "KEY `idx_start_time` (`start_time`)",
    ],
] );

self::migrate_table( 'cb_meeting_locations', [
    'columns' => [
        "`id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT",
        "`code` VARCHAR(50) NOT NULL",
        "`name` VARCHAR(255) NOT NULL",
        "`type` VARCHAR(50) NOT NULL",
        "`value` TEXT DEFAULT NULL",
        "`additional_info` TEXT DEFAULT NULL",
        "`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP",
        "`updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
    ],
    'keys' => [
        "PRIMARY KEY (`id`)",
        "UNIQUE KEY `code` (`code`)",
    ],
] );

self::migrate_table( 'cb_scheduled_events', [
    'columns' => [
        "`id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT",
        "`uuid` CHAR(36) NOT NULL",
        "`order_id` VARCHAR(64) DEFAULT NULL",
        "`event_type_id` BIGINT(20) UNSIGNED NOT NULL",
        "`location_id` BIGINT(20) UNSIGNED DEFAULT NULL",
        "`name` VARCHAR(255) NOT NULL",
        "`start_time` DATETIME NOT NULL",
        "`end_time` DATETIME NOT NULL",
        "`status` ENUM('active','canceled','rescheduled','completed') NOT NULL DEFAULT 'active'",
        "`uri` VARCHAR(512) NOT NULL DEFAULT ''",
        "`reschedule_url` VARCHAR(512) DEFAULT NULL",
        "`cancel_url` VARCHAR(512) DEFAULT NULL",
        "`payload` LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`payload`))",
        "`created_ts` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP",
        "`updated_ts` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
        "`notes` TEXT DEFAULT NULL",
    ],
    'keys' => [
        "PRIMARY KEY (`id`)",
        "UNIQUE KEY `uuid` (`uuid`)",
        "KEY `idx_event_type` (`event_type_id`)",
        "KEY `idx_location` (`location_id`)",
        "KEY `idx_start_time` (`start_time`)",
        "KEY `idx_status` (`status`)",
    ],
] );

self::migrate_table( 'cb_scheduled_event_invitees', [
    'columns' => [
        "`id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT",
        "`scheduled_event_uuid` CHAR(36) NOT NULL",
        "`uuid` CHAR(36) NOT NULL",
        "`name` VARCHAR(255) NOT NULL",
        "`email` VARCHAR(255) NOT NULL",
        "`status` ENUM('active','canceled') NOT NULL DEFAULT 'active'",
        "`answers` LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`answers`))",
        "`payload` LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`payload`))",
        "`created_ts` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP",
        "`updated_ts` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
    ],
    'keys' => [
        "PRIMARY KEY (`id`)",
        "UNIQUE KEY `uuid` (`uuid`)",
        "KEY `idx_event_uuid` (`scheduled_event_uuid`)",
        "KEY `idx_email` (`email`)",
    ],
] );

self::migrate_table( 'cb_sync_state', [
    'columns' => [
        "`id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT",
        "`domain` VARCHAR(64) NOT NULL",
        "`cursor` VARCHAR(255) DEFAULT NULL",
        "`last_success` DATETIME DEFAULT NULL",
        "`last_error` DATETIME DEFAULT NULL",
        "`error_msg` TEXT DEFAULT NULL",
    ],
    'keys' => [
        "PRIMARY KEY (`id`)",
        "UNIQUE KEY `uniq_domain` (`domain`)",
    ],
] );


    }

    /**
     * General, idempotent table migrator.
     *
     * - Creates table if it does not exist.
     * - Adds missing columns.
     * - Adds missing indexes/keys.
     * - Never drops or alters existing columns (safe upgrade).
     *
     * @param string $table_name Logical table name without prefix (e.g., 'cb_invitees').
     * @param array  $schema     Array with 'columns' and 'keys'.
     */
    private static function migrate_table( string $table_name, array $schema ): void {
        global $wpdb;

        $full_table = $wpdb->prefix . $table_name;

        // 1. Does the table exist?
        $table_exists = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*)
                 FROM INFORMATION_SCHEMA.TABLES
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = %s",
                $full_table
            )
        );

        if ( $table_exists === 0 ) {
            // Create table fresh.
            $columns_sql = implode( ",\n", $schema['columns'] );
            $keys_sql    = implode( ",\n", $schema['keys'] );

            $sql = "CREATE TABLE {$full_table} (
                {$columns_sql},
                {$keys_sql}
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

            $wpdb->query( $sql );

            return;
        }

        // 2. Existing columns.
        $existing_columns = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT COLUMN_NAME
                 FROM INFORMATION_SCHEMA.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = %s",
                $full_table
            )
        );

        $existing_columns = $existing_columns ? $existing_columns : [];

        // 3. Add missing columns.
        foreach ( $schema['columns'] as $col_def ) {
            if ( ! preg_match( '/^`?(\w+)`?\s/i', $col_def, $m ) ) {
                continue;
            }

            $col_name = $m[1];

            if ( ! in_array( $col_name, $existing_columns, true ) ) {
                $wpdb->query( "ALTER TABLE {$full_table} ADD COLUMN {$col_def}" );
            }
        }

        // 4. Existing indexes.
        $existing_indexes = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT INDEX_NAME
                 FROM INFORMATION_SCHEMA.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = %s",
                $full_table
            )
        );

        $existing_indexes = $existing_indexes ? $existing_indexes : [];

        // 5. Add missing keys/indexes.
        foreach ( $schema['keys'] as $key_def ) {
            if ( ! preg_match( '/(PRIMARY KEY|UNIQUE KEY|KEY)\s+`?(\w+)`?/i', $key_def, $m ) ) {
                continue;
            }

            $key_name = strtoupper( $m[1] ) === 'PRIMARY KEY' ? 'PRIMARY' : $m[2];

            if ( in_array( $key_name, $existing_indexes, true ) && $key_name !== 'PRIMARY' ) {
                continue;
            }

            if ( $key_name === 'PRIMARY' ) {
                $has_primary = (int) $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT COUNT(*)
                         FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
                         WHERE TABLE_SCHEMA = DATABASE()
                           AND TABLE_NAME = %s
                           AND CONSTRAINT_TYPE = 'PRIMARY KEY'",
                        $full_table
                    )
                );

                if ( $has_primary > 0 ) {
                    continue;
                }
            }

            $wpdb->query( "ALTER TABLE {$full_table} ADD {$key_def}" );
        }
    }

    /**
     * Ensure foreign keys exist where appropriate.
     *
     * Uses FKs only when tables are InnoDB. Skips quietly otherwise.
     */
    private static function ensure_foreign_keys(): void {
        global $wpdb;

        $prefix = $wpdb->prefix;

$constraints = [
    [
        'name'       => 'fk_etat_event_type',
        'table'      => "{$prefix}cb_event_type_available_times",
        'column'     => 'event_type_id',
        'ref_table'  => "{$prefix}cb_event_types",
        'ref_column' => 'id',
        'on_delete'  => 'CASCADE',
        'on_update'  => 'CASCADE',
    ],
    [
        'name'       => 'fk_se_event_type',
        'table'      => "{$prefix}cb_scheduled_events",
        'column'     => 'event_type_id',
        'ref_table'  => "{$prefix}cb_event_types",
        'ref_column' => 'id',
        'on_delete'  => 'NO ACTION',
        'on_update'  => 'CASCADE',
    ],
];

        foreach ( $constraints as $fk ) {
            if ( ! self::table_supports_foreign_keys( $fk['table'] ) ) {
                continue;
            }

            $exists = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*)
                     FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS
                     WHERE CONSTRAINT_SCHEMA = DATABASE()
                       AND CONSTRAINT_NAME = %s",
                    $fk['name']
                )
            );

            if ( $exists > 0 ) {
                continue;
            }

            $sql = sprintf(
                'ALTER TABLE %s ADD CONSTRAINT %s FOREIGN KEY (%s) REFERENCES %s (%s) ON DELETE %s ON UPDATE %s',
                $fk['table'],
                $fk['name'],
                $fk['column'],
                $fk['ref_table'],
                $fk['ref_column'],
                $fk['on_delete'],
                $fk['on_update']
            );

            $wpdb->query( $sql );
        }
    }

    /**
     * Check whether a table supports foreign keys (i.e., is InnoDB).
     *
     * @param string $table_name Fully-qualified table name (with prefix).
     *
     * @return bool
     */
    private static function table_supports_foreign_keys( string $table_name ): bool {
        global $wpdb;

        $engine = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT ENGINE
                 FROM INFORMATION_SCHEMA.TABLES
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = %s",
                $table_name
            )
        );

        return strtoupper( (string) $engine ) === 'INNODB';
    }
    
    
    private static function create_meeting_page() { 
        $existing = get_option(self::PAGE_OPTION); 
        
        if ($existing && get_post($existing)) { 
            return; 
        } 
        
        $page_id = wp_insert_post([ 
            'post_title' => 'Meeting Scheduled', 
            'post_name' => self::PAGE_SLUG, 
            'post_status' => 'publish', 
            'post_type' => 'page', 
            'post_content' => '', 
        ]); 
        
        if (!is_wp_error($page_id)) { 
            update_option(self::PAGE_OPTION, $page_id); 
            
        }
    } 
	
	public static function get_page_option() {
		return self::PAGE_OPTION;
	}

    public static function uninstall() {
        $page_id = get_option(self::PAGE_OPTION);

        if ($page_id) {
            wp_delete_post($page_id, true);
            delete_option(self::PAGE_OPTION);
        }
    }
}