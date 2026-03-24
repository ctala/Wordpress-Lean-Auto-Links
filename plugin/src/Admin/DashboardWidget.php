<?php
declare(strict_types=1);

namespace LeanAutoLinks\Admin;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * WordPress dashboard widget for LeanAutoLinks.
 *
 * Displays a quick overview of the linking system status including
 * rules summary, applied links, queue status, and performance metrics.
 */
final class DashboardWidget
{
    /**
     * Register the dashboard widget hook.
     */
    public function register(): void
    {
        add_action('wp_dashboard_setup', [$this, 'add_widget']);
    }

    /**
     * Add the dashboard widget for users with manage_options capability.
     */
    public function add_widget(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        wp_add_dashboard_widget(
            'leanautolinks_dashboard_widget',
            esc_html__('LeanAutoLinks Overview', 'leanautolinks'),
            [$this, 'render']
        );
    }

    /**
     * Render the dashboard widget content.
     */
    public function render(): void
    {
        global $wpdb;

        $rules_table    = $wpdb->prefix . 'lw_rules';
        $applied_table  = $wpdb->prefix . 'lw_applied_links';
        $queue_table    = $wpdb->prefix . 'lw_queue';
        $perf_table     = $wpdb->prefix . 'lw_performance_log';

        // --- Rules summary ---
        $rules_data = $wpdb->get_row(
            "SELECT
                COUNT(*) AS total,
                SUM(is_active = 1) AS active,
                SUM(rule_type = 'internal') AS type_internal,
                SUM(rule_type = 'entity') AS type_entity,
                SUM(rule_type = 'affiliate') AS type_affiliate
            FROM {$rules_table}"
        );

        $total_rules     = (int) ($rules_data->total ?? 0);
        $active_rules    = (int) ($rules_data->active ?? 0);
        $internal_rules  = (int) ($rules_data->type_internal ?? 0);
        $entity_rules    = (int) ($rules_data->type_entity ?? 0);
        $affiliate_rules = (int) ($rules_data->type_affiliate ?? 0);

        // --- Applied links ---
        $total_applied = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$applied_table}"
        );

        $today = current_time('Y-m-d');
        $applied_today = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$applied_table} WHERE DATE(applied_at) = %s",
                $today
            )
        );

        // --- Queue status ---
        $queue_data = $wpdb->get_results(
            "SELECT status, COUNT(*) AS cnt FROM {$queue_table} GROUP BY status",
            OBJECT_K
        );

        $pending    = (int) ($queue_data['pending']->cnt ?? 0);
        $processing = (int) ($queue_data['processing']->cnt ?? 0);
        $failed     = (int) ($queue_data['failed']->cnt ?? 0);

        // --- Performance (avg processing time last 24h) ---
        $avg_duration = $wpdb->get_var(
            "SELECT ROUND(AVG(duration_ms))
            FROM {$perf_table}
            WHERE event_type = 'process_single'
                AND logged_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"
        );

        $admin_url = admin_url('tools.php?page=leanautolinks');

        ?>
        <style>
            .lal-dashboard-grid {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 12px;
                margin-bottom: 12px;
            }
            .lal-dashboard-card {
                background: #f9f9f9;
                border: 1px solid #e0e0e0;
                border-radius: 4px;
                padding: 12px;
            }
            .lal-dashboard-card h4 {
                margin: 0 0 8px;
                font-size: 13px;
                color: #1d2327;
            }
            .lal-dashboard-card .lal-stat {
                font-size: 24px;
                font-weight: 600;
                color: #2271b1;
                line-height: 1.2;
            }
            .lal-dashboard-card .lal-detail {
                font-size: 12px;
                color: #646970;
                margin-top: 4px;
            }
            .lal-dashboard-card .lal-detail span {
                display: inline-block;
                margin-right: 8px;
            }
            .lal-dashboard-footer {
                display: flex;
                justify-content: space-between;
                align-items: center;
                border-top: 1px solid #e0e0e0;
                padding-top: 8px;
                font-size: 12px;
                color: #646970;
            }
            .lal-queue-failed {
                color: #d63638;
                font-weight: 600;
            }
        </style>

        <div class="lal-dashboard-grid">
            <div class="lal-dashboard-card">
                <h4><?php echo esc_html__('Rules', 'leanautolinks'); ?></h4>
                <div class="lal-stat"><?php echo esc_html((string) $total_rules); ?></div>
                <div class="lal-detail">
                    <span><?php
                        /* translators: %d: number of active rules */
                        echo esc_html(sprintf(__('%d active', 'leanautolinks'), $active_rules));
                    ?></span>
                </div>
                <div class="lal-detail">
                    <span><?php
                        /* translators: %d: number of internal rules */
                        echo esc_html(sprintf(__('Internal: %d', 'leanautolinks'), $internal_rules));
                    ?></span>
                    <span><?php
                        /* translators: %d: number of entity rules */
                        echo esc_html(sprintf(__('Entity: %d', 'leanautolinks'), $entity_rules));
                    ?></span>
                    <span><?php
                        /* translators: %d: number of affiliate rules */
                        echo esc_html(sprintf(__('Affiliate: %d', 'leanautolinks'), $affiliate_rules));
                    ?></span>
                </div>
            </div>

            <div class="lal-dashboard-card">
                <h4><?php echo esc_html__('Links Applied', 'leanautolinks'); ?></h4>
                <div class="lal-stat"><?php echo esc_html(number_format_i18n($total_applied)); ?></div>
                <div class="lal-detail">
                    <span><?php
                        /* translators: %s: number of links applied today */
                        echo esc_html(sprintf(__('%s today', 'leanautolinks'), number_format_i18n($applied_today)));
                    ?></span>
                </div>
            </div>

            <div class="lal-dashboard-card">
                <h4><?php echo esc_html__('Queue', 'leanautolinks'); ?></h4>
                <div class="lal-stat"><?php echo esc_html((string) $pending); ?></div>
                <div class="lal-detail">
                    <span><?php
                        /* translators: %d: number of posts being processed */
                        echo esc_html(sprintf(__('%d processing', 'leanautolinks'), $processing));
                    ?></span>
                    <?php if ($failed > 0) : ?>
                        <span class="lal-queue-failed"><?php
                            /* translators: %d: number of failed posts */
                            echo esc_html(sprintf(__('%d failed', 'leanautolinks'), $failed));
                        ?></span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="lal-dashboard-card">
                <h4><?php echo esc_html__('Performance', 'leanautolinks'); ?></h4>
                <?php if ($avg_duration !== null) : ?>
                    <div class="lal-stat"><?php echo esc_html($avg_duration . 'ms'); ?></div>
                    <div class="lal-detail">
                        <span><?php echo esc_html__('Avg processing time (24h)', 'leanautolinks'); ?></span>
                    </div>
                <?php else : ?>
                    <div class="lal-stat">&mdash;</div>
                    <div class="lal-detail">
                        <span><?php echo esc_html__('No data in last 24h', 'leanautolinks'); ?></span>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="lal-dashboard-footer">
            <a href="<?php echo esc_url($admin_url); ?>">
                <?php echo esc_html__('Go to LeanAutoLinks', 'leanautolinks'); ?> &rarr;
            </a>
            <span><?php
                /* translators: %s: plugin version */
                echo esc_html(sprintf(__('v%s', 'leanautolinks'), LEANAUTOLINKS_VERSION));
            ?></span>
        </div>
        <?php
    }
}
