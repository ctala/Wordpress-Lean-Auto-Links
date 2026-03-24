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
$leanautolinks_tables = [
    $wpdb->prefix . 'lw_rules',
    $wpdb->prefix . 'lw_applied_links',
    $wpdb->prefix . 'lw_queue',
    $wpdb->prefix . 'lw_exclusions',
    $wpdb->prefix . 'lw_performance_log',
];

foreach ($leanautolinks_tables as $leanautolinks_table) {
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $wpdb->query("DROP TABLE IF EXISTS {$leanautolinks_table}");
}

// 2. Delete all options prefixed with leanautolinks_.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$leanautolinks_options = $wpdb->get_col(
    "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'leanautolinks\_%'"
);

foreach ($leanautolinks_options as $leanautolinks_option) {
    delete_option($leanautolinks_option);
}

// 3. Clear scheduled Action Scheduler actions.
if (function_exists('as_unschedule_all_actions')) {
    $leanautolinks_actions = [
        'leanautolinks_process_single',
        'leanautolinks_process_batch',
        'leanautolinks_warm_cache',
        'leanautolinks_recache_post',
    ];

    foreach ($leanautolinks_actions as $leanautolinks_action) {
        as_unschedule_all_actions($leanautolinks_action, [], 'leanautolinks');
    }
}

// 4. Flush object cache keys in the leanautolinks group.
if (function_exists('wp_cache_flush_group')) {
    wp_cache_flush_group('leanautolinks');
} else {
    // Manually delete known cache keys when group flush is unavailable.
    $leanautolinks_cache_keys = [
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

    foreach ($leanautolinks_cache_keys as $leanautolinks_key) {
        wp_cache_delete($leanautolinks_key, 'leanautolinks');
    }
}
