<?php
declare(strict_types=1);

namespace LeanAutoLinks\Admin;

if (!defined('ABSPATH')) {
    exit;
}

use LeanAutoLinks\Repositories\AppliedLinksRepository;
use LeanAutoLinks\Repositories\ExclusionsRepository;
use LeanAutoLinks\Repositories\PerformanceRepository;
use LeanAutoLinks\Repositories\QueueRepository;
use LeanAutoLinks\Repositories\RulesRepository;

/**
 * WordPress admin page for LeanAutoLinks.
 *
 * Registered under Tools > LeanAutoLinks. Provides a tabbed interface for
 * non-technical users to manage linking rules, monitor the queue,
 * configure exclusions, and adjust settings.
 */
final class AdminPage
{
    private RulesRepository $rules_repo;
    private QueueRepository $queue_repo;
    private AppliedLinksRepository $applied_repo;
    private ExclusionsRepository $exclusions_repo;
    private PerformanceRepository $performance_repo;

    /** @var string The admin page hook suffix. */
    private string $hook_suffix = '';

    public function __construct(
        RulesRepository $rules_repo,
        QueueRepository $queue_repo,
        AppliedLinksRepository $applied_repo,
        ExclusionsRepository $exclusions_repo,
        PerformanceRepository $performance_repo
    ) {
        $this->rules_repo       = $rules_repo;
        $this->queue_repo       = $queue_repo;
        $this->applied_repo     = $applied_repo;
        $this->exclusions_repo  = $exclusions_repo;
        $this->performance_repo = $performance_repo;
    }

    /**
     * Register the admin menu page and asset hooks.
     */
    public function register(): void
    {
        add_action('admin_menu', [$this, 'add_menu_page']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_ajax_leanautolinks_admin', [$this, 'handle_ajax']);
    }

    /**
     * Add the LeanAutoLinks page under Tools.
     */
    public function add_menu_page(): void
    {
        $this->hook_suffix = add_management_page(
            __('LeanAutoLinks', 'leanautolinks'),
            __('LeanAutoLinks', 'leanautolinks'),
            'manage_options',
            'leanautolinks',
            [$this, 'render_page']
        );
    }

    /**
     * Enqueue CSS and JS only on the LeanAutoLinks admin page.
     */
    public function enqueue_assets(string $hook): void
    {
        if ($hook !== $this->hook_suffix) {
            return;
        }

        wp_enqueue_style(
            'leanautolinks-admin',
            LEANAUTOLINKS_URL . 'src/Admin/assets/admin.css',
            [],
            LEANAUTOLINKS_VERSION
        );

        wp_enqueue_script(
            'leanautolinks-admin',
            LEANAUTOLINKS_URL . 'src/Admin/assets/admin.js',
            ['jquery'],
            LEANAUTOLINKS_VERSION,
            true
        );

        wp_localize_script('leanautolinks-admin', 'leanautolinksAdmin', [
            'ajaxUrl'   => admin_url('admin-ajax.php'),
            'restUrl'   => rest_url('leanautolinks/v1/'),
            'nonce'     => wp_create_nonce('leanautolinks_admin'),
            'restNonce' => wp_create_nonce('wp_rest'),
            'strings'   => [
                'confirmDelete'     => __('Are you sure you want to delete this item?', 'leanautolinks'),
                'confirmBulk'       => __('Are you sure you want to reprocess all posts? This may take a while.', 'leanautolinks'),
                'confirmClearDone'  => __('Clear all completed queue items?', 'leanautolinks'),
                'processing'        => __('Processing...', 'leanautolinks'),
                'done'              => __('Done!', 'leanautolinks'),
                'error'             => __('An error occurred. Please try again.', 'leanautolinks'),
                'saved'             => __('Saved successfully.', 'leanautolinks'),
                'imported'          => __('Rules imported successfully.', 'leanautolinks'),
            ],
        ]);
    }

    /**
     * Handle AJAX requests for admin actions.
     */
    public function handle_ajax(): void
    {
        check_ajax_referer('leanautolinks_admin', '_nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied.', 'leanautolinks'), 403);
        }

        $action_type = isset($_POST['action_type']) ? sanitize_text_field(wp_unslash($_POST['action_type'])) : '';

        switch ($action_type) {
            case 'save_settings':
                $this->ajax_save_settings();
                break;
            case 'create_rule':
                $this->ajax_create_rule();
                break;
            case 'update_rule':
                $this->ajax_update_rule();
                break;
            case 'delete_rule':
                $this->ajax_delete_rule();
                break;
            case 'toggle_rule':
                $this->ajax_toggle_rule();
                break;
            case 'create_exclusion':
                $this->ajax_create_exclusion();
                break;
            case 'delete_exclusion':
                $this->ajax_delete_exclusion();
                break;
            case 'bulk_action':
                $this->ajax_bulk_action();
                break;
            default:
                wp_send_json_error(__('Unknown action.', 'leanautolinks'));
        }
    }

    /**
     * Render the main admin page with tabs.
     */
    public function render_page(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'leanautolinks'));
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Query parameter for tab navigation, no state change.
        $current_tab = isset($_GET['tab']) ? sanitize_text_field(wp_unslash($_GET['tab'])) : 'dashboard';
        $tabs = [
            'dashboard'  => __('Dashboard', 'leanautolinks'),
            'rules'      => __('Rules', 'leanautolinks'),
            'queue'      => __('Queue', 'leanautolinks'),
            'exclusions' => __('Exclusions', 'leanautolinks'),
            'settings'   => __('Settings', 'leanautolinks'),
        ];

        ?>
        <div class="wrap leanautolinks-admin">
            <h1><?php echo esc_html__('LeanAutoLinks - Internal Linking', 'leanautolinks'); ?></h1>

            <nav class="nav-tab-wrapper">
                <?php foreach ($tabs as $slug => $label) : ?>
                    <a href="<?php echo esc_url(add_query_arg(['page' => 'leanautolinks', 'tab' => $slug], admin_url('tools.php'))); ?>"
                       class="nav-tab <?php echo $slug === $current_tab ? 'nav-tab-active' : ''; ?>">
                        <?php echo esc_html($label); ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <div class="leanautolinks-tab-content" style="margin-top: 20px;">
                <?php
                switch ($current_tab) {
                    case 'rules':
                        $this->render_rules_tab();
                        break;
                    case 'queue':
                        $this->render_queue_tab();
                        break;
                    case 'exclusions':
                        $this->render_exclusions_tab();
                        break;
                    case 'settings':
                        $this->render_settings_tab();
                        break;
                    default:
                        $this->render_dashboard_tab();
                        break;
                }
                ?>
            </div>

            <!-- Footer -->
            <div style="margin-top: 32px; padding: 16px 0; border-top: 1px solid #c3c4c7; text-align: center; color: #646970; font-size: 13px;">
                <?php
                echo wp_kses(
                    sprintf(
                        'LeanAutoLinks v%1$s — Made with %2$s from Chile by <a href="%3$s" target="_blank" rel="noopener">cristiantala.com</a>',
                        LEANAUTOLINKS_VERSION,
                        '<span style="color: #d63638;">&#10084;</span>',
                        'https://cristiantala.com/'
                    ),
                    ['a' => ['href' => [], 'target' => [], 'rel' => []], 'span' => ['style' => []]]
                );
                ?>
            </div>
        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // Tab: Dashboard
    // -------------------------------------------------------------------------

    private function render_dashboard_tab(): void
    {
        global $wpdb;

        $queue_stats  = $this->queue_repo->get_stats();
        $applied_stats = $this->applied_repo->get_stats();
        $perf_summary = $this->performance_repo->get_summary('24h');

        $rules_table  = $wpdb->prefix . 'lw_rules';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $total_rules  = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$rules_table}");
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $active_rules = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$rules_table} WHERE is_active = 1");

        $has_object_cache = wp_using_ext_object_cache();

        // Health status.
        $health = 'healthy';
        if ($queue_stats['failed'] > 0 || $perf_summary['avg_duration_ms'] > 5000) {
            $health = 'warning';
        }

        // Links applied today.
        $applied_table = $wpdb->prefix . 'lw_applied_links';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $links_today   = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$applied_table} WHERE rule_id != 0 AND DATE(applied_at) = %s",
                current_time('Y-m-d')
            )
        );

        // Recent log entries - try performance log first, fall back to queue.
        $recent_log = $this->performance_repo->get_log(['limit' => 20]);

        // If no performance log entries, show recent queue activity instead.
        $recent_queue = [];
        if (empty($recent_log)) {
            $queue_table = $wpdb->prefix . 'lw_queue';
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $recent_queue = $wpdb->get_results(
                "SELECT q.*, p.post_title
                 FROM {$queue_table} q
                 LEFT JOIN {$wpdb->posts} p ON q.post_id = p.ID
                 ORDER BY q.scheduled_at DESC
                 LIMIT 20"
            );
        }

        ?>
        <div class="lw-dashboard-grid">
            <!-- Health Status -->
            <div class="lw-card lw-card-health lw-health-<?php echo esc_attr($health); ?>">
                <h3><?php echo esc_html__('System Health', 'leanautolinks'); ?></h3>
                <div class="lw-health-indicator">
                    <span class="lw-health-dot"></span>
                    <strong><?php echo esc_html(ucfirst($health)); ?></strong>
                </div>
                <?php if (!$has_object_cache) : ?>
                    <p class="lw-notice-text">
                        <?php echo esc_html__('Tip: Install Redis or Memcached for better performance.', 'leanautolinks'); ?>
                    </p>
                <?php endif; ?>
                <?php if (!(defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) && $pending > 0) : ?>
                    <p class="lw-notice-text">
                        <?php echo esc_html__('Tip: For reliable queue processing, add a system cron and set DISABLE_WP_CRON to true in wp-config.php.', 'leanautolinks'); ?>
                    </p>
                <?php endif; ?>
            </div>

            <!-- Rules -->
            <div class="lw-card">
                <h3><?php echo esc_html__('Rules', 'leanautolinks'); ?></h3>
                <div class="lw-stat-number"><?php echo esc_html((string) $total_rules); ?></div>
                <p>
                    <span class="lw-badge lw-badge-green"><?php echo esc_html((string) $active_rules); ?> <?php echo esc_html__('active', 'leanautolinks'); ?></span>
                    <span class="lw-badge lw-badge-gray"><?php echo esc_html((string) ($total_rules - $active_rules)); ?> <?php echo esc_html__('inactive', 'leanautolinks'); ?></span>
                </p>
            </div>

            <!-- Queue -->
            <div class="lw-card">
                <h3><?php echo esc_html__('Queue', 'leanautolinks'); ?></h3>
                <div class="lw-stat-number"><?php echo esc_html((string) $queue_stats['total']); ?></div>
                <p>
                    <span class="lw-badge lw-badge-blue"><?php echo esc_html((string) $queue_stats['pending']); ?> <?php echo esc_html__('pending', 'leanautolinks'); ?></span>
                    <span class="lw-badge lw-badge-yellow"><?php echo esc_html((string) $queue_stats['processing']); ?> <?php echo esc_html__('processing', 'leanautolinks'); ?></span>
                    <span class="lw-badge lw-badge-green"><?php echo esc_html((string) $queue_stats['done']); ?> <?php echo esc_html__('done', 'leanautolinks'); ?></span>
                    <?php if ($queue_stats['failed'] > 0) : ?>
                        <span class="lw-badge lw-badge-red"><?php echo esc_html((string) $queue_stats['failed']); ?> <?php echo esc_html__('failed', 'leanautolinks'); ?></span>
                    <?php endif; ?>
                </p>
            </div>

            <!-- Applied Links -->
            <div class="lw-card">
                <h3><?php echo esc_html__('Links Applied', 'leanautolinks'); ?></h3>
                <div class="lw-stat-number"><?php echo esc_html((string) $applied_stats['total_links']); ?></div>
                <p>
                    <span class="lw-badge lw-badge-blue"><?php echo esc_html((string) $links_today); ?> <?php echo esc_html__('today', 'leanautolinks'); ?></span>
                    <?php echo esc_html(sprintf(
                        /* translators: %s: average links per post */
                        __('Avg %s per post', 'leanautolinks'),
                        (string) $applied_stats['avg_links_per_post']
                    )); ?>
                </p>
            </div>

            <!-- Performance -->
            <div class="lw-card">
                <h3><?php echo esc_html__('Performance (24h)', 'leanautolinks'); ?></h3>
                <p><?php echo esc_html(sprintf(
                    /* translators: %s: average processing time in milliseconds */
                    __('Avg processing: %sms', 'leanautolinks'),
                    (string) $perf_summary['avg_duration_ms']
                )); ?></p>
                <p><?php echo esc_html(sprintf(
                    /* translators: %d: total events processed */
                    __('Events processed: %d', 'leanautolinks'),
                    $perf_summary['total_events']
                )); ?></p>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="lw-section">
            <h2><?php echo esc_html__('Quick Actions', 'leanautolinks'); ?></h2>
            <p>
                <button type="button" class="button button-primary lw-ajax-action" data-action="bulk_reprocess" data-scope="all">
                    <?php echo esc_html__('Process All Posts', 'leanautolinks'); ?>
                </button>
                <button type="button" class="button lw-ajax-action" data-action="retry_failed">
                    <?php echo esc_html__('Retry Failed', 'leanautolinks'); ?>
                </button>
                <button type="button" class="button lw-ajax-action" data-action="clear_done">
                    <?php echo esc_html__('Clear Completed', 'leanautolinks'); ?>
                </button>
            </p>
            <div id="lw-action-status" class="lw-status-message" style="display:none;"></div>
        </div>

        <!-- Recent Applied Links -->
        <div class="lw-section">
            <h2><?php echo esc_html__('Recent Applied Links', 'leanautolinks'); ?></h2>
            <?php
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $recent_applied = $wpdb->get_results(
                "SELECT al.keyword, al.target_url, al.post_id, al.applied_at, r.rule_type,
                        p.post_title
                 FROM {$applied_table} al
                 INNER JOIN {$rules_table} r ON al.rule_id = r.id
                 LEFT JOIN {$wpdb->posts} p ON al.post_id = p.ID
                 WHERE al.rule_id != 0
                 ORDER BY al.applied_at DESC
                 LIMIT 25"
            );
            ?>
            <?php if (!empty($recent_applied)) : ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php echo esc_html__('Keyword', 'leanautolinks'); ?></th>
                            <th><?php echo esc_html__('Target URL', 'leanautolinks'); ?></th>
                            <th><?php echo esc_html__('Type', 'leanautolinks'); ?></th>
                            <th><?php echo esc_html__('Applied In', 'leanautolinks'); ?></th>
                            <th><?php echo esc_html__('Date', 'leanautolinks'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_applied as $al) : ?>
                            <tr>
                                <td><strong><?php echo esc_html($al->keyword); ?></strong></td>
                                <td>
                                    <a href="<?php echo esc_url($al->target_url); ?>" target="_blank" rel="noopener" title="<?php echo esc_attr($al->target_url); ?>">
                                        <?php echo esc_html(mb_strimwidth($al->target_url, 0, 50, '...')); ?>
                                    </a>
                                </td>
                                <td>
                                    <?php
                                    $type_class = match ($al->rule_type) {
                                        'affiliate' => 'lw-badge-orange',
                                        'entity'    => 'lw-badge-blue',
                                        default     => 'lw-badge-green',
                                    };
                                    ?>
                                    <span class="lw-badge <?php echo esc_attr($type_class); ?>">
                                        <?php echo esc_html(ucfirst($al->rule_type)); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if (!empty($al->post_title)) : ?>
                                        <a href="<?php echo esc_url(get_permalink((int) $al->post_id) ?: '#'); ?>" target="_blank" rel="noopener">
                                            <?php echo esc_html(mb_strimwidth($al->post_title, 0, 40, '...')); ?>
                                        </a>
                                    <?php else : ?>
                                        #<?php echo esc_html((string) $al->post_id); ?>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html($al->applied_at ? wp_date(get_option('date_format') . ' ' . get_option('time_format'), strtotime($al->applied_at)) : '—'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else : ?>
                <p class="description"><?php echo esc_html__('No links applied yet. Process some posts to see applied links here.', 'leanautolinks'); ?></p>
            <?php endif; ?>
        </div>

        <!-- Recent Activity -->
        <div class="lw-section">
            <h2><?php echo esc_html__('Recent Activity', 'leanautolinks'); ?></h2>
            <?php if (!empty($recent_log)) : ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php echo esc_html__('Event', 'leanautolinks'); ?></th>
                            <th><?php echo esc_html__('Post ID', 'leanautolinks'); ?></th>
                            <th><?php echo esc_html__('Duration', 'leanautolinks'); ?></th>
                            <th><?php echo esc_html__('Links', 'leanautolinks'); ?></th>
                            <th><?php echo esc_html__('Time', 'leanautolinks'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_log as $entry) : ?>
                            <tr>
                                <td><span class="lw-badge"><?php echo esc_html($entry->event_type); ?></span></td>
                                <td>
                                    <?php if (!empty($entry->post_id)) : ?>
                                        <a href="<?php echo esc_url(get_permalink((int) $entry->post_id) ?: '#'); ?>" target="_blank" rel="noopener">
                                            #<?php echo esc_html((string) $entry->post_id); ?>
                                        </a>
                                    <?php else : ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html((string) $entry->duration_ms); ?>ms</td>
                                <td><?php echo esc_html((string) $entry->links_applied); ?></td>
                                <td><?php echo esc_html($entry->logged_at); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php elseif (!empty($recent_queue)) : ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php echo esc_html__('Post', 'leanautolinks'); ?></th>
                            <th><?php echo esc_html__('Status', 'leanautolinks'); ?></th>
                            <th><?php echo esc_html__('Triggered By', 'leanautolinks'); ?></th>
                            <th><?php echo esc_html__('Scheduled', 'leanautolinks'); ?></th>
                            <th><?php echo esc_html__('Processed', 'leanautolinks'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_queue as $q) : ?>
                            <tr>
                                <td>
                                    <?php if (!empty($q->post_title)) : ?>
                                        <a href="<?php echo esc_url(get_permalink((int) $q->post_id) ?: '#'); ?>" target="_blank" rel="noopener">
                                            <?php echo esc_html(mb_strimwidth($q->post_title, 0, 50, '...')); ?>
                                        </a>
                                    <?php else : ?>
                                        <a href="<?php echo esc_url(get_permalink((int) $q->post_id) ?: '#'); ?>" target="_blank" rel="noopener">
                                            #<?php echo esc_html((string) $q->post_id); ?>
                                        </a>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php
                                    $sc = match ($q->status) {
                                        'pending'    => 'lw-badge-blue',
                                        'processing' => 'lw-badge-yellow',
                                        'done'       => 'lw-badge-green',
                                        'failed'     => 'lw-badge-red',
                                        default      => '',
                                    };
                                    ?>
                                    <span class="lw-badge <?php echo esc_attr($sc); ?>"><?php echo esc_html(ucfirst($q->status)); ?></span>
                                </td>
                                <td><?php echo esc_html($q->triggered_by); ?></td>
                                <td><?php echo esc_html($q->scheduled_at); ?></td>
                                <td><?php echo esc_html($q->processed_at ?: '-'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else : ?>
                <p class="description"><?php echo esc_html__('No activity logged yet. Process some posts to see activity here.', 'leanautolinks'); ?></p>
            <?php endif; ?>
        </div>
        <?php
    }

    // -------------------------------------------------------------------------
    // Tab: Rules
    // -------------------------------------------------------------------------

    private function render_rules_tab(): void
    {
        global $wpdb;

        // Detail view: show posts linked by a specific rule.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Query parameter for rule detail view, no state change.
        $view_rule_id = isset($_GET['view_rule']) ? (int) $_GET['view_rule'] : 0;
        if ($view_rule_id > 0) {
            $this->render_rule_linked_posts($view_rule_id);
            return;
        }

        $table = $wpdb->prefix . 'lw_rules';

        // Filters.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Query parameters for filtering rules list, no state change.
        $filter_type   = isset($_GET['rule_type']) ? sanitize_text_field(wp_unslash($_GET['rule_type'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Query parameter for filtering rules list, no state change.
        $filter_active = isset($_GET['is_active']) ? sanitize_text_field(wp_unslash($_GET['is_active'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Query parameter for search, no state change.
        $search        = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';

        $where  = '1=1';
        $params = [];

        if (!empty($filter_type)) {
            $where   .= ' AND rule_type = %s';
            $params[] = $filter_type;
        }
        if ($filter_active !== '') {
            $where   .= ' AND is_active = %d';
            $params[] = (int) $filter_active;
        }
        if (!empty($search)) {
            $where   .= ' AND (keyword LIKE %s OR target_url LIKE %s)';
            $like     = '%' . $wpdb->esc_like($search) . '%';
            $params[] = $like;
            $params[] = $like;
        }

        // Count total for pagination.
        $count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where}";
        if (empty($params)) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Safe: table name from $wpdb->prefix.
            $total_rules_count = (int) $wpdb->get_var($count_sql);
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Safe: table name from $wpdb->prefix, values via prepare().
            $total_rules_count = (int) $wpdb->get_var($wpdb->prepare($count_sql, ...$params));
        }

        $per_page    = 50;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Query parameter for pagination, no state change.
        $current_page = isset($_GET['paged']) ? max(1, (int) $_GET['paged']) : 1;
        $total_pages = max(1, (int) ceil($total_rules_count / $per_page));
        $offset      = ($current_page - 1) * $per_page;

        $sql = "SELECT * FROM {$table} WHERE {$where} ORDER BY priority ASC, id ASC LIMIT %d OFFSET %d";
        $query_params = array_merge($params, [$per_page, $offset]);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $rules = $wpdb->get_results($wpdb->prepare($sql, ...$query_params));

        $page_url = admin_url('tools.php?page=leanautolinks&tab=rules');

        // Pre-fetch applied link counts for current page rules.
        $rule_link_counts = [];
        if (!empty($rules)) {
            $rule_ids = array_map(fn($r) => (int) $r->id, $rules);
            $applied_table = $wpdb->prefix . 'lw_applied_links';
            $placeholders = implode(',', array_fill(0, count($rule_ids), '%d'));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
            $counts = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT rule_id, COUNT(DISTINCT post_id) as post_count
                     FROM {$applied_table}
                     WHERE rule_id IN ({$placeholders})
                     GROUP BY rule_id",
                    ...$rule_ids
                )
            );
            foreach ($counts as $c) {
                $rule_link_counts[(int) $c->rule_id] = (int) $c->post_count;
            }
        }

        ?>
        <!-- Add New Rule Form -->
        <div class="lw-section">
            <h2><?php echo esc_html__('Add New Rule', 'leanautolinks'); ?></h2>
            <form id="lw-add-rule-form" class="lw-inline-form">
                <?php wp_nonce_field('leanautolinks_admin', '_lw_nonce'); ?>
                <input type="hidden" name="action_type" value="create_rule" />
                <table class="form-table">
                    <tr>
                        <td>
                            <label for="lw-keyword"><?php echo esc_html__('Keyword', 'leanautolinks'); ?> <span class="required">*</span></label>
                            <input type="text" id="lw-keyword" name="keyword" class="regular-text" required />
                        </td>
                        <td>
                            <label for="lw-target-url"><?php echo esc_html__('Target URL', 'leanautolinks'); ?> <span class="required">*</span></label>
                            <input type="url" id="lw-target-url" name="target_url" class="regular-text" required />
                        </td>
                        <td>
                            <label for="lw-rule-type"><?php echo esc_html__('Type', 'leanautolinks'); ?></label>
                            <select id="lw-rule-type" name="rule_type">
                                <option value="internal"><?php echo esc_html__('Internal', 'leanautolinks'); ?></option>
                                <option value="entity"><?php echo esc_html__('Entity', 'leanautolinks'); ?></option>
                                <option value="affiliate"><?php echo esc_html__('Affiliate', 'leanautolinks'); ?></option>
                            </select>
                        </td>
                        <td id="lw-entity-type-wrapper" style="display:none;">
                            <label for="lw-entity-type"><?php echo esc_html__('Entity Type', 'leanautolinks'); ?></label>
                            <select id="lw-entity-type" name="entity_type">
                                <option value=""><?php echo esc_html__('-- Select --', 'leanautolinks'); ?></option>
                                <option value="glossary"><?php echo esc_html__('Glossary', 'leanautolinks'); ?></option>
                                <option value="company"><?php echo esc_html__('Company', 'leanautolinks'); ?></option>
                                <option value="vc"><?php echo esc_html__('VC', 'leanautolinks'); ?></option>
                                <option value="person"><?php echo esc_html__('Person', 'leanautolinks'); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <td>
                            <label for="lw-priority"><?php echo esc_html__('Priority', 'leanautolinks'); ?></label>
                            <input type="number" id="lw-priority" name="priority" value="10" min="1" max="100" class="small-text" />
                        </td>
                        <td>
                            <label for="lw-max-per-post"><?php echo esc_html__('Max per post', 'leanautolinks'); ?></label>
                            <input type="number" id="lw-max-per-post" name="max_per_post" value="1" min="1" max="10" class="small-text" />
                        </td>
                        <td>
                            <label>
                                <input type="checkbox" name="case_sensitive" value="1" />
                                <?php echo esc_html__('Case sensitive', 'leanautolinks'); ?>
                            </label>
                        </td>
                        <td>
                            <label>
                                <input type="checkbox" name="nofollow" value="1" />
                                <?php echo esc_html__('Nofollow', 'leanautolinks'); ?>
                            </label>
                            <label>
                                <input type="checkbox" id="lw-sponsored" name="sponsored" value="1" />
                                <?php echo esc_html__('Sponsored', 'leanautolinks'); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <td colspan="4">
                            <button type="submit" class="button button-primary">
                                <?php echo esc_html__('Add Rule', 'leanautolinks'); ?>
                            </button>
                        </td>
                    </tr>
                </table>
            </form>
            <div id="lw-rule-status" class="lw-status-message" style="display:none;"></div>
        </div>

        <!-- Filters & Bulk Actions -->
        <div class="lw-section">
            <div class="tablenav top">
                <div class="alignleft actions">
                    <form method="get" action="<?php echo esc_url($page_url); ?>" style="display:inline;">
                        <input type="hidden" name="page" value="leanautolinks" />
                        <input type="hidden" name="tab" value="rules" />
                        <select name="rule_type">
                            <option value=""><?php echo esc_html__('All types', 'leanautolinks'); ?></option>
                            <option value="internal" <?php selected($filter_type, 'internal'); ?>><?php echo esc_html__('Internal', 'leanautolinks'); ?></option>
                            <option value="entity" <?php selected($filter_type, 'entity'); ?>><?php echo esc_html__('Entity', 'leanautolinks'); ?></option>
                            <option value="affiliate" <?php selected($filter_type, 'affiliate'); ?>><?php echo esc_html__('Affiliate', 'leanautolinks'); ?></option>
                        </select>
                        <select name="is_active">
                            <option value=""><?php echo esc_html__('All statuses', 'leanautolinks'); ?></option>
                            <option value="1" <?php selected($filter_active, '1'); ?>><?php echo esc_html__('Active', 'leanautolinks'); ?></option>
                            <option value="0" <?php selected($filter_active, '0'); ?>><?php echo esc_html__('Inactive', 'leanautolinks'); ?></option>
                        </select>
                        <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="<?php echo esc_attr__('Search keyword...', 'leanautolinks'); ?>" />
                        <button type="submit" class="button"><?php echo esc_html__('Filter', 'leanautolinks'); ?></button>
                    </form>
                </div>
                <div class="alignright">
                    <button type="button" class="button" id="lw-import-rules-btn">
                        <?php echo esc_html__('Import JSON', 'leanautolinks'); ?>
                    </button>
                    <button type="button" class="button" id="lw-export-rules-btn">
                        <?php echo esc_html__('Export JSON', 'leanautolinks'); ?>
                    </button>
                </div>
            </div>
        </div>

        <!-- Import Modal -->
        <div id="lw-import-modal" class="lw-modal" style="display:none;">
            <div class="lw-modal-content">
                <h3><?php echo esc_html__('Import Rules', 'leanautolinks'); ?></h3>
                <p class="description"><?php echo esc_html__('Paste a JSON array of rules or upload a JSON file.', 'leanautolinks'); ?></p>
                <textarea id="lw-import-json" rows="10" class="large-text" placeholder='[{"keyword":"example","target_url":"https://...","rule_type":"internal"}]'></textarea>
                <p>
                    <input type="file" id="lw-import-file" accept=".json" />
                </p>
                <p>
                    <button type="button" class="button button-primary" id="lw-import-submit">
                        <?php echo esc_html__('Import', 'leanautolinks'); ?>
                    </button>
                    <button type="button" class="button lw-modal-close">
                        <?php echo esc_html__('Cancel', 'leanautolinks'); ?>
                    </button>
                </p>
                <div id="lw-import-status" class="lw-status-message" style="display:none;"></div>
            </div>
        </div>

        <!-- Edit Rule Modal -->
        <div id="lw-edit-modal" class="lw-modal" style="display:none;">
            <div class="lw-modal-content">
                <h3><?php echo esc_html__('Edit Rule', 'leanautolinks'); ?></h3>
                <input type="hidden" id="lw-edit-rule-id" />
                <table class="form-table lw-inline-form">
                    <tr>
                        <td>
                            <label for="lw-edit-keyword"><?php echo esc_html__('Keyword', 'leanautolinks'); ?> <span class="required">*</span></label>
                            <input type="text" id="lw-edit-keyword" class="regular-text" required />
                        </td>
                        <td>
                            <label for="lw-edit-target-url"><?php echo esc_html__('Target URL', 'leanautolinks'); ?> <span class="required">*</span></label>
                            <input type="url" id="lw-edit-target-url" class="regular-text" required />
                        </td>
                    </tr>
                    <tr>
                        <td>
                            <label for="lw-edit-rule-type"><?php echo esc_html__('Type', 'leanautolinks'); ?></label>
                            <select id="lw-edit-rule-type">
                                <option value="internal"><?php echo esc_html__('Internal', 'leanautolinks'); ?></option>
                                <option value="entity"><?php echo esc_html__('Entity', 'leanautolinks'); ?></option>
                                <option value="affiliate"><?php echo esc_html__('Affiliate', 'leanautolinks'); ?></option>
                            </select>
                        </td>
                        <td id="lw-edit-entity-type-wrapper" style="display:none;">
                            <label for="lw-edit-entity-type"><?php echo esc_html__('Entity Type', 'leanautolinks'); ?></label>
                            <select id="lw-edit-entity-type">
                                <option value=""><?php echo esc_html__('-- Select --', 'leanautolinks'); ?></option>
                                <option value="glossary"><?php echo esc_html__('Glossary', 'leanautolinks'); ?></option>
                                <option value="company"><?php echo esc_html__('Company', 'leanautolinks'); ?></option>
                                <option value="vc"><?php echo esc_html__('VC', 'leanautolinks'); ?></option>
                                <option value="person"><?php echo esc_html__('Person', 'leanautolinks'); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <td>
                            <label for="lw-edit-priority"><?php echo esc_html__('Priority', 'leanautolinks'); ?></label>
                            <input type="number" id="lw-edit-priority" value="10" min="1" max="100" class="small-text" />
                        </td>
                        <td>
                            <label for="lw-edit-max-per-post"><?php echo esc_html__('Max per post', 'leanautolinks'); ?></label>
                            <input type="number" id="lw-edit-max-per-post" value="1" min="1" max="10" class="small-text" />
                        </td>
                    </tr>
                    <tr>
                        <td colspan="2">
                            <label><input type="checkbox" id="lw-edit-case-sensitive" /> <?php echo esc_html__('Case sensitive', 'leanautolinks'); ?></label>
                            <label><input type="checkbox" id="lw-edit-nofollow" /> <?php echo esc_html__('Nofollow', 'leanautolinks'); ?></label>
                            <label><input type="checkbox" id="lw-edit-sponsored" /> <?php echo esc_html__('Sponsored', 'leanautolinks'); ?></label>
                        </td>
                    </tr>
                </table>
                <p>
                    <button type="button" class="button button-primary" id="lw-edit-submit">
                        <?php echo esc_html__('Save Changes', 'leanautolinks'); ?>
                    </button>
                    <button type="button" class="button lw-modal-close">
                        <?php echo esc_html__('Cancel', 'leanautolinks'); ?>
                    </button>
                </p>
                <div id="lw-edit-status" class="lw-status-message" style="display:none;"></div>
            </div>
        </div>

        <!-- Rules Table -->
        <form id="lw-rules-bulk-form">
            <table class="widefat striped lw-rules-table">
                <thead>
                    <tr>
                        <th class="check-column"><input type="checkbox" id="lw-select-all" /></th>
                        <th><?php echo esc_html__('Keyword', 'leanautolinks'); ?></th>
                        <th><?php echo esc_html__('Target URL', 'leanautolinks'); ?></th>
                        <th><?php echo esc_html__('Type', 'leanautolinks'); ?></th>
                        <th><?php echo esc_html__('Priority', 'leanautolinks'); ?></th>
                        <th><?php echo esc_html__('Max/Post', 'leanautolinks'); ?></th>
                        <th><?php echo esc_html__('Linked In', 'leanautolinks'); ?></th>
                        <th><?php echo esc_html__('Active', 'leanautolinks'); ?></th>
                        <th><?php echo esc_html__('Actions', 'leanautolinks'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($rules)) : ?>
                        <tr>
                            <td colspan="8"><?php echo esc_html__('No rules found.', 'leanautolinks'); ?></td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ($rules as $rule) : ?>
                            <tr data-rule-id="<?php echo esc_attr((string) $rule->id); ?>">
                                <th class="check-column">
                                    <input type="checkbox" name="rule_ids[]" value="<?php echo esc_attr((string) $rule->id); ?>" />
                                </th>
                                <td><strong><?php echo esc_html($rule->keyword); ?></strong></td>
                                <td>
                                    <a href="<?php echo esc_url($rule->target_url); ?>" target="_blank" rel="noopener">
                                        <?php echo esc_html(mb_strimwidth($rule->target_url, 0, 60, '...')); ?>
                                    </a>
                                </td>
                                <td>
                                    <?php
                                    $type_class = match ($rule->rule_type) {
                                        'affiliate' => 'lw-badge-yellow',
                                        'entity'    => 'lw-badge-blue',
                                        default     => 'lw-badge-green',
                                    };
                                    ?>
                                    <span class="lw-badge <?php echo esc_attr($type_class); ?>">
                                        <?php echo esc_html(ucfirst($rule->rule_type)); ?>
                                    </span>
                                    <?php if ($rule->entity_type) : ?>
                                        <small>(<?php echo esc_html($rule->entity_type); ?>)</small>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html((string) $rule->priority); ?></td>
                                <td><?php echo esc_html((string) $rule->max_per_post); ?></td>
                                <td>
                                    <?php
                                    $link_count = $rule_link_counts[(int) $rule->id] ?? 0;
                                    if ($link_count > 0) :
                                    ?>
                                        <a href="<?php echo esc_url(admin_url('tools.php?page=leanautolinks&tab=rules&view_rule=' . $rule->id)); ?>">
                                            <?php
                                            /* translators: %d: number of posts */
                                            echo esc_html(sprintf(_n('%d post', '%d posts', $link_count, 'leanautolinks'), $link_count));
                                            ?>
                                        </a>
                                    <?php else : ?>
                                        <span class="description">&mdash;</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <button type="button"
                                            class="lw-toggle-switch <?php echo $rule->is_active ? 'lw-active' : ''; ?>"
                                            data-rule-id="<?php echo esc_attr((string) $rule->id); ?>"
                                            title="<?php echo esc_attr($rule->is_active ? __('Active - click to deactivate', 'leanautolinks') : __('Inactive - click to activate', 'leanautolinks')); ?>">
                                        <span class="lw-toggle-track"></span>
                                        <span class="lw-toggle-thumb"></span>
                                    </button>
                                    <span class="lw-toggle-label"><?php echo esc_html($rule->is_active ? __('On', 'leanautolinks') : __('Off', 'leanautolinks')); ?></span>
                                </td>
                                <td>
                                    <button type="button" class="button button-small lw-edit-rule"
                                            data-rule-id="<?php echo esc_attr((string) $rule->id); ?>"
                                            data-keyword="<?php echo esc_attr($rule->keyword); ?>"
                                            data-target-url="<?php echo esc_attr($rule->target_url); ?>"
                                            data-rule-type="<?php echo esc_attr($rule->rule_type); ?>"
                                            data-entity-type="<?php echo esc_attr($rule->entity_type ?? ''); ?>"
                                            data-priority="<?php echo esc_attr((string) $rule->priority); ?>"
                                            data-max-per-post="<?php echo esc_attr((string) $rule->max_per_post); ?>"
                                            data-case-sensitive="<?php echo esc_attr((string) $rule->case_sensitive); ?>"
                                            data-nofollow="<?php echo esc_attr((string) $rule->nofollow); ?>"
                                            data-sponsored="<?php echo esc_attr((string) $rule->sponsored); ?>">
                                        <?php echo esc_html__('Edit', 'leanautolinks'); ?>
                                    </button>
                                    <button type="button" class="button button-small lw-delete-rule" data-rule-id="<?php echo esc_attr((string) $rule->id); ?>">
                                        <?php echo esc_html__('Delete', 'leanautolinks'); ?>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <?php if (!empty($rules)) : ?>
                <div class="tablenav bottom">
                    <div class="alignleft actions">
                        <select id="lw-bulk-action">
                            <option value=""><?php echo esc_html__('Bulk Actions', 'leanautolinks'); ?></option>
                            <option value="delete"><?php echo esc_html__('Delete Selected', 'leanautolinks'); ?></option>
                            <option value="activate"><?php echo esc_html__('Activate Selected', 'leanautolinks'); ?></option>
                            <option value="deactivate"><?php echo esc_html__('Deactivate Selected', 'leanautolinks'); ?></option>
                        </select>
                        <button type="button" class="button" id="lw-apply-bulk">
                            <?php echo esc_html__('Apply', 'leanautolinks'); ?>
                        </button>
                    </div>

                    <?php if ($total_pages > 1) : ?>
                        <div class="tablenav-pages">
                            <span class="displaying-num">
                                <?php echo esc_html(sprintf(
                                    /* translators: %d: total number of rules */
                                    _n('%d rule', '%d rules', $total_rules_count, 'leanautolinks'),
                                    $total_rules_count
                                )); ?>
                            </span>
                            <span class="pagination-links">
                                <?php if ($current_page > 1) : ?>
                                    <a class="first-page button" href="<?php echo esc_url(add_query_arg(['paged' => 1, 'rule_type' => $filter_type, 'is_active' => $filter_active, 's' => $search], $page_url)); ?>">&laquo;</a>
                                    <a class="prev-page button" href="<?php echo esc_url(add_query_arg(['paged' => $current_page - 1, 'rule_type' => $filter_type, 'is_active' => $filter_active, 's' => $search], $page_url)); ?>">&lsaquo;</a>
                                <?php else : ?>
                                    <span class="tablenav-pages-navspan button disabled">&laquo;</span>
                                    <span class="tablenav-pages-navspan button disabled">&lsaquo;</span>
                                <?php endif; ?>

                                <span class="paging-input">
                                    <span class="tablenav-paging-text">
                                        <?php echo esc_html((string) $current_page); ?>
                                        <?php echo esc_html__('of', 'leanautolinks'); ?>
                                        <span class="total-pages"><?php echo esc_html((string) $total_pages); ?></span>
                                    </span>
                                </span>

                                <?php if ($current_page < $total_pages) : ?>
                                    <a class="next-page button" href="<?php echo esc_url(add_query_arg(['paged' => $current_page + 1, 'rule_type' => $filter_type, 'is_active' => $filter_active, 's' => $search], $page_url)); ?>">&rsaquo;</a>
                                    <a class="last-page button" href="<?php echo esc_url(add_query_arg(['paged' => $total_pages, 'rule_type' => $filter_type, 'is_active' => $filter_active, 's' => $search], $page_url)); ?>">&raquo;</a>
                                <?php else : ?>
                                    <span class="tablenav-pages-navspan button disabled">&rsaquo;</span>
                                    <span class="tablenav-pages-navspan button disabled">&raquo;</span>
                                <?php endif; ?>
                            </span>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </form>
        <?php
    }

    // -------------------------------------------------------------------------
    // Rules: Linked Posts Detail View
    // -------------------------------------------------------------------------

    private function render_rule_linked_posts(int $rule_id): void
    {
        global $wpdb;

        $rules_table   = $wpdb->prefix . 'lw_rules';
        $applied_table = $wpdb->prefix . 'lw_applied_links';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $rule = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$rules_table} WHERE id = %d", $rule_id));

        if (!$rule) {
            echo '<div class="notice notice-error"><p>' . esc_html__('Rule not found.', 'leanautolinks') . '</p></div>';
            return;
        }

        // Pagination.
        $per_page     = 50;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Query parameter for pagination, no state change.
        $current_page = isset($_GET['paged']) ? max(1, (int) $_GET['paged']) : 1;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $total_count  = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT post_id) FROM {$applied_table} WHERE rule_id = %d",
            $rule_id
        ));
        $total_pages = max(1, (int) ceil($total_count / $per_page));
        $offset      = ($current_page - 1) * $per_page;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $linked_posts = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT al.post_id, p.post_title, p.post_type, p.post_status,
                    COUNT(*) as link_count, MAX(al.applied_at) as last_applied
             FROM {$applied_table} al
             INNER JOIN {$wpdb->posts} p ON p.ID = al.post_id
             WHERE al.rule_id = %d
             GROUP BY al.post_id, p.post_title, p.post_type, p.post_status
             ORDER BY last_applied DESC
             LIMIT %d OFFSET %d",
            $rule_id,
            $per_page,
            $offset
        ));

        $back_url = admin_url('tools.php?page=leanautolinks&tab=rules');
        $page_url = admin_url('tools.php?page=leanautolinks&tab=rules&view_rule=' . $rule_id);
        ?>
        <div class="lw-section">
            <p>
                <a href="<?php echo esc_url($back_url); ?>" class="button">&larr; <?php echo esc_html__('Back to Rules', 'leanautolinks'); ?></a>
            </p>
            <h2>
                <?php
                /* translators: %s: keyword */
                echo esc_html(sprintf(__('Posts linked by "%s"', 'leanautolinks'), $rule->keyword));
                ?>
            </h2>
            <p class="description">
                <?php echo esc_html(sprintf(
                    /* translators: 1: target URL, 2: total posts count */
                    __('Target: %1$s — %2$d posts linked', 'leanautolinks'),
                    $rule->target_url,
                    $total_count
                )); ?>
            </p>
        </div>

        <?php if (empty($linked_posts)) : ?>
            <p><?php echo esc_html__('No posts have been linked with this rule yet.', 'leanautolinks'); ?></p>
        <?php else : ?>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php echo esc_html__('Post Title', 'leanautolinks'); ?></th>
                        <th><?php echo esc_html__('Post Type', 'leanautolinks'); ?></th>
                        <th><?php echo esc_html__('Status', 'leanautolinks'); ?></th>
                        <th><?php echo esc_html__('Links', 'leanautolinks'); ?></th>
                        <th><?php echo esc_html__('Last Applied', 'leanautolinks'); ?></th>
                        <th><?php echo esc_html__('Actions', 'leanautolinks'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($linked_posts as $lp) : ?>
                        <tr>
                            <td><strong><?php echo esc_html($lp->post_title ?: __('(no title)', 'leanautolinks')); ?></strong></td>
                            <td><span class="lw-badge"><?php echo esc_html($lp->post_type); ?></span></td>
                            <td><?php echo esc_html(ucfirst($lp->post_status)); ?></td>
                            <td><?php echo esc_html((string) $lp->link_count); ?></td>
                            <td><?php echo esc_html($lp->last_applied ? wp_date(get_option('date_format') . ' ' . get_option('time_format'), strtotime($lp->last_applied)) : '—'); ?></td>
                            <td>
                                <a href="<?php echo esc_url(get_permalink($lp->post_id)); ?>" target="_blank" rel="noopener" class="button button-small">
                                    <?php echo esc_html__('View', 'leanautolinks'); ?>
                                </a>
                                <a href="<?php echo esc_url(get_edit_post_link($lp->post_id)); ?>" class="button button-small">
                                    <?php echo esc_html__('Edit', 'leanautolinks'); ?>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php if ($total_pages > 1) : ?>
                <div class="tablenav bottom">
                    <div class="tablenav-pages">
                        <span class="displaying-num">
                            <?php echo esc_html(sprintf(
                                /* translators: %s: number of items */
                                _n('%s item', '%s items', $total_count, 'leanautolinks'),
                                number_format_i18n($total_count)
                            )); ?>
                        </span>
                        <span class="pagination-links">
                            <?php if ($current_page > 1) : ?>
                                <a class="first-page button" href="<?php echo esc_url(add_query_arg('paged', 1, $page_url)); ?>">&laquo;</a>
                                <a class="prev-page button" href="<?php echo esc_url(add_query_arg('paged', $current_page - 1, $page_url)); ?>">&lsaquo;</a>
                            <?php else : ?>
                                <span class="tablenav-pages-navspan button disabled">&laquo;</span>
                                <span class="tablenav-pages-navspan button disabled">&lsaquo;</span>
                            <?php endif; ?>
                            <span class="paging-input">
                                <?php echo esc_html($current_page); ?>
                                <?php echo esc_html__('of', 'leanautolinks'); ?>
                                <span class="total-pages"><?php echo esc_html((string) $total_pages); ?></span>
                            </span>
                            <?php if ($current_page < $total_pages) : ?>
                                <a class="next-page button" href="<?php echo esc_url(add_query_arg('paged', $current_page + 1, $page_url)); ?>">&rsaquo;</a>
                                <a class="last-page button" href="<?php echo esc_url(add_query_arg('paged', $total_pages, $page_url)); ?>">&raquo;</a>
                            <?php else : ?>
                                <span class="tablenav-pages-navspan button disabled">&rsaquo;</span>
                                <span class="tablenav-pages-navspan button disabled">&raquo;</span>
                            <?php endif; ?>
                        </span>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif;
    }

    // -------------------------------------------------------------------------
    // Tab: Queue
    // -------------------------------------------------------------------------

    private function render_queue_tab(): void
    {
        global $wpdb;

        $queue_stats = $this->queue_repo->get_stats();
        $table       = $wpdb->prefix . 'lw_queue';

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Query parameter for filtering queue by status, no state change.
        $filter_status = isset($_GET['status']) ? sanitize_text_field(wp_unslash($_GET['status'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Query parameter for search, no state change.
        $search        = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';

        $where  = '1=1';
        $params = [];

        if (!empty($filter_status)) {
            $where   .= ' AND q.status = %s';
            $params[] = $filter_status;
        }
        if (!empty($search)) {
            $where   .= ' AND (q.post_id = %d OR p.post_title LIKE %s)';
            $params[] = (int) $search;
            $params[] = '%' . $wpdb->esc_like($search) . '%';
        }

        // Count total for pagination.
        $count_sql = "SELECT COUNT(*) FROM {$table} q WHERE {$where}";
        if (empty($params)) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Safe: table name from $wpdb->prefix.
            $total_queue_items = (int) $wpdb->get_var($count_sql);
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Safe: table name from $wpdb->prefix, values via prepare().
            $total_queue_items = (int) $wpdb->get_var($wpdb->prepare($count_sql, ...$params));
        }

        $queue_per_page    = 50;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Query parameter for pagination, no state change.
        $queue_current_page = isset($_GET['paged']) ? max(1, (int) $_GET['paged']) : 1;
        $queue_total_pages = max(1, (int) ceil($total_queue_items / $queue_per_page));
        $queue_offset      = ($queue_current_page - 1) * $queue_per_page;

        $query_params = array_merge($params, [$queue_per_page, $queue_offset]);

        $sql   = "SELECT q.*, p.post_title
                  FROM {$table} q
                  LEFT JOIN {$wpdb->posts} p ON q.post_id = p.ID
                  WHERE {$where}
                  ORDER BY q.priority ASC, q.scheduled_at DESC
                  LIMIT %d OFFSET %d";
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $items = $wpdb->get_results($wpdb->prepare($sql, ...$query_params));

        $total = $queue_stats['total'];
        $page_url = admin_url('tools.php?page=leanautolinks&tab=queue');

        ?>
        <!-- Queue Statistics Bar -->
        <div class="lw-queue-stats-bar">
            <span class="lw-stat-item lw-stat-blue">
                <?php echo esc_html__('Pending', 'leanautolinks'); ?>: <strong><?php echo esc_html((string) $queue_stats['pending']); ?></strong>
            </span>
            <span class="lw-stat-item lw-stat-yellow">
                <?php echo esc_html__('Processing', 'leanautolinks'); ?>: <strong><?php echo esc_html((string) $queue_stats['processing']); ?></strong>
            </span>
            <span class="lw-stat-item lw-stat-green">
                <?php echo esc_html__('Done', 'leanautolinks'); ?>: <strong><?php echo esc_html((string) $queue_stats['done']); ?></strong>
            </span>
            <span class="lw-stat-item lw-stat-red">
                <?php echo esc_html__('Failed', 'leanautolinks'); ?>: <strong><?php echo esc_html((string) $queue_stats['failed']); ?></strong>
            </span>
        </div>

        <!-- Segmented Progress Bar -->
        <?php if ($total > 0) : ?>
            <?php
            $pct_done       = round(($queue_stats['done'] / $total) * 100, 1);
            $pct_processing = round(($queue_stats['processing'] / $total) * 100, 1);
            $pct_failed     = round(($queue_stats['failed'] / $total) * 100, 1);
            $pct_pending    = max(0, 100 - $pct_done - $pct_processing - $pct_failed);
            ?>
            <div class="lw-progress-segmented">
                <?php if ($pct_done > 0) : ?>
                    <div class="lw-progress-segment lw-progress-done" style="width: <?php echo esc_attr((string) $pct_done); ?>%;" title="<?php echo esc_attr(sprintf('%s: %d (%s%%)', __('Done', 'leanautolinks'), $queue_stats['done'], $pct_done)); ?>">
                        <?php if ($pct_done > 8) : ?><?php echo esc_html((string) $queue_stats['done']); ?><?php endif; ?>
                    </div>
                <?php endif; ?>
                <?php if ($pct_processing > 0) : ?>
                    <div class="lw-progress-segment lw-progress-active" style="width: <?php echo esc_attr((string) $pct_processing); ?>%;" title="<?php echo esc_attr(sprintf('%s: %d', __('Processing', 'leanautolinks'), $queue_stats['processing'])); ?>">
                    </div>
                <?php endif; ?>
                <?php if ($pct_failed > 0) : ?>
                    <div class="lw-progress-segment lw-progress-failed" style="width: <?php echo esc_attr((string) $pct_failed); ?>%;" title="<?php echo esc_attr(sprintf('%s: %d', __('Failed', 'leanautolinks'), $queue_stats['failed'])); ?>">
                    </div>
                <?php endif; ?>
                <?php if ($pct_pending > 0) : ?>
                    <div class="lw-progress-segment lw-progress-pending" style="width: <?php echo esc_attr((string) $pct_pending); ?>%;" title="<?php echo esc_attr(sprintf('%s: %d', __('Pending', 'leanautolinks'), $queue_stats['pending'])); ?>">
                    </div>
                <?php endif; ?>
            </div>
            <p class="lw-progress-label">
                <?php echo esc_html(sprintf(
                    /* translators: 1: done count 2: total count 3: percentage */
                    __('%1$d of %2$d posts processed (%3$s%%)', 'leanautolinks'),
                    $queue_stats['done'],
                    $total,
                    $pct_done
                )); ?>
                <?php if ($queue_stats['processing'] > 0) : ?>
                    &mdash; <span class="lw-processing-pulse"><?php echo esc_html(sprintf(
                        /* translators: %d: number of posts currently processing */
                        __('%d processing now', 'leanautolinks'),
                        $queue_stats['processing']
                    )); ?></span>
                <?php endif; ?>
                <?php
                // Calculate ETA or show idle status based on recent processing activity.
                $remaining_posts = $queue_stats['pending'] + $queue_stats['processing'];
                if ($remaining_posts > 0) :
                    $last_processed_at = (int) get_option('leanautolinks_last_processed_at', 0);
                    $seconds_since_last = time() - $last_processed_at;
                    // Active = batch ran within last 2 minutes (2x the 60s recurring interval).
                    $is_active = $last_processed_at > 0 && $seconds_since_last < 120;

                    if (!$is_active) :
                        ?>
                        &mdash; <span class="lw-eta lw-queue-idle"><?php echo esc_html__('Queue idle — waiting for cron trigger', 'leanautolinks'); ?></span>
                    <?php else :
                        $perf_table = $wpdb->prefix . 'lw_performance_log';
                        // Calculate ETA from observed throughput (posts processed in last 5 minutes).
                        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
                        $recent_count = (int) $wpdb->get_var(
                            "SELECT COUNT(*) FROM {$perf_table} WHERE event_type = 'process_single' AND logged_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)"
                        );
                        if ($recent_count > 0) {
                            // Real throughput: posts processed per second in the last 5 minutes.
                            $posts_per_second = $recent_count / 300.0;
                            $eta_seconds = (int) ($remaining_posts / $posts_per_second);
                        } else {
                            // Fallback: estimate from avg processing time + 60s cron interval.
                            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
                            $avg_ms = (float) $wpdb->get_var(
                                "SELECT AVG(duration_ms) FROM {$perf_table} WHERE event_type = 'process_single'"
                            );
                            if ($avg_ms <= 0) {
                                $avg_ms = 100.0;
                            }
                            $batch_size = (int) get_option('leanautolinks_batch_size', 25);
                            $seconds_per_batch = ($batch_size * $avg_ms / 1000) + 60;
                            $eta_seconds = (int) (ceil($remaining_posts / $batch_size) * $seconds_per_batch);
                        }

                        if ($eta_seconds < 60) :
                            $eta_text = __('< 1 minute remaining', 'leanautolinks');
                        elseif ($eta_seconds < 3600) :
                            $eta_text = sprintf(
                                /* translators: %d: minutes remaining */
                                __('~%d minutes remaining', 'leanautolinks'),
                                (int) ceil($eta_seconds / 60)
                            );
                        else :
                            $hours = floor($eta_seconds / 3600);
                            $mins = (int) ceil(($eta_seconds % 3600) / 60);
                            $eta_text = sprintf(
                                /* translators: 1: hours 2: minutes remaining */
                                __('~%1$dh %2$dm remaining', 'leanautolinks'),
                                $hours,
                                $mins
                            );
                        endif;
                        ?>
                        &mdash; <span class="lw-processing-pulse"><?php
                            echo esc_html(sprintf(
                                /* translators: %s: time ago like "30s" or "1m" */
                                __('Last batch %s ago', 'leanautolinks'),
                                $seconds_since_last < 60
                                    ? $seconds_since_last . 's'
                                    : (int) ceil($seconds_since_last / 60) . 'm'
                            ));
                        ?></span>
                        &mdash; <span class="lw-eta"><?php echo esc_html($eta_text); ?></span>
                    <?php endif; ?>
                <?php endif; ?>
            </p>
        <?php endif; ?>

        <!-- Queue Actions -->
        <div class="lw-section">
            <p>
                <button type="button" class="button button-primary lw-ajax-action" data-action="bulk_reprocess" data-scope="all">
                    <?php echo esc_html__('Reprocess All Posts', 'leanautolinks'); ?>
                </button>
                <button type="button" class="button lw-ajax-action" data-action="retry_failed">
                    <?php echo esc_html__('Retry Failed', 'leanautolinks'); ?>
                </button>
                <button type="button" class="button lw-ajax-action" data-action="clear_done">
                    <?php echo esc_html__('Clear Completed', 'leanautolinks'); ?>
                </button>
            </p>
            <div id="lw-queue-action-status" class="lw-status-message" style="display:none;"></div>
        </div>

        <!-- How the Queue Works -->
        <div class="lw-section lw-info-box">
            <h3><?php echo esc_html__('How the Queue Works', 'leanautolinks'); ?></h3>
            <div class="lw-info-grid">
                <div class="lw-info-item">
                    <strong><?php echo esc_html__('Priority (1-100)', 'leanautolinks'); ?></strong>
                    <p><?php echo esc_html__('Lower number = processed first. New posts get priority 10 (processed before bulk reprocessing at priority 50).', 'leanautolinks'); ?></p>
                </div>
                <div class="lw-info-item">
                    <strong><?php echo esc_html__('Processing Order', 'leanautolinks'); ?></strong>
                    <p><?php echo esc_html__('Posts are processed by priority (ascending), then by scheduled date (newest first). This ensures new content gets links before old content during bulk operations.', 'leanautolinks'); ?></p>
                </div>
                <div class="lw-info-item">
                    <strong><?php echo esc_html__('Retries', 'leanautolinks'); ?></strong>
                    <p><?php echo esc_html__('Failed posts are retried up to 3 times automatically. After 3 failures, they remain in "Failed" status until you manually retry them.', 'leanautolinks'); ?></p>
                </div>
                <div class="lw-info-item">
                    <strong><?php echo esc_html__('Background Processing', 'leanautolinks'); ?></strong>
                    <p><?php echo esc_html(sprintf(
                        /* translators: 1: batch size 2: concurrency */
                        __('Action Scheduler processes %1$d posts per batch with up to %2$d concurrent jobs. This runs in the background without affecting your site speed.', 'leanautolinks'),
                        (int) get_option('leanautolinks_batch_size', 100),
                        (int) get_option('leanautolinks_max_concurrent_jobs', 3)
                    )); ?></p>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="tablenav top">
            <form method="get" action="<?php echo esc_url($page_url); ?>" style="display:inline;">
                <input type="hidden" name="page" value="leanautolinks" />
                <input type="hidden" name="tab" value="queue" />
                <select name="status">
                    <option value=""><?php echo esc_html__('All statuses', 'leanautolinks'); ?></option>
                    <option value="pending" <?php selected($filter_status, 'pending'); ?>><?php echo esc_html__('Pending', 'leanautolinks'); ?></option>
                    <option value="processing" <?php selected($filter_status, 'processing'); ?>><?php echo esc_html__('Processing', 'leanautolinks'); ?></option>
                    <option value="done" <?php selected($filter_status, 'done'); ?>><?php echo esc_html__('Done', 'leanautolinks'); ?></option>
                    <option value="failed" <?php selected($filter_status, 'failed'); ?>><?php echo esc_html__('Failed', 'leanautolinks'); ?></option>
                </select>
                <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="<?php echo esc_attr__('Search by post title or ID...', 'leanautolinks'); ?>" class="regular-text" />
                <button type="submit" class="button"><?php echo esc_html__('Filter', 'leanautolinks'); ?></button>
            </form>
        </div>

        <!-- Queue Table -->
        <table class="widefat striped">
            <thead>
                <tr>
                    <th><?php echo esc_html__('Post', 'leanautolinks'); ?></th>
                    <th><?php echo esc_html__('Status', 'leanautolinks'); ?></th>
                    <th><?php echo esc_html__('Triggered By', 'leanautolinks'); ?></th>
                    <th><?php echo esc_html__('Priority', 'leanautolinks'); ?></th>
                    <th><?php echo esc_html__('Attempts', 'leanautolinks'); ?></th>
                    <th><?php echo esc_html__('Scheduled At', 'leanautolinks'); ?></th>
                    <th><?php echo esc_html__('Processed At', 'leanautolinks'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($items)) : ?>
                    <tr>
                        <td colspan="7"><?php echo esc_html__('Queue is empty.', 'leanautolinks'); ?></td>
                    </tr>
                <?php else : ?>
                    <?php foreach ($items as $item) : ?>
                        <tr>
                            <td>
                                <?php if (!empty($item->post_title)) : ?>
                                    <a href="<?php echo esc_url(get_permalink((int) $item->post_id) ?: '#'); ?>" target="_blank" rel="noopener">
                                        <?php echo esc_html($item->post_title); ?>
                                    </a>
                                <?php else : ?>
                                    <a href="<?php echo esc_url(get_permalink((int) $item->post_id) ?: '#'); ?>" target="_blank" rel="noopener">
                                        #<?php echo esc_html((string) $item->post_id); ?>
                                    </a>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                $status_class = match ($item->status) {
                                    'pending'    => 'lw-badge-blue',
                                    'processing' => 'lw-badge-yellow',
                                    'done'       => 'lw-badge-green',
                                    'failed'     => 'lw-badge-red',
                                    default      => '',
                                };
                                ?>
                                <span class="lw-badge <?php echo esc_attr($status_class); ?>">
                                    <?php echo esc_html(ucfirst($item->status)); ?>
                                </span>
                            </td>
                            <td><?php echo esc_html($item->triggered_by); ?></td>
                            <td><?php echo esc_html((string) $item->priority); ?></td>
                            <td><?php echo esc_html((string) $item->attempts); ?></td>
                            <td><?php echo esc_html($item->scheduled_at); ?></td>
                            <td><?php echo esc_html($item->processed_at ?: '-'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <?php if ($queue_total_pages > 1) : ?>
            <div class="tablenav bottom">
                <div class="tablenav-pages">
                    <span class="displaying-num">
                        <?php echo esc_html(sprintf(
                            /* translators: %d: total number of items */
                            _n('%d item', '%d items', $total_queue_items, 'leanautolinks'),
                            $total_queue_items
                        )); ?>
                    </span>
                    <span class="pagination-links">
                        <?php if ($queue_current_page > 1) : ?>
                            <a class="first-page button" href="<?php echo esc_url(add_query_arg(['paged' => 1, 'status' => $filter_status], $page_url)); ?>">&laquo;</a>
                            <a class="prev-page button" href="<?php echo esc_url(add_query_arg(['paged' => $queue_current_page - 1, 'status' => $filter_status], $page_url)); ?>">&lsaquo;</a>
                        <?php else : ?>
                            <span class="tablenav-pages-navspan button disabled">&laquo;</span>
                            <span class="tablenav-pages-navspan button disabled">&lsaquo;</span>
                        <?php endif; ?>
                        <span class="paging-input">
                            <?php echo esc_html((string) $queue_current_page); ?>
                            <?php echo esc_html__('of', 'leanautolinks'); ?>
                            <span class="total-pages"><?php echo esc_html((string) $queue_total_pages); ?></span>
                        </span>
                        <?php if ($queue_current_page < $queue_total_pages) : ?>
                            <a class="next-page button" href="<?php echo esc_url(add_query_arg(['paged' => $queue_current_page + 1, 'status' => $filter_status], $page_url)); ?>">&rsaquo;</a>
                            <a class="last-page button" href="<?php echo esc_url(add_query_arg(['paged' => $queue_total_pages, 'status' => $filter_status], $page_url)); ?>">&raquo;</a>
                        <?php else : ?>
                            <span class="tablenav-pages-navspan button disabled">&rsaquo;</span>
                            <span class="tablenav-pages-navspan button disabled">&raquo;</span>
                        <?php endif; ?>
                    </span>
                </div>
            </div>
        <?php endif; ?>
        <?php
    }

    // -------------------------------------------------------------------------
    // Tab: Exclusions
    // -------------------------------------------------------------------------

    private function render_exclusions_tab(): void
    {
        global $wpdb;

        $excl_table = $wpdb->prefix . 'lw_exclusions';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Query parameter for search, no state change.
        $excl_search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';

        $excl_where  = '1=1';
        $excl_params = [];

        if (!empty($excl_search)) {
            $excl_where   .= ' AND (type LIKE %s OR value LIKE %s)';
            $excl_like     = '%' . $wpdb->esc_like($excl_search) . '%';
            $excl_params[] = $excl_like;
            $excl_params[] = $excl_like;
        }

        $count_sql = "SELECT COUNT(*) FROM {$excl_table} WHERE {$excl_where}";
        if (empty($excl_params)) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Safe: table name from $wpdb->prefix.
            $total_exclusions = (int) $wpdb->get_var($count_sql);
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Safe: table name from $wpdb->prefix, values via prepare().
            $total_exclusions = (int) $wpdb->get_var($wpdb->prepare($count_sql, ...$excl_params));
        }

        $excl_per_page    = 50;
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Query parameter for pagination, no state change.
        $excl_current_page = isset($_GET['paged']) ? max(1, (int) $_GET['paged']) : 1;
        $excl_total_pages = max(1, (int) ceil($total_exclusions / $excl_per_page));
        $excl_offset      = ($excl_current_page - 1) * $excl_per_page;

        $query_params = array_merge($excl_params, [$excl_per_page, $excl_offset]);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Safe: dynamic WHERE clause with correct placeholder count.
        $exclusions = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$excl_table} WHERE {$excl_where} ORDER BY created_at DESC LIMIT %d OFFSET %d",
                ...$query_params
            )
        );

        ?>
        <!-- Add Exclusion Form -->
        <div class="lw-section">
            <h2><?php echo esc_html__('Add Exclusion', 'leanautolinks'); ?></h2>
            <form id="lw-add-exclusion-form" class="lw-inline-form">
                <?php wp_nonce_field('leanautolinks_admin', '_lw_nonce'); ?>
                <input type="hidden" name="action_type" value="create_exclusion" />
                <table class="form-table">
                    <tr>
                        <td>
                            <label for="lw-excl-type"><?php echo esc_html__('Type', 'leanautolinks'); ?></label>
                            <select id="lw-excl-type" name="excl_type">
                                <option value="post"><?php echo esc_html__('Post ID', 'leanautolinks'); ?></option>
                                <option value="url"><?php echo esc_html__('URL Pattern', 'leanautolinks'); ?></option>
                                <option value="keyword"><?php echo esc_html__('Keyword', 'leanautolinks'); ?></option>
                                <option value="post_type"><?php echo esc_html__('Post Type', 'leanautolinks'); ?></option>
                            </select>
                        </td>
                        <td>
                            <label for="lw-excl-value"><?php echo esc_html__('Value', 'leanautolinks'); ?></label>
                            <input type="text" id="lw-excl-value" name="excl_value" class="regular-text" required />
                        </td>
                        <td style="vertical-align: bottom;">
                            <button type="submit" class="button button-primary">
                                <?php echo esc_html__('Add Exclusion', 'leanautolinks'); ?>
                            </button>
                        </td>
                    </tr>
                </table>
            </form>
            <div id="lw-exclusion-status" class="lw-status-message" style="display:none;"></div>
        </div>

        <!-- Common Suggestions -->
        <div class="lw-section">
            <h3><?php echo esc_html__('Quick Add', 'leanautolinks'); ?></h3>
            <p class="description"><?php echo esc_html__('Common exclusions you might want to add:', 'leanautolinks'); ?></p>
            <p>
                <button type="button" class="button lw-quick-exclusion" data-type="url" data-value="/">
                    <?php echo esc_html__('Exclude Homepage', 'leanautolinks'); ?>
                </button>
                <button type="button" class="button lw-quick-exclusion" data-type="url" data-value="/contact">
                    <?php echo esc_html__('Exclude Contact Page', 'leanautolinks'); ?>
                </button>
                <button type="button" class="button lw-quick-exclusion" data-type="post_type" data-value="page">
                    <?php echo esc_html__('Exclude All Pages', 'leanautolinks'); ?>
                </button>
            </p>
        </div>

        <!-- Exclusions Search -->
        <div class="tablenav top">
            <form method="get" action="<?php echo esc_url(admin_url('tools.php?page=leanautolinks&tab=exclusions')); ?>" style="display:inline;">
                <input type="hidden" name="page" value="leanautolinks" />
                <input type="hidden" name="tab" value="exclusions" />
                <input type="search" name="s" value="<?php echo esc_attr($excl_search); ?>" placeholder="<?php echo esc_attr__('Search exclusions...', 'leanautolinks'); ?>" class="regular-text" />
                <button type="submit" class="button"><?php echo esc_html__('Search', 'leanautolinks'); ?></button>
            </form>
        </div>

        <!-- Exclusions Table -->
        <table class="widefat striped">
            <thead>
                <tr>
                    <th><?php echo esc_html__('Type', 'leanautolinks'); ?></th>
                    <th><?php echo esc_html__('Value', 'leanautolinks'); ?></th>
                    <th><?php echo esc_html__('Created At', 'leanautolinks'); ?></th>
                    <th><?php echo esc_html__('Actions', 'leanautolinks'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($exclusions)) : ?>
                    <tr>
                        <td colspan="4"><?php echo esc_html__('No exclusions configured.', 'leanautolinks'); ?></td>
                    </tr>
                <?php else : ?>
                    <?php foreach ($exclusions as $excl) : ?>
                        <tr data-exclusion-id="<?php echo esc_attr((string) $excl->id); ?>">
                            <td><span class="lw-badge"><?php echo esc_html(ucfirst($excl->type)); ?></span></td>
                            <td><?php echo esc_html($excl->value); ?></td>
                            <td><?php echo esc_html($excl->created_at); ?></td>
                            <td>
                                <button type="button" class="button button-small lw-delete-exclusion" data-exclusion-id="<?php echo esc_attr((string) $excl->id); ?>">
                                    <?php echo esc_html__('Delete', 'leanautolinks'); ?>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <?php
        $excl_page_url = admin_url('tools.php?page=leanautolinks&tab=exclusions');
        if ($excl_total_pages > 1) : ?>
            <div class="tablenav bottom">
                <div class="tablenav-pages">
                    <span class="displaying-num">
                        <?php echo esc_html(sprintf(
                            /* translators: %d: number of exclusion rules */
                            _n('%d exclusion', '%d exclusions', $total_exclusions, 'leanautolinks'),
                            $total_exclusions
                        )); ?>
                    </span>
                    <span class="pagination-links">
                        <?php if ($excl_current_page > 1) : ?>
                            <a class="first-page button" href="<?php echo esc_url(add_query_arg(['paged' => 1], $excl_page_url)); ?>">&laquo;</a>
                            <a class="prev-page button" href="<?php echo esc_url(add_query_arg(['paged' => $excl_current_page - 1], $excl_page_url)); ?>">&lsaquo;</a>
                        <?php else : ?>
                            <span class="tablenav-pages-navspan button disabled">&laquo;</span>
                            <span class="tablenav-pages-navspan button disabled">&lsaquo;</span>
                        <?php endif; ?>
                        <span class="paging-input">
                            <?php echo esc_html((string) $excl_current_page); ?>
                            <?php echo esc_html__('of', 'leanautolinks'); ?>
                            <span class="total-pages"><?php echo esc_html((string) $excl_total_pages); ?></span>
                        </span>
                        <?php if ($excl_current_page < $excl_total_pages) : ?>
                            <a class="next-page button" href="<?php echo esc_url(add_query_arg(['paged' => $excl_current_page + 1], $excl_page_url)); ?>">&rsaquo;</a>
                            <a class="last-page button" href="<?php echo esc_url(add_query_arg(['paged' => $excl_total_pages], $excl_page_url)); ?>">&raquo;</a>
                        <?php else : ?>
                            <span class="tablenav-pages-navspan button disabled">&rsaquo;</span>
                            <span class="tablenav-pages-navspan button disabled">&raquo;</span>
                        <?php endif; ?>
                    </span>
                </div>
            </div>
        <?php endif; ?>
        <?php
    }

    // -------------------------------------------------------------------------
    // Tab: Settings
    // -------------------------------------------------------------------------

    private function render_settings_tab(): void
    {
        // Current settings with defaults.
        $max_links          = (int) get_option('leanautolinks_max_links_per_post', 10);
        $max_per_1000       = (int) get_option('leanautolinks_max_links_per_1000_words', 5);
        $max_affiliate      = (int) get_option('leanautolinks_max_affiliate_per_post', 3);
        $affiliate_ratio    = (int) get_option('leanautolinks_affiliate_ratio_limit', 30);
        $min_distance       = (int) get_option('leanautolinks_min_distance_between_links', 100);
        $min_content_length = (int) get_option('leanautolinks_min_content_length', 200);
        $supported_types    = (array) get_option('leanautolinks_supported_post_types', ['post', 'page']);
        $batch_size         = (int) get_option('leanautolinks_batch_size', 100);
        $concurrency        = (int) get_option('leanautolinks_max_concurrent_jobs', 3);
        $cache_ttl_internal = (int) get_option('leanautolinks_cache_ttl_internal', 24);
        $cache_ttl_affiliate = (int) get_option('leanautolinks_cache_ttl_affiliate', 12);

        // Get all registered public post types.
        $all_post_types = get_post_types(['public' => true], 'objects');

        ?>
        <form id="lw-settings-form">
            <?php wp_nonce_field('leanautolinks_admin', '_lw_nonce'); ?>
            <input type="hidden" name="action_type" value="save_settings" />

            <h2><?php echo esc_html__('Link Limits', 'leanautolinks'); ?></h2>
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="lw-max-links"><?php echo esc_html__('Max links per post', 'leanautolinks'); ?></label>
                    </th>
                    <td>
                        <input type="number" id="lw-max-links" name="max_links_per_post" value="<?php echo esc_attr((string) $max_links); ?>" min="1" max="100" class="small-text" />
                        <p class="description"><?php echo esc_html__('Total maximum number of auto-inserted links (internal + affiliate + entity) per post. Recommended: 5-15 for SEO best practices.', 'leanautolinks'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="lw-max-per-1000"><?php echo esc_html__('Max links per 1000 words', 'leanautolinks'); ?></label>
                    </th>
                    <td>
                        <input type="number" id="lw-max-per-1000" name="max_links_per_1000_words" value="<?php echo esc_attr((string) $max_per_1000); ?>" min="1" max="50" class="small-text" />
                        <p class="description"><?php echo esc_html__('Scales link density based on content length. A 2000-word post with this set to 5 would get up to 10 links. Prevents over-linking short posts.', 'leanautolinks'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="lw-max-affiliate"><?php echo esc_html__('Max affiliate links per post', 'leanautolinks'); ?></label>
                    </th>
                    <td>
                        <input type="number" id="lw-max-affiliate" name="max_affiliate_per_post" value="<?php echo esc_attr((string) $max_affiliate); ?>" min="0" max="50" class="small-text" />
                        <p class="description"><?php echo esc_html__('Hard cap on affiliate links per post. Affiliate links always include rel="sponsored nofollow" automatically.', 'leanautolinks'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="lw-affiliate-ratio"><?php echo esc_html__('Affiliate ratio limit (%)', 'leanautolinks'); ?></label>
                    </th>
                    <td>
                        <input type="number" id="lw-affiliate-ratio" name="affiliate_ratio_limit" value="<?php echo esc_attr((string) $affiliate_ratio); ?>" min="0" max="100" class="small-text" />
                        <p class="description"><?php echo esc_html__('Maximum percentage of links that can be affiliate.', 'leanautolinks'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="lw-min-distance"><?php echo esc_html__('Min distance between links (chars)', 'leanautolinks'); ?></label>
                    </th>
                    <td>
                        <input type="number" id="lw-min-distance" name="min_distance_between_links" value="<?php echo esc_attr((string) $min_distance); ?>" min="0" max="1000" class="small-text" />
                        <p class="description"><?php echo esc_html__('Minimum character spacing between two auto-inserted links. Prevents clustering of links in the same paragraph. Set to 0 to disable.', 'leanautolinks'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="lw-min-content"><?php echo esc_html__('Min content length (chars)', 'leanautolinks'); ?></label>
                    </th>
                    <td>
                        <input type="number" id="lw-min-content" name="min_content_length" value="<?php echo esc_attr((string) $min_content_length); ?>" min="0" max="5000" class="small-text" />
                        <p class="description"><?php echo esc_html__('Posts shorter than this will be skipped.', 'leanautolinks'); ?></p>
                    </td>
                </tr>
            </table>

            <h2><?php echo esc_html__('Post Types', 'leanautolinks'); ?></h2>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php echo esc_html__('Supported post types', 'leanautolinks'); ?></th>
                    <td>
                        <p class="description" style="margin-bottom: 8px;"><?php echo esc_html__('Select which post types should have automatic links inserted. Only checked types will be processed by the queue and engine.', 'leanautolinks'); ?></p>
                        <?php foreach ($all_post_types as $pt) : ?>
                            <label style="display: block; margin-bottom: 4px;">
                                <input type="checkbox" name="supported_post_types[]"
                                       value="<?php echo esc_attr($pt->name); ?>"
                                       <?php checked(in_array($pt->name, $supported_types, true)); ?> />
                                <?php echo esc_html($pt->label); ?> <code><?php echo esc_html($pt->name); ?></code>
                            </label>
                        <?php endforeach; ?>
                    </td>
                </tr>
            </table>

            <h2><?php echo esc_html__('Processing', 'leanautolinks'); ?></h2>
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="lw-batch-size"><?php echo esc_html__('Batch size', 'leanautolinks'); ?></label>
                    </th>
                    <td>
                        <input type="number" id="lw-batch-size" name="batch_size" value="<?php echo esc_attr((string) $batch_size); ?>" min="10" max="1000" class="small-text" />
                        <p class="description"><?php echo esc_html__('Posts processed per Action Scheduler batch.', 'leanautolinks'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="lw-concurrency"><?php echo esc_html__('Concurrency', 'leanautolinks'); ?></label>
                    </th>
                    <td>
                        <input type="number" id="lw-concurrency" name="max_concurrent_jobs" value="<?php echo esc_attr((string) $concurrency); ?>" min="1" max="10" class="small-text" />
                        <p class="description"><?php echo esc_html__('Max concurrent Action Scheduler jobs.', 'leanautolinks'); ?></p>
                    </td>
                </tr>
            </table>

            <h2><?php echo esc_html__('Cache', 'leanautolinks'); ?></h2>
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="lw-cache-ttl-internal"><?php echo esc_html__('Cache TTL internal/entity (hours)', 'leanautolinks'); ?></label>
                    </th>
                    <td>
                        <input type="number" id="lw-cache-ttl-internal" name="cache_ttl_internal" value="<?php echo esc_attr((string) $cache_ttl_internal); ?>" min="1" max="168" class="small-text" />
                        <p class="description"><?php echo esc_html__('How long to cache internal and entity rules before refreshing from the database. Longer TTL = fewer DB queries, but rule changes take longer to appear on the frontend.', 'leanautolinks'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="lw-cache-ttl-affiliate"><?php echo esc_html__('Cache TTL affiliate (hours)', 'leanautolinks'); ?></label>
                    </th>
                    <td>
                        <input type="number" id="lw-cache-ttl-affiliate" name="cache_ttl_affiliate" value="<?php echo esc_attr((string) $cache_ttl_affiliate); ?>" min="1" max="168" class="small-text" />
                        <p class="description"><?php echo esc_html__('Separate cache TTL for affiliate rules. Typically shorter than internal since affiliate URLs/parameters may change more frequently.', 'leanautolinks'); ?></p>
                    </td>
                </tr>
            </table>

            <p class="submit">
                <button type="submit" class="button button-primary">
                    <?php echo esc_html__('Save Settings', 'leanautolinks'); ?>
                </button>
            </p>
            <div id="lw-settings-status" class="lw-status-message" style="display:none;"></div>
        </form>
        <?php
    }

    // -------------------------------------------------------------------------
    // AJAX Handlers
    // -------------------------------------------------------------------------

    private function ajax_save_settings(): void
    {
        // Nonce already verified in handle_ajax() via check_ajax_referer().
        $settings_map = [
            'max_links_per_post'        => 'leanautolinks_max_links_per_post',
            'max_links_per_1000_words'  => 'leanautolinks_max_links_per_1000_words',
            'max_affiliate_per_post'    => 'leanautolinks_max_affiliate_per_post',
            'affiliate_ratio_limit'     => 'leanautolinks_affiliate_ratio_limit',
            'min_distance_between_links' => 'leanautolinks_min_distance_between_links',
            'min_content_length'        => 'leanautolinks_min_content_length',
            'batch_size'                => 'leanautolinks_batch_size',
            'max_concurrent_jobs'       => 'leanautolinks_max_concurrent_jobs',
            'cache_ttl_internal'        => 'leanautolinks_cache_ttl_internal',
            'cache_ttl_affiliate'       => 'leanautolinks_cache_ttl_affiliate',
        ];

        foreach ($settings_map as $form_key => $option_key) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in handle_ajax().
            if (isset($_POST[$form_key])) {
                // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified in handle_ajax(). Value cast to int.
                update_option($option_key, absint(wp_unslash($_POST[$form_key])));
            }
        }

        // Post types as array.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in handle_ajax().
        if (isset($_POST['supported_post_types']) && is_array($_POST['supported_post_types'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in handle_ajax().
            $post_types = array_map('sanitize_text_field', wp_unslash($_POST['supported_post_types']));
        } else {
            $post_types = ['post'];
        }

        update_option('leanautolinks_supported_post_types', $post_types);

        wp_send_json_success(__('Settings saved.', 'leanautolinks'));
    }

    private function ajax_create_rule(): void
    {
        // Nonce already verified in handle_ajax() via check_ajax_referer().
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in handle_ajax().
        $keyword    = isset($_POST['keyword']) ? sanitize_text_field(wp_unslash($_POST['keyword'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in handle_ajax().
        $target_url = isset($_POST['target_url']) ? esc_url_raw(wp_unslash($_POST['target_url'])) : '';

        if (empty($keyword) || empty($target_url)) {
            wp_send_json_error(__('Keyword and Target URL are required.', 'leanautolinks'));
        }

        // Duplicate keyword check.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in handle_ajax().
        $case_sensitive = isset($_POST['case_sensitive']) ? (bool) absint(wp_unslash($_POST['case_sensitive'])) : false;
        $existing = $this->rules_repo->find_by_keyword($keyword, $case_sensitive);
        if ($existing) {
            wp_send_json_error(sprintf(
                /* translators: %s: the duplicate keyword */
                __('The keyword "%s" is already used by another rule.', 'leanautolinks'),
                $keyword
            ));
        }

        $data = [
            'keyword'        => $keyword,
            'target_url'     => $target_url,
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in handle_ajax().
            'rule_type'      => isset($_POST['rule_type']) ? sanitize_text_field(wp_unslash($_POST['rule_type'])) : 'internal',
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in handle_ajax().
            'entity_type'    => !empty($_POST['entity_type']) ? sanitize_text_field(wp_unslash($_POST['entity_type'])) : null,
            // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified in handle_ajax(). Value cast to int.
            'priority'       => isset($_POST['priority']) ? absint(wp_unslash($_POST['priority'])) : 10,
            // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified in handle_ajax(). Value cast to int.
            'max_per_post'   => isset($_POST['max_per_post']) ? absint(wp_unslash($_POST['max_per_post'])) : 1,
            // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified in handle_ajax(). Value cast to int.
            'case_sensitive' => isset($_POST['case_sensitive']) ? absint(wp_unslash($_POST['case_sensitive'])) : 0,
            'is_active'      => 1,
            // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified in handle_ajax(). Value cast to int.
            'nofollow'       => isset($_POST['nofollow']) ? absint(wp_unslash($_POST['nofollow'])) : 0,
            // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified in handle_ajax(). Value cast to int.
            'sponsored'      => isset($_POST['sponsored']) ? absint(wp_unslash($_POST['sponsored'])) : 0,
        ];

        $id = $this->rules_repo->create($data);

        if ($id === 0) {
            wp_send_json_error(__('Failed to create rule.', 'leanautolinks'));
        }

        $plugin = \LeanAutoLinks\Plugin::get_instance();
        $plugin->rule_change_handler()->handle($id, 'created');

        wp_send_json_success([
            'message' => __('Rule created successfully.', 'leanautolinks'),
            'id'      => $id,
        ]);
    }

    private function ajax_update_rule(): void
    {
        // Nonce already verified in handle_ajax() via check_ajax_referer().
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in handle_ajax().
        $id = isset($_POST['rule_id']) ? absint(wp_unslash($_POST['rule_id'])) : 0;

        if ($id <= 0) {
            wp_send_json_error(__('Invalid rule ID.', 'leanautolinks'));
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in handle_ajax().
        $keyword    = isset($_POST['keyword']) ? sanitize_text_field(wp_unslash($_POST['keyword'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in handle_ajax().
        $target_url = isset($_POST['target_url']) ? esc_url_raw(wp_unslash($_POST['target_url'])) : '';

        if (empty($keyword) || empty($target_url)) {
            wp_send_json_error(__('Keyword and Target URL are required.', 'leanautolinks'));
        }

        // Duplicate keyword check (exclude current rule).
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in handle_ajax().
        $case_sensitive = isset($_POST['case_sensitive']) ? (bool) absint(wp_unslash($_POST['case_sensitive'])) : false;
        $existing = $this->rules_repo->find_by_keyword($keyword, $case_sensitive, $id);
        if ($existing) {
            wp_send_json_error(sprintf(
                /* translators: %s: the duplicate keyword */
                __('The keyword "%s" is already used by another rule.', 'leanautolinks'),
                $keyword
            ));
        }

        $data = [
            'keyword'        => $keyword,
            'target_url'     => $target_url,
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in handle_ajax().
            'rule_type'      => isset($_POST['rule_type']) ? sanitize_text_field(wp_unslash($_POST['rule_type'])) : 'internal',
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in handle_ajax().
            'entity_type'    => !empty($_POST['entity_type']) ? sanitize_text_field(wp_unslash($_POST['entity_type'])) : null,
            // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified in handle_ajax(). Value cast to int.
            'priority'       => isset($_POST['priority']) ? absint(wp_unslash($_POST['priority'])) : 10,
            // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified in handle_ajax(). Value cast to int.
            'max_per_post'   => isset($_POST['max_per_post']) ? absint(wp_unslash($_POST['max_per_post'])) : 1,
            // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified in handle_ajax(). Value cast to int.
            'case_sensitive' => isset($_POST['case_sensitive']) ? absint(wp_unslash($_POST['case_sensitive'])) : 0,
            // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified in handle_ajax(). Value cast to int.
            'nofollow'       => isset($_POST['nofollow']) ? absint(wp_unslash($_POST['nofollow'])) : 0,
            // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified in handle_ajax(). Value cast to int.
            'sponsored'      => isset($_POST['sponsored']) ? absint(wp_unslash($_POST['sponsored'])) : 0,
        ];

        $updated = $this->rules_repo->update($id, $data);

        if (!$updated) {
            wp_send_json_error(__('Failed to update rule.', 'leanautolinks'));
        }

        $plugin = \LeanAutoLinks\Plugin::get_instance();
        $plugin->rule_change_handler()->handle($id, 'updated');

        wp_send_json_success([
            'message' => __('Rule updated successfully.', 'leanautolinks'),
            'id'      => $id,
        ]);
    }

    private function ajax_delete_rule(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in handle_ajax().
        $id = isset($_POST['rule_id']) ? absint(wp_unslash($_POST['rule_id'])) : 0;

        if ($id <= 0) {
            wp_send_json_error(__('Invalid rule ID.', 'leanautolinks'));
        }

        $plugin = \LeanAutoLinks\Plugin::get_instance();
        $plugin->rule_change_handler()->handle($id, 'deleted');
        $this->rules_repo->delete($id);

        wp_send_json_success(__('Rule deleted.', 'leanautolinks'));
    }

    private function ajax_toggle_rule(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in handle_ajax().
        $id = isset($_POST['rule_id']) ? absint(wp_unslash($_POST['rule_id'])) : 0;

        if ($id <= 0) {
            wp_send_json_error(__('Invalid rule ID.', 'leanautolinks'));
        }

        $this->rules_repo->toggle($id);

        $plugin = \LeanAutoLinks\Plugin::get_instance();
        $plugin->rule_change_handler()->handle($id, 'toggled');

        $rule = $this->rules_repo->find($id);

        wp_send_json_success([
            'message'   => __('Rule toggled.', 'leanautolinks'),
            'is_active' => $rule ? (int) $rule->is_active : 0,
        ]);
    }

    private function ajax_create_exclusion(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in handle_ajax().
        $type  = isset($_POST['excl_type']) ? sanitize_text_field(wp_unslash($_POST['excl_type'])) : '';
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in handle_ajax().
        $value = isset($_POST['excl_value']) ? sanitize_text_field(wp_unslash($_POST['excl_value'])) : '';

        $valid_types = ['post', 'url', 'keyword', 'post_type'];

        if (!in_array($type, $valid_types, true) || empty($value)) {
            wp_send_json_error(__('Invalid exclusion data.', 'leanautolinks'));
        }

        $id = $this->exclusions_repo->create([
            'type'  => $type,
            'value' => $value,
        ]);

        wp_send_json_success([
            'message' => __('Exclusion created.', 'leanautolinks'),
            'id'      => $id,
        ]);
    }

    private function ajax_delete_exclusion(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in handle_ajax().
        $id = isset($_POST['exclusion_id']) ? absint(wp_unslash($_POST['exclusion_id'])) : 0;

        if ($id <= 0) {
            wp_send_json_error(__('Invalid exclusion ID.', 'leanautolinks'));
        }

        $deleted = $this->exclusions_repo->delete($id);

        if (!$deleted) {
            wp_send_json_error(__('Exclusion not found.', 'leanautolinks'));
        }

        wp_send_json_success(__('Exclusion deleted.', 'leanautolinks'));
    }

    private function ajax_bulk_action(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in handle_ajax().
        $action = isset($_POST['bulk_type']) ? sanitize_text_field(wp_unslash($_POST['bulk_type'])) : '';

        switch ($action) {
            case 'bulk_reprocess':
                $this->ajax_bulk_reprocess();
                break;
            case 'retry_failed':
                $this->ajax_retry_failed();
                break;
            case 'clear_done':
                $this->ajax_clear_done();
                break;
            case 'delete_rules':
                $this->ajax_bulk_delete_rules();
                break;
            case 'activate_rules':
                $this->ajax_bulk_update_rules(1);
                break;
            case 'deactivate_rules':
                $this->ajax_bulk_update_rules(0);
                break;
            default:
                wp_send_json_error(__('Unknown bulk action.', 'leanautolinks'));
        }
    }

    private function ajax_bulk_reprocess(): void
    {
        global $wpdb;

        $supported_types = (array) get_option('leanautolinks_supported_post_types', ['post', 'page']);
        $placeholders    = implode(',', array_fill(0, count($supported_types), '%s'));

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Safe: dynamic IN() placeholders.
        $post_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ({$placeholders})",
                ...$supported_types
            )
        );

        $enqueued = 0;
        foreach ($post_ids as $post_id) {
            $this->queue_repo->enqueue((int) $post_id, 'bulk_reprocess', 50);
            $enqueued++;
        }

        if (function_exists('as_schedule_single_action') && $enqueued > 0) {
            as_schedule_single_action(
                time(),
                'leanautolinks_process_batch',
                [['triggered_by' => 'bulk_reprocess']],
                'leanautolinks'
            );
        }

        wp_send_json_success(sprintf(
            /* translators: %d: number of posts enqueued */
            __('%d posts enqueued for reprocessing.', 'leanautolinks'),
            $enqueued
        ));
    }

    private function ajax_retry_failed(): void
    {
        global $wpdb;

        $table = $wpdb->prefix . 'lw_queue';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Safe: table name from $wpdb->prefix.
        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'failed'");

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Safe: table name from $wpdb->prefix.
        $wpdb->query(
            "UPDATE {$table} SET status = 'pending', attempts = 0, error_log = NULL, processed_at = NULL WHERE status = 'failed'"
        );

        if (function_exists('as_schedule_single_action') && $count > 0) {
            as_schedule_single_action(
                time(),
                'leanautolinks_process_batch',
                [['triggered_by' => 'retry_failed']],
                'leanautolinks'
            );
        }

        wp_send_json_success(sprintf(
            /* translators: %d: number of items retried */
            __('%d failed items reset for retry.', 'leanautolinks'),
            $count
        ));
    }

    private function ajax_clear_done(): void
    {
        global $wpdb;

        $table = $wpdb->prefix . 'lw_queue';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Safe: table name from $wpdb->prefix.
        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'done'");
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Safe: table name from $wpdb->prefix.
        $wpdb->query("DELETE FROM {$table} WHERE status = 'done'");

        wp_send_json_success(sprintf(
            /* translators: %d: number of items cleared */
            __('%d completed items cleared.', 'leanautolinks'),
            $count
        ));
    }

    private function ajax_bulk_delete_rules(): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in handle_ajax().
        if (isset($_POST['rule_ids']) && is_array($_POST['rule_ids'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in handle_ajax().
            $ids = array_map('absint', wp_unslash($_POST['rule_ids']));
        } else {
            $ids = [];
        }

        if (empty($ids)) {
            wp_send_json_error(__('No rules selected.', 'leanautolinks'));
        }

        $plugin = \LeanAutoLinks\Plugin::get_instance();
        foreach ($ids as $id) {
            $plugin->rule_change_handler()->handle($id, 'deleted');
            $this->rules_repo->delete($id);
        }

        wp_send_json_success(sprintf(
            /* translators: %d: number of rules deleted */
            __('%d rules deleted.', 'leanautolinks'),
            count($ids)
        ));
    }

    private function ajax_bulk_update_rules(int $is_active): void
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in handle_ajax().
        if (isset($_POST['rule_ids']) && is_array($_POST['rule_ids'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified in handle_ajax().
            $ids = array_map('absint', wp_unslash($_POST['rule_ids']));
        } else {
            $ids = [];
        }

        if (empty($ids)) {
            wp_send_json_error(__('No rules selected.', 'leanautolinks'));
        }

        $plugin = \LeanAutoLinks\Plugin::get_instance();
        foreach ($ids as $id) {
            $this->rules_repo->update($id, ['is_active' => $is_active]);
            $plugin->rule_change_handler()->handle($id, 'toggled');
        }

        $label = $is_active ? __('activated', 'leanautolinks') : __('deactivated', 'leanautolinks');

        wp_send_json_success(sprintf(
            /* translators: 1: number of rules 2: activated/deactivated */
            __('%1$d rules %2$s.', 'leanautolinks'),
            count($ids),
            $label
        ));
    }
}
