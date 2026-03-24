# ADR-003: AI and Agent-First Architecture

**Status:** Accepted
**Date:** 2026-03-24
**Decision Maker:** Estratega

---

## 1. Decision

LeanWeave is designed API-first and agent-first from day one. Every feature is accessible via REST API before it is accessible via UI. The admin UI is a consumer of the same API that external agents use.

---

## 2. Context

Existing internal linking plugins (Link Whisper, ILJ, Rank Math) were built for manual human operation:
- Configuration via WordPress admin UI only
- No public REST API
- No programmatic access to rules, queue, or results
- No way for an external system to monitor or control the plugin

LeanWeave targets a different era where:
- AI agents manage content pipelines
- External systems need to create/update linking rules programmatically
- Monitoring and observability are expected via API
- Bulk operations are triggered by automated workflows, not manual clicks

---

## 3. Design Principles

### 3.1 API Parity
Every action available in the admin UI must be available via REST API.
No feature is UI-only. The UI is built on top of the API.

### 3.2 Structured Responses
All API responses return structured JSON that an agent can parse without heuristics:
- Consistent error format: `{code, message, data}`
- Pagination via headers: `X-WP-Total`, `X-WP-TotalPages`
- Status enums with documented values (not free-text)
- Statistics with exact numeric values (not "approximately")

### 3.3 Observable State
An agent can always know what the plugin is doing:
- `GET /health` - overall system status
- `GET /queue` - what is being processed and what is pending
- `GET /applied/stats` - what has been done
- `GET /performance/summary` - how well it is performing
- `GET /cache/stats` - cache health

### 3.4 Bulk Operations
Agents operate at scale, not one-by-one:
- `POST /rules/import` - create hundreds of rules in one call
- `POST /queue/bulk` - trigger processing of all posts
- `POST /cache/flush` - reset cache state
- `POST /cache/warm` - pre-populate cache

### 3.5 Idempotent and Safe
Agents may retry operations. The API must handle this gracefully:
- Creating a rule with the same keyword is safe (upsert behavior)
- Enqueueing an already-enqueued post updates priority (ON DUPLICATE KEY UPDATE)
- Flushing cache when cache is empty is a no-op
- Health endpoint is always safe to call at any frequency

---

## 4. Agent Workflow Examples

### Example 1: Glossary Sync Agent
An agent that syncs glossary terms from a CMS to LeanWeave rules:
```
1. GET /rules?rule_type=internal -> get current glossary rules
2. Compare with CMS glossary terms
3. POST /rules (for new terms)
4. PUT /rules/{id} (for updated terms)
5. DELETE /rules/{id} (for removed terms)
6. POST /queue/bulk -> reprocess affected posts
7. Poll GET /queue until pending=0
8. GET /applied/stats -> report results
```

### Example 2: Content Pipeline Agent
An agent that publishes content and ensures links are applied:
```
1. Publish post via WordPress REST API
2. Wait for save_post hook to enqueue (automatic)
3. GET /queue/{post_id} -> monitor until status=done
4. GET /applied?post_id={id} -> verify links were applied
5. If links_count=0, check GET /health for issues
```

### Example 3: Performance Monitoring Agent
An agent that monitors LeanWeave health:
```
1. GET /health (every 5 minutes)
2. If cache.hit_rate < 0.8 -> POST /cache/warm
3. If queue.failed > 10 -> POST /queue/retry
4. If queue.pending > 5000 -> alert operator
5. GET /performance/summary -> log metrics to dashboard
```

### Example 4: Affiliate Campaign Manager
An agent that manages affiliate linking campaigns:
```
1. POST /rules/import -> batch create affiliate rules from campaign data
2. POST /queue/bulk -> process all posts with new affiliate rules
3. GET /applied/stats -> report: how many posts now have affiliate links
4. After campaign ends: DELETE /rules/{id} for each campaign rule
5. POST /queue/bulk -> reprocess to remove old affiliate links
```

---

## 5. Competitive Advantage

This architecture creates a structural moat:

| Capability | LeanWeave | Link Whisper | ILJ | Rank Math |
|-----------|-----------|-------------|-----|-----------|
| REST API | 26 endpoints | None | None | Limited (not for linking) |
| Bulk rule import | Yes (JSON) | No | CSV only (manual upload) | No |
| Queue monitoring | Yes (real-time) | No | No | No |
| Health endpoint | Yes | No | No | No |
| Agent integration | Native | Impossible | Impossible | Partial |
| Programmatic control | Full | None | None | Limited |

Competitors would need to rebuild their architecture from scratch to match this.
Their plugins were designed for humans clicking buttons.
LeanWeave was designed for agents making API calls AND humans clicking buttons.

---

## 6. Consequences

- OpenAPI spec (`openapi.yaml`) is the source of truth and must be updated before any endpoint change
- Admin UI must consume the REST API (via AJAX/fetch), not bypass it with direct DB calls
- Every new feature must have an API endpoint before it gets a UI element
- Documentation must include agent workflow examples, not just human instructions
- The README includes a section specifically for "Agent Integration"
