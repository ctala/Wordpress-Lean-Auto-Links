<?php
declare(strict_types=1);

namespace LeanAutoLinks\Admin;

if (!defined('ABSPATH')) {
    exit;
}

use LeanAutoLinks\Repositories\AppliedLinksRepository;
use LeanAutoLinks\Repositories\RulesRepository;

/**
 * Adds a "LeanAutoLinks Keywords" meta box to the post editor.
 *
 * Shows which linking rules are currently applied to the post
 * and allows creating new rules directly from the editor.
 */
final class KeywordMetaBox
{
    private RulesRepository $rules_repo;
    private AppliedLinksRepository $applied_repo;

    public function __construct(RulesRepository $rules_repo, AppliedLinksRepository $applied_repo)
    {
        $this->rules_repo  = $rules_repo;
        $this->applied_repo = $applied_repo;
    }

    /**
     * Register hooks for the meta box.
     */
    public function register(): void
    {
        add_action('add_meta_boxes', [$this, 'add_meta_box']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_ajax_leanautolinks_metabox_add_rule', [$this, 'ajax_add_rule']);
        add_action('wp_ajax_leanautolinks_metabox_remove_rule', [$this, 'ajax_remove_rule']);
    }

    /**
     * Register the meta box for all supported post types.
     */
    public function add_meta_box(): void
    {
        $supported_types = (array) get_option('leanautolinks_supported_post_types', ['post', 'page']);

        foreach ($supported_types as $post_type) {
            add_meta_box(
                'leanautolinks-keywords',
                __('LeanAutoLinks Keywords', 'leanautolinks'),
                [$this, 'render'],
                $post_type,
                'side',
                'default'
            );
        }
    }

    /**
     * Enqueue meta box CSS and JS on post editor screens.
     */
    public function enqueue_assets(string $hook): void
    {
        if (!in_array($hook, ['post.php', 'post-new.php'], true)) {
            return;
        }

        $post = get_post();
        if (!$post) {
            return;
        }

        wp_enqueue_style(
            'leanautolinks-metabox',
            LEANAUTOLINKS_URL . 'src/Admin/assets/metabox.css',
            [],
            LEANAUTOLINKS_VERSION
        );

        wp_enqueue_script(
            'leanautolinks-metabox',
            LEANAUTOLINKS_URL . 'src/Admin/assets/metabox.js',
            ['jquery'],
            LEANAUTOLINKS_VERSION,
            true
        );

        wp_localize_script('leanautolinks-metabox', 'leanautolinksMetabox', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('leanautolinks_metabox'),
            'postId'  => $post->ID,
            'strings' => [
                'confirmDelete' => __('Remove this keyword rule?', 'leanautolinks'),
                'error'         => __('An error occurred.', 'leanautolinks'),
                'added'         => __('Rule created. Post will be reprocessed.', 'leanautolinks'),
                'removed'       => __('Rule removed.', 'leanautolinks'),
            ],
        ]);
    }

    /**
     * Render the meta box content.
     */
    public function render(\WP_Post $post): void
    {
        global $wpdb;

        $applied_table = $wpdb->prefix . 'lw_applied_links';
        $rules_table   = $wpdb->prefix . 'lw_rules';

        // Get rules that have been applied to this post.
        $applied_rules = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT r.id, r.keyword, r.target_url, r.rule_type, r.is_active,
                    COUNT(al.id) as link_count
             FROM {$applied_table} al
             INNER JOIN {$rules_table} r ON r.id = al.rule_id
             WHERE al.post_id = %d
             GROUP BY r.id, r.keyword, r.target_url, r.rule_type, r.is_active
             ORDER BY r.keyword ASC",
            $post->ID
        ));

        // Get all active rules that could match this post (for reference).
        $post_url = get_permalink($post->ID);
        $rules_targeting_this = $wpdb->get_results($wpdb->prepare(
            "SELECT id, keyword, target_url, rule_type
             FROM {$rules_table}
             WHERE is_active = 1 AND target_url = %s
             ORDER BY keyword ASC",
            wp_make_link_relative($post_url ?: '')
        ));

        wp_nonce_field('leanautolinks_metabox', '_lw_metabox_nonce');
        ?>
        <div class="lw-metabox">
            <?php if (!empty($applied_rules)) : ?>
                <div class="lw-metabox-section">
                    <strong><?php echo esc_html__('Applied Keywords', 'leanautolinks'); ?></strong>
                    <ul class="lw-metabox-list">
                        <?php foreach ($applied_rules as $rule) : ?>
                            <li class="lw-metabox-item">
                                <span class="lw-metabox-keyword"><?php echo esc_html($rule->keyword); ?></span>
                                <span class="lw-metabox-badge lw-metabox-badge-<?php echo esc_attr($rule->rule_type); ?>">
                                    <?php echo esc_html(ucfirst($rule->rule_type)); ?>
                                </span>
                                <span class="lw-metabox-count">&times;<?php echo esc_html((string) $rule->link_count); ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php else : ?>
                <p class="lw-metabox-empty">
                    <?php echo esc_html__('No keywords linked in this post yet.', 'leanautolinks'); ?>
                </p>
            <?php endif; ?>

            <?php if (!empty($rules_targeting_this)) : ?>
                <div class="lw-metabox-section">
                    <strong><?php echo esc_html__('Keywords pointing here', 'leanautolinks'); ?></strong>
                    <ul class="lw-metabox-list">
                        <?php foreach ($rules_targeting_this as $rule) : ?>
                            <li class="lw-metabox-item">
                                <span class="lw-metabox-keyword"><?php echo esc_html($rule->keyword); ?></span>
                                <span class="lw-metabox-badge lw-metabox-badge-<?php echo esc_attr($rule->rule_type); ?>">
                                    <?php echo esc_html(ucfirst($rule->rule_type)); ?>
                                </span>
                                <button type="button" class="lw-mb-remove-btn" data-rule-id="<?php echo esc_attr((string) $rule->id); ?>" title="<?php echo esc_attr__('Remove rule', 'leanautolinks'); ?>">&times;</button>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <div class="lw-metabox-section lw-metabox-add">
                <strong><?php echo esc_html__('Add Keyword', 'leanautolinks'); ?></strong>
                <p class="description" style="margin: 0 0 6px;">
                    <?php echo esc_html__('Other posts containing this keyword will link here.', 'leanautolinks'); ?>
                </p>
                <div class="lw-metabox-form">
                    <input type="text" id="lw-mb-keyword" placeholder="<?php echo esc_attr__('Keyword...', 'leanautolinks'); ?>" class="widefat" />
                    <button type="button" id="lw-mb-add-btn" class="button button-primary widefat">
                        <?php echo esc_html__('Add Keyword', 'leanautolinks'); ?>
                    </button>
                    <div id="lw-mb-status" style="display:none;"></div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * AJAX: Add a new rule from the meta box.
     */
    public function ajax_add_rule(): void
    {
        check_ajax_referer('leanautolinks_metabox', '_nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied.', 'leanautolinks'));
        }

        $post_id = (int) ($_POST['post_id'] ?? 0);
        $keyword = sanitize_text_field($_POST['keyword'] ?? '');

        if (empty($keyword)) {
            wp_send_json_error(__('Keyword is required.', 'leanautolinks'));
        }

        if ($post_id <= 0) {
            wp_send_json_error(__('Invalid post.', 'leanautolinks'));
        }

        // Always use the current post's permalink as the target URL.
        $permalink  = get_permalink($post_id);
        $target_url = $permalink ? wp_make_link_relative($permalink) : '';

        if (empty($target_url)) {
            wp_send_json_error(__('Could not determine post URL. Save the post first.', 'leanautolinks'));
        }

        $data = [
            'keyword'        => $keyword,
            'target_url'     => $target_url,
            'rule_type'      => 'internal',
            'priority'       => 10,
            'max_per_post'   => 1,
            'case_sensitive' => 0,
            'nofollow'       => 0,
            'sponsored'      => 0,
            'is_active'      => 1,
        ];

        $rule_id = $this->rules_repo->create($data);

        if ($rule_id) {
            // Trigger reprocessing for this post's linked content.
            $plugin = \LeanAutoLinks\Plugin::get_instance();
            $plugin->rule_change_handler()->handle($rule_id, 'created');

            wp_send_json_success([
                'message' => __('Rule created. Post will be reprocessed.', 'leanautolinks'),
                'rule_id' => $rule_id,
            ]);
        }

        wp_send_json_error(__('Failed to create rule.', 'leanautolinks'));
    }

    /**
     * AJAX: Remove a rule from the meta box.
     */
    public function ajax_remove_rule(): void
    {
        check_ajax_referer('leanautolinks_metabox', '_nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Permission denied.', 'leanautolinks'));
        }

        $rule_id = (int) ($_POST['rule_id'] ?? 0);

        if ($rule_id <= 0) {
            wp_send_json_error(__('Invalid rule ID.', 'leanautolinks'));
        }

        $this->rules_repo->delete($rule_id);

        wp_send_json_success(__('Rule removed.', 'leanautolinks'));
    }
}
