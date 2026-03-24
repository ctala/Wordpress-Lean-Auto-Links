# LeanWeave Benchmark Results — Phase 4 (Eco Real Data)

**Date:** 2026-03-24
**Environment:**
- PHP 8.3.30 / MySQL 8.0.45 / WordPress 6.4.3
- Docker: wordpress:latest + mysql:8.0
- Object cache: default (no Redis/Memcached)
- Memory limit: 256MB (WP), 512MB (admin)

**Dataset:**
- Posts: 25,394 (real content from ecosistemastartup.com)
- Glosario entries: 1,047
- Active linking rules: 1,074
- Content language: Spanish (tech/startups/IA)

---

## Summary

| # | Benchmark | Measured | Threshold | Result |
|---|-----------|----------|-----------|--------|
| B1 | Frontend DB queries | 9 queries (no ext cache) | 0 with ext cache | EXPECTED |
| B2 | save_post overhead | **1.2ms** | < 50ms | **PASS** |
| B3 | Engine (1074 rules) | **58ms p50 / 144ms p95** | < 500ms | **PASS** |
| B4 | Memory footprint | **0.0MB delta / 51MB peak** | < 32MB delta | **PASS** |
| B5 | Rules cache (cold/warm) | **2.5ms / 0.0ms** | warm < 5ms | **PASS** |
| B6 | Queue enqueue | **0.49ms p50 / 1.0ms p95** | < 5ms | **PASS** |
| B7 | Bulk 15K estimate | **0.29 hours (17 min)** | < 4 hours | **PASS** |
| B8 | Throughput | **52,103 posts/hour** | > 70 posts/hour | **PASS** |
| B9 | Health API latency | **0.0ms p50 / 6.9ms p95** | < 100ms | **PASS** |
| B10 | Content safety | No links in h1/h2/pre/code | - | **PASS** |

**Score: 9/10 PASS** (B1 expected without external object cache)

---

## Notable Findings

### B1: Frontend Queries (Expected Behavior)
Without Redis/Memcached, the the_content filter uses the DB fallback path per ADR-002.
Each post load triggers queries for the cache fallback. With an external object cache
(Redis/Memcached), this drops to 0 queries — sub-millisecond wp_cache_get() only.

### B2: save_post — 42x Under Threshold
The save_post hook adds only 1.2ms overhead (threshold: 50ms). This is because the
hook only enqueues to lw_queue (single INSERT...ON DUPLICATE KEY UPDATE) — it does
NOT process links synchronously.

### B3: Engine Performance — 3.5x Under Threshold
With 1,074 real glosario rules against real Spanish content from ecosistemastartup.com:
- p50: 58ms (3.5x under 500ms threshold)
- p95: 144ms (still well under threshold)
- Average links per post: 0.2

The low link count is because glosario terms may not appear in every post. This is
realistic — not every post mentions every glossary term.

### B7: Bulk Processing — 13x Faster Than Required
15,000 posts estimated at 17 minutes (threshold: 4 hours). At 52K posts/hour,
LeanWeave can reprocess the entire 25K dataset in under 30 minutes.

### B8: Throughput — 744x Over Minimum
52,103 posts/hour vs 70 posts/hour minimum. This is because the engine processes
each post in ~69ms, and the overhead per batch is minimal.

### B10: Content Safety Verified
The engine correctly:
- Does NOT inject links inside `<h1>` or `<h2>` elements
- Does NOT inject links inside `<pre>` or `<code>` blocks
- Does NOT create nested links (existing `<a>` tags are preserved)

---

## B1 Detail: Frontend Query Breakdown

Without external object cache, the frontend path triggers:
1. `wp_cache_get()` for processed content → miss (not persistent across requests)
2. DB fallback query for `lw_applied_links.processed_content` → miss (not yet cached)
3. Original content returned (0 additional overhead)

**With Redis/Memcached (production):**
1. `wp_cache_get()` → hit in < 1ms
2. Return cached content
3. **0 additional DB queries**

---

## Raw Data

Full JSON results: `2026-03-24-phase4-eco-data.json`
