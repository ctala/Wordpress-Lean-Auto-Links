<?php
/**
 * Test the_content filter with posts that have LeanAutoLinks links.
 */
if (!defined('WP_CLI') || !WP_CLI) {
    die('WP-CLI only.');
}

global $wpdb;

// Get posts that LeanAutoLinks has processed (have stored content).
$linked_ids = $wpdb->get_col(
    "SELECT DISTINCT post_id FROM {$wpdb->prefix}lw_applied_links WHERE rule_id = 0 LIMIT 20"
);

WP_CLI::log(sprintf('Testing the_content on %d posts with stored links...', count($linked_ids)));
WP_CLI::log('');

$lw_times   = [];
$lw_queries = [];

foreach ($linked_ids as $pid) {
    $post = get_post($pid);
    if (!$post) {
        continue;
    }

    $GLOBALS['post'] = $post;
    setup_postdata($post);

    $q_before = get_num_queries();
    $start    = microtime(true);
    $content  = apply_filters('the_content', $post->post_content);
    $duration = (microtime(true) - $start) * 1000;
    $queries  = get_num_queries() - $q_before;

    $lw_times[]   = $duration;
    $lw_queries[] = $queries;

    // Check if our links are in the output.
    preg_match_all('/<a [^>]*href="[^"]*"[^>]*title="[^"]*"[^>]*>/', $content, $lw_links);
    $link_count = count($lw_links[0]);

    WP_CLI::log(sprintf(
        '  Post %d: %.1fms, %d queries, lw_links=%d',
        $pid, $duration, $queries, $link_count
    ));

    wp_reset_postdata();
}

WP_CLI::log('');
WP_CLI::log('=== SUMMARY ===');

sort($lw_times);
$count = count($lw_times);

if ($count > 0) {
    $p50 = $lw_times[(int) ($count * 0.5)];
    $p95 = $lw_times[min($count - 1, (int) ($count * 0.95))];

    WP_CLI::log(sprintf('the_content latency (LeanAutoLinks with stored links):'));
    WP_CLI::log(sprintf('  p50: %.2fms', $p50));
    WP_CLI::log(sprintf('  p95: %.2fms', $p95));
    WP_CLI::log(sprintf('  max: %.2fms', max($lw_times)));
    WP_CLI::log(sprintf('  avg queries: %.1f', array_sum($lw_queries) / count($lw_queries)));
    WP_CLI::log(sprintf('  max queries: %d', max($lw_queries)));
} else {
    WP_CLI::warning('No processed posts found. Run the engine first.');
}

// Now verify the actual HTTP response on a sample post.
WP_CLI::log('');
if (!empty($linked_ids)) {
    $sample_id  = $linked_ids[0];
    $sample_url = get_permalink($sample_id);
    WP_CLI::log(sprintf('Sample post for browser verification:'));
    WP_CLI::log(sprintf('  ID: %d', $sample_id));
    WP_CLI::log(sprintf('  Title: %s', get_the_title($sample_id)));
    WP_CLI::log(sprintf('  URL: %s', $sample_url));

    $stored = $wpdb->get_var($wpdb->prepare(
        "SELECT processed_content FROM {$wpdb->prefix}lw_applied_links WHERE post_id = %d AND rule_id = 0",
        $sample_id
    ));
    if ($stored) {
        preg_match_all('/<a [^>]+>[^<]+<\/a>/', $stored, $all_links);
        WP_CLI::log(sprintf('  Links in stored content: %d', count($all_links[0])));
        foreach ($all_links[0] as $link) {
            // Only show LeanAutoLinks-generated links (have title attr).
            if (strpos($link, 'title=') !== false) {
                WP_CLI::log(sprintf('    %s', $link));
            }
        }
    }
}
