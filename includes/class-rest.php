<?php
namespace Procyon\ImagePacker;

if (!defined('ABSPATH')) exit;

class Rest {
    public static function init(): void {
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
    }

    public static function register_routes(): void {
        register_rest_route('procyon-image-packer/v1', '/status', [
            'methods' => \WP_REST_Server::READABLE,
            'callback' => [__CLASS__, 'handle_status'],
            'permission_callback' => [__CLASS__, 'can_manage'],
        ]);

        register_rest_route('procyon-image-packer/v1', '/start', [
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => [__CLASS__, 'handle_start'],
            'permission_callback' => [__CLASS__, 'can_manage'],
            'args' => [
                'mode' => [
                    'type' => 'string',
                    'default' => 'full',
                ],
            ],
        ]);

        register_rest_route('procyon-image-packer/v1', '/pause', [
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => [__CLASS__, 'handle_pause'],
            'permission_callback' => [__CLASS__, 'can_manage'],
        ]);

        register_rest_route('procyon-image-packer/v1', '/resume', [
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => [__CLASS__, 'handle_resume'],
            'permission_callback' => [__CLASS__, 'can_manage'],
        ]);
    }

    public static function can_manage(\WP_REST_Request $request): bool {
        unset($request);
        return current_user_can('manage_options');
    }

    public static function handle_status(\WP_REST_Request $request) {
        unset($request);
        return Runner::status_payload();
    }

    public static function handle_start(\WP_REST_Request $request) {
        $mode = (string) $request->get_param('mode');
        $response = Runner::start($mode);
        if (is_wp_error($response)) return $response;
        return $response;
    }

    public static function handle_pause(\WP_REST_Request $request) {
        unset($request);
        $response = Runner::pause();
        if (is_wp_error($response)) return $response;
        return $response;
    }

    public static function handle_resume(\WP_REST_Request $request) {
        unset($request);
        $response = Runner::resume();
        if (is_wp_error($response)) return $response;
        return $response;
    }
}
