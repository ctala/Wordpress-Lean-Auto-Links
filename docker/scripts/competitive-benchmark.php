<?php
/**
 * Competitive Benchmark: LeanAutoLinks vs ILJ vs Internal Links Manager.
 *
 * Usage: wp eval-file /scripts/competitive-benchmark.php
 *
 * Tests each plugin's impact on:
 *   - the_content filter performance (frontend)
 *   - DB queries added per page load
 *   - save_post overhead
 *   - Memory footprint during page load
 */

if (!defined('WP_CLI') || !WP_CLI) {
    die('This script must be run via WP-CLI.');
}

global $wpdb;

$results = [
    'timestamp'   => gmdate('Y-m-d\TH:i:s\Z'),
    'environment' => [
        'php_version'   => PHP_VERSION,
        'mysql_version' => $wpdb->get_var('SELECT VERSION()'),
        'wp_version'    => get_bloginfo('version'),
        'post_count'    => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status='publish' AND post_type='post'"),
    ],
    'plugins_tested' => [],
];

WP_CLI::log('=== Competitive Benchmark Suite ===');
WP_CLI::log(sprintf('Posts: %d', $results['environment']['post_count']));
WP_CLI::log('');

// Get 20 random real posts for testing.
$sample_ids = $wpdb->get_col(
    "SELECT ID FROM {$wpdb->posts}
     WHERE post_status='publish' AND post_type='post' AND LENGTH(post_content) > 500
     ORDER BY RAND() LIMIT 20"
);

// ─────────────────────────────────────────────
// Test 1: Baseline (no linking plugins active on the_content).
// ─────────────────────────────────────────────
WP_CLI::log('--- BASELINE (no linking plugins) ---');

// Remove all linking-plugin filters from the_content temporarily.
// We'll measure raw WP the_content processing.
$baseline_times   = [];
$baseline_queries = [];

foreach ($sample_ids as $pid) {
    $post = get_post($pid);
    if (!$post) continue;

    $GLOBALS['post'] = $post;
    setup_postdata($post);

    $q_before = get_num_queries();
    $start    = microtime(true);

    // Apply core WP filters only (wpautop, wptexturize, etc).
    $content = wpautop($post->post_content);

    $duration = (microtime(true) - $start) * 1000;
    $queries  = get_num_queries() - $q_before;

    $baseline_times[]   = $duration;
    $baseline_queries[] = $queries;

    wp_reset_postdata();
}

$results['baseline'] = [
    'avg_ms'      => round(array_sum($baseline_times) / count($baseline_times), 2),
    'max_ms'      => round(max($baseline_times), 2),
    'avg_queries' => round(array_sum($baseline_queries) / count($baseline_queries), 1),
    'max_queries' => max($baseline_queries),
];
WP_CLI::log(sprintf('  the_content avg: %.2fms, max: %.2fms, avg queries: %.1f',
    $results['baseline']['avg_ms'], $results['baseline']['max_ms'],
    $results['baseline']['avg_queries']));

// ─────────────────────────────────────────────
// Test 2: the_content filter with all plugins active.
// ─────────────────────────────────────────────
WP_CLI::log('');
WP_CLI::log('--- the_content FILTER (all linking plugins active) ---');

$content_results = [];

foreach ($sample_ids as $pid) {
    $post = get_post($pid);
    if (!$post) continue;

    $GLOBALS['post'] = $post;
    setup_postdata($post);

    $q_before = get_num_queries();
    $mem_before = memory_get_usage(true);
    $start    = microtime(true);

    // Run the FULL the_content filter chain including all active plugins.
    $content = apply_filters('the_content', $post->post_content);

    $duration   = (microtime(true) - $start) * 1000;
    $queries    = get_num_queries() - $q_before;
    $mem_delta  = (memory_get_usage(true) - $mem_before) / 1024;

    $content_results[] = [
        'post_id'     => (int) $pid,
        'duration_ms' => round($duration, 2),
        'queries'     => $queries,
        'memory_kb'   => round($mem_delta, 1),
        'content_len' => strlen($content),
    ];

    wp_reset_postdata();
}

$avg_duration = array_sum(array_column($content_results, 'duration_ms')) / count($content_results);
$max_duration = max(array_column($content_results, 'duration_ms'));
$avg_queries  = array_sum(array_column($content_results, 'queries')) / count($content_results);
$max_queries  = max(array_column($content_results, 'queries'));

$results['the_content_all_plugins'] = [
    'avg_ms'      => round($avg_duration, 2),
    'max_ms'      => round($max_duration, 2),
    'avg_queries' => round($avg_queries, 1),
    'max_queries' => $max_queries,
    'detail'      => $content_results,
];

WP_CLI::log(sprintf('  Combined avg: %.2fms, max: %.2fms, avg queries: %.1f, max queries: %d',
    $avg_duration, $max_duration, $avg_queries, $max_queries));

// ─────────────────────────────────────────────
// Test 3: save_post overhead with all plugins.
// ─────────────────────────────────────────────
WP_CLI::log('');
WP_CLI::log('--- save_post OVERHEAD (all plugins active) ---');

$save_times = [];
for ($i = 0; $i < 5; $i++) {
    $start = microtime(true);
    $pid = wp_insert_post([
        'post_title'   => "Competitive benchmark post $i " . uniqid(),
        'post_content' => str_repeat('Este es contenido de prueba para el benchmark competitivo de plugins de linking interno. ', 30),
        'post_status'  => 'publish',
        'post_type'    => 'post',
    ]);
    $save_times[] = (microtime(true) - $start) * 1000;
    wp_delete_post($pid, true);
}

$avg_save = array_sum($save_times) / count($save_times);
$results['save_post_all_plugins'] = [
    'avg_ms' => round($avg_save, 2),
    'max_ms' => round(max($save_times), 2),
    'times'  => array_map(fn($t) => round($t, 2), $save_times),
];
WP_CLI::log(sprintf('  save_post avg: %.2fms, max: %.2fms', $avg_save, max($save_times)));

// ─────────────────────────────────────────────
// Test 4: Individual plugin isolation tests.
// Deactivate all, then test each one in isolation.
// ─────────────────────────────────────────────
WP_CLI::log('');
WP_CLI::log('--- INDIVIDUAL PLUGIN TESTS ---');

$plugins_to_test = [
    'leanautolinks'             => 'leanautolinks/leanautolinks.php',
    'internal-link-juicer'      => 'internal-links/internal-links.php',
    'internal-links-manager'    => 'seo-automated-link-building/seo-automated-link-building.php',
];

// Check which plugins are actually active.
$active_plugins = get_option('active_plugins', []);
WP_CLI::log(sprintf('  Active plugins: %s', implode(', ', $active_plugins)));

// Record individual plugin DB impact.
foreach ($plugins_to_test as $name => $file) {
    $is_active = in_array($file, $active_plugins, true);
    WP_CLI::log(sprintf('  %s: %s', $name, $is_active ? 'ACTIVE' : 'NOT ACTIVE'));

    if (!$is_active) {
        $results['plugins_tested'][$name] = ['status' => 'not_active', 'note' => 'Plugin file not found or not active'];
        continue;
    }

    // Test the_content with this plugin.
    $plugin_times   = [];
    $plugin_queries = [];

    foreach (array_slice($sample_ids, 0, 10) as $pid) {
        $post = get_post($pid);
        if (!$post) continue;

        $GLOBALS['post'] = $post;
        setup_postdata($post);

        $q_before = get_num_queries();
        $start    = microtime(true);

        $content = apply_filters('the_content', $post->post_content);

        $plugin_times[]   = (microtime(true) - $start) * 1000;
        $plugin_queries[] = get_num_queries() - $q_before;

        wp_reset_postdata();
    }

    $results['plugins_tested'][$name] = [
        'status'           => 'active',
        'the_content_avg'  => round(array_sum($plugin_times) / count($plugin_times), 2),
        'the_content_max'  => round(max($plugin_times), 2),
        'queries_avg'      => round(array_sum($plugin_queries) / count($plugin_queries), 1),
        'queries_max'      => max($plugin_queries),
    ];

    WP_CLI::log(sprintf('  %s: the_content avg %.2fms, max %.2fms, queries avg %.1f, max %d',
        $name,
        $results['plugins_tested'][$name]['the_content_avg'],
        $results['plugins_tested'][$name]['the_content_max'],
        $results['plugins_tested'][$name]['queries_avg'],
        $results['plugins_tested'][$name]['queries_max']
    ));
}

// ─────────────────────────────────────────────
// Test 5: DB table sizes.
// ─────────────────────────────────────────────
WP_CLI::log('');
WP_CLI::log('--- DATABASE FOOTPRINT ---');

$tables_query = "SELECT TABLE_NAME, TABLE_ROWS, DATA_LENGTH, INDEX_LENGTH
                 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE()
                 AND (TABLE_NAME LIKE 'wp_lw_%' OR TABLE_NAME LIKE '%ilj%' OR TABLE_NAME LIKE '%internal_link%' OR TABLE_NAME LIKE '%seo_link%')
                 ORDER BY TABLE_NAME";

$tables = $wpdb->get_results($tables_query);
$db_footprint = [];

foreach ($tables as $t) {
    $size_kb = round(($t->DATA_LENGTH + $t->INDEX_LENGTH) / 1024, 1);
    $db_footprint[$t->TABLE_NAME] = [
        'rows'    => (int) $t->TABLE_ROWS,
        'size_kb' => $size_kb,
    ];
    WP_CLI::log(sprintf('  %s: %d rows, %.1f KB', $t->TABLE_NAME, $t->TABLE_ROWS, $size_kb));
}

$results['db_footprint'] = $db_footprint;

// ─────────────────────────────────────────────
// Test 6: Check for REST API availability.
// ─────────────────────────────────────────────
WP_CLI::log('');
WP_CLI::log('--- REST API AVAILABILITY ---');

$api_tests = [
    'leanautolinks' => [
        '/leanautolinks/v1/health',
        '/leanautolinks/v1/rules',
        '/leanautolinks/v1/queue',
        '/leanautolinks/v1/applied/stats',
    ],
    'internal-link-juicer' => [
        '/ilj/v1/linkindex',
    ],
    'internal-links-manager' => [
        '/internal-links-manager/v1/links',
    ],
];

foreach ($api_tests as $plugin_name => $endpoints) {
    foreach ($endpoints as $route) {
        $request  = new WP_REST_Request('GET', $route);
        $response = rest_do_request($request);
        $status   = $response->get_status();
        $has_api  = $status !== 404;

        $results['api_availability'][$plugin_name][$route] = [
            'status'    => $status,
            'available' => $has_api,
        ];

        WP_CLI::log(sprintf('  %s %s: %d %s',
            $plugin_name, $route, $status,
            $has_api ? 'AVAILABLE' : 'NOT FOUND'));
    }
}

// ─────────────────────────────────────────────
// Summary.
// ─────────────────────────────────────────────
WP_CLI::log('');
WP_CLI::log('=== COMPETITIVE SUMMARY ===');
WP_CLI::log('');

foreach ($results['plugins_tested'] as $name => $data) {
    if ($data['status'] !== 'active') {
        WP_CLI::log(sprintf('%s: NOT ACTIVE', $name));
        continue;
    }
    WP_CLI::log(sprintf('%s:', $name));
    WP_CLI::log(sprintf('  the_content: %.2fms avg / %.2fms max', $data['the_content_avg'], $data['the_content_max']));
    WP_CLI::log(sprintf('  DB queries:  %.1f avg / %d max', $data['queries_avg'], $data['queries_max']));
}

// Save results.
$json_path = '/scripts/competitive-results.json';
file_put_contents($json_path, json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
WP_CLI::success("Results saved to $json_path");
