<?php
namespace Procyon\ImagePacker;

if (!defined('ABSPATH')) exit;

class Runner {
    public const DIRTY_CRON_HOOK = 'procyon_image_packer_run_dirty_queue';

    public static function init_hooks(): void {
        add_filter('wp_generate_attachment_metadata', [__CLASS__, 'handle_generated_attachment_metadata'], 20, 2);
        add_action('delete_attachment', [__CLASS__, 'handle_attachment_delete'], 10, 1);
        add_action(self::DIRTY_CRON_HOOK, [__CLASS__, 'handle_dirty_queue']);
    }

    public static function handle_generated_attachment_metadata($metadata, int $attachment_id) {
        $mime = get_post_mime_type($attachment_id);
        if (!in_array($mime, ['image/jpeg', 'image/png'], true)) {
            return $metadata;
        }

        Registry::mark_attachment_dirty($attachment_id);

        $settings = Settings::settings();
        if (!empty($settings['auto_queue_new_uploads']) && !self::is_running()) {
            if (!wp_next_scheduled(self::DIRTY_CRON_HOOK)) {
                wp_schedule_single_event(time() + MINUTE_IN_SECONDS, self::DIRTY_CRON_HOOK);
            }
        }

        return $metadata;
    }

    public static function clear_scheduled_dirty_run(): void {
        $ts = wp_next_scheduled(self::DIRTY_CRON_HOOK);
        while ($ts) {
            wp_unschedule_event($ts, self::DIRTY_CRON_HOOK);
            $ts = wp_next_scheduled(self::DIRTY_CRON_HOOK);
        }
    }

    public static function handle_attachment_delete(int $attachment_id): void {
        if ($attachment_id <= 0) return;

        $targets = Manifest::attachment_cleanup_targets($attachment_id);
        $relative_paths = [];

        foreach ($targets as $target) {
            $relative_path = (string) ($target['relative_path'] ?? '');
            if ($relative_path !== '') {
                $relative_paths[] = $relative_path;
            }

            foreach (['webp_path', 'avif_path'] as $field) {
                $path = isset($target[$field]) ? wp_normalize_path((string) $target[$field]) : '';
                if ($path === '' || !is_file($path)) continue;

                @unlink($path);
            }
        }

        Registry::remove_attachment_records($attachment_id, $relative_paths);
    }

    public static function handle_dirty_queue(): void {
        if (self::is_running()) return;
        $status = self::environment_issues(Settings::settings(), Environment::detect_binaries());
        if (!empty($status['errors'])) return;

        $job = self::start('dirty');
        if (is_wp_error($job)) {
            return;
        }
    }

    public static function start(string $mode = 'full') {
        $current = Environment::read_json_file(Environment::job_file());
        if (!empty($current['status']) && $current['status'] === 'running' && self::lock_exists()) {
            return new \WP_Error('job_running', __('An image packing job is already running.', PROCYON_IMAGE_PACKER_DOMAIN));
        }

        if ($current && empty($current['results_applied'])) {
            Registry::sync_job_results($current);
        }

        $settings = Settings::settings();
        $binaries = Environment::detect_binaries();
        $issues = self::environment_issues($settings, $binaries);
        if (!empty($issues['errors'])) {
            return new \WP_Error('environment_invalid', implode(' ', $issues['errors']));
        }

        $job = Manifest::prepare($mode);
        if (is_wp_error($job)) {
            return $job;
        }

        if ((int) ($job['total'] ?? 0) === 0) {
            $job['results_applied'] = true;
            Environment::write_json_file(Environment::job_file(), $job);
            return self::status_payload();
        }

        @unlink(Environment::pause_flag_file());

        $launched = Environment::start_background_runner();
        if (is_wp_error($launched)) {
            $job['status'] = 'failed';
            $job['updated_at'] = gmdate('c');
            $job['notes'][] = $launched->get_error_message();
            Environment::write_json_file(Environment::job_file(), $job);
            return $launched;
        }

        $job['status'] = 'running';
        $job['pid'] = (string) ($launched['pid'] ?? '');
        $job['started_at'] = $job['started_at'] ?: gmdate('c');
        $job['updated_at'] = gmdate('c');
        Environment::write_json_file(Environment::job_file(), $job);
        Environment::write_key_value_file(Environment::runtime_file(), [
            'status' => 'running',
            'total' => (int) ($job['total'] ?? 0),
            'processed' => (int) ($job['processed'] ?? 0),
            'success' => (int) ($job['success'] ?? 0),
            'failed' => (int) ($job['failed'] ?? 0),
            'current_file' => '',
            'pid' => $job['pid'],
            'updated_at' => $job['updated_at'],
        ]);

        return self::status_payload();
    }

    public static function pause() {
        $job = Environment::read_json_file(Environment::job_file());
        if (!$job) {
            return new \WP_Error('no_job', __('There is no image packing job to pause.', PROCYON_IMAGE_PACKER_DOMAIN));
        }

        if (in_array((string) ($job['status'] ?? ''), ['paused', 'completed'], true)) {
            return self::status_payload();
        }

        file_put_contents(Environment::pause_flag_file(), "1\n");
        if (!self::lock_exists()) {
            $job['status'] = 'paused';
            Environment::write_key_value_file(Environment::runtime_file(), [
                'status' => 'paused',
                'total' => (int) ($job['total'] ?? 0),
                'processed' => (int) ($job['processed'] ?? 0),
                'success' => (int) ($job['success'] ?? 0),
                'failed' => (int) ($job['failed'] ?? 0),
                'current_file' => (string) ($job['current_file'] ?? ''),
                'pid' => (string) ($job['pid'] ?? ''),
                'updated_at' => gmdate('c'),
            ]);
        }
        $job['updated_at'] = gmdate('c');
        Environment::write_json_file(Environment::job_file(), $job);

        return self::status_payload();
    }

    public static function resume() {
        $job = Environment::read_json_file(Environment::job_file());
        if (!$job) {
            return new \WP_Error('no_job', __('There is no prepared image packing job to resume.', PROCYON_IMAGE_PACKER_DOMAIN));
        }

        if (($job['status'] ?? '') === 'running' && self::lock_exists()) {
            return self::status_payload();
        }

        if ((int) ($job['total'] ?? 0) === 0 || (int) ($job['processed'] ?? 0) >= (int) ($job['total'] ?? 0)) {
            return new \WP_Error('nothing_to_resume', __('The current job does not have pending files to resume.', PROCYON_IMAGE_PACKER_DOMAIN));
        }

        $issues = self::environment_issues(Settings::settings(), Environment::detect_binaries());
        if (!empty($issues['errors'])) {
            return new \WP_Error('environment_invalid', implode(' ', $issues['errors']));
        }

        @unlink(Environment::pause_flag_file());

        $launched = Environment::start_background_runner();
        if (is_wp_error($launched)) {
            return $launched;
        }

        $job['status'] = 'running';
        $job['pid'] = (string) ($launched['pid'] ?? '');
        $job['results_applied'] = false;
        $job['updated_at'] = gmdate('c');
        Environment::write_json_file(Environment::job_file(), $job);
        Environment::write_key_value_file(Environment::runtime_file(), [
            'status' => 'running',
            'total' => (int) ($job['total'] ?? 0),
            'processed' => (int) ($job['processed'] ?? 0),
            'success' => (int) ($job['success'] ?? 0),
            'failed' => (int) ($job['failed'] ?? 0),
            'current_file' => (string) ($job['current_file'] ?? ''),
            'pid' => $job['pid'],
            'updated_at' => $job['updated_at'],
        ]);

        return self::status_payload();
    }

    public static function status_payload(): array {
        $job = Environment::read_json_file(Environment::job_file());
        if (!$job) {
            $job = self::empty_job();
        }

        $runtime = Environment::read_key_value_file(Environment::runtime_file());
        $job = self::merge_runtime($job, $runtime);
        $job = self::normalize_job_state($job);

        if (
            !empty($job['job_id'])
            && in_array((string) ($job['status'] ?? ''), ['paused', 'completed', 'failed'], true)
            && empty($job['results_applied'])
        ) {
            $job = Registry::sync_job_results($job);
            $job = self::merge_runtime($job, Environment::read_key_value_file(Environment::runtime_file()));
        }

        $settings = Settings::settings();
        $binaries = Environment::detect_binaries();
        $issues = self::environment_issues($settings, $binaries);
        $total = (int) ($job['total'] ?? 0);
        $processed = (int) ($job['processed'] ?? 0);
        $success = (int) ($job['success'] ?? 0);

        $job['progress_percent'] = $total > 0 ? round(($processed / $total) * 100, 2) : 0;
        $job['optimized_percent'] = $total > 0 ? round(($success / $total) * 100, 2) : 0;

        return [
            'job' => $job,
            'settings' => $settings,
            'binaries' => $binaries,
            'issues' => $issues,
            'can_start' => ($job['status'] ?? 'idle') !== 'running' && empty($issues['errors']),
            'can_pause' => ($job['status'] ?? '') === 'running',
            'can_resume' => in_array((string) ($job['status'] ?? ''), ['paused', 'failed'], true)
                && $processed < $total
                && $total > 0
                && empty($issues['errors']),
        ];
    }

    private static function empty_job(): array {
        return [
            'schema' => 1,
            'job_id' => '',
            'status' => 'idle',
            'mode' => 'full',
            'created_at' => '',
            'updated_at' => '',
            'started_at' => '',
            'finished_at' => '',
            'settings_hash' => '',
            'total' => 0,
            'processed' => 0,
            'success' => 0,
            'failed' => 0,
            'attachments_scanned' => 0,
            'attachments_queued' => 0,
            'current_file' => '',
            'pid' => '',
            'results_applied' => true,
            'notes' => [],
        ];
    }

    private static function merge_runtime(array $job, array $runtime): array {
        if (!$runtime) return $job;

        foreach (['status', 'current_file', 'pid', 'updated_at'] as $field) {
            if (isset($runtime[$field])) {
                $job[$field] = (string) $runtime[$field];
            }
        }

        foreach (['total', 'processed', 'success', 'failed'] as $field) {
            if (isset($runtime[$field])) {
                $job[$field] = (int) $runtime[$field];
            }
        }

        return $job;
    }

    private static function normalize_job_state(array $job): array {
        $status = (string) ($job['status'] ?? 'idle');
        $lock_exists = self::lock_exists();
        $changed = false;
        $updated_at = !empty($job['updated_at']) ? strtotime((string) $job['updated_at']) : 0;

        if ($status === 'running' && !$lock_exists) {
            if ($updated_at && (time() - $updated_at) <= 10) {
                return $job;
            }

            if ((int) ($job['processed'] ?? 0) >= (int) ($job['total'] ?? 0) && (int) ($job['total'] ?? 0) > 0) {
                $job['status'] = 'completed';
                $job['finished_at'] = $job['finished_at'] ?: gmdate('c');
                $changed = true;
            } elseif (is_file(Environment::pause_flag_file())) {
                $job['status'] = 'paused';
                $changed = true;
            } elseif (!empty($job['job_id'])) {
                $job['status'] = 'failed';
                $changed = true;
            }
        }

        if ($status === 'queued' && (int) ($job['total'] ?? 0) === 0) {
            $job['status'] = 'completed';
            $job['finished_at'] = $job['finished_at'] ?: gmdate('c');
            $changed = true;
        }

        if ($changed) {
            $job['updated_at'] = gmdate('c');
            Environment::write_json_file(Environment::job_file(), $job);
        }

        return $job;
    }

    private static function environment_issues(array $settings, array $binaries): array {
        $errors = [];
        $warnings = [];

        if (!Environment::launcher_available()) {
            $errors[] = __('PHP on this server does not expose exec/shell_exec/popen, so the shell runner cannot be launched from WordPress.', PROCYON_IMAGE_PACKER_DOMAIN);
        }

        if (!empty($settings['generate_webp']) && empty($binaries['cwebp']['available'])) {
            $errors[] = __('WebP generation is enabled, but `cwebp` is missing.', PROCYON_IMAGE_PACKER_DOMAIN);
        }

        if (!empty($settings['generate_avif']) && empty($binaries['avifenc']['available'])) {
            $errors[] = __('AVIF generation is enabled, but `avifenc` is missing.', PROCYON_IMAGE_PACKER_DOMAIN);
        }

        if (!empty($settings['optimize_originals']) && empty($binaries['jpegoptim']['available'])) {
            $errors[] = __('Original optimization is enabled, but `jpegoptim` is missing.', PROCYON_IMAGE_PACKER_DOMAIN);
        }

        if (!empty($settings['optimize_originals']) && empty($binaries['pngquant']['available'])) {
            $errors[] = __('Original optimization is enabled, but `pngquant` is missing.', PROCYON_IMAGE_PACKER_DOMAIN);
        }

        return [
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    private static function is_running(): bool {
        $job = Environment::read_json_file(Environment::job_file());
        return !empty($job['status']) && $job['status'] === 'running' && self::lock_exists();
    }

    private static function lock_exists(): bool {
        return is_file(Environment::lock_file());
    }
}
