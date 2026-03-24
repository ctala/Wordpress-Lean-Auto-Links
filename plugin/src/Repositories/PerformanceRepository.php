<?php
declare(strict_types=1);

namespace LeanAutoLinks\Repositories;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Repository for the lw_performance_log table.
 *
 * Logs processing events with timing, memory, and link count metrics.
 * Provides summary and filtered views for the admin dashboard and
 * health endpoint. Includes cleanup for log rotation.
 */
final class PerformanceRepository
{
    private string $table;

    public function __construct()
    {
        global $wpdb;
        $this->table = $wpdb->prefix . 'lw_performance_log';
    }

    /**
     * Log a performance event.
     */
    public function log(
        string $event,
        ?int $post_id,
        int $duration_ms,
        int $memory_kb,
        int $rules_checked,
        int $links_applied
    ): void {
        global $wpdb;

        $wpdb->insert(
            $this->table,
            [
                'event_type'    => $event,
                'post_id'       => $post_id,
                'duration_ms'   => $duration_ms,
                'memory_kb'     => $memory_kb,
                'rules_checked' => $rules_checked,
                'links_applied' => $links_applied,
            ],
            ['%s', '%d', '%d', '%d', '%d', '%d']
        );
    }

    /**
     * Get summary statistics for a given period.
     *
     * @param string $period One of '24h', '7d', '30d'.
     * @return array{total_events: int, avg_duration_ms: float, avg_memory_kb: float, total_links_applied: int, avg_rules_checked: float}
     */
    public function get_summary(string $period = '24h'): array
    {
        global $wpdb;

        $interval = match ($period) {
            '7d'  => 'INTERVAL 7 DAY',
            '30d' => 'INTERVAL 30 DAY',
            default => 'INTERVAL 24 HOUR',
        };

        $row = $wpdb->get_row(
            "SELECT
                COUNT(*) as total_events,
                COALESCE(AVG(duration_ms), 0) as avg_duration_ms,
                COALESCE(AVG(memory_kb), 0) as avg_memory_kb,
                COALESCE(SUM(links_applied), 0) as total_links_applied,
                COALESCE(AVG(rules_checked), 0) as avg_rules_checked
             FROM {$this->table}
             WHERE logged_at > DATE_SUB(NOW(), {$interval})"
        );

        if (!$row) {
            return [
                'total_events'       => 0,
                'avg_duration_ms'    => 0.0,
                'avg_memory_kb'      => 0.0,
                'total_links_applied' => 0,
                'avg_rules_checked'  => 0.0,
            ];
        }

        return [
            'total_events'        => (int) $row->total_events,
            'avg_duration_ms'     => round((float) $row->avg_duration_ms, 2),
            'avg_memory_kb'       => round((float) $row->avg_memory_kb, 2),
            'total_links_applied' => (int) $row->total_links_applied,
            'avg_rules_checked'   => round((float) $row->avg_rules_checked, 2),
        ];
    }

    /**
     * Get log entries with optional filters.
     *
     * @param array{event_type?: string, post_id?: int, limit?: int, offset?: int} $filters
     * @return array<object>
     */
    public function get_log(array $filters = []): array
    {
        global $wpdb;

        $where  = '1=1';
        $params = [];

        if (!empty($filters['event_type'])) {
            $where   .= ' AND event_type = %s';
            $params[] = $filters['event_type'];
        }

        if (!empty($filters['post_id'])) {
            $where   .= ' AND post_id = %d';
            $params[] = (int) $filters['post_id'];
        }

        $limit  = isset($filters['limit']) ? min((int) $filters['limit'], 500) : 100;
        $offset = isset($filters['offset']) ? (int) $filters['offset'] : 0;

        $sql = "SELECT * FROM {$this->table} WHERE {$where} ORDER BY logged_at DESC LIMIT %d OFFSET %d";
        $params[] = $limit;
        $params[] = $offset;

        $results = $wpdb->get_results(
            $wpdb->prepare($sql, ...$params)
        );

        return is_array($results) ? $results : [];
    }

    /**
     * Delete log entries older than the specified number of days.
     *
     * @param int $days_to_keep Keep entries from the last N days.
     * @return int Number of rows deleted.
     */
    public function cleanup(int $days_to_keep = 30): int
    {
        global $wpdb;

        $rows = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$this->table} WHERE logged_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
                $days_to_keep
            )
        );

        return is_int($rows) ? $rows : 0;
    }
}
