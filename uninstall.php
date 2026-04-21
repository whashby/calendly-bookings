<?php
// uninstall.php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

// List of custom tables
$tables = [
    "{$wpdb->prefix}cb_sync_state",
    "{$wpdb->prefix}cb_scheduled_event_invitees",
    "{$wpdb->prefix}cb_scheduled_events",
    "{$wpdb->prefix}cb_meeting_locations",
    "{$wpdb->prefix}cb_event_type_available_times",
    "{$wpdb->prefix}cb_event_types",
    "{$wpdb->prefix}cb_audit_log",
];

// Loop through and drop safely
foreach ( $tables as $table ) {
    $exists = $wpdb->get_var( $wpdb->prepare(
        "SHOW TABLES LIKE %s", $table
    ) );

    if ( $exists === $table ) {
        $wpdb->query( "DROP TABLE IF EXISTS $table" );
        error_log( "[Calendly Bookings Uninstall] Dropped table: {$table}" );
    } else {
        error_log( "[Calendly Bookings Uninstall] Table not found, skipped: {$table}" );
    }
}

// Remove plugin options
$options = [
    'cb_schema_version',
    'cb_remove_data_on_uninstall', // if you added a setting for purge choice
];

foreach ( $options as $option ) {
    if ( get_option( $option ) !== false ) {
        delete_option( $option );
        error_log( "[Calendly Bookings Uninstall] Deleted option: {$option}" );
    } else {
        error_log( "[Calendly Bookings Uninstall] Option not found, skipped: {$option}" );
    }
}

// Clean transients if any
global $wpdb;
$transients = $wpdb->get_col(
    "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '_transient_cb_%'"
);
foreach ( $transients as $transient ) {
    delete_option( $transient );
    error_log( "[Calendly Bookings Uninstall] Deleted transient: {$transient}" );
}

error_log( "[Calendly Bookings Uninstall] Uninstall routine completed." );
