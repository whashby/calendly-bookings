<?php

namespace Calendly_Bookings;

if (!defined('ABSPATH')) {
    exit;
}

final class CB_Constants {
    public const VERSION = '6.9.188';
    public const OPT_GROUP = 'calendly_bookings';

    public const OPT_API_TOKEN = 'cb_api_token';
    public const OPT_USER_UUID = 'cb_user_uuid';
    public const OPT_LICENSE_KEY = 'cb_license_key';

    public const OPT_SYNC_INTERVAL = 'cb_sync_interval';
    public const OPT_MIN_START_DATE = 'cb_min_start_date';
    public const OPT_LAST_SYNC = 'cb_last_sync';
    public const OPT_LAST_SYNC_ALL = 'cb_last_sync_all';
    
    public const OPT_LAST_SYNC_EVENT_TYPES = 'cb_last_sync_event_types';
    public const OPT_LAST_SYNC_EVENT_TYPE_AVAILABLE_TIMES = 'cb_last_sync_event_type_available_times';
    public const OPT_LAST_SYNC_SCHEDULED_EVENTS = 'cb_last_sync_scheduled_events';
    public const OPT_LAST_SYNC_SCHEDULED_EVENT_INVITEES = 'cb_last_sync_scheduled_event_invitees';
    public const OPT_LAST_SYNC_LOCATIONS = 'cb_last_sync_locations';

    public const OPT_WEBHOOK_SECRET = 'cb_webhook_secret';
    public const OPT_WEBHOOK_URL = 'cb_webhook_url';

    public const OPT_EMAIL_HEADER = 'cb_email_header';
    public const OPT_EMAIL_BODY = 'cb_email_body';
    public const OPT_EMAIL_FOOTER = 'cb_email_footer';
    public const OPT_EMAIL_TO = 'cb_email_to';
    public const OPT_EMAIL_FROM = 'cb_email_from';
    public const OPT_EMAIL_REPLY_TO = 'cb_email_reply_to';
    public const OPT_EMAIL_BCC = 'cb_email_bcc';

    public const OPT_REPORT_TEMPLATE = 'cb_report_template';
    public const OPT_REPORT_FILETYPE = 'cb_report_filetype';
    public const OPT_REPORT_SCHEDULE = 'cb_report_schedule';

    public const OPT_GITHUB_TOKEN = 'cb_github_token';

    public const CB_API_BASE_URL = 'https://api.calendly.com';
    public const CB_WORKER_ENDPOINT = 'https://calendly-bookings-worker.whashby.workers.dev';


    public const API_USER_AGENT = 'CalendlyBookingsWP/' . self::VERSION;
    public const GITHUB_RAW_URL = 'https://raw.githubusercontent.com/whashby/calendly-bookings/main/';
    public const GITHUB_REPO_URL = 'https://github.com/whashby/calendly-bookings';
    public const GITHUB_API_REPO_URL = 'https://api.github.com/repos/whashby/calendly-bookings';
    public const GITHUB_RELEASES_URL = self::GITHUB_API_REPO_URL . '/releases';



    public const OPT_LICENSE_STATUS = 'cb_license_status';
    public const OPT_LAST_REPORT_MONTH = 'cb_last_report_month';
    public const TRANSIENT_SYNC_LOCK = 'cb_sync_lock';
    public const TRANSIENT_SYNC_LOCK_TIMEOUT = 300; // 5 minutes
    public const TRANSIENT_SYNC_INTERVAL = 300; // 5 minutes

    public static function plugin_file(): string {
        return dirname(__DIR__) . '/calendly-bookings.php';
    }
    public static function url(string $path = ''): string {
        return plugins_url(ltrim($path, '/'), dirname(__FILE__, 2) . '/calendly-bookings.php');
    }
    public static function path(string $path = ''): string {
        return plugin_dir_path(dirname(__FILE__)) . ltrim($path, '/');
    }
    
}
