<?php
declare(strict_types=1);

namespace LeanAutoLinks\Api;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Base REST controller for all LeanAutoLinks API endpoints.
 *
 * Provides common namespace, permission checks, pagination helpers,
 * and standardised error response formatting.
 */
abstract class RestController extends \WP_REST_Controller
{
    /** @var string REST API namespace. */
    protected $namespace = 'leanautolinks/v1';

    /**
     * Permission callback: require manage_options capability.
     */
    public function check_permissions(\WP_REST_Request $request): bool
    {
        return current_user_can('manage_options');
    }

    /**
     * Build pagination parameters from a request.
     *
     * @return array{per_page: int, page: int, offset: int}
     */
    protected function get_pagination_params(\WP_REST_Request $request): array
    {
        $per_page = (int) $request->get_param('per_page') ?: 20;
        $per_page = min(max($per_page, 1), 100);
        $page     = max((int) $request->get_param('page'), 1);
        $offset   = ($page - 1) * $per_page;

        return [
            'per_page' => $per_page,
            'page'     => $page,
            'offset'   => $offset,
        ];
    }

    /**
     * Add pagination headers to a response.
     */
    protected function add_pagination_headers(\WP_REST_Response $response, int $total, int $per_page, int $page): \WP_REST_Response
    {
        $total_pages = (int) ceil($total / max($per_page, 1));

        $response->header('X-WP-Total', (string) $total);
        $response->header('X-WP-TotalPages', (string) $total_pages);

        return $response;
    }

    /**
     * Return a standardised error response.
     */
    protected function error(string $code, string $message, int $status = 400): \WP_Error
    {
        return new \WP_Error($code, $message, ['status' => $status]);
    }

    /**
     * Common pagination schema args for collection endpoints.
     *
     * @return array<string, array<string, mixed>>
     */
    public function get_collection_params(): array
    {
        return [
            'page'     => [
                'description' => __('Current page of the collection.', 'leanautolinks'),
                'type'        => 'integer',
                'default'     => 1,
                'minimum'     => 1,
            ],
            'per_page' => [
                'description' => __('Maximum number of items per page.', 'leanautolinks'),
                'type'        => 'integer',
                'default'     => 20,
                'minimum'     => 1,
                'maximum'     => 100,
            ],
        ];
    }
}
