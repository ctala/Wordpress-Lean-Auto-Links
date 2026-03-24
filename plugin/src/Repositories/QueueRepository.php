<?php
declare(strict_types=1);

namespace LeanAutoLinks\Repositories;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Repository for the lw_queue table.
 *
 * Manages the processing queue for posts that need link computation.
 * Uses INSERT ... ON DUPLICATE KEY UPDATE to handle re-enqueue of the
 * same post_id (UNIQUE constraint on post_id).
 */
final class QueueRepository
{
    private string $table;

    public function __construct()
    {
        global $wpdb;
        $this->table = $wpdb->prefix . 'lw_queue';
    }

    /**
     * Enqueue a post for processing.
     *
     * If the post is already in the queue, its status is reset to 'pending'
     * and the trigger/priority are updated.
     */
    public function enqueue(int $post_id, string $triggered_by = 'save_post', int $priority = 10): void
    {
        global $wpdb;

        $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO {$this->table} (post_id, status, triggered_by, priority, attempts, scheduled_at)
                 VALUES (%d, 'pending', %s, %d, 0, NOW())
                 ON DUPLICATE KEY UPDATE
                    status = 'pending',
                    triggered_by = VALUES(triggered_by),
                    priority = LEAST(priority, VALUES(priority)),
                    attempts = 0,
                    scheduled_at = NOW(),
                    processed_at = NULL,
                    error_log = NULL",
                $post_id,
                $triggered_by,
                $priority
            )
        );
    }

    /**
     * Get pending posts ordered by priority and scheduled time.
     *
     * @param int $limit Maximum number of posts to return.
     * @return array<object>
     */
    public function get_pending(int $limit = 100): array
    {
        global $wpdb;

        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table}
                 WHERE status = 'pending'
                 ORDER BY priority ASC, scheduled_at ASC
                 LIMIT %d",
                $limit
            )
        );

        return is_array($results) ? $results : [];
    }

    /**
     * Mark a post as currently being processed.
     */
    public function mark_processing(int $post_id): void
    {
        global $wpdb;

        $wpdb->update(
            $this->table,
            ['status' => 'processing'],
            ['post_id' => $post_id],
            ['%s'],
            ['%d']
        );
    }

    /**
     * Mark a post as successfully processed.
     */
    public function mark_done(int $post_id): void
    {
        global $wpdb;

        $wpdb->update(
            $this->table,
            [
                'status'       => 'done',
                'processed_at' => current_time('mysql', true),
            ],
            ['post_id' => $post_id],
            ['%s', '%s'],
            ['%d']
        );
    }

    /**
     * Mark a post as failed with an error message.
     */
    public function mark_failed(int $post_id, string $error): void
    {
        global $wpdb;

        $wpdb->update(
            $this->table,
            [
                'status'       => 'failed',
                'error_log'    => $error,
                'processed_at' => current_time('mysql', true),
            ],
            ['post_id' => $post_id],
            ['%s', '%s', '%s'],
            ['%d']
        );
    }

    /**
     * Increment the attempt counter for a post and return the new value.
     */
    public function increment_attempts(int $post_id): int
    {
        global $wpdb;

        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$this->table} SET attempts = attempts + 1 WHERE post_id = %d",
                $post_id
            )
        );

        $attempts = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT attempts FROM {$this->table} WHERE post_id = %d",
                $post_id
            )
        );

        return (int) $attempts;
    }

    /**
     * Get queue statistics: count per status.
     *
     * @return array{pending: int, processing: int, done: int, failed: int, total: int}
     */
    public function get_stats(): array
    {
        global $wpdb;

        $results = $wpdb->get_results(
            "SELECT status, COUNT(*) as count FROM {$this->table} GROUP BY status",
            OBJECT_K
        );

        $stats = [
            'pending'    => 0,
            'processing' => 0,
            'done'       => 0,
            'failed'     => 0,
            'total'      => 0,
        ];

        foreach ($results as $status => $row) {
            $stats[$status] = (int) $row->count;
            $stats['total'] += (int) $row->count;
        }

        return $stats;
    }
}
