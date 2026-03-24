<?php
declare(strict_types=1);

namespace LeanAutoLinks\Cli;

if (!defined('ABSPATH')) {
    exit;
}

use LeanAutoLinks\Cache\RulesCache;
use LeanAutoLinks\Repositories\QueueRepository;
use LeanAutoLinks\Repositories\RulesRepository;

/**
 * WP-CLI commands for LeanAutoLinks: seed data, bulk operations, cache management.
 *
 * ## EXAMPLES
 *
 *     wp leanautolinks seed --posts=15000 --actors=500 --glossary=500 --affiliates=100
 *     wp leanautolinks bulk-reprocess
 *     wp leanautolinks queue-stats
 *     wp leanautolinks cache flush
 *     wp leanautolinks cache stats
 *     wp leanautolinks cache warm
 */
final class SeedCommand
{
    /**
     * Seed the database with realistic test data.
     *
     * ## OPTIONS
     *
     * [--posts=<number>]
     * : Number of posts to generate.
     * ---
     * default: 15000
     * ---
     *
     * [--actors=<number>]
     * : Number of entity/actor rules (companies).
     * ---
     * default: 500
     * ---
     *
     * [--glossary=<number>]
     * : Number of glossary term rules.
     * ---
     * default: 500
     * ---
     *
     * [--affiliates=<number>]
     * : Number of affiliate rules.
     * ---
     * default: 100
     * ---
     *
     * [--growth-simulation=<number>]
     * : Mark last N posts as recent for throughput testing.
     * ---
     * default: 700
     * ---
     *
     * ## EXAMPLES
     *
     *     wp leanautolinks seed
     *     wp leanautolinks seed --posts=5000 --glossary=200
     *
     * @param array<string> $args
     * @param array<string, string> $assoc_args
     */
    public function seed(array $args, array $assoc_args): void
    {
        $num_posts      = (int) ($assoc_args['posts'] ?? 15000);
        $num_actors     = (int) ($assoc_args['actors'] ?? 500);
        $num_glossary   = (int) ($assoc_args['glossary'] ?? 500);
        $num_affiliates = (int) ($assoc_args['affiliates'] ?? 100);
        $growth_sim     = (int) ($assoc_args['growth-simulation'] ?? 700);

        \WP_CLI::log("LeanAutoLinks Seed: Generating test data...");
        \WP_CLI::log("  Posts: {$num_posts}");
        \WP_CLI::log("  Actor rules: {$num_actors}");
        \WP_CLI::log("  Glossary rules: {$num_glossary}");
        \WP_CLI::log("  Affiliate rules: {$num_affiliates}");
        \WP_CLI::log("  Growth simulation: {$growth_sim}");

        // Step 1: Generate posts.
        \WP_CLI::log("\n--- Generating posts ---");
        $post_ids = $this->generate_posts($num_posts);
        \WP_CLI::success(count($post_ids) . " posts created.");

        // Step 2: Generate glossary rules.
        \WP_CLI::log("\n--- Generating glossary rules ---");
        $glossary_count = $this->generate_glossary_rules($num_glossary);
        \WP_CLI::success("{$glossary_count} glossary rules created.");

        // Step 3: Generate actor/entity rules.
        \WP_CLI::log("\n--- Generating actor/entity rules ---");
        $actor_count = $this->generate_actor_rules($num_actors);
        \WP_CLI::success("{$actor_count} actor rules created.");

        // Step 4: Generate affiliate rules.
        \WP_CLI::log("\n--- Generating affiliate rules ---");
        $aff_count = $this->generate_affiliate_rules($num_affiliates);
        \WP_CLI::success("{$aff_count} affiliate rules created.");

        // Step 5: Growth simulation (mark recent posts).
        if ($growth_sim > 0 && !empty($post_ids)) {
            \WP_CLI::log("\n--- Growth simulation ---");
            $recent = array_slice($post_ids, -$growth_sim);
            $queue  = new QueueRepository();
            foreach ($recent as $pid) {
                $queue->enqueue($pid, 'seed_growth_sim', 50);
            }
            \WP_CLI::success(count($recent) . " posts marked for throughput testing.");
        }

        \WP_CLI::success("\nSeed complete!");
    }

    /**
     * Trigger bulk reprocessing of all published posts.
     *
     * ## EXAMPLES
     *
     *     wp leanautolinks bulk-reprocess
     *
     * @subcommand bulk-reprocess
     * @param array<string> $args
     * @param array<string, string> $assoc_args
     */
    public function bulk_reprocess(array $args, array $assoc_args): void
    {
        global $wpdb;

        $supported_types = (array) get_option('leanautolinks_supported_post_types', ['post', 'page']);
        $placeholders    = implode(',', array_fill(0, count($supported_types), '%s'));

        $post_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ({$placeholders})",
                ...$supported_types
            )
        );

        $queue = new QueueRepository();
        $count = 0;
        $progress = \WP_CLI\Utils\make_progress_bar('Enqueueing posts', count($post_ids));

        foreach ($post_ids as $post_id) {
            $queue->enqueue((int) $post_id, 'cli_bulk_reprocess', 50);
            $count++;
            $progress->tick();
        }

        $progress->finish();

        // Schedule processing.
        if (function_exists('as_schedule_single_action')) {
            as_schedule_single_action(
                time(),
                'leanautolinks_process_batch',
                [['triggered_by' => 'cli_bulk_reprocess']],
                'leanautolinks'
            );
        }

        \WP_CLI::success("{$count} posts enqueued for reprocessing.");
    }

    /**
     * Display current queue statistics.
     *
     * ## EXAMPLES
     *
     *     wp leanautolinks queue-stats
     *
     * @subcommand queue-stats
     * @param array<string> $args
     * @param array<string, string> $assoc_args
     */
    public function queue_stats(array $args, array $assoc_args): void
    {
        $queue = new QueueRepository();
        $stats = $queue->get_stats();

        $rows = [];
        foreach ($stats as $key => $value) {
            $rows[] = ['Status' => ucfirst($key), 'Count' => $value];
        }

        \WP_CLI\Utils\format_items('table', $rows, ['Status', 'Count']);
    }

    /**
     * Force-process all pending queue items immediately (bypasses cron).
     *
     * Runs batches in a tight loop until the queue is empty.
     * Ideal for initial setup, migrations, or sites with many posts.
     *
     * ## OPTIONS
     *
     * [--batch-size=<size>]
     * : Posts per batch. Default: value from settings (25).
     *
     * ## EXAMPLES
     *
     *     wp leanautolinks process-now
     *     wp leanautolinks process-now --batch-size=100
     *
     * @subcommand process-now
     * @param array<string> $args
     * @param array<string, string> $assoc_args
     */
    public function process_now(array $args, array $assoc_args): void
    {
        $queue = new QueueRepository();
        $stats = $queue->get_stats();
        $total_pending = $stats['pending'];

        if ($total_pending === 0) {
            \WP_CLI::success('No pending posts in queue.');
            return;
        }

        $batch_size = isset($assoc_args['batch-size'])
            ? (int) $assoc_args['batch-size']
            : (int) get_option('leanautolinks_batch_size', 25);

        \WP_CLI::log(sprintf('Processing %d pending posts (batch size: %d)...', $total_pending, $batch_size));

        $job = new \LeanAutoLinks\Jobs\LinkProcessorJob(
            $queue,
            new \LeanAutoLinks\Repositories\AppliedLinksRepository(),
            new \LeanAutoLinks\Repositories\PerformanceRepository()
        );

        $progress = \WP_CLI\Utils\make_progress_bar('Processing posts', $total_pending);
        $processed = 0;
        $start = microtime(true);

        while (true) {
            $pending = $queue->get_pending($batch_size);
            if (empty($pending)) {
                break;
            }

            foreach ($pending as $item) {
                $job->process_single((int) $item->post_id);
                $processed++;
                $progress->tick();
            }
        }

        $progress->finish();
        $elapsed = round(microtime(true) - $start, 1);
        $rate = $elapsed > 0 ? round($processed / $elapsed * 3600) : 0;

        \WP_CLI::success(sprintf(
            '%d posts processed in %ss (%s posts/hour).',
            $processed,
            $elapsed,
            number_format($rate)
        ));
    }

    /**
     * Cache management subcommands.
     *
     * ## EXAMPLES
     *
     *     wp leanautolinks cache flush
     *     wp leanautolinks cache stats
     *     wp leanautolinks cache warm
     *
     * @param array<string> $args
     * @param array<string, string> $assoc_args
     */
    public function cache(array $args, array $assoc_args): void
    {
        if (empty($args[0])) {
            \WP_CLI::error('Subcommand required: flush, stats, or warm.');
            return;
        }

        $subcommand = $args[0];

        switch ($subcommand) {
            case 'flush':
                RulesCache::flush();
                \WP_CLI::success('LeanAutoLinks caches flushed.');
                break;

            case 'stats':
                $this->display_cache_stats();
                break;

            case 'warm':
                $this->warm_cache();
                break;

            default:
                \WP_CLI::error("Unknown subcommand: {$subcommand}. Use flush, stats, or warm.");
        }
    }

    // =========================================================================
    // Generators
    // =========================================================================

    /**
     * @return array<int> Generated post IDs.
     */
    private function generate_posts(int $count): array
    {
        $post_ids = [];
        $progress = \WP_CLI\Utils\make_progress_bar('Creating posts', $count);

        $topics = $this->get_topics();
        $paragraphs = $this->get_paragraphs();

        for ($i = 0; $i < $count; $i++) {
            $topic_idx = $i % count($topics);
            $topic = $topics[$topic_idx];

            // Generate content: 800-2000 words approximately (4-10 paragraphs).
            $num_paragraphs = random_int(4, 10);
            $content = '';
            for ($p = 0; $p < $num_paragraphs; $p++) {
                $para_idx = ($i + $p) % count($paragraphs);
                $content .= "<p>" . $paragraphs[$para_idx] . "</p>\n\n";
            }

            // Add some topic-specific keywords naturally.
            $content .= "<p>" . $this->generate_topic_paragraph($topic) . "</p>\n";

            $post_id = wp_insert_post([
                'post_title'   => $this->generate_title($topic, $i),
                'post_content' => $content,
                'post_status'  => 'publish',
                'post_type'    => 'post',
                'post_author'  => 1,
                'post_date'    => $this->random_date($i, $count),
            ], true);

            if (!is_wp_error($post_id)) {
                $post_ids[] = $post_id;
            }

            $progress->tick();

            // Flush object cache periodically to manage memory.
            if ($i % 500 === 0 && $i > 0) {
                wp_cache_flush();
            }
        }

        $progress->finish();

        return $post_ids;
    }

    private function generate_glossary_rules(int $count): int
    {
        $repo  = new RulesRepository();
        $terms = $this->get_glossary_terms();
        $created = 0;

        $progress = \WP_CLI\Utils\make_progress_bar('Creating glossary rules', $count);

        for ($i = 0; $i < $count; $i++) {
            $term = $terms[$i % count($terms)];
            $slug = sanitize_title($term);

            // Avoid exact duplicates by appending index if we wrap around.
            if ($i >= count($terms)) {
                $suffix = (int) floor($i / count($terms));
                $term   = $term . ' ' . $suffix;
                $slug   = $slug . '-' . $suffix;
            }

            $repo->create([
                'rule_type'    => 'entity',
                'keyword'      => $term,
                'target_url'   => home_url("/glosario/{$slug}/"),
                'entity_type'  => 'glossary',
                'priority'     => random_int(5, 15),
                'max_per_post' => 1,
                'is_active'    => 1,
                'nofollow'     => 0,
                'sponsored'    => 0,
            ]);

            $created++;
            $progress->tick();
        }

        $progress->finish();

        return $created;
    }

    private function generate_actor_rules(int $count): int
    {
        $repo      = new RulesRepository();
        $companies = $this->get_company_names();
        $created   = 0;

        $progress = \WP_CLI\Utils\make_progress_bar('Creating actor rules', $count);

        for ($i = 0; $i < $count; $i++) {
            $name = $companies[$i % count($companies)];
            $slug = sanitize_title($name);

            if ($i >= count($companies)) {
                $suffix = (int) floor($i / count($companies));
                $name   = $name . ' ' . $suffix;
                $slug   = $slug . '-' . $suffix;
            }

            $entity_types = ['company', 'vc', 'person'];
            $entity_type  = $entity_types[$i % count($entity_types)];

            $repo->create([
                'rule_type'    => 'entity',
                'keyword'      => $name,
                'target_url'   => home_url("/directorio/{$entity_type}/{$slug}/"),
                'entity_type'  => $entity_type,
                'priority'     => random_int(8, 20),
                'max_per_post' => 1,
                'is_active'    => 1,
                'nofollow'     => 0,
                'sponsored'    => 0,
            ]);

            $created++;
            $progress->tick();
        }

        $progress->finish();

        return $created;
    }

    private function generate_affiliate_rules(int $count): int
    {
        $repo     = new RulesRepository();
        $products = $this->get_affiliate_products();
        $created  = 0;

        $progress = \WP_CLI\Utils\make_progress_bar('Creating affiliate rules', $count);

        for ($i = 0; $i < $count; $i++) {
            $product = $products[$i % count($products)];

            if ($i >= count($products)) {
                $suffix  = (int) floor($i / count($products));
                $product['name'] .= ' ' . $suffix;
            }

            $repo->create([
                'rule_type'    => 'affiliate',
                'keyword'      => $product['name'],
                'target_url'   => $product['url'],
                'priority'     => random_int(15, 30),
                'max_per_post' => 1,
                'is_active'    => 1,
                'nofollow'     => 1,
                'sponsored'    => 1,
            ]);

            $created++;
            $progress->tick();
        }

        $progress->finish();

        return $created;
    }

    // =========================================================================
    // Cache Helpers
    // =========================================================================

    private function display_cache_stats(): void
    {
        $has_ext = wp_using_ext_object_cache();
        $sentinel = wp_cache_get('lw_sentinel', 'leanautolinks');
        $version = RulesCache::get_version();

        $rows = [
            ['Metric' => 'External Object Cache', 'Value' => $has_ext ? 'Yes' : 'No'],
            ['Metric' => 'Sentinel Active', 'Value' => $sentinel !== false ? 'Yes' : 'No'],
            ['Metric' => 'Rules Version', 'Value' => (string) $version],
        ];

        // Try to get hit rate stats if available.
        $cache_stats = wp_cache_get('lw_cache_stats', 'leanautolinks');
        if (is_array($cache_stats)) {
            foreach ($cache_stats as $key => $val) {
                $rows[] = ['Metric' => ucfirst(str_replace('_', ' ', $key)), 'Value' => (string) $val];
            }
        }

        \WP_CLI\Utils\format_items('table', $rows, ['Metric', 'Value']);
    }

    private function warm_cache(): void
    {
        \WP_CLI::log('Warming rules cache...');

        // Force cache rebuild.
        RulesCache::flush();
        $rules = RulesCache::get_active_rules();

        \WP_CLI::success(count($rules) . ' rules loaded into cache.');

        // Schedule warm via Action Scheduler if available.
        if (function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action(
                'leanautolinks_warm_cache',
                [['tier' => 1, 'limit' => 100]],
                'leanautolinks'
            );
            \WP_CLI::log('Scheduled cache warming for recent posts.');
        }
    }

    // =========================================================================
    // Data Generators
    // =========================================================================

    /**
     * @return array<string>
     */
    private function get_topics(): array
    {
        return [
            'inteligencia artificial', 'machine learning', 'startups', 'fintech',
            'blockchain', 'ciberseguridad', 'cloud computing', 'SaaS',
            'venture capital', 'emprendimiento', 'transformacion digital',
            'big data', 'IoT', 'robotica', 'biotecnologia',
            'ecommerce', 'marketing digital', 'product management',
            'devops', 'microservicios', 'API economy', 'open source',
            'regulacion tecnologica', 'privacidad de datos', 'web3',
        ];
    }

    /**
     * @return array<string>
     */
    private function get_paragraphs(): array
    {
        return [
            'La inteligencia artificial esta transformando la manera en que las empresas operan en America Latina. Desde la automatizacion de procesos hasta la personalizacion de experiencias de usuario, las aplicaciones de IA se multiplican a un ritmo sin precedentes. Los inversores de venture capital estan prestando especial atencion a las startups que desarrollan soluciones basadas en machine learning para resolver problemas especificos de la region.',

            'El ecosistema emprendedor latinoamericano ha experimentado un crecimiento extraordinario en los ultimos anos. Ciudades como Ciudad de Mexico, Sao Paulo, Buenos Aires y Bogota se han consolidado como hubs de innovacion tecnologica. Las rondas de financiamiento han alcanzado cifras record, con unicornios emergiendo en sectores como fintech, logistica y healthtech.',

            'La transformacion digital no es solo una tendencia, es una necesidad para la supervivencia empresarial. Las companias que no adoptan tecnologias como cloud computing, big data analytics y automatizacion de procesos se arriesgan a quedar rezagadas frente a competidores mas agiles. La pandemia acelero esta transicion, forzando a muchas organizaciones a digitalizar sus operaciones en cuestion de meses.',

            'El sector fintech en Latinoamerica representa una de las oportunidades mas grandes de la region. Con millones de personas sin acceso a servicios bancarios tradicionales, las startups fintech estan democratizando el acceso a servicios financieros a traves de aplicaciones moviles, billeteras digitales y plataformas de lending alternativo.',

            'La ciberseguridad se ha convertido en una prioridad critica para empresas de todos los tamanos. Los ataques de ransomware, phishing y las vulnerabilidades en la cadena de suministro de software representan amenazas crecientes. Las inversiones en soluciones de seguridad informatica han aumentado significativamente, creando nuevas oportunidades para startups especializadas.',

            'El concepto de API economy esta redefiniendo como las empresas construyen y entregan servicios digitales. Las APIs permiten la integracion rapida entre sistemas, habilitando modelos de negocio basados en plataformas y ecosistemas. Empresas como Stripe, Twilio y Plaid han demostrado el enorme valor de construir productos API-first.',

            'La computacion en la nube continua su expansion con proveedores como AWS, Google Cloud y Azure compitiendo por cuota de mercado en Latinoamerica. La adopcion de arquitecturas cloud-native y el uso de contenedores con Kubernetes se han convertido en el estandar de la industria para el despliegue de aplicaciones modernas.',

            'El desarrollo de productos digitales requiere un enfoque centrado en el usuario y una metodologia agil. Los equipos de product management utilizan frameworks como Jobs to Be Done, Design Thinking y Lean Startup para validar hipotesis rapidamente y construir productos que realmente resuelvan problemas del mercado.',

            'La regulacion tecnologica esta evolucionando rapidamente en toda la region. Leyes de proteccion de datos personales, regulaciones de criptomonedas y marcos normativos para la inteligencia artificial estan siendo discutidos e implementados en diversos paises. Las empresas deben mantenerse actualizadas para cumplir con estos requisitos cambiantes.',

            'El ecosistema de open source ha madurado significativamente, con empresas de todos los tamanos contribuyendo y beneficiandose de proyectos de codigo abierto. Herramientas como PostgreSQL, Redis, Kubernetes y Linux forman la columna vertebral de la infraestructura tecnologica moderna.',

            'Las metodologias DevOps y las practicas de integracion y entrega continua se han convertido en pilares fundamentales del desarrollo de software moderno. La automatizacion de pipelines de CI/CD, la infraestructura como codigo y la observabilidad son competencias esenciales para los equipos de ingenieria.',

            'El Internet de las Cosas esta conectando dispositivos fisicos al mundo digital a una velocidad impresionante. Desde sensores industriales hasta wearables de salud, la generacion de datos en tiempo real esta creando nuevas oportunidades para la analitica avanzada y la toma de decisiones automatizada.',
        ];
    }

    /**
     * @return array<string>
     */
    private function get_glossary_terms(): array
    {
        return [
            'Inteligencia Artificial', 'Machine Learning', 'Deep Learning',
            'API', 'REST API', 'GraphQL', 'Microservicios', 'Serverless',
            'Blockchain', 'Smart Contract', 'NFT', 'DeFi', 'Web3',
            'Cloud Computing', 'SaaS', 'PaaS', 'IaaS', 'Kubernetes',
            'Docker', 'DevOps', 'CI/CD', 'Git', 'Agile', 'Scrum',
            'Product Market Fit', 'MVP', 'Lean Startup', 'Design Thinking',
            'Venture Capital', 'Angel Investor', 'Serie A', 'Serie B',
            'Unicornio', 'Fintech', 'Healthtech', 'Edtech', 'Proptech',
            'Big Data', 'Data Lake', 'Data Warehouse', 'ETL',
            'NLP', 'Computer Vision', 'Redes Neuronales', 'GPT',
            'Ciberseguridad', 'Ransomware', 'Zero Trust', 'Firewall',
            'SEO', 'SEM', 'Growth Hacking', 'Funnel de Conversion',
            'PostgreSQL', 'Redis', 'MongoDB', 'Elasticsearch',
            'React', 'Vue.js', 'Node.js', 'Python', 'TypeScript',
            'Laravel', 'Django', 'Ruby on Rails', 'Spring Boot',
            'AWS', 'Google Cloud', 'Azure', 'Terraform',
            'Observabilidad', 'Monitoreo', 'APM', 'Logging',
            'IoT', 'Edge Computing', 'Robotica', 'Automatizacion',
            'Open Banking', 'Neobank', 'Insurtech', 'Regtech',
            'Marketplace', 'Plataforma', 'Ecosistema', 'API Economy',
            'OKR', 'KPI', 'Sprint', 'Retrospectiva', 'Backlog',
            'UX', 'UI', 'Wireframe', 'Prototipo', 'User Research',
            'CAC', 'LTV', 'MRR', 'ARR', 'Churn Rate',
            'Product Led Growth', 'Community Led Growth', 'Sales Led Growth',
            'Token', 'DAO', 'Metaverso', 'Realidad Aumentada',
            'Quantum Computing', 'Biotecnologia', 'Nanotecnologia',
            'Green Tech', 'Carbon Neutral', 'ESG', 'Impacto Social',
        ];
    }

    /**
     * @return array<string>
     */
    private function get_company_names(): array
    {
        return [
            'Rappi', 'Nubank', 'MercadoLibre', 'Kavak', 'Clip',
            'Konfio', 'Bitso', 'Platzi', 'Globant', 'dLocal',
            'VTEX', 'Nuvemshop', 'Ualá', 'Fintual', 'Cornershop',
            'NotCo', 'Betterfly', 'Clara', 'Jeeves', 'Habi',
            'Addi', 'Crehana', 'Houm', 'Truora', 'Belvo',
            'Tul', 'Kushki', 'Mundi', 'Nowports', 'Merama',
            'Yuno', 'Pomelo', 'Mural', 'Auth0', 'Vercel',
            'Deel', 'Remote', 'Lemon Cash', 'Buenbit', 'Ripio',
            'Mercado Bitcoin', 'Hashdex', 'Foxbit', 'Pismo', 'Dock',
            'Creditas', 'Loft', 'QuintoAndar', 'Gympass', 'Loggi',
            'iFood', 'Movile', 'Stone', 'PagSeguro', 'Ebanx',
            'Wildlife Studios', 'Olist', 'Hotmart', 'RD Station', 'TOTVS',
            'Softplan', 'Resultados Digitais', 'Conta Azul', 'Omie',
            'Zenvia', 'Take Blip', 'Cielo', 'Rede', 'GetNet',
            'Brex', 'Camunda', 'DataRobot', 'Stripe', 'Plaid',
            'Twilio', 'Segment', 'Amplitude', 'Mixpanel', 'LaunchDarkly',
            'Datadog', 'New Relic', 'PagerDuty', 'Snyk', 'Crowdstrike',
            'Notion', 'Airtable', 'Figma', 'Canva', 'Miro',
            'Linear', 'Shortcut', 'ClickUp', 'Monday.com', 'Asana',
            'Slack', 'Discord', 'Zoom', 'Loom', 'Calendly',
        ];
    }

    /**
     * @return array<array{name: string, url: string}>
     */
    private function get_affiliate_products(): array
    {
        return [
            ['name' => 'AWS Activate', 'url' => 'https://aws.amazon.com/activate/?ref=leanautolinks'],
            ['name' => 'Google Cloud Credits', 'url' => 'https://cloud.google.com/startup/?ref=leanautolinks'],
            ['name' => 'Stripe Atlas', 'url' => 'https://stripe.com/atlas?ref=leanautolinks'],
            ['name' => 'HubSpot CRM', 'url' => 'https://www.hubspot.com/?ref=leanautolinks'],
            ['name' => 'Notion Team', 'url' => 'https://www.notion.so/teams?ref=leanautolinks'],
            ['name' => 'Figma Professional', 'url' => 'https://www.figma.com/pricing/?ref=leanautolinks'],
            ['name' => 'Vercel Pro', 'url' => 'https://vercel.com/pricing?ref=leanautolinks'],
            ['name' => 'Datadog APM', 'url' => 'https://www.datadoghq.com/?ref=leanautolinks'],
            ['name' => 'Airtable Business', 'url' => 'https://airtable.com/pricing?ref=leanautolinks'],
            ['name' => 'Linear Pro', 'url' => 'https://linear.app/pricing?ref=leanautolinks'],
            ['name' => 'Segment CDP', 'url' => 'https://segment.com/pricing/?ref=leanautolinks'],
            ['name' => 'Amplitude Analytics', 'url' => 'https://amplitude.com/pricing?ref=leanautolinks'],
            ['name' => 'Mixpanel Growth', 'url' => 'https://mixpanel.com/pricing/?ref=leanautolinks'],
            ['name' => 'PostHog Cloud', 'url' => 'https://posthog.com/pricing?ref=leanautolinks'],
            ['name' => 'Cloudflare Pro', 'url' => 'https://www.cloudflare.com/plans/?ref=leanautolinks'],
            ['name' => 'DigitalOcean Credits', 'url' => 'https://www.digitalocean.com/?ref=leanautolinks'],
            ['name' => 'MongoDB Atlas', 'url' => 'https://www.mongodb.com/atlas?ref=leanautolinks'],
            ['name' => 'Redis Cloud', 'url' => 'https://redis.com/cloud/?ref=leanautolinks'],
            ['name' => 'Algolia Search', 'url' => 'https://www.algolia.com/pricing/?ref=leanautolinks'],
            ['name' => 'Twilio SendGrid', 'url' => 'https://sendgrid.com/pricing/?ref=leanautolinks'],
            ['name' => 'Mailchimp Standard', 'url' => 'https://mailchimp.com/pricing/?ref=leanautolinks'],
            ['name' => 'Calendly Teams', 'url' => 'https://calendly.com/pricing?ref=leanautolinks'],
            ['name' => 'Loom Business', 'url' => 'https://www.loom.com/pricing?ref=leanautolinks'],
            ['name' => 'Miro Business', 'url' => 'https://miro.com/pricing/?ref=leanautolinks'],
            ['name' => 'Grammarly Business', 'url' => 'https://www.grammarly.com/business?ref=leanautolinks'],
        ];
    }

    private function generate_title(string $topic, int $index): string
    {
        $templates = [
            'Como %s esta cambiando el panorama de las startups en 2025',
            'Guia completa sobre %s para emprendedores',
            'El futuro de %s en Latinoamerica',
            '%s: tendencias y oportunidades para inversores',
            'Por que %s es clave para la transformacion digital',
            '10 startups de %s que debes conocer',
            'El impacto de %s en el ecosistema tecnologico',
            'Inversion en %s: lo que los VCs estan buscando',
            '%s en 2025: predicciones y analisis',
            'Como implementar %s en tu empresa',
            'Casos de exito de %s en America Latina',
            'Los desafios de %s para startups early-stage',
        ];

        $template = $templates[$index % count($templates)];

        return sprintf($template, $topic);
    }

    private function generate_topic_paragraph(string $topic): string
    {
        $templates = [
            'En el contexto de %s, las empresas latinoamericanas estan adoptando nuevas estrategias para mantenerse competitivas en un mercado global cada vez mas exigente. La combinacion de talento local y capital internacional esta creando un ecosistema unico.',
            'Los avances recientes en %s han abierto nuevas posibilidades para startups que buscan resolver problemas especificos de la region. Desde Mexico hasta Argentina, los emprendedores estan desarrollando soluciones innovadoras que atraen la atencion de inversores globales.',
            'La adopcion de %s por parte de grandes corporaciones esta acelerando la demanda de talento especializado y creando oportunidades para consultoras y startups de nicho. Este fenomeno esta redefiniendo el mercado laboral tecnologico de la region.',
        ];

        return sprintf($templates[random_int(0, count($templates) - 1)], $topic);
    }

    private function random_date(int $index, int $total): string
    {
        // Distribute posts over the last 2 years, with more recent posts weighted higher.
        $days_back = (int) (730 * pow(($total - $index) / $total, 1.5));
        $timestamp = time() - ($days_back * 86400) - random_int(0, 86400);

        return gmdate('Y-m-d H:i:s', $timestamp);
    }
}
