# LeanWeave SEO Matching Algorithm & Linking Strategy

**Version:** 1.0
**Date:** 2026-03-24
**Author:** SEO Engineering Team
**Target Site:** ecosistemastartup.com (Spanish-language, startup/IA/emprendimiento vertical)

---

## Table of Contents

1. [Matching Algorithm Design](#1-matching-algorithm-design)
2. [Linking Density Rules](#2-linking-density-rules)
3. [Element Exclusion Rules](#3-element-exclusion-rules)
4. [Rule Type Strategy](#4-rule-type-strategy)
5. [Default Configuration Values](#5-default-configuration-values)
6. [Content Parser Rules](#6-content-parser-rules)
7. [WordPress.org Listing Optimization](#7-wordpressorg-listing-optimization)

---

## 1. Matching Algorithm Design

### 1.1 Keyword Matching Modes

LeanWeave supports three matching modes, configurable per rule:

#### Exact Match (Default for Glossary and Entity rules)

Matches the keyword as a complete phrase with word boundaries on both sides.

**Regex pattern:**
```
/(?<=[\\s.,;:!?()\\[\\]\"\'\\-]|^){keyword}(?=[\\s.,;:!?()\\[\\]\"\'\\-]|$)/ui
```

**Rationale:** Word boundary assertions (`\b`) in PCRE do not reliably handle Unicode characters such as accented vowels and the letter "n with tilde." Using explicit boundary character classes ensures correct matching for Spanish text.

**Examples:**
- Rule keyword: `inteligencia artificial`
- MATCHES: `La inteligencia artificial transforma...`
- MATCHES: `...sobre inteligencia artificial.`
- DOES NOT MATCH: `superinteligencia artificial` (no left boundary)
- DOES NOT MATCH: `inteligencia artificialmente` (no right boundary)

#### Partial Match (Available as option, discouraged for SEO)

Matches the keyword anywhere in the text, including within other words. This mode exists for edge cases but is NOT recommended because it produces unnatural anchor text that degrades user experience and search quality signals.

#### Stemmed Match (Future consideration, not in v1)

Reserved for a future release. Would match morphological variants (e.g., `emprender` matching `emprendimiento`, `emprendedor`). Excluded from v1 due to complexity of Spanish verb conjugation and the risk of incorrect anchor text.

### 1.2 Case Sensitivity Rules

**All matching is case-insensitive by default.**

The `u` (Unicode) and `i` (case-insensitive) flags are mandatory on every matching regex.

#### Spanish-Specific Accent Handling

LeanWeave implements **accent-aware matching** as follows:

| Scenario | Behavior | Example |
|---|---|---|
| Rule has accent, text has accent | Match | Rule: `inversion` text: `inversion` -- matches |
| Rule has accent, text lacks accent | Match | Rule: `inversion` text: `inversion` -- matches |
| Rule lacks accent, text has accent | Match | Rule: `inversion` text: `inversion` -- matches |
| Rule lacks accent, text lacks accent | Match | Rule: `inversion` text: `inversion` -- matches |

**Implementation:** Before matching, normalize both the rule keyword and the target text to NFD (Canonical Decomposition) form, strip combining diacritical marks (Unicode range `\x{0300}-\x{036f}`), and perform the match on the stripped versions. When a match is found, use the **original text** (with its original accents) as the visible anchor text.

**Critical rule:** The displayed anchor text always preserves the original casing and accents from the source content. Only the matching logic is accent-insensitive.

#### The "n with tilde" Exception

The letter `n with tilde` is a distinct letter in the Spanish alphabet, not an accented `n`. Matching must treat `n` and `n with tilde` as **different characters**.

- Rule: `ano` must NOT match `ano` (these are entirely different words: "year" vs "anus")
- Rule: `espanol` must NOT match `espanol`

**Implementation:** During NFD normalization, preserve the combining tilde on `n` (U+0303) while stripping other combining marks. This requires a targeted exclusion in the diacritical stripping regex.

### 1.3 Priority System for Conflicting Rules

When multiple rules match the same text span, LeanWeave resolves conflicts using a deterministic priority cascade:

#### Priority Cascade (highest to lowest)

```
1. User-defined priority (integer 1-100, lower number = higher priority)
2. Rule type priority:
   a. Affiliate rules (priority weight: 100) -- revenue-generating
   b. Entity rules (priority weight: 200) -- topical authority building
   c. Glossary rules (priority weight: 300) -- informational linking
3. Keyword length (longer keyword wins)
4. Rule creation date (older rule wins -- first-mover stability)
```

**Justification for affiliate-first default:**
Affiliate rules have explicit revenue impact and are typically fewer in number (100 rules vs 1,000+ total). Giving them top priority ensures revenue-critical keywords are never overridden by glossary or entity links. Site owners can override this per-rule using the `priority` integer field.

**Justification for longer-keyword-wins:**
When `inteligencia artificial` and `inteligencia` both match, the longer phrase should win because:
- It produces more specific, semantically richer anchor text
- It prevents the shorter keyword from "stealing" matches that belong to a more precise rule
- Google values descriptive anchor text that clearly signals the target page content

### 1.4 Overlapping Keyword Resolution

Overlapping keywords are the most complex matching scenario. LeanWeave handles them with a **greedy left-to-right, longest-match-first** algorithm:

#### Algorithm Steps

```
1. Build a list of all potential matches with their positions in the text
2. Sort matches by: start position (ascending), then length (descending)
3. Iterate through sorted matches:
   a. If the match does not overlap with any already-accepted match, accept it
   b. If it overlaps, apply the priority cascade to choose the winner
   c. The loser is discarded for THIS text span (may match elsewhere in the post)
4. Apply density limits (Section 2) to the accepted match list
```

#### Overlap Example

Text: `La inteligencia artificial generativa cambia la inteligencia de negocios`

Rules:
- Rule A: `inteligencia artificial` -> /glosario/inteligencia-artificial/
- Rule B: `inteligencia` -> /glosario/inteligencia/
- Rule C: `artificial` -> /glosario/artificial/
- Rule D: `inteligencia artificial generativa` -> /glosario/ia-generativa/

Resolution:
1. Position 3: Rule D matches `inteligencia artificial generativa` (longest, wins)
2. Position 3: Rules A, B, C overlap with Rule D -- discarded at this position
3. Position 48: Rule B matches `inteligencia` (only match at this position, accepted)

Result: Two links inserted, each with distinct target URLs.

### 1.5 Keyword Index Architecture

To support 1,000+ rules against 15,000+ posts efficiently:

```
keyword_index = {
    "first_two_chars" => [
        { rule_id, keyword, keyword_normalized, length, priority, type, target_url }
    ]
}
```

**Prefix bucketing** on the first two characters of the normalized keyword reduces comparison operations. For a typical 500-word post, this cuts matching from O(n*m) where m=1000 rules to approximately O(n*k) where k is the average bucket size (typically 20-40 rules per bucket).

The full index is stored in a single WordPress transient (serialized), rebuilt on rule CRUD operations, and served from object cache (Redis/Memcached) when available.

---

## 2. Linking Density Rules

### 2.1 Maximum Internal Links Per Post

| Setting | Default Value | Range | SEO Justification |
|---|---|---|---|
| `max_links_per_post` | **10** | 1-50 | Google's John Mueller has consistently stated there is no hard limit but to "keep it reasonable." Analysis of top-ranking pages in the startup/tech vertical shows 5-15 internal links per post as the norm. 10 is a conservative default that avoids over-optimization signals while distributing link equity effectively across a 15K+ post corpus. |
| `max_links_per_1000_words` | **5** | 1-15 | This ratio ensures natural link density. A 2,000-word article gets up to 10 links; a 500-word news brief gets up to 2-3. This prevents short posts from becoming link-stuffed while allowing long-form content adequate internal linking. |

### 2.2 Per-Keyword Limits

| Setting | Default Value | Range | SEO Justification |
|---|---|---|---|
| `max_per_keyword_per_post` | **1** | 1-5 | Linking the same keyword multiple times within a single post provides zero additional SEO value (Google only counts the first link's anchor text between two pages) and degrades reading experience. One link per keyword per post is the industry standard. |
| `max_per_target_per_post` | **1** | 1-3 | Even with different anchor text, linking to the same target URL multiple times in one post wastes link slots. One link per destination per post maximizes the breadth of internal link distribution. |
| `max_keyword_links_sitewide` | **unlimited** (no cap) | 1-unlimited | Unlike per-post limits, sitewide repetition is beneficial. If `inteligencia artificial` appears in 500 posts, linking it in all 500 posts sends a strong topical authority signal to the glossary page. There is no SEO reason to limit this. |

### 2.3 Minimum Content Length

| Setting | Default Value | Range | SEO Justification |
|---|---|---|---|
| `min_content_length` | **200 characters** (~35-40 words) | 0-2000 | Content under 200 characters is typically too short for contextual linking to be natural. This filters out stubs, image captions stored as posts, and auto-generated excerpts. Measured in characters (not words) for faster evaluation without tokenization. |

### 2.4 Link Distribution Strategy

Beyond raw limits, LeanWeave implements **even distribution** across the post body:

```
Algorithm: Spread Selection
1. After identifying all valid match positions, divide the post into N zones
   (where N = max_links_per_post)
2. Select at most one link per zone, preferring higher-priority rules
3. This prevents link clustering in the first paragraph (a common anti-pattern
   in automated linking tools)
```

**SEO Rationale:** Google evaluates link context. Links distributed throughout the content body provide more contextual signals than links clustered at the top. Users also engage more naturally with links that appear near relevant discussion points rather than in a dense cluster.

---

## 3. Element Exclusion Rules

### 3.1 HTML Elements That Must NEVER Contain Auto-Links

These exclusions are non-negotiable and cannot be overridden by configuration:

| Element | Reason |
|---|---|
| `<a>` (existing links) | Nesting anchors inside anchors is invalid HTML and causes unpredictable browser behavior. |
| `<h1>`, `<h2>`, `<h3>`, `<h4>`, `<h5>`, `<h6>` | Headings carry significant ranking weight for the current page. Linking keywords inside headings dilutes that on-page signal and redirects authority to the target page. Google's SEO Starter Guide advises keeping heading text focused on page topic. |
| `<code>`, `<pre>`, `<kbd>`, `<samp>` | Code blocks contain technical syntax where linking would break meaning and confuse readers. |
| `<script>`, `<style>`, `<noscript>` | Non-visible content. Linking here serves no user and risks appearing as hidden link manipulation. |
| `<button>`, `<input>`, `<select>`, `<textarea>`, `<label>` | Form elements. Links inside form controls cause accessibility and UX failures. |
| `<img>` (alt attributes), `<video>`, `<audio>`, `<source>` | Media elements where injecting anchor tags is either impossible or semantically wrong. |
| `<figcaption>` | Captions are metadata about images. Auto-linking here feels unnatural and is a signal of automated manipulation. |
| `<blockquote>`, `<cite>` | Quoted text should remain faithful to the original source. Modifying quotes with links misrepresents the source content. |
| `<title>`, `<meta>` | Document head elements. No user-visible links possible. |
| `<table>` headers (`<th>`) | Table headers serve a structural role similar to headings. Body cells (`<td>`) are permitted. |

### 3.2 Configurable Exclusions (Defaults ON, user can disable)

| Element/Attribute | Default | Reason |
|---|---|---|
| `<caption>` | Excluded | Table captions are structural, similar to headings. |
| Elements with `class="no-auto-link"` | Excluded | Escape hatch for theme developers and content creators. |
| Elements with `data-leanweave-skip="true"` | Excluded | Plugin-specific skip attribute for granular control. |
| `.wp-block-image` containers | Excluded | Image block wrappers where linking text is typically alt-text or captions. |
| `.wp-block-embed` containers | Excluded | Embed blocks (YouTube, Twitter) should not be modified. |

### 3.3 Post Type Inclusion/Exclusion

| Post Type | Default | Rationale |
|---|---|---|
| `post` | **Included** | Primary content type, highest volume (15K+). |
| `page` | **Included** | Important for pillar page linking. |
| `glossary` (CPT) | **Excluded** | Glossary terms are link targets, not link sources. Auto-linking within glossary definitions creates circular linking patterns that dilute link equity. Glossary-to-glossary linking should be manual and editorial. |
| `entity` (CPT: actors, companies, VCs) | **Excluded** | Same rationale as glossary. Entity pages receive links; they should not auto-generate outbound links that compete with their own internal linking strategy. |
| `attachment` | **Excluded** | Media attachment pages have no meaningful content to link. |
| `revision` | **Excluded** | Draft content not visible to users or crawlers. |
| `nav_menu_item` | **Excluded** | Navigation structure, not content. |
| Custom post types (other) | **Excluded by default** | Opt-in via settings. Unknown CPTs may have structures incompatible with auto-linking. |

### 3.4 Minimum Distance Between Links

| Setting | Default Value | SEO Justification |
|---|---|---|
| `min_chars_between_links` | **100 characters** (~15-20 words) | Two links within the same sentence or adjacent sentences feel unnatural to readers and can trigger over-optimization signals. 100 characters ensures approximately one full sentence of breathing room between auto-inserted links. |
| `min_chars_from_post_start` | **0 characters** | No restriction on linking near the start. The first paragraph often contains the most important contextual keywords, and first-paragraph links carry slightly more weight in Google's link context evaluation. |
| `min_chars_from_post_end` | **0 characters** | No restriction on linking near the end. Conclusion paragraphs often summarize key topics and benefit from contextual links. |

### 3.5 Self-Link Prevention

**LeanWeave must NEVER link a post to itself.** Before inserting any link, the target URL is compared against the current post's permalink. This check uses both the canonical URL and any known URL aliases (from redirects or URL changes).

---

## 4. Rule Type Strategy

### 4.1 Internal Links: Glossary Terms

**Use case:** 500+ glossary terms defining startup, IA, and emprendimiento concepts.

| Attribute | Value | Rationale |
|---|---|---|
| Target URL pattern | `/glosario/{term-slug}/` | Clean, predictable URL structure for crawlers. |
| `rel` attribute | **(none)** | Internal links should pass full link equity. No `nofollow`, `noopener`, or `noreferrer` needed for same-domain links. |
| `target` attribute | **(none -- same tab)** | Internal links open in the same tab. Opening in new tabs for internal navigation is a UX anti-pattern that frustrates users. |
| `class` attribute | `leanweave-link leanweave-glossary` | Enables CSS styling and JS tracking without inline styles. |
| `data-leanweave-rule` | `{rule_id}` | Enables analytics tracking and debugging. |
| `title` attribute | **(omitted)** | Title attributes on links are not used as ranking signals and create tooltip clutter. Omit for cleaner HTML. |
| Anchor text | Original text from content (preserving case/accents) | Never rewrite the visible anchor text. Use whatever text the author wrote. This maintains content authenticity and E-E-A-T signals. |
| Max per post | Governed by global `max_links_per_post` | No type-specific override by default. |
| Priority weight | **300** (lowest of the three types) | Glossary links are informational. They support topical authority but are lower priority than revenue (affiliate) or entity authority building. |

#### Glossary Linking Logic

```
1. Only link to glossary terms that have published, non-empty definitions
2. Skip glossary terms where the definition page returns 404 or is in draft status
3. If a glossary term has synonyms defined, match all synonyms but always link
   to the canonical glossary URL
4. Glossary terms shorter than 3 characters are excluded from auto-matching
   (prevents matching "IA", "AI", "VC" in unintended contexts -- these must
   be added as explicit rules with careful word-boundary patterns)
```

### 4.2 Internal Links: Entities (Actors, Companies, VCs)

**Use case:** 500+ entity pages for people, companies, and venture capital firms in the startup ecosystem.

| Attribute | Value | Rationale |
|---|---|---|
| Target URL pattern | `/entidad/{entity-slug}/` or type-specific: `/empresa/{slug}/`, `/persona/{slug}/`, `/vc/{slug}/` | Entity type prefix aids both user comprehension and crawl organization. |
| `rel` attribute | **(none)** | Internal links, full equity pass-through. |
| `target` attribute | **(none)** | Same tab. |
| `class` attribute | `leanweave-link leanweave-entity` | Distinct class for entity styling. |
| `data-leanweave-rule` | `{rule_id}` | Tracking and debugging. |
| Anchor text | Original text from content | Same rule as glossary: never rewrite. |
| Priority weight | **200** (middle tier) | Entity pages build topical authority and E-E-A-T signals (demonstrating coverage of real people and companies). Higher priority than glossary but lower than revenue-generating affiliate links. |

#### Entity Matching Special Rules

```
1. Company names often contain common words (e.g., "Apple", "Meta"). Entity
   rules for common-word names must use EXACT MATCH with strict word boundaries
   and are recommended to require surrounding context keywords (e.g., only
   match "Meta" when the post is in category "empresas" or contains
   "Mark Zuckerberg" within 500 characters).
2. Person names should match both "Nombre Apellido" and "Apellido" forms but
   prefer the full name match. Short surnames (e.g., "Li", "Ma") require
   minimum 4-character thresholds or explicit exact-match rules.
3. VC firm names should match both full name and common abbreviation
   (e.g., "Sequoia Capital" and "Sequoia") with the full name taking priority.
```

### 4.3 Affiliate Links

**Use case:** 100+ keyword rules linking to affiliate/sponsored destinations.

| Attribute | Value | Rationale |
|---|---|---|
| `rel` attribute | **`sponsored nofollow`** | **Mandatory.** Google requires `rel="sponsored"` for paid/affiliate links. Adding `nofollow` provides backward compatibility with older search engine implementations. This is non-negotiable for compliance with Google's link spam policies and FTC disclosure requirements. |
| `target` attribute | **`_blank`** | Affiliate links open in a new tab to preserve the user's session on the source site. This is standard practice for outbound commercial links. |
| `rel` (additional) | Add `noopener` when `target="_blank"` | Security best practice. Prevents the destination page from accessing `window.opener`. Full rel: `sponsored nofollow noopener`. |
| `class` attribute | `leanweave-link leanweave-affiliate` | Enables affiliate link styling (some sites add a small external-link icon). |
| `data-leanweave-rule` | `{rule_id}` | Click tracking for affiliate revenue attribution. |
| Priority weight | **100** (highest) | Affiliate links generate revenue. They should win over glossary and entity matches when competing for the same keyword. |

#### Affiliate Frequency Limits

| Setting | Default | Rationale |
|---|---|---|
| `max_affiliate_links_per_post` | **3** | Google's Helpful Content system penalizes pages where affiliate links dominate. Capping at 3 per post ensures the page reads as editorial content with occasional commercial references, not as an affiliate landing page. |
| `max_affiliate_ratio` | **30%** of total auto-links | If a post has 10 auto-links, at most 3 can be affiliate. This prevents scenarios where density limits are low and all slots go to affiliate rules. |
| `affiliate_disclosure_required` | **true** | When any affiliate link is inserted, LeanWeave adds a configurable disclosure notice. Default text: `Este articulo contiene enlaces de afiliado. Consulta nuestra politica de divulgacion.` Position: top of post content, wrapped in `<p class="leanweave-disclosure">`. |

#### Affiliate Disclosure Implementation

```html
<p class="leanweave-disclosure">
  Este articulo contiene enlaces de afiliado. Si realizas una compra a traves
  de estos enlaces, podemos recibir una comision sin costo adicional para ti.
  <a href="/divulgacion-afiliados/">Mas informacion</a>.
</p>
```

**Legal rationale:** FTC guidelines (applicable to Spanish-language sites targeting US Hispanic audiences) and Spain's LSSI require clear disclosure of commercial relationships. Even for primarily Spanish-speaking audiences in LATAM, disclosure builds trust and aligns with E-E-A-T principles.

---

## 5. Default Configuration Values

### 5.1 Complete Default Configuration Table

```php
$leanweave_defaults = [

    // === Density Limits ===
    'max_links_per_post'           => 10,    // Hard cap on auto-links per post
    'max_links_per_1000_words'     => 5,     // Density cap (whichever is lower wins)
    'max_per_keyword_per_post'     => 1,     // Same keyword linked once per post
    'max_per_target_per_post'      => 1,     // Same destination linked once per post
    'max_affiliate_links_per_post' => 3,     // Affiliate subset cap
    'max_affiliate_ratio'          => 0.30,  // 30% affiliate ceiling

    // === Content Thresholds ===
    'min_content_length'           => 200,   // Characters. Skip posts shorter than this
    'min_keyword_length'           => 3,     // Characters. Ignore rules with shorter keywords
    'min_chars_between_links'      => 100,   // Spacing between auto-links

    // === Matching Behavior ===
    'match_mode'                   => 'exact',  // 'exact' or 'partial'
    'case_sensitive'               => false,     // Always case-insensitive
    'accent_insensitive'           => true,      // Normalize accents for matching
    'preserve_original_text'       => true,      // Use source text as anchor (never rewrite)

    // === Rule Type Priorities (lower = higher priority) ===
    'priority_affiliate'           => 100,
    'priority_entity'              => 200,
    'priority_glossary'            => 300,

    // === Post Types ===
    'enabled_post_types'           => ['post', 'page'],
    'excluded_post_types'          => ['glossary', 'entity', 'attachment', 'revision'],

    // === Link Attributes ===
    'internal_link_rel'            => '',                           // No rel for internal
    'internal_link_target'         => '',                           // Same tab
    'affiliate_link_rel'           => 'sponsored nofollow noopener', // Mandatory
    'affiliate_link_target'        => '_blank',                     // New tab

    // === Affiliate Disclosure ===
    'affiliate_disclosure_enabled' => true,
    'affiliate_disclosure_text'    => 'Este articulo contiene enlaces de afiliado...',
    'affiliate_disclosure_position'=> 'before_content',  // 'before_content' or 'after_content'
    'affiliate_disclosure_url'     => '/divulgacion-afiliados/',

    // === Exclusion Elements (CSS selectors) ===
    'excluded_selectors'           => [
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'a', 'code', 'pre', 'kbd', 'samp',
        'script', 'style', 'noscript',
        'button', 'input', 'select', 'textarea', 'label',
        'figcaption', 'blockquote', 'cite',
        'th', 'caption',
        '.no-auto-link',
        '[data-leanweave-skip]',
        '.wp-block-image',
        '.wp-block-embed',
    ],

    // === Performance ===
    'cache_ttl'                    => 86400,  // 24 hours in seconds
    'batch_size'                   => 50,     // Posts processed per async batch
    'enable_object_cache'          => true,   // Use Redis/Memcached when available

    // === Link Distribution ===
    'distribution_mode'            => 'spread', // 'spread' (even) or 'natural' (first-match)
];
```

### 5.2 Justification Matrix

| Setting | Default | Why This Value |
|---|---|---|
| `max_links_per_post = 10` | Conservative middle ground. Competitor analysis of Internal Link Juicer shows most users set 7-12. For a 15K-post site, 10 links per post means ~150K total internal links -- sufficient for deep graph connectivity without over-linking. |
| `max_links_per_1000_words = 5` | At average reading speed, this means a link roughly every 40-50 words (3-4 sentences). Natural density that mirrors how human editors would link. |
| `max_per_keyword_per_post = 1` | Google counts only the first link between page A and page B. Repeating the same link wastes a slot and annoys readers. |
| `max_affiliate_links_per_post = 3` | Google's Product Reviews and Helpful Content systems devalue pages dominated by affiliate links. Three is enough for monetization without triggering quality signals. |
| `min_content_length = 200` | Approximately 35-40 Spanish words. Below this, content is too thin for contextual links to be meaningful. Prevents linking in stubs, excerpts, and auto-generated content. |
| `min_keyword_length = 3` | Two-character keywords (IA, AI, VC) match too aggressively. Require explicit rules with custom regex for these abbreviations. |
| `cache_ttl = 86400` | 24-hour cache balances freshness with performance. New links appear within a day of rule creation. Sites publishing 700 posts/week means ~100/day, manageable with daily cache refresh. |
| `batch_size = 50` | Processing 50 posts per async cron batch keeps individual job execution under 30 seconds on standard shared hosting, avoiding timeout kills. |

---

## 6. Content Parser Rules

### 6.1 Gutenberg (Block Editor) Content Handling

WordPress stores Gutenberg content as HTML with block comment delimiters:

```html
<!-- wp:paragraph -->
<p>Content here about inteligencia artificial and startups.</p>
<!-- /wp:paragraph -->

<!-- wp:heading -->
<h2>Section Title</h2>
<!-- /wp:heading -->
```

#### Parser Strategy

```
1. Parse the raw post_content using DOMDocument (with libxml HTML5 support)
2. Respect block comment boundaries -- treat each block as an independent unit
3. Apply element exclusion rules to each block based on its HTML structure
4. Never modify block comment delimiters (<!-- wp:* --> markers)
5. Process only text nodes within permitted elements
6. Reconstruct the modified HTML preserving exact block structure
```

#### Block-Specific Rules

| Block Type | Processing Rule |
|---|---|
| `wp:paragraph` | **Process** -- primary linking target |
| `wp:list`, `wp:list-item` | **Process** -- list items can contain contextual links |
| `wp:heading` | **Skip** -- excluded element (Section 3.1) |
| `wp:code`, `wp:preformatted` | **Skip** -- code content must not be modified |
| `wp:image`, `wp:gallery` | **Skip** -- media blocks |
| `wp:embed` | **Skip** -- third-party embeds |
| `wp:html` | **Process with caution** -- parse inner HTML, apply exclusion rules |
| `wp:table` | **Process `<td>` only** -- skip `<th>` and `<caption>` |
| `wp:quote` | **Skip** -- blockquote content (Section 3.1) |
| `wp:columns`, `wp:column` | **Process** -- container blocks, process inner blocks recursively |
| `wp:group` | **Process** -- container block, process inner blocks recursively |
| `wp:shortcode` | **See Section 6.3** |
| `wp:pullquote` | **Skip** -- same rationale as blockquote |
| `wp:verse` | **Skip** -- poetic/formatted text should not be modified |
| `wp:separator`, `wp:spacer` | **Skip** -- no text content |
| `wp:buttons` | **Skip** -- UI elements |
| `wp:cover` | **Process inner text blocks only** |
| `wp:media-text` | **Process text column only** |

### 6.2 Classic Editor Content Handling

Classic editor content is stored as raw HTML without block delimiters.

#### Parser Strategy

```
1. Parse using DOMDocument (same as Gutenberg path)
2. Walk the DOM tree, identifying text nodes
3. For each text node, check if any ancestor element is in the exclusion list
4. If no excluded ancestor, run keyword matching on the text node content
5. Replace matched text with the link HTML
6. Serialize back to HTML string
```

**Important:** The parser must handle malformed HTML gracefully. Classic editor content often contains unclosed tags, nested inline elements, and mixed block/inline usage. DOMDocument's `loadHTML` with `LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD` flags handles most malformed cases.

### 6.3 Shortcode Handling

Shortcodes present a challenge because their content may or may not be visible text.

#### Rules

| Scenario | Behavior | Example |
|---|---|---|
| Self-closing shortcodes | **Skip entirely** | `[gallery ids="1,2,3"]` -- no text to link |
| Enclosing shortcodes (known safe) | **Process inner content** | `[caption]Text here[/caption]` -- process "Text here" |
| Enclosing shortcodes (unknown) | **Skip by default** | Unknown shortcodes may generate HTML that conflicts with linking |
| Shortcode output (rendered) | **Never process** | LeanWeave operates on stored content, not rendered output, to avoid double-processing |

#### Safe Shortcode Allowlist

```php
$safe_shortcodes = [
    'caption',      // Image captions -- but note figcaption exclusion applies to output
    'wp_caption',   // Alias
];
```

All other shortcodes are treated as opaque blocks and skipped. Users can extend the allowlist via a filter: `leanweave_safe_shortcodes`.

### 6.4 HTML Entities and Encoded Characters

| Entity Type | Handling | Example |
|---|---|---|
| Named HTML entities | **Decode before matching, re-encode after** | `&amp;` becomes `&` for matching, restored in output |
| Numeric HTML entities | **Decode before matching** | `&#233;` becomes `e with accent` for matching |
| UTF-8 multibyte characters | **Handle natively** | DOMDocument in UTF-8 mode handles these correctly |
| `&nbsp;` (non-breaking space) | **Normalize to regular space for matching** | `inteligencia&nbsp;artificial` should match rule `inteligencia artificial` |
| URL-encoded characters | **Do not decode** | These appear in href attributes, not in visible text |

#### DOMDocument UTF-8 Handling

```php
// Force UTF-8 interpretation to prevent Spanish character mangling
$dom = new DOMDocument('1.0', 'UTF-8');
$dom->loadHTML(
    '<?xml encoding="UTF-8">' . $content,
    LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
);
```

**Critical:** Without the XML encoding declaration, DOMDocument defaults to ISO-8859-1 and corrupts multibyte UTF-8 characters (accented vowels, "n with tilde," em dashes). This is the single most common bug in WordPress content manipulation plugins.

### 6.5 Content Modification Safety Rules

```
1. NEVER modify content in the database. All link injection happens at render
   time (via the_content filter) or in a cached layer. The original post_content
   remains untouched.
2. All DOM manipulation must be idempotent. Processing the same content twice
   must produce identical output (no double-linking).
3. Implement a signature check: hash the input content and rule set version.
   If both match the cache, serve cached output without reprocessing.
4. On any DOMDocument parsing error, return the ORIGINAL content unmodified.
   Never serve broken HTML.
```

---

## 7. WordPress.org Listing Optimization

### 7.1 Plugin Name

**Primary name:** `LeanWeave - Automated Internal Linking for Large Sites`

**Rationale:**
- "Automated Internal Linking" captures the primary search query on wordpress.org
- "Large Sites" differentiates from competitors and targets the underserved high-scale niche
- Name is under 64 characters (wordpress.org limit)

### 7.2 Plugin Description (Short)

```
Automated internal linking engine built for sites with 10,000+ posts. Links
glossary terms, entities, and affiliate keywords with zero frontend performance
impact. Handles 1,000+ linking rules with configurable SEO-safe density limits.
```

**Character count:** 249 (under 150 words, fits the short description field)

### 7.3 Plugin Description (Long / readme.txt)

```
== Description ==

LeanWeave is the internal linking plugin built for scale.

If your site has thousands of posts and hundreds of linking rules, you have felt
the pain of slow page loads, missed linking opportunities, and hours of manual
linking work. LeanWeave solves all three.

**Built for sites that outgrew other linking plugins:**

* Process 1,000+ keyword rules against 15,000+ posts without slowing your site
* Async processing with cached serving means zero impact on frontend page speed
* Smart matching handles Spanish accents, overlapping keywords, and word boundaries
* Three rule types: glossary terms, entity pages, and affiliate links
* SEO-safe defaults: configurable density limits, element exclusions, and rel attributes

**What makes LeanWeave different:**

* **True zero frontend cost** -- links are pre-computed and served from cache, not
  calculated on every page load
* **Conflict resolution** -- when multiple rules match the same text, a deterministic
  priority system picks the winner (no random or race-condition results)
* **Scale tested** -- designed for sites adding 700+ posts per week with 500+ glossary
  terms and 500+ entity pages
* **Spanish-language optimized** -- accent-insensitive matching that correctly handles
  tildes, diacritics, and Unicode word boundaries
* **Affiliate compliance** -- automatic rel="sponsored nofollow" and configurable
  disclosure notices

**Rule Types:**

1. **Glossary Rules** -- Link terms to their definition pages. Perfect for building
   topical authority in your niche.
2. **Entity Rules** -- Link company names, person names, and organizations to their
   profile pages. Build E-E-A-T signals automatically.
3. **Affiliate Rules** -- Link commercial keywords with proper sponsored attributes.
   Revenue generation without SEO risk.

**SEO Features:**

* Configurable maximum links per post and per 1,000 words
* Element exclusion (never links inside headings, code blocks, or existing links)
* Even link distribution across post body (not clustered at the top)
* Self-link prevention
* One link per keyword per post (industry best practice)
* Full Core Web Vitals compliance (zero CLS, zero LCP impact)

**Developer Friendly:**

* Filter hooks for customizing matching, exclusions, and output
* WP-CLI commands for bulk processing and cache management
* REST API endpoints for rule management
* Compatible with Redis, Memcached, and persistent object caches
* Works with Gutenberg, Classic Editor, and major page builders

== Installation ==

1. Upload the plugin files to /wp-content/plugins/leanweave/ or install through
   the WordPress plugins screen
2. Activate the plugin through the Plugins screen
3. Navigate to LeanWeave > Settings to configure density limits
4. Add your first linking rules under LeanWeave > Rules
5. Run initial processing via LeanWeave > Tools > Process All Posts

== Frequently Asked Questions ==

= Will this slow down my site? =

No. LeanWeave uses async background processing to compute links and serves them
from cache. Your frontend page load time is unaffected. We add zero JavaScript
and zero database queries to your page render.

= How many rules can it handle? =

LeanWeave is tested with 1,000+ active rules against 15,000+ posts. The keyword
index uses prefix bucketing for efficient matching regardless of rule count.

= Does it work with Spanish and other accented languages? =

Yes. LeanWeave uses Unicode-aware matching with accent normalization. It correctly
matches keywords regardless of accent variations while preserving the original text
in anchor tags. It also correctly treats the Spanish letter "n with tilde" as
distinct from "n".

= What about affiliate link SEO compliance? =

Affiliate rules automatically receive rel="sponsored nofollow noopener" and open
in new tabs. An optional disclosure notice is inserted at the top of posts
containing affiliate links.

= Can I exclude certain posts or sections from auto-linking? =

Yes. You can exclude by post type, category, tag, or individual post. Within
content, add the CSS class "no-auto-link" or the attribute data-leanweave-skip
to any element to prevent linking inside it.

= Does it modify my post content in the database? =

No. LeanWeave never modifies stored content. All link injection happens at render
time through WordPress's the_content filter, served from a pre-computed cache.

= Is it compatible with caching plugins? =

Yes. Since LeanWeave injects links via the_content filter before page caching
occurs, it works seamlessly with WP Super Cache, W3 Total Cache, WP Rocket,
LiteSpeed Cache, and any other page caching plugin.

= How does it handle keyword conflicts? =

When multiple rules match the same text, LeanWeave uses a deterministic priority
cascade: user-defined priority first, then rule type (affiliate > entity >
glossary), then keyword length (longer wins), then creation date (older wins).
```

### 7.4 Plugin Tags

WordPress.org allows a maximum of 5 tags per plugin. Select tags based on actual search volume on the plugin directory:

```
Tags: internal linking, auto link, SEO, glossary, affiliate links
```

**Rationale for each tag:**

| Tag | Reason |
|---|---|
| `internal linking` | Primary use case. Highest-volume search query for this category of plugin. |
| `auto link` | Second most common search query. Users search "auto link plugin wordpress." |
| `SEO` | Broad category tag that ensures visibility in general SEO plugin searches. High competition but necessary for discovery. |
| `glossary` | Captures users searching specifically for glossary/tooltip linking functionality. Lower competition, high intent. |
| `affiliate links` | Captures users looking for affiliate link management. Differentiates from pure SEO plugins. |

### 7.5 Key Screenshots

WordPress.org displays up to 10 screenshots. Prioritize these based on what converts browsers into installers:

| # | Screenshot | Purpose |
|---|---|---|
| 1 | **Dashboard overview** showing total rules, total links inserted, and processing status | First impression: shows the plugin works at scale and provides actionable data |
| 2 | **Rule creation form** with keyword, target URL, type selector, and priority field | Demonstrates simplicity of setup |
| 3 | **Settings page** showing density limits, exclusion rules, and post type toggles | Shows configurability without overwhelming complexity |
| 4 | **Post editor sidebar panel** showing which auto-links will be inserted in the current post | Demonstrates editorial control and transparency |
| 5 | **Link report table** showing top linked keywords, target URLs, and link counts | Proves the plugin provides analytics, not just blind automation |
| 6 | **Before/after content comparison** showing original text and text with auto-links highlighted | The "aha moment" -- visually shows what the plugin does |
| 7 | **Performance metrics** showing zero frontend impact (Core Web Vitals before/after) | Addresses the primary concern of site speed-conscious users |
| 8 | **Conflict resolution log** showing how overlapping keywords were resolved | Differentiator: no competitor shows this level of transparency |

**Screenshot specifications:**
- Resolution: 1544x940 pixels (wordpress.org standard)
- Format: PNG with clean UI, no browser chrome
- Use realistic data from ecosistemastartup.com (Spanish content) to show multilingual capability
- Dark/light mode variants are unnecessary -- use the default WordPress admin color scheme

### 7.6 FAQ Items That Drive Search Traffic

Beyond the readme FAQ (Section 7.3), these questions target long-tail searches that lead users to plugin pages:

| Question | Target Search Query |
|---|---|
| "How many internal links per post is too many?" | `how many internal links per post SEO` |
| "Should internal links be nofollow?" | `internal links nofollow wordpress` |
| "How to automate internal linking in WordPress?" | `automated internal linking wordpress plugin` |
| "Best internal linking plugin for large WordPress sites?" | `internal linking plugin large site wordpress` |
| "How to link glossary terms automatically?" | `auto link glossary terms wordpress` |
| "Does automated internal linking hurt SEO?" | `automated internal linking SEO safe` |

### 7.7 Changelog and Update Strategy

Maintain an active changelog with at least monthly updates. WordPress.org's search algorithm factors in "last updated" date. Plugins not updated in 6+ months lose visibility.

```
== Changelog ==

= 1.0.0 =
* Initial release
* Glossary, entity, and affiliate rule types
* Async processing with cached serving
* Spanish accent-aware matching
* Configurable density limits and element exclusions
* WP-CLI support for bulk operations
```

---

## Appendix A: Matching Algorithm Pseudocode

```
function process_post_content(post_id, content):
    // Step 1: Check cache
    cache_key = hash(post_id + content_hash + rule_version)
    if cached = get_cache(cache_key):
        return cached

    // Step 2: Parse content
    dom = parse_html_to_dom(content)
    text_nodes = extract_eligible_text_nodes(dom, excluded_selectors)

    // Step 3: Find all matches
    matches = []
    for node in text_nodes:
        node_text = node.text_content
        node_text_normalized = normalize_for_matching(node_text)

        for rule in keyword_index.lookup(node_text_normalized):
            positions = find_all_occurrences(node_text_normalized, rule.keyword_normalized)
            for pos in positions:
                matches.append({
                    node: node,
                    start: pos,
                    end: pos + rule.keyword_length,
                    rule: rule,
                    original_text: node_text[pos:pos+rule.keyword_length]
                })

    // Step 4: Resolve overlaps (greedy longest-match-first)
    matches = sort_by(matches, [start ASC, length DESC])
    accepted = []
    occupied_ranges = []

    for match in matches:
        if overlaps(match, occupied_ranges):
            existing = find_overlapping(match, accepted)
            if match.rule.priority < existing.rule.priority:
                // New match wins, remove existing
                remove(accepted, existing)
                remove(occupied_ranges, existing.range)
                accepted.append(match)
                occupied_ranges.append(match.range)
            // else: existing wins, skip new match
        else:
            accepted.append(match)
            occupied_ranges.append(match.range)

    // Step 5: Apply density limits
    accepted = apply_max_links_per_post(accepted, settings)
    accepted = apply_max_per_keyword(accepted, settings)
    accepted = apply_max_per_target(accepted, settings)
    accepted = apply_max_affiliate(accepted, settings)
    accepted = apply_min_distance(accepted, settings)
    accepted = apply_distribution_spread(accepted, settings)
    accepted = filter_self_links(accepted, post_id)

    // Step 6: Inject links into DOM
    for match in sort_by(accepted, [start DESC]):  // reverse order to preserve positions
        link_html = build_link_html(match)
        replace_text_in_node(match.node, match.start, match.end, link_html)

    // Step 7: Serialize and cache
    output = serialize_dom(dom)
    set_cache(cache_key, output, cache_ttl)
    return output
```

---

## Appendix B: Configuration Precedence

When settings overlap, this precedence applies (highest to lowest):

```
1. Per-rule override (set on individual rule)
2. Per-post-type override (set in post type settings)
3. Global plugin settings
4. Hardcoded defaults (this document, Section 5)
```

No configuration can override the hardcoded safety rules:
- Element exclusions for `<a>`, `<script>`, `<style>` are always enforced
- `rel="sponsored nofollow"` on affiliate links is always enforced
- Self-link prevention is always enforced
- Maximum of 50 links per post (absolute ceiling regardless of configuration)

---

## Appendix C: Glossary of SEO Terms Used

| Term | Definition |
|---|---|
| **Link equity** | The ranking value passed from one page to another through hyperlinks. Also called "link juice." |
| **E-E-A-T** | Experience, Expertise, Authoritativeness, Trustworthiness. Google's quality framework for evaluating content. |
| **Topical authority** | A site's demonstrated expertise in a specific subject area, built through comprehensive content coverage and internal linking. |
| **CLS** | Cumulative Layout Shift. A Core Web Vital measuring visual stability. Auto-linking must not cause layout shifts. |
| **LCP** | Largest Contentful Paint. A Core Web Vital measuring load speed. Auto-linking must not delay content rendering. |
| **SERP** | Search Engine Results Page. The page displayed by a search engine in response to a query. |
| **Anchor text** | The visible, clickable text of a hyperlink. Critical ranking signal for the destination page. |
| **NFD** | Canonical Decomposition. A Unicode normalization form that separates base characters from combining marks. |
