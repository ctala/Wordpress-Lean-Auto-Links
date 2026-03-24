# LeanAutoLinks

> Lean, API-first automated internal linking for high-volume WordPress sites.

![WordPress Plugin Version](https://img.shields.io/badge/WordPress-Plugin_v0.1.0-blue?logo=wordpress)
![PHP 8.1+](https://img.shields.io/badge/PHP-8.1%2B-777BB4?logo=php&logoColor=white)
![License: GPLv2](https://img.shields.io/badge/License-GPLv2-green.svg)

## Why This Exists

Internal linking at scale is a manual nightmare. Sites with 15,000+ posts and hundreds of linking rules cannot rely on plugins that scan content on every page load or block the editor during saves. Existing solutions (Link Whisper, Internal Link Juicer, Rank Math) degrade performance as rule counts grow, and none of them expose a proper API for automation.

LeanAutoLinks was built for a site publishing 100 posts per day with 1,000+ linking rules. It processes links in the background, serves them from cache, adds zero queries to your frontend, and exposes every operation through a REST API designed for AI agents and content pipelines.

## Features

- **Background processing** -- links are built asynchronously via Action Scheduler, never blocking saves or page loads.
- **Zero frontend overhead** -- processed content is served from cache with 0 additional database queries.
- **Unicode-aware matching** -- handles accented characters, Spanish, Portuguese, and other diacritics natively.
- **Content safety** -- never injects links inside headings (`h1`-`h6`), `pre`, `code`, or existing anchor tags.
- **Affiliate compliance** -- affiliate links automatically receive `rel="sponsored nofollow"`.
- **17 REST API endpoints** -- full CRUD for rules, queue management, applied links, exclusions, performance logs, and health checks.
- **3-layer cache** -- object cache (Redis/Memcached), partitioned transients by rule type, and pre-built content cache.
- **Admin UI** -- 5-tab interface for managing rules, monitoring the queue, reviewing applied links, tracking performance, and configuring exclusions.
- **WP-CLI commands** -- seed data, bulk reprocess, manage cache, and run benchmarks from the command line.
- **Exclusions system** -- exclude by post, URL, keyword, or post type.
- **Bulk import** -- load rules from CSV or JSON via the API.

## Quick Start

### Option 1: Docker (recommended for evaluation)

```bash
git clone https://github.com/ecosistemastartup/leanautolinks.git
cd leanautolinks
docker compose up -d
```

Open `http://localhost:8080/wp-admin`, activate the LeanAutoLinks plugin, and you are ready.

### Option 2: Manual installation

1. Upload the `leanautolinks` folder to `/wp-content/plugins/`.
2. Activate through the WordPress admin panel.
3. The plugin creates its database tables on activation automatically.

### Create your first rule

**Via the admin UI:** Go to LeanAutoLinks > Rules > Add New.

**Via the API:**

```bash
curl -X POST http://localhost:8080/wp-json/leanautolinks/v1/rules \
  -H "Content-Type: application/json" \
  -u "admin:YOUR_APP_PASSWORD" \
  -d '{
    "rule_type": "internal",
    "keyword": "inteligencia artificial",
    "target_url": "/glosario/inteligencia-artificial/",
    "max_per_post": 1
  }'
```

New and updated posts are processed automatically in the background. To reprocess existing content in bulk:

```bash
curl -X POST http://localhost:8080/wp-json/leanautolinks/v1/queue/bulk \
  -H "Content-Type: application/json" \
  -u "admin:YOUR_APP_PASSWORD" \
  -d '{"post_type": "post", "limit": 15000}'
```

## REST API

Base URL: `/wp-json/leanautolinks/v1/`

Authentication: WordPress Application Passwords (recommended) or any authentication plugin that supports the REST API.

Full specification: see `openapi.yaml` in the repository root.

### Key endpoints

| Method | Endpoint | Description |
|---|---|---|
| `GET` | `/rules` | List all rules (filterable by type, status) |
| `POST` | `/rules` | Create a new linking rule |
| `PUT` | `/rules/{id}` | Update a rule |
| `PATCH` | `/rules/{id}/toggle` | Enable or disable a rule |
| `POST` | `/rules/import` | Bulk import rules (CSV/JSON) |
| `POST` | `/queue/bulk` | Enqueue posts for background processing |
| `POST` | `/queue/retry` | Retry failed queue items |
| `GET` | `/queue` | List queue status |
| `GET` | `/applied?post_id={id}` | Get links applied to a specific post |
| `GET` | `/applied/stats` | Aggregated linking statistics |
| `GET` | `/exclusions` | List all exclusions |
| `POST` | `/exclusions` | Add an exclusion |
| `GET` | `/performance/summary` | Performance metrics summary |
| `GET` | `/health` | Plugin health, queue depth, cache status |

### Example: Create an affiliate rule

```bash
curl -X POST http://localhost:8080/wp-json/leanautolinks/v1/rules \
  -H "Content-Type: application/json" \
  -u "admin:YOUR_APP_PASSWORD" \
  -d '{
    "rule_type": "affiliate",
    "keyword": "AWS",
    "target_url": "https://aws.amazon.com/?ref=yourtrackingid",
    "max_per_post": 1,
    "nofollow": true,
    "sponsored": true
  }'
```

### Example: Check queue health

```bash
curl http://localhost:8080/wp-json/leanautolinks/v1/health \
  -u "admin:YOUR_APP_PASSWORD"
```

```json
{
  "status": "healthy",
  "queue": {"pending": 12, "processing": 3, "failed": 0},
  "cache": {"hit_rate": 0.94},
  "action_scheduler": "available"
}
```

## Agent Integration

LeanAutoLinks is designed as infrastructure for AI agents and automated content pipelines. Every operation is available through the REST API with predictable JSON responses, making it straightforward to integrate with LLM-based workflows, CI/CD pipelines, and custom automation scripts.

### Workflow 1: Glossary sync

When your content team adds a new glossary term, an agent can automatically create the corresponding linking rule and reprocess recent posts.

```bash
# 1. Agent creates a rule for the new glossary term
curl -X POST http://localhost:8080/wp-json/leanautolinks/v1/rules \
  -H "Content-Type: application/json" \
  -u "admin:YOUR_APP_PASSWORD" \
  -d '{
    "rule_type": "internal",
    "keyword": "machine learning",
    "target_url": "/glosario/machine-learning/",
    "entity_type": "glossary",
    "entity_id": 4521,
    "max_per_post": 1,
    "priority": 5
  }'

# 2. Agent enqueues recent posts for reprocessing
curl -X POST http://localhost:8080/wp-json/leanautolinks/v1/queue/bulk \
  -H "Content-Type: application/json" \
  -u "admin:YOUR_APP_PASSWORD" \
  -d '{"post_type": "post", "date_after": "2026-03-01", "limit": 5000}'

# 3. Agent monitors progress
curl http://localhost:8080/wp-json/leanautolinks/v1/queue \
  -u "admin:YOUR_APP_PASSWORD"
```

### Workflow 2: Content pipeline monitoring

An agent that publishes posts programmatically can verify that linking was applied correctly after each batch.

```bash
# Check applied links for a specific post
curl http://localhost:8080/wp-json/leanautolinks/v1/applied?post_id=12345 \
  -u "admin:YOUR_APP_PASSWORD"

# Get aggregate statistics to detect anomalies
curl http://localhost:8080/wp-json/leanautolinks/v1/applied/stats \
  -u "admin:YOUR_APP_PASSWORD"
```

### Workflow 3: Performance monitoring

Agents can poll the performance endpoint to detect degradation before it affects users.

```bash
# Get performance summary
curl http://localhost:8080/wp-json/leanautolinks/v1/performance/summary \
  -u "admin:YOUR_APP_PASSWORD"

# Example response
{
  "avg_duration_ms": 58,
  "p95_duration_ms": 142,
  "avg_memory_kb": 4200,
  "total_processed_24h": 1847,
  "failed_24h": 0,
  "avg_links_per_post": 3.2
}
```

### Workflow 4: Bulk rule import from external systems

Sync rules from a CRM, affiliate platform, or spreadsheet.

```bash
curl -X POST http://localhost:8080/wp-json/leanautolinks/v1/rules/import \
  -H "Content-Type: application/json" \
  -u "admin:YOUR_APP_PASSWORD" \
  -d '{
    "rules": [
      {"rule_type": "entity", "keyword": "Y Combinator", "target_url": "/actores/y-combinator/", "entity_type": "actor", "entity_id": 101},
      {"rule_type": "entity", "keyword": "Sequoia Capital", "target_url": "/actores/sequoia-capital/", "entity_type": "actor", "entity_id": 102},
      {"rule_type": "affiliate", "keyword": "Notion", "target_url": "https://notion.so/?ref=yoursite", "nofollow": true, "sponsored": true}
    ]
  }'
```

## Performance

Benchmarked with real production data from a site running 25,394 posts and 687 active rules.

| Metric | Target | Result |
|---|---|---|
| `save_post` overhead | < 50 ms | **1.2 ms** |
| Engine latency (p50) | < 500 ms | **58 ms** |
| Engine latency (p95) | -- | **142 ms** |
| Bulk reprocess 15K posts | < 4 hours | **17 minutes** |
| Throughput | > 70 posts/hr | **52,000 posts/hr** |
| Frontend DB queries added | 0 | **0** |
| Memory per job execution | < 32 MB | **Within budget** |

The plugin adds zero database queries to frontend page loads. All link injection is resolved at processing time and served from cache.

## Architecture

LeanAutoLinks uses a **hybrid async** strategy:

```
save_post hook
  |
  v
Enqueue post_id (< 2ms, non-blocking)
  |
  v
Action Scheduler picks up job in background
  |
  v
RuleMatcherEngine scans content against active rules
  |-- ContentParser: extracts safe text nodes (skips h1-h6, pre, code, a)
  |-- LinkBuilder: constructs links with correct rel attributes
  |
  v
Processed content stored in cache
  |
  v
Frontend serves cached content (0 extra queries)
```

**Why this approach?**

- **On-save sync** blocks the editor. At 687 rules, that means noticeable delay every time an author hits Publish.
- **On-render** risks TTFB spikes on cache misses, especially for cold starts on high-traffic pages.
- **Hybrid async** decouples processing from both the editor and the reader. The editor save returns instantly. The reader always gets cached content. The background worker handles the heavy lifting on its own schedule.

### Cache layers

1. **Object cache** (Redis/Memcached) -- used when available for rule sets and processed content.
2. **Partitioned transients** -- rules are cached by type (internal, affiliate, entity) with independent TTLs and invalidation.
3. **Content cache** -- pre-built HTML with links already injected, keyed by post ID and rule version hash.

## Requirements

- **PHP**: 8.1 or higher
- **WordPress**: 6.0 or higher
- **Action Scheduler**: 3.0 or higher (bundled with WooCommerce, or installed standalone)
- **MySQL**: 8.0 or higher (or MariaDB 10.4+)
- **Recommended**: Redis or Memcached for optimal cache performance

## WP-CLI Commands

```bash
# Seed test data for benchmarking
wp leanautolinks seed --posts=15000 --actors=500 --glossary=500 --affiliates=100

# Reprocess all posts
wp leanautolinks reprocess --all

# Reprocess posts by type
wp leanautolinks reprocess --post-type=post --limit=5000

# Clear all caches
wp leanautolinks cache clear

# Run performance benchmark
wp leanautolinks benchmark --posts=1000 --rules=500
```

## Contributing

Contributions are welcome. Before submitting a pull request:

1. Ensure all code follows `declare(strict_types=1)` and WordPress coding standards.
2. Run the test suite: `composer test`
3. Verify that performance benchmarks pass: no regression in `save_post` overhead, engine latency, or memory usage.
4. Update `openapi.yaml` if you modify any API endpoint.
5. Add a changelog entry under `[Unreleased]` in `CHANGELOG.md`.

See [CONTRIBUTING.md](CONTRIBUTING.md) for full guidelines.

## License

GPLv2 or later. See [LICENSE](LICENSE) for the full text.
