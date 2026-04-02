<?php

namespace Calendly_Bookings\Modules;

use wpdb;

if (!defined('ABSPATH')) exit;

final class CB_Scheduled_Events {

    /** @var self|null */
    private static $instance = null;

    /** @var wpdb */
    private $db;

    /** @var string */
    private $table_scheduled_events;
    private $table_event_types;
    private $table_invitees;
    private $table_locations;

    /**
     * Initialize the singleton instance.
     */
    public static function init(): void {
        self::instance();
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

    /**
     * Private constructor to enforce singleton pattern.
     */
    private function __construct() {
        global $wpdb;
        $this->db    = $wpdb;
        $this->table_scheduled_events = $wpdb->prefix . 'cb_scheduled_events';
        $this->table_event_types = $wpdb->prefix . 'cb_event_types';
        $this->table_invitees = $wpdb->prefix . 'cb_scheduled_event_invitees';
        $this->table_locations= $wpdb->prefix . 'cb_meeting_locations';

    }

    /**
     * Fetch scheduled events with filters, sorting, pagination.
     *
     * @param array $filters
     * @param array $args
     * @return array
     */
    public function get_events(array $filters = [], array $args = []): array {
        $where  = [];
        $params = [];
    
        // Name filter: match invitee OR event name
        if (!empty($filters['name'])) {
            $like = '%' . $this->db->esc_like($filters['name']) . '%';
            $where[] = "(inv.name LIKE %s OR se.name LIKE %s)";
            $params[] = $like;
            $params[] = $like;
        }
    
        // Status filter
        if (!empty($filters['status'])) {
            $where[] = "se.status = %s";
            $params[] = $filters['status'];
        }
    
        // Start date filter
        if (!empty($filters['start_date'])) {
            $where[] = "DATE(se.start_time) >= %s";
            $params[] = $filters['start_date'];
        }
    
        // End date filter
        if (!empty($filters['end_date'])) {
            $where[] = "DATE(se.start_time) <= %s";
            $params[] = $filters['end_date'];
        }
    
        // Example: Email filter (new valid option)
        if (!empty($filters['email'])) {
            $like = '%' . $this->db->esc_like($filters['email']) . '%';
            $where[] = "inv.email LIKE %s";
            $params[] = $like;
        }
    
        // Example: Event type filter (new valid option)
        if (!empty($filters['event_type'])) {
            $where[] = "et.name = %s";
            $params[] = $filters['event_type'];
        }
    
        $where_sql = $where ? "WHERE " . implode(" AND ", $where) : "";
    
        // Sorting
        $orderby = $args['orderby'] ?? 'start_time';
        $order   = strtoupper($args['order'] ?? 'DESC');
    
        $allowed_orderby = ['start_time', 'status', 'event_name', 'invitee_name', 'order_id', 'location', 'event_type'];
        if (!in_array($orderby, $allowed_orderby, true)) {
            $orderby = 'start_time';
        }
    
        if (!in_array($order, ['ASC', 'DESC'], true)) {
            $order = 'DESC';
        }
    
        // Pagination
        $limit  = isset($args['limit'])  ? intval($args['limit'])  : 50;
        $offset = isset($args['offset']) ? intval($args['offset']) : 0;
    
        $sql = "
            SELECT se.id, se.uuid, se.order_id, se.name AS event_name, se.start_time, se.end_time, se.status,
                   se.reschedule_url, se.cancel_url, se.payload AS event_payload, se.notes, 
                   et.name AS event_type, 
                   ml.name AS location,
                   inv.name AS invitee_name, inv.email AS invitee_email, inv.payload AS invitee_payload
            FROM {$this->table_scheduled_events} se
            LEFT JOIN {$this->table_event_types} et ON se.event_type_id = et.id
            LEFT JOIN {$this->table_locations} ml ON se.location_id = ml.id
            LEFT JOIN {$this->table_invitees} inv ON inv.scheduled_event_uuid = se.uuid
            {$where_sql}
            ORDER BY {$orderby} {$order}
            LIMIT %d OFFSET %d
        ";
    
        $params[] = $limit;
        $params[] = $offset;
        
        return $this->db->get_results($this->db->prepare($sql, $params), ARRAY_A) ?: [];
    }
    
    /**
     * Get invitee history by name.
     *
     * @param string $invitee Invitee name or identifier
     * @return array|null
     */
    public function get_invitee_history($invitee) {
        global $wpdb;
        
        $inv = implode(' ', explode('_', $invitee));
    
        // Base query: join invitees and events
        $sql = $wpdb->prepare(
            "SELECT se.uuid, se.name AS event_name, se.start_time, se.status, se.notes, ml.name AS location, inv.name AS invitee_name
             FROM {$this->table_scheduled_events} se
    		 LEFT JOIN {$this->table_locations} ml ON se.location_id = ml.id
             LEFT JOIN {$this->table_invitees} inv ON inv.scheduled_event_uuid = se.uuid
             WHERE inv.name = %s
    		 ORDER BY se.start_time DESC",
            $inv
        );
    
        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (empty($rows)) {
            return null;
        }
    
        // Normalize response
        $history = [];
        foreach ($rows as $row) {
            $history[] = [
                'event_id'     => $row['uuid'],
                'event_name'   => $row['event_name'],
                'start_time'   => get_date_from_gmt(
                    $row['start_time'],
                    get_option('date_format') . ' ' . get_option('time_format')
                ),
                'location'     => $row['location'],
                'notes'        => $row['notes'],
                'status'       => $row['status'],
                'invitee_name' => $row['invitee_name'],
            ];
        }
        return [ 'success' => true, 'data' => $history, ];
    }
    
    /**
     * Get all sessions for an invitee, optionally filtered by event.
     *
     * @param string $invitee Invitee name or identifier
     * @param string|null $event Optional event filter
     * @return array|null
     */
    public function get_invitee_history_by_email($email) {
        global $wpdb;
    
        $sql = $wpdb->prepare(
            "SELECT se.uuid, se.name AS event_name, se.start_time, ml.name AS location,
                    se.notes, se.status, i.email AS invitee_email, i.name AS invitee_name
             FROM {$this->table_scheduled_events} se
             INNER JOIN {$this->table_invitees} i ON se.uuid = i.scheduled_event_uuid
             INNER JOIN {$this->table_locations} ml ON se.location_id = ml.id
             WHERE i.email = %s
             ORDER BY se.start_time DESC",
            $email
        );
    
        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (empty($rows)) {
            return null;
        }
    
        $history = [];
        foreach ($rows as $row) {
            $history[] = [
                'event_id'     => $row['uuid'],
                'event_name'   => $row['event_name'],
                'start_time'   => get_date_from_gmt(
                    $row['start_time'],
                    get_option('date_format') . ' ' . get_option('time_format')
                ),
                'location'     => $row['location'],
                'notes'        => $row['notes'],
                'status'       => $row['status'],
                'invitee_name' => $row['invitee_name'],
                'invitee_email'=> $row['invitee_email'],
            ];
        }
    
        return $history;
    }

	
    /**
     * Count events for pagination.
     * @param array $filters
     * @return int
     */
    public function count_events(array $filters = []): int {

        $where = [];
        $params = [];

        if (!empty($filters['status'])) {
            $where[] = "status = %s";
            $params[] = $filters['status'];
        }

        if (!empty($filters['start_date'])) {
            $where[] = "DATE(start_time) >= %s";
            $params[] = $filters['start_date'];
        }

        if (!empty($filters['end_date'])) {
            $where[] = "DATE(start_time) <= %s";
            $params[] = $filters['end_date'];
        }

        $where_sql = $where ? "WHERE " . implode(" AND ", $where) : "";

        $sql = "SELECT COUNT(*) FROM {$this->table_scheduled_events} {$where_sql}";

        return (int) ($params ? $this->db->get_var($this->db->prepare($sql, $params))
                              : $this->db->get_var($sql));
    }

	/**
	 * Update a scheduled event by UUID.
	 *
	 * @param string $uuid
	 * @param array $data
	 * @return bool
	 */
    public function update_event(string $uuid, array $data): bool {
        if (!$uuid || empty($data)) {
            return false;
        }

        $allowed_event = ['notes','completed','status','location','order_id'];
        $allowed_invitee = ['invitee_name','invitee_payload'];

        $update_event = [];
        $update_invitee = [];

        foreach ($allowed_event as $field) {
            if (array_key_exists($field, $data)) {
                $value = $data[$field];
                // If notes is JSON, keep as-is; otherwise sanitize text
                if ($field === 'notes' && is_string($value)) {
                    $update_event[$field] = $value;
                } else {
                    $update_event[$field] = is_string($value)
                        ? sanitize_text_field($value)
                        : $value;
                }
            }
        }

        foreach ($allowed_invitee as $field) {
            if (array_key_exists($field, $data)) {
                $value = $data[$field];
                $update_invitee[$field] = is_string($value)
                    ? sanitize_text_field($value)
                    : $value;
            }
        }

        if (isset($update_event['completed'])) {
            $update_event['completed'] = $update_event['completed'] ? 1 : 0;
        }

        $success = true;

        if (!empty($update_event)) {
            $updated = $this->db->update(
                $this->table_scheduled_events,
                $update_event,
                ['uuid' => sanitize_text_field($uuid)],
                null,
                ['%s']
            );
            if ($updated === false) {
                $success = false;
            }
        }

        if (!empty($update_invitee)) {
            $updated_inv = $this->db->update(
                $this->table_invitees,
                $update_invitee,
                ['scheduled_event_uuid' => sanitize_text_field($uuid)],
                null,
                ['%s']
            );
            if ($updated_inv === false) {
                $success = false;
            }
        }

        return $success;
    }

    /**
     * Fetch a single event by UUID.
     * @param string $uuid
     * @return array|null
     */
	public function get_event(string $uuid): ?array {
		$sql = "
			SELECT se.id,
				   se.uuid,
				   se.order_id,
				   se.name AS event_name,
				   se.start_time,
				   se.end_time,
				   se.status,
				   se.reschedule_url,
				   se.cancel_url,
				   se.payload AS event_payload,
				   se.notes,
				   et.name AS event_type,
				   ml.name AS location,
				   inv.name AS invitee_name,
				   inv.payload AS invitee_payload
			FROM {$this->table_scheduled_events} se
			LEFT JOIN {$this->table_event_types} et ON se.event_type_id = et.id
			LEFT JOIN {$this->table_locations} ml ON se.location_id = ml.id
			LEFT JOIN {$this->table_invitees} inv ON inv.scheduled_event_uuid = se.uuid
			WHERE se.uuid = %s
			LIMIT 1
		";

		$row = $this->db->get_row($this->db->prepare($sql, $uuid), ARRAY_A);
		return $row ? ['success' => true, 'data' => $row] : null;
	}

    /**
     * Get all available locations.
     *
     * @return array|null
     */
    public function get_locations(): array {
        $sql = "SELECT id, uuid, type, name FROM {$this->table_locations} ORDER BY name ASC";
        $data = $this->db->get_results($sql, ARRAY_A);
        return $data ? ['success' => true, 'data' => $data] : null;
    }
}
