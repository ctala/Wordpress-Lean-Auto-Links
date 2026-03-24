# LeanWeave Performance Benchmark Specification

Version: 1.0
Last updated: 2026-03-24
Owner: Performance Agent

---

## 1. Overview

This document defines the complete benchmark specification for the LeanWeave WordPress plugin. Every metric, threshold, testing methodology, and pass/fail criterion is defined here. No code ships to production unless all benchmarks pass.

The absolute principle: **LeanWeave must NEVER cause a site to load slower.** Every benchmark exists to enforce this guarantee.

---

## 2. Test Environment Requirements

### Hardware Baseline
- Docker containers running on the developer machine
- WordPress latest with PHP 8.1+
- MySQL 8.0
- No object cache (Redis/Memcached) unless explicitly testing cache scenarios

### Data Requirements
- 15,000 posts with realistic content (Spanish, technology/startup topics)
- 500 glossary terms (CPT)
- 500 actor/entity entries (CPT)
- 1,000+ active linking rules (500 glossary + 500 entity + affiliates)
- Content length: 800-2,000 words per post (realistic distribution)

### Environment Setup
```bash
docker compose up -d
docker compose run --rm wp-cli bash /scripts/setup-testing.sh
```

---

## 3. Metrics and Thresholds

### 3.1 Frontend Performance: TTFB (Time To First Byte)

| Metric | Threshold | Priority |
|--------|-----------|----------|
| TTFB difference (plugin active vs inactive) | < 5ms | CRITICAL |
| Additional frontend DB queries | 0 (exactly zero) | CRITICAL |

**Methodology:**
1. Deactivate LeanWeave plugin.
2. Run Apache Bench against 10 different post URLs, 100 requests each, concurrency 5.
3. Record median TTFB for each URL.
4. Activate LeanWeave plugin.
5. Repeat the exact same Apache Bench runs against the same 10 URLs.
6. Compare median TTFB values.
7. The difference must be < 5ms for every URL tested.

**Commands:**
```bash
# Deactivate plugin and measure baseline
docker compose run --rm wp-cli wp plugin deactivate leanweave
ab -n 100 -c 5 -T 'text/html' http://localhost:8080/?p=<POST_ID> 2>&1 | grep 'Time per request'

# Activate plugin and measure
docker compose run --rm wp-cli wp plugin activate leanweave
ab -n 100 -c 5 -T 'text/html' http://localhost:8080/?p=<POST_ID> 2>&1 | grep 'Time per request'
```

**DB Query Validation:**
```bash
# With Query Monitor active, fetch a page and check the query count
# Plugin must add exactly 0 queries to frontend page loads
curl -s -o /dev/null -w "%{http_code}" http://localhost:8080/?p=<POST_ID>
# Compare query count in Query Monitor output (wp-admin bar or REST endpoint)
```

**Pass/Fail:**
- PASS: Median TTFB difference < 5ms AND 0 additional queries on all 10 URLs
- FAIL: Any URL shows >= 5ms difference OR any URL shows additional queries

---

### 3.2 save_post Overhead

| Metric | Threshold | Priority |
|--------|-----------|----------|
| Additional time in save_post hook | < 50ms | CRITICAL |
| Additional memory in save_post | < 2MB | HIGH |

**Methodology:**
1. Instrument save_post with microtime(true) before/after LeanWeave hook.
2. Save 50 posts (mix of new and updated) with plugin inactive. Record baseline.
3. Save the same 50 posts with plugin active. Record instrumented time.
4. The difference must be < 50ms for every single save operation.

**Commands:**
```bash
# The benchmark script measures save_post via WP-CLI with timing instrumentation
docker compose run --rm wp-cli wp eval '
    $start = microtime(true);
    wp_update_post(["ID" => 1, "post_content" => get_post(1)->post_content . " "]);
    $elapsed = (microtime(true) - $start) * 1000;
    echo "save_post time: {$elapsed}ms\n";
'
```

**Detailed Profiling:**
```bash
# With Blackfire/XDebug, profile save_post to identify specific bottlenecks
# Focus on: DB queries triggered, memory allocations, function call count
docker compose run --rm wp-cli wp eval '
    $post_id = 1;
    $hooks_before = array_keys($GLOBALS["wp_filter"]["save_post"]->callbacks ?? []);
    echo "Hooks on save_post: " . implode(", ", $hooks_before) . "\n";
'
```

**Pass/Fail:**
- PASS: All 50 save operations show < 50ms additional overhead
- FAIL: Any single save operation shows >= 50ms additional overhead

---

### 3.3 Bulk Processing: 15,000 Posts

| Metric | Threshold | Priority |
|--------|-----------|----------|
| Process all 15,000 posts | < 4 hours (240 minutes) | HIGH |
| Average throughput | > 62.5 posts/minute (1.04 posts/second) | HIGH |
| No fatal errors during bulk | 0 fatals | CRITICAL |
| Memory per batch | < 32MB peak | HIGH |

**Methodology:**
1. Ensure 15,000 posts exist with content and 1,000+ rules are active.
2. Trigger a full bulk reprocess via WP-CLI or API.
3. Monitor progress, timing, memory usage, and error count.
4. Record total wall-clock time from start to completion.

**Commands:**
```bash
# Trigger bulk reprocess and time it
docker compose run --rm wp-cli bash -c '
    START=$(date +%s)
    wp leanweave bulk-reprocess --all 2>&1
    END=$(date +%s)
    ELAPSED=$((END - START))
    echo "Bulk processing completed in ${ELAPSED} seconds"
    echo "That is $((ELAPSED / 60)) minutes"
'
```

**Memory Monitoring:**
```bash
# Monitor PHP memory during bulk processing
docker compose run --rm wp-cli wp eval '
    $mem_before = memory_get_usage(true);
    // Trigger single batch processing
    do_action("leanweave_process_batch", 0, 100);
    $mem_after = memory_get_peak_usage(true);
    $mem_used = ($mem_after - $mem_before) / 1024 / 1024;
    echo "Memory used by batch: {$mem_used}MB\n";
'
```

**Pass/Fail:**
- PASS: Total time < 240 minutes AND 0 fatal errors AND memory per batch < 32MB
- FAIL: Any threshold exceeded

---

### 3.4 Sustained Throughput

| Metric | Threshold | Priority |
|--------|-----------|----------|
| Posts processed per hour (sustained) | > 70 posts/hour | HIGH |
| No degradation over 2-hour window | Throughput stays within 10% | MEDIUM |

**Methodology:**
1. Queue 200 posts for processing.
2. Start the Action Scheduler runner.
3. Measure posts completed per 15-minute window over a 2-hour period.
4. Calculate hourly throughput rate.
5. Verify no significant degradation between the first and last measurement windows.

**Commands:**
```bash
# Queue posts and monitor throughput
docker compose run --rm wp-cli bash -c '
    # Queue 200 posts
    wp leanweave queue --count=200

    # Monitor processing every 15 minutes for 2 hours
    for i in $(seq 1 8); do
        sleep 900
        PROCESSED=$(wp leanweave queue-stats --field=processed_last_15m)
        echo "Window $i: ${PROCESSED} posts in 15 minutes ($(( PROCESSED * 4 ))/hour)"
    done
'
```

**Pass/Fail:**
- PASS: Every 15-minute window processes >= 17 posts (68/hour rounded) AND last window >= 90% of first window
- FAIL: Any window below threshold OR degradation > 10%

---

### 3.5 Rule Matching Engine Performance

| Metric | Threshold | Priority |
|--------|-----------|----------|
| Time to process 1 post with 1,000 rules | < 500ms | CRITICAL |
| Time to process 1 post with 100 rules | < 50ms | HIGH |
| Memory per post processing | < 8MB | HIGH |

**Methodology:**
1. Load 1,000 active rules into the system.
2. Select 20 posts with varying content lengths (500 to 3,000 words).
3. Process each post individually and measure wall-clock time.
4. Record p50, p95, and p99 processing times.
5. All p95 values must be under the threshold.

**Commands:**
```bash
# Benchmark engine with 1,000 rules
docker compose run --rm wp-cli wp eval '
    $rule_count = count(LeanWeave\Repositories\RulesRepository::get_active_rules());
    echo "Active rules: {$rule_count}\n";

    $post_ids = get_posts([
        "posts_per_page" => 20,
        "fields" => "ids",
        "orderby" => "rand",
    ]);

    $times = [];
    foreach ($post_ids as $post_id) {
        $start = microtime(true);
        $engine = new LeanWeave\Engine\RuleMatcherEngine();
        $engine->process_post($post_id);
        $elapsed = (microtime(true) - $start) * 1000;
        $times[] = $elapsed;
        echo "Post {$post_id}: {$elapsed}ms\n";
    }

    sort($times);
    $p50 = $times[(int)(count($times) * 0.50)];
    $p95 = $times[(int)(count($times) * 0.95)];
    echo "\np50: {$p50}ms\np95: {$p95}ms\n";
'
```

**Pass/Fail:**
- PASS: p95 < 500ms with 1,000 rules AND p95 < 50ms with 100 rules
- FAIL: Any threshold exceeded

---

### 3.6 Memory Footprint per Job

| Metric | Threshold | Priority |
|--------|-----------|----------|
| Peak memory per job execution | < 32MB | HIGH |
| Memory growth over 100 batches | < 5% (no leaks) | CRITICAL |

**Methodology:**
1. Run a single LinkProcessorJob batch (100 posts).
2. Measure peak memory via memory_get_peak_usage(true).
3. Run 100 consecutive batches and compare memory at batch 1 vs batch 100.
4. Growth must be < 5% to confirm no memory leaks.

**Commands:**
```bash
docker compose run --rm wp-cli wp eval '
    $initial_mem = memory_get_usage(true);

    for ($batch = 1; $batch <= 100; $batch++) {
        $mem_before = memory_get_usage(true);
        do_action("leanweave_process_batch", ($batch - 1) * 100, 100);
        $mem_after = memory_get_peak_usage(true);

        if ($batch === 1 || $batch === 100 || $batch % 25 === 0) {
            $used = ($mem_after - $initial_mem) / 1024 / 1024;
            echo "Batch {$batch}: peak memory {$used}MB above initial\n";
        }
    }

    $final_mem = memory_get_usage(true);
    $growth = (($final_mem - $initial_mem) / $initial_mem) * 100;
    echo "Memory growth over 100 batches: {$growth}%\n";
'
```

**Pass/Fail:**
- PASS: Peak memory < 32MB per batch AND growth < 5% over 100 batches
- FAIL: Any threshold exceeded

---

## 4. Tools

| Tool | Purpose | Installation |
|------|---------|-------------|
| Query Monitor | Count DB queries, identify slow queries, hook profiling | `wp plugin install query-monitor --activate` |
| Blackfire | PHP profiling, call graph analysis, memory tracking | Blackfire agent + probe in Docker |
| XDebug | Alternative PHP profiler, step debugging if needed | PHP extension in Docker |
| Apache Bench (ab) | HTTP load testing, TTFB measurement, concurrency testing | Pre-installed in most systems, `apt-get install apache2-utils` in Docker |
| WP-CLI | WordPress management, script execution, data generation | `wordpress:cli` Docker image |
| wp --debug | Hook analysis, query logging, error tracking | Built into WP-CLI |

---

## 5. How to Run Benchmarks

### Quick Run (CI-friendly)
```bash
./bin/benchmark.sh
```

This script runs all benchmarks and outputs a pass/fail report. Exit code 0 means all pass, non-zero means at least one failure.

### Manual Run by Category

```bash
# Frontend only (TTFB + queries)
./bin/benchmark.sh --frontend

# save_post only
./bin/benchmark.sh --save-post

# Engine performance only
./bin/benchmark.sh --engine

# Bulk processing (long-running)
./bin/benchmark.sh --bulk

# Full suite
./bin/benchmark.sh --all
```

### Before/After Comparison Workflow

This is the standard workflow for validating any code change:

```bash
# Step 1: Ensure clean environment
docker compose down -v && docker compose up -d
docker compose run --rm wp-cli bash /scripts/setup-testing.sh

# Step 2: Measure BASELINE (plugin inactive)
docker compose run --rm wp-cli wp plugin deactivate leanweave
./bin/benchmark.sh --frontend --output baseline.json

# Step 3: Measure WITH PLUGIN (plugin active)
docker compose run --rm wp-cli wp plugin activate leanweave
./bin/benchmark.sh --frontend --output active.json

# Step 4: Compare results
# The benchmark script handles comparison and shows deltas
./bin/benchmark.sh --compare baseline.json active.json
```

---

## 6. Pass/Fail Summary Table

| ID | Metric | Threshold | Priority | Veto Power |
|----|--------|-----------|----------|------------|
| B1 | TTFB delta (active vs inactive) | < 5ms | CRITICAL | YES |
| B2 | Additional frontend DB queries | 0 | CRITICAL | YES |
| B3 | save_post overhead | < 50ms | CRITICAL | YES |
| B4 | Bulk 15,000 posts | < 4 hours | HIGH | YES |
| B5 | Sustained throughput | > 70 posts/hour | HIGH | YES |
| B6 | Engine with 1,000 rules per post | < 500ms (p95) | CRITICAL | YES |
| B7 | Memory per job execution | < 32MB peak | HIGH | YES |
| B8 | Memory leak over 100 batches | < 5% growth | CRITICAL | YES |
| B9 | save_post additional memory | < 2MB | HIGH | NO |
| B10 | Engine with 100 rules per post | < 50ms (p95) | HIGH | NO |

**Veto Power** means the Performance Agent blocks any release if this metric fails.

---

## 7. Statistical Requirements

All performance measurements must meet the following statistical standards:

- **Minimum sample size:** 50 requests per metric (100 for TTFB)
- **Reported percentiles:** p50, p90, p95, p99
- **Threshold comparison:** Uses p95 unless otherwise specified
- **Warm-up:** Discard first 10 requests as warm-up for TTFB tests
- **Confidence:** Results must be reproducible across 3 consecutive runs
- **Environment:** Docker must be idle (no other heavy processes) during benchmarks
- **Reporting:** All raw data saved as JSON for historical comparison

---

## 8. Benchmark Cadence

| When | What to Run |
|------|-------------|
| Every PR | B1, B2, B3, B6, B7 (fast benchmarks) |
| Every milestone | Full suite (B1-B10) |
| Before release | Full suite, 3 consecutive runs, all must pass |
| After infrastructure change | Full suite with extended warm-up |

---

## 9. Failure Protocol

When a benchmark fails:

1. **Stop.** Do not merge or release.
2. **Profile.** Use Blackfire/XDebug to identify the exact bottleneck.
3. **Fix.** Optimize the code causing the regression.
4. **Re-run.** Execute the failed benchmark plus all CRITICAL benchmarks.
5. **Document.** Record the failure, root cause, and fix in the benchmark results log.

The Performance Agent has veto power over any release where CRITICAL benchmarks fail. No exceptions.

---

## 10. Competitive Benchmark: LeanWeave vs Existing Plugins

### Purpose
Install competing plugins alongside LeanWeave in the Docker environment to validate that LeanWeave is objectively better at scale.

### Plugins to Test
1. **Internal Link Juicer Pro** - Current plugin used by the project owner. Known to fail auto-linking at 15K+ posts.
2. **Internal Link Juicer Free** - Free version for baseline comparison.
3. **Internal Links Manager** - v3.0+ with caching backends.
4. **Link Whisper Free** - Market leader, documented performance issues.

### Test Protocol
For each plugin, on the same Docker environment with the same 15,000 posts and 1,000 rules:

| Metric | Measurement |
|--------|------------|
| Auto-linking execution | Does it actually process all posts? Or fail silently like ILJ? |
| TTFB delta (active vs inactive) | Apache Bench, 100 requests, 10 sample posts |
| Additional frontend DB queries | Query Monitor count comparison |
| save_post overhead | Instrumented timing on 50 saves |
| Bulk processing time (15K posts) | Wall clock time from trigger to completion |
| Memory usage | Peak memory during bulk processing |
| Keywords with tildes/accents | Does matching work for: inteligencia artificial, startup, tecnologia vs tecnología |
| Overlapping keywords | How does it handle "IA" vs "inteligencia artificial"? |

### Setup Script
```bash
# Install competing plugins for benchmark comparison
docker compose run --rm wp-cli wp plugin install internal-links --activate
docker compose run --rm wp-cli wp plugin install seo-automated-link-building --activate
docker compose run --rm wp-cli wp plugin install link-whisper --activate

# Configure each with the same 1,000 rules (import via CSV/API)
# Run benchmark suite per plugin
# Deactivate all except LeanWeave for final comparison
```

### Expected Outcome
Document where each plugin fails and how LeanWeave handles the same scenario. This becomes marketing material for the wordpress.org listing and README.

### First-Party Evidence
The project owner reports that ILJ Pro on ecosistemastartup.com (15K+ posts):
- Auto-linking does not execute (fails silently)
- Must trigger manually every time
- Always recalculates from scratch (no incremental processing)
This must be reproduced and documented in the competitive benchmark.
