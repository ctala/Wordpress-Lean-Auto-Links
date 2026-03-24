<?php
declare(strict_types=1);

namespace LeanAutoLinks\Api;

if (!defined('ABSPATH')) {
    exit;
}

use LeanAutoLinks\Repositories\PerformanceRepository;

/**
 * REST controller for performance metrics (lw_performance_log).
 *
 * Endpoints:
 *   GET /performance/summary - Aggregated metrics
 *   GET /performance/log     - Detailed log with filters
 */
final class PerformanceController extends RestController
{
    private PerformanceRepository $repo;

    public function __construct(PerformanceRepository $repo)
    {
        $this->repo = $repo;
    }

    public function register_routes(): void
    {
        register_rest_route($this->namespace, '/performance/summary', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [$this, 'get_summary'],
            'permission_callback' => [$this, 'check_permissions'],
            'args'                => [
                'period' => [
                    'type'    => 'string',
                    'enum'    => ['24h', '7d', '30d'],
                    'default' => '24h',
                ],
            ],
        ]);

        register_rest_route($this->namespace, '/performance/log', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [$this, 'get_log'],
            'permission_callback' => [$this, 'check_permissions'],
            'args'                => array_merge($this->get_collection_params(), [
                'event_type' => [
                    'type'    => 'string',
                    'default' => '',
                ],
                'post_id' => [
                    'type'    => 'integer',
                    'default' => 0,
                ],
                'date_after' => [
                    'type'    => 'string',
                    'default' => '',
                ],
                'date_before' => [
                    'type'    => 'string',
                    'default' => '',
                ],
            ]),
        ]);
    }

    /**
     * GET /performance/summary - Aggregated performance metrics.
     */
    public function get_summary(\WP_REST_Request $request): \WP_REST_Response
    {
        $period  = $request->get_param('period') ?: '24h';
        $summary = $this->repo->get_summary($period);

        // Compute a rough cache hit rate from object cache if available.
        $cache_stats = wp_cache_get('lw_cache_stats', 'leanautolinks');
        $hit_rate    = 0.0;
        if (is_array($cache_stats) && !empty($cache_stats['hits']) && !empty($cache_stats['total'])) {
            $hit_rate = round(($cache_stats['hits'] / $cache_stats['total']) * 100, 1);
        }

        $summary['cache_hit_rate'] = $hit_rate;

        return new \WP_REST_Response($summary, 200);
    }

    /**
     * GET /performance/log - Detailed performance log with filters.
     */
    public function get_log(\WP_REST_Request $request): \WP_REST_Response
    {
        global $wpdb;

        $pagination = $this->get_pagination_params($request);
        $table      = $wpdb->prefix . 'lw_performance_log';

        $where  = '1=1';
        $params = [];

        $event_type = $request->get_param('event_type');
        if (!empty($event_type)) {
            $where   .= ' AND event_type = %s';
            $params[] = $event_type;
        }

        $post_id = (int) $request->get_param('post_id');
        if ($post_id > 0) {
            $where   .= ' AND post_id = %d';
            $params[] = $post_id;
        }

        $date_after = $request->get_param('date_after');
        if (!empty($date_after)) {
            $where   .= ' AND logged_at >= %s';
            $params[] = sanitize_text_field($date_after);
        }

        $date_before = $request->get_param('date_before');
        if (!empty($date_before)) {
            $where   .= ' AND logged_at <= %s';
            $params[] = sanitize_text_field($date_before);
        }

        // Count total.
        $count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where}";
        if (empty($params)) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Safe: table name from $wpdb->prefix.
            $total = (int) $wpdb->get_var($count_sql);
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Safe: table name from $wpdb->prefix, values via prepare().
            $total = (int) $wpdb->get_var($wpdb->prepare($count_sql, ...$params));
        }

        // Fetch results.
        $query_params   = $params;
        $query_params[] = $pagination['per_page'];
        $query_params[] = $pagination['offset'];

        $sql = "SELECT * FROM {$table} WHERE {$where} ORDER BY logged_at DESC LIMIT %d OFFSET %d";
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Safe: table name from $wpdb->prefix, values via prepare().
        $results = $wpdb->get_results($wpdb->prepare($sql, ...$query_params));

        $response = new \WP_REST_Response($results ?: [], 200);

        return $this->add_pagination_headers($response, $total, $pagination['per_page'], $pagination['page']);
    }
}
