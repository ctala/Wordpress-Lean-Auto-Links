<?php
declare(strict_types=1);

namespace LeanAutoLinks\Api;

if (!defined('ABSPATH')) {
    exit;
}

use LeanAutoLinks\Repositories\QueueRepository;

/**
 * REST controller for the processing queue (lw_queue).
 *
 * Endpoints:
 *   GET    /queue            - List queue items with status filter + pagination
 *   POST   /queue/bulk       - Trigger bulk reprocess
 *   POST   /queue/retry      - Retry all failed items
 *   DELETE /queue/clear-done - Clear completed items
 *   GET    /queue/{post_id}  - Get queue status for specific post
 */
final class QueueController extends RestController
{
    private QueueRepository $repo;

    public function __construct(QueueRepository $repo)
    {
        $this->repo = $repo;
    }

    public function register_routes(): void
    {
        register_rest_route($this->namespace, '/queue', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [$this, 'get_items'],
            'permission_callback' => [$this, 'check_permissions'],
            'args'                => array_merge($this->get_collection_params(), [
                'status' => [
                    'type'    => 'string',
                    'enum'    => ['pending', 'processing', 'done', 'failed'],
                    'default' => '',
                ],
            ]),
        ]);

        register_rest_route($this->namespace, '/queue/bulk', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'bulk_reprocess'],
            'permission_callback' => [$this, 'check_permissions'],
        ]);

        register_rest_route($this->namespace, '/queue/retry', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'retry_failed'],
            'permission_callback' => [$this, 'check_permissions'],
        ]);

        register_rest_route($this->namespace, '/queue/clear-done', [
            'methods'             => \WP_REST_Server::DELETABLE,
            'callback'            => [$this, 'clear_done'],
            'permission_callback' => [$this, 'check_permissions'],
        ]);

        register_rest_route($this->namespace, '/queue/(?P<post_id>\d+)', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [$this, 'get_item'],
            'permission_callback' => [$this, 'check_permissions'],
        ]);
    }

    /**
     * GET /queue - List queue items with optional status filter.
     */
    public function get_items($request): \WP_REST_Response
    {
        global $wpdb;

        $pagination = $this->get_pagination_params($request);
        $table      = $wpdb->prefix . 'lw_queue';

        $where  = '1=1';
        $params = [];

        $status = $request->get_param('status');
        if (!empty($status)) {
            $where   .= ' AND status = %s';
            $params[] = $status;
        }

        // Count total.
        $count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where}";
        $total     = empty($params)
            ? (int) $wpdb->get_var($count_sql)
            : (int) $wpdb->get_var($wpdb->prepare($count_sql, ...$params));

        // Fetch with post titles via LEFT JOIN.
        $query_params   = $params;
        $query_params[] = $pagination['per_page'];
        $query_params[] = $pagination['offset'];

        $sql = "SELECT q.*, p.post_title
                FROM {$table} q
                LEFT JOIN {$wpdb->posts} p ON q.post_id = p.ID
                WHERE {$where}
                ORDER BY q.priority ASC, q.scheduled_at DESC
                LIMIT %d OFFSET %d";

        $results  = $wpdb->get_results($wpdb->prepare($sql, ...$query_params));
        $response = new \WP_REST_Response($results ?: [], 200);

        return $this->add_pagination_headers($response, $total, $pagination['per_page'], $pagination['page']);
    }

    /**
     * GET /queue/{post_id} - Get queue status for a specific post.
     */
    public function get_item($request): \WP_REST_Response|\WP_Error
    {
        global $wpdb;

        $post_id = (int) $request->get_param('post_id');
        $table   = $wpdb->prefix . 'lw_queue';

        $item = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE post_id = %d", $post_id)
        );

        if (!$item) {
            return $this->error('not_found', __('No queue entry for this post.', 'leanautolinks'), 404);
        }

        return new \WP_REST_Response($item, 200);
    }

    /**
     * POST /queue/bulk - Trigger bulk reprocessing.
     *
     * Accepts: scope (all|post_type), post_type, date_after, date_before.
     */
    public function bulk_reprocess(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        global $wpdb;

        $body  = $request->get_json_params();
        $scope = $body['scope'] ?? 'all';

        $where  = "post_status = 'publish'";
        $params = [];

        $supported_types = (array) get_option('leanautolinks_supported_post_types', ['post', 'page']);

        if ($scope === 'post_type' && !empty($body['post_type'])) {
            $where   .= ' AND post_type = %s';
            $params[] = sanitize_text_field($body['post_type']);
        } else {
            $placeholders = implode(',', array_fill(0, count($supported_types), '%s'));
            $where       .= " AND post_type IN ({$placeholders})";
            $params       = array_merge($params, $supported_types);
        }

        if (!empty($body['date_after'])) {
            $where   .= ' AND post_date >= %s';
            $params[] = sanitize_text_field($body['date_after']);
        }

        if (!empty($body['date_before'])) {
            $where   .= ' AND post_date <= %s';
            $params[] = sanitize_text_field($body['date_before']);
        }

        $sql      = "SELECT ID FROM {$wpdb->posts} WHERE {$where}";
        $post_ids = $wpdb->get_col($wpdb->prepare($sql, ...$params));
        $enqueued = 0;

        foreach ($post_ids as $post_id) {
            $this->repo->enqueue((int) $post_id, 'bulk_reprocess', 50);
            $enqueued++;
        }

        // Schedule batch processing.
        if (function_exists('as_schedule_single_action') && $enqueued > 0) {
            as_schedule_single_action(
                time(),
                'leanautolinks_process_batch',
                ['triggered_by' => 'bulk_reprocess'],
                'leanautolinks'
            );
        }

        return new \WP_REST_Response([
            'enqueued' => $enqueued,
            'message'  => sprintf(
                /* translators: %d: number of posts enqueued */
                __('%d posts enqueued for reprocessing.', 'leanautolinks'),
                $enqueued
            ),
        ], 200);
    }

    /**
     * POST /queue/retry - Retry all failed items.
     */
    public function retry_failed(\WP_REST_Request $request): \WP_REST_Response
    {
        global $wpdb;

        $table = $wpdb->prefix . 'lw_queue';

        $count = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table} WHERE status = 'failed'"
        );

        $wpdb->query(
            "UPDATE {$table} SET status = 'pending', attempts = 0, error_log = NULL, processed_at = NULL WHERE status = 'failed'"
        );

        if (function_exists('as_schedule_single_action') && $count > 0) {
            as_schedule_single_action(
                time(),
                'leanautolinks_process_batch',
                ['triggered_by' => 'retry_failed'],
                'leanautolinks'
            );
        }

        return new \WP_REST_Response([
            'retried' => $count,
            'message' => sprintf(
                /* translators: %d: number of items retried */
                __('%d failed items reset for retry.', 'leanautolinks'),
                $count
            ),
        ], 200);
    }

    /**
     * DELETE /queue/clear-done - Remove completed items.
     */
    public function clear_done(\WP_REST_Request $request): \WP_REST_Response
    {
        global $wpdb;

        $table = $wpdb->prefix . 'lw_queue';

        $count = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table} WHERE status = 'done'"
        );

        $wpdb->query("DELETE FROM {$table} WHERE status = 'done'");

        return new \WP_REST_Response([
            'cleared' => $count,
            'message' => sprintf(
                /* translators: %d: number of items cleared */
                __('%d completed items cleared.', 'leanautolinks'),
                $count
            ),
        ], 200);
    }
}
