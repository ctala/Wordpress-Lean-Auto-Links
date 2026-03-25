<?php
declare(strict_types=1);

namespace LeanAutoLinks\Api;

if (!defined('ABSPATH')) {
    exit;
}

use LeanAutoLinks\Hooks\RuleChangeHandler;
use LeanAutoLinks\Repositories\RulesRepository;

/**
 * REST controller for linking rules (lw_rules).
 *
 * Endpoints:
 *   GET    /rules           - List with filtering + pagination
 *   POST   /rules           - Create
 *   GET    /rules/{id}      - Single
 *   PUT    /rules/{id}      - Update
 *   DELETE /rules/{id}      - Delete
 *   PATCH  /rules/{id}/toggle - Toggle active state
 *   POST   /rules/import    - Bulk import from JSON array
 */
final class RulesController extends RestController
{
    private RulesRepository $repo;
    private RuleChangeHandler $handler;

    public function __construct(RulesRepository $repo, RuleChangeHandler $handler)
    {
        $this->repo    = $repo;
        $this->handler = $handler;
    }

    public function register_routes(): void
    {
        // Collection: list + create.
        register_rest_route($this->namespace, '/rules', [
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [$this, 'get_items'],
                'permission_callback' => [$this, 'check_permissions'],
                'args'                => array_merge($this->get_collection_params(), [
                    'rule_type' => [
                        'type'     => 'string',
                        'enum'     => ['internal', 'affiliate', 'entity'],
                        'default'  => '',
                    ],
                    'is_active' => [
                        'type'    => 'string',
                        'default' => '',
                    ],
                    'entity_type' => [
                        'type'    => 'string',
                        'default' => '',
                    ],
                    'search' => [
                        'type'    => 'string',
                        'default' => '',
                    ],
                ]),
            ],
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'create_item'],
                'permission_callback' => [$this, 'check_permissions'],
            ],
        ]);

        // Single: get, update, delete.
        register_rest_route($this->namespace, '/rules/(?P<id>\d+)', [
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [$this, 'get_item'],
                'permission_callback' => [$this, 'check_permissions'],
            ],
            [
                'methods'             => 'PUT',
                'callback'            => [$this, 'update_item'],
                'permission_callback' => [$this, 'check_permissions'],
            ],
            [
                'methods'             => \WP_REST_Server::DELETABLE,
                'callback'            => [$this, 'delete_item'],
                'permission_callback' => [$this, 'check_permissions'],
            ],
        ]);

        // Toggle active status.
        register_rest_route($this->namespace, '/rules/(?P<id>\d+)/toggle', [
            'methods'             => 'PATCH',
            'callback'            => [$this, 'toggle_item'],
            'permission_callback' => [$this, 'check_permissions'],
        ]);

        // Bulk import.
        register_rest_route($this->namespace, '/rules/import', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'import_items'],
            'permission_callback' => [$this, 'check_permissions'],
        ]);
    }

    /**
     * GET /rules - List rules with filtering and pagination.
     */
    public function get_items($request): \WP_REST_Response
    {
        global $wpdb;

        $pagination = $this->get_pagination_params($request);
        $table      = $wpdb->prefix . 'lw_rules';

        $where  = '1=1';
        $params = [];

        $rule_type = $request->get_param('rule_type');
        if (!empty($rule_type)) {
            $where   .= ' AND rule_type = %s';
            $params[] = $rule_type;
        }

        $is_active = $request->get_param('is_active');
        if ($is_active !== '' && $is_active !== null) {
            $where   .= ' AND is_active = %d';
            $params[] = (int) $is_active;
        }

        $entity_type = $request->get_param('entity_type');
        if (!empty($entity_type)) {
            $where   .= ' AND entity_type = %s';
            $params[] = $entity_type;
        }

        $search = $request->get_param('search');
        if (!empty($search)) {
            $where   .= ' AND (keyword LIKE %s OR target_url LIKE %s)';
            $like     = '%' . $wpdb->esc_like($search) . '%';
            $params[] = $like;
            $params[] = $like;
        }

        // Count total.
        $count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where}";
        if (empty($params)) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Safe: table name from $wpdb->prefix.
            $total = (int) $wpdb->get_var($count_sql);
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Safe: table name from $wpdb->prefix, values via prepare().
            $total = (int) $wpdb->get_var($wpdb->prepare($count_sql, ...$params));
        }

        // Fetch paginated results.
        $query_params   = $params;
        $query_params[] = $pagination['per_page'];
        $query_params[] = $pagination['offset'];

        $sql = "SELECT * FROM {$table} WHERE {$where} ORDER BY priority ASC, id ASC LIMIT %d OFFSET %d";
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Safe: table name from $wpdb->prefix, values via prepare().
        $results = $wpdb->get_results($wpdb->prepare($sql, ...$query_params));

        $response = new \WP_REST_Response($results ?: [], 200);

        return $this->add_pagination_headers($response, $total, $pagination['per_page'], $pagination['page']);
    }

    /**
     * GET /rules/{id} - Get single rule.
     */
    public function get_item($request): \WP_REST_Response|\WP_Error
    {
        $id   = (int) $request->get_param('id');
        $rule = $this->repo->find($id);

        if (!$rule) {
            return $this->error('not_found', __('Rule not found.', 'leanautolinks'), 404);
        }

        return new \WP_REST_Response($rule, 200);
    }

    /**
     * POST /rules - Create a new rule.
     */
    public function create_item($request): \WP_REST_Response|\WP_Error
    {
        $body = $request->get_json_params();

        // Validation.
        if (empty($body['keyword'])) {
            return $this->error('missing_keyword', __('Keyword is required.', 'leanautolinks'));
        }
        if (empty($body['target_url'])) {
            return $this->error('missing_target_url', __('Target URL is required.', 'leanautolinks'));
        }

        $valid_types = ['internal', 'affiliate', 'entity'];
        if (!empty($body['rule_type']) && !in_array($body['rule_type'], $valid_types, true)) {
            return $this->error('invalid_rule_type', __('Rule type must be internal, affiliate, or entity.', 'leanautolinks'));
        }

        // Duplicate keyword check.
        $case_sensitive = !empty($body['case_sensitive']);
        $existing = $this->repo->find_by_keyword(sanitize_text_field($body['keyword']), $case_sensitive);
        if ($existing) {
            return $this->error(
                'duplicate_keyword',
                sprintf(
                    /* translators: %s: the duplicate keyword */
                    __('The keyword "%s" is already used by another rule.', 'leanautolinks'),
                    sanitize_text_field($body['keyword'])
                ),
                409
            );
        }

        $data = [
            'keyword'        => sanitize_text_field($body['keyword']),
            'target_url'     => esc_url_raw($body['target_url']),
            'rule_type'      => sanitize_text_field($body['rule_type'] ?? 'internal'),
            'entity_type'    => isset($body['entity_type']) ? sanitize_text_field($body['entity_type']) : null,
            'entity_id'      => isset($body['entity_id']) ? (int) $body['entity_id'] : null,
            'priority'       => (int) ($body['priority'] ?? 10),
            'max_per_post'   => (int) ($body['max_per_post'] ?? 1),
            'case_sensitive' => (int) ($body['case_sensitive'] ?? 0),
            'is_active'      => (int) ($body['is_active'] ?? 1),
            'nofollow'       => (int) ($body['nofollow'] ?? 0),
            'sponsored'      => (int) ($body['sponsored'] ?? 0),
        ];

        $id = $this->repo->create($data);

        if ($id === 0) {
            return $this->error('create_failed', __('Failed to create rule.', 'leanautolinks'), 500);
        }

        $this->handler->handle($id, 'created');

        $rule = $this->repo->find($id);

        return new \WP_REST_Response($rule, 201);
    }

    /**
     * PUT /rules/{id} - Update a rule.
     */
    public function update_item($request): \WP_REST_Response|\WP_Error
    {
        $id   = (int) $request->get_param('id');
        $rule = $this->repo->find($id);

        if (!$rule) {
            return $this->error('not_found', __('Rule not found.', 'leanautolinks'), 404);
        }

        $body = $request->get_json_params();
        $data = [];

        if (isset($body['keyword'])) {
            $case_sensitive = isset($body['case_sensitive']) ? (bool) $body['case_sensitive'] : (bool) $rule->case_sensitive;
            $existing = $this->repo->find_by_keyword(sanitize_text_field($body['keyword']), $case_sensitive, $id);
            if ($existing) {
                return $this->error(
                    'duplicate_keyword',
                    sprintf(
                        /* translators: %s: the duplicate keyword */
                        __('The keyword "%s" is already used by another rule.', 'leanautolinks'),
                        sanitize_text_field($body['keyword'])
                    ),
                    409
                );
            }
            $data['keyword'] = sanitize_text_field($body['keyword']);
        }
        if (isset($body['target_url'])) {
            $data['target_url'] = esc_url_raw($body['target_url']);
        }
        if (isset($body['rule_type'])) {
            $valid_types = ['internal', 'affiliate', 'entity'];
            if (!in_array($body['rule_type'], $valid_types, true)) {
                return $this->error('invalid_rule_type', __('Rule type must be internal, affiliate, or entity.', 'leanautolinks'));
            }
            $data['rule_type'] = $body['rule_type'];
        }
        if (isset($body['entity_type'])) {
            $data['entity_type'] = sanitize_text_field($body['entity_type']);
        }
        if (isset($body['entity_id'])) {
            $data['entity_id'] = (int) $body['entity_id'];
        }
        if (isset($body['priority'])) {
            $data['priority'] = (int) $body['priority'];
        }
        if (isset($body['max_per_post'])) {
            $data['max_per_post'] = (int) $body['max_per_post'];
        }
        if (isset($body['case_sensitive'])) {
            $data['case_sensitive'] = (int) $body['case_sensitive'];
        }
        if (isset($body['is_active'])) {
            $data['is_active'] = (int) $body['is_active'];
        }
        if (isset($body['nofollow'])) {
            $data['nofollow'] = (int) $body['nofollow'];
        }
        if (isset($body['sponsored'])) {
            $data['sponsored'] = (int) $body['sponsored'];
        }

        if (empty($data)) {
            return $this->error('no_data', __('No fields to update.', 'leanautolinks'));
        }

        $this->repo->update($id, $data);
        $this->handler->handle($id, 'updated');

        $updated_rule = $this->repo->find($id);

        return new \WP_REST_Response($updated_rule, 200);
    }

    /**
     * DELETE /rules/{id} - Delete a rule.
     */
    public function delete_item($request): \WP_REST_Response|\WP_Error
    {
        $id   = (int) $request->get_param('id');
        $rule = $this->repo->find($id);

        if (!$rule) {
            return $this->error('not_found', __('Rule not found.', 'leanautolinks'), 404);
        }

        $this->handler->handle($id, 'deleted');
        $this->repo->delete($id);

        return new \WP_REST_Response(['deleted' => true, 'id' => $id], 200);
    }

    /**
     * PATCH /rules/{id}/toggle - Toggle active/inactive.
     */
    public function toggle_item(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $id   = (int) $request->get_param('id');
        $rule = $this->repo->find($id);

        if (!$rule) {
            return $this->error('not_found', __('Rule not found.', 'leanautolinks'), 404);
        }

        $this->repo->toggle($id);
        $this->handler->handle($id, 'toggled');

        $updated = $this->repo->find($id);

        return new \WP_REST_Response($updated, 200);
    }

    /**
     * POST /rules/import - Bulk import rules from JSON array.
     */
    public function import_items(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $body  = $request->get_json_params();
        $rules = $body['rules'] ?? $body;

        if (!is_array($rules) || empty($rules)) {
            return $this->error('invalid_data', __('Expected a JSON array of rules.', 'leanautolinks'));
        }

        $created = 0;
        $errors  = [];

        foreach ($rules as $index => $rule_data) {
            if (empty($rule_data['keyword']) || empty($rule_data['target_url'])) {
                $errors[] = sprintf(
                    /* translators: %d: index of the rule in the import array */
                    __('Rule at index %d missing keyword or target_url.', 'leanautolinks'),
                    $index
                );
                continue;
            }

            $clean_keyword  = sanitize_text_field($rule_data['keyword']);
            $case_sensitive = !empty($rule_data['case_sensitive']);

            // Skip duplicate keywords.
            $existing = $this->repo->find_by_keyword($clean_keyword, $case_sensitive);
            if ($existing) {
                $errors[] = sprintf(
                    /* translators: 1: index, 2: keyword */
                    __('Rule at index %1$d skipped: keyword "%2$s" already exists.', 'leanautolinks'),
                    $index,
                    $clean_keyword
                );
                continue;
            }

            $data = [
                'keyword'        => $clean_keyword,
                'target_url'     => esc_url_raw($rule_data['target_url']),
                'rule_type'      => sanitize_text_field($rule_data['rule_type'] ?? 'internal'),
                'entity_type'    => isset($rule_data['entity_type']) ? sanitize_text_field($rule_data['entity_type']) : null,
                'entity_id'      => isset($rule_data['entity_id']) ? (int) $rule_data['entity_id'] : null,
                'priority'       => (int) ($rule_data['priority'] ?? 10),
                'max_per_post'   => (int) ($rule_data['max_per_post'] ?? 1),
                'case_sensitive' => (int) ($rule_data['case_sensitive'] ?? 0),
                'is_active'      => (int) ($rule_data['is_active'] ?? 1),
                'nofollow'       => (int) ($rule_data['nofollow'] ?? 0),
                'sponsored'      => (int) ($rule_data['sponsored'] ?? 0),
            ];

            $id = $this->repo->create($data);
            if ($id > 0) {
                $this->handler->handle($id, 'created');
                $created++;
            }
        }

        return new \WP_REST_Response([
            'imported' => $created,
            'errors'   => $errors,
            'total'    => count($rules),
        ], 200);
    }
}
