<?php
declare(strict_types=1);

namespace LeanAutoLinks\Repositories;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Repository for the lw_exclusions table.
 *
 * Manages exclusion rules that prevent certain posts, URLs, keywords,
 * or post types from being processed by the linking engine.
 * Types: 'post', 'url', 'keyword', 'post_type'.
 */
final class ExclusionsRepository
{
    private string $table;

    public function __construct()
    {
        global $wpdb;
        $this->table = $wpdb->prefix . 'lw_exclusions';
    }

    /**
     * Get all exclusion records.
     *
     * @return array<object>
     */
    public function get_all(): array
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $results = $wpdb->get_results(
            "SELECT * FROM {$this->table} ORDER BY type ASC, id ASC"
        );

        return is_array($results) ? $results : [];
    }

    /**
     * Get exclusions filtered by type.
     *
     * @param string $type One of 'post', 'url', 'keyword', 'post_type'.
     * @return array<object>
     */
    public function get_by_type(string $type): array
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE type = %s ORDER BY id ASC",
                $type
            )
        );

        return is_array($results) ? $results : [];
    }

    /**
     * Create a new exclusion record.
     *
     * @param array{type: string, value: string} $data
     * @return int The newly inserted exclusion ID.
     */
    public function create(array $data): int
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $wpdb->insert(
            $this->table,
            [
                'type'  => $data['type'],
                'value' => $data['value'],
            ],
            ['%s', '%s']
        );

        return (int) $wpdb->insert_id;
    }

    /**
     * Delete an exclusion by ID.
     */
    public function delete(int $id): bool
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $rows = $wpdb->delete($this->table, ['id' => $id], ['%d']);

        return $rows !== false && $rows > 0;
    }

    /**
     * Check whether a given type/value combination is excluded.
     *
     * For post type exclusions: value is the post_type slug.
     * For post exclusions: value is the post ID (as string).
     * For URL exclusions: value is compared against target URLs.
     * For keyword exclusions: value is compared against rule keywords.
     */
    public function is_excluded(string $type, string $value): bool
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table} WHERE type = %s AND value = %s",
                $type,
                $value
            )
        );

        return $count > 0;
    }
}
