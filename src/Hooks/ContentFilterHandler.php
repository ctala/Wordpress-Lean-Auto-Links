<?php
declare(strict_types=1);

namespace LeanAutoLinks\Hooks;

if (!defined('ABSPATH')) {
    exit;
}

use LeanAutoLinks\Cache\RulesCache;
use LeanAutoLinks\Repositories\AppliedLinksRepository;

/**
 * Handles the the_content filter at priority 999.
 *
 * Implements the full degradation flow from ADR-002 section 7:
 *   Priority 1: Serve from object cache (0 queries, < 1ms)
 *   Priority 2: Serve from DB fallback (1 query, 2-5ms) -- only without ext cache
 *   Priority 3: Serve original content without links (0 queries, 0ms)
 *   NEVER:      Compute links at request time
 *
 * The critical guarantee: at most one wp_cache_get() call per page load when
 * external object cache is available. No database queries on the frontend
 * in the normal case.
 */
final class ContentFilterHandler
{
    private const CACHE_GROUP = 'leanautolinks';

    /** Whether the site has a persistent external object cache (Redis/Memcached). */
    private static bool $has_ext_cache = false;

    /** Whether to use the DB fallback (only when no external object cache). */
    private static bool $has_db_fallback = false;

    private AppliedLinksRepository $applied_repo;

    public function __construct(AppliedLinksRepository $applied_repo)
    {
        $this->applied_repo = $applied_repo;
    }

    /**
     * Detect object cache availability once at plugin init.
     * Called by Plugin::init_cache().
     */
    public static function init(): void
    {
        self::$has_ext_cache  = (bool) wp_using_ext_object_cache();
        self::$has_db_fallback = !self::$has_ext_cache;
    }

    /**
     * Filter the_content to inject pre-computed links.
     *
     * @param string $content The original post content.
     * @return string Content with links applied, or original content on miss.
     */
    public function filter(string $content): string
    {
        // Guard: no processing in admin screens.
        if (is_admin()) {
            return $content;
        }

        // Guard: no links in RSS feeds.
        if (is_feed()) {
            return $content;
        }

        // Guard: must have a valid post ID.
        $post_id = get_the_ID();
        if (!$post_id) {
            return $content;
        }

        // -----------------------------------------------------------------
        // Attempt 1: Object cache (always tried, even without ext cache).
        //            With Redis/Memcached this is sub-millisecond.
        //            Without ext cache, wp_cache_get uses per-request memory
        //            and will return false (nothing persisted across requests).
        // -----------------------------------------------------------------
        $cached = wp_cache_get("lw_processed:{$post_id}", self::CACHE_GROUP);

        if ($cached !== false) {
            // Validate the rules version to detect stale cache entries.
            $meta            = wp_cache_get("lw_meta:{$post_id}", self::CACHE_GROUP);
            $current_version = RulesCache::get_version();

            if ($meta && isset($meta['rules_version']) && (int) $meta['rules_version'] === $current_version) {
                // Sanity check from ADR-001 Risk 4: if processed content is
                // drastically smaller than original, it is likely corrupted.
                if (strlen($cached) >= strlen($content) * 0.5) {
                    self::increment_stat('hits');
                    return $cached;
                }
            }

            // Version mismatch or sanity check failed: treat as miss.
            // The stale entry will expire via its TTL naturally.
        }

        // -----------------------------------------------------------------
        // Attempt 2: DB fallback (only when no external object cache).
        //            This adds exactly ONE query per page load.
        //            Per ADR-002, this is acceptable only without ext cache.
        // -----------------------------------------------------------------
        if (self::$has_db_fallback) {
            $db_content = $this->applied_repo->get_processed_content($post_id);
            if ($db_content !== null) {
                // Re-populate the per-request object cache so subsequent
                // calls within the same request are free.
                wp_cache_set("lw_processed:{$post_id}", $db_content, self::CACHE_GROUP, 3600);
                self::increment_stat('hits');
                return $db_content;
            }
        }

        // -----------------------------------------------------------------
        // Attempt 3: Serve original content (always safe, always fast).
        //            Enqueue the post for background re-caching.
        // -----------------------------------------------------------------
        self::increment_stat('misses');

        // Fire-and-forget: schedule background processing only if Action
        // Scheduler is available and the post is not already scheduled.
        if (function_exists('as_enqueue_async_action')) {
            if (!as_next_scheduled_action('leanautolinks_recache_post', ['post_id' => $post_id], 'leanautolinks')) {
                as_enqueue_async_action(
                    'leanautolinks_recache_post',
                    ['post_id' => $post_id],
                    'leanautolinks'
                );
            }
        }

        return $content;
    }

    /**
     * Atomically increment a cache hit/miss counter.
     */
    private static function increment_stat(string $type): void
    {
        $result = wp_cache_incr("lw_stats:{$type}", 1, self::CACHE_GROUP);

        // Initialise the counter if it does not exist yet.
        if ($result === false) {
            wp_cache_set("lw_stats:{$type}", 1, self::CACHE_GROUP, 86400);
        }
    }
}
