<?php
declare(strict_types=1);

namespace LeanAutoLinks\Api;

if (!defined('ABSPATH')) {
    exit;
}

use LeanAutoLinks\Repositories\AppliedLinksRepository;

/**
 * REST controller for applied links (lw_applied_links).
 *
 * Endpoints:
 *   GET /applied       - List applied links with filters + pagination
 *   GET /applied/stats - Statistics: totals, per rule_type, top posts, top rules
 */
final class AppliedController extends RestController
{
    private AppliedLinksRepository $repo;

    public function __construct(AppliedLinksRepository $repo)
    {
        $this->repo = $repo;
    }

    public function register_routes(): void
    {
        register_rest_route($this->namespace, '/applied', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [$this, 'get_items'],
            'permission_callback' => [$this, 'check_permissions'],
            'args'                => array_merge($this->get_collection_params(), [
                'post_id' => [
                    'type'    => 'integer',
                    'default' => 0,
                ],
                'rule_id' => [
                    'type'    => 'integer',
                    'default' => 0,
                ],
            ]),
        ]);

        register_rest_route($this->namespace, '/applied/stats', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [$this, 'get_stats'],
            'permission_callback' => [$this, 'check_permissions'],
        ]);
    }

    /**
     * GET /applied - List applied links.
     */
    public function get_items($request): \WP_REST_Response
    {
        global $wpdb;

        $pagination = $this->get_pagination_params($request);
        $table      = $wpdb->prefix . 'lw_applied_links';

        $where  = 'rule_id != 0';
        $params = [];

        $post_id = (int) $request->get_param('post_id');
        if ($post_id > 0) {
            $where   .= ' AND post_id = %d';
            $params[] = $post_id;
        }

        $rule_id = (int) $request->get_param('rule_id');
        if ($rule_id > 0) {
            $where   .= ' AND rule_id = %d';
            $params[] = $rule_id;
        }

        // Count total.
        $count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where}";
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $total     = empty($params)
            ? (int) $wpdb->get_var($count_sql)
            : (int) $wpdb->get_var($wpdb->prepare($count_sql, ...$params));

        // Fetch results.
        $query_params   = $params;
        $query_params[] = $pagination['per_page'];
        $query_params[] = $pagination['offset'];

        $sql     = "SELECT * FROM {$table} WHERE {$where} ORDER BY applied_at DESC LIMIT %d OFFSET %d";
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $results = $wpdb->get_results($wpdb->prepare($sql, ...$query_params));

        $response = new \WP_REST_Response($results ?: [], 200);

        return $this->add_pagination_headers($response, $total, $pagination['per_page'], $pagination['page']);
    }

    /**
     * GET /applied/stats - Statistics overview.
     */
    public function get_stats(\WP_REST_Request $request): \WP_REST_Response
    {
        global $wpdb;

        $table       = $wpdb->prefix . 'lw_applied_links';
        $rules_table = $wpdb->prefix . 'lw_rules';

        // Basic stats from repository.
        $basic = $this->repo->get_stats();

        // Per rule_type breakdown.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $by_type = $wpdb->get_results(
            "SELECT r.rule_type, COUNT(al.id) as count
             FROM {$table} al
             INNER JOIN {$rules_table} r ON al.rule_id = r.id
             WHERE al.rule_id != 0
             GROUP BY r.rule_type",
            OBJECT
        );

        $type_breakdown = [];
        foreach ($by_type ?: [] as $row) {
            $type_breakdown[$row->rule_type] = (int) $row->count;
        }

        // Top linked posts (most links applied).
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $top_posts = $wpdb->get_results(
            "SELECT al.post_id, p.post_title, COUNT(al.id) as link_count
             FROM {$table} al
             LEFT JOIN {$wpdb->posts} p ON al.post_id = p.ID
             WHERE al.rule_id != 0
             GROUP BY al.post_id
             ORDER BY link_count DESC
             LIMIT 10"
        );

        // Top rules (most applied).
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $top_rules = $wpdb->get_results(
            "SELECT al.rule_id, r.keyword, r.target_url, COUNT(al.id) as usage_count
             FROM {$table} al
             INNER JOIN {$rules_table} r ON al.rule_id = r.id
             WHERE al.rule_id != 0
             GROUP BY al.rule_id
             ORDER BY usage_count DESC
             LIMIT 10"
        );

        // Links applied today.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $today = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE rule_id != 0 AND DATE(applied_at) = %s",
                current_time('Y-m-d')
            )
        );

        return new \WP_REST_Response([
            'total_links'        => $basic['total_links'],
            'total_posts'        => $basic['total_posts'],
            'avg_links_per_post' => $basic['avg_links_per_post'],
            'links_today'        => (int) $today,
            'by_type'            => $type_breakdown,
            'top_posts'          => $top_posts ?: [],
            'top_rules'          => $top_rules ?: [],
        ], 200);
    }
}
