<?php
declare(strict_types=1);

/**
 * LeanAutoLinks uninstall handler.
 *
 * Fired when the plugin is deleted via the WordPress admin.
 * Drops all custom tables, removes all plugin options, and clears
 * any scheduled Action Scheduler actions.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

// 1. Drop all custom tables.
$tables = [
    $wpdb->prefix . 'lw_rules',
    $wpdb->prefix . 'lw_applied_links',
    $wpdb->prefix . 'lw_queue',
    $wpdb->prefix . 'lw_exclusions',
    $wpdb->prefix . 'lw_performance_log',
];

foreach ($tables as $table) {
    $wpdb->query("DROP TABLE IF EXISTS {$table}"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

// 2. Delete all options prefixed with leanautolinks_.
$options = $wpdb->get_col(
    "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'leanautolinks\_%'"
);

foreach ($options as $option) {
    delete_option($option);
}

// 3. Clear scheduled Action Scheduler actions.
if (function_exists('as_unschedule_all_actions')) {
    $actions = [
        'leanautolinks_process_single',
        'leanautolinks_process_batch',
        'leanautolinks_warm_cache',
        'leanautolinks_recache_post',
    ];

    foreach ($actions as $action) {
        as_unschedule_all_actions($action, [], 'leanautolinks');
    }
}

// 4. Flush object cache keys in the leanautolinks group.
if (function_exists('wp_cache_flush_group')) {
    wp_cache_flush_group('leanautolinks');
} else {
    // Manually delete known cache keys when group flush is unavailable.
    $cache_keys = [
        'lw_rules_active',
        'lw_rules:internal',
        'lw_rules:affiliate',
        'lw_rules:entity',
        'lw_rule_index',
        'lw_version:rules',
        'lw_sentinel',
        'lw_stats:hits',
        'lw_stats:misses',
    ];

    foreach ($cache_keys as $key) {
        wp_cache_delete($key, 'leanautolinks');
    }
}
