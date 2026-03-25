<?php
declare(strict_types=1);

namespace LeanAutoLinks\Cache;

if (!defined('ABSPATH')) {
    exit;
}

use LeanAutoLinks\Repositories\RulesRepository;

/**
 * Three-layer cache for linking rules (ADR-002, Layer 3).
 *
 * Layer 1: Per-request in-memory static cache (avoids redundant lookups
 *          within a single PHP request, e.g. archive pages with multiple posts).
 * Layer 2: WordPress object cache (Redis/Memcached when available).
 * Layer 3: Database via RulesRepository (always available).
 *
 * Cache group: 'leanautolinks'
 * TTL: 3600 seconds (1 hour) for rule sets.
 * Invalidation: via flush() on any rule CRUD + version counter.
 */
final class RulesCache
{
    private const CACHE_GROUP = 'leanautolinks';
    private const RULES_TTL   = 3600; // 1 hour

    /** @var array<object>|null In-memory cache for all active rules. */
    private static ?array $rules = null;

    /** @var array<string, array<object>> In-memory cache per rule type. */
    private static array $rules_by_type = [];

    /**
     * Get all active rules with three-layer fallback.
     *
     * @return array<object>
     */
    public static function get_active_rules(): array
    {
        // Layer 1: In-memory (per-request).
        if (self::$rules !== null) {
            return self::$rules;
        }

        // Layer 2: Object cache.
        $cached = wp_cache_get('lw_rules_active', self::CACHE_GROUP);
        if (is_array($cached)) {
            self::$rules = $cached;
            return self::$rules;
        }

        // Layer 3: Database.
        $repo = new RulesRepository();
        self::$rules = $repo->fetch_all_active();

        // Populate object cache for subsequent requests.
        wp_cache_set('lw_rules_active', self::$rules, self::CACHE_GROUP, self::RULES_TTL);

        return self::$rules;
    }

    /**
     * Get active rules filtered by type with three-layer fallback.
     *
     * @param string $type One of 'internal', 'affiliate', 'entity'.
     * @return array<object>
     */
    public static function get_rules_by_type(string $type): array
    {
        // Layer 1: In-memory.
        if (isset(self::$rules_by_type[$type])) {
            return self::$rules_by_type[$type];
        }

        // Layer 2: Object cache.
        $cache_key = "lw_rules:{$type}";
        $cached = wp_cache_get($cache_key, self::CACHE_GROUP);
        if (is_array($cached)) {
            self::$rules_by_type[$type] = $cached;
            return self::$rules_by_type[$type];
        }

        // Layer 3: Database.
        $repo = new RulesRepository();
        self::$rules_by_type[$type] = $repo->fetch_by_type($type);

        // Populate object cache.
        wp_cache_set($cache_key, self::$rules_by_type[$type], self::CACHE_GROUP, self::RULES_TTL);

        return self::$rules_by_type[$type];
    }

    /**
     * Flush all rule caches (in-memory, object cache) and increment
     * the version counter so processed content caches are invalidated.
     */
    public static function flush(): void
    {
        // Clear in-memory caches.
        self::$rules         = null;
        self::$rules_by_type = [];

        // Clear object cache keys.
        wp_cache_delete('lw_rules_active', self::CACHE_GROUP);
        wp_cache_delete('lw_rules:internal', self::CACHE_GROUP);
        wp_cache_delete('lw_rules:affiliate', self::CACHE_GROUP);
        wp_cache_delete('lw_rules:entity', self::CACHE_GROUP);
        wp_cache_delete('lw_rule_index', self::CACHE_GROUP);

        // Increment version so all processed content caches become stale.
        self::increment_version();
    }

    /**
     * Get the current rules version counter.
     *
     * Used by ContentFilterHandler to detect stale processed content.
     */
    public static function get_version(): int
    {
        $version = wp_cache_get('lw_version:rules', self::CACHE_GROUP);

        if ($version === false) {
            // Initialise the version counter if it does not exist.
            wp_cache_set('lw_version:rules', 1, self::CACHE_GROUP, 0);
            return 1;
        }

        return (int) $version;
    }

    /**
     * Increment the rules version counter atomically.
     *
     * Returns the new version number.
     */
    public static function increment_version(): int
    {
        $new_version = wp_cache_incr('lw_version:rules', 1, self::CACHE_GROUP);

        if ($new_version === false) {
            // Counter did not exist; initialise it.
            wp_cache_set('lw_version:rules', 1, self::CACHE_GROUP, 0);
            return 1;
        }

        return (int) $new_version;
    }
}
