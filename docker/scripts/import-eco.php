<?php
/**
 * WP-CLI command to import ecosistemastartup.com data into WordPress.
 *
 * Usage: wp eval-file /scripts/import-eco.php [--skip-posts] [--skip-glosario] [--limit=N]
 *
 * Imports posts and glosario entries from JSON exports, then generates
 * LeanAutoLinks linking rules from glosario terms.
 */

if (!defined('WP_CLI') || !WP_CLI) {
    die('This script must be run via WP-CLI.');
}

$data_dir = '/data/ecosistema';

// Parse args.
$skip_posts    = in_array('--skip-posts', $GLOBALS['argv'] ?? [], true);
$skip_glosario = in_array('--skip-glosario', $GLOBALS['argv'] ?? [], true);
$limit         = 0;
foreach ($GLOBALS['argv'] ?? [] as $arg) {
    if (str_starts_with($arg, '--limit=')) {
        $limit = (int) substr($arg, 8);
    }
}

// ─────────────────────────────────────────────
// Register glosario CPT if not already registered.
// ─────────────────────────────────────────────
if (!post_type_exists('glosario')) {
    register_post_type('glosario', [
        'public'  => true,
        'label'   => 'Glosario',
        'rewrite' => ['slug' => 'glosario'],
    ]);
    WP_CLI::log('Registered glosario post type.');
}

// ─────────────────────────────────────────────
// Import categories.
// ─────────────────────────────────────────────
$cats_file = "$data_dir/categories.json";
if (file_exists($cats_file)) {
    $cats = json_decode(file_get_contents($cats_file), true);
    WP_CLI::log(sprintf('Importing %d categories...', count($cats)));

    $cat_map = []; // old_id => new_id
    foreach ($cats as $cat) {
        $existing = get_term_by('slug', $cat['slug'], 'category');
        if ($existing) {
            $cat_map[$cat['id']] = $existing->term_id;
            continue;
        }
        $result = wp_insert_term($cat['name'], 'category', [
            'slug'        => $cat['slug'],
            'description' => $cat['description'] ?? '',
        ]);
        if (!is_wp_error($result)) {
            $cat_map[$cat['id']] = $result['term_id'];
        }
    }
    WP_CLI::success(sprintf('Categories done. Mapped %d.', count($cat_map)));
}

// ─────────────────────────────────────────────
// Import tags.
// ─────────────────────────────────────────────
$tags_file = "$data_dir/tags.json";
if (file_exists($tags_file)) {
    $tags = json_decode(file_get_contents($tags_file), true);
    WP_CLI::log(sprintf('Importing %d tags...', count($tags)));

    $tag_map = [];
    foreach ($tags as $tag) {
        $existing = get_term_by('slug', $tag['slug'], 'post_tag');
        if ($existing) {
            $tag_map[$tag['id']] = $existing->term_id;
            continue;
        }
        $result = wp_insert_term($tag['name'], 'post_tag', [
            'slug'        => $tag['slug'],
            'description' => $tag['description'] ?? '',
        ]);
        if (!is_wp_error($result)) {
            $tag_map[$tag['id']] = $result['term_id'];
        }
    }
    WP_CLI::success(sprintf('Tags done. Mapped %d.', count($tag_map)));
}

// ─────────────────────────────────────────────
// Import posts.
// ─────────────────────────────────────────────
if (!$skip_posts) {
    $posts_file = "$data_dir/posts.json";
    if (!file_exists($posts_file)) {
        WP_CLI::error("Posts file not found: $posts_file");
    }

    WP_CLI::log('Loading posts JSON (this may take a moment)...');
    $posts_raw = file_get_contents($posts_file);
    $posts     = json_decode($posts_raw, true);
    unset($posts_raw); // Free memory.

    $total = $limit > 0 ? min($limit, count($posts)) : count($posts);
    WP_CLI::log(sprintf('Importing %d posts...', $total));

    $progress = WP_CLI\Utils\make_progress_bar('Posts', $total);
    $imported  = 0;
    $skipped   = 0;

    for ($i = 0; $i < $total; $i++) {
        $post = $posts[$i];

        // Skip if slug already exists.
        $existing = get_page_by_path($post['slug'], OBJECT, 'post');
        if ($existing) {
            $skipped++;
            $progress->tick();
            continue;
        }

        $content = $post['content']['rendered'] ?? '';
        $title   = $post['title']['rendered'] ?? 'Untitled';
        $excerpt = $post['excerpt']['rendered'] ?? '';
        // Strip HTML from excerpt.
        $excerpt = wp_strip_all_tags($excerpt);

        $post_data = [
            'post_title'   => html_entity_decode($title, ENT_QUOTES, 'UTF-8'),
            'post_content' => $content,
            'post_excerpt' => $excerpt,
            'post_status'  => 'publish',
            'post_type'    => 'post',
            'post_date'    => $post['date'] ?? current_time('mysql'),
            'post_name'    => $post['slug'],
        ];

        $new_id = wp_insert_post($post_data, true);

        if (is_wp_error($new_id)) {
            WP_CLI::warning("Failed to import post '{$title}': " . $new_id->get_error_message());
            $progress->tick();
            continue;
        }

        // Map categories.
        if (!empty($post['categories']) && !empty($cat_map)) {
            $new_cats = [];
            foreach ($post['categories'] as $old_cat_id) {
                if (isset($cat_map[$old_cat_id])) {
                    $new_cats[] = $cat_map[$old_cat_id];
                }
            }
            if ($new_cats) {
                wp_set_post_categories($new_id, $new_cats);
            }
        }

        // Map tags.
        if (!empty($post['tags']) && !empty($tag_map)) {
            $new_tags = [];
            foreach ($post['tags'] as $old_tag_id) {
                if (isset($tag_map[$old_tag_id])) {
                    $new_tags[] = (int) $tag_map[$old_tag_id];
                }
            }
            if ($new_tags) {
                wp_set_post_tags($new_id, $new_tags);
            }
        }

        $imported++;
        $progress->tick();

        // Free memory every 500 posts.
        if ($imported % 500 === 0) {
            wp_cache_flush();
            WP_CLI::log(sprintf('  ... %d imported, memory: %s', $imported, size_format(memory_get_usage(true))));
        }
    }

    $progress->finish();
    WP_CLI::success(sprintf('Posts import complete. Imported: %d, Skipped: %d', $imported, $skipped));
    unset($posts);
}

// ─────────────────────────────────────────────
// Import glosario.
// ─────────────────────────────────────────────
if (!$skip_glosario) {
    $glosario_file = "$data_dir/glosario.json";
    if (!file_exists($glosario_file)) {
        WP_CLI::error("Glosario file not found: $glosario_file");
    }

    $glosario = json_decode(file_get_contents($glosario_file), true);
    $total    = $limit > 0 ? min($limit, count($glosario)) : count($glosario);
    WP_CLI::log(sprintf('Importing %d glosario entries...', $total));

    $progress  = WP_CLI\Utils\make_progress_bar('Glosario', $total);
    $imported  = 0;
    $skipped   = 0;

    for ($i = 0; $i < $total; $i++) {
        $entry = $glosario[$i];

        $existing = get_page_by_path($entry['slug'], OBJECT, 'glosario');
        if ($existing) {
            $skipped++;
            $progress->tick();
            continue;
        }

        $title   = $entry['title']['rendered'] ?? 'Untitled';
        $content = $entry['content']['rendered'] ?? '';

        $post_data = [
            'post_title'   => html_entity_decode($title, ENT_QUOTES, 'UTF-8'),
            'post_content' => $content,
            'post_status'  => 'publish',
            'post_type'    => 'glosario',
            'post_date'    => $entry['date'] ?? current_time('mysql'),
            'post_name'    => $entry['slug'],
        ];

        $new_id = wp_insert_post($post_data, true);

        if (is_wp_error($new_id)) {
            WP_CLI::warning("Failed to import glosario '{$title}': " . $new_id->get_error_message());
        } else {
            $imported++;
        }

        $progress->tick();
    }

    $progress->finish();
    WP_CLI::success(sprintf('Glosario import complete. Imported: %d, Skipped: %d', $imported, $skipped));
}

// ─────────────────────────────────────────────
// Generate LeanAutoLinks rules from glosario.
// ─────────────────────────────────────────────
WP_CLI::log('Generating LeanAutoLinks rules from glosario...');

global $wpdb;
$table = $wpdb->prefix . 'lw_rules';

// Check if the LeanAutoLinks table exists.
if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
    WP_CLI::warning('LeanAutoLinks rules table not found. Activate the plugin first, then re-run with --skip-posts --skip-glosario.');
} else {
    $glosario_posts = get_posts([
        'post_type'      => 'glosario',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
    ]);

    WP_CLI::log(sprintf('Found %d glosario posts. Creating rules...', count($glosario_posts)));

    $rules_created = 0;
    $rules_skipped = 0;

    foreach ($glosario_posts as $gp) {
        $keyword    = $gp->post_title;
        $target_url = get_permalink($gp->ID);

        // Skip duplicates.
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE keyword = %s AND rule_type = 'internal'",
            $keyword
        ));

        if ($exists > 0) {
            $rules_skipped++;
            continue;
        }

        $wpdb->insert($table, [
            'rule_type'      => 'internal',
            'keyword'        => $keyword,
            'target_url'     => $target_url,
            'entity_type'    => 'glosario',
            'entity_id'      => $gp->ID,
            'priority'       => 10,
            'max_per_post'   => 1,
            'case_sensitive'  => 0,
            'is_active'      => 1,
            'nofollow'       => 0,
            'sponsored'      => 0,
        ]);

        $rules_created++;
    }

    WP_CLI::success(sprintf('Rules created: %d, Skipped: %d', $rules_created, $rules_skipped));
}

WP_CLI::success('Import complete!');
