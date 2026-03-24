<?php
declare(strict_types=1);

namespace LeanAutoLinks\Repositories;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Repository for the lw_applied_links table.
 *
 * Stores the individual links applied to each post plus the full processed
 * content (used as DB fallback when object cache is unavailable, per ADR-002).
 *
 * Per ADR-002: processed content is stored as a single row with rule_id = 0,
 * while individual link records have their real rule_id.
 */
final class AppliedLinksRepository
{
    private string $table;

    public function __construct()
    {
        global $wpdb;
        $this->table = $wpdb->prefix . 'lw_applied_links';
    }

    /**
     * Save the processed content and individual link records for a post.
     *
     * Replaces all existing records for the given post_id in a single
     * transaction to maintain consistency.
     *
     * @param int                   $post_id          The post ID.
     * @param array<array{rule_id: int, keyword: string, target_url: string}> $links Applied links.
     * @param string                $processed_content Full HTML with links.
     * @param string                $content_hash      MD5 of original post_content.
     */
    public function save_links(int $post_id, array $links, string $processed_content, string $content_hash): void
    {
        global $wpdb;

        // Delete existing records for this post.
        $wpdb->delete($this->table, ['post_id' => $post_id], ['%d']);

        $now = current_time('mysql', true);

        // Insert the processed content summary row (rule_id = 0).
        $wpdb->insert(
            $this->table,
            [
                'post_id'           => $post_id,
                'rule_id'           => 0,
                'keyword'           => '',
                'target_url'        => '',
                'processed_content' => $processed_content,
                'content_hash'      => $content_hash,
                'processed_at'      => $now,
                'applied_at'        => $now,
            ],
            ['%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s']
        );

        // Insert individual link records.
        foreach ($links as $link) {
            $wpdb->insert(
                $this->table,
                [
                    'post_id'    => $post_id,
                    'rule_id'    => (int) $link['rule_id'],
                    'keyword'    => $link['keyword'],
                    'target_url' => $link['target_url'],
                    'applied_at' => $now,
                ],
                ['%d', '%d', '%s', '%s', '%s']
            );
        }
    }

    /**
     * Get all applied link records for a post (excludes the summary row).
     *
     * @return array<object>
     */
    public function get_by_post(int $post_id): array
    {
        global $wpdb;

        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE post_id = %d AND rule_id != 0 ORDER BY id ASC",
                $post_id
            )
        );

        return is_array($results) ? $results : [];
    }

    /**
     * Get the processed content for a post from the DB fallback layer.
     *
     * Returns the full HTML with links applied, or null if not found.
     * Per ADR-002, this is used only when no external object cache is available.
     */
    public function get_processed_content(int $post_id): ?string
    {
        global $wpdb;

        $content = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT processed_content FROM {$this->table}
                 WHERE post_id = %d AND rule_id = 0
                 LIMIT 1",
                $post_id
            )
        );

        return is_string($content) ? $content : null;
    }

    /**
     * Delete all records for a given post.
     */
    public function delete_by_post(int $post_id): void
    {
        global $wpdb;

        $wpdb->delete($this->table, ['post_id' => $post_id], ['%d']);
    }

    /**
     * Delete all records associated with a given rule.
     */
    public function delete_by_rule(int $rule_id): void
    {
        global $wpdb;

        $wpdb->delete($this->table, ['rule_id' => $rule_id], ['%d']);
    }

    /**
     * Get aggregate statistics about applied links.
     *
     * @return array{total_links: int, total_posts: int, avg_links_per_post: float}
     */
    public function get_stats(): array
    {
        global $wpdb;

        $total_links = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->table} WHERE rule_id != 0"
        );

        $total_posts = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT post_id) FROM {$this->table} WHERE rule_id != 0"
        );

        $avg = $total_posts > 0 ? round($total_links / $total_posts, 2) : 0.0;

        return [
            'total_links'        => $total_links,
            'total_posts'        => $total_posts,
            'avg_links_per_post' => $avg,
        ];
    }
}
