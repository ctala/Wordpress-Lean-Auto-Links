<?php
declare(strict_types=1);

namespace LeanAutoLinks\Api;

if (!defined('ABSPATH')) {
    exit;
}

use LeanAutoLinks\Repositories\ExclusionsRepository;

/**
 * REST controller for exclusions (lw_exclusions).
 *
 * Endpoints:
 *   GET    /exclusions      - List all exclusions
 *   POST   /exclusions      - Create exclusion
 *   DELETE /exclusions/{id} - Delete exclusion
 */
final class ExclusionsController extends RestController
{
    private ExclusionsRepository $repo;

    public function __construct(ExclusionsRepository $repo)
    {
        $this->repo = $repo;
    }

    public function register_routes(): void
    {
        register_rest_route($this->namespace, '/exclusions', [
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [$this, 'get_items'],
                'permission_callback' => [$this, 'check_permissions'],
            ],
            [
                'methods'             => \WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'create_item'],
                'permission_callback' => [$this, 'check_permissions'],
            ],
        ]);

        register_rest_route($this->namespace, '/exclusions/(?P<id>\d+)', [
            'methods'             => \WP_REST_Server::DELETABLE,
            'callback'            => [$this, 'delete_item'],
            'permission_callback' => [$this, 'check_permissions'],
        ]);
    }

    /**
     * GET /exclusions - List all exclusions.
     */
    public function get_items($request): \WP_REST_Response
    {
        $items = $this->repo->get_all();

        return new \WP_REST_Response($items, 200);
    }

    /**
     * POST /exclusions - Create a new exclusion.
     */
    public function create_item($request): \WP_REST_Response|\WP_Error
    {
        $body = $request->get_json_params();

        $valid_types = ['post', 'url', 'keyword', 'post_type'];

        if (empty($body['type']) || !in_array($body['type'], $valid_types, true)) {
            return $this->error(
                'invalid_type',
                __('Type must be one of: post, url, keyword, post_type.', 'leanautolinks')
            );
        }

        if (empty($body['value'])) {
            return $this->error('missing_value', __('Value is required.', 'leanautolinks'));
        }

        $id = $this->repo->create([
            'type'  => sanitize_text_field($body['type']),
            'value' => sanitize_text_field($body['value']),
        ]);

        if ($id === 0) {
            return $this->error('create_failed', __('Failed to create exclusion.', 'leanautolinks'), 500);
        }

        return new \WP_REST_Response([
            'id'    => $id,
            'type'  => $body['type'],
            'value' => $body['value'],
        ], 201);
    }

    /**
     * DELETE /exclusions/{id} - Delete an exclusion.
     */
    public function delete_item($request): \WP_REST_Response|\WP_Error
    {
        $id      = (int) $request->get_param('id');
        $deleted = $this->repo->delete($id);

        if (!$deleted) {
            return $this->error('not_found', __('Exclusion not found.', 'leanautolinks'), 404);
        }

        return new \WP_REST_Response(['deleted' => true, 'id' => $id], 200);
    }
}
