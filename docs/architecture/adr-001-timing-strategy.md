# ADR-001: Link Insertion Timing Strategy

**Status:** Accepted
**Date:** 2026-03-24
**Decision Maker:** Estratega (Strategy Lead)
**Input From:** Research Agent (state-of-the-art report), Performance Agent (benchmark specification)

---

## 1. Decision

**LeanWeave adopts Option C: Hybrid Async Processing with Cache Serving** as its link insertion timing strategy.

Links are pre-computed in background jobs triggered by `save_post` and rule changes, stored in a dedicated results table (`lw_applied_links`) and served through a lightweight `the_content` filter that reads only from pre-computed storage. When no pre-computed result exists, the original content is served unmodified. The frontend never executes rule matching, content parsing, or database lookups against the rules table.

---

## 2. Context

### Scale and Growth
- 15,000+ published posts today, growing at approximately 700 posts per week (100 per day)
- 1,000+ active linking rules across three types: glossary (500+), entities/actors (500+), and affiliates
- Projection: 50,000 posts within 12 months

### Non-Negotiable Constraints
These constraints come directly from the project charter and are absolute:

| Constraint | Threshold | Priority |
|------------|-----------|----------|
| Additional frontend DB queries | Exactly 0 | CRITICAL |
| TTFB delta (plugin active vs inactive) | < 5ms | CRITICAL |
| `save_post` overhead | < 50ms | CRITICAL |
| Bulk reprocess 15,000 posts | < 4 hours | HIGH |
| Sustained throughput | > 70 posts/hour | HIGH |
| Engine with 1,000 rules per post | < 500ms (p95) | CRITICAL |
| Memory per job execution | < 32MB peak | HIGH |

### Research Evidence
The state-of-the-art report (2026-03-24) analyzed 8 plugins across the WordPress ecosystem. Key findings relevant to this decision:

1. **No existing plugin achieves true zero frontend impact with 1,000+ automated rules.** This is LeanWeave's primary differentiation opportunity.
2. **Internal Link Juicer (90K installs)** uses render-time dynamic linking. While it claims "zero impact," it still processes at render time, executing CPU work on every page load.
3. **Link Whisper** has documented performance regressions at scale.
4. **Internal Links Manager v3.0+** introduced multi-backend caching but still uses synchronous content modification.
5. The research agent explicitly recommends Option C with detailed rationale.

---

## 3. Options Considered

### Option A: On-Save Async (Pure Background Processing)

```
save_post -> enqueue job -> Action Scheduler processes in background -> modify post_content in DB
```

**Pros:**
- Zero frontend impact: links are already embedded in `post_content`
- Zero render-time cost: WordPress serves content as-is
- Works perfectly with full-page caching (Varnish, WP Super Cache, etc.)
- Simplest mental model

**Cons:**
- Links are not visible immediately after publish (latency depends on queue depth)
- When a rule is created, updated, or deleted, ALL posts potentially affected must be reprocessed
- Modifying `post_content` directly is destructive and makes rollback difficult
- If the engine has a bug, corrupted content is persisted permanently
- Revisions table bloats with each reprocessing cycle (15,000 revisions per bulk run)

**Why rejected:** Option A requires the same bulk reprocessing queue as Option C when rules change, making it effectively Option C but with the added risk of destructive content modification. Storing links separately preserves content integrity.

### Option B: Render-Time Dynamic Linking

```
the_content filter -> load rules from cache/index -> match keywords -> inject links -> serve
```

**Pros:**
- Links always reflect current rule state (zero staleness)
- No content modification (original `post_content` untouched)
- No background processing infrastructure needed
- Simplest implementation

**Cons:**
- Processing executes on EVERY page load, adding CPU cost that scales with rule count
- Must load rules on frontend (even from object cache, this adds at least one lookup)
- With 1,000 rules and 2,000-word content, processing adds 5-50ms per page load
- Breaks the "0 additional frontend queries" requirement
- Does not work well when object cache is unavailable (falls back to DB queries)
- Under traffic spikes, every concurrent request runs the matching engine

**Why rejected:** This option fundamentally violates the "0 additional frontend DB queries" constraint. Even with aggressive caching, the `the_content` filter must perform rule loading and content processing on every request. Internal Link Juicer uses this pattern and claims zero impact, but it still consumes CPU at render time. LeanWeave can be genuinely zero-cost by pre-computing.

### Option C: Hybrid Async Processing with Cache Serving (SELECTED)

```
save_post -> enqueue post_id -> Action Scheduler processes in background -> store pre-computed result
the_content filter -> check for pre-computed result -> apply if exists, serve original if not
```

**Pros:**
- Achieves true 0 additional frontend DB queries when served from object cache
- TTFB delta near 0ms (serving a cached string replacement is negligible)
- Content integrity preserved (original `post_content` never modified)
- Handles rule changes via bulk reprocessing queue
- Cache partitioned by rule type enables different TTL and invalidation strategies
- Graceful degradation: if no pre-computed result exists, original content serves without links (safe fallback)

**Cons:**
- Most complex implementation of the three options
- Cache invalidation requires careful design (see ADR-002)
- First visit after cache clear serves content without links (acceptable trade-off)
- Requires Action Scheduler as a dependency

---

## 4. Decision Rationale

Option C wins because it is the only option that satisfies ALL non-negotiable constraints simultaneously:

| Constraint | Option A | Option B | Option C |
|------------|----------|----------|----------|
| 0 frontend DB queries | Yes (content in DB) | NO (loads rules) | Yes (serves from cache) |
| TTFB < 5ms | Yes | NO (5-50ms processing) | Yes (near 0ms) |
| save_post < 50ms | Yes (enqueue only) | N/A | Yes (enqueue only) |
| Content integrity | NO (modifies post_content) | Yes | Yes |
| Links always current | NO (stale until reprocessed) | Yes | Near-current (stale until reprocessed) |
| Handles rule changes | Requires bulk reprocess | Automatic | Requires bulk reprocess |
| Works without object cache | Yes | Degrades | Degrades gracefully |

The staleness trade-off (links not appearing until background processing completes) is acceptable because:
1. New posts are prioritized in the queue and typically processed within seconds
2. Rule changes trigger targeted reprocessing, not random delays
3. The alternative (render-time processing) violates the absolute performance constraint
4. Full-page caching on production sites already introduces content staleness of minutes to hours

---

## 5. Implementation Flow

### 5.1 What Happens on `save_post`

```
save_post hook fires
    |
    v
LeanWeave\Hooks\SavePostHandler::handle($post_id, $post, $update)
    |
    +-- Guard: is post type supported? (post, page, configured CPTs)
    +-- Guard: is post status 'publish'?
    +-- Guard: is post excluded via lw_exclusions?
    |
    v
Insert/update row in {prefix}lw_queue:
    post_id     = $post_id
    status      = 'pending'
    triggered_by = 'save_post'
    priority    = 10 (new posts get priority 10, bulk reprocess gets priority 50)
    scheduled_at = NOW()
    |
    v
Schedule Action Scheduler action:
    as_enqueue_async_action('leanweave_process_single', ['post_id' => $post_id], 'leanweave')
    |
    v
Return (total overhead target: < 5ms, well within 50ms budget)
```

**Critical constraint:** The `save_post` handler performs exactly ONE insert/upsert query on `lw_queue` and ONE Action Scheduler enqueue call. No content processing, no rule loading, no matching.

### 5.2 What Happens in the Background Job

```
Action Scheduler triggers leanweave_process_single or leanweave_process_batch
    |
    v
LeanWeave\Jobs\LinkProcessorJob::process($post_id)
    |
    +-- Load post content: get_post($post_id)->post_content
    +-- Load active rules from RulesCache (object cache or DB with in-memory cache)
    |
    v
LeanWeave\Engine\RuleMatcherEngine::process($content, $rules)
    |
    +-- Parse content into safe/unsafe zones (skip <a>, <h1>-<h6>, <code>, <pre>, <script>)
    +-- For each rule (sorted by priority):
    |     +-- Find keyword matches in safe zones (case-sensitive or insensitive per rule)
    |     +-- Apply max_per_post limit
    |     +-- Build link HTML via LinkBuilder (handles rel="sponsored nofollow" for affiliates)
    |     +-- Replace keyword with link HTML in content
    +-- Return processed content + array of applied links
    |
    v
Store results:
    1. Delete existing rows from lw_applied_links WHERE post_id = $post_id
    2. Insert new rows into lw_applied_links for each link applied
    3. Store processed content in object cache:
       Key: "lw_processed:{$post_id}"
       Value: processed HTML string
       TTL: rule_type dependent (see Cache Strategy)
    4. Update lw_queue: status = 'done', processed_at = NOW()
    |
    v
Log performance:
    Insert into lw_performance_log:
        event_type    = 'process_post'
        post_id       = $post_id
        duration_ms   = measured execution time
        memory_kb     = peak memory delta
        rules_checked = count of rules evaluated
        links_applied = count of links inserted
```

**Batch processing:** For `leanweave_process_batch`, the job iterates over a batch of post IDs (default 100), calling `process()` for each. Memory is monitored per batch, and if peak exceeds 28MB (safety margin below the 32MB limit), the batch is split and remaining posts re-queued.

### 5.3 What Happens on Page Load (`the_content` Filter)

```
the_content filter fires (priority 999 to run after other content filters)
    |
    v
LeanWeave\Hooks\ContentFilterHandler::filter($content)
    |
    +-- Get current post ID: get_the_ID()
    +-- Guard: is_admin()? Return original content (no processing in admin)
    +-- Guard: is_feed()? Return original content (no links in RSS)
    |
    v
Attempt to load pre-computed content from object cache:
    $cached = wp_cache_get("lw_processed:{$post_id}", 'leanweave')
    |
    +-- Cache HIT: return $cached (zero DB queries, near-zero CPU)
    +-- Cache MISS: (see fallback strategy below)
    |
    v
Fallback on cache miss:
    Check if post has rows in lw_applied_links:
        NO  -> return original $content (post was never processed or has no matching rules)
        YES -> This case means cache expired but DB has results.
               Return original $content (do NOT query DB on frontend).
               Instead, enqueue post for re-caching in background:
               as_enqueue_async_action('leanweave_recache_post', ['post_id' => $post_id], 'leanweave')
```

**The critical guarantee:** The `the_content` filter performs at most ONE `wp_cache_get()` call. If object cache is available (Redis/Memcached), this is a sub-millisecond in-memory lookup with zero DB queries. If object cache is not available, `wp_cache_get` returns false (WordPress default in-memory cache does not persist across requests), and the original content is served. There is never a fallback to a database query on the frontend.

### 5.4 What Happens When a Rule is Created, Updated, or Deleted

```
Rule CRUD via REST API or admin UI
    |
    v
LeanWeave\Hooks\RuleChangeHandler::handle($rule_id, $action)
    |
    +-- Invalidate rules cache: wp_cache_delete('lw_rules_active', 'leanweave')
    +-- Invalidate per-type cache: wp_cache_delete("lw_rules_{$rule_type}", 'leanweave')
    |
    v
Determine affected posts:
    |
    +-- Rule CREATED: Find posts containing the rule's keyword
    |     SELECT post_id FROM {prefix}posts
    |     WHERE post_content LIKE '%{keyword}%'
    |     AND post_status = 'publish'
    |     AND post_type IN (supported types)
    |     Limit: process in batches of 1000 post IDs
    |
    +-- Rule UPDATED: Same as created (keyword or target may have changed)
    |     Additionally, invalidate cache for posts previously linked by this rule:
    |     SELECT DISTINCT post_id FROM lw_applied_links WHERE rule_id = $rule_id
    |
    +-- Rule DELETED: Invalidate cache for posts that had this rule applied:
    |     SELECT DISTINCT post_id FROM lw_applied_links WHERE rule_id = $rule_id
    |     Delete rows: DELETE FROM lw_applied_links WHERE rule_id = $rule_id
    |
    v
Enqueue affected posts for reprocessing:
    INSERT INTO lw_queue (post_id, status, triggered_by, priority)
    VALUES ($post_id, 'pending', 'rule_change', 30)
    ON DUPLICATE KEY UPDATE status = 'pending', triggered_by = 'rule_change'
    |
    v
Schedule batch processing:
    as_schedule_single_action(time(), 'leanweave_process_batch', ['triggered_by' => 'rule_change'], 'leanweave')
```

### 5.5 What Happens on Bulk Reprocess

```
Trigger: WP-CLI command, REST API endpoint, or admin UI button
    wp leanweave bulk-reprocess --all
    POST /wp-json/leanweave/v1/queue/bulk
    |
    v
LeanWeave\Jobs\BulkReprocessJob::enqueue($params)
    |
    +-- Count total posts to process
    +-- Split into batches of 100 post IDs
    +-- For each batch, insert rows into lw_queue:
    |     status      = 'pending'
    |     triggered_by = 'bulk_reprocess'
    |     priority    = 50 (lowest priority, yields to new posts and rule changes)
    |
    v
Schedule batches with staggered timing:
    for ($i = 0; $i < $total_batches; $i++) {
        as_schedule_single_action(
            time() + ($i * 10),  // 10-second stagger between batch starts
            'leanweave_process_batch',
            ['batch_offset' => $i * 100, 'batch_size' => 100],
            'leanweave'
        );
    }
    |
    v
Action Scheduler respects concurrency limit:
    - Maximum 3 parallel leanweave jobs at any time
    - Each batch processes 100 posts sequentially
    - Effective throughput: 3 batches * 100 posts = 300 posts in parallel pipeline
    |
    v
Progress tracking:
    - lw_queue table tracks status of every post
    - REST endpoint GET /queue returns progress: pending/processing/done/failed counts
    - WP-CLI: wp leanweave queue-stats
```

---

## 6. Cache Strategy

Detailed cache architecture is documented in ADR-002. Summary of key decisions:

### Storage Layers

| Layer | What | Where | TTL |
|-------|------|-------|-----|
| Processed content | Full HTML with links applied | Object cache (Redis/Memcached) | 24 hours (glossary/entity), 12 hours (affiliate) |
| Active rules | Compiled rule sets per type | Object cache with in-memory fallback | 1 hour |
| Rule index | Keyword-to-rule mapping | Object cache | Invalidated on rule CRUD |

### Cache Key Convention

```
lw_processed:{post_id}           -- Processed content for a post
lw_rules_active                   -- All active rules (compiled)
lw_rules_{rule_type}              -- Rules filtered by type
lw_rule_index:{keyword_hash}      -- Keyword lookup index
```

### Invalidation Triggers

| Event | Cache Keys Invalidated |
|-------|----------------------|
| Post saved | `lw_processed:{post_id}` |
| Rule created/updated/deleted | `lw_rules_active`, `lw_rules_{type}`, `lw_processed:{affected_post_ids}` |
| Bulk reprocess | All `lw_processed:*` keys |
| Plugin settings changed | `lw_rules_active`, all `lw_rules_{type}` |

### Degradation Without Object Cache

When no persistent object cache (Redis/Memcached) is available:
1. `wp_cache_get`/`wp_cache_set` use WordPress default in-memory cache (per-request only)
2. Processed content is NOT available across requests via this path
3. Fallback: store processed content in the `lw_applied_links` table with a `processed_content` column
4. The `the_content` filter checks a lightweight transient flag (autoloaded, so no extra query) to determine if a post has been processed
5. If transient flag is set, load from `lw_applied_links.processed_content` -- this is ONE query on frontend, acceptable only when no object cache is available
6. Sites without object cache are strongly recommended to install one; the plugin admin page surfaces this recommendation

---

## 7. Action Scheduler Configuration

### Concurrency and Batching

| Parameter | Value | Rationale |
|-----------|-------|-----------|
| Max concurrent jobs (leanweave group) | 3 | Prevents overwhelming the server while maintaining throughput |
| Batch size | 100 posts per job | Fits within 32MB memory limit with 1,000 rules |
| Job timeout | 300 seconds (5 minutes) | 100 posts * 500ms worst-case per post = 50s, with 6x safety margin |
| Queue runner interval | 60 seconds | WordPress default Action Scheduler heartbeat |

### Priority System

| Priority Value | Trigger | Rationale |
|----------------|---------|-----------|
| 10 | New post published (`save_post`) | User expects links to appear quickly |
| 20 | Post updated (`save_post` on existing) | Less urgent than new content |
| 30 | Rule change (affected posts) | Batch operation, can wait |
| 50 | Bulk reprocess | Lowest priority, background maintenance |

Priority is implemented via the `scheduled_at` column in `lw_queue`: lower priority values get earlier scheduled times relative to their insertion time. Action Scheduler processes actions in chronological order, so higher-priority items naturally process first.

### Retry Strategy

```
Attempt 1: Immediate execution
Attempt 2: Retry after 60 seconds
Attempt 3: Retry after 300 seconds (5 minutes)
Attempt 4: Retry after 3600 seconds (1 hour)
Attempt 5: Mark as 'failed', log error, stop retrying
```

Implementation:
```php
// In LinkProcessorJob::process()
try {
    $this->execute($post_id);
    $this->queue_repo->mark_done($post_id);
} catch (\Throwable $e) {
    $attempts = $this->queue_repo->increment_attempts($post_id);
    $delays = [60, 300, 3600];

    if ($attempts >= 5) {
        $this->queue_repo->mark_failed($post_id, $e->getMessage());
        $this->performance_repo->log('process_failed', $post_id, [
            'error' => $e->getMessage(),
            'attempts' => $attempts,
        ]);
    } else {
        $delay = $delays[min($attempts - 1, count($delays) - 1)];
        as_schedule_single_action(
            time() + $delay,
            'leanweave_process_single',
            ['post_id' => $post_id],
            'leanweave'
        );
    }
}
```

### Throughput Projections

With 3 concurrent workers and 100 posts per batch:
- Conservative estimate (500ms per post): 3 workers * (100 posts / 50s) = 6 posts/second = 360 posts/minute
- Realistic estimate (200ms per post): 3 workers * (100 posts / 20s) = 15 posts/second = 900 posts/minute
- Bulk 15,000 posts: 15,000 / 360 = ~42 minutes (conservative), well within the 4-hour limit
- Sustained throughput for 100 new posts/day: trivially handled (100 posts in under 1 minute)

---

## 8. Risk Mitigation

### Risk 1: Cold Cache (First Visit After Cache Clear)

**Scenario:** Object cache is flushed (Redis restart, Memcached eviction, deploy). A visitor hits a page before the background job re-caches it.

**Mitigation:**
- The `the_content` filter serves original content without links. The page loads at full speed with zero degradation.
- A low-priority background action is enqueued to re-cache the post.
- This is an acceptable trade-off: the user sees the page without automated links for one visit. Manual links in the content still work.
- Cache warming strategy (see ADR-002) proactively re-caches the most-visited posts after a flush.

**Impact:** Minimal. Visitors see original content. No errors, no slowdown. Links reappear within minutes as the background queue processes.

### Risk 2: Action Scheduler Backlog

**Scenario:** A large rule change triggers 15,000 posts for reprocessing while 100 new posts/day are also being published.

**Mitigation:**
- Priority system ensures new posts (priority 10) are processed before bulk reprocess (priority 50).
- Bulk reprocess jobs are staggered with 10-second intervals to prevent queue flooding.
- Action Scheduler has a built-in claim system that prevents duplicate processing.
- Monitoring: the `/health` endpoint reports queue depth and estimated time to drain.
- Circuit breaker: if queue depth exceeds 20,000, bulk reprocess is paused and an admin notice is displayed.

**Impact:** New posts still get links within seconds. Bulk reprocess may take longer but completes in the background without affecting site performance.

### Risk 3: Rule Change Triggers 15,000-Post Reprocessing

**Scenario:** A glossary term is added that matches content in most posts, triggering reprocessing of 15,000+ posts.

**Mitigation:**
- The `LIKE '%keyword%'` query to find affected posts runs only in the admin/API context (not frontend).
- Affected post IDs are batched (1,000 at a time) to avoid memory issues with the query result.
- Reprocessing jobs are enqueued at priority 30, below new posts (10) but above bulk (50).
- Estimated completion: 15,000 posts at conservative 360 posts/minute = ~42 minutes.
- During reprocessing, posts serve their last cached version (if TTL has not expired) or original content (if expired).
- Admin UI shows progress: "Reprocessing: 8,432 of 15,000 posts complete."

### Risk 4: Engine Bug Corrupts Output

**Scenario:** A bug in `RuleMatcherEngine` produces malformed HTML in the processed content.

**Mitigation:**
- Original `post_content` is NEVER modified. The processed content is stored separately.
- If a bug is discovered, the fix is: clear the cache and re-run bulk reprocess.
- The `the_content` filter includes a basic sanity check: if processed content length is less than 50% of original content length, discard it and serve original (likely corruption).
- All processed content is logged in `lw_applied_links`, enabling audit and rollback.

### Risk 5: Object Cache Unavailable

**Scenario:** The hosting environment does not have Redis or Memcached installed.

**Mitigation:**
- Fallback to transients with autoload for lightweight flag checking (see section 6).
- Processed content stored in `lw_applied_links.processed_content` column.
- Frontend query count rises to 1 per page load (loading processed content from DB).
- This exceeds the "0 queries" target, so the admin dashboard prominently recommends installing an object cache.
- Performance degrades gracefully rather than failing.

### Risk 6: Plugin Deactivation or Conflict

**Scenario:** LeanWeave is deactivated or another plugin conflicts with the `the_content` filter.

**Mitigation:**
- Since original `post_content` is never modified, deactivating LeanWeave simply stops applying links. No cleanup needed.
- The `the_content` filter runs at priority 999 (very late) to avoid conflicts with other content filters.
- If another plugin removes or overrides the content, LeanWeave's filter gracefully handles it (null check on content).

---

## 9. Performance Guarantees

### How Each Metric Is Guaranteed

| Metric | Guarantee Mechanism |
|--------|-------------------|
| **B1: TTFB < 5ms delta** | `the_content` filter does at most one `wp_cache_get()` call (sub-millisecond). No computation, no DB queries on frontend. |
| **B2: 0 frontend DB queries** | `wp_cache_get()` hits object cache (in-memory). On miss, original content served without any DB query. |
| **B3: save_post < 50ms** | Handler performs one `INSERT ... ON DUPLICATE KEY UPDATE` on `lw_queue` and one `as_enqueue_async_action()`. Both are sub-5ms operations. |
| **B4: Bulk 15K < 4 hours** | 3 concurrent workers at 360 posts/minute (conservative) = 42 minutes for 15,000 posts. |
| **B5: Sustained > 70 posts/hour** | Even a single worker at the slowest estimate processes 120 posts/minute. 70/hour is trivially met. |
| **B6: Engine < 500ms/post (p95)** | Engine optimizations: compiled regex patterns, sorted rules by priority, early termination on max_per_post. Benchmarked before release. |
| **B7: Memory < 32MB/job** | Batch size of 100 posts with per-batch memory monitoring. If approaching limit, batch splits automatically. |

### Monitoring Strategy

**Runtime monitoring (always active):**
- Every background job logs duration, memory, and link count to `lw_performance_log`
- The `the_content` filter logs cache hit/miss ratio to a lightweight counter (object cache key, incremented atomically)
- Health endpoint (`GET /health`) reports: queue depth, cache hit rate, average processing time, failed jobs count

**Benchmark monitoring (development/CI):**
- `bin/benchmark.sh` runs the full benchmark suite defined in the performance specification
- Every PR runs fast benchmarks (B1, B2, B3, B6, B7)
- Every milestone runs the full suite (B1-B10)
- Before release: 3 consecutive full-suite runs, all must pass

**Alerting thresholds:**
- Queue depth > 5,000: Warning (admin notice)
- Queue depth > 20,000: Critical (pause bulk operations, admin notice)
- Cache hit rate < 80%: Warning (may indicate cache eviction issues)
- Failed jobs > 50 in 24 hours: Critical (possible engine bug)
- Average processing time > 1000ms per post: Warning (performance regression)

---

## 10. Consequences

### What This Decision Means for the Architecture

**Database schema:**
- `lw_applied_links` table gains a `processed_content` column (LONGTEXT) for storing the full processed HTML as fallback when object cache is unavailable.
- `lw_queue` table is the central coordination point for all processing.
- No modifications to the WordPress `posts` table or `post_content` column, ever.

**Plugin dependencies:**
- Action Scheduler becomes a required dependency (bundled, not external). It is battle-tested (used by WooCommerce with millions of installs) and adds minimal overhead.
- Object cache (Redis/Memcached) becomes a strongly recommended dependency. The plugin works without it but with degraded guarantees.

**API design:**
- Queue management endpoints (`/queue/*`) become first-class citizens in the REST API.
- Rule CRUD endpoints must trigger the reprocessing pipeline as a side effect.
- Health endpoint must expose queue and cache metrics.

**Admin UI:**
- Must display queue status (pending/processing/done/failed counts).
- Must surface cache health and object cache recommendation.
- Must provide a "Reprocess All" button with progress indicator.
- Must show per-rule impact (how many posts affected, last processed time).

**Testing requirements:**
- Integration tests must verify the full pipeline: save_post -> queue -> process -> cache -> serve.
- Performance tests must validate all B1-B10 metrics in the Docker environment.
- Cache miss scenarios must be explicitly tested (serve original content, not error).

**Operational model:**
- Site operators should expect a brief delay (seconds to minutes) between publishing and links appearing.
- After adding a high-frequency rule (keyword appears in many posts), reprocessing may take up to an hour for the full corpus.
- Cache flushes (e.g., during deploys) temporarily remove automated links but do not break the site.

**Future extensibility:**
- The background processing model supports future features: link analytics tracking, A/B testing different link strategies, ML-based link optimization.
- The cache layer can be swapped or enhanced without changing the public API.
- The engine can be upgraded (e.g., from regex to NLP-based matching) without changing the pipeline architecture.

---

## Appendix: Architecture Diagram

```
                            WRITE PATH (Background)
                            =======================

  +-----------+     +------------+     +------------------+     +-----------------+
  | save_post | --> | lw_queue   | --> | Action Scheduler | --> | LinkProcessor   |
  | hook      |     | (pending)  |     | (3 workers)      |     | Job             |
  +-----------+     +------------+     +------------------+     +-----------------+
                                                                       |
                         +---------------------------------------------+
                         |                    |                         |
                         v                    v                         v
                  +-------------+    +-----------------+    +------------------+
                  | Object      |    | lw_applied_     |    | lw_performance_  |
                  | Cache       |    | links (DB)      |    | log (DB)         |
                  | (Redis/MC)  |    +-----------------+    +------------------+
                  +-------------+
                         |
                         |
                            READ PATH (Frontend)
                            ====================

  +-----------+     +------------------+     +-------------+
  | Page      | --> | the_content      | --> | wp_cache_   |
  | Request   |     | filter (p999)    |     | get()       |
  +-----------+     +------------------+     +-------------+
                                                    |
                                      +-------------+-------------+
                                      |                           |
                                      v                           v
                               Cache HIT               Cache MISS
                               Return cached            Return original
                               content                  content (no links)
                               (0 DB queries)           (0 DB queries)


                         RULE CHANGE PATH
                         ================

  +-------------+     +------------------+     +------------+
  | Rule CRUD   | --> | Find affected    | --> | Enqueue    |
  | (API/Admin) |     | posts (LIKE)     |     | to         |
  +-------------+     +------------------+     | lw_queue   |
                                               +------------+
                                                     |
                                                     v
                                              (joins Write Path)
```

---

**This decision is final and governs all subsequent implementation work. The Performance Agent retains veto power over any implementation that fails to meet the benchmark thresholds defined in the performance specification.**
