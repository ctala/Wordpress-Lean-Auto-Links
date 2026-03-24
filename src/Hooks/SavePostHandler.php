<?php
declare(strict_types=1);

namespace LeanAutoLinks\Hooks;

if (!defined('ABSPATH')) {
    exit;
}

use LeanAutoLinks\Repositories\ExclusionsRepository;
use LeanAutoLinks\Repositories\QueueRepository;

/**
 * Handles the save_post hook.
 *
 * Per ADR-001 section 5.1, this handler performs exactly:
 *   1. Guard checks (post type, status, exclusions).
 *   2. One INSERT ... ON DUPLICATE KEY UPDATE on lw_queue.
 *   3. One as_enqueue_async_action() call.
 *
 * Target overhead: < 5ms, well within the 50ms budget.
 * No content processing, no rule loading, no matching happens here.
 */
final class SavePostHandler
{
    /** Priority value for new posts. */
    private const PRIORITY_NEW     = 10;

    /** Priority value for updated existing posts. */
    private const PRIORITY_UPDATE  = 20;

    private QueueRepository $queue_repo;
    private ExclusionsRepository $exclusions_repo;

    public function __construct(QueueRepository $queue_repo, ExclusionsRepository $exclusions_repo)
    {
        $this->queue_repo      = $queue_repo;
        $this->exclusions_repo = $exclusions_repo;
    }

    /**
     * Handle the save_post action.
     *
     * @param int      $post_id The post ID.
     * @param \WP_Post $post    The post object.
     * @param bool     $update  Whether this is an update to an existing post.
     */
    public function handle(int $post_id, \WP_Post $post, bool $update): void
    {
        // Guard: skip autosaves.
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        // Guard: skip revisions.
        if (wp_is_post_revision($post_id)) {
            return;
        }

        // Guard: only process published posts.
        if ($post->post_status !== 'publish') {
            return;
        }

        // Guard: only process supported post types.
        $supported_types = (array) get_option('leanautolinks_supported_post_types', ['post', 'page']);
        if (!in_array($post->post_type, $supported_types, true)) {
            return;
        }

        // Guard: check post-level exclusion.
        if ($this->exclusions_repo->is_excluded('post', (string) $post_id)) {
            return;
        }

        // Guard: check post-type-level exclusion.
        if ($this->exclusions_repo->is_excluded('post_type', $post->post_type)) {
            return;
        }

        // Determine priority: new posts get higher priority than updates.
        $priority = $update ? self::PRIORITY_UPDATE : self::PRIORITY_NEW;

        // Enqueue the post for background processing (one DB query).
        $this->queue_repo->enqueue($post_id, 'save_post', $priority);

        // Invalidate any existing processed content cache for this post.
        wp_cache_delete("lw_processed:{$post_id}", 'leanautolinks');
        wp_cache_delete("lw_meta:{$post_id}", 'leanautolinks');

        // Schedule the Action Scheduler action (one insert into AS tables).
        if (function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action(
                'leanautolinks_process_single',
                ['post_id' => $post_id],
                'leanautolinks'
            );
        }
    }
}
