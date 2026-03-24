<?php
declare(strict_types=1);

namespace LeanAutoLinks\Hooks;

if (!defined('ABSPATH')) {
    exit;
}

use LeanAutoLinks\Cache\RulesCache;
use LeanAutoLinks\Repositories\AppliedLinksRepository;
use LeanAutoLinks\Repositories\QueueRepository;
use LeanAutoLinks\Repositories\RulesRepository;

/**
 * Handles rule CRUD events to invalidate caches and enqueue affected posts.
 *
 * Per ADR-001 section 5.4:
 *   1. Invalidate rules cache.
 *   2. Determine affected posts (via keyword LIKE or applied_links lookup).
 *   3. Enqueue affected posts at priority 30 (rule_change).
 *   4. Schedule batch processing.
 *
 * This handler runs in admin/API context only, never on the frontend.
 */
final class RuleChangeHandler
{
    /** Queue priority for rule-change triggered reprocessing. */
    private const PRIORITY_RULE_CHANGE = 30;

    /** Maximum post IDs to process in a single LIKE query batch. */
    private const BATCH_SIZE = 1000;

    private RulesRepository $rules_repo;
    private AppliedLinksRepository $applied_repo;
    private QueueRepository $queue_repo;

    public function __construct(
        RulesRepository $rules_repo,
        AppliedLinksRepository $applied_repo,
        QueueRepository $queue_repo
    ) {
        $this->rules_repo   = $rules_repo;
        $this->applied_repo = $applied_repo;
        $this->queue_repo   = $queue_repo;
    }

    /**
     * Handle a rule change event.
     *
     * @param int    $rule_id The rule that was created, updated, deleted, or toggled.
     * @param string $action  One of 'created', 'updated', 'deleted', 'toggled'.
     */
    public function handle(int $rule_id, string $action): void
    {
        // Step 1: Invalidate all rule caches and increment version.
        RulesCache::flush();

        // Step 2: Determine affected posts and enqueue them.
        $affected_post_ids = $this->find_affected_posts($rule_id, $action);

        if (empty($affected_post_ids)) {
            return;
        }

        // Step 3: Enqueue affected posts in batches.
        foreach ($affected_post_ids as $post_id) {
            $this->queue_repo->enqueue((int) $post_id, 'rule_change', self::PRIORITY_RULE_CHANGE);

            // Invalidate processed content cache for this post.
            wp_cache_delete("lw_processed:{$post_id}", 'leanautolinks');
            wp_cache_delete("lw_meta:{$post_id}", 'leanautolinks');
        }

        // Step 4: Schedule batch processing via Action Scheduler.
        if (function_exists('as_schedule_single_action')) {
            as_schedule_single_action(
                time(),
                'leanautolinks_process_batch',
                [['triggered_by' => 'rule_change']],
                'leanautolinks'
            );
        }

        // If the rule was deleted, clean up its applied links records.
        if ($action === 'deleted') {
            $this->applied_repo->delete_by_rule($rule_id);
        }
    }

    /**
     * Find posts affected by a rule change.
     *
     * For created/updated rules: find posts whose content contains the keyword.
     * For deleted/toggled rules: find posts that had the rule applied.
     *
     * @return array<int> Post IDs.
     */
    private function find_affected_posts(int $rule_id, string $action): array
    {
        global $wpdb;

        $post_ids = [];

        // For updates and deletes, always include posts that previously had
        // this rule applied (they need to be reprocessed without the old link).
        if (in_array($action, ['updated', 'deleted', 'toggled'], true)) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $previously_linked = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT DISTINCT post_id FROM {$wpdb->prefix}lw_applied_links WHERE rule_id = %d",
                    $rule_id
                )
            );

            $post_ids = array_map('intval', $previously_linked);
        }

        // For created/updated/toggled rules, find posts containing the keyword.
        if (in_array($action, ['created', 'updated', 'toggled'], true)) {
            $rule = $this->rules_repo->find($rule_id);

            if ($rule && !empty($rule->keyword)) {
                $supported_types = (array) get_option('leanautolinks_supported_post_types', ['post', 'page']);
                $placeholders    = implode(',', array_fill(0, count($supported_types), '%s'));

                $keyword_escaped = '%' . $wpdb->esc_like($rule->keyword) . '%';

                // Batched query to avoid memory issues with very large result sets.
                $params = array_merge(
                    [$keyword_escaped],
                    $supported_types,
                    [self::BATCH_SIZE]
                );

                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
                $matching = $wpdb->get_col(
                    $wpdb->prepare(
                        "SELECT ID FROM {$wpdb->posts}
                         WHERE post_content LIKE %s
                         AND post_status = 'publish'
                         AND post_type IN ({$placeholders})
                         LIMIT %d",
                        ...$params
                    )
                );

                $post_ids = array_unique(array_merge($post_ids, array_map('intval', $matching)));
            }
        }

        return $post_ids;
    }
}
