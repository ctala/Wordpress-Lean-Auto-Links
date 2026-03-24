<?php
declare(strict_types=1);

namespace LeanAutoLinks\Api;

if (!defined('ABSPATH')) {
    exit;
}

use LeanAutoLinks\Repositories\PerformanceRepository;
use LeanAutoLinks\Repositories\QueueRepository;
use LeanAutoLinks\Repositories\RulesRepository;

/**
 * REST controller for system health checks.
 *
 * Endpoints:
 *   GET /health - Full system health overview
 */
final class HealthController extends RestController
{
    private QueueRepository $queue_repo;
    private RulesRepository $rules_repo;
    private PerformanceRepository $performance_repo;

    public function __construct(
        QueueRepository $queue_repo,
        RulesRepository $rules_repo,
        PerformanceRepository $performance_repo
    ) {
        $this->queue_repo       = $queue_repo;
        $this->rules_repo       = $rules_repo;
        $this->performance_repo = $performance_repo;
    }

    public function register_routes(): void
    {
        register_rest_route($this->namespace, '/health', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [$this, 'get_health'],
            'permission_callback' => [$this, 'check_permissions'],
        ]);
    }

    /**
     * GET /health - System health summary.
     */
    public function get_health(\WP_REST_Request $request): \WP_REST_Response
    {
        global $wpdb;

        // Queue stats.
        $queue_stats = $this->queue_repo->get_stats();

        // Rules count.
        $rules_table  = $wpdb->prefix . 'lw_rules';
        $total_rules  = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$rules_table}");
        $active_rules = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$rules_table} WHERE is_active = 1");

        // Processing stats (last 24h).
        $perf_summary = $this->performance_repo->get_summary('24h');

        // Cache status.
        $has_object_cache = wp_using_ext_object_cache();
        $cache_sentinel   = wp_cache_get('lw_sentinel', 'leanautolinks');

        // Determine health status.
        $status = 'healthy';
        $issues = [];

        if ($queue_stats['failed'] > 0) {
            $status   = 'warning';
            $issues[] = sprintf(
                /* translators: %d: number of failed items */
                __('%d failed items in queue.', 'leanautolinks'),
                $queue_stats['failed']
            );
        }

        if ($queue_stats['pending'] > 1000) {
            $status   = 'warning';
            $issues[] = __('Large queue backlog (>1000 pending).', 'leanautolinks');
        }

        if (!$has_object_cache) {
            $issues[] = __('No external object cache detected. Consider Redis or Memcached for better performance.', 'leanautolinks');
        }

        if ($perf_summary['avg_duration_ms'] > 5000) {
            $status   = 'warning';
            $issues[] = __('Average processing time exceeds 5 seconds.', 'leanautolinks');
        }

        // Action Scheduler availability.
        $has_action_scheduler = function_exists('as_enqueue_async_action');

        // WP-Cron health: warn if using HTTP-based cron on cached sites.
        $wp_cron_disabled = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
        if (!$wp_cron_disabled && $queue_stats['pending'] > 0) {
            $issues[] = __('WP-Cron relies on site traffic. For reliable queue processing, add a system cron job and set DISABLE_WP_CRON to true.', 'leanautolinks');
        }

        return new \WP_REST_Response([
            'status'         => $status,
            'issues'         => $issues,
            'plugin_version' => defined('LEANAUTOLINKS_VERSION') ? LEANAUTOLINKS_VERSION : 'unknown',
            'queue'          => $queue_stats,
            'rules'          => [
                'total'  => $total_rules,
                'active' => $active_rules,
            ],
            'processing'     => $perf_summary,
            'cache'          => [
                'external_object_cache' => $has_object_cache,
                'sentinel_active'       => $cache_sentinel !== false,
            ],
            'dependencies'   => [
                'action_scheduler' => $has_action_scheduler,
            ],
            'timestamp'      => current_time('c'),
        ], 200);
    }
}
