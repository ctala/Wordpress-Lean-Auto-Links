# Changelog

All notable changes to the LeanAutoLinks plugin are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.4.1] - 2026-03-24

### Fixed

- **Rule change enqueues all affected posts** -- `RuleChangeHandler` had a `LIMIT 1000` that silently truncated enqueuing when a keyword appeared in more than 1,000 posts. Keywords like "startups" (9,570 posts) would only reprocess 1,000. Now paginated in batches of 500 with no upper limit.

### Added

- **Reprocessing rules documented** -- AGENTS.md now includes a complete table of what gets reprocessed for each event (create, update, delete, toggle, save_post, bulk).

## [0.4.0] - 2026-03-24

### Added

- **Applied links visible in dashboard** -- new "Recent Applied Links" table in the Dashboard tab showing the last 25 links with keyword, target URL, rule type, post name, and date.
- **Target URL in meta box and Gutenberg panel** -- applied keywords now show the destination URL below each keyword, both in the classic editor meta box and the block editor sidebar panel.
- **`entity_type` API filter** -- `GET /rules?entity_type=glossary` filters rules by entity type. Enables agents to query and manage rules by entity category (glossary, actor, company, vc, person).
- **Rule type documentation** -- comprehensive documentation of `internal`, `entity`, and `affiliate` rule types with decision flowchart in AGENTS.md and detailed descriptions in openapi.yaml.

## [0.3.2] - 2026-03-24

### Fixed

- **Critical: Links invisible on frontend with Redis** -- `ContentFilterHandler` disabled the DB fallback when an external object cache (Redis/Memcached) was present. When cached entries expired via TTL, links disappeared entirely from the frontend. DB fallback is now always enabled as a reliable secondary layer.
- **Critical: Schema not upgraded on plugin update** -- `dbDelta()` only ran on first activation, not on subsequent updates. Uploading a new zip version never added the `processed_content` and `content_hash` columns to `lw_applied_links`, causing all processed content to be lost. Added `maybe_upgrade()` that automatically runs `dbDelta()` when the plugin version changes.
- **Unique keyword enforcement** -- the same keyword can no longer be used in two different rules. API returns HTTP 409 on duplicates, admin UI shows an error, and bulk import skips duplicates with a warning.

## [0.3.1] - 2026-03-24

### Fixed

- **Plugin Check: 0 errors** -- resolved all 23 PHPCS errors reported by WordPress Plugin Check.
- **Input sanitization** -- replaced `(int)` casts with `absint()` for all `$_POST` values, added `wp_unslash()` in KeywordMetaBox.
- **PHPCS multi-line coverage** -- refactored ternary expressions to if/else so phpcs:ignore comments cover each branch correctly.
- **Translators comments** -- moved `translators:` comments directly above `__()` and `_n()` calls as required by i18n standards.

### Added

- **`wp leanautolinks process-now`** -- WP-CLI command for immediate bulk processing without waiting for cron. Achieved 69,355 posts/hour on 25K test dataset.
- **Observed throughput ETA** -- queue progress now calculates ETA from actual processing speed (last 5 minutes) instead of a fixed formula.
- **Queue idle state** -- dashboard shows "Queue idle — waiting for cron trigger" when no batch has run in the last 2 minutes.

### Changed

- **Tested up to** bumped to WordPress 6.9.

## [0.3.0] - 2026-03-24

### Fixed

- **Critical: Action Scheduler args wrapping** -- batch and retry actions failed with "Argument #1 must be of type array, string given". Fixed in all 6 callers (QueueController, RuleChangeHandler, SeedCommand, AdminPage).
- **Critical: Memory threshold too low** -- `process_batch` aborted immediately before processing any posts because the absolute 28MB threshold was below WordPress baseline (~51MB). Changed to relative threshold (32MB headroom from PHP memory limit).
- **Critical: Queue not progressing** -- batches only processed once per trigger with no continuation. Added self-chaining: after each batch, checks for remaining pending posts and schedules the next batch (5s delay, 30s if memory-aborted).

### Changed

- **Queue processing strategy** -- replaced self-chaining batches with a recurring Action Scheduler action (every 60s). More reliable, doesn't depend on each batch succeeding to schedule the next one. Auto-stops when queue is empty.
- **Queue post links** -- post titles in queue table, performance log, and dashboard overview now link to the frontend post URL, opening in a new tab.
- **Default batch size** -- reduced from 100 to 25 posts per batch in Installer defaults.

### Added

- **WP-Cron health notice** -- admin page and health endpoint warn when `DISABLE_WP_CRON` is not set and queue has pending items, recommending a system cron for reliable processing.
- **Translations** -- Spanish (es_ES), Portuguese (pt_BR), French (fr_FR), German (de_DE), Japanese (ja) with compiled .mo files.
- **Color-coded type badges** -- dashboard widget shows rule types with colored badges (internal/entity/affiliate).

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

[0.4.1]: https://github.com/ctala/Lean-Auto-Links/releases/tag/v0.4.1
[0.4.0]: https://github.com/ctala/Lean-Auto-Links/releases/tag/v0.4.0
[0.3.2]: https://github.com/ctala/Lean-Auto-Links/releases/tag/v0.3.2
[0.3.1]: https://github.com/ctala/Lean-Auto-Links/releases/tag/v0.3.1
[0.3.0]: https://github.com/ctala/Wordpress-Lean-Auto-Links/releases/tag/v0.3.0
[0.2.0]: https://github.com/ctala/Wordpress-Lean-Auto-Links/releases/tag/v0.2.0
[0.1.0]: https://github.com/ctala/Wordpress-Lean-Auto-Links/releases/tag/v0.1.0
