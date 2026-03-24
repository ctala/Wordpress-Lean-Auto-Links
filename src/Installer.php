<?php
declare(strict_types=1);

namespace LeanAutoLinks;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handles plugin activation and deactivation.
 *
 * Creates custom database tables on activation using dbDelta() and cleans up
 * scheduled actions on deactivation. Tables follow the schema defined in the
 * project specification with the processed_content addition from ADR-002.
 */
final class Installer
{
    /**
     * Run on plugin activation: create all custom tables.
     */
    public function activate(): void
    {
        $this->create_tables();
        $this->set_default_options();

        // Store the DB schema version for future upgrade routines.
        update_option('leanautolinks_db_version', LEANAUTOLINKS_VERSION);
    }

    /**
     * Run on plugin deactivation: unschedule all Action Scheduler actions.
     */
    public function deactivate(): void
    {
        $this->unschedule_actions();
    }

    /**
     * Create all five custom tables using dbDelta().
     */
    private function create_tables(): void
    {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // 1. lw_rules table
        $table_rules = $wpdb->prefix . 'lw_rules';
        $sql_rules = "CREATE TABLE {$table_rules} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            rule_type VARCHAR(20) NOT NULL,
            keyword VARCHAR(255) NOT NULL,
            target_url TEXT NOT NULL,
            entity_type VARCHAR(100) DEFAULT NULL,
            entity_id BIGINT UNSIGNED DEFAULT NULL,
            priority TINYINT NOT NULL DEFAULT 10,
            max_per_post TINYINT NOT NULL DEFAULT 1,
            case_sensitive TINYINT(1) NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            nofollow TINYINT(1) NOT NULL DEFAULT 0,
            sponsored TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY idx_keyword (keyword),
            KEY idx_type (rule_type),
            KEY idx_active (is_active)
        ) {$charset_collate};";

        dbDelta($sql_rules);

        // 2. lw_applied_links table (with processed_content from ADR-002)
        $table_applied = $wpdb->prefix . 'lw_applied_links';
        $sql_applied = "CREATE TABLE {$table_applied} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            post_id BIGINT UNSIGNED NOT NULL,
            rule_id BIGINT UNSIGNED NOT NULL,
            keyword VARCHAR(255) NOT NULL,
            target_url TEXT NOT NULL,
            processed_content LONGTEXT DEFAULT NULL,
            content_hash CHAR(32) DEFAULT NULL,
            processed_at DATETIME DEFAULT NULL,
            applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY idx_post (post_id),
            KEY idx_rule (rule_id)
        ) {$charset_collate};";

        dbDelta($sql_applied);

        // 3. lw_queue table
        $table_queue = $wpdb->prefix . 'lw_queue';
        $sql_queue = "CREATE TABLE {$table_queue} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            post_id BIGINT UNSIGNED NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            triggered_by VARCHAR(50) NOT NULL DEFAULT 'save_post',
            priority TINYINT NOT NULL DEFAULT 10,
            attempts TINYINT NOT NULL DEFAULT 0,
            scheduled_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            processed_at DATETIME DEFAULT NULL,
            error_log TEXT DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY idx_post_unique (post_id),
            KEY idx_status (status),
            KEY idx_priority_status (priority,status)
        ) {$charset_collate};";

        dbDelta($sql_queue);

        // 4. lw_exclusions table
        $table_exclusions = $wpdb->prefix . 'lw_exclusions';
        $sql_exclusions = "CREATE TABLE {$table_exclusions} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            type VARCHAR(20) NOT NULL,
            value TEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id)
        ) {$charset_collate};";

        dbDelta($sql_exclusions);

        // 5. lw_performance_log table
        $table_perf = $wpdb->prefix . 'lw_performance_log';
        $sql_perf = "CREATE TABLE {$table_perf} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_type VARCHAR(100) NOT NULL,
            post_id BIGINT UNSIGNED DEFAULT NULL,
            duration_ms INT UNSIGNED DEFAULT NULL,
            memory_kb INT UNSIGNED DEFAULT NULL,
            rules_checked SMALLINT UNSIGNED DEFAULT NULL,
            links_applied SMALLINT UNSIGNED DEFAULT NULL,
            logged_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY idx_event (event_type),
            KEY idx_date (logged_at)
        ) {$charset_collate};";

        dbDelta($sql_perf);
    }

    /**
     * Set default plugin options on first activation.
     */
    private function set_default_options(): void
    {
        $defaults = [
            'leanautolinks_supported_post_types' => ['post', 'page'],
            'leanautolinks_max_links_per_post'   => 10,
            'leanautolinks_batch_size'           => 25,
            'leanautolinks_max_concurrent_jobs'  => 3,
        ];

        foreach ($defaults as $key => $value) {
            if (get_option($key) === false) {
                add_option($key, $value);
            }
        }
    }

    /**
     * Unschedule all LeanAutoLinks Action Scheduler actions.
     */
    private function unschedule_actions(): void
    {
        if (!function_exists('as_unschedule_all_actions')) {
            return;
        }

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
}
