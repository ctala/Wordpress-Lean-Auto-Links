<?php
declare(strict_types=1);

namespace LeanAutoLinks\Repositories;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Repository for the lw_rules table.
 *
 * Handles CRUD operations on linking rules. All queries use $wpdb with
 * prepared statements. Rule types: 'internal', 'affiliate', 'entity'.
 */
final class RulesRepository
{
    private string $table;

    public function __construct()
    {
        global $wpdb;
        $this->table = $wpdb->prefix . 'lw_rules';
    }

    /**
     * Fetch all active rules ordered by priority (lower = higher priority).
     *
     * @return array<object>
     */
    public function fetch_all_active(): array
    {
        global $wpdb;

        $results = $wpdb->get_results(
            "SELECT * FROM {$this->table} WHERE is_active = 1 ORDER BY priority ASC, id ASC"
        );

        return is_array($results) ? $results : [];
    }

    /**
     * Fetch active rules filtered by type.
     *
     * @param string $type One of 'internal', 'affiliate', 'entity'.
     * @return array<object>
     */
    public function fetch_by_type(string $type): array
    {
        global $wpdb;

        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE rule_type = %s AND is_active = 1 ORDER BY priority ASC, id ASC",
                $type
            )
        );

        return is_array($results) ? $results : [];
    }

    /**
     * Find a single rule by ID.
     */
    public function find(int $id): ?object
    {
        global $wpdb;

        $result = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$this->table} WHERE id = %d", $id)
        );

        return $result ?: null;
    }

    /**
     * Create a new rule and return its ID.
     *
     * @param array<string, mixed> $data Column-value pairs.
     * @return int The newly inserted rule ID.
     */
    public function create(array $data): int
    {
        global $wpdb;

        $defaults = [
            'rule_type'      => 'internal',
            'keyword'        => '',
            'target_url'     => '',
            'entity_type'    => null,
            'entity_id'      => null,
            'priority'       => 10,
            'max_per_post'   => 1,
            'case_sensitive' => 0,
            'is_active'      => 1,
            'nofollow'       => 0,
            'sponsored'      => 0,
        ];

        $data = array_merge($defaults, $data);

        $wpdb->insert(
            $this->table,
            [
                'rule_type'      => $data['rule_type'],
                'keyword'        => $data['keyword'],
                'target_url'     => $data['target_url'],
                'entity_type'    => $data['entity_type'],
                'entity_id'      => $data['entity_id'],
                'priority'       => (int) $data['priority'],
                'max_per_post'   => (int) $data['max_per_post'],
                'case_sensitive' => (int) $data['case_sensitive'],
                'is_active'      => (int) $data['is_active'],
                'nofollow'       => (int) $data['nofollow'],
                'sponsored'      => (int) $data['sponsored'],
            ],
            ['%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%d', '%d']
        );

        return (int) $wpdb->insert_id;
    }

    /**
     * Update an existing rule.
     *
     * @param int                  $id   Rule ID.
     * @param array<string, mixed> $data Column-value pairs to update.
     * @return bool True on success.
     */
    public function update(int $id, array $data): bool
    {
        global $wpdb;

        $allowed = [
            'rule_type', 'keyword', 'target_url', 'entity_type', 'entity_id',
            'priority', 'max_per_post', 'case_sensitive', 'is_active',
            'nofollow', 'sponsored',
        ];

        $update_data   = [];
        $update_format = [];

        foreach ($allowed as $col) {
            if (!array_key_exists($col, $data)) {
                continue;
            }

            $update_data[$col] = $data[$col];

            if (in_array($col, ['rule_type', 'keyword', 'target_url', 'entity_type'], true)) {
                $update_format[] = '%s';
            } else {
                $update_format[] = '%d';
            }
        }

        if (empty($update_data)) {
            return false;
        }

        $rows = $wpdb->update(
            $this->table,
            $update_data,
            ['id' => $id],
            $update_format,
            ['%d']
        );

        return $rows !== false;
    }

    /**
     * Delete a rule by ID.
     */
    public function delete(int $id): bool
    {
        global $wpdb;

        $rows = $wpdb->delete($this->table, ['id' => $id], ['%d']);

        return $rows !== false && $rows > 0;
    }

    /**
     * Toggle the is_active status of a rule.
     */
    public function toggle(int $id): bool
    {
        global $wpdb;

        $rows = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$this->table} SET is_active = 1 - is_active WHERE id = %d",
                $id
            )
        );

        return $rows !== false && $rows > 0;
    }
}
