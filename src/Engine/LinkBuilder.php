<?php
declare(strict_types=1);

namespace LeanAutoLinks\Engine;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Builds HTML anchor tags for link injection based on rule type and properties.
 *
 * Link types and their attributes:
 *   - Internal: <a href="url" title="keyword">keyword</a>
 *   - Entity:   <a href="url" title="keyword">keyword</a>
 *   - Affiliate: <a href="url" rel="sponsored nofollow noopener" target="_blank" title="keyword">keyword</a>
 *
 * All affiliate links always receive rel="sponsored nofollow noopener" and target="_blank"
 * regardless of the individual nofollow/sponsored flags on the rule.
 */
final class LinkBuilder
{
    /**
     * Build an anchor tag for the given rule and matched keyword text.
     *
     * The matched_text parameter preserves the original casing from the content,
     * so "WordPress" stays "WordPress" even if the rule keyword is "wordpress".
     *
     * @param object $rule         The rule object from lw_rules (with properties: target_url, rule_type, keyword, nofollow, sponsored).
     * @param string $matched_text The exact text matched in the content (preserves original casing).
     * @return string Complete HTML anchor tag.
     */
    public function build(object $rule, string $matched_text): string
    {
        $url = esc_url($rule->target_url);
        $title = esc_attr($matched_text);
        $text = esc_html($matched_text);

        $rel = $this->build_rel($rule);
        $target = $this->build_target($rule);

        $attrs = sprintf('href="%s"', $url);

        if ($rel !== '') {
            $attrs .= sprintf(' rel="%s"', esc_attr($rel));
        }

        if ($target !== '') {
            $attrs .= sprintf(' target="%s"', esc_attr($target));
        }

        $attrs .= sprintf(' title="%s"', $title);

        return sprintf('<a %s>%s</a>', $attrs, $text);
    }

    /**
     * Build the rel attribute string based on rule properties.
     *
     * Affiliate rules always get "sponsored nofollow noopener" regardless of
     * individual flag settings. For other rule types, flags are respected.
     *
     * @param object $rule The rule object.
     * @return string Space-separated rel values, or empty string if none apply.
     */
    private function build_rel(object $rule): string
    {
        $rel_parts = [];

        if ($rule->rule_type === 'affiliate') {
            // Affiliate links always get the full safety suite.
            return 'sponsored nofollow noopener';
        }

        // For non-affiliate rules, respect individual flags.
        if (!empty($rule->sponsored)) {
            $rel_parts[] = 'sponsored';
        }

        if (!empty($rule->nofollow)) {
            $rel_parts[] = 'nofollow';
        }

        // Add noopener for any link that opens in a new tab.
        if (!empty($rel_parts)) {
            $rel_parts[] = 'noopener';
        }

        return implode(' ', $rel_parts);
    }

    /**
     * Build the target attribute based on rule type.
     *
     * Affiliate links always open in a new tab. Other rule types
     * stay within the current tab by default.
     *
     * @param object $rule The rule object.
     * @return string Target attribute value, or empty string for same-tab.
     */
    private function build_target(object $rule): string
    {
        if ($rule->rule_type === 'affiliate') {
            return '_blank';
        }

        return '';
    }
}
