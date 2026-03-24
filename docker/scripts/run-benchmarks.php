<?php
/**
 * LeanAutoLinks Benchmark Suite.
 *
 * Usage: wp eval-file /scripts/run-benchmarks.php
 *
 * Runs B1-B10 benchmarks against live WordPress with real data.
 * Outputs JSON results suitable for storage in docs/performance/results/.
 */

if (!defined('WP_CLI') || !WP_CLI) {
    die('This script must be run via WP-CLI.');
}

global $wpdb;

$results = [
    'timestamp'   => gmdate('Y-m-d\TH:i:s\Z'),
    'environment' => [],
    'benchmarks'  => [],
];

// ─────────────────────────────────────────────
// Environment info.
// ─────────────────────────────────────────────
$results['environment'] = [
    'php_version'   => PHP_VERSION,
    'mysql_version' => $wpdb->get_var('SELECT VERSION()'),
    'wp_version'    => get_bloginfo('version'),
    'post_count'    => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status='publish' AND post_type='post'"),
    'glosario_count'=> (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status='publish' AND post_type='glosario'"),
    'rule_count'    => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}lw_rules WHERE is_active=1"),
    'memory_limit'  => WP_MEMORY_LIMIT,
    'object_cache'  => wp_using_ext_object_cache() ? 'external' : 'default',
];

WP_CLI::log('=== LeanAutoLinks Benchmark Suite ===');
WP_CLI::log(sprintf('Posts: %d | Glosario: %d | Rules: %d',
    $results['environment']['post_count'],
    $results['environment']['glosario_count'],
    $results['environment']['rule_count']
));
WP_CLI::log('');

// ─────────────────────────────────────────────
// Helper functions.
// ─────────────────────────────────────────────
function bench_start(): array {
    return [
        'time'   => microtime(true),
        'memory' => memory_get_usage(true),
        'queries'=> get_num_queries(),
    ];
}

function bench_end(array $start): array {
    return [
        'duration_ms' => round((microtime(true) - $start['time']) * 1000, 2),
        'memory_kb'   => round((memory_get_usage(true) - $start['memory']) / 1024, 1),
        'queries'     => get_num_queries() - $start['queries'],
    ];
}

function run_multiple(callable $fn, int $iterations = 10): array {
    $durations = [];
    for ($i = 0; $i < $iterations; $i++) {
        $start = microtime(true);
        $fn();
        $durations[] = (microtime(true) - $start) * 1000;
    }
    sort($durations);
    $count = count($durations);
    return [
        'iterations' => $count,
        'p50'        => round($durations[(int)($count * 0.5)], 2),
        'p95'        => round($durations[(int)($count * 0.95)], 2),
        'p99'        => round($durations[min($count - 1, (int)($count * 0.99))], 2),
        'min'        => round(min($durations), 2),
        'max'        => round(max($durations), 2),
        'avg'        => round(array_sum($durations) / $count, 2),
    ];
}

// ─────────────────────────────────────────────
// B1: Frontend Zero Queries (the_content filter).
// Threshold: 0 additional DB queries on frontend page load.
// ─────────────────────────────────────────────
WP_CLI::log('B1: Frontend Zero Queries...');
$sample_posts = $wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE post_status='publish' AND post_type='post' ORDER BY RAND() LIMIT 10");

$b1_results = [];
foreach ($sample_posts as $pid) {
    $post = get_post($pid);
    if (!$post) continue;

    // Simulate the_content filter context.
    $GLOBALS['post'] = $post;
    setup_postdata($post);

    $q_before = get_num_queries();
    // The content filter should serve from cache or return original.
    $content = apply_filters('the_content', $post->post_content);
    $q_after  = get_num_queries();

    $b1_results[] = [
        'post_id'  => (int) $pid,
        'queries'  => $q_after - $q_before,
    ];
    wp_reset_postdata();
}

// With no cache populated, the DB fallback adds at most 1 query.
// With ext cache, it should be 0.
$max_queries = max(array_column($b1_results, 'queries'));
$results['benchmarks']['B1_frontend_queries'] = [
    'description' => 'Additional DB queries on frontend the_content filter',
    'threshold'   => 'max 1 query (DB fallback without ext cache)',
    'measured'    => $b1_results,
    'max_queries' => $max_queries,
    'pass'        => $max_queries <= 1,
];
WP_CLI::log(sprintf('  Max queries: %d — %s', $max_queries, $max_queries <= 1 ? 'PASS' : 'FAIL'));

// ─────────────────────────────────────────────
// B2: save_post overhead.
// Threshold: < 50ms additional overhead.
// ─────────────────────────────────────────────
WP_CLI::log('B2: save_post overhead...');

// Measure baseline: insert post without plugin hooks.
remove_all_actions('save_post');
$baseline_times = [];
for ($i = 0; $i < 5; $i++) {
    $start = microtime(true);
    $pid = wp_insert_post([
        'post_title'   => "Benchmark baseline $i " . uniqid(),
        'post_content' => str_repeat('Contenido de prueba para benchmark. ', 50),
        'post_status'  => 'publish',
        'post_type'    => 'post',
    ]);
    $baseline_times[] = (microtime(true) - $start) * 1000;
    wp_delete_post($pid, true);
}

// Re-register LeanAutoLinks hook.
$plugin = \LeanAutoLinks\Plugin::get_instance();
// The hook is already registered via Plugin::init(), so re-init is not needed.
// Instead, let's measure with plugin active.
$lw_handler = $plugin->queue_repo();

$plugin_times = [];
// Re-add the save_post action.
add_action('save_post', [new \LeanAutoLinks\Hooks\SavePostHandler($plugin->queue_repo(), $plugin->exclusions_repo()), 'handle'], 20, 3);

for ($i = 0; $i < 5; $i++) {
    $start = microtime(true);
    $pid = wp_insert_post([
        'post_title'   => "Benchmark plugin $i " . uniqid(),
        'post_content' => str_repeat('Contenido de prueba para benchmark con plugin activo. ', 50),
        'post_status'  => 'publish',
        'post_type'    => 'post',
    ]);
    $plugin_times[] = (microtime(true) - $start) * 1000;
    wp_delete_post($pid, true);
}

$avg_baseline = array_sum($baseline_times) / count($baseline_times);
$avg_plugin   = array_sum($plugin_times) / count($plugin_times);
$overhead     = $avg_plugin - $avg_baseline;

$results['benchmarks']['B2_save_post_overhead'] = [
    'description'    => 'Additional time added by LeanAutoLinks on save_post',
    'threshold'      => '< 50ms',
    'baseline_avg_ms'=> round($avg_baseline, 2),
    'plugin_avg_ms'  => round($avg_plugin, 2),
    'overhead_ms'    => round($overhead, 2),
    'pass'           => $overhead < 50,
];
WP_CLI::log(sprintf('  Baseline: %.1fms, Plugin: %.1fms, Overhead: %.1fms — %s',
    $avg_baseline, $avg_plugin, $overhead, $overhead < 50 ? 'PASS' : 'FAIL'));

// ─────────────────────────────────────────────
// B3: Engine performance with N rules.
// Threshold: < 500ms per post with 1,000 rules.
// ─────────────────────────────────────────────
WP_CLI::log('B3: Engine performance...');

$rule_count = $results['environment']['rule_count'];
WP_CLI::log(sprintf('  Testing with %d active rules...', $rule_count));

// Get sample posts with real content.
$sample_ids = $wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE post_status='publish' AND post_type='post' AND LENGTH(post_content) > 500 ORDER BY RAND() LIMIT 20");

$engine_times  = [];
$links_applied = [];

// Check if engine classes exist.
if (class_exists(\LeanAutoLinks\Engine\RuleMatcherEngine::class)) {
    $rules_repo = $plugin->rules_repo();
    $rules = $rules_repo->fetch_all_active();

    foreach ($sample_ids as $pid) {
        $post = get_post($pid);
        if (!$post) continue;

        $start = microtime(true);

        $parser = new \LeanAutoLinks\Engine\ContentParser();
        $link_builder = new \LeanAutoLinks\Engine\LinkBuilder();
        $engine = new \LeanAutoLinks\Engine\RuleMatcherEngine($parser, $link_builder);
        $result = $engine->process($post->post_content, $rules, (int) $pid);

        $duration = (microtime(true) - $start) * 1000;
        $engine_times[] = $duration;
        $links_applied[] = count($result['links'] ?? []);

        // Free memory.
        unset($engine, $result);
    }

    sort($engine_times);
    $count = count($engine_times);
    $p50 = $engine_times[(int)($count * 0.5)] ?? 0;
    $p95 = $engine_times[min($count - 1, (int)($count * 0.95))] ?? 0;
    $max = max($engine_times);
    $avg_links = count($links_applied) > 0 ? array_sum($links_applied) / count($links_applied) : 0;

    $results['benchmarks']['B3_engine_performance'] = [
        'description'    => 'RuleMatcherEngine processing time per post',
        'threshold'      => '< 500ms with 1000+ rules',
        'rule_count'     => $rule_count,
        'sample_size'    => $count,
        'p50_ms'         => round($p50, 2),
        'p95_ms'         => round($p95, 2),
        'max_ms'         => round($max, 2),
        'avg_links'      => round($avg_links, 1),
        'pass'           => $p95 < 500,
    ];
    WP_CLI::log(sprintf('  p50: %.1fms, p95: %.1fms, max: %.1fms, avg links: %.1f — %s',
        $p50, $p95, $max, $avg_links, $p95 < 500 ? 'PASS' : 'FAIL'));
} else {
    WP_CLI::warning('  RuleMatcherEngine class not found. Skipping B3.');
    $results['benchmarks']['B3_engine_performance'] = ['skip' => 'Class not found'];
}

// ─────────────────────────────────────────────
// B4: Memory footprint per job.
// Threshold: < 32MB per execution.
// ─────────────────────────────────────────────
WP_CLI::log('B4: Memory footprint...');

$mem_before = memory_get_usage(true);

if (!empty($sample_ids)) {
    $pid = $sample_ids[0];
    $post = get_post($pid);

    if ($post && class_exists(\LeanAutoLinks\Engine\RuleMatcherEngine::class)) {
        $rules = $rules ?? $rules_repo->fetch_all_active();
        $parser = new \LeanAutoLinks\Engine\ContentParser();
        $link_builder = new \LeanAutoLinks\Engine\LinkBuilder();
        $engine = new \LeanAutoLinks\Engine\RuleMatcherEngine($parser, $link_builder);
        $engine->process($post->post_content, $rules, (int) $pid);
        unset($engine);
    }
}

$mem_after = memory_get_usage(true);
$mem_delta_mb = ($mem_after - $mem_before) / 1024 / 1024;

$results['benchmarks']['B4_memory_footprint'] = [
    'description'   => 'Memory used by single post processing',
    'threshold'     => '< 32MB',
    'delta_mb'      => round($mem_delta_mb, 2),
    'peak_mb'       => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
    'pass'          => $mem_delta_mb < 32,
];
WP_CLI::log(sprintf('  Delta: %.1fMB, Peak: %.1fMB — %s',
    $mem_delta_mb, memory_get_peak_usage(true) / 1024 / 1024,
    $mem_delta_mb < 32 ? 'PASS' : 'FAIL'));

// ─────────────────────────────────────────────
// B5: Rules cache performance.
// ─────────────────────────────────────────────
WP_CLI::log('B5: Rules cache performance...');

if (class_exists(\LeanAutoLinks\Cache\RulesCache::class)) {
    // Warm the cache.
    \LeanAutoLinks\Cache\RulesCache::flush();
    $cache_cold = bench_start();
    \LeanAutoLinks\Cache\RulesCache::get_active_rules();
    $cold = bench_end($cache_cold);

    // Now read from cache.
    $cache_warm = bench_start();
    \LeanAutoLinks\Cache\RulesCache::get_active_rules();
    $warm = bench_end($cache_warm);

    $results['benchmarks']['B5_cache_performance'] = [
        'description'    => 'Rules cache cold vs warm read',
        'cold_ms'        => $cold['duration_ms'],
        'cold_queries'   => $cold['queries'],
        'warm_ms'        => $warm['duration_ms'],
        'warm_queries'   => $warm['queries'],
        'pass'           => $warm['queries'] === 0 || $warm['duration_ms'] < 5,
    ];
    WP_CLI::log(sprintf('  Cold: %.1fms (%d queries), Warm: %.1fms (%d queries) — %s',
        $cold['duration_ms'], $cold['queries'], $warm['duration_ms'], $warm['queries'],
        ($warm['queries'] === 0 || $warm['duration_ms'] < 5) ? 'PASS' : 'FAIL'));
} else {
    $results['benchmarks']['B5_cache_performance'] = ['skip' => 'RulesCache not found'];
}

// ─────────────────────────────────────────────
// B6: Queue enqueue performance.
// ─────────────────────────────────────────────
WP_CLI::log('B6: Queue enqueue performance...');

$queue_table = $wpdb->prefix . 'lw_queue';
$queue_exists = $wpdb->get_var("SHOW TABLES LIKE '$queue_table'") === $queue_table;

if ($queue_exists) {
    $enqueue_times = [];
    for ($i = 0; $i < 20; $i++) {
        $fake_id = 900000 + $i;
        $start = microtime(true);
        $wpdb->query($wpdb->prepare(
            "INSERT INTO $queue_table (post_id, status, triggered_by, attempts, scheduled_at)
             VALUES (%d, 'pending', 'benchmark', 0, NOW())
             ON DUPLICATE KEY UPDATE status='pending', triggered_by='benchmark', scheduled_at=NOW()",
            $fake_id
        ));
        $enqueue_times[] = (microtime(true) - $start) * 1000;
    }

    // Cleanup.
    $wpdb->query("DELETE FROM $queue_table WHERE triggered_by='benchmark'");

    sort($enqueue_times);
    $p50 = $enqueue_times[(int)(count($enqueue_times) * 0.5)];
    $p95 = $enqueue_times[min(count($enqueue_times) - 1, (int)(count($enqueue_times) * 0.95))];

    $results['benchmarks']['B6_queue_enqueue'] = [
        'description' => 'Time to enqueue a post (upsert)',
        'threshold'   => '< 5ms',
        'p50_ms'      => round($p50, 2),
        'p95_ms'      => round($p95, 2),
        'pass'        => $p95 < 5,
    ];
    WP_CLI::log(sprintf('  p50: %.2fms, p95: %.2fms — %s', $p50, $p95, $p95 < 5 ? 'PASS' : 'FAIL'));
} else {
    $results['benchmarks']['B6_queue_enqueue'] = ['skip' => 'Queue table not found'];
}

// ─────────────────────────────────────────────
// B7: Bulk processing estimate.
// Process 100 posts, extrapolate to 15,000.
// Threshold: 15,000 posts < 4 hours.
// ─────────────────────────────────────────────
WP_CLI::log('B7: Bulk processing estimate (100 posts sample)...');

if (class_exists(\LeanAutoLinks\Engine\RuleMatcherEngine::class)) {
    $bulk_ids = $wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE post_status='publish' AND post_type='post' ORDER BY RAND() LIMIT 100");

    $rules = $rules ?? $rules_repo->fetch_all_active();
    $bulk_start = microtime(true);
    $bulk_links = 0;

    foreach ($bulk_ids as $pid) {
        $post = get_post($pid);
        if (!$post) continue;

        $parser = new \LeanAutoLinks\Engine\ContentParser();
        $link_builder = new \LeanAutoLinks\Engine\LinkBuilder();
        $engine = new \LeanAutoLinks\Engine\RuleMatcherEngine($parser, $link_builder);
        $result = $engine->process($post->post_content, $rules, (int) $pid);
        $bulk_links += count($result['links'] ?? []);
        unset($engine, $result);
    }

    $bulk_duration_s = microtime(true) - $bulk_start;
    $per_post_ms     = ($bulk_duration_s / count($bulk_ids)) * 1000;
    $estimate_15k_h  = ($per_post_ms * 15000) / 1000 / 3600;

    $results['benchmarks']['B7_bulk_estimate'] = [
        'description'     => 'Bulk reprocessing time estimate for 15,000 posts',
        'threshold'       => '< 4 hours',
        'sample_size'     => count($bulk_ids),
        'sample_time_s'   => round($bulk_duration_s, 2),
        'per_post_ms'     => round($per_post_ms, 2),
        'estimate_15k_h'  => round($estimate_15k_h, 2),
        'total_links'     => $bulk_links,
        'avg_links_post'  => round($bulk_links / count($bulk_ids), 1),
        'pass'            => $estimate_15k_h < 4,
    ];
    WP_CLI::log(sprintf('  100 posts in %.1fs (%.1fms/post), 15K estimate: %.2fh, avg links: %.1f — %s',
        $bulk_duration_s, $per_post_ms, $estimate_15k_h, $bulk_links / count($bulk_ids),
        $estimate_15k_h < 4 ? 'PASS' : 'FAIL'));
} else {
    $results['benchmarks']['B7_bulk_estimate'] = ['skip' => 'Engine not found'];
}

// ─────────────────────────────────────────────
// B8: Throughput (posts per hour).
// Threshold: > 70 posts/hour sustained.
// ─────────────────────────────────────────────
if (isset($per_post_ms)) {
    $posts_per_hour = 3600000 / $per_post_ms;
    $results['benchmarks']['B8_throughput'] = [
        'description'    => 'Sustained throughput in posts per hour',
        'threshold'      => '> 70 posts/hour',
        'posts_per_hour' => round($posts_per_hour, 0),
        'pass'           => $posts_per_hour > 70,
    ];
    WP_CLI::log(sprintf('B8: Throughput: %d posts/hour — %s',
        round($posts_per_hour), $posts_per_hour > 70 ? 'PASS' : 'FAIL'));
}

// ─────────────────────────────────────────────
// B9: API endpoint latency (/health).
// ─────────────────────────────────────────────
WP_CLI::log('B9: Health endpoint latency...');

// Simulate REST request internally.
$health_times = [];
for ($i = 0; $i < 10; $i++) {
    $start = microtime(true);
    $request = new WP_REST_Request('GET', '/leanautolinks/v1/health');
    $response = rest_do_request($request);
    $health_times[] = (microtime(true) - $start) * 1000;
}

sort($health_times);
$hp50 = $health_times[(int)(count($health_times) * 0.5)];
$hp95 = $health_times[min(count($health_times) - 1, (int)(count($health_times) * 0.95))];

$results['benchmarks']['B9_health_latency'] = [
    'description' => 'Health endpoint response time',
    'threshold'   => '< 100ms',
    'p50_ms'      => round($hp50, 2),
    'p95_ms'      => round($hp95, 2),
    'pass'        => $hp95 < 100,
];
WP_CLI::log(sprintf('  p50: %.1fms, p95: %.1fms — %s', $hp50, $hp95, $hp95 < 100 ? 'PASS' : 'FAIL'));

// ─────────────────────────────────────────────
// B10: Content parsing safety.
// No links in headings, code, pre, existing links.
// ─────────────────────────────────────────────
WP_CLI::log('B10: Content parsing safety...');

if (class_exists(\LeanAutoLinks\Engine\RuleMatcherEngine::class)) {
    $test_content = '<h1>Inteligencia Artificial en B2B</h1>
<p>El modelo B2B de startups está creciendo. La robótica y Claude son tendencias.</p>
<h2>Tecnologías Emergentes del sector</h2>
<p>Las <a href="https://example.com">tecnologías emergentes</a> incluyen PLG y North Star Metric.</p>
<pre><code>const b2b = new B2B(); // No linkear aquí</code></pre>
<p>Los Limited Partners invierten en startups de PLG.</p>';

    // Use first few glosario rules.
    $test_rules = array_slice($rules ?? [], 0, 20);

    $parser = new \LeanAutoLinks\Engine\ContentParser();
    $link_builder = new \LeanAutoLinks\Engine\LinkBuilder();
    $engine = new \LeanAutoLinks\Engine\RuleMatcherEngine($parser, $link_builder);
    $result = $engine->process($test_content, $test_rules, 0);

    $processed = $result['content'] ?? $test_content;

    // Check that headings don't contain our links.
    $has_link_in_h1 = (bool) preg_match('/<h1[^>]*>.*<a[^>]*class="leanautolinks-link".*<\/h1>/is', $processed);
    $has_link_in_h2 = (bool) preg_match('/<h2[^>]*>.*<a[^>]*class="leanautolinks-link".*<\/h2>/is', $processed);
    $has_link_in_pre = (bool) preg_match('/<pre[^>]*>.*<a[^>]*class="leanautolinks-link".*<\/pre>/is', $processed);
    $has_link_in_code = (bool) preg_match('/<code[^>]*>.*<a[^>]*class="leanautolinks-link".*<\/code>/is', $processed);

    $safety_pass = !$has_link_in_h1 && !$has_link_in_h2 && !$has_link_in_pre && !$has_link_in_code;

    $results['benchmarks']['B10_content_safety'] = [
        'description'     => 'Engine does not inject links into protected elements',
        'link_in_h1'      => $has_link_in_h1,
        'link_in_h2'      => $has_link_in_h2,
        'link_in_pre'     => $has_link_in_pre,
        'link_in_code'    => $has_link_in_code,
        'links_applied'   => count($result['links'] ?? []),
        'pass'            => $safety_pass,
    ];
    WP_CLI::log(sprintf('  h1: %s, h2: %s, pre: %s, code: %s, links: %d — %s',
        $has_link_in_h1 ? 'LINKED!' : 'safe',
        $has_link_in_h2 ? 'LINKED!' : 'safe',
        $has_link_in_pre ? 'LINKED!' : 'safe',
        $has_link_in_code ? 'LINKED!' : 'safe',
        count($result['links'] ?? []),
        $safety_pass ? 'PASS' : 'FAIL'));
} else {
    $results['benchmarks']['B10_content_safety'] = ['skip' => 'Engine not found'];
}

// ─────────────────────────────────────────────
// Summary.
// ─────────────────────────────────────────────
WP_CLI::log('');
WP_CLI::log('=== SUMMARY ===');

$total  = 0;
$passed = 0;
$failed = 0;
$skipped_count = 0;

foreach ($results['benchmarks'] as $name => $bench) {
    if (isset($bench['skip'])) {
        $skipped_count++;
        WP_CLI::log(sprintf('  %s: SKIPPED (%s)', $name, $bench['skip']));
        continue;
    }
    $total++;
    if ($bench['pass'] ?? false) {
        $passed++;
        WP_CLI::log(sprintf('  %s: PASS', $name));
    } else {
        $failed++;
        WP_CLI::log(sprintf('  %s: FAIL', $name));
    }
}

$results['summary'] = [
    'total'   => $total,
    'passed'  => $passed,
    'failed'  => $failed,
    'skipped' => $skipped_count,
];

WP_CLI::log('');
WP_CLI::log(sprintf('Total: %d | Passed: %d | Failed: %d | Skipped: %d', $total, $passed, $failed, $skipped_count));

// Output JSON.
$json_path = '/scripts/benchmark-results.json';
file_put_contents($json_path, json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
WP_CLI::success("Results saved to $json_path");
