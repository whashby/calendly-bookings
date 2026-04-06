<?php

namespace Calendly_Bookings\Modules;

if (!defined('ABSPATH')) {
    exit;
}

use Calendly_Bookings\CB_Constants;

final class CB_Plugin{
    public static function init():void{
        CB_Audit_Log::log('method_entry', 'plugin', __METHOD__, [], 'info');
        try {
            add_action('admin_init',[__CLASS__,'register_settings']);
            CB_Audit_Log::log('method_exit', 'plugin', __METHOD__, [], 'info');
        } catch (\Throwable $e) {
            CB_Audit_Log::log('error', 'plugin', __METHOD__, ['error' => $e->getMessage()], 'error');
        }
    }

public static function register_settings(): void {
    CB_Audit_Log::log('method_entry', 'plugin', __METHOD__, [], 'info');
    try {
        register_setting(CB_Constants::OPT_GROUP, CB_Constants::OPT_API_TOKEN, ['sanitize_callback' => 'sanitize_text_field']);
        register_setting(CB_Constants::OPT_GROUP, CB_Constants::OPT_USER_UUID, ['sanitize_callback' => 'sanitize_text_field']);
        register_setting(CB_Constants::OPT_GROUP, CB_Constants::OPT_LICENSE_KEY, ['sanitize_callback' => 'sanitize_text_field']);
        register_setting(CB_Constants::OPT_GROUP, CB_Constants::OPT_SYNC_INTERVAL, ['sanitize_callback' => 'absint']);
        register_setting(CB_Constants::OPT_GROUP, CB_Constants::OPT_MIN_START_DATE, ['sanitize_callback' => 'sanitize_text_field']);
        register_setting(CB_Constants::OPT_GROUP, CB_Constants::OPT_LAST_SYNC, ['sanitize_callback' => 'sanitize_text_field']);
        register_setting(CB_Constants::OPT_GROUP, CB_Constants::OPT_LAST_SYNC_ALL, ['sanitize_callback' => 'sanitize_text_field']);
        register_setting(CB_Constants::OPT_GROUP, CB_Constants::OPT_LAST_SYNC_EVENT_TYPES, ['sanitize_callback' => 'sanitize_text_field']);
        register_setting(CB_Constants::OPT_GROUP, CB_Constants::OPT_LAST_SYNC_EVENT_TYPE_AVAILABLE_TIMES, ['sanitize_callback' => 'sanitize_text_field']);
        register_setting(CB_Constants::OPT_GROUP, CB_Constants::OPT_LAST_SYNC_SCHEDULED_EVENTS, ['sanitize_callback' => 'sanitize_text_field']);
        register_setting(CB_Constants::OPT_GROUP, CB_Constants::OPT_LAST_SYNC_SCHEDULED_EVENT_INVITEES, ['sanitize_callback' => 'sanitize_text_field']);
        register_setting(CB_Constants::OPT_GROUP, CB_Constants::OPT_LAST_SYNC_LOCATIONS, ['sanitize_callback' => 'sanitize_text_field']);
        register_setting(CB_Constants::OPT_GROUP, CB_Constants::OPT_WEBHOOK_SECRET, ['sanitize_callback' => 'sanitize_text_field']);
        register_setting(CB_Constants::OPT_GROUP, CB_Constants::OPT_WEBHOOK_URL, ['sanitize_callback' => 'sanitize_text_field']);

        register_setting(CB_Constants::OPT_GROUP, CB_Constants::OPT_EMAIL_HEADER);
        register_setting(CB_Constants::OPT_GROUP, CB_Constants::OPT_EMAIL_BODY);
        register_setting(CB_Constants::OPT_GROUP, CB_Constants::OPT_EMAIL_FOOTER);
        register_setting(CB_Constants::OPT_GROUP, CB_Constants::OPT_EMAIL_TO);
        register_setting(CB_Constants::OPT_GROUP, CB_Constants::OPT_EMAIL_FROM);
        register_setting(CB_Constants::OPT_GROUP, CB_Constants::OPT_EMAIL_REPLY_TO);
        register_setting(CB_Constants::OPT_GROUP, CB_Constants::OPT_EMAIL_BCC);

        CB_Audit_Log::log('method_exit', 'plugin', __METHOD__, [], 'info');
    } catch (\Throwable $e) {
        CB_Audit_Log::log('error', 'plugin', __METHOD__, ['error' => $e->getMessage()], 'error');
    }
}
}
