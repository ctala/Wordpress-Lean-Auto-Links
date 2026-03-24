=== LeanAutoLinks - Automated Internal Linking for High-Volume Sites ===
Contributors: cristiantala
Tags: internal links, seo, link building, affiliate, performance
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 8.1
Stable tag: 0.3.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: leanautolinks

Automated internal linking that scales to 25,000+ posts with zero frontend impact. Background processing, REST API, AI-ready.

== Description ==

**LeanAutoLinks** is a lean, API-first WordPress plugin that automates internal linking for high-volume sites. Define keyword-to-URL rules once, and LeanAutoLinks inserts links across your entire content library in the background -- with zero impact on page load times.

Built for sites with 15,000+ posts growing at hundreds per week, LeanAutoLinks handles scale that breaks other internal linking plugins.

= How It Works =

1. **Define rules**: Map keywords to target URLs (internal pages, glossary terms, entity pages, or affiliate links).
2. **Background processing**: When a post is saved, LeanAutoLinks queues it for processing via Action Scheduler. Links are computed in the background -- your editors never wait.
3. **Pre-computed serving**: On page load, pre-computed links are served from cache. Zero additional database queries. Zero JavaScript. Zero performance cost.

= Key Features =

* **Rule-based linking engine** -- Define keywords, target URLs, priority, and limits per post. Supports exact match, case-sensitive matching, and configurable max links per post.
* **Three link types** -- Internal links, entity links (glossary terms, companies, people), and affiliate links with automatic `rel="sponsored nofollow"` attributes.
* **Background processing** -- All link computation happens asynchronously via Action Scheduler. Saving a post adds only 1.2ms of overhead.
* **Zero frontend impact** -- Pre-computed links are served in under 1ms. No additional database queries on page load when object cache is available.
* **Full REST API** -- 17 endpoints covering rules, queue, applied links, exclusions, performance logs, and health checks. Build dashboards, integrate with AI agents, or automate your entire linking strategy programmatically.
* **Content-aware parser** -- Never inserts links inside headings (h1-h6), existing links, code blocks, pre-formatted text, or script tags.
* **Exclusion system** -- Exclude specific posts, URLs, keywords, or entire post types from link processing.
* **Admin dashboard** -- Five-tab interface for managing rules, monitoring the queue, reviewing applied links, configuring exclusions, and adjusting settings.
* **WP-CLI commands** -- Bulk process, seed test data, and manage rules from the command line.
* **Performance logging** -- Built-in metrics tracking for processing duration, memory usage, rules checked, and links applied.

= Performance at Scale =

LeanAutoLinks was benchmarked on a live dataset of 25,394 posts with 687 active rules:

* **save_post overhead**: 1.2ms (threshold: < 50ms)
* **Engine processing**: 58ms median with 1,074 rules (threshold: < 500ms)
* **Bulk processing 15K posts**: ~17 minutes (threshold: < 4 hours)
* **Throughput**: 52,103 posts per hour sustained
* **Frontend serving**: 1ms median for pre-computed links
* **Additional memory per request**: 0 MB

= Why LeanAutoLinks Instead of Other Plugins? =

* **Full REST API**: 17 endpoints for programmatic access. Competitors offer zero API endpoints.
* **True background processing**: Links are computed asynchronously. Competitors process on page load (slowing your site) or require manual triggers.
* **Scales to 25K+ posts**: Tested and benchmarked at scale. Some competitors fail to build their link index beyond a few thousand posts.
* **AI and agent-first architecture**: Designed from the ground up for integration with AI workflows, automation pipelines, and external tools.
* **Zero frontend queries**: Pre-computed links mean your visitors never pay a performance penalty for internal linking.

= Use Cases =

* **Content-heavy publishers** with thousands of articles that need consistent internal linking
* **Knowledge bases and glossaries** where terms should automatically link to their definition pages
* **Startup ecosystems and directories** linking mentions of companies, people, and organizations to their profile pages
* **Affiliate marketers** who need automated, compliant affiliate link insertion with proper rel attributes
* **SEO teams** managing internal link architecture at scale through API-driven workflows

== Installation ==

= Minimum Requirements =

* WordPress 6.0 or higher
* PHP 8.1 or higher
* MySQL 5.7 or higher

= Recommended =

* Redis or Memcached object cache for zero-query frontend serving
* Action Scheduler (bundled or via WooCommerce) for background processing

= Installation Steps =

1. Upload the `leanautolinks` folder to the `/wp-content/plugins/` directory, or install directly through the WordPress plugin screen.
2. Activate the plugin through the "Plugins" screen in WordPress.
3. Navigate to **LeanAutoLinks** in the admin menu to access the dashboard.
4. Create your first linking rule: define a keyword and target URL.
5. Save or update any post -- LeanAutoLinks will automatically queue it for link processing.

= API Access =

To use the REST API, generate an Application Password in your WordPress user profile. All endpoints are available under `/wp-json/leanautolinks/v1/`.

== Frequently Asked Questions ==

= Does LeanAutoLinks slow down my site? =

No. LeanAutoLinks processes links in the background using Action Scheduler. On page load, pre-computed links are served from cache in under 1ms with zero additional database queries (when an object cache like Redis or Memcached is available). The plugin was designed with an absolute principle: it must never cause a site to load slower.

= How many posts can LeanAutoLinks handle? =

LeanAutoLinks has been tested with over 25,000 posts and 1,000+ active linking rules. Bulk processing 15,000 posts completes in approximately 17 minutes. The architecture supports scaling to 50,000+ posts without degradation.

= Will LeanAutoLinks insert links inside headings or existing links? =

No. The content parser is aware of HTML structure and will never insert links inside h1-h6 headings, existing anchor tags, code blocks, pre-formatted text, or script tags. This prevents broken HTML and preserves your content structure.

= How are affiliate links handled? =

Affiliate link rules automatically receive `rel="sponsored nofollow"` attributes, ensuring compliance with Google's link spam policies. You can configure each rule individually for nofollow and sponsored attributes.

= Can I control how many links are inserted per post? =

Yes. Each rule has a `max_per_post` setting (default: 1) that limits how many times that keyword is linked within a single post. Rules also have priority levels, so higher-priority rules are processed first when multiple rules match the same content.

= Does LeanAutoLinks work with custom post types? =

Yes. LeanAutoLinks processes standard posts by default and can be configured to work with any custom post type. You can also exclude specific post types entirely through the exclusion system.

= Is there a REST API? =

Yes. LeanAutoLinks provides 17 REST API endpoints covering rules management, queue operations, applied link queries, exclusions, performance metrics, and health checks. Full documentation is available in the bundled OpenAPI specification file.

= Can I import rules in bulk? =

Yes. Use the `POST /wp-json/leanautolinks/v1/rules/import` endpoint to import rules in bulk via the API, or use the WP-CLI commands for command-line bulk operations.

== Screenshots ==

1. **Dashboard** -- Overview of linking activity, queue status, and performance metrics at a glance.
2. **Rules Management** -- Create and manage keyword-to-URL linking rules with priority, type, and attribute controls.
3. **Queue Monitor** -- Real-time view of background processing status, pending posts, and job history.
4. **Applied Links** -- Review all links inserted across your site, filterable by post or rule.
5. **Exclusions** -- Manage excluded posts, URLs, keywords, and post types.
6. **Settings** -- Configure processing options, cache behavior, and default link attributes.

== Changelog ==

= 0.1.0 =
* Initial release.
* Rule-based linking engine with support for internal, entity, and affiliate link types.
* Background processing via Action Scheduler with configurable batch sizes.
* Pre-computed link serving with object cache integration.
* Content-aware parser that respects HTML structure (headings, existing links, code blocks).
* Full REST API with 17 endpoints and OpenAPI specification.
* Admin dashboard with five tabs: Dashboard, Rules, Queue, Exclusions, Settings.
* WP-CLI commands for bulk processing and data seeding.
* Exclusion system for posts, URLs, keywords, and post types.
* Performance logging with duration, memory, and throughput tracking.
* Automatic `rel="sponsored nofollow"` for affiliate link rules.

== Upgrade Notice ==

= 0.1.0 =
Initial release. Install and configure your first linking rules to start building automated internal links across your site.
