# LeanAutoLinks - Agent Integration Guide

> Everything an AI agent needs to manage internal linking at scale via the REST API.

## Quick Reference

| What | Value |
|---|---|
| Base URL | `/wp-json/leanautolinks/v1/` |
| Auth | HTTP Basic with WordPress Application Passwords |
| Required capability | `manage_options` |
| Content-Type | `application/json` |
| Pagination | `page` & `per_page` params; `X-WP-Total` / `X-WP-TotalPages` headers |
| Rate limit | None (server-limited) |
| OpenAPI spec | `openapi.yaml` in repo root |

## Authentication

```bash
# Generate an Application Password at: WP Admin > Users > Profile > Application Passwords
# Use it as:
curl -u "admin:xxxx xxxx xxxx xxxx xxxx xxxx" \
  https://yoursite.com/wp-json/leanautolinks/v1/health
```

All API calls require authentication. Unauthenticated requests return `401`.

---

## Core Concepts

### Rules
A **rule** maps a keyword to a target URL. When the engine processes a post, it scans content for keyword matches and inserts links to the target URL.

| Field | Type | Description |
|---|---|---|
| `keyword` | string | Text to match in post content (required, must be unique across all rules) |
| `target_url` | string | URL to link to (required) |
| `rule_type` | enum | `internal`, `affiliate`, or `entity` (see Rule Types below) |
| `entity_type` | string | For entity rules: `glossary`, `actor`, `company`, `vc`, `person` |
| `entity_id` | int | WordPress post ID of the entity (optional) |
| `priority` | int 1-100 | Lower = processed first. Default: 10 |
| `max_per_post` | int 1-10 | Max times this keyword is linked per post. Default: 1 |
| `case_sensitive` | bool | Whether matching is case-sensitive. Default: false |
| `nofollow` | bool | Add `rel="nofollow"`. Default: false |
| `sponsored` | bool | Add `rel="sponsored"`. Default: false. Auto-set for affiliate rules |
| `is_active` | bool | Whether rule is active. Default: true |

### Rule Types — When to Use Each

| Type | Use Case | HTML Output | `rel` attribute | Extra Fields |
|---|---|---|---|---|
| `internal` | Regular post-to-post or post-to-page links | `<a href="/target/">keyword</a>` | none | — |
| `entity` | Links to CPT entries (glossary, actors, companies, VCs) | `<a href="/target/">keyword</a>` | none | `entity_type`, `entity_id` |
| `affiliate` | External monetized/referral links | `<a href="https://..." rel="sponsored nofollow">keyword</a>` | `sponsored nofollow` (always) | — |

**`internal`** — The default. Use for any link between regular WordPress content: posts linking to other posts, pages, category archives, etc.

```json
{"rule_type": "internal", "keyword": "startup", "target_url": "/que-es-una-startup/"}
```

**`entity`** — Use when the target is a Custom Post Type (CPT) entry representing a real-world entity. The `entity_type` and `entity_id` fields enable entity-aware operations:
- Query all rules linked to a specific entity type: `GET /rules?rule_type=entity&entity_type=glossary`
- Sync rules from a CPT programmatically (e.g., when a new glossary term is created, auto-create a rule)
- Bulk manage rules by entity category

```json
{
  "rule_type": "entity",
  "keyword": "Y Combinator",
  "target_url": "/actores/y-combinator/",
  "entity_type": "company",
  "entity_id": 42
}
```

Common `entity_type` values: `glossary`, `actor`, `company`, `vc`, `person`, `product` (convention, not enforced — any string works).

**`affiliate`** — Use for any external link that generates revenue. The `rel="sponsored nofollow"` attribute is **always** added automatically, regardless of the `nofollow`/`sponsored` field values. This ensures compliance with Google's link spam policies.

```json
{"rule_type": "affiliate", "keyword": "Notion", "target_url": "https://affiliate.example.com/notion?ref=site"}
```

**Decision flowchart for agents:**
```
Is the link external and monetized? → affiliate
Is the target a CPT entry (actor, glossary, company)? → entity
Everything else → internal
```

**Important:** The same keyword cannot be used in two different rules. API returns HTTP 409 if a duplicate keyword is detected. Self-linking is automatically prevented (a post about "startup" at /que-es-una-startup/ won't link "startup" to itself).

### Queue
Posts are processed asynchronously. When a post is saved or a bulk reprocess is triggered, post IDs are added to the **queue**. Action Scheduler picks them up in the background.

| Status | Meaning |
|---|---|
| `pending` | Waiting to be processed |
| `processing` | Currently being processed by a worker |
| `done` | Successfully processed |
| `failed` | Processing failed (will retry up to 3 times) |

### Applied Links
After processing, the engine records which links were inserted in `lw_applied_links`. This allows agents to audit exactly what was linked, where, and when.

### Exclusions
Prevent linking in specific contexts:

| Type | Example | Effect |
|---|---|---|
| `post` | `12345` | Skip this specific post ID |
| `url` | `/contact` | Skip posts matching this URL pattern |
| `keyword` | `login` | Never link this keyword |
| `post_type` | `page` | Skip all pages |

---

## API Endpoints

### Rules CRUD

```bash
# List rules (paginated, filterable)
GET /rules
GET /rules?rule_type=affiliate&is_active=1&per_page=100&page=1

# Create a rule
POST /rules
{
  "rule_type": "internal",
  "keyword": "machine learning",
  "target_url": "/glosario/machine-learning/",
  "priority": 5,
  "max_per_post": 1
}

# Get a single rule
GET /rules/{id}

# Update a rule
PUT /rules/{id}
{
  "keyword": "ML",
  "target_url": "/glosario/machine-learning/",
  "priority": 3
}

# Toggle active/inactive
PATCH /rules/{id}/toggle

# Delete a rule
DELETE /rules/{id}

# Bulk import rules
POST /rules/import
{
  "rules": [
    {"rule_type": "entity", "keyword": "Y Combinator", "target_url": "/actores/y-combinator/", "entity_type": "company"},
    {"rule_type": "affiliate", "keyword": "AWS", "target_url": "https://aws.amazon.com/?ref=yoursite"}
  ]
}
# Response: {"imported": 2, "total": 2, "errors": []}
```

### Queue Management

```bash
# View queue status (paginated)
GET /queue
GET /queue?status=failed&per_page=50

# Enqueue posts for bulk processing
POST /queue/bulk
{
  "post_type": "post",      # optional: filter by post type
  "date_after": "2026-03-01", # optional: only posts after this date
  "limit": 5000              # optional: cap number of posts enqueued
}

# Retry failed items
POST /queue/retry

# Clear completed items
DELETE /queue/clear-done

# Check specific post queue status
GET /queue/{post_id}
```

### Applied Links

```bash
# Get links applied to a specific post
GET /applied?post_id=12345
# Response: [{"rule_id": 42, "keyword": "IA", "target_url": "/glosario/ia/", "applied_at": "2026-03-24T10:30:00"}]

# Get links generated by a specific rule
GET /applied?rule_id=42

# Get aggregate statistics
GET /applied/stats
# Response: {"total_links": 24521, "total_posts_linked": 8943, "avg_links_per_post": 2.7, "top_rules": [...]}
```

### Exclusions

```bash
# List exclusions
GET /exclusions

# Add an exclusion
POST /exclusions
{"type": "post", "value": "12345"}

# Delete an exclusion
DELETE /exclusions/{id}
```

### Health & Performance

```bash
# Health check (use this for monitoring)
GET /health
# Response:
{
  "status": "healthy",        # healthy | warning | critical
  "queue": {"pending": 12, "processing": 3, "failed": 0},
  "cache": {"hit_rate": 0.94},
  "action_scheduler": "available",
  "rules_count": 687,
  "applied_links_count": 24521
}

# Performance summary
GET /performance/summary
# Response:
{
  "avg_duration_ms": 58,
  "p95_duration_ms": 142,
  "avg_memory_kb": 4200,
  "total_processed_24h": 1847,
  "failed_24h": 0,
  "avg_links_per_post": 3.2
}

# Detailed performance log
GET /performance/log?per_page=100
```

---

## Agent Workflows

### 1. Glossary/Entity Sync

When new terms are added to a glossary, taxonomy, or CRM:

```
1. GET existing rules filtered by entity_type
2. Compare with source system (glossary CPT, CRM, etc.)
3. POST new rules for terms that don't have rules yet
4. DELETE rules for terms that were removed
5. POST /queue/bulk to reprocess affected posts
6. Poll GET /health until queue.pending = 0
```

### 2. Content Pipeline (post-publish verification)

After an agent publishes posts programmatically:

```
1. Publish post via WordPress REST API
2. Wait for save_post hook to enqueue (automatic, ~2ms)
3. Poll GET /queue/{post_id} until status = "done"
4. GET /applied?post_id={id} to verify links were applied
5. If no links applied and post has >200 chars: investigate rules
```

### 3. Affiliate Campaign Management

When onboarding or updating affiliate partners:

```
1. POST /rules/import with affiliate rules from spreadsheet/CRM
2. POST /queue/bulk to reprocess all posts
3. Monitor GET /applied/stats for affiliate link counts
4. Periodic: GET /rules?rule_type=affiliate to audit active affiliates
```

### 4. Performance Monitoring Loop

Run periodically (every 5-15 minutes during active processing):

```
1. GET /health
2. If queue.failed > 0: POST /queue/retry
3. If status != "healthy": alert human operator
4. GET /performance/summary
5. If p95_duration_ms > 500: investigate (too many rules? content too long?)
```

### 5. Rule Deduplication & Cleanup

Periodic maintenance:

```
1. GET /rules?per_page=100 (paginate through all)
2. Detect duplicate keywords pointing to different URLs
3. Detect rules with 0 applied links (unused rules)
4. DELETE or deactivate stale rules
5. POST /queue/bulk to reprocess after cleanup
```

---

## Decision Tree for Agents

```
Agent needs to add internal links?
|
+-- Is it a new keyword/entity?
|   YES --> POST /rules (create rule)
|           POST /queue/bulk (reprocess relevant posts)
|
+-- Is it an affiliate link?
|   YES --> POST /rules with rule_type="affiliate"
|           (nofollow + sponsored are auto-applied)
|
+-- Need to check if linking is working?
|   YES --> GET /health (overall status)
|           GET /applied?post_id=X (specific post)
|           GET /applied/stats (aggregate)
|
+-- Processing seems slow or stuck?
|   YES --> GET /performance/summary (check p95)
|           GET /queue?status=failed (check failures)
|           POST /queue/retry (retry failed items)
|
+-- Need to prevent linking somewhere?
    YES --> POST /exclusions
            (type: post|url|keyword|post_type)
```

---

## Error Handling

All errors return consistent JSON:

```json
{
  "code": "error_code",
  "message": "Human-readable message",
  "data": {"status": 400}
}
```

| HTTP Status | Meaning | Agent Action |
|---|---|---|
| 200 | Success | Process response |
| 201 | Created | Extract `id` from response |
| 400 | Bad request | Fix parameters and retry |
| 401 | Unauthorized | Check Application Password |
| 403 | Forbidden | User lacks `manage_options` capability |
| 404 | Not found | Resource doesn't exist |
| 500 | Server error | Retry with exponential backoff |

---

## Rate & Capacity Guidelines

| Operation | Expected Throughput |
|---|---|
| Rule CRUD | ~100 req/s (limited by MySQL) |
| Rule import | ~500 rules/batch |
| Queue bulk enqueue | ~15,000 posts/request |
| Background processing | ~52,000 posts/hour |
| Applied links query | ~200 req/s (cached) |
| Health check | ~500 req/s (lightweight) |

---

## MCP Server Integration

LeanAutoLinks's REST API can be exposed as an MCP (Model Context Protocol) server for direct integration with Claude and other LLM agents. Each API endpoint maps naturally to an MCP tool:

| MCP Tool | API Endpoint | Description |
|---|---|---|
| `create_linking_rule` | `POST /rules` | Create a new keyword-to-URL rule |
| `list_rules` | `GET /rules` | List and filter existing rules |
| `import_rules` | `POST /rules/import` | Bulk import from JSON |
| `enqueue_posts` | `POST /queue/bulk` | Trigger background reprocessing |
| `check_health` | `GET /health` | Monitor plugin status |
| `get_applied_links` | `GET /applied` | Audit links on a post |
| `get_stats` | `GET /applied/stats` | Get linking statistics |
| `add_exclusion` | `POST /exclusions` | Exclude posts/URLs/keywords |

---

## WP-CLI Commands

For agents with shell access:

```bash
# Seed test data
wp leanautolinks seed --posts=15000 --actors=500 --glossary=500 --affiliates=100

# Bulk reprocess all posts
wp leanautolinks reprocess --all

# Reprocess specific post types
wp leanautolinks reprocess --post-type=post --limit=5000

# Clear and rebuild cache
wp leanautolinks cache clear
wp leanautolinks cache warm

# Run benchmark
wp leanautolinks benchmark --posts=1000 --rules=500

# Export rules to JSON
wp leanautolinks rules export > rules.json

# Import rules from JSON
wp leanautolinks rules import < rules.json
```
