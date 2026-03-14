<?php
namespace Procyon\ImagePacker;

if (!defined('ABSPATH')) exit;

class Manifest {
    private const QUERY_BATCH = 200;

    public static function prepare(string $mode = 'full'): array {
        if (!Environment::ensure_storage()) {
            return new \WP_Error('storage_unavailable', __('Cannot create the image packer storage directory.', PROCYON_IMAGE_PACKER_DOMAIN));
        }

        if (!in_array($mode, ['full', 'dirty'], true)) {
            $mode = 'full';
        }

        Environment::clear_runtime_files();

        $settings = Settings::settings();
        $binaries = Environment::detect_binaries();
        $settings_hash = Environment::generation_settings_hash($settings);
        $registry = Registry::load();
        $permission_issues = [];

        $lines = [];
        $attachments_scanned = 0;
        $attachments_queued = [];
        $page = 1;
        $max_pages = 1;

        do {
            $query = new \WP_Query(self::query_args($mode, $page));
            $max_pages = max(1, (int) $query->max_num_pages);

            foreach ($query->posts as $attachment_id) {
                $attachment_id = (int) $attachment_id;
                if ($attachment_id <= 0) continue;

                $attachments_scanned++;
                $files = self::attachment_files($attachment_id, $settings);
                if (!$files) {
                    continue;
                }

                $queued_for_attachment = 0;
                foreach ($files as $file_entry) {
                    self::collect_write_issues($file_entry, $settings, $permission_issues);

                    $record = $registry[$file_entry['relative_path']] ?? [];
                    if (Registry::is_current($record, $file_entry, $settings, $settings_hash)) {
                        continue;
                    }

                    $lines[] = self::manifest_line($file_entry, $settings);
                    $queued_for_attachment++;
                }

                if ($queued_for_attachment > 0) {
                    $attachments_queued[$attachment_id] = true;
                } else {
                    Registry::mark_attachment_clean($attachment_id, $settings_hash, count($files));
                }
            }

            $page++;
        } while ($page <= $max_pages);

        if ($permission_issues) {
            return self::permission_error($permission_issues);
        }

        $job = [
            'schema' => 1,
            'job_id' => gmdate('YmdHis') . '-' . wp_generate_password(8, false, false),
            'status' => count($lines) > 0 ? 'queued' : 'completed',
            'mode' => $mode,
            'created_at' => gmdate('c'),
            'updated_at' => gmdate('c'),
            'started_at' => '',
            'finished_at' => count($lines) > 0 ? '' : gmdate('c'),
            'settings' => $settings,
            'settings_hash' => $settings_hash,
            'binaries' => $binaries,
            'total' => count($lines),
            'processed' => 0,
            'success' => 0,
            'failed' => 0,
            'attachments_scanned' => $attachments_scanned,
            'attachments_queued' => count($attachments_queued),
            'current_file' => '',
            'pid' => '',
            'results_applied' => false,
            'notes' => count($lines) > 0 ? [] : [__('No pending JPEG/PNG files requiring optimization were found.', PROCYON_IMAGE_PACKER_DOMAIN)],
        ];

        $manifest_payload = implode("\n", $lines);
        file_put_contents(Environment::manifest_file(), $manifest_payload . ($manifest_payload === '' ? '' : "\n"));
        Environment::write_json_file(Environment::job_file(), $job);
        Environment::write_key_value_file(Environment::runtime_file(), [
            'status' => $job['status'],
            'total' => $job['total'],
            'processed' => 0,
            'success' => 0,
            'failed' => 0,
            'current_file' => '',
            'pid' => '',
            'updated_at' => $job['updated_at'],
        ]);
        Environment::write_env_file(Environment::job_env_file(), Environment::build_job_env($settings, $binaries, $job));

        return $job;
    }

    public static function load_manifest_entries(): array {
        $path = Environment::manifest_file();
        if (!is_file($path)) return [];

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) return [];

        $out = [];
        foreach ($lines as $line) {
            $parts = explode("\t", (string) $line);
            if (count($parts) < 10) continue;

            $out[] = [
                'attachment_id' => (int) $parts[0],
                'relative_path' => $parts[1],
                'source_mime' => $parts[2],
                'absolute_path' => $parts[3],
                'signature' => $parts[4],
                'needs_original' => $parts[5] === '1',
                'needs_webp' => $parts[6] === '1',
                'webp_path' => $parts[7],
                'needs_avif' => $parts[8] === '1',
                'avif_path' => $parts[9],
            ];
        }

        return $out;
    }

    public static function attachment_cleanup_targets(int $attachment_id): array {
        $original_path = get_attached_file($attachment_id);
        if (!is_string($original_path) || $original_path === '' || !is_file($original_path)) {
            return [];
        }

        $original_mime = get_post_mime_type($attachment_id);
        if (!in_array($original_mime, ['image/jpeg', 'image/png'], true)) {
            return [];
        }

        $metadata = wp_get_attachment_metadata($attachment_id);
        $seen = [];
        $targets = [];

        self::append_cleanup_target($targets, $seen, $attachment_id, $original_path, $original_mime);

        $sizes = is_array($metadata) && !empty($metadata['sizes']) && is_array($metadata['sizes']) ? $metadata['sizes'] : [];
        foreach ($sizes as $size_meta) {
            if (!is_array($size_meta) || empty($size_meta['file'])) continue;

            $subsize_path = wp_normalize_path(dirname($original_path) . '/' . ltrim((string) $size_meta['file'], '/'));
            $subsize_mime = self::mime_for_path($subsize_path);
            if (!in_array($subsize_mime, ['image/jpeg', 'image/png'], true)) continue;

            self::append_cleanup_target($targets, $seen, $attachment_id, $subsize_path, $subsize_mime);
        }

        return $targets;
    }

    private static function query_args(string $mode, int $page): array {
        $args = [
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'post_mime_type' => ['image/jpeg', 'image/png'],
            'posts_per_page' => self::QUERY_BATCH,
            'paged' => max(1, $page),
            'fields' => 'ids',
            'orderby' => 'ID',
            'order' => 'ASC',
            'no_found_rows' => false,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ];

        if ($mode === 'dirty') {
            $args['meta_query'] = [
                [
                    'key' => Registry::DIRTY_META,
                    'value' => '1',
                ],
            ];
        }

        return $args;
    }

    private static function attachment_files(int $attachment_id, array $settings): array {
        self::maybe_require_image_functions();

        if (!empty($settings['repair_missing_subsizes'])) {
            self::repair_missing_subsizes($attachment_id);
        }

        $original_path = get_attached_file($attachment_id);
        if (!is_string($original_path) || $original_path === '' || !is_file($original_path)) {
            return [];
        }

        $original_mime = get_post_mime_type($attachment_id);
        if (!in_array($original_mime, ['image/jpeg', 'image/png'], true)) {
            return [];
        }

        $metadata = wp_get_attachment_metadata($attachment_id);
        $seen = [];
        $files = [];

        self::append_file_entry($files, $seen, $attachment_id, $original_path, $original_mime, $settings);

        $sizes = is_array($metadata) && !empty($metadata['sizes']) && is_array($metadata['sizes']) ? $metadata['sizes'] : [];
        foreach ($sizes as $size_meta) {
            if (!is_array($size_meta) || empty($size_meta['file'])) continue;

            $subsize_path = wp_normalize_path(dirname($original_path) . '/' . ltrim((string) $size_meta['file'], '/'));
            $subsize_mime = self::mime_for_path($subsize_path);
            if (!in_array($subsize_mime, ['image/jpeg', 'image/png'], true)) continue;

            self::append_file_entry($files, $seen, $attachment_id, $subsize_path, $subsize_mime, $settings);
        }

        return $files;
    }

    private static function append_file_entry(array &$files, array &$seen, int $attachment_id, string $absolute_path, string $mime, array $settings): void {
        $absolute_path = wp_normalize_path($absolute_path);
        if (!is_file($absolute_path)) return;

        $relative_path = Environment::relative_upload_path($absolute_path);
        if ($relative_path === '' || isset($seen[$relative_path])) return;

        $seen[$relative_path] = true;

        $needs_original = !empty($settings['optimize_originals']) ? 1 : 0;
        $needs_webp = !empty($settings['generate_webp']) ? 1 : 0;
        $needs_avif = !empty($settings['generate_avif']) ? 1 : 0;
        if ($needs_original === 0 && $needs_webp === 0 && $needs_avif === 0) {
            return;
        }

        $files[] = [
            'attachment_id' => $attachment_id,
            'relative_path' => $relative_path,
            'absolute_path' => $absolute_path,
            'source_mime' => $mime,
            'signature' => self::signature_for_path($absolute_path),
            'needs_original' => $needs_original,
            'needs_webp' => $needs_webp,
            'needs_avif' => $needs_avif,
            'webp_path' => self::sibling_path($absolute_path, 'webp'),
            'avif_path' => self::sibling_path($absolute_path, 'avif'),
        ];
    }

    private static function append_cleanup_target(array &$targets, array &$seen, int $attachment_id, string $absolute_path, string $mime): void {
        $absolute_path = wp_normalize_path($absolute_path);
        if (!is_file($absolute_path)) return;

        $relative_path = Environment::relative_upload_path($absolute_path);
        if ($relative_path === '' || isset($seen[$relative_path])) return;

        $seen[$relative_path] = true;

        $targets[] = [
            'attachment_id' => $attachment_id,
            'relative_path' => $relative_path,
            'absolute_path' => $absolute_path,
            'source_mime' => $mime,
            'webp_path' => self::sibling_path($absolute_path, 'webp'),
            'avif_path' => self::sibling_path($absolute_path, 'avif'),
        ];
    }

    private static function manifest_line(array $entry, array $settings): string {
        unset($settings);

        return implode("\t", [
            (string) ((int) ($entry['attachment_id'] ?? 0)),
            (string) ($entry['relative_path'] ?? ''),
            (string) ($entry['source_mime'] ?? ''),
            (string) ($entry['absolute_path'] ?? ''),
            (string) ($entry['signature'] ?? ''),
            (string) ((int) ($entry['needs_original'] ?? 0)),
            (string) ((int) ($entry['needs_webp'] ?? 0)),
            (string) ($entry['webp_path'] ?? ''),
            (string) ((int) ($entry['needs_avif'] ?? 0)),
            (string) ($entry['avif_path'] ?? ''),
        ]);
    }

    private static function collect_write_issues(array $entry, array $settings, array &$issues): void {
        $absolute_path = isset($entry['absolute_path']) ? wp_normalize_path((string) $entry['absolute_path']) : '';
        if ($absolute_path === '') return;

        $dir = wp_normalize_path((string) dirname($absolute_path));
        if ($dir === '') return;

        if (!empty($settings['optimize_originals'])) {
            if (!is_writable($absolute_path) || !is_writable($dir)) {
                $issues[$dir] = true;
                return;
            }
        }

        if ((!empty($settings['generate_webp']) || !empty($settings['generate_avif'])) && !is_writable($dir)) {
            $issues[$dir] = true;
        }
    }

    private static function permission_error(array $issues): \WP_Error {
        $dirs = array_keys($issues);
        sort($dirs, SORT_NATURAL | SORT_FLAG_CASE);

        $sample = array_slice($dirs, 0, 5);
        $message = __('The image runner cannot write into some upload directories.', PROCYON_IMAGE_PACKER_DOMAIN) . ' ';
        $message .= __('Grant write access for the PHP / shell process to those directories and retry.', PROCYON_IMAGE_PACKER_DOMAIN) . ' ';
        $message .= __('Examples:', PROCYON_IMAGE_PACKER_DOMAIN) . ' ' . implode(', ', $sample);

        if (count($dirs) > count($sample)) {
            $message .= ' (+' . (count($dirs) - count($sample)) . ' ' . __('more', PROCYON_IMAGE_PACKER_DOMAIN) . ')';
        }

        return new \WP_Error('upload_dirs_not_writable', $message, [
            'directories' => $dirs,
        ]);
    }

    private static function repair_missing_subsizes(int $attachment_id): void {
        if (!function_exists('wp_get_missing_image_subsizes') || !function_exists('wp_update_image_subsizes')) {
            return;
        }

        $missing = wp_get_missing_image_subsizes($attachment_id);
        if (empty($missing)) return;

        wp_update_image_subsizes($attachment_id);
    }

    private static function maybe_require_image_functions(): void {
        if (
            !function_exists('wp_get_missing_image_subsizes')
            || !function_exists('wp_update_image_subsizes')
        ) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }
    }

    private static function signature_for_path(string $path): string {
        $size = @filesize($path);
        $mtime = @filemtime($path);
        return (string) (($size !== false ? $size : 0) . ':' . ($mtime !== false ? $mtime : 0));
    }

    private static function sibling_path(string $path, string $extension): string {
        return preg_replace('/\.[^.]+$/', '.' . $extension, $path) ?: ($path . '.' . $extension);
    }

    private static function mime_for_path(string $path): string {
        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        if (in_array($extension, ['jpg', 'jpeg'], true)) return 'image/jpeg';
        if ($extension === 'png') return 'image/png';
        return '';
    }
}
