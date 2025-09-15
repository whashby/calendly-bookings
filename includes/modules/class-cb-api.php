<?php
// includes/modules/class-cb-api.php
declare(strict_types=1);

namespace Calendly_Bookings\Modules;

use Calendly_Bookings\CB_Constants;

if (!defined('ABSPATH')) exit;

final class CB_API {
    private const API_BASE = 'https://api.calendly.com';
    private string $token;
    private string $user_uuid;

    public function __construct(?string $token = null, ?string $user_uuid = null) {
        $this->token     = $token     ?: (string) get_option(CB_Constants::OPT_API_TOKEN, '');
        $this->user_uuid = $user_uuid ?: (string) get_option(CB_Constants::OPT_USER_UUID, '');		
    }

    private function headers(): array {
        return [
            'Authorization' => 'Bearer ' . $this->token,
            'Content-Type'  => 'application/json',
        ];
    }

    private function cache_key(string $url): string {
        return 'cb_api_' . md5($url);
    }

    private function get_cached(string $url): ?array {
        $cached = get_transient($this->cache_key($url));
        return is_array($cached) ? $cached : null;
    }

    private function set_cached(string $url, array $value, int $ttl): void {
        set_transient($this->cache_key($url), $value, $ttl);
    }

    private function build_url(string $path, array $query, bool $remove_user): string {
        $base = rtrim(self::API_BASE, '/');
        $normalizedPath = '/' . ltrim($path, '/');

        $existing = [];
        $parsed = wp_parse_url($normalizedPath);
        if (!empty($parsed['query'])) {
            parse_str($parsed['query'], $existing);
            $normalizedPath = str_replace('?' . $parsed['query'], '', $normalizedPath);
        }
        $params = array_merge($existing, $query);

        if ($remove_user) {
            unset($params['user']);
        } elseif ($this->user_uuid && empty($params['user'])) {
            $params['user'] = self::API_BASE . '/users/' . $this->user_uuid;
        }

        $url = $base . $normalizedPath;
        if (!empty($params)) $url = add_query_arg($params, $url);
        return esc_url_raw($url);
    }

    private function get(string $path, array $query = [], bool $remove_user = false, int $ttl = 60): array {
        $url = $this->build_url($path, $query, $remove_user);

        if ($ttl > 0 && ($hit = $this->get_cached($url))) {
            return $hit;
        }

        $t0 = microtime(true);
        $res = wp_remote_get($url, ['headers' => $this->headers(), 'timeout' => 20]);
        $dur = (int) round((microtime(true) - $t0) * 1000);

        if (is_wp_error($res)) {
            CB_Logger::log('api_error', ['endpoint' => $path, 'method' => 'GET', 'error' => $res->get_error_message(), 'duration_ms' => $dur]);
            return ['error' => $res->get_error_message()];
        }

        $code = wp_remote_retrieve_response_code($res);
        $body = json_decode(wp_remote_retrieve_body($res), true) ?: [];

        CB_Logger::log('api_response', ['endpoint' => $path, 'method' => 'GET', 'status' => $code, 'duration_ms' => $dur]);

        if ($code >= 200 && $code < 300) {
            if ($ttl > 0) $this->set_cached($url, $body, $ttl);
            return is_array($body) ? $body : [];
        }

        return ['error' => 'HTTP ' . $code, 'body' => $body];
    }

    // Persist a single event type row
    private function upsert_event_type(array $t): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'cb_event_types';

        $uuid     = basename((string)($t['uri'] ?? '')) ?: (string)($t['uuid'] ?? '');
        $name     = sanitize_text_field($t['name'] ?? '');
        $duration = isset($t['duration']) ? absint($t['duration']) : 0;
        $uri      = esc_url_raw((string)($t['uri'] ?? ''));
        $meta     = wp_json_encode($t);

        if (!$uuid || !$name) return false;

        $existing_pid = $wpdb->get_var($wpdb->prepare("SELECT product_id FROM $table WHERE uuid=%s", $uuid));
        $wpdb->replace(
            $table,
            [
                'uuid'       => $uuid,
                'name'       => $name,
                'duration'   => $duration,
                'uri'        => $uri,
                'meta'       => $meta,
                'product_id' => $existing_pid,
            ]
        );
        return true;
    }

    /**
     * Event types:
     * - When $uuid is null: fetch all pages, persist to DB, return report.
     * - When $uuid is set: fetch exactly that event type, persist, return the row.
     */
    public function get_event_types(?string $uuid = null, bool $persist = true): array {
        if ($uuid !== null) {
            // Single event type
            $path = '/event_types/' . urlencode($uuid);
            $res  = $this->get($path, [], false, 60);
            if (!empty($res['error'])) return ['error' => $res['error']];

            $resource = $res['resource'] ?? null;
            if (!$resource) return ['error' => 'Not found'];

            if ($persist) $this->upsert_event_type($resource);
            return ['collection' => [$resource], 'count' => 1];
        }

        // All event types (paginated)
        $all = [];
        $next_token = null;

        do {
            $query = ['count' => 100];
            if ($next_token) $query['page_token'] = $next_token;

            $res = $this->get('/event_types', $query, false, 120);
            if (!empty($res['error'])) return ['error' => $res['error']];

            $page = $res['collection'] ?? [];
            foreach ($page as $t) {
                $all[] = $t;
                if ($persist) $this->upsert_event_type($t);
            }

            // Calendly pagination may expose page.next_page or pagination.next_page_token — support both
            $pagination = $res['pagination'] ?? $res['page'] ?? [];
            $next_token = $pagination['next_page_token'] ?? ($pagination['next_page'] ?? null);
        } while (!empty($next_token));

        return ['collection' => $all, 'count' => count($all)];
    }

    public function get_event_type_availability(string $event_type_uri, string $start_iso): array {
#		$start_time = new \DateTime('now');
#		$start_iso = $start_time->format('Y-m-d');

		try { $dt = new \DateTimeImmutable($start_iso); } catch (\Exception $e) { $dt = new \DateTimeImmutable('now'); }
        $utc = $dt->setTimezone(new \DateTimeZone('UTC'));
        $m = (int) $utc->format('i'); $s = (int) $utc->format('s');
        if ($m === 0 && $s === 0) { $rounded = $utc->setTime((int) $utc->format('H'), 30, 0); }
        elseif ($m < 30) { $rounded = $utc->setTime((int) $utc->format('H'), 30, 0); }
        else { $rounded = $utc->modify('+1 hour')->setTime((int) $utc->modify('+1 hour')->format('H'), 0, 0); }

        $start_utc_iso = $rounded->format('Y-m-d\TH:i:s\Z');
        $end_utc_iso   = $rounded->modify('+7 days')->format('Y-m-d\TH:i:s\Z');

		return $this->get('/event_type_available_times', [
            'event_type' => $event_type_uri,
            'start_time' => $start_utc_iso,
            'end_time'   => $end_utc_iso,
        ], true, 60);
    }

    public function get_upcoming_events(int $count = 50): array {
        global $wpdb;

        $events = $this->get('/scheduled_events', [
            'count'          => $count,
            'min_start_time' => gmdate('Y-m-d\TH:i:s\Z'),
            'sort'           => 'start_time:asc',
        ], false, 30);

        if (!is_array($events) || empty($events['collection'])) {
            return $events;
        }

        $seenUuids = [];

		foreach ($events['collection'] as $event) {
            $uuid = $event['uuid'] ?? '';
            if (!$uuid) continue;
            $seenUuids[] = $uuid;

            $name       = $event['name'] ?? '';
            $status     = $event['status'] ?? '';
            $start_time = !empty($event['start_time']) ? gmdate('Y-m-d H:i:s', strtotime($event['start_time'])) : null;
            $end_time   = !empty($event['end_time']) ? gmdate('Y-m-d H:i:s', strtotime($event['end_time'])) : null;
            $location   = $event['location']['location'] ?? '';
            $payload    = wp_json_encode($event);

            $wpdb->query(
                $wpdb->prepare(
                    "INSERT INTO {$wpdb->prefix}cb_scheduled_events
                        (uuid, name, status, start_time, end_time, location, payload)
                     VALUES (%s, %s, %s, %s, %s, %s, %s)
                     ON DUPLICATE KEY UPDATE
                        name = VALUES(name),
                        status = VALUES(status),
                        start_time = VALUES(start_time),
                        end_time = VALUES(end_time),
                        location = VALUES(location),
                        payload = VALUES(payload)",
                    $uuid, $name, $status, $start_time, $end_time, $location, $payload
                )
            );
        }

        if (!empty($seenUuids)) {
            $placeholders = implode(',', array_fill(0, count($seenUuids), '%s'));
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM {$wpdb->prefix}cb_scheduled_events
                     WHERE start_time >= NOW()
                       AND uuid NOT IN ($placeholders)",
                    ...$seenUuids
                )
            );
        }

        return $events;
    }

    public function get_event_invitees(string $scheduled_event_uri): array {
        return $this->get(str_replace(self::API_BASE, '', $scheduled_event_uri) . '/invitees', [], true, 30);
    }

    public function manual_connection_test(): array {
        if ($this->token === '') return ['success' => false, 'message' => __('No API token found.', 'calendly-bookings')];
        $res = $this->get('/users/me', [], true, 0);
        if (!empty($res['error'])) return ['success' => false, 'message' => $res['error']];
        return !empty($res['resource'])
            ? ['success' => true, 'message' => __('Calendly API connection successful.', 'calendly-bookings')]
            : ['success' => false, 'message' => __('Unexpected API response.', 'calendly-bookings')];
    }

    public function test_connection(): array {
        $types = $this->get_event_types(null, false);
        if (!empty($types['error'])) return $types;
        return ['ok' => true, 'count' => isset($types['collection']) ? count($types['collection']) : 0];
    }

    public function normalize_availability(array $collection): array {
        $tz = wp_timezone(); $byDate = [];
        foreach ($collection as $slot) {
            $start = $slot['start_time'] ?? ($slot['start_time_utc'] ?? null);
            if (!$start) continue;
            $dtUtc = new \DateTimeImmutable($start, new \DateTimeZone('UTC'));
            $dtLocal = $dtUtc->setTimezone($tz);
            $dateKey = $dtLocal->format('Y-m-d');
            $byDate[$dateKey][] = [
                'start_iso'       => $dtUtc->format('Y-m-d\TH:i:s\Z'),
                'start_local_iso' => $dtLocal->format('c'),
                'label'           => $dtLocal->format('H:i'),
            ];
        }
        ksort($byDate);
        $out = [];
        foreach ($byDate as $date => $slots) {
            usort($slots, fn($a,$b)=>strcmp($a['start_iso'],$b['start_iso']));
            $out[] = ['date'=>$date,'slots'=>$slots];
        }
        return $out;
    }

    public static function clear_cache(): void {
        global $wpdb;
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                $wpdb->esc_like('_transient_cb_api_') . '%',
                $wpdb->esc_like('_transient_timeout_cb_api_') . '%'
            )
        );
    }

    public function rebuild_links(): array {
        $types = $this->get_event_types(null, true);
        if (!empty($types['error'])) {
            return ['success' => false, 'error' => $types['error']];
        }

        $collection = $types['collection'] ?? [];
        $byUuid = [];
        foreach ($collection as $t) {
            $uuid = $t['uuid'] ?? basename((string)($t['uri'] ?? ''));
            if ($uuid) $byUuid[$uuid] = $t;
        }

        $args = [
            'post_type'      => 'product',
            'posts_per_page' => -1,
            'post_status'    => 'any',
            'meta_query'     => [[ 'key' => '_cb_event_uuid', 'compare' => 'EXISTS' ]],
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ];
        $q = new \WP_Query($args);
        $linked = [];
        $unknown_in_calendly = [];
        $missing_in_wp = [];

        foreach ($q->posts as $pid) {
            $uuid = (string) get_post_meta($pid, '_cb_event_uuid', true);
            if (!$uuid) continue;

            if (isset($byUuid[$uuid])) {
                $linked[] = [
                    'uuid'       => $uuid,
                    'product_id' => $pid,
                    'product'    => get_the_title($pid),
                    'event_name' => $byUuid[$uuid]['name'] ?? '',
                ];
            } else {
                $unknown_in_calendly[] = [
                    'uuid'       => $uuid,
                    'product_id' => $pid,
                    'product'    => get_the_title($pid),
                ];
            }
        }

        foreach (array_keys($byUuid) as $uuid) {
            $found = false;
            foreach ($linked as $row) { if ($row['uuid'] === $uuid) { $found = true; break; } }
            if (!$found) {
                $missing_in_wp[] = [
                    'uuid'       => $uuid,
                    'event_name' => $byUuid[$uuid]['name'] ?? '',
                ];
            }
        }

        return [
            'success'            => true,
            'linked'             => $linked,
            'missing_in_wp'      => $missing_in_wp,
            'unknown_in_calendly'=> $unknown_in_calendly,
            'counts'             => [
                'calendly_event_types' => count($byUuid),
                'linked'               => count($linked),
                'missing_in_wp'        => count($missing_in_wp),
                'unknown_in_calendly'  => count($unknown_in_calendly),
            ],
        ];
    }

public function sync(int $upcoming_count = 100): array {
    $results = [
        'event_types_upserted' => 0,
        'events_upserted'      => 0,
        'events_deleted'       => 0,
        'errors'               => [],
    ];

    // 1. Sync Event Types
    $types = $this->get_event_types(null, true);
    if (!empty($types['error'])) {
        $results['errors'][] = ['stage' => 'event_types', 'error' => $types['error']];
    } else {
        $results['event_types_upserted'] = (int) ($types['count'] ?? count($types['collection'] ?? []));
    }

    // 2. Sync Scheduled Events
    $before = $this->count_future_events();
    $events = $this->get_upcoming_events($upcoming_count);

    if (!empty($events['error'])) {
        $results['errors'][] = ['stage' => 'scheduled_events', 'error' => $events['error']];
    } else {
        $after = $this->count_future_events();
        $results['events_upserted'] = max(0, $after - $before);

        // Estimate deletions based on UUID diff (if tracked)
        $results['events_deleted'] = $events['deleted'] ?? 0;
    }

    // 3. Update sync timestamp
    update_option('cb_last_sync', current_time('timestamp'));

    return [
        'success'               => empty($results['errors']),
        'last_sync'            => current_time('mysql'),
        'event_types_upserted' => $results['event_types_upserted'],
        'events_upserted'      => $results['events_upserted'],
        'events_deleted'       => $results['events_deleted'],
        'errors'               => $results['errors'],
    ];
}

    private function count_future_events(): int {
        global $wpdb;
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}cb_scheduled_events WHERE start_time >= NOW()");
    }
	
	
	
	
	public function foo(): void {
		$start_time = new \DateTime('now');
		$start_iso = $start_time->format('Y-m-d');
        try { $dt = new \DateTimeImmutable($start_iso); } catch (\Exception $e) { $dt = new \DateTimeImmutable('now'); }
        $utc = $dt->setTimezone(new \DateTimeZone('UTC'));
        $m = (int) $utc->format('i'); $s = (int) $utc->format('s');
        if ($m === 0 && $s === 0) { $rounded = $utc->setTime((int) $utc->format('H'), 30, 0); }
        elseif ($m < 30) { $rounded = $utc->setTime((int) $utc->format('H'), 30, 0); }
        else { $rounded = $utc->modify('+1 hour')->setTime((int) $utc->modify('+1 hour')->format('H'), 0, 0); }

        $start_utc_iso = $rounded->format('Y-m-d\TH:i:s\Z');
        $end_utc_iso   = $rounded->modify('+7 days')->format('Y-m-d\TH:i:s\Z');
echo "start: $start_utc_iso  end: $end_utc_iso"; exit;

	}
}
