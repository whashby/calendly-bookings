<?php
namespace Calendly_Bookings\Modules;
use Calendly_Bookings\CB_Constants;
if (!defined('ABSPATH')) exit;
final class CB_Sync_Status {
    public const HOOKS = [
        'master' => 'cb_sync_master_cron',
        'scheduled_events' => 'cb_sync_scheduled_events_cron',
        'invitees' => 'cb_sync_invitees_cron',
        'event_types' => 'cb_sync_event_types_cron',
        'locations' => 'cb_sync_locations_cron',
    ];
    public static function record(string $domain, array $result): array {
        $key = 'cb_sync_result_' . $domain;
        $state = self::state($domain);
        $now = time();
        $state['last_attempt'] = $now;
        $state['success'] = !empty($result['success']);
        $state['errors'] = array_values(array_map('sanitize_text_field', (array) ($result['errors'] ?? [])));
        if ($state['success']) { $state['last_success'] = $now; unset($state['legacy']); }
        update_option($key, $state, false);
        $result['last_sync'] = gmdate('Y-m-d\TH:i:s\Z', $now);
        return $result;
    }
    public static function state(string $domain): array {
        $state = (array) get_option('cb_sync_result_' . $domain, []);
        if (!$state) {
            $legacy = [
                'master' => CB_Constants::OPT_LAST_SYNC_ALL,
                'scheduled_events' => CB_Constants::OPT_LAST_SYNC_SCHEDULED_EVENTS,
                'invitees' => CB_Constants::OPT_LAST_SYNC_SCHEDULED_EVENT_INVITEES,
                'event_types' => CB_Constants::OPT_LAST_SYNC_EVENT_TYPES,
                'locations' => CB_Constants::OPT_LAST_SYNC_LOCATIONS,
                'availability' => CB_Constants::OPT_LAST_SYNC_EVENT_TYPE_AVAILABLE_TIMES,
            ];
            $old = isset($legacy[$domain]) ? get_option($legacy[$domain], 0) : 0;
            // Legacy current_time('timestamp') is a site-offset timestamp, not Unix UTC.
            if (is_numeric($old) && (int) $old > 0) {
                $state['last_success'] = (new \DateTimeImmutable(gmdate('Y-m-d H:i:s', (int) $old), wp_timezone()))->getTimestamp();
                $state['legacy'] = true;
            }
        }
        return $state;
    }
    public static function schedules(): array {
        $schedules = wp_get_schedules(); $result = [];
        foreach (self::HOOKS as $domain => $hook) {
            $event = wp_get_scheduled_event($hook);
            $state = self::state($domain);
            $result[$domain] = [
                'enabled' => (bool) $event,
                'frequency' => $event ? $event->schedule : null,
                'frequency_label' => $event ? ($schedules[$event->schedule]['display'] ?? $event->schedule) : null,
                'next_run' => $event ? (int) $event->timestamp : null,
                'last_success' => self::iso($state['last_success'] ?? 0),
                'last_attempt' => self::iso($state['last_attempt'] ?? 0),
                'success' => $state['success'] ?? null,
                'errors' => $state['errors'] ?? [],
                'legacy' => !empty($state['legacy']),
            ];
        }
        return $result;
    }
    public static function iso($timestamp): ?string {
        return (int) $timestamp > 0 ? gmdate('Y-m-d\TH:i:s\Z', (int) $timestamp) : null;
    }
}
