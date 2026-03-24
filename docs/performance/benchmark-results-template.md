# LeanWeave Benchmark Results

## Run Information

| Field | Value |
|-------|-------|
| Date | YYYY-MM-DD HH:MM |
| Milestone | Phase X: [name] |
| Git Commit | [hash] |
| Tester | [Performance Agent / name] |
| Environment | Docker (WordPress latest, MySQL 8.0, PHP 8.x) |
| Data Set | [15,000 posts / X rules active] |

---

## B1: TTFB (Plugin Active vs Inactive)

**Threshold: delta < 5ms**

| Post ID | Baseline (ms) | Active (ms) | Delta (ms) | Result |
|---------|---------------|-------------|------------|--------|
| | | | | PASS/FAIL |
| | | | | PASS/FAIL |
| | | | | PASS/FAIL |
| | | | | PASS/FAIL |
| | | | | PASS/FAIL |

**Overall: PASS / FAIL**

---

## B2: Additional Frontend DB Queries

**Threshold: 0 additional queries**

| Post ID | Baseline Queries | Active Queries | Additional | Result |
|---------|-----------------|----------------|------------|--------|
| | | | | PASS/FAIL |

**Overall: PASS / FAIL**

---

## B3: save_post Overhead

**Threshold: < 50ms additional per save**

| Statistic | Value (ms) |
|-----------|------------|
| p50 | |
| p90 | |
| p95 | |
| p99 | |
| Max | |
| Samples | /50 |

**Overall: PASS / FAIL** (max must be < 50ms)

---

## B4: Bulk Processing (15,000 Posts)

**Threshold: < 4 hours (240 minutes)**

| Metric | Value |
|--------|-------|
| Total posts | |
| Active rules | |
| Total time | min |
| Average per post | ms |
| Batches | |
| Fatal errors | |

**Overall: PASS / FAIL**

---

## B5: Sustained Throughput

**Threshold: > 70 posts/hour sustained**

| Window (15 min) | Posts Processed | Rate (posts/hr) | Result |
|-----------------|-----------------|------------------|--------|
| 0:00 - 0:15 | | | PASS/FAIL |
| 0:15 - 0:30 | | | PASS/FAIL |
| 0:30 - 0:45 | | | PASS/FAIL |
| 0:45 - 1:00 | | | PASS/FAIL |
| 1:00 - 1:15 | | | PASS/FAIL |
| 1:15 - 1:30 | | | PASS/FAIL |
| 1:30 - 1:45 | | | PASS/FAIL |
| 1:45 - 2:00 | | | PASS/FAIL |

**Degradation check:** Last window rate / First window rate = X% (must be >= 90%)

**Overall: PASS / FAIL**

---

## B6: Engine Performance (1,000 Rules)

**Threshold: p95 < 500ms**

| Statistic | Value (ms) |
|-----------|------------|
| p50 | |
| p90 | |
| p95 | |
| p99 | |
| Max | |
| Active rules | |
| Samples | /20 |

**Overall: PASS / FAIL**

---

## B7: Memory Footprint per Job

**Threshold: < 32MB peak**

| Metric | Value |
|--------|-------|
| Peak memory (single batch) | MB |
| Average memory (single batch) | MB |
| Batch size | 100 posts |

**Overall: PASS / FAIL**

---

## B8: Memory Leak Detection

**Threshold: < 5% growth over 100 batches**

| Batch | Memory (MB) |
|-------|-------------|
| 1 | |
| 25 | |
| 50 | |
| 75 | |
| 100 | |

**Growth: X%**

**Overall: PASS / FAIL**

---

## Summary

| ID | Metric | Threshold | Measured | Result | Veto |
|----|--------|-----------|----------|--------|------|
| B1 | TTFB delta | < 5ms | ms | PASS/FAIL | YES |
| B2 | Frontend queries | 0 | | PASS/FAIL | YES |
| B3 | save_post overhead | < 50ms | ms | PASS/FAIL | YES |
| B4 | Bulk 15,000 posts | < 4 hours | min | PASS/FAIL | YES |
| B5 | Sustained throughput | > 70/hr | /hr | PASS/FAIL | YES |
| B6 | Engine 1,000 rules | < 500ms p95 | ms | PASS/FAIL | YES |
| B7 | Memory per job | < 32MB | MB | PASS/FAIL | YES |
| B8 | Memory leak | < 5% growth | % | PASS/FAIL | YES |

---

## Performance Agent Decision

**Status:** APPROVED / BLOCKED

**Notes:**
[Explanation of any failures, required fixes, or notable observations]

**Comparison with Previous Run:**
[Delta analysis against the last benchmark run if available]

**Recommendations:**
[Any optimization suggestions based on results]
