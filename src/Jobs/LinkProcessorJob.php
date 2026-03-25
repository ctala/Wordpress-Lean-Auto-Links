<?php
declare(strict_types=1);

namespace LeanAutoLinks\Jobs;

if (!defined('ABSPATH')) {
    exit;
}

use LeanAutoLinks\Cache\RulesCache;
use LeanAutoLinks\Engine\ContentParser;
use LeanAutoLinks\Engine\LinkBuilder;
use LeanAutoLinks\Engine\RuleMatcherEngine;
use LeanAutoLinks\Repositories\AppliedLinksRepository;
use LeanAutoLinks\Repositories\PerformanceRepository;
use LeanAutoLinks\Repositories\QueueRepository;

/**
 * Background job that integrates the engine with the queue and cache systems.
 *
 * Called by Action Scheduler actions to process posts asynchronously.
 * Handles single-post processing, batch processing, and cache re-warming.
 *
 * Retry strategy: exponential backoff at 60s, 300s, 3600s. Max 5 attempts.
 * Memory safety: monitors usage and aborts batch at 28MB threshold.
 */
final class LinkProcessorJob
{
    private const CACHE_GROUP = 'leanautolinks';

    /** Cache TTL for processed content: 1 hour. */
    private const CACHE_TTL = 3600;

    /** Memory headroom to reserve in bytes (32MB before the PHP limit). */
    private const MEMORY_HEADROOM = 32 * 1024 * 1024;

    /** Maximum retry attempts before giving up. */
    private const MAX_ATTEMPTS = 5;

    /** Retry delays in seconds, indexed by attempt number (0-based). */
    private const RETRY_DELAYS = [60, 300, 3600, 3600, 3600];

    private QueueRepository $queue_repo;
    private AppliedLinksRepository $applied_repo;
    private PerformanceRepository $performance_repo;

    public function __construct(
        QueueRepository $queue_repo,
        AppliedLinksRepository $applied_repo,
        PerformanceRepository $performance_repo
    ) {
        $this->queue_repo       = $queue_repo;
        $this->applied_repo     = $applied_repo;
        $this->performance_repo = $performance_repo;
    }

    /**
     * Process a single post: load content, run engine, store results, update cache.
     *
     * This is the primary entry point called by the leanautolinks_process_single
     * Action Scheduler action.
     *
     * @param int $post_id The post ID to process.
     */
    public function process_single(int $post_id): void
    {
        // Mark as processing in the queue.
        $this->queue_repo->mark_processing($post_id);

        try {
            $result = $this->execute($post_id);

            if ($result === null) {
                // Post not found or not eligible; mark as done silently.
                $this->queue_repo->mark_done($post_id);
                return;
            }

            $this->store_results($post_id, $result);
            $this->queue_repo->mark_done($post_id);
        } catch (\Throwable $error) {
            $this->handle_failure($post_id, $error);
        }
    }

    /**
     * Process a batch of posts from the queue.
     *
     * Fetches pending posts and processes them sequentially, monitoring
     * memory usage and aborting if approaching the 32MB limit.
     *
     * @param array $args Batch arguments: ['triggered_by' => string, 'limit' => int].
     */
    public function process_batch(array $args): void
    {
        $batch_size = (int) get_option('leanautolinks_batch_size', 25);
        $limit = isset($args['limit']) ? min((int) $args['limit'], $batch_size) : $batch_size;
        $pending = $this->queue_repo->get_pending($limit);

        if (empty($pending)) {
            return;
        }

        $processed_count = 0;
        $memory_aborted = false;

        foreach ($pending as $queue_item) {
            // Memory safety check: abort if within 32MB of the PHP memory limit.
            $memory_limit = $this->get_memory_limit();
            if ($memory_limit > 0 && memory_get_usage(true) > ($memory_limit - self::MEMORY_HEADROOM)) {
                $memory_aborted = true;
                break;
            }

            $post_id = (int) $queue_item->post_id;
            $this->process_single($post_id);
            $processed_count++;
        }

        // Ensure the recurring batch schedule is active if there are more pending posts.
        $this->ensure_recurring_schedule();
    }

    /**
     * Re-cache a single post (triggered by a cache miss on the frontend).
     *
     * This is a lighter-weight version of process_single that does not
     * interact with the queue table (the post may not be in the queue).
     *
     * @param int $post_id The post ID to re-cache.
     */
    public function recache_post(int $post_id): void
    {
        try {
            $result = $this->execute($post_id);

            if ($result === null) {
                return;
            }

            $this->store_results($post_id, $result);
        } catch (\Throwable $error) {
            // Log the failure but do not retry for re-cache operations.
            // The next page view will trigger another re-cache attempt.
            $this->performance_repo->log(
                'recache_failure',
                $post_id,
                0,
                (int) (memory_get_peak_usage(true) / 1024),
                0,
                0
            );
        }
    }

    /**
     * Execute the engine for a single post.
     *
     * Loads the post content, fetches active rules from cache, runs the
     * RuleMatcherEngine, and returns the result.
     *
     * @param int $post_id The post ID to process.
     * @return array{content: string, links: array}|null Result array, or null if post is ineligible.
     */
    private function execute(int $post_id): ?array
    {
        $start_time = hrtime(true);
        $start_memory = memory_get_usage(true);

        // Load the post.
        $post = get_post($post_id);
        if (!$post instanceof \WP_Post) {
            return null;
        }

        // Guard: only process published posts.
        if ($post->post_status !== 'publish') {
            return null;
        }

        // Guard: only process supported post types.
        $supported_types = (array) get_option('leanautolinks_supported_post_types', ['post', 'page']);
        if (!in_array($post->post_type, $supported_types, true)) {
            return null;
        }

        // Get the post content. Apply early content filters (shortcodes, blocks)
        // but not the_content filters (which would include our own filter).
        $content = $post->post_content;

        // Expand shortcodes and blocks if WordPress functions are available.
        if (function_exists('do_shortcode')) {
            $content = do_shortcode($content);
        }
        if (function_exists('do_blocks')) {
            $content = do_blocks($content);
        }

        // Load active rules from the three-layer cache.
        $rules = RulesCache::get_active_rules();

        // Instantiate the engine.
        $parser = new ContentParser();
        $builder = new LinkBuilder();
        $engine = new RuleMatcherEngine($parser, $builder);

        // Run the engine.
        $result = $engine->process($content, $rules, $post_id);

        // Calculate performance metrics.
        $duration_ns = hrtime(true) - $start_time;
        $duration_ms = (int) ($duration_ns / 1_000_000);
        $memory_kb = (int) ((memory_get_usage(true) - $start_memory) / 1024);

        // Log performance.
        $this->performance_repo->log(
            'process_single',
            $post_id,
            $duration_ms,
            max($memory_kb, 0),
            count($rules),
            count($result['links'])
        );

        return $result;
    }

    /**
     * Store processing results: update lw_applied_links, set cache, log performance.
     *
     * Per ADR-002:
     *   - Object cache: store processed content with version-based key.
     *   - DB fallback: store in lw_applied_links with rule_id=0 summary row.
     *   - Individual links: store in lw_applied_links with real rule_id.
     *
     * @param int   $post_id The post ID.
     * @param array $result  The engine result array with 'content' and 'links'.
     */
    private function store_results(int $post_id, array $result): void
    {
        $processed_content = $result['content'];
        $links = $result['links'];

        // Compute the content hash of the original post content for staleness detection.
        $post = get_post($post_id);
        $content_hash = $post instanceof \WP_Post ? md5($post->post_content) : '';

        // Store in lw_applied_links (DB fallback + individual link records).
        $this->applied_repo->save_links(
            $post_id,
            $links,
            $processed_content,
            $content_hash
        );

        // Store in object cache with version-based metadata.
        $rules_version = RulesCache::get_version();

        wp_cache_set(
            "lw_processed:{$post_id}",
            $processed_content,
            self::CACHE_GROUP,
            self::CACHE_TTL
        );

        wp_cache_set(
            "lw_meta:{$post_id}",
            [
                'rules_version' => $rules_version,
                'content_hash'  => $content_hash,
                'link_count'    => count($links),
                'processed_at'  => time(),
            ],
            self::CACHE_GROUP,
            self::CACHE_TTL
        );

        // Update the last processed timestamp.
        update_option('leanautolinks_last_processed_at', time(), false);
    }

    /**
     * Handle processing failure with retry logic.
     *
     * Implements exponential backoff: 60s, 300s, 3600s.
     * After MAX_ATTEMPTS (5), the post is marked as permanently failed.
     *
     * @param int        $post_id The post ID that failed.
     * @param \Throwable $error   The exception or error that occurred.
     */
    /** Action Scheduler hook for the recurring batch schedule. */
    public const RECURRING_HOOK = 'leanautolinks_process_batch_recurring';

    /** Recurring interval in seconds (1 minute). */
    private const RECURRING_INTERVAL = 60;

    /**
     * Ensure a recurring AS action is scheduled for batch processing.
     *
     * Checks if there are pending posts; if yes, schedules a recurring action
     * every 60 seconds. If no pending posts remain, unschedules it.
     */
    public function ensure_recurring_schedule(): void
    {
        if (!function_exists('as_has_scheduled_action') || !function_exists('as_schedule_recurring_action')) {
            return;
        }

        $stats = $this->queue_repo->get_stats();
        $has_pending = ($stats['pending'] ?? 0) > 0;

        $is_scheduled = as_has_scheduled_action(self::RECURRING_HOOK, [], 'leanautolinks');

        if ($has_pending && !$is_scheduled) {
            as_schedule_recurring_action(
                time(),
                self::RECURRING_INTERVAL,
                self::RECURRING_HOOK,
                [],
                'leanautolinks'
            );
        } elseif (!$has_pending && $is_scheduled) {
            as_unschedule_all_actions(self::RECURRING_HOOK, [], 'leanautolinks');
        }
    }

    /**
     * Get the PHP memory limit in bytes.
     *
     * Returns 0 if the limit is unlimited (-1).
     */
    private function get_memory_limit(): int
    {
        $limit = ini_get('memory_limit');
        if ($limit === '-1' || $limit === false) {
            return 0;
        }

        $value = (int) $limit;
        $unit = strtolower(substr($limit, -1));

        return match ($unit) {
            'g' => $value * 1024 * 1024 * 1024,
            'm' => $value * 1024 * 1024,
            'k' => $value * 1024,
            default => $value,
        };
    }

    private function handle_failure(int $post_id, \Throwable $error): void
    {
        $attempts = $this->queue_repo->increment_attempts($post_id);

        $error_message = sprintf(
            '[%s] %s in %s:%d',
            get_class($error),
            $error->getMessage(),
            $error->getFile(),
            $error->getLine()
        );

        if ($attempts >= self::MAX_ATTEMPTS) {
            // Permanently failed: stop retrying.
            $this->queue_repo->mark_failed($post_id, $error_message);

            $this->performance_repo->log(
                'process_failure_permanent',
                $post_id,
                0,
                (int) (memory_get_peak_usage(true) / 1024),
                0,
                0
            );

            return;
        }

        // Schedule a retry with exponential backoff.
        $delay = self::RETRY_DELAYS[$attempts - 1] ?? 3600;

        // Mark as pending again so it can be picked up.
        $this->queue_repo->mark_failed($post_id, $error_message);

        // Re-enqueue with the retry delay via Action Scheduler.
        if (function_exists('as_schedule_single_action')) {
            as_schedule_single_action(
                time() + $delay,
                'leanautolinks_process_single',
                ['post_id' => $post_id],
                'leanautolinks'
            );
        }

        $this->performance_repo->log(
            'process_failure_retry',
            $post_id,
            0,
            (int) (memory_get_peak_usage(true) / 1024),
            0,
            0
        );
    }
}
