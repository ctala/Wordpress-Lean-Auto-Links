<?php
declare(strict_types=1);

namespace LeanAutoLinks\Engine;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Parses HTML content into safe and unsafe zones for link injection.
 *
 * Uses DOMDocument with UTF-8 forcing (<?xml encoding="UTF-8"> prefix trick)
 * to reliably parse HTML content and identify text nodes that are safe for
 * automatic link insertion.
 *
 * Safe zones: text nodes NOT inside excluded elements.
 * Unsafe zones: text nodes inside <a>, headings, code, pre, script, etc.
 */
final class ContentParser
{
    /**
     * HTML elements that must NEVER contain auto-inserted links.
     *
     * Includes: existing links, headings (SEO), code blocks, scripts,
     * styles, form elements, iframes, SVG, and blockquotes.
     */
    private const EXCLUDED_TAGS = [
        'a', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'code', 'pre', 'script', 'style', 'button',
        'input', 'textarea', 'select', 'iframe', 'svg',
        'blockquote',
    ];

    /**
     * Parse content and return an array of text segments with metadata.
     *
     * Each segment is an associative array:
     *   - 'text'    => string  The text content of the node.
     *   - 'is_safe' => bool    Whether this text node can receive links.
     *   - 'node'    => DOMText The original DOM text node reference.
     *
     * @param string $content Raw HTML content (e.g. post_content after other filters).
     * @return array{segments: array<array{text: string, is_safe: bool, node: \DOMText}>, doc: \DOMDocument}
     */
    public function parse(string $content): array
    {
        if (trim($content) === '') {
            return ['segments' => [], 'doc' => new \DOMDocument()];
        }

        $doc = new \DOMDocument('1.0', 'UTF-8');

        // Suppress DOMDocument warnings for malformed HTML.
        $prev_errors = libxml_use_internal_errors(true);

        // UTF-8 forcing via XML encoding declaration prefix.
        // Wrap in a <div> to ensure a single root element for reliable parsing.
        $wrapped = '<?xml encoding="UTF-8"><div>' . $content . '</div>';
        $doc->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

        // Clear any libxml errors and restore previous state.
        libxml_clear_errors();
        libxml_use_internal_errors($prev_errors);

        $xpath = new \DOMXPath($doc);
        $text_nodes = $xpath->query('//text()');

        $segments = [];

        if ($text_nodes !== false) {
            foreach ($text_nodes as $text_node) {
                if (!($text_node instanceof \DOMText)) {
                    continue;
                }

                // Skip empty or whitespace-only text nodes.
                if (trim($text_node->nodeValue ?? '') === '') {
                    $segments[] = [
                        'text'    => $text_node->nodeValue ?? '',
                        'is_safe' => false,
                        'node'    => $text_node,
                    ];
                    continue;
                }

                $is_safe = !$this->is_inside_excluded($text_node);

                $segments[] = [
                    'text'    => $text_node->nodeValue ?? '',
                    'is_safe' => $is_safe,
                    'node'    => $text_node,
                ];
            }
        }

        return ['segments' => $segments, 'doc' => $doc];
    }

    /**
     * Rebuild content from the DOMDocument after link injection.
     *
     * Extracts the inner HTML of the wrapper <div>, stripping the XML
     * declaration and wrapper element that were added during parsing.
     *
     * @param \DOMDocument $doc The modified DOMDocument.
     * @return string Rebuilt HTML content.
     */
    public function rebuild(\DOMDocument $doc): string
    {
        $html = $doc->saveHTML();

        if ($html === false) {
            return '';
        }

        // Remove the XML processing instruction.
        $html = (string) preg_replace('/^<\?xml[^?]*\?>\s*/i', '', $html);

        // Extract content from the wrapper structure.
        // DOMDocument may wrap in <!DOCTYPE>, <html>, <body> tags.
        // We need to extract the inner content of our wrapper <div>.
        if (preg_match('/<div>(.*)<\/div>/s', $html, $matches)) {
            return $matches[1];
        }

        // Fallback: strip common wrapper tags added by DOMDocument.
        $html = (string) preg_replace('/^<!DOCTYPE[^>]*>\s*/i', '', $html);
        $html = (string) preg_replace('/<\/?html[^>]*>/i', '', $html);
        $html = (string) preg_replace('/<\/?head[^>]*>/i', '', $html);
        $html = (string) preg_replace('/<\/?body[^>]*>/i', '', $html);

        return trim($html);
    }

    /**
     * Inject a link into a text node by splitting it around the match.
     *
     * Replaces a portion of a text node with an anchor element, preserving
     * the surrounding text as sibling text nodes.
     *
     * @param \DOMText     $text_node    The text node containing the match.
     * @param int          $offset       Character offset within the text node.
     * @param int          $length       Length of the matched text.
     * @param string       $link_html    The complete <a> tag HTML to insert.
     * @param \DOMDocument $doc          The owning document.
     * @return \DOMText|null The new text node after the inserted link (for further matching).
     */
    public function inject_link(
        \DOMText $text_node,
        int $offset,
        int $length,
        string $link_html,
        \DOMDocument $doc
    ): ?\DOMText {
        $parent = $text_node->parentNode;
        if ($parent === null) {
            return null;
        }

        $full_text = $text_node->nodeValue ?? '';
        $before_text = mb_substr($full_text, 0, $offset, 'UTF-8');
        $after_text = mb_substr($full_text, $offset + $length, null, 'UTF-8');

        // Create a document fragment to hold the replacement nodes.
        $fragment = $doc->createDocumentFragment();

        // Add text before the match.
        if ($before_text !== '') {
            $fragment->appendChild($doc->createTextNode($before_text));
        }

        // Parse the link HTML and add it to the fragment.
        // Suppress warnings as the fragment loading can be strict.
        $temp_doc = new \DOMDocument('1.0', 'UTF-8');
        $prev_errors = libxml_use_internal_errors(true);
        $temp_doc->loadHTML(
            '<?xml encoding="UTF-8"><div>' . $link_html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors($prev_errors);

        // Import the link node into the main document.
        $temp_body = $temp_doc->getElementsByTagName('div')->item(0);
        if ($temp_body !== null) {
            foreach ($temp_body->childNodes as $child) {
                $imported = $doc->importNode($child, true);
                $fragment->appendChild($imported);
            }
        }

        // Create the "after" text node.
        $after_node = null;
        if ($after_text !== '') {
            $after_node = $doc->createTextNode($after_text);
            $fragment->appendChild($after_node);
        }

        // Replace the original text node with the fragment.
        $parent->replaceChild($fragment, $text_node);

        return $after_node;
    }

    /**
     * Check if a text node is inside an excluded element.
     *
     * Walks up the DOM tree from the given node, checking each ancestor
     * element against the excluded tags list.
     *
     * @param \DOMNode $node The text node to check.
     * @return bool True if the node is inside an excluded element.
     */
    private function is_inside_excluded(\DOMNode $node): bool
    {
        $current = $node->parentNode;

        while ($current !== null) {
            if ($current instanceof \DOMElement) {
                $tag_name = strtolower($current->nodeName);

                if (in_array($tag_name, self::EXCLUDED_TAGS, true)) {
                    return true;
                }
            }

            $current = $current->parentNode;
        }

        return false;
    }
}
