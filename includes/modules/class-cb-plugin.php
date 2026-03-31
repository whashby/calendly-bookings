<?php
namespace Calendly_Bookings\Modules;
use Calendly_Bookings\CB_Constants;
if(!defined('ABSPATH'))exit;
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
    public static function register_settings():void{
        CB_Audit_Log::log('method_entry', 'plugin', __METHOD__, [], 'info');
        try {
            register_setting(CB_Constants::OPT_GROUP,CB_Constants::OPT_SYNC_INTERVAL);
            CB_Audit_Log::log('method_exit', 'plugin', __METHOD__, [], 'info');
        } catch (\Throwable $e) {
            CB_Audit_Log::log('error', 'plugin', __METHOD__, ['error' => $e->getMessage()], 'error');
        }
    }
}
