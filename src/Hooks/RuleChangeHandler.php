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

    /** Maximum post IDs to enqueue per INSERT batch. */
    private const ENQUEUE_BATCH = 500;

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
     * Reprocessing rules:
     *   - created:  Enqueue all posts whose content contains the keyword.
     *   - updated:  Enqueue posts previously linked by this rule (to remove old
     *               link) + posts containing the new keyword (to add new link).
     *   - deleted:  Enqueue posts previously linked by this rule (to remove link),
     *               then delete applied_links records.
     *   - toggled:  Same as updated (posts may gain or lose the link).
     *
     * Only affected posts are enqueued — never the full site. Posts are found
     * by querying lw_applied_links (for existing links) and wp_posts.post_content
     * LIKE (for potential new matches). Both queries are paginated to handle
     * keywords appearing in 10,000+ posts without memory issues.
     *
     * @param int    $rule_id The rule that was created, updated, deleted, or toggled.
     * @param string $action  One of 'created', 'updated', 'deleted', 'toggled'.
     */
    public function handle(int $rule_id, string $action): void
    {
        // Step 1: Invalidate all rule caches and increment version.
        RulesCache::flush();

        // Step 2: Enqueue posts previously linked by this rule.
        $enqueued = 0;
        if (in_array($action, ['updated', 'deleted', 'toggled'], true)) {
            $enqueued += $this->enqueue_previously_linked($rule_id);
        }

        // Step 3: Enqueue posts whose content contains the keyword.
        if (in_array($action, ['created', 'updated', 'toggled'], true)) {
            $enqueued += $this->enqueue_keyword_matches($rule_id);
        }

        // Step 4: Schedule batch processing if any posts were enqueued.
        if ($enqueued > 0 && function_exists('as_schedule_single_action')) {
            as_schedule_single_action(
                time(),
                'leanautolinks_process_batch',
                [['triggered_by' => 'rule_change']],
                'leanautolinks'
            );
        }

        // Step 5: If the rule was deleted, clean up its applied links records.
        if ($action === 'deleted') {
            $this->applied_repo->delete_by_rule($rule_id);
        }
    }

    /**
     * Enqueue posts that previously had this rule applied.
     *
     * These posts need reprocessing to remove/update the old link.
     * Paginated to handle rules applied in 10,000+ posts.
     *
     * @return int Number of posts enqueued.
     */
    private function enqueue_previously_linked(int $rule_id): int
    {
        global $wpdb;

        $table    = $wpdb->prefix . 'lw_applied_links';
        $offset   = 0;
        $enqueued = 0;

        do {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $batch = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT DISTINCT post_id FROM {$table} WHERE rule_id = %d LIMIT %d OFFSET %d",
                    $rule_id,
                    self::ENQUEUE_BATCH,
                    $offset
                )
            );

            foreach ($batch as $post_id) {
                $this->queue_repo->enqueue((int) $post_id, 'rule_change', self::PRIORITY_RULE_CHANGE);
                wp_cache_delete("lw_processed:{$post_id}", 'leanautolinks');
                wp_cache_delete("lw_meta:{$post_id}", 'leanautolinks');
                $enqueued++;
            }

            $offset += self::ENQUEUE_BATCH;
        } while (count($batch) === self::ENQUEUE_BATCH);

        return $enqueued;
    }

    /**
     * Enqueue posts whose content contains the rule's keyword.
     *
     * These posts might gain a new link (or need the link updated).
     * Paginated to handle keywords appearing in 10,000+ posts.
     *
     * @return int Number of posts enqueued.
     */
    private function enqueue_keyword_matches(int $rule_id): int
    {
        global $wpdb;

        $rule = $this->rules_repo->find($rule_id);
        if (!$rule || empty($rule->keyword)) {
            return 0;
        }

        $supported_types = (array) get_option('leanautolinks_supported_post_types', ['post', 'page']);
        $placeholders    = implode(',', array_fill(0, count($supported_types), '%s'));
        $keyword_escaped = '%' . $wpdb->esc_like($rule->keyword) . '%';

        $offset   = 0;
        $enqueued = 0;

        do {
            $params = array_merge(
                [$keyword_escaped],
                $supported_types,
                [self::ENQUEUE_BATCH, $offset]
            );

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Safe: dynamic IN() placeholders, table from $wpdb->posts.
            $batch = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT ID FROM {$wpdb->posts}
                     WHERE post_content LIKE %s
                     AND post_status = 'publish'
                     AND post_type IN ({$placeholders})
                     LIMIT %d OFFSET %d",
                    ...$params
                )
            );

            foreach ($batch as $post_id) {
                $this->queue_repo->enqueue((int) $post_id, 'rule_change', self::PRIORITY_RULE_CHANGE);
                wp_cache_delete("lw_processed:{$post_id}", 'leanautolinks');
                wp_cache_delete("lw_meta:{$post_id}", 'leanautolinks');
                $enqueued++;
            }

            $offset += self::ENQUEUE_BATCH;
        } while (count($batch) === self::ENQUEUE_BATCH);

        return $enqueued;
    }
}
