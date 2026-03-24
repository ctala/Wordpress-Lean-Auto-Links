<?php
declare(strict_types=1);

namespace LeanAutoLinks\Engine;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Core matching engine for automated link insertion.
 *
 * Processes a post's content against active linking rules, respecting:
 *   - Density limits (max links per post, per 1000 words, affiliate caps)
 *   - Minimum distance between auto-inserted links (100 chars)
 *   - Self-linking prevention (never link a post to itself)
 *   - Priority ordering (longest keyword first, then user priority, then rule type)
 *   - Unicode-safe word boundaries for Spanish text (tildes, n-tilde, accented vowels)
 *   - HTML safety zones (never link inside excluded elements)
 *
 * Performance targets: < 500ms per post with 1,000 rules, < 32MB per batch.
 */
final class RuleMatcherEngine
{
    private ContentParser $parser;
    private LinkBuilder $builder;

    /** Maximum total links that can be inserted into a single post. */
    private int $max_links_per_post = 10;

    /** Maximum links per 1000 words of content. */
    private int $max_links_per_1000_words = 5;

    /** Maximum affiliate links per post. */
    private int $max_affiliate_per_post = 3;

    /** Maximum ratio of affiliate links to total links (0.0 - 1.0). */
    private float $max_affiliate_ratio = 0.3;

    /** Minimum character distance between auto-inserted links. */
    private int $min_distance_chars = 100;

    /** Minimum content length in characters to process (skip very short content). */
    private int $min_content_length = 200;

    /**
     * Rule type priority map. Lower number = higher priority.
     * Used as tiebreaker when user priority and keyword length are equal.
     */
    private const RULE_TYPE_PRIORITY = [
        'affiliate' => 1,
        'entity'    => 2,
        'internal'  => 3,
    ];

    public function __construct(ContentParser $parser, LinkBuilder $builder)
    {
        $this->parser  = $parser;
        $this->builder = $builder;
    }

    /**
     * Process a post's content against active rules.
     *
     * Steps:
     *   1. Guard: check minimum content length.
     *   2. Parse content into safe/unsafe segments via ContentParser.
     *   3. Sort rules by priority.
     *   4. For each rule, find and apply matches in safe segments only.
     *   5. Rebuild content from modified DOM.
     *   6. Return processed content and array of applied link records.
     *
     * @param string $content The raw post content HTML.
     * @param array  $rules   Array of rule objects from lw_rules.
     * @param int    $post_id The current post ID (for self-link prevention).
     * @return array{content: string, links: array<array{rule_id: int, keyword: string, target_url: string}>}
     */
    public function process(string $content, array $rules, int $post_id): array
    {
        $result = [
            'content' => $content,
            'links'   => [],
        ];

        // Guard: skip content that is too short for meaningful linking.
        $stripped_length = mb_strlen(strip_tags($content), 'UTF-8');
        if ($stripped_length < $this->min_content_length) {
            return $result;
        }

        // Guard: no rules to process.
        if (empty($rules)) {
            return $result;
        }

        // Parse content into safe/unsafe segments.
        $parsed = $this->parser->parse($content);
        $segments = $parsed['segments'];
        $doc = $parsed['doc'];

        if (empty($segments)) {
            return $result;
        }

        // Calculate word count for density limits.
        $plain_text = strip_tags($content);
        $word_count = $this->word_count($plain_text);

        // Sort rules by priority.
        $rules = $this->sort_rules($rules);

        // Track applied links state.
        $total_links_applied = 0;
        $affiliate_count = 0;
        $applied_keywords = [];       // keyword => count (tracks per-keyword limit)
        $applied_positions = [];      // Global character positions of inserted links.
        $applied_links = [];          // Records of applied links for storage.

        // Build a running offset tracker for character positions across all safe segments.
        // This is used for minimum distance enforcement.
        $global_offset = 0;
        $segment_offsets = [];
        foreach ($segments as $idx => $seg) {
            $segment_offsets[$idx] = $global_offset;
            $global_offset += mb_strlen($seg['text'], 'UTF-8');
        }

        foreach ($rules as $rule) {
            // Guard: self-link prevention.
            if ($this->is_self_link($rule->target_url, $post_id)) {
                continue;
            }

            // Guard: check if this keyword has already reached its per-post maximum.
            $keyword_lower = mb_strtolower($rule->keyword, 'UTF-8');
            $max_per_post = isset($rule->max_per_post) ? (int) $rule->max_per_post : 1;
            $keyword_applied_count = $applied_keywords[$keyword_lower] ?? 0;

            if ($keyword_applied_count >= $max_per_post) {
                continue;
            }

            // Guard: check density limits before even trying to match.
            $is_affiliate = ($rule->rule_type === 'affiliate');
            if ($this->exceeds_density($total_links_applied, $word_count, $affiliate_count, $is_affiliate)) {
                continue;
            }

            // Build the regex pattern for this rule's keyword.
            $pattern = $this->build_pattern($rule);
            if ($pattern === '') {
                continue;
            }

            $instances_applied = 0;

            // Iterate over segments looking for matches in safe zones.
            // We must re-check segments after each injection because the DOM changes.
            $this->match_in_segments(
                $doc,
                $rule,
                $pattern,
                $max_per_post,
                $keyword_applied_count,
                $instances_applied,
                $total_links_applied,
                $affiliate_count,
                $word_count,
                $applied_positions,
                $applied_links,
                $segment_offsets
            );

            // Update the per-keyword count.
            $applied_keywords[$keyword_lower] = $keyword_applied_count + $instances_applied;
        }

        // Rebuild the content from the modified DOM.
        $result['content'] = $this->parser->rebuild($doc);
        $result['links'] = $applied_links;

        return $result;
    }

    /**
     * Find and apply matches for a single rule across all safe text nodes.
     *
     * Modifies the DOM in-place via ContentParser::inject_link(). After each
     * injection, the text node is split so subsequent matches operate on the
     * remaining text.
     */
    private function match_in_segments(
        \DOMDocument $doc,
        object $rule,
        string $pattern,
        int $max_per_post,
        int $keyword_already_applied,
        int &$instances_applied,
        int &$total_links_applied,
        int &$affiliate_count,
        int $word_count,
        array &$applied_positions,
        array &$applied_links,
        array &$segment_offsets
    ): void {
        $is_affiliate = ($rule->rule_type === 'affiliate');

        // Re-query text nodes from the DOM each time because previous
        // injections may have split text nodes.
        $xpath = new \DOMXPath($doc);
        $text_nodes = $xpath->query('//text()');

        if ($text_nodes === false) {
            return;
        }

        // Collect safe text nodes first (avoid modifying while iterating).
        $safe_nodes = [];
        foreach ($text_nodes as $text_node) {
            if (!($text_node instanceof \DOMText)) {
                continue;
            }
            if (trim($text_node->nodeValue ?? '') === '') {
                continue;
            }
            if (!$this->is_node_safe($text_node)) {
                continue;
            }
            $safe_nodes[] = $text_node;
        }

        // Calculate a rough global offset for distance checking.
        // We track the cumulative character position across all text nodes.
        $cumulative_offset = 0;

        foreach ($safe_nodes as $text_node) {
            // Re-check: the node may have been removed by a previous injection
            // within this same rule's iteration.
            if ($text_node->parentNode === null) {
                continue;
            }

            $text = $text_node->nodeValue ?? '';
            if ($text === '') {
                continue;
            }

            // Find all matches in this text node.
            if (preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE) === 0) {
                $cumulative_offset += mb_strlen($text, 'UTF-8');
                continue;
            }

            foreach ($matches[0] as $match) {
                $matched_text = $match[0];
                // preg_match PREG_OFFSET_CAPTURE returns byte offset; convert to character offset.
                $byte_offset = $match[1];
                $char_offset = mb_strlen(substr($text, 0, $byte_offset), 'UTF-8');
                $global_pos = $cumulative_offset + $char_offset;

                // Guard: per-keyword limit.
                if (($keyword_already_applied + $instances_applied) >= $max_per_post) {
                    break;
                }

                // Guard: density limits.
                if ($this->exceeds_density($total_links_applied, $word_count, $affiliate_count, $is_affiliate)) {
                    break;
                }

                // Guard: minimum distance.
                if (!$this->respects_min_distance($global_pos, $applied_positions)) {
                    continue;
                }

                // Build the link HTML.
                $link_html = $this->builder->build($rule, $matched_text);

                // Inject the link into the DOM.
                $remaining_node = $this->parser->inject_link(
                    $text_node,
                    $char_offset,
                    mb_strlen($matched_text, 'UTF-8'),
                    $link_html,
                    $doc
                );

                // Record the applied link.
                $applied_links[] = [
                    'rule_id'    => (int) $rule->id,
                    'keyword'    => $matched_text,
                    'target_url' => $rule->target_url,
                ];

                $applied_positions[] = $global_pos;
                $total_links_applied++;
                $instances_applied++;

                if ($is_affiliate) {
                    $affiliate_count++;
                }

                // After injection, the original text_node has been replaced.
                // If there is remaining text after the link, continue matching
                // in the remaining node. Otherwise, move to the next segment.
                if ($remaining_node !== null) {
                    // Update cumulative offset: advance past the matched portion plus the link.
                    $cumulative_offset = $global_pos + mb_strlen($matched_text, 'UTF-8');
                    $text_node = $remaining_node;
                    $text = $text_node->nodeValue ?? '';

                    // Re-run pattern on the remaining text for additional matches.
                    if (preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE) === 0) {
                        break;
                    }

                    // Process remaining matches in a nested loop would be complex.
                    // Instead, break and let the next rule iteration re-scan.
                    // For the same rule, we break and the outer loop handles it.
                    break;
                }

                // No remaining text node; move to next safe node.
                break;
            }

            // If we exhausted the per-keyword or density limits, stop for this rule.
            if (($keyword_already_applied + $instances_applied) >= $max_per_post) {
                break;
            }
            if ($this->exceeds_density($total_links_applied, $word_count, $affiliate_count, $is_affiliate)) {
                break;
            }

            $cumulative_offset += mb_strlen($text, 'UTF-8');
        }
    }

    /**
     * Check if a text node is safe for link injection.
     *
     * Duplicates the ancestor-walk logic from ContentParser but operates
     * on live DOM nodes (which may have changed after previous injections).
     */
    private function is_node_safe(\DOMText $node): bool
    {
        $excluded = [
            'a', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
            'code', 'pre', 'script', 'style', 'button',
            'input', 'textarea', 'select', 'iframe', 'svg',
            'blockquote',
        ];

        $current = $node->parentNode;

        while ($current !== null) {
            if ($current instanceof \DOMElement) {
                if (in_array(strtolower($current->nodeName), $excluded, true)) {
                    return false;
                }
            }
            $current = $current->parentNode;
        }

        return true;
    }

    /**
     * Sort rules by processing priority.
     *
     * Order (each criterion breaks ties from the previous):
     *   1. Longest keyword first (longer = more specific = higher priority).
     *   2. User-defined priority (lower number = higher priority).
     *   3. Rule type: affiliate (1) > entity (2) > internal (3).
     *   4. Keyword length descending (secondary tiebreaker).
     *   5. Creation date ascending (oldest rules win ties).
     *
     * @param array $rules Array of rule objects.
     * @return array Sorted array of rule objects.
     */
    private function sort_rules(array $rules): array
    {
        usort($rules, function (object $a, object $b): int {
            // 1. Longest keyword first.
            $len_a = mb_strlen($a->keyword, 'UTF-8');
            $len_b = mb_strlen($b->keyword, 'UTF-8');
            if ($len_a !== $len_b) {
                return $len_b <=> $len_a;
            }

            // 2. User priority (lower = higher).
            $prio_a = (int) ($a->priority ?? 10);
            $prio_b = (int) ($b->priority ?? 10);
            if ($prio_a !== $prio_b) {
                return $prio_a <=> $prio_b;
            }

            // 3. Rule type priority.
            $type_a = self::RULE_TYPE_PRIORITY[$a->rule_type] ?? 99;
            $type_b = self::RULE_TYPE_PRIORITY[$b->rule_type] ?? 99;
            if ($type_a !== $type_b) {
                return $type_a <=> $type_b;
            }

            // 4. Creation date ascending (oldest first).
            $date_a = $a->created_at ?? '';
            $date_b = $b->created_at ?? '';
            return strcmp((string) $date_a, (string) $date_b);
        });

        return $rules;
    }

    /**
     * Build a regex pattern for matching a keyword in content.
     *
     * Uses Unicode word boundary character classes instead of \b, which does
     * not work correctly with Unicode characters like n-tilde and accented vowels.
     *
     * The boundary pattern matches:
     *   - Start/end of string
     *   - Whitespace, punctuation, and common delimiters
     *
     * Handles accent normalization: builds an alternation pattern that matches
     * both accented and unaccented forms for Spanish text.
     *
     * @param object $rule The rule object (with keyword and case_sensitive properties).
     * @return string The compiled regex pattern, or empty string on failure.
     */
    private function build_pattern(object $rule): string
    {
        $keyword = $rule->keyword ?? '';
        if (trim($keyword) === '') {
            return '';
        }

        // Escape the keyword for use in a regex pattern.
        $escaped = preg_quote($keyword, '/');

        // Build accent-insensitive alternation for Spanish characters.
        // This allows "articulo" to match "articulo" and vice versa.
        $escaped = $this->build_accent_alternation($escaped);

        // Unicode word boundary character classes.
        // These match positions at the boundary between word and non-word characters,
        // working correctly with Unicode letters including n-tilde and accented vowels.
        $boundary_before = '(?<=[\s.,;:!?\'"()\[\]\-\x{00AB}\x{00BB}\x{2013}\x{2014}\x{201C}\x{201D}\x{2018}\x{2019}]|^)';
        $boundary_after  = '(?=[\s.,;:!?\'"()\[\]\-\x{00AB}\x{00BB}\x{2013}\x{2014}\x{201C}\x{201D}\x{2018}\x{2019}]|$)';

        // Build flags: always Unicode mode, optionally case-insensitive.
        $flags = 'u';
        if (empty($rule->case_sensitive)) {
            $flags .= 'i';
        }

        $pattern = '/' . $boundary_before . $escaped . $boundary_after . '/' . $flags;

        // Validate the pattern compiles correctly.
        if (@preg_match($pattern, '') === false) {
            return '';
        }

        return $pattern;
    }

    /**
     * Build accent-insensitive alternation for common Spanish accented characters.
     *
     * Replaces each accented/unaccented vowel with a character class that matches
     * both forms. Preserves n-tilde as distinct from n (they are different letters
     * in Spanish).
     *
     * @param string $escaped The preg_quote'd keyword.
     * @return string The keyword with accent alternation applied.
     */
    private function build_accent_alternation(string $escaped): string
    {
        // Map of base vowels to their accented alternation character classes.
        // Note: n-tilde (n) is NOT mapped to n because they are distinct letters in Spanish.
        $replacements = [
            'a' => '[a\x{00E1}]',  // a, a-acute
            'A' => '[A\x{00C1}]',  // A, A-acute
            'e' => '[e\x{00E9}]',  // e, e-acute
            'E' => '[E\x{00C9}]',  // E, E-acute
            'i' => '[i\x{00ED}]',  // i, i-acute
            'I' => '[I\x{00CD}]',  // I, I-acute
            'o' => '[o\x{00F3}]',  // o, o-acute
            'O' => '[O\x{00D3}]',  // O, O-acute
            'u' => '[u\x{00FA}\x{00FC}]',  // u, u-acute, u-dieresis
            'U' => '[U\x{00DA}\x{00DC}]',  // U, U-acute, U-dieresis
        ];

        // Apply replacements character by character to avoid replacing
        // inside already-built character classes.
        $result = '';
        $len = strlen($escaped);
        $i = 0;

        while ($i < $len) {
            $char = $escaped[$i];

            // Check for multi-byte UTF-8 sequences (accented characters in the keyword itself).
            $byte = ord($char);
            if ($byte >= 0xC0 && $byte < 0xE0 && ($i + 1) < $len) {
                $mb_char = $escaped[$i] . $escaped[$i + 1];
                $replacement = $this->get_accent_class_for_mb($mb_char);
                if ($replacement !== null) {
                    $result .= $replacement;
                    $i += 2;
                    continue;
                }
                $result .= $mb_char;
                $i += 2;
                continue;
            }

            if ($byte >= 0xE0 && $byte < 0xF0 && ($i + 2) < $len) {
                $result .= $escaped[$i] . $escaped[$i + 1] . $escaped[$i + 2];
                $i += 3;
                continue;
            }

            // Check if this is a preg_quote escaped character (backslash prefix).
            if ($char === '\\' && ($i + 1) < $len) {
                $result .= $escaped[$i] . $escaped[$i + 1];
                $i += 2;
                continue;
            }

            // Single-byte ASCII character: apply accent alternation if applicable.
            if (isset($replacements[$char])) {
                $result .= $replacements[$char];
            } else {
                $result .= $char;
            }

            $i++;
        }

        return $result;
    }

    /**
     * Get the accent alternation character class for a multi-byte accented character.
     *
     * @param string $mb_char A 2-byte UTF-8 character.
     * @return string|null The character class, or null if no mapping exists.
     */
    private function get_accent_class_for_mb(string $mb_char): ?string
    {
        // Map accented characters to their alternation classes.
        $map = [
            "\xC3\xA1" => '[a\x{00E1}]',      // a-acute -> a or a-acute
            "\xC3\x81" => '[A\x{00C1}]',       // A-acute
            "\xC3\xA9" => '[e\x{00E9}]',       // e-acute
            "\xC3\x89" => '[E\x{00C9}]',       // E-acute
            "\xC3\xAD" => '[i\x{00ED}]',       // i-acute
            "\xC3\x8D" => '[I\x{00CD}]',       // I-acute
            "\xC3\xB3" => '[o\x{00F3}]',       // o-acute
            "\xC3\x93" => '[O\x{00D3}]',       // O-acute
            "\xC3\xBA" => '[u\x{00FA}\x{00FC}]', // u-acute
            "\xC3\x9A" => '[U\x{00DA}\x{00DC}]', // U-acute
            "\xC3\xBC" => '[u\x{00FA}\x{00FC}]', // u-dieresis
            "\xC3\x9C" => '[U\x{00DA}\x{00DC}]', // U-dieresis
        ];

        return $map[$mb_char] ?? null;
    }

    /**
     * Check if a match position respects the minimum distance from other links.
     *
     * Ensures at least min_distance_chars characters between any two
     * auto-inserted links to prevent link clustering.
     *
     * @param int   $position          The global character position of the proposed match.
     * @param array $applied_positions Array of previously applied link positions.
     * @return bool True if the distance requirement is satisfied.
     */
    private function respects_min_distance(int $position, array $applied_positions): bool
    {
        foreach ($applied_positions as $applied_pos) {
            if (abs($position - $applied_pos) < $this->min_distance_chars) {
                return false;
            }
        }

        return true;
    }

    /**
     * Calculate word count for a plain-text string.
     *
     * Uses a Unicode-aware split on whitespace sequences, which handles
     * Spanish text correctly including words with accented characters.
     *
     * @param string $text Plain text (no HTML).
     * @return int Word count.
     */
    private function word_count(string $text): int
    {
        $text = trim($text);
        if ($text === '') {
            return 0;
        }

        // Split on Unicode whitespace sequences.
        $words = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);

        return is_array($words) ? count($words) : 0;
    }

    /**
     * Check if adding another link would exceed any density limit.
     *
     * Density limits checked:
     *   - Total links per post (default 10)
     *   - Links per 1000 words (default 5)
     *   - Affiliate links per post (default 3)
     *   - Affiliate ratio of total links (default 30%)
     *
     * @param int  $current_links   Number of links already applied.
     * @param int  $word_count      Total word count of the post.
     * @param int  $affiliate_count Number of affiliate links already applied.
     * @param bool $is_affiliate    Whether the proposed link is an affiliate link.
     * @return bool True if adding the link would exceed a limit.
     */
    private function exceeds_density(
        int $current_links,
        int $word_count,
        int $affiliate_count,
        bool $is_affiliate
    ): bool {
        // Total links cap.
        if ($current_links >= $this->max_links_per_post) {
            return true;
        }

        // Per-1000-words density limit.
        if ($word_count > 0) {
            $max_for_length = (int) ceil(($word_count / 1000) * $this->max_links_per_1000_words);
            // Ensure at least 1 link is allowed for very short content that passed the min length check.
            $max_for_length = max($max_for_length, 1);
            if ($current_links >= $max_for_length) {
                return true;
            }
        }

        // Affiliate-specific limits.
        if ($is_affiliate) {
            // Hard cap on affiliate links per post.
            if ($affiliate_count >= $this->max_affiliate_per_post) {
                return true;
            }

            // Affiliate ratio limit (check what ratio would be after adding this link).
            $projected_total = $current_links + 1;
            $projected_affiliate = $affiliate_count + 1;
            if ($projected_total > 0 && ($projected_affiliate / $projected_total) > $this->max_affiliate_ratio) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if the target URL points to the current post (self-link prevention).
     *
     * Compares the target URL against the current post's permalink to prevent
     * a post from linking to itself.
     *
     * @param string $target_url The rule's target URL.
     * @param int    $post_id    The current post ID.
     * @return bool True if the target URL is the current post.
     */
    private function is_self_link(string $target_url, int $post_id): bool
    {
        // Get the current post's permalink.
        $permalink = get_permalink($post_id);
        if ($permalink === false) {
            return false;
        }

        // Normalize both URLs for comparison: remove trailing slashes, lowercase, remove scheme.
        $normalize = static function (string $url): string {
            $url = strtolower(trim($url));
            $url = rtrim($url, '/');
            // Remove protocol for comparison.
            $url = (string) preg_replace('#^https?://#', '', $url);
            // Remove www. prefix for comparison.
            $url = (string) preg_replace('#^www\.#', '', $url);
            return $url;
        };

        return $normalize($target_url) === $normalize($permalink);
    }
}
