<?php
namespace Procyon\ImagePacker;

if (!defined('ABSPATH')) exit;

class Cli {
    public static function register(): void {
        \WP_CLI::add_command('procyon image-packer status', [__CLASS__, 'status']);
        \WP_CLI::add_command('procyon image-packer start', [__CLASS__, 'start']);
        \WP_CLI::add_command('procyon image-packer pause', [__CLASS__, 'pause']);
        \WP_CLI::add_command('procyon image-packer resume', [__CLASS__, 'resume']);
    }

    public static function status($args, $assoc_args): void {
        unset($args, $assoc_args);

        $status = Runner::status_payload();
        $job = $status['job'] ?? [];
        $binaries = $status['binaries'] ?? [];

        \WP_CLI::log(__('Job status:', PROCYON_IMAGE_PACKER_DOMAIN) . ' ' . (string) ($job['status'] ?? 'idle'));
        \WP_CLI::log(__('Progress:', PROCYON_IMAGE_PACKER_DOMAIN) . ' ' . (string) ($job['processed'] ?? 0) . '/' . (string) ($job['total'] ?? 0));
        \WP_CLI::log(__('Optimized:', PROCYON_IMAGE_PACKER_DOMAIN) . ' ' . (string) ($job['success'] ?? 0));
        \WP_CLI::log(__('Failed:', PROCYON_IMAGE_PACKER_DOMAIN) . ' ' . (string) ($job['failed'] ?? 0));
        \WP_CLI::log(__('Current file:', PROCYON_IMAGE_PACKER_DOMAIN) . ' ' . (string) ($job['current_file'] ?? ''));

        foreach ($binaries as $binary => $meta) {
            \WP_CLI::log($binary . ': ' . (!empty($meta['available']) ? (string) ($meta['path'] ?? __('available', PROCYON_IMAGE_PACKER_DOMAIN)) : __('missing', PROCYON_IMAGE_PACKER_DOMAIN)));
        }
    }

    public static function start($args, $assoc_args): void {
        unset($args);

        $mode = isset($assoc_args['mode']) ? (string) $assoc_args['mode'] : 'full';
        $response = Runner::start($mode);
        if (is_wp_error($response)) {
            \WP_CLI::error($response->get_error_message());
        }

        $job = $response['job'] ?? [];
        \WP_CLI::success(__('Started image packer job:', PROCYON_IMAGE_PACKER_DOMAIN) . ' ' . (string) ($job['job_id'] ?? ''));
    }

    public static function pause($args, $assoc_args): void {
        unset($args, $assoc_args);

        $response = Runner::pause();
        if (is_wp_error($response)) {
            \WP_CLI::error($response->get_error_message());
        }

        \WP_CLI::success(__('Pause requested.', PROCYON_IMAGE_PACKER_DOMAIN));
    }

    public static function resume($args, $assoc_args): void {
        unset($args, $assoc_args);

        $response = Runner::resume();
        if (is_wp_error($response)) {
            \WP_CLI::error($response->get_error_message());
        }

        $job = $response['job'] ?? [];
        \WP_CLI::success(__('Resumed image packer job:', PROCYON_IMAGE_PACKER_DOMAIN) . ' ' . (string) ($job['job_id'] ?? ''));
    }
}
