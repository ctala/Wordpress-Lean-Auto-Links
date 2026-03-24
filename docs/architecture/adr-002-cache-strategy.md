# ADR-002: Cache Architecture

**Status:** Accepted
**Date:** 2026-03-24
**Decision Maker:** Estratega (Strategy Lead)
**Depends On:** ADR-001 (Timing Strategy: Hybrid Async Processing with Cache Serving)

---

## 1. Decision

LeanWeave uses a three-layer cache architecture: persistent object cache (Redis/Memcached) as the primary layer, a database fallback layer (`lw_applied_links.processed_content`), and per-request in-memory cache for rules. Cache is partitioned by rule type to enable differentiated TTL and invalidation strategies. The frontend never falls back to computing links at request time; if cache is unavailable, original content is served.

---

## 2. Cache Layers

### Layer 1: Persistent Object Cache (Primary)

**Technology:** Redis or Memcached via WordPress object cache API (`wp_cache_get`/`wp_cache_set`)

**What is cached:**
| Data | Cache Key | Serialization | Size Estimate |
|------|-----------|---------------|---------------|
| Processed content per post | `lw_processed:{post_id}` | Raw HTML string | 5-20 KB per post |
| Active rules (all types) | `lw_rules_active` | Serialized PHP array | 200-500 KB for 1,000 rules |
| Rules by type | `lw_rules:{rule_type}` | Serialized PHP array | 50-250 KB per type |
| Rule keyword index | `lw_rule_index` | Serialized hash map | 100-300 KB |
| Cache metadata | `lw_meta:{post_id}` | Serialized array | 100 bytes per post |
| Hit/miss counters | `lw_stats:hits`, `lw_stats:misses` | Integer | 8 bytes each |

**Memory footprint estimate for 15,000 posts:**
- Processed content: 15,000 * 10 KB average = ~150 MB
- Rules and indexes: ~1 MB
- Metadata: 15,000 * 100 bytes = ~1.5 MB
- Total: ~153 MB in Redis/Memcached

This is within normal operating parameters for a production Redis instance (typically allocated 256 MB to 1 GB). For constrained environments, TTLs ensure natural eviction.

### Layer 2: Database Fallback

**Technology:** `lw_applied_links` table with `processed_content` column

**Purpose:** Serve as a durable store when object cache is unavailable or has been flushed. This layer is populated during background processing alongside the object cache write.

**Schema addition to lw_applied_links:**
```sql
ALTER TABLE {prefix}lw_applied_links
    ADD COLUMN processed_content LONGTEXT NULL AFTER target_url,
    ADD COLUMN content_hash CHAR(32) NULL AFTER processed_content,
    ADD COLUMN processed_at DATETIME NULL AFTER content_hash;
```

Note: The `processed_content` is stored as a single row per post (not per link). This is achieved via a separate summary row with `rule_id = 0` serving as the "processed content" record, while individual rows track per-link details.

**Frontend access pattern:**
- Only used when object cache returns a miss AND the site has no persistent object cache backend.
- Detected once at plugin init via `wp_using_ext_object_cache()`.
- When true: frontend never queries this table (object cache handles everything).
- When false: frontend performs ONE query per page load to fetch processed content.

### Layer 3: Per-Request In-Memory Cache

**Technology:** Static PHP variables / singleton pattern

**Purpose:** Prevent redundant object cache lookups within a single request. Relevant primarily for:
- Rules loading: fetched once per request, reused if multiple posts are processed (e.g., archive pages)
- Configuration values: loaded once, stored in static property

**Implementation:**
```php
class RulesCache {
    private static ?array $rules = null;

    public static function get_active_rules(): array {
        if (self::$rules !== null) {
            return self::$rules;
        }

        self::$rules = wp_cache_get('lw_rules_active', 'leanweave');
        if (self::$rules === false) {
            self::$rules = RulesRepository::fetch_all_active();
            wp_cache_set('lw_rules_active', self::$rules, 'leanweave', 3600);
        }

        return self::$rules;
    }

    public static function flush(): void {
        self::$rules = null;
        wp_cache_delete('lw_rules_active', 'leanweave');
    }
}
```

---

## 3. Cache Key Naming Convention

### Format

```
lw_{entity}:{identifier}[:{qualifier}]
```

### Complete Key Registry

| Key Pattern | Example | Description | TTL |
|-------------|---------|-------------|-----|
| `lw_processed:{post_id}` | `lw_processed:4521` | Processed HTML content for post | See per-type TTL |
| `lw_meta:{post_id}` | `lw_meta:4521` | Processing metadata (timestamp, rule versions, hash) | Same as processed |
| `lw_rules_active` | `lw_rules_active` | All active rules, compiled | 3600s (1 hour) |
| `lw_rules:{rule_type}` | `lw_rules:glossary` | Active rules filtered by type | 3600s (1 hour) |
| `lw_rule_index` | `lw_rule_index` | Keyword-to-rule-id hash map | 3600s (1 hour) |
| `lw_stats:hits` | `lw_stats:hits` | Cache hit counter (atomic increment) | 86400s (24 hours) |
| `lw_stats:misses` | `lw_stats:misses` | Cache miss counter | 86400s (24 hours) |
| `lw_version:rules` | `lw_version:rules` | Rule set version counter (incremented on any rule CRUD) | 0 (no expiry) |
| `lw_lock:batch:{batch_id}` | `lw_lock:batch:42` | Processing lock to prevent duplicate batch execution | 600s (10 minutes) |

### WordPress Cache Group

All keys use the `leanweave` cache group:
```php
wp_cache_get('lw_processed:4521', 'leanweave');
wp_cache_set('lw_processed:4521', $content, 'leanweave', $ttl);
```

Using a dedicated group enables bulk flush:
```php
// If using Redis with group support:
wp_cache_flush_group('leanweave');
```

For object cache backends that do not support `flush_group`, individual key deletion is used during invalidation events.

---

## 4. Per-Rule-Type Partitioning

### Why Partition

Different rule types have fundamentally different change frequencies and business requirements:

| Rule Type | Change Frequency | Business Requirement | Consequence |
|-----------|-----------------|---------------------|-------------|
| Glossary (`internal`) | Low (terms added weekly) | Links should be accurate, slight staleness OK | Longer TTL, less frequent invalidation |
| Entity/Actor (`entity`) | Low (entities added weekly) | Same as glossary | Longer TTL |
| Affiliate (`affiliate`) | Medium (campaigns change, URLs rotate) | Links must reflect current offers, stale = lost revenue | Shorter TTL, more aggressive refresh |

### TTL Strategy

| Rule Type | Object Cache TTL | Rationale |
|-----------|-----------------|-----------|
| `internal` (glossary) | 86400s (24 hours) | Terms rarely change; daily refresh sufficient |
| `entity` (actors, companies) | 86400s (24 hours) | Entity data is stable; daily refresh sufficient |
| `affiliate` | 43200s (12 hours) | Campaigns and URLs may rotate; 12-hour window balances freshness and cache efficiency |

### Implementation

When the background job processes a post, it determines the effective TTL based on the rule types that contributed links:

```php
private function determine_ttl(array $applied_rules): int {
    $has_affiliate = false;
    foreach ($applied_rules as $rule) {
        if ($rule->rule_type === 'affiliate') {
            $has_affiliate = true;
            break;
        }
    }

    // If any affiliate link was applied, use the shorter TTL
    // This ensures affiliate URLs are refreshed more frequently
    return $has_affiliate ? 43200 : 86400;
}
```

The rule type of each applied link is stored in `lw_applied_links.rule_type` (derived from the rule), enabling targeted invalidation when a specific rule type changes.

---

## 5. Invalidation Matrix

### Event-to-Invalidation Mapping

| Event | Keys Invalidated | Reprocessing Triggered |
|-------|-----------------|----------------------|
| **Post saved (new)** | None (no cached content yet) | Yes: enqueue post to `lw_queue` |
| **Post updated** | `lw_processed:{post_id}`, `lw_meta:{post_id}` | Yes: enqueue post to `lw_queue` |
| **Post deleted** | `lw_processed:{post_id}`, `lw_meta:{post_id}` | No: delete from `lw_applied_links` |
| **Rule created** | `lw_rules_active`, `lw_rules:{type}`, `lw_rule_index`, `lw_version:rules` | Yes: enqueue affected posts (keyword match) |
| **Rule updated** | `lw_rules_active`, `lw_rules:{type}`, `lw_rule_index`, `lw_version:rules`, `lw_processed:{previously_linked_posts}` | Yes: enqueue affected posts (old + new keyword match) |
| **Rule deleted** | `lw_rules_active`, `lw_rules:{type}`, `lw_rule_index`, `lw_version:rules`, `lw_processed:{previously_linked_posts}` | Yes: enqueue previously linked posts |
| **Rule toggled (active/inactive)** | Same as rule updated | Yes: same as rule updated |
| **Bulk reprocess triggered** | All `lw_processed:*` and `lw_meta:*` (staggered, not instant) | Yes: all published posts enqueued |
| **Plugin settings changed** | `lw_rules_active`, all `lw_rules:{type}`, `lw_rule_index` | Depends on setting (e.g., max_links_per_post change triggers bulk) |
| **Object cache flushed externally** | All keys lost | Cache warming strategy activates (see section 6) |

### Version-Based Invalidation

To detect stale cache entries without iterating all keys, LeanWeave uses a version counter:

```php
// On any rule CRUD:
$version = wp_cache_incr('lw_version:rules', 1, 'leanweave');
if ($version === false) {
    wp_cache_set('lw_version:rules', 1, 'leanweave', 0);
}

// When storing processed content, include the version:
$meta = [
    'rules_version' => wp_cache_get('lw_version:rules', 'leanweave'),
    'processed_at'  => time(),
    'content_hash'  => md5($original_content),
];
wp_cache_set("lw_meta:{$post_id}", $meta, 'leanweave', $ttl);

// On the_content filter, verify version:
$meta = wp_cache_get("lw_meta:{$post_id}", 'leanweave');
$current_version = wp_cache_get('lw_version:rules', 'leanweave');

if ($meta && $meta['rules_version'] === $current_version) {
    // Cache is valid, serve processed content
    return wp_cache_get("lw_processed:{$post_id}", 'leanweave');
}

// Cache is stale (rules changed since processing), serve original
// Enqueue for re-processing in background
return $original_content;
```

This mechanism means that when a rule changes and the version counter increments, all previously cached processed content becomes stale immediately without needing to delete individual keys. The stale entries naturally expire via their TTL.

### Content Hash Validation

To detect when post content has changed outside of LeanWeave (e.g., direct database edit, import plugin):

```php
$meta = wp_cache_get("lw_meta:{$post_id}", 'leanweave');
$current_hash = md5($post->post_content);

if ($meta && $meta['content_hash'] !== $current_hash) {
    // Post content changed since last processing, cache is invalid
    wp_cache_delete("lw_processed:{$post_id}", 'leanweave');
    wp_cache_delete("lw_meta:{$post_id}", 'leanweave');
    // Enqueue for re-processing
}
```

Note: The `md5()` call on post content adds negligible overhead on the frontend (microseconds for typical post sizes). This is a single hash comparison, not a DB query.

---

## 6. Cache Warming Strategy

### When Warming Is Needed

1. **After full cache flush** (e.g., Redis restart, `wp cache flush`)
2. **After plugin activation** (no cached content exists yet)
3. **After bulk reprocess completes** (cache is populated as a natural side effect)

### Warming Priority

Not all 15,000 posts need to be cached immediately. Warming is prioritized by traffic:

**Tier 1: Immediate warming (within 5 minutes)**
- Posts visited in the last 24 hours (tracked via lightweight page view counter)
- Homepage and primary landing pages
- Posts linked from external sources (referrer data if available)

**Tier 2: Standard warming (within 1 hour)**
- Posts published in the last 30 days
- Posts with high link counts in `lw_applied_links`

**Tier 3: Passive warming (on-demand)**
- Older posts are warmed when they are requested by a visitor
- Background re-caching is triggered on cache miss (see ADR-001 section 5.3)

### Implementation

```php
class CacheWarmer {
    public static function warm_after_flush(): void {
        // Tier 1: Recent popular posts
        $popular_ids = self::get_recently_visited_posts(100);
        if (!empty($popular_ids)) {
            as_enqueue_async_action(
                'leanweave_warm_cache',
                ['post_ids' => $popular_ids, 'tier' => 1],
                'leanweave'
            );
        }

        // Tier 2: Recent posts (staggered)
        $recent_ids = get_posts([
            'posts_per_page' => 500,
            'post_status'    => 'publish',
            'fields'         => 'ids',
            'orderby'        => 'date',
            'order'          => 'DESC',
            'date_query'     => [['after' => '30 days ago']],
        ]);

        $batches = array_chunk($recent_ids, 100);
        foreach ($batches as $i => $batch) {
            as_schedule_single_action(
                time() + (300 + $i * 60), // Start after 5 min, stagger by 1 min
                'leanweave_warm_cache',
                ['post_ids' => $batch, 'tier' => 2],
                'leanweave'
            );
        }
    }

    private static function get_recently_visited_posts(int $limit): array {
        // Uses a lightweight tracking mechanism:
        // On each cache hit in the_content filter, a sorted set in Redis
        // or a small DB table tracks post_id + last_accessed timestamp
        global $wpdb;
        $table = $wpdb->prefix . 'lw_performance_log';

        return $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT post_id FROM {$table}
             WHERE event_type = 'cache_hit'
             AND logged_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
             ORDER BY logged_at DESC
             LIMIT %d",
            $limit
        ));
    }
}
```

### Warming Triggers

| Trigger | Action |
|---------|--------|
| Plugin activated | `CacheWarmer::warm_after_flush()` |
| Object cache flush detected | `CacheWarmer::warm_after_flush()` via `wp_cache_flush` action |
| Bulk reprocess completed | No explicit warming needed (cache populated during processing) |
| Daily maintenance (WP Cron) | Check for posts with expired cache, re-queue top 500 by traffic |

### Detection of Cache Flush

LeanWeave detects external cache flushes by maintaining a sentinel key:

```php
// Set sentinel on plugin init:
if (wp_cache_get('lw_sentinel', 'leanweave') === false) {
    // Cache was flushed (sentinel missing)
    wp_cache_set('lw_sentinel', time(), 'leanweave', 0);

    // Only trigger warming if plugin was previously active
    // (prevents warming on first activation before any processing)
    if (get_option('leanweave_last_processed_at')) {
        CacheWarmer::warm_after_flush();
    }
}
```

---

## 7. Degradation Behavior

### Scenario Matrix

| Scenario | Object Cache | DB Fallback | Behavior | Frontend Queries | TTFB Impact |
|----------|-------------|-------------|----------|-----------------|-------------|
| Normal operation (Redis/MC available) | Available | Available | Serve from object cache | 0 | < 1ms |
| Object cache miss (TTL expired) | Available but empty | Available | Serve original content, enqueue re-cache | 0 | 0ms |
| Object cache down (Redis crash) | Unavailable | Available | Fall back to DB read | 1 | +2-5ms |
| Object cache down + DB fallback empty | Unavailable | No data | Serve original content | 0 | 0ms |
| Full outage (cache + DB issues) | Unavailable | Unavailable | Serve original content | 0 | 0ms |

### Degradation Hierarchy

```
Priority 1: Serve from object cache (0 queries, < 1ms)
    |
    | (miss or unavailable)
    v
Priority 2: Serve from DB fallback (1 query, 2-5ms)
    |         Only if wp_using_ext_object_cache() === false
    |
    | (miss or unavailable)
    v
Priority 3: Serve original content without links (0 queries, 0ms)
    |         Always safe, always fast
    v
NEVER: Compute links at request time
```

### Implementation of Graceful Degradation

```php
class ContentFilterHandler {
    private static bool $has_ext_cache;
    private static bool $has_db_fallback;

    public static function init(): void {
        self::$has_ext_cache = wp_using_ext_object_cache();
        self::$has_db_fallback = !self::$has_ext_cache;
    }

    public static function filter(string $content): string {
        if (is_admin() || is_feed()) {
            return $content;
        }

        $post_id = get_the_ID();
        if (!$post_id) {
            return $content;
        }

        // Attempt 1: Object cache (always tried, even without ext cache)
        $cached = wp_cache_get("lw_processed:{$post_id}", 'leanweave');
        if ($cached !== false) {
            // Validate version
            $meta = wp_cache_get("lw_meta:{$post_id}", 'leanweave');
            $current_version = wp_cache_get('lw_version:rules', 'leanweave');
            if ($meta && $meta['rules_version'] === $current_version) {
                // Sanity check: processed content should not be drastically smaller
                if (strlen($cached) >= strlen($content) * 0.5) {
                    self::increment_stat('hits');
                    return $cached;
                }
            }
        }

        // Attempt 2: DB fallback (only when no external object cache)
        if (self::$has_db_fallback) {
            $db_content = AppliedLinksRepository::get_processed_content($post_id);
            if ($db_content !== null) {
                // Re-populate object cache for this request
                wp_cache_set("lw_processed:{$post_id}", $db_content, 'leanweave', 3600);
                self::increment_stat('hits');
                return $db_content;
            }
        }

        // Attempt 3: Serve original content (always safe)
        self::increment_stat('misses');

        // Enqueue for background processing (fire-and-forget, non-blocking)
        if (!wp_next_scheduled('leanweave_process_single', ['post_id' => $post_id])) {
            as_enqueue_async_action(
                'leanweave_process_single',
                ['post_id' => $post_id],
                'leanweave'
            );
        }

        return $content;
    }

    private static function increment_stat(string $type): void {
        wp_cache_incr("lw_stats:{$type}", 1, 'leanweave');
    }
}
```

### Health Indicators

The `/health` endpoint reports cache status:

```json
{
    "cache": {
        "backend": "redis",
        "available": true,
        "hit_rate": 0.94,
        "hits_24h": 45230,
        "misses_24h": 2870,
        "estimated_memory_mb": 148.5,
        "rules_version": 42,
        "sentinel_age_seconds": 86400
    },
    "fallback": {
        "active": false,
        "posts_with_db_content": 15000
    },
    "queue": {
        "pending": 12,
        "processing": 3,
        "failed": 0,
        "estimated_drain_seconds": 45
    }
}
```

### Admin Notices

| Condition | Notice Level | Message |
|-----------|-------------|---------|
| No external object cache detected | Warning | "LeanWeave works best with Redis or Memcached. Without a persistent object cache, link serving requires one database query per page load." |
| Cache hit rate < 80% | Warning | "LeanWeave cache hit rate is {rate}%. This may indicate insufficient object cache memory or aggressive eviction." |
| Object cache unavailable after being available | Error | "LeanWeave detected that your object cache is no longer responding. Links will be served from the database fallback or original content." |
| Queue depth > 5,000 | Warning | "LeanWeave has {count} posts pending processing. This is expected after a bulk rule change and will resolve automatically." |

---

## 8. Cache Operations Reference

### Manual Cache Management (WP-CLI)

```bash
# Flush all LeanWeave caches
wp leanweave cache flush

# Flush processed content only (keeps rules cache)
wp leanweave cache flush --type=content

# Flush rules cache only (triggers recompilation on next access)
wp leanweave cache flush --type=rules

# Warm cache for top 500 posts by recent traffic
wp leanweave cache warm --count=500

# Show cache statistics
wp leanweave cache stats

# Check cache status for a specific post
wp leanweave cache status --post_id=4521
```

### REST API Cache Operations

```
POST /wp-json/leanweave/v1/cache/flush          -- Flush all caches
POST /wp-json/leanweave/v1/cache/flush/content   -- Flush content cache only
POST /wp-json/leanweave/v1/cache/flush/rules     -- Flush rules cache only
POST /wp-json/leanweave/v1/cache/warm            -- Trigger cache warming
GET  /wp-json/leanweave/v1/cache/stats           -- Cache statistics
GET  /wp-json/leanweave/v1/cache/status/{post_id} -- Per-post cache status
```

---

## 9. Consequences

### What This Cache Architecture Means

**Memory requirements:**
- Redis/Memcached should have at least 256 MB allocated to comfortably hold 15,000 processed posts plus rules.
- At 50,000 posts (12-month projection), approximately 500 MB is needed. This should be factored into hosting recommendations.

**Operational overhead:**
- Cache warming adds background processing load after flushes. This is bounded (at most 500 posts in Tier 2 warming) and staggered.
- The version-based invalidation avoids the need for expensive "flush all keys matching a pattern" operations.

**Monitoring requirements:**
- Cache hit rate must be tracked and surfaced in the admin dashboard.
- Memory usage in the object cache backend should be monitored to prevent eviction of LeanWeave keys.

**Testing requirements:**
- All three degradation paths (object cache hit, DB fallback, original content) must be tested.
- Cache invalidation must be verified for every event in the invalidation matrix.
- Version-based staleness detection must be tested with concurrent rule changes and content serving.
- Memory footprint of the full cache must be measured at scale (15,000 posts) in the Docker environment.

**Hosting recommendations for end users:**
- Minimum: WordPress with default configuration (plugin works but with degraded performance).
- Recommended: Redis or Memcached with at least 256 MB allocated to the object cache.
- Optimal: Redis with persistent storage enabled (survives restarts without needing full re-warm).

---

**This cache architecture is designed to uphold the absolute guarantee: LeanWeave never makes a site load slower. When in doubt, serve original content.**
