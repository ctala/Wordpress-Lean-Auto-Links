<?php
declare(strict_types=1);

namespace LeanAutoLinks;

if (!defined('ABSPATH')) {
    exit;
}

use LeanAutoLinks\Admin\AdminPage;
use LeanAutoLinks\Admin\DashboardWidget;
use LeanAutoLinks\Admin\KeywordMetaBox;
use LeanAutoLinks\Api\AppliedController;
use LeanAutoLinks\Api\ExclusionsController;
use LeanAutoLinks\Api\HealthController;
use LeanAutoLinks\Api\PerformanceController;
use LeanAutoLinks\Api\QueueController;
use LeanAutoLinks\Api\RulesController;
use LeanAutoLinks\Cache\RulesCache;
use LeanAutoLinks\Hooks\ContentFilterHandler;
use LeanAutoLinks\Hooks\RuleChangeHandler;
use LeanAutoLinks\Hooks\SavePostHandler;
use LeanAutoLinks\Jobs\LinkProcessorJob;
use LeanAutoLinks\Repositories\AppliedLinksRepository;
use LeanAutoLinks\Repositories\ExclusionsRepository;
use LeanAutoLinks\Repositories\PerformanceRepository;
use LeanAutoLinks\Repositories\QueueRepository;
use LeanAutoLinks\Repositories\RulesRepository;

/**
 * Main plugin bootstrap class.
 *
 * Uses singleton pattern to ensure a single instance throughout the request
 * lifecycle. Registers all hooks, REST API routes, Action Scheduler actions,
 * and initialises cache layers.
 */
final class Plugin
{
    private static ?self $instance = null;

    private RulesRepository $rules_repo;
    private QueueRepository $queue_repo;
    private AppliedLinksRepository $applied_repo;
    private ExclusionsRepository $exclusions_repo;
    private PerformanceRepository $performance_repo;

    private SavePostHandler $save_post_handler;
    private ContentFilterHandler $content_filter_handler;
    private RuleChangeHandler $rule_change_handler;
    private LinkProcessorJob $link_processor_job;

    private function __construct()
    {
        // Intentionally empty. Initialisation happens in init().
    }

    public static function get_instance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Initialise the plugin: repositories, cache, hooks, and scheduled actions.
     */
    public function init(): void
    {
        $this->maybe_upgrade();
        $this->init_repositories();
        $this->init_cache();
        $this->init_handlers();
        $this->register_hooks();
        $this->register_action_scheduler_actions();
        $this->register_rest_api();
    }

    /**
     * Run upgrade routines when the plugin version changes.
     *
     * Calls dbDelta() to add any new columns/tables introduced in newer versions.
     */
    private function maybe_upgrade(): void
    {
        $stored_version = get_option('leanautolinks_db_version', '0.0.0');

        if (version_compare($stored_version, LEANAUTOLINKS_VERSION, '<')) {
            $installer = new Installer();
            $installer->activate();
        }
    }

    /**
     * Instantiate all repository classes.
     */
    private function init_repositories(): void
    {
        $this->rules_repo       = new RulesRepository();
        $this->queue_repo       = new QueueRepository();
        $this->applied_repo     = new AppliedLinksRepository();
        $this->exclusions_repo  = new ExclusionsRepository();
        $this->performance_repo = new PerformanceRepository();
    }

    /**
     * Initialise the cache layer including sentinel detection from ADR-002.
     */
    private function init_cache(): void
    {
        // Check the cache sentinel to detect external cache flushes.
        $sentinel = wp_cache_get('lw_sentinel', 'leanautolinks');
        if ($sentinel === false) {
            wp_cache_set('lw_sentinel', time(), 'leanautolinks', 0);

            // Only trigger warming if the plugin has previously processed content.
            if (get_option('leanautolinks_last_processed_at')) {
                $this->schedule_cache_warming();
            }
        }

        // Pre-detect external object cache availability for the content filter.
        ContentFilterHandler::init();
    }

    /**
     * Instantiate hook handler classes.
     */
    private function init_handlers(): void
    {
        $this->save_post_handler       = new SavePostHandler(
            $this->queue_repo,
            $this->exclusions_repo
        );
        $this->content_filter_handler  = new ContentFilterHandler(
            $this->applied_repo
        );
        $this->rule_change_handler     = new RuleChangeHandler(
            $this->rules_repo,
            $this->applied_repo,
            $this->queue_repo
        );
        $this->link_processor_job      = new LinkProcessorJob(
            $this->queue_repo,
            $this->applied_repo,
            $this->performance_repo
        );
    }

    /**
     * Register WordPress hooks.
     */
    private function register_hooks(): void
    {
        // Enqueue post for background processing on save (priority 20).
        add_action('save_post', [$this->save_post_handler, 'handle'], 20, 3);

        // Apply pre-computed links on the frontend (priority 999 = very late).
        add_filter('the_content', [$this->content_filter_handler, 'filter'], 999);

        // Load text domain for internationalisation.
        add_action('init', static function (): void {
            load_plugin_textdomain('leanautolinks', false, dirname(plugin_basename(LEANAUTOLINKS_FILE)) . '/languages');
        });

        // Register admin UI.
        if (is_admin()) {
            $admin_page = new AdminPage(
                $this->rules_repo,
                $this->queue_repo,
                $this->applied_repo,
                $this->exclusions_repo,
                $this->performance_repo
            );
            $admin_page->register();

            $meta_box = new KeywordMetaBox($this->rules_repo, $this->applied_repo);
            $meta_box->register();

            $dashboard = new DashboardWidget();
            $dashboard->register();
        }

        // Register WP-CLI commands.
        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::add_command('leanautolinks', \LeanAutoLinks\Cli\SeedCommand::class);
        }
    }

    /**
     * Register Action Scheduler actions for background processing.
     */
    private function register_action_scheduler_actions(): void
    {
        $job = $this->link_processor_job;

        add_action('leanautolinks_process_single', static function (int $post_id) use ($job): void {
            $job->process_single($post_id);
        });

        add_action('leanautolinks_process_batch', static function (array $args) use ($job): void {
            $job->process_batch($args);
        });

        add_action(LinkProcessorJob::RECURRING_HOOK, static function () use ($job): void {
            $job->process_batch([]);
        });

        add_action('leanautolinks_warm_cache', static function (array $args) use ($job): void {
            // Cache warming: re-process recent posts in batches.
            $job->process_batch($args);
        });

        add_action('leanautolinks_recache_post', static function (int $post_id) use ($job): void {
            $job->recache_post($post_id);
        });
    }

    /**
     * Register REST API routes on the rest_api_init action.
     */
    private function register_rest_api(): void
    {
        add_action('rest_api_init', function (): void {
            $rules_ctrl = new RulesController($this->rules_repo, $this->rule_change_handler);
            $rules_ctrl->register_routes();

            $queue_ctrl = new QueueController($this->queue_repo);
            $queue_ctrl->register_routes();

            $applied_ctrl = new AppliedController($this->applied_repo);
            $applied_ctrl->register_routes();

            $exclusions_ctrl = new ExclusionsController($this->exclusions_repo);
            $exclusions_ctrl->register_routes();

            $performance_ctrl = new PerformanceController($this->performance_repo);
            $performance_ctrl->register_routes();

            $health_ctrl = new HealthController($this->queue_repo, $this->rules_repo, $this->performance_repo);
            $health_ctrl->register_routes();

            do_action('leanautolinks_register_rest_routes');
        });
    }

    /**
     * Schedule cache warming after an external cache flush is detected.
     */
    private function schedule_cache_warming(): void
    {
        if (!function_exists('as_enqueue_async_action')) {
            return;
        }

        // Tier 1: Warm recently visited posts immediately.
        as_enqueue_async_action(
            'leanautolinks_warm_cache',
            [['tier' => 1, 'limit' => 100]],
            'leanautolinks'
        );

        // Tier 2: Warm recent posts with a 5-minute delay.
        as_schedule_single_action(
            time() + 300,
            'leanautolinks_warm_cache',
            [['tier' => 2, 'limit' => 500]],
            'leanautolinks'
        );
    }

    // -- Accessors for repositories (used by other plugin components) --

    public function rules_repo(): RulesRepository
    {
        return $this->rules_repo;
    }

    public function queue_repo(): QueueRepository
    {
        return $this->queue_repo;
    }

    public function applied_repo(): AppliedLinksRepository
    {
        return $this->applied_repo;
    }

    public function exclusions_repo(): ExclusionsRepository
    {
        return $this->exclusions_repo;
    }

    public function performance_repo(): PerformanceRepository
    {
        return $this->performance_repo;
    }

    public function rule_change_handler(): RuleChangeHandler
    {
        return $this->rule_change_handler;
    }

    /**
     * Prevent cloning.
     */
    private function __clone()
    {
    }
}
