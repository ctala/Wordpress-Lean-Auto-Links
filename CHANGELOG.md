# Changelog

All notable changes to the LeanAutoLinks plugin are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.2.0] - 2026-03-24

### Added

- **Gutenberg sidebar panel** -- manage keywords directly from the block editor using a native `PluginDocumentSettingPanel`.
- **Dashboard widget** -- quick overview of rules, applied links, queue status, and performance on the WordPress dashboard.
- **Meta box on all CPTs** -- keyword meta box now appears on all public post types by default (configurable via settings).
- **ETA timer** -- estimated time remaining shown on the queue progress bar.
- **Translation support** -- `.pot` file generated with 1,069 translatable strings.
- **Spanish README** -- `README.es.md` for Spanish-speaking users.
- **GitHub Sponsors** -- `FUNDING.yml` configured for project sponsorship.
- **Migration guide** -- instructions for migrating from Internal Link Juicer and other plugins.

### Changed

- **Queue processing** -- batches now self-chain: after completing a batch, the next one is automatically scheduled (5s delay). Previously, only one batch ran per trigger.
- **Default batch size** -- reduced from 100 to 25 posts per batch for faster incremental progress.
- **Meta box simplified** -- removed URL and type fields; always creates internal rules targeting the current post's URL.

### Fixed

- Fixed README links pointing to wrong repository URLs.

## [0.1.0] - 2026-03-24

### Added

- **Rule Engine** with Unicode and accent-aware keyword matching (handles Spanish, Portuguese, and other diacritics natively).
- **17 REST API endpoints** under `/wp-json/leanautolinks/v1/` covering rules, queue, applied links, exclusions, performance, and health.
- **Action Scheduler background processing** for zero-impact post saving -- links are built asynchronously, never blocking the editor.
- **3-layer caching system**: object cache (Redis/Memcached when available), partitioned transient cache by rule type, and pre-built content cache for frontend delivery.
- **Admin UI** with 5 tabs: Rules, Queue, Applied Links, Performance, and Exclusions.
- **WP-CLI commands** for seeding test data, bulk reprocessing, cache management, and benchmark execution.
- **Content safety parser** that skips `<h1>`-`<h6>`, `<pre>`, `<code>`, and existing `<a>` tags -- links are never injected inside protected HTML elements.
- **Affiliate link support** with automatic `rel="sponsored nofollow"` attribution on all affiliate rule types.
- **Queue management** with configurable concurrency, automatic retries (up to 3 attempts), error logging, and priority scheduling (new posts before bulk reprocessing).
- **Performance logging** that records duration, memory usage, rules checked, and links applied for every processing event.
- **Exclusions system** supporting post-level, URL-level, keyword-level, and post-type-level exclusions.
- **Bulk import** endpoint for rules via CSV/JSON payloads.
- **Health check** endpoint reporting plugin status, queue depth, cache hit rate, and Action Scheduler availability.

### Performance

Benchmarked against a production-scale dataset from ecosistemastartup.com:

| Metric | Target | Actual |
|---|---|---|
| Dataset size | 15,000 posts | 25,394 posts |
| Active rules | 1,000 | 687 |
| `save_post` overhead | < 50 ms | 1.2 ms |
| Engine p50 (per post) | < 500 ms | 58 ms |
| Bulk 15,000 posts | < 4 hours | 17 minutes |
| Throughput | > 70 posts/hr | 52,000 posts/hr |
| Frontend queries added | 0 | 0 |
| Memory per job | < 32 MB | Within budget |

[0.2.0]: https://github.com/ctala/Wordpress-Lean-Auto-Links/releases/tag/v0.2.0
[0.1.0]: https://github.com/ctala/Wordpress-Lean-Auto-Links/releases/tag/v0.1.0
