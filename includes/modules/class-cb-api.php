<?php

declare(strict_types=1);
namespace Calendly_Bookings\Modules;

if (!defined('ABSPATH')) {
    exit;
}

use Calendly_Bookings\CB_Constants;
use Calendly_Bookings\Modules\CB_Audit_Log;
use Calendly_Bookings\Utils\CB_Timezone_Converter;

final class CB_API {

    /** @var self|null */
    private static $instance = null;

    /** API base URL */
    private const API_BASE = 'https://api.calendly.com';

    /** Instance properties */
    private string $token;
    private string $user_uuid;

    /* Initialize the module (called on plugins_loaded) */
    public static function init(): void {
        CB_Audit_Log::log('method_entry', 'api', __METHOD__, [], 'info');
        try {
            // Nothing to initialize for now.
            CB_Audit_Log::log('method_exit', 'api', __METHOD__, [], 'info');
        } catch (\Throwable $e) {
            CB_Audit_Log::log('error', 'api', __METHOD__, ['error' => $e->getMessage()], 'error');
        }
    }

    /* Constructor is private to enforce singleton pattern */
    public function __construct(?string $token = null, ?string $user_uuid = null) {
        $this->token     = $token     ?: (string) get_option(CB_Constants::OPT_API_TOKEN, '');
        $this->user_uuid = $user_uuid ?: (string) get_option(CB_Constants::OPT_USER_UUID, '');
    }

    /**
     * Singleton instance accessor.
     * @return self
     */
    public static function instance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /* HTTP helpers */
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

    private function build_url(string $path, array $query = [], bool $remove_user = false): string {
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
        if (!empty($params)) {
            $url = add_query_arg($params, $url);
        }

        return esc_url_raw($url);
    }

	private function get(string $path, array $query = [], bool $remove_user = false, int $ttl = 60): array {
		$url = $this->build_url($path, $query, $remove_user);

		if ($ttl > 0 && ($hit = $this->get_cached($url))) {
			return $hit;
		}

		$t0  = microtime(true);
		CB_Audit_Log::log('debug', 'api', $path, [
            'url' => $url,
            'query' => $query,
        ], 'info');

		$res = wp_remote_get($url, ['headers' => $this->headers(), 'timeout' => 20]);
		$dur = (int) round((microtime(true) - $t0) * 1000);

		if (is_wp_error($res)) {
			CB_Audit_Log::log('api_error', 'api', $path, [
				'url'        => $url,
				'method'     => 'GET',
				'duration_ms'=> $dur,
				'error'      => $res->get_error_message(),
			], 'error');
			return ['error' => true, 'message' => $res->get_error_message()];
		}

		$code = wp_remote_retrieve_response_code($res);
		$body = wp_remote_retrieve_body($res);

		CB_Audit_Log::log('api_response', 'api', $path, [
			'url'        => $url,
			'method'     => 'GET',
			'status'     => $code,
			'duration_ms'=> $dur,
		], $code === 200 ? 'info' : 'warning');

		if ($code !== 200) {
			return ['error' => true, 'status' => $code, 'body' => $body];
		}

		$data = json_decode($body, true) ?: [];
		if (!empty($data) && $ttl > 0) {
			$this->set_cached($url, $data, $ttl);
		}
		return $data;
	}
	
        
    public function sync(string $min_start_date = '', bool $force = false): array {
        CB_Audit_Log::log('method_entry', 'api', __METHOD__, [], 'info');

        $results = [
            'locations'                  => [],
            'scheduled_events'           => [],
            'scheduled_event_invitees'   => [],
            'event_types'                => [],
            'event_type_available_times' => [],
            'errors'                     => [],
        ];

        try {
            // Core syncs
            $results['locations']                  = $this->sync_locations();
            $results['scheduled_events']           = $this->sync_scheduled_events($min_start_date, $force);
            $results['scheduled_event_invitees']   = $this->sync_scheduled_event_invitees();
            $results['event_types']                = $this->sync_event_types();
            $results['event_type_available_times'] = $this->sync_event_type_available_times();

            // Collect errors from each sync
            foreach ($results as $key => $res) {
                if (is_array($res) && !empty($res['errors'])) {
                    $results['errors'] = array_merge($results['errors'], $res['errors']);
                }
            }

            // Update global sync timestamp
            update_option(CB_Constants::OPT_LAST_SYNC_ALL, current_time('timestamp'));

            CB_Audit_Log::log('info', 'sync_all', 'master', [
                'event_types_upserted'      => $results['event_types']['upserted'] ?? 0,
                'scheduled_events_upserted' => $results['scheduled_events']['upserted'] ?? 0,
                'available_times_upserted'  => $results['event_type_available_times']['upserted'] ?? 0,
                'invitees_upserted'         => $results['scheduled_event_invitees']['upserted'] ?? 0,
                'errors'                    => $results['errors'],
            ]);
        } catch (\Throwable $e) {
            $results['errors'][] = $e->getMessage();
            CB_Audit_Log::log('error', 'sync_all', 'exception', [
                'error' => $e->getMessage()
            ]);
        }

        CB_Audit_Log::log('method_exit', 'api', __METHOD__, ['success' => empty($results['errors'])], 'info');
        return [
            'success'   => empty($results['errors']),
            'last_sync' => current_time('mysql'),
            'results'   => $results,
            'errors'    => $results['errors'],
        ];
    }


    public function query_event_types(): array {
        CB_Audit_Log::log('method_entry', 'api', __METHOD__, [], 'info');
        try {
            $res = $this->get('/event_types', ['count' => 100], false, 120);
            $result = $res['collection'] ?? [];
            CB_Audit_Log::log('method_exit', 'api', __METHOD__, ['count' => count($result)], 'info');
            return $result;
        } catch (\Throwable $e) {
            CB_Audit_Log::log('error', 'api', __METHOD__, ['error' => $e->getMessage()], 'error');
            return [];
        }
    }

    public function set_event_types(array $event_types): int {
        CB_Audit_Log::log('method_entry', 'api', __METHOD__, ['count' => count($event_types)], 'info');
        try {
            global $wpdb;
            $table = $wpdb->prefix . 'cb_event_types';
            $count = 0;

            foreach ($event_types as $t) {
                $uuid = basename($t['uri']);
                if (!$uuid) continue;

                $wpdb->query($wpdb->prepare(
                    "INSERT INTO $table (uuid, name, duration, uri, scheduling_url, description_html, meta, active, created_at, updated_at)
                    VALUES (%s, %s, %d, %s, %s, %s, %s, 1, NOW(), NOW())
                    ON DUPLICATE KEY UPDATE
                    name=VALUES(name),
                    duration=VALUES(duration),
                    uri=VALUES(uri),
                    scheduling_url=VALUES(scheduling_url),
                    description_html=VALUES(description_html),
                    meta=VALUES(meta),
                    active=VALUES(active),
                    updated_at=NOW()",
                    $uuid,
                    sanitize_text_field($t['name'] ?? ''),
                    absint($t['duration'] ?? 0),
                    esc_url_raw($t['uri'] ?? ''),
                    esc_url_raw($t['scheduling_url'] ?? ''),
                    $t['description_html'] ?? '',
                    wp_json_encode($t)
                ));
                $count++;
            }
            CB_Audit_Log::log('method_exit', 'api', __METHOD__, ['inserted' => $count], 'info');
            return $count;
        } catch (\Throwable $e) {
            CB_Audit_Log::log('error', 'api', __METHOD__, ['error' => $e->getMessage()], 'error');
            return 0;
        }
    }

    public function get_event_types(bool $active_only = false): array {
        CB_Audit_Log::log('method_entry', 'api', __METHOD__, ['active_only' => $active_only], 'info');
        try {
            global $wpdb;
            $result = $wpdb->get_results(
                "SELECT et.id, et.uuid, et.name, et.duration, et.scheduling_url, et.uri, et.description_html, et.meta, et.product_id,
            MAX(p.post_title) AS product_title,
            COUNT(se.uuid) AS scheduled_count
            FROM {$wpdb->prefix}cb_event_types AS et
            LEFT JOIN {$wpdb->prefix}cb_scheduled_events AS se
            ON se.event_type_id = et.id
            LEFT JOIN {$wpdb->posts} AS p
            ON et.product_id = p.ID
            WHERE et.active" . ($active_only ? '=1' : '<>0') . "
            GROUP BY et.id
            ORDER BY et.name ASC",
                ARRAY_A
            );
            CB_Audit_Log::log('method_exit', 'api', __METHOD__, ['count' => count($result)], 'info');
            return $result;
        } catch (\Throwable $e) {
            CB_Audit_Log::log('error', 'api', __METHOD__, ['error' => $e->getMessage()], 'error');
            return [];
        }
    }

    public function sync_event_types(): array {
        CB_Audit_Log::log('method_entry', 'api', __METHOD__, [], 'info');
        $results = ['upserted' => 0, 'errors' => []];

        try {
            $types = $this->query_event_types();
            if (empty($types)) {
                $results['errors'][] = 'No event types returned from Calendly';
            } else {
                $results['upserted'] = $this->set_event_types($types);
            }
            update_option(CB_Constants::OPT_LAST_SYNC_EVENT_TYPES, current_time('timestamp'));
        } catch (\Throwable $e) {
            $results['errors'][] = $e->getMessage();
        }

        if (!empty($results['errors'])) {
            CB_Audit_Log::log('warning', 'api', __METHOD__, ['errors' => $results['errors']], 'warning');
        }
        CB_Audit_Log::log('method_exit', 'api', __METHOD__, ['success' => empty($results['errors']), 'upserted' => $results['upserted']], 'info');
        return [
            'success'   => empty($results['errors']),
            'last_sync' => current_time('mysql'),
            'upserted'  => $results['upserted'],
            'errors'    => $results['errors'],
        ];
    }


    public function query_event_type_available_times(string $event_type_uuid): array {
        CB_Audit_Log::log('method_entry', 'api', __METHOD__, ['event_type_uuid' => $event_type_uuid], 'info');
        try {
            // Current UTC time
            $nowObj = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

            // Round to next half-hour slot
            $minutes = (int) $nowObj->format('i');
            $seconds = (int) $nowObj->format('s');

            // Total minutes past the hour
            $totalMinutes = $minutes + ($seconds > 0 ? 1 : 0);

            // Compute next slot: either :30 or next hour
            $roundedMinutes = $totalMinutes <= 30 ? 30 : 0;
            $roundedHour    = $totalMinutes <= 30 ? (int) $nowObj->format('H') : (int) $nowObj->format('H') + 1;

            $rounded = $nowObj->setTime($roundedHour % 24, $roundedMinutes, 0);

            // ISO‑8601 UTC window
            $start_utc_iso = $rounded->format('Y-m-d\TH:i:s\Z');
            $end_utc_iso   = $rounded->modify('+7 days')->format('Y-m-d\TH:i:s\Z');

            // Build event_type URI
            $event_type = self::API_BASE . '/event_types/' . $event_type_uuid;

            // Call Calendly API
            $res = $this->get('/event_type_available_times', [
                'event_type' => $event_type,
                'start_time' => $start_utc_iso,
                'end_time'   => $end_utc_iso,
            ], true, 60);

            CB_Audit_Log::log('info', 'event_type_available_times', $event_type_uuid, [
                'start' => $start_utc_iso,
                'end'   => $end_utc_iso,
                'response' => $res,
            ]);

            $result = $res['collection'] ?? [];
            CB_Audit_Log::log('method_exit', 'api', __METHOD__, ['count' => count($result)], 'info');
            return $result;
        } catch (\Throwable $e) {
            CB_Audit_Log::log('error', 'api', __METHOD__, ['error' => $e->getMessage(), 'event_type_uuid' => $event_type_uuid], 'error');
            return [];
        }
    }

    public function set_event_type_available_times(string $event_type_uuid, array $slots): int {
        CB_Audit_Log::log('method_entry', 'api', __METHOD__, ['event_type_uuid' => $event_type_uuid, 'slots_count' => count($slots)], 'info');
        try {
            global $wpdb;
            $table = $wpdb->prefix . 'cb_event_type_available_times';

            // Resolve event_type_id
            $event_type_id = $wpdb->get_var(
                $wpdb->prepare("SELECT id FROM {$wpdb->prefix}cb_event_types WHERE uuid=%s", $event_type_uuid)
            );
            if (!$event_type_id) {
                CB_Audit_Log::log('warning', 'api', __METHOD__, ['message' => 'Event type not found', 'event_type_uuid' => $event_type_uuid], 'warning');
                return 0;
            }

            $count = 0;
            foreach ($slots as $slot) {
                // Normalize values
                $status            = sanitize_text_field($slot['status'] ?? 'available');
                $invitees_remaining = absint($slot['invitees_remaining'] ?? 0);
                $start_time        = gmdate('Y-m-d H:i:s', strtotime($slot['start_time'] ?? 'now'));
                $scheduling_url    = esc_url_raw($slot['scheduling_url'] ?? '');

                // Upsert into DB
                $wpdb->query($wpdb->prepare(
                    "INSERT INTO $table (event_type_id, status, invitees_remaining, start_time, scheduling_url, created_ts, updated_ts)
                    VALUES (%d, %s, %d, %s, %s, NOW(), NOW())
                    ON DUPLICATE KEY UPDATE
                    status=VALUES(status),
                    invitees_remaining=VALUES(invitees_remaining),
                    scheduling_url=VALUES(scheduling_url),
                    updated_ts=NOW()",
                    $event_type_id,
                    $status,
                    $invitees_remaining,
                    $start_time,
                    $scheduling_url
                ));

                $count++;

                // Audit log per slot
                CB_Audit_Log::log('set_event_type_available_time', 'event_type_times', $event_type_uuid, [
                    'event_type_id'      => $event_type_id,
                    'status'             => $status,
                    'invitees_remaining' => $invitees_remaining,
                    'start_time'         => $start_time,
                    'scheduling_url'     => $scheduling_url,
                ], 'info');
            }

            CB_Audit_Log::log('method_exit', 'api', __METHOD__, ['inserted' => $count], 'info');
            return $count;
        } catch (\Throwable $e) {
            CB_Audit_Log::log('error', 'api', __METHOD__, ['error' => $e->getMessage(), 'event_type_uuid' => $event_type_uuid], 'error');
            return 0;
        }
    }

    public function get_event_type_available_times($event_identifier, $start_iso): array {
        CB_Audit_Log::log('method_entry', 'api', __METHOD__, ['event_identifier' => $event_identifier, 'start_iso' => $start_iso], 'info');
        try {
            global $wpdb;

            $table_event_types  = $wpdb->prefix . 'cb_event_types';
            $table_times        = $wpdb->prefix . 'cb_event_type_available_times';

            // Step 1: Resolve event_type_id from identifier (uuid or id)
            $event_type_id = null;
            if (is_numeric($event_identifier)) {
                $event_type_id = (int) $event_identifier;
            } else {
                $event_type_id = $wpdb->get_var(
                    $wpdb->prepare("SELECT id FROM {$table_event_types} WHERE uuid = %s", $event_identifier)
                );
            }

            if (!$event_type_id) {
                CB_Audit_Log::log('warning', 'api', __METHOD__, ['message' => 'Event type not found', 'event_identifier' => $event_identifier], 'warning');
                return [];
            }

        // Step 2: Define current hour window
        try { 
            $dt = new \DateTimeImmutable($start_iso); 
        } catch (\Exception $e) { 
            $dt = new \DateTimeImmutable('now'); 
        }

        $utc = $dt->setTimezone(new \DateTimeZone('UTC'));
        $m = (int) $utc->format('i'); $s = (int) $utc->format('s');

        if ($m === 0 && $s === 0) { 
            $rounded = $utc->setTime((int) $utc->format('H'), 30, 0); 
        } elseif ($m < 30) { 
            $rounded = $utc->setTime((int) $utc->format('H'), 30, 0); 
        } else { 
            $rounded = $utc->modify('+1 hour')->setTime((int) $utc->modify('+1 hour')->format('H'), 0, 0);
        }

        $start_utc_iso = $rounded->format('Y-m-d\TH:i:s\Z');
        $end_utc_iso   = $rounded->modify('+7 days')->format('Y-m-d\TH:i:s\Z');

            // Step 3: Query available times within this hour
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT DISTINCT invitees_remaining, scheduling_url, start_time, status
                    FROM {$table_times}
                    WHERE event_type_id = %d
                    AND status = 'available'
                    AND start_time BETWEEN %s AND %s
                    ORDER BY start_time ASC",
                    $event_type_id,
                    $start_utc_iso,
                    $end_utc_iso
                ),
                ARRAY_A
            );

        foreach ($rows as &$row) {
            $row['start_time'] = implode('T', explode(' ', $row['start_time'])) . 'Z';
        }

            // Step 4: Format output
            $available_times['collection'] = $rows;

            CB_Audit_Log::log('method_exit', 'api', __METHOD__, ['count' => count($rows)], 'info');
            return $available_times;
        } catch (\Throwable $e) {
            CB_Audit_Log::log('error', 'api', __METHOD__, ['error' => $e->getMessage(), 'event_identifier' => $event_identifier], 'error');
            return [];
        }
    }

    public function sync_event_type_available_times(): array {
        CB_Audit_Log::log('method_entry', 'api', __METHOD__, [], 'info');
        $results = ['upserted' => 0, 'errors' => []];

        try {
            global $wpdb;
            $event_types = $wpdb->get_col("SELECT uuid FROM {$wpdb->prefix}cb_event_types WHERE product_id>0 AND active=1");

            foreach ($event_types as $uuid) {
                $slots = $this->query_event_type_available_times($uuid);

                if (empty($slots)) {
                    $results['errors'][] = "No available times for event_type {$uuid}";
                    CB_Audit_Log::log('warning', 'sync_event_type_available_times', $uuid, [
                        'message' => 'No slots returned'
                    ]);
                    continue;
                }

                $count = $this->set_event_type_available_times($uuid, $slots);
                $results['upserted'] += $count;

                CB_Audit_Log::log('info', 'sync_event_type_available_times', $uuid, [
                    'upserted' => $count,
                    'total_upserted' => $results['upserted']
                ]);
            }

            update_option(CB_Constants::OPT_LAST_SYNC_EVENT_TYPE_AVAILABLE_TIMES, current_time('timestamp'));
        } catch (\Throwable $e) {
            $results['errors'][] = $e->getMessage();
            CB_Audit_Log::log('error', 'sync_event_type_available_times', 'exception', [
                'error' => $e->getMessage()
            ]);
        }

        if (!empty($results['errors'])) {
            CB_Audit_Log::log('warning', 'api', __METHOD__, ['errors' => $results['errors']], 'warning');
        }
        CB_Audit_Log::log('method_exit', 'api', __METHOD__, ['success' => empty($results['errors']), 'upserted' => $results['upserted']], 'info');
        return [
            'success'   => empty($results['errors']),
            'last_sync' => current_time('mysql'),
            'upserted'  => $results['upserted'],
            'errors'    => $results['errors'],
        ];
    }


    public function query_scheduled_events(?int $count = null, ?string $min_start_date = null): array {
        CB_Audit_Log::log('method_entry', 'api', __METHOD__, ['count' => $count, 'min_start_date' => $min_start_date], 'info');
        try {
            $params = [];
            if ($count !== null) {
                $params['count'] = $count;
            }
            if ($min_start_date) {
                $params['min_start_time'] = gmdate('Y-m-d\TH:i:s\Z', strtotime($min_start_date));
            }

            $allEvents = [];
            $cursor = null;

            do {
                if ($cursor) {
                    $params['cursor'] = $cursor;
                }

                $res = $this->get('/scheduled_events', $params, false, 120);
                $batch = $res['collection'] ?? [];
                $allEvents = array_merge($allEvents, $batch);

                $cursor = $res['pagination']['next_page'] ?? null;
            } while ($count === null && $cursor);

            CB_Audit_Log::log('method_exit', 'api', __METHOD__, ['count' => count($allEvents)], 'info');
            return $allEvents;
        } catch (\Throwable $e) {
            CB_Audit_Log::log('error', 'api', __METHOD__, ['error' => $e->getMessage()], 'error');
            return [];
        }
    }

    public function set_scheduled_events(array $events): int {
        CB_Audit_Log::log('method_entry', 'api', __METHOD__, ['events_count' => count($events)], 'info');
        try {
            global $wpdb;
            $table_events   = $wpdb->prefix . 'cb_scheduled_events';
            $table_types    = $wpdb->prefix . 'cb_event_types';
            $table_invitees = $wpdb->prefix . 'cb_scheduled_event_invitees';
            $table_locations= $wpdb->prefix . 'cb_meeting_locations';

            $count = 0;

            foreach ($events as $se) {
                $uuid = basename($se['uri'] ?? '');
                if (!$uuid) {
                    continue;
                }

                // Resolve event_type_id
                $event_type_uuid = basename($se['event_type'] ?? '');
                $event_type_id = $wpdb->get_var(
                    $wpdb->prepare("SELECT id FROM {$table_types} WHERE uuid=%s", $event_type_uuid)
                );

                // Resolve location_id
                $location_id = null;
                $location_ids = $wpdb->get_results("SELECT id, type FROM {$table_locations}", ARRAY_A);

                foreach ($location_ids as $key => $location) {
                    $loc = $se['location'] ?? [];
                    if ($loc && $loc['type'] === $location['type']) {
                        $location_id = $location['id'];
                    }
                }

                // Normalize status
                $status = $se['status'] ?? 'active';
                $raw_payload_status = null;

                if (!empty($se['payload'])) {
                    $payload = is_array($se['payload']) ? $se['payload'] : json_decode($se['payload'], true);

                    // Handle explicit status from payload
                    if (!empty($payload['status'])) {
                        $raw_payload_status = $payload['status'];
                        $status = strtolower($payload['status']) === 'canceled'
                            ? 'cancelled'
                            : sanitize_text_field($payload['status']);
                    }

                    // NEW CONDITION: mark as completed if end_date is in the past and not cancelled
                    if (!empty($payload['end_date']) && strtolower($status) !== 'cancelled') {
                        $end_timestamp = strtotime($payload['end_date']);

                        if ($end_timestamp !== false && $end_timestamp < time()) {
                            $status = 'completed';
                        }
                    }
                }


                // Defaults
                $reschedule_url = null;
                $cancel_url     = null;
                $order_id       = null;

                // Upsert invitees and extract order_id + URLs
                if (!empty($se['invitees']) && is_array($se['invitees'])) {
                    foreach ($se['invitees'] as $inv) {
                        $inv_payload = is_array($inv['payload'] ?? null)
                            ? $inv['payload']
                            : json_decode($inv['payload'] ?? '{}', true);

                        // Extract order_id and URLs from invitee payload
                        if (!empty($inv_payload['order_id'])) {
                            $order_id = $inv_payload['order_id'];
                        }
                        $reschedule_url = $inv_payload['reschedule_url'] ?? $reschedule_url;
                        $cancel_url     = $inv_payload['cancel_url'] ?? $cancel_url;

                        // Upsert invitee record
                        $wpdb->query($wpdb->prepare(
                            "INSERT INTO $table_invitees (scheduled_event_uuid, name, email, payload, created_ts, updated_ts)
                            VALUES (%s, %s, %s, %s, NOW(), NOW())
                            ON DUPLICATE KEY UPDATE
                            name=VALUES(name),
                            email=VALUES(email),
                            payload=VALUES(payload),
                            updated_ts=NOW()",
                            $uuid,
                            sanitize_text_field($inv['name'] ?? ''),
                            sanitize_email($inv['email'] ?? ''),
                            wp_json_encode($inv_payload)
                        ));
                    }
                }

                // Upsert scheduled event with order_id from invitee payload
                $wpdb->query($wpdb->prepare(
                    "INSERT INTO $table_events (uuid, order_id, event_type_id, location_id, name, start_time, end_time, status, uri, reschedule_url, cancel_url, payload, created_ts, updated_ts)
                    VALUES (%s, %s, %d, %d, %s, %s, %s, %s, %s, %s, %s, %s, NOW(), NOW())
                    ON DUPLICATE KEY UPDATE
                    order_id=VALUES(order_id),
                    event_type_id=VALUES(event_type_id),
                    location_id=VALUES(location_id),
                    name=VALUES(name),
                    start_time=VALUES(start_time),
                    end_time=VALUES(end_time),
                    status=VALUES(status),
                    uri=VALUES(uri),
                    reschedule_url=VALUES(reschedule_url),
                    cancel_url=VALUES(cancel_url),
                    payload=VALUES(payload),
                    updated_ts=NOW()",
                    $uuid,
                    $order_id, // now sourced from invitee payload
                    $event_type_id ?: 0,
                    $location_id,
                    sanitize_text_field(!$se['name'] == "Initial meeting"? $se['name'] : "Initial Consultation"),
                    gmdate('Y-m-d H:i:s', strtotime($se['start_time'] ?? 'now')),
                    gmdate('Y-m-d H:i:s', strtotime($se['end_time'] ?? 'now')),
                    $status,
                    esc_url_raw($se['uri'] ?? ''),
                    esc_url_raw($reschedule_url ?? ''),
                    esc_url_raw($cancel_url ?? ''),
                    wp_json_encode($se)
                ));

                $count++;

                // Audit log
                CB_Audit_Log::log('set_scheduled_event', 'scheduled_events', $uuid, [
                    'order_id'       => $order_id,
                    'event_type_id'  => $event_type_id,
                    'location_id'    => $location_id,
                    'status'         => $status,
                    'payload_status' => $raw_payload_status,
                    'reschedule_url' => $reschedule_url,
                    'cancel_url'     => $cancel_url,
                    'start_time'     => $se['start_time'] ?? null,
                    'end_time'       => $se['end_time'] ?? null,
                ], 'info');
            }

            CB_Audit_Log::log('method_exit', 'api', __METHOD__, ['inserted' => $count], 'info');
            return $count;
        } catch (\Throwable $e) {
            CB_Audit_Log::log('error', 'api', __METHOD__, ['error' => $e->getMessage()], 'error');
            return 0;
        }
    }

    public function get_scheduled_events(array $filters = [], string $context = 'admin', int $limit = 10): array {
        CB_Audit_Log::log('method_entry', 'api', __METHOD__, ['filters' => $filters, 'context' => $context, 'limit' => $limit], 'info');
        try {
            global $wpdb;

            $table_event_types = $wpdb->prefix . 'cb_event_types';
            $table_scheduled_events   = $wpdb->prefix . 'cb_scheduled_events';
            $table_invitees = $wpdb->prefix . 'cb_scheduled_event_invitees';
            $table_locations= $wpdb->prefix . 'cb_meeting_locations';

            $where  = ["1=1"];
            $params = [];

            switch ($context) {
                case 'today':
                    // Only events starting today onwards
                    $where[] = "se.start_time >= %s";
                    $params[] = gmdate('Y-m-d 00:00:00');
                    break;

                case 'admin':
                    // Admin view: upcoming events, limited by $limit
                    $where[] = "se.start_time >= UTC_TIMESTAMP()";
                    break;

                case 'my-account':
                    // Logged-in user’s events (based on contact email)
                    $user = wp_get_current_user();
                    if ($user && $user->user_email) {
                        $where[] = "se.start_time >= UTC_TIMESTAMP()";
                        $where[] = "inv.email = %s";
                        $params[] = $user->user_email;
                    } else {
                        return []; // no user context
                    }
                    break;

                case 'default':
                default:
                    // Apply custom filters
                    if (!empty($filters['status'])) {
                        $where[] = "se.status = %s";
                        $params[] = $filters['status'];
                    }
                    if (!empty($filters['start_date'])) {
                        $where[] = "se.start_time >= %s";
                        $params[] = gmdate('Y-m-d H:i:s', strtotime($filters['start_date']));
                    }
                    if (!empty($filters['end_date'])) {
                        $where[] = "se.start_time <= %s";
                        $params[] = gmdate('Y-m-d H:i:s', strtotime($filters['end_date']));
                    }
                    break;
            }

            $sql = "
                SELECT se.id, se.uuid, se.order_id, se.name AS event_name, se.start_time, se.end_time, se.status,
                    se.reschedule_url, se.cancel_url, se.payload as event_payload, se.notes,
                    et.name AS event_type,
                    ml.name AS location_name,
                    inv.name AS invitee_name, inv.payload AS invitee_payload
                FROM {$table_scheduled_events} se
                LEFT JOIN {$table_event_types} et ON se.event_type_id = et.id
                LEFT JOIN {$table_locations} ml ON se.location_id = ml.id
                LEFT JOIN {$table_invitees} inv ON inv.scheduled_event_uuid = se.uuid
                WHERE " . implode(' AND ', $where) . "
                ORDER BY se.start_time DESC
                " . (($context === 'today' || $context === 'admin') ? "LIMIT %d" : "");

            if ($context === 'today' || $context === 'admin') {
                $params[] = $limit;
            }

            $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A);

            $events = [];
            foreach ($rows as $row) {
                $order_id = '-';
                $invitee_payload = [];

                if( !empty($row['invitee_payload']) ) {
                    $invitee_payload = json_decode($row['invitee_payload'], true);
                }

                if(!empty($invitee_payload)) {
                    $qna = $invitee_payload['questions_and_answers'];

                        foreach($qna as $idx => $arr) {
                            if($arr['question'] == 'Order ID') {
                                $order_id = $arr['answer'];
                            }
                        }
                }

                $events[] = [
                    'uuid'            => $row['uuid'],
                    'order_id'        => $order_id,
                    'event_name'      => $row['event_name'],
                    'start_time'      => implode('T', explode(' ', $row['start_time'])) . 'Z',
                    'end_time'        => implode('T', explode(' ', $row['end_time'])) . 'Z',
                    'location'        => $row['location_name'] ?? '—',
                    'status'          => $row['status'],
                    'invitee_name'    => $row['invitee_name'] ?? '—',
                    'invitee_payload' => $invitee_payload,
                    'reschedule_url'  => $invitee_payload['reschedule_url'] ?? null,
                    'cancel_url'      => $invitee_payload['cancel_url'] ?? null,
                    'completed'       => ($row['status'] === 'completed'),
                ];
            }

            // Audit log
            CB_Audit_Log::log('get_scheduled_events', 'scheduled_events', $context, [
                'filters' => $filters,
                'limit'   => $limit,
                'count'   => count($events),
            ], 'info');

            CB_Audit_Log::log('method_exit', 'api', __METHOD__, ['count' => count($events)], 'info');
            return $events;
        } catch (\Throwable $e) {
            CB_Audit_Log::log('error', 'api', __METHOD__, ['error' => $e->getMessage()], 'error');
            return [];
        }
    }

    public function sync_scheduled_events(?string $min_start_date = null, ?bool $force = false): array {
        CB_Audit_Log::log('method_entry', 'api', __METHOD__, ['min_start_date' => $min_start_date], 'info');
        $results = ['upserted' => 0, 'errors' => []];

        try {
            $events = self::query_scheduled_events(null, $min_start_date);

            if (empty($events)) {
                $results['errors'][] = 'No scheduled events returned from Calendly';
            } else {
                $this->normalize_event_statuses($events);
                $results['upserted'] = $this->set_scheduled_events($events);
            }

            $this->update_sync_state_success();
            update_option(CB_Constants::OPT_LAST_SYNC_SCHEDULED_EVENTS, current_time('timestamp'));
        } catch (\Throwable $e) {
            $results['errors'][] = $e->getMessage();
            $this->update_sync_state_error($e->getMessage());
        }

        if (!empty($results['errors'])) {
            CB_Audit_Log::log('warning', 'api', __METHOD__, ['errors' => $results['errors']], 'warning');
        }
        CB_Audit_Log::log('method_exit', 'api', __METHOD__, ['success' => empty($results['errors']), 'upserted' => $results['upserted']], 'info');
        return [
            'success'   => empty($results['errors']),
            'last_sync' => current_time('mysql'),
            'upserted'  => $results['upserted'],
            'errors'    => $results['errors'],
        ];
    }


    public function query_scheduled_event_invitees(string $scheduled_event_uuid): array {
        CB_Audit_Log::log('method_entry', 'api', __METHOD__, ['scheduled_event_uuid' => $scheduled_event_uuid], 'info');
        try {
            $res = $this->get('/scheduled_events/' . $scheduled_event_uuid . '/invitees', [], false, 60);
            $result = $res['collection'] ?? [];
            CB_Audit_Log::log('method_exit', 'api', __METHOD__, ['count' => count($result)], 'info');
            return $result;
        } catch (\Throwable $e) {
            CB_Audit_Log::log('error', 'api', __METHOD__, ['error' => $e->getMessage(), 'scheduled_event_uuid' => $scheduled_event_uuid], 'error');
            return [];
        }
    }

    public function set_scheduled_event_invitees(string $scheduled_event_uuid, array $invitees): int {
        CB_Audit_Log::log('method_entry', 'api', __METHOD__, ['scheduled_event_uuid' => $scheduled_event_uuid, 'invitees_count' => count($invitees)], 'info');
        try {
            global $wpdb;
            $table = $wpdb->prefix . 'cb_scheduled_event_invitees';
            $scheduled_event_uuid = $wpdb->get_var(
                $wpdb->prepare("SELECT uuid FROM {$wpdb->prefix}cb_scheduled_events WHERE uuid=%s", $scheduled_event_uuid)
            );
            if (!$scheduled_event_uuid) {
                CB_Audit_Log::log('warning', 'api', __METHOD__, ['message' => 'Scheduled event not found', 'scheduled_event_uuid' => $scheduled_event_uuid], 'warning');
                return 0;
            }

            $count = 0;
            foreach ($invitees as $inv) {
                $uuid = basename($inv['uri'] ?? '');
                if (!$uuid) continue;

                $wpdb->query($wpdb->prepare(
                    "INSERT INTO $table (scheduled_event_uuid, uuid, name, email, status, answers, payload, created_ts, updated_ts)
                    VALUES (%s, %s, %s, %s, %s, %s, %s, NOW(), NOW())
                    ON DUPLICATE KEY UPDATE
                    name=VALUES(name),
                    email=VALUES(email),
                    status=VALUES(status),
                    answers=VALUES(answers),
                    payload=VALUES(payload),
                    updated_ts=NOW()",
                    $scheduled_event_uuid,
                    $uuid,
                    sanitize_text_field($inv['name'] ?? ''),
                    sanitize_email($inv['email'] ?? ''),
                    sanitize_text_field($inv['status'] ?? 'active'),
                    wp_json_encode($inv['questions'] ?? []),
                    wp_json_encode($inv)
                ));
                $count++;
            }
            CB_Audit_Log::log('method_exit', 'api', __METHOD__, ['inserted' => $count], 'info');
            return $count;
        } catch (\Throwable $e) {
            CB_Audit_Log::log('error', 'api', __METHOD__, ['error' => $e->getMessage(), 'scheduled_event_uuid' => $scheduled_event_uuid], 'error');
            return 0;
        }
    }

    public function sync_scheduled_event_invitees(): array {
        CB_Audit_Log::log('method_entry', 'api', __METHOD__, [], 'info');
        $results = ['upserted' => 0, 'errors' => []];

        try {
            global $wpdb;
            $scheduled_events = $wpdb->get_col("SELECT uuid FROM {$wpdb->prefix}cb_scheduled_events");

            foreach ($scheduled_events as $uuid) {
                $invitees = $this->query_scheduled_event_invitees($uuid);

                if (empty($invitees)) {
                    $results['errors'][] = "No invitees for scheduled_event {$uuid}";
                    CB_Audit_Log::log('warning', 'sync_scheduled_event_invitees', $uuid, [
                        'message' => 'No invitees returned'
                    ]);
                    continue;
                }

                $count = $this->set_scheduled_event_invitees($uuid, $invitees);
                $results['upserted'] += $count;

                CB_Audit_Log::log('info', 'sync_scheduled_event_invitees', $uuid, [
                    'upserted' => $count,
                    'total_upserted' => $results['upserted']
                ]);
            }

            update_option(CB_Constants::OPT_LAST_SYNC_SCHEDULED_EVENT_INVITEES, current_time('timestamp'));
        } catch (\Throwable $e) {
            $results['errors'][] = $e->getMessage();
            CB_Audit_Log::log('error', 'sync_scheduled_event_invitees', 'exception', [
                'error' => $e->getMessage()
            ]);
        }

        if (!empty($results['errors'])) {
            CB_Audit_Log::log('warning', 'api', __METHOD__, ['errors' => $results['errors']], 'warning');
        }
        CB_Audit_Log::log('method_exit', 'api', __METHOD__, ['success' => empty($results['errors']), 'upserted' => $results['upserted']], 'info');
        return [
            'success'   => empty($results['errors']),
            'last_sync' => current_time('mysql'),
            'upserted'  => $results['upserted'],
            'errors'    => $results['errors'],
        ];
    }

    public function query_locations(): array {
        CB_Audit_Log::log('method_entry', 'api', __METHOD__, [], 'info');
        try {
            $res = $this->get('/locations', ['count' => 100], false, 120);
            $result = $res['collection'] ?? [];
            CB_Audit_Log::log('method_exit', 'api', __METHOD__, ['count' => count($result)], 'info');
            return $result;
        } catch (\Throwable $e) {
            CB_Audit_Log::log('error', 'api', __METHOD__, ['error' => $e->getMessage()], 'error');
            return [];
        }
    }

    public function set_locations(array $locations): int {
        CB_Audit_Log::log('method_entry', 'api', __METHOD__, ['locations_count' => count($locations)], 'info');
        try {
            global $wpdb;
            $table = $wpdb->prefix . 'cb_meeting_locations';
            $count = 0;

            $location_map = [
                'physical'                  => ['name' => 'HIER Life', 'type' => 'physical'],
                'ask_invitee'               => ['name' => 'Ask Invitee', 'type' => 'ask_invitee'],
                'custom'                    => ['name' => 'Custom Location', 'type' => 'custom'],
                'outbound_call'             => ['name' => 'Phone (Outbound Call)', 'type' => 'outbound_call'],
                'inbound_call'              => ['name' => 'Phone (Inbound Call)', 'type' => 'inbound_call'],
                'zoom_conference'           => ['name' => 'Zoom Conference', 'type' => 'zoom'],
                'gotomeeting_conference'    => ['name' => 'GoToMeeting', 'type' => 'gotomeeting'],
                'google_conference'         => ['name' => 'Google Meet', 'type' => 'google_meet'],
                'microsoft_teams_conference'=> ['name' => 'Microsoft Teams', 'type' => 'teams'],
                'webex_conference'          => ['name' => 'Webex', 'type' => 'webex'],
            ];

            foreach ($locations as $loc) {
                $kind = $loc['kind'] ?? '';
                if (!$kind || !isset($location_map[$kind])) continue;

                $mapped = $location_map[$kind];

                // Check if record already exists by kind
                $existing = $wpdb->get_var(
                    $wpdb->prepare("SELECT uuid FROM $table WHERE type = %s", $mapped['type'])
                );

                $uuid = $existing ?: wp_generate_uuid4();

                $wpdb->query($wpdb->prepare(
                    "INSERT INTO $table (uuid, name, type, created_ts, updated_ts)
                    VALUES (%s, %s, %s, NOW(), NOW())
                    ON DUPLICATE KEY UPDATE
                    name=VALUES(name),
                    updated_ts=NOW()",
                    $uuid,
                    sanitize_text_field($mapped['name']),
                    sanitize_text_field($mapped['type'])
                ));
                $count++;
            }

            CB_Audit_Log::log('method_exit', 'api', __METHOD__, ['inserted_or_updated' => $count], 'info');
            return $count;
        } catch (\Throwable $e) {
            CB_Audit_Log::log('error', 'api', __METHOD__, ['error' => $e->getMessage()], 'error');
            return 0;
        }
    }

    public function get_locations(): array {
        CB_Audit_Log::log('method_entry', 'api', __METHOD__, [], 'info');
        try {
            global $wpdb;
            $table = $wpdb->prefix . 'cb_meeting_locations';
            $rows = $wpdb->get_results("SELECT uuid, name, type FROM {$table}", ARRAY_A);
            $locations = [];
            foreach ($rows as $row) {
                $locations[] = [
                    'uuid' => $row['uuid'],
                    'name' => $row['name'],
                    'type' => $row['type'],
                ];
            }
            CB_Audit_Log::log('method_exit', 'api', __METHOD__, ['count' => count($locations)], 'info');
            return $locations;
        } catch (\Throwable $e) {
            CB_Audit_Log::log('error', 'api', __METHOD__, ['error' => $e->getMessage()], 'error');
            return [];
        }
    }

    public function sync_locations(): array {
        CB_Audit_Log::log('method_entry', 'api', __METHOD__, [], 'info');
        $results = ['upserted' => 0, 'errors' => []];
        try {
            $locations = $this->query_locations();

            if (empty($locations)) {
                $results['errors'][] = 'No locations returned from Calendly';
            } else {
                $results['upserted'] = $this->set_locations($locations);
            }

            update_option(CB_Constants::OPT_LAST_SYNC_LOCATIONS, current_time('timestamp'));
        } catch (\Throwable $e) {
            $results['errors'][] = $e->getMessage();
        }

        if (!empty($results['errors'])) {
            CB_Audit_Log::log('warning', 'api', __METHOD__, ['errors' => $results['errors']], 'warning');
        }
        CB_Audit_Log::log('method_exit', 'api', __METHOD__, ['success' => empty($results['errors']), 'upserted' => $results['upserted']], 'info');
        return [
            'success'   => empty($results['errors']),
            'last_sync' => current_time('mysql'),
            'upserted'  => $results['upserted'],
            'errors'    => $results['errors'],
        ];
    }




    private function normalize_event_statuses(array &$events): void {
        foreach ($events as &$event) {
            $this->normalize_single_event_status($event);
        }
    }

    private function normalize_single_event_status(array &$event): void {
        if (!empty($event['payload'])) {
            $payload = is_array($event['payload']) ? $event['payload'] : json_decode($event['payload'], true);
            if (!empty($payload['status']) && (strtolower($payload['status']) === 'canceled' || str_contains($payload['status'], 'cancel'))) {
                $event['status'] = 'canceled';
                return;
            }
        }

        if (!empty($event['end_time'])) {
            $current_iso = date('c');
            if ($current_iso > $event['end_time'] && $event['status'] !== 'canceled') {
                $event['status'] = 'completed';
            }
        }
    }

    private function update_sync_state_success(): void {
        global $wpdb;
        $wpdb->replace("{$wpdb->prefix}cb_sync_state", [
            'domain'       => 'scheduled_events',
            'cursor'       => null,
            'last_success' => current_time('mysql'),
            'last_error'   => null,
            'error_msg'    => null,
        ]);
    }

    private function update_sync_state_error(string $error_msg): void {
        global $wpdb;
        $wpdb->replace("{$wpdb->prefix}cb_sync_state", [
            'domain'       => 'scheduled_events',
            'cursor'       => null,
            'last_success' => null,
            'last_error'   => current_time('mysql'),
            'error_msg'    => $error_msg,
        ]);
    }

    /** Update cb_scheduled_events.created_ts from payload.created_at */
    public static function update_all_events_created_ts_from_payload(): void {
        CB_Audit_Log::log('method_entry', 'api', __METHOD__, [], 'info');
        try {
            global $wpdb; $t = $wpdb->prefix.'cb_scheduled_events';
            $rows = $wpdb->get_results("SELECT uuid, payload FROM {$t}", ARRAY_A);
            $updated = 0;
            foreach ($rows as $row) {
                $payload = self::json_decode_safe($row['payload']);
                $created = self::to_mysql_dt($payload['created_at'] ?? null);
                if ($created) {
                    $wpdb->update($t, ['created_at' => $created], ['uuid' => $row['uuid']], ['%s'], ['%s']);
                    $updated++;
                }
            }
            CB_Audit_Log::log('method_exit', 'api', __METHOD__, ['updated' => $updated], 'info');
        } catch (\Throwable $e) {
            CB_Audit_Log::log('error', 'api', __METHOD__, ['error' => $e->getMessage()], 'error');
        }
    }

    /** Refresh reschedule_url and cancel_url on cb_scheduled_events from invitee payloads */
    public static function refresh_event_urls_from_invitees_payload(): void {
        CB_Audit_Log::log('method_entry', 'api', __METHOD__, [], 'info');
        try {
            global $wpdb;
            $ti = $wpdb->prefix.'cb_scheduled_event_invitees';
            $te = $wpdb->prefix.'cb_scheduled_events';

            $events = $wpdb->get_col("SELECT DISTINCT scheduled_event_uuid FROM {$ti}");
            $updated = 0;
            foreach ($events as $uuid) {
                $inv = $wpdb->get_results($wpdb->prepare("SELECT payload FROM {$ti} WHERE scheduled_event_uuid=%s", $uuid), ARRAY_A);
                $reschedule = null; $cancel = null;
                foreach ($inv as $row) {
                    $payload = self::json_decode_safe($row['payload']);
                    $reschedule = $reschedule ?: ($payload['reschedule_url'] ?? null);
                    $cancel     = $cancel     ?: ($payload['cancel_url'] ?? null);
                }
                $wpdb->update($te, ['reschedule_url' => $reschedule, 'cancel_url' => $cancel], ['uuid' => $uuid], ['%s','%s'], ['%s']);
                $updated++;
            }
            CB_Audit_Log::log('method_exit', 'api', __METHOD__, ['updated' => $updated], 'info');
        } catch (\Throwable $e) {
            CB_Audit_Log::log('error', 'api', __METHOD__, ['error' => $e->getMessage()], 'error');
        }
    }

    /** Backfill cb_scheduled_events.order_id from invitee answers where question === "Order ID" */
    public static function backfill_event_order_ids_from_invitees(): void {
        CB_Audit_Log::log('method_entry', 'api', __METHOD__, [], 'info');
        try {
            global $wpdb;
            $ti = $wpdb->prefix.'cb_scheduled_event_invitees';
            $te = $wpdb->prefix.'cb_scheduled_events';

            $events = $wpdb->get_col("SELECT DISTINCT scheduled_event_uuid FROM {$ti}");
            $updated = 0;
            foreach ($events as $uuid) {
                $inv = $wpdb->get_results($wpdb->prepare("SELECT payload FROM {$ti} WHERE scheduled_event_uuid=%s", $uuid), ARRAY_A);
                $order_id = null;
                foreach ($inv as $row) {
                    $payload = self::json_decode_safe($row['payload']);
                    $answers = is_array($payload['answers'] ?? null) ? $payload['answers'] : [];
                    foreach ($answers as $a) {
                        if (($a['question'] ?? '') === 'Order ID') {
                            $order_id = (string) ($a['answer'] ?? '');
                            if ($order_id) break;
                        }
                    }
                    if ($order_id) break;
                }
                if ($order_id) {
                    $wpdb->update($te, ['order_id' => $order_id], ['uuid' => $uuid], ['%s'], ['%s']);
                    $updated++;
                }
            }
            CB_Audit_Log::log('method_exit', 'api', __METHOD__, ['updated' => $updated], 'info');
        } catch (\Throwable $e) {
            CB_Audit_Log::log('error', 'api', __METHOD__, ['error' => $e->getMessage()], 'error');
        }
    }

    public static function normalize_all_event_statuses() {
        CB_Audit_Log::log('method_entry', 'api', __METHOD__, [], 'info');
        try {
            global $wpdb;

            $table = $wpdb->prefix . 'cb_scheduled_events';

            // Fetch all events
            $events = $wpdb->get_results("SELECT id, status, payload FROM {$table}", ARRAY_A);

            if (empty($events)) {
                CB_Audit_Log::log('method_exit', 'api', __METHOD__, ['message' => 'no events to process'], 'info');
                return; // nothing to do
            }

            $updated = 0;
            foreach ($events as $se) {

                // --- Your exact logic begins here ---
                $status = $se['status'] ?? 'active';
                $raw_payload_status = null;

                if (!empty($se['payload'])) {
                    $payload = is_array($se['payload'])
                        ? $se['payload']
                        : json_decode($se['payload'], true);

                    // Handle explicit status from payload
                    if (!empty($payload['status'])) {
                        $raw_payload_status = $payload['status'];
                        $status = strtolower($payload['status']) === 'canceled'
                            ? 'cancelled'
                            : sanitize_text_field($payload['status']);
                    }

                    // Mark as completed if end_date is in the past and not cancelled
                    if (!empty($payload['end_date']) && strtolower($status) !== 'cancelled') {
                        $end_timestamp = strtotime($payload['end_date']);

                        if ($end_timestamp !== false && $end_timestamp < time()) {
                            $status = 'completed';
                        }
                    }
                }
                // --- Your exact logic ends here ---

                // Update only if changed
                if ($status !== $se['status']) {
                    $wpdb->update(
                        $table,
                        ['status' => $status],
                        ['id' => $se['id']],
                        ['%s'],
                        ['%d']
                    );
                    $updated++;
                }
            }
            CB_Audit_Log::log('method_exit', 'api', __METHOD__, ['updated' => $updated], 'info');
        } catch (\Throwable $e) {
            CB_Audit_Log::log('error', 'api', __METHOD__, ['error' => $e->getMessage()], 'error');
        }
    }

    /** Helpers (align to your existing utilities if present) */
    private static function json_decode_safe(?string $json): array {
        if (!$json) return [];
        $data = json_decode($json, true);
        return is_array($data) ? $data : [];
    }

    private static function to_mysql_dt(?string $iso): ?string {
        if (!$iso) return null;
        $ts = strtotime($iso);
        return $ts ? gmdate('Y-m-d H:i:s', $ts) : null;
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
        $types = $this->get_event_types(false);
        if (!empty($types['error'])) {
            return ['success' => false, 'error' => $types['error']];
        }

        $collection = $types['collection'] ?? [];
        $byUuid = $this->build_uuid_map($collection);

        $args = [
            'post_type'      => 'product',
            'posts_per_page' => -1,
            'post_status'    => 'any',
            'meta_query'     => [
                ['key' => '_cb_event_uuid', 'compare' => 'EXISTS'],
                ['key' => '_cb_scheduling_url', 'compare' => 'EXISTS']
            ],
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ];
        $q = new \WP_Query($args);

        $linked = [];
        $unknown_in_calendly = [];
        $this->process_wp_posts($q->posts, $byUuid, $linked, $unknown_in_calendly);

        $missing_in_wp = $this->find_missing_in_wp($byUuid, $linked);

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

    private function build_uuid_map(array $collection): array {
        $byUuid = [];
        foreach ($collection as $t) {
            $uuid = $t['uuid'] ?? basename((string)($t['uri'] ?? ''));
            if ($uuid) {
                $byUuid[$uuid] = $t;
            }
        }
        return $byUuid;
    }

    private function process_wp_posts(array $posts, array $byUuid, array &$linked, array &$unknown_in_calendly): void {
        foreach ($posts as $pid) {
            $uuid = (string) get_post_meta($pid, '_cb_event_uuid', true);
            if (!$uuid) continue;

            $scheduling_url = (string) get_post_meta($pid, '_cb_scheduling_url', true);

            if (isset($byUuid[$uuid])) {
                $linked[] = [
                    'uuid'           => $uuid,
                    'product_id'     => $pid,
                    'product'        => get_the_title($pid),
                    'event_name'     => $byUuid[$uuid]['name'] ?? '',
                    'scheduling_url' => $scheduling_url ?: ($byUuid[$uuid]['scheduling_url'] ?? ''),
                ];
            } else {
                $unknown_in_calendly[] = [
                    'uuid'           => $uuid,
                    'product_id'     => $pid,
                    'product'        => get_the_title($pid),
                    'scheduling_url' => $scheduling_url,
                ];
            }
        }
    }

    private function find_missing_in_wp(array $byUuid, array $linked): array {
        $missing_in_wp = [];
        $linked_uuids = array_column($linked, 'uuid');
        foreach (array_keys($byUuid) as $uuid) {
            if (!in_array($uuid, $linked_uuids)) {
                $missing_in_wp[] = [
                    'uuid'           => $uuid,
                    'event_name'     => $byUuid[$uuid]['name'] ?? '',
                    'scheduling_url' => $byUuid[$uuid]['scheduling_url'] ?? '',
                ];
            }
        }
        return $missing_in_wp;
    }

    public function manual_connection_test(): array {
        if ($this->token === '') {
            return ['success' => false, 'message' => __('No API token found.', 'calendly-bookings')];
        }
        $res = $this->get('/users/me', [], true, 0);
        if (!empty($res['error'])) {
            return ['success' => false, 'message' => $res['error']];
        }
        return !empty($res['resource'])
            ? ['success' => true, 'message' => __('Calendly API connection successful.', 'calendly-bookings')]
            : ['success' => false, 'message' => __('Unexpected API response.', 'calendly-bookings')];
    }

    public function get_event_type_availability(string $event_type_uri, string $start_iso): array {
        try { $dt = new \DateTimeImmutable($start_iso); } catch (\Exception $e) { $dt = new \DateTimeImmutable('now'); }
        $utc = $dt->setTimezone(new \DateTimeZone('UTC'));
        $m = (int) $utc->format('i'); $s = (int) $utc->format('s');
        if ($m === 0 && $s === 0) { $rounded = $utc->setTime((int) $utc->format('H'), 30, 0); }
        elseif ($m < 30) { $rounded = $utc->setTime((int) $utc->format('H'), 30, 0); }
        else { $rounded = $utc->modify('+1 hour')->setTime((int) $utc->modify('+1 hour')->format('H'), 0, 0); }

        $start_utc_iso = $rounded->format('Y-m-d\TH:i:s\Z');
        $end_utc_iso   = $rounded->modify('+7 days')->format('Y-m-d\TH:i:s\Z');

        print_r( $this->get('/event_type_available_times', [
            'event_type' => $event_type_uri,
            'start_time' => $start_utc_iso,
            'end_time'   => $end_utc_iso,
        ], true, 60));
    }

}
