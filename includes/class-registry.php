<?php
namespace Procyon\ImagePacker;

if (!defined('ABSPATH')) exit;

class Registry {
    public const ATTACHMENT_STATE_META = '_procyon_image_packer_state';
    public const DIRTY_META = '_procyon_image_packer_dirty';

    public static function load(): array {
        $path = Environment::registry_file();
        if (!is_file($path)) return [];

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) return [];

        $out = [];
        foreach ($lines as $line) {
            $parts = explode("\t", (string) $line);
            if (count($parts) < 9) continue;

            $record = [
                'relative_path' => $parts[0],
                'signature' => $parts[1],
                'settings_hash' => $parts[2],
                'attachment_id' => (int) $parts[3],
                'original_done' => $parts[4] === '1',
                'webp_done' => $parts[5] === '1',
                'avif_done' => $parts[6] === '1',
                'processed_at' => $parts[7],
                'source_mime' => $parts[8],
            ];

            $out[$record['relative_path']] = $record;
        }

        return $out;
    }

    public static function save(array $registry): bool {
        ksort($registry, SORT_NATURAL | SORT_FLAG_CASE);

        $lines = [];
        foreach ($registry as $relative_path => $record) {
            $lines[] = implode("\t", [
                (string) $relative_path,
                (string) ($record['signature'] ?? ''),
                (string) ($record['settings_hash'] ?? ''),
                (string) ((int) ($record['attachment_id'] ?? 0)),
                !empty($record['original_done']) ? '1' : '0',
                !empty($record['webp_done']) ? '1' : '0',
                !empty($record['avif_done']) ? '1' : '0',
                (string) ($record['processed_at'] ?? ''),
                (string) ($record['source_mime'] ?? ''),
            ]);
        }

        return file_put_contents(Environment::registry_file(), implode("\n", $lines) . ($lines ? "\n" : '')) !== false;
    }

    public static function is_current(array $record, array $file_entry, array $settings, string $settings_hash): bool {
        if (!$record) return false;
        if (($record['signature'] ?? '') !== ($file_entry['signature'] ?? '')) return false;
        if (($record['settings_hash'] ?? '') !== $settings_hash) return false;

        if (!empty($settings['optimize_originals']) && empty($record['original_done'])) {
            return false;
        }

        if (!empty($settings['generate_webp'])) {
            if (empty($record['webp_done']) || !is_file((string) ($file_entry['webp_path'] ?? ''))) {
                return false;
            }
        }

        if (!empty($settings['generate_avif'])) {
            if (empty($record['avif_done']) || !is_file((string) ($file_entry['avif_path'] ?? ''))) {
                return false;
            }
        }

        return true;
    }

    public static function sync_job_results(array $job): array {
        if (!$job || !empty($job['results_applied'])) {
            return $job;
        }

        $registry = self::load();
        $manifest_entries = Manifest::load_manifest_entries();
        $done_records = self::read_done_records();
        $failed_records = self::read_failed_records();

        $attachment_stats = [];
        foreach ($manifest_entries as $entry) {
            $attachment_id = (int) ($entry['attachment_id'] ?? 0);
            if ($attachment_id <= 0) continue;

            if (!isset($attachment_stats[$attachment_id])) {
                $attachment_stats[$attachment_id] = [
                    'total' => 0,
                    'done' => 0,
                    'failed' => 0,
                    'updated_at' => '',
                    'last_error' => '',
                ];
            }

            $attachment_stats[$attachment_id]['total']++;
        }

        foreach ($done_records as $record) {
            $registry[$record['relative_path']] = [
                'relative_path' => $record['relative_path'],
                'signature' => $record['signature'],
                'settings_hash' => (string) ($job['settings_hash'] ?? ''),
                'attachment_id' => (int) $record['attachment_id'],
                'original_done' => !empty($record['original_done']),
                'webp_done' => !empty($record['webp_done']),
                'avif_done' => !empty($record['avif_done']),
                'processed_at' => (string) ($record['processed_at'] ?? gmdate('c')),
                'source_mime' => (string) ($record['source_mime'] ?? ''),
            ];

            $attachment_id = (int) ($record['attachment_id'] ?? 0);
            if ($attachment_id > 0) {
                if (!isset($attachment_stats[$attachment_id])) {
                    $attachment_stats[$attachment_id] = ['total' => 0, 'done' => 0, 'failed' => 0, 'updated_at' => '', 'last_error' => ''];
                }
                $attachment_stats[$attachment_id]['done']++;
                $attachment_stats[$attachment_id]['updated_at'] = (string) ($record['processed_at'] ?? gmdate('c'));
            }
        }

        foreach ($failed_records as $record) {
            $attachment_id = (int) ($record['attachment_id'] ?? 0);
            if ($attachment_id > 0) {
                if (!isset($attachment_stats[$attachment_id])) {
                    $attachment_stats[$attachment_id] = ['total' => 0, 'done' => 0, 'failed' => 0, 'updated_at' => '', 'last_error' => ''];
                }
                $attachment_stats[$attachment_id]['failed']++;
                $attachment_stats[$attachment_id]['updated_at'] = (string) ($record['processed_at'] ?? gmdate('c'));
                $attachment_stats[$attachment_id]['last_error'] = (string) ($record['reason'] ?? '');
            }
        }

        self::save($registry);

        foreach ($attachment_stats as $attachment_id => $stats) {
            $dirty = (int) $stats['failed'] > 0 || (int) $stats['done'] < (int) $stats['total'];
            self::set_attachment_state($attachment_id, [
                'dirty' => $dirty,
                'last_job_id' => (string) ($job['job_id'] ?? ''),
                'last_result' => $dirty ? 'pending' : 'packed',
                'last_packed_at' => (string) ($stats['updated_at'] ?: gmdate('c')),
                'settings_hash' => (string) ($job['settings_hash'] ?? ''),
                'files_total' => (int) $stats['total'],
                'files_done' => (int) $stats['done'],
                'files_failed' => (int) $stats['failed'],
                'last_error' => (string) ($stats['last_error'] ?? ''),
            ]);
        }

        $job['results_applied'] = true;
        $job['synced_at'] = gmdate('c');
        Environment::write_json_file(Environment::job_file(), $job);

        return $job;
    }

    public static function mark_attachment_dirty(int $attachment_id): void {
        if ($attachment_id <= 0) return;

        self::set_attachment_state($attachment_id, [
            'dirty' => true,
            'last_result' => 'dirty',
            'last_packed_at' => '',
            'updated_at' => gmdate('c'),
        ]);
    }

    public static function mark_attachment_clean(int $attachment_id, string $settings_hash, int $file_count): void {
        if ($attachment_id <= 0) return;

        self::set_attachment_state($attachment_id, [
            'dirty' => false,
            'last_result' => 'up_to_date',
            'last_packed_at' => gmdate('c'),
            'settings_hash' => $settings_hash,
            'files_total' => $file_count,
            'files_done' => $file_count,
            'files_failed' => 0,
            'last_error' => '',
        ]);
    }

    public static function remove_attachment_records(int $attachment_id, array $relative_paths = []): void {
        if ($attachment_id <= 0 && !$relative_paths) return;

        $registry = self::load();
        if (!$registry) return;

        $relative_map = [];
        foreach ($relative_paths as $relative_path) {
            $relative_path = (string) $relative_path;
            if ($relative_path === '') continue;
            $relative_map[$relative_path] = true;
        }

        $changed = false;
        foreach ($registry as $path => $record) {
            $record_attachment_id = (int) ($record['attachment_id'] ?? 0);
            if (($attachment_id > 0 && $record_attachment_id === $attachment_id) || isset($relative_map[$path])) {
                unset($registry[$path]);
                $changed = true;
            }
        }

        if ($changed) {
            self::save($registry);
        }
    }

    public static function set_attachment_state(int $attachment_id, array $state): void {
        if ($attachment_id <= 0) return;

        $state['updated_at'] = (string) ($state['updated_at'] ?? gmdate('c'));
        update_post_meta($attachment_id, self::ATTACHMENT_STATE_META, $state);

        if (!empty($state['dirty'])) {
            update_post_meta($attachment_id, self::DIRTY_META, '1');
        } else {
            delete_post_meta($attachment_id, self::DIRTY_META);
        }
    }

    private static function read_done_records(): array {
        $path = Environment::done_file();
        if (!is_file($path)) return [];

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) return [];

        $records = [];
        foreach ($lines as $line) {
            $parts = explode("\t", (string) $line);
            if (count($parts) < 8) continue;

            $records[] = [
                'relative_path' => $parts[0],
                'signature' => $parts[1],
                'attachment_id' => (int) $parts[2],
                'original_done' => $parts[3] === '1',
                'webp_done' => $parts[4] === '1',
                'avif_done' => $parts[5] === '1',
                'processed_at' => $parts[6],
                'source_mime' => $parts[7],
            ];
        }

        return $records;
    }

    private static function read_failed_records(): array {
        $path = Environment::failed_file();
        if (!is_file($path)) return [];

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) return [];

        $records = [];
        foreach ($lines as $line) {
            $parts = explode("\t", (string) $line);
            if (count($parts) < 5) continue;

            $records[] = [
                'relative_path' => $parts[0],
                'signature' => $parts[1],
                'attachment_id' => (int) $parts[2],
                'reason' => $parts[3],
                'processed_at' => $parts[4],
            ];
        }

        return $records;
    }
}
