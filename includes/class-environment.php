<?php
namespace Procyon\ImagePacker;

if (!defined('ABSPATH')) exit;

class Environment {
    private const STORAGE_DIR = 'procyon-image-packer';

    public static function ensure_storage(): bool {
        $root = self::state_root();
        if (is_dir($root)) return true;
        return (bool) wp_mkdir_p($root);
    }

    public static function uploads(): array {
        $uploads = wp_upload_dir();
        return is_array($uploads) ? $uploads : [];
    }

    public static function state_root(): string {
        $uploads = self::uploads();
        $base = isset($uploads['basedir']) ? (string) $uploads['basedir'] : WP_CONTENT_DIR . '/uploads';
        return wp_normalize_path(trailingslashit($base) . self::STORAGE_DIR);
    }

    public static function job_file(): string {
        return self::state_root() . '/job.json';
    }

    public static function job_env_file(): string {
        return self::state_root() . '/job.env';
    }

    public static function manifest_file(): string {
        return self::state_root() . '/manifest.tsv';
    }

    public static function runtime_file(): string {
        return self::state_root() . '/runtime.status';
    }

    public static function done_file(): string {
        return self::state_root() . '/done.tsv';
    }

    public static function failed_file(): string {
        return self::state_root() . '/failed.tsv';
    }

    public static function log_file(): string {
        return self::state_root() . '/job.log';
    }

    public static function registry_file(): string {
        return self::state_root() . '/registry.tsv';
    }

    public static function pause_flag_file(): string {
        return self::state_root() . '/pause.flag';
    }

    public static function lock_file(): string {
        return self::state_root() . '/runner.lock';
    }

    public static function profile(): array {
        $profile = apply_filters('procyon_image_packer_profile', [
            'jpeg_max_quality' => 85,
            'png_quality_min' => 65,
            'png_quality_max' => 85,
            'webp_quality' => 82,
            'avif_quality' => 50,
            'avif_speed' => 6,
        ]);

        if (!is_array($profile)) {
            $profile = [];
        }

        return [
            'jpeg_max_quality' => min(max((int) ($profile['jpeg_max_quality'] ?? 85), 40), 100),
            'png_quality_min' => min(max((int) ($profile['png_quality_min'] ?? 65), 0), 100),
            'png_quality_max' => min(max((int) ($profile['png_quality_max'] ?? 85), 0), 100),
            'webp_quality' => min(max((int) ($profile['webp_quality'] ?? 82), 1), 100),
            'avif_quality' => min(max((int) ($profile['avif_quality'] ?? 50), 1), 100),
            'avif_speed' => min(max((int) ($profile['avif_speed'] ?? 6), 0), 10),
        ];
    }

    public static function detect_binaries(): array {
        $definitions = [
            'jpegoptim' => [
                'label' => 'jpegoptim',
                'package' => 'jpegoptim',
                'install_command' => 'sudo apt update && sudo apt install jpegoptim',
            ],
            'pngquant' => [
                'label' => 'pngquant',
                'package' => 'pngquant',
                'install_command' => 'sudo apt update && sudo apt install pngquant',
            ],
            'cwebp' => [
                'label' => 'cwebp',
                'package' => 'webp',
                'install_command' => 'sudo apt update && sudo apt install webp',
            ],
            'avifenc' => [
                'label' => 'avifenc',
                'package' => 'libavif-bin',
                'install_command' => 'sudo apt update && sudo apt install libavif-bin',
            ],
        ];

        $out = [];
        foreach ($definitions as $binary => $meta) {
            $path = self::find_binary($binary);
            $out[$binary] = [
                'label' => $meta['label'],
                'path' => $path,
                'available' => $path !== '',
                'package' => $meta['package'],
                'install_command' => $meta['install_command'],
            ];
        }

        return $out;
    }

    public static function generation_settings_hash(array $settings): string {
        $payload = [
            'plugin_version' => PROCYON_IMAGE_PACKER_VER,
            'optimize_originals' => !empty($settings['optimize_originals']),
            'generate_webp' => !empty($settings['generate_webp']),
            'generate_avif' => !empty($settings['generate_avif']),
            'profile' => self::profile(),
        ];

        return substr(sha1((string) wp_json_encode($payload)), 0, 20);
    }

    public static function launcher_available(): bool {
        return function_exists('exec') || function_exists('shell_exec') || function_exists('popen');
    }

    public static function start_background_runner(): array {
        $script = wp_normalize_path(PROCYON_IMAGE_PACKER_PATH . 'bin/process-images.sh');
        if (!is_file($script)) {
            return new \WP_Error('missing_runner', __('Shell runner process-images.sh is missing.', PROCYON_IMAGE_PACKER_DOMAIN));
        }

        $command = 'nohup /bin/sh '
            . escapeshellarg($script)
            . ' '
            . escapeshellarg(self::state_root())
            . ' >> '
            . escapeshellarg(self::log_file())
            . ' 2>&1 & echo $!';

        $pid = '';
        if (function_exists('exec')) {
            $output = [];
            $code = 0;
            exec($command, $output, $code);
            if ($code === 0 && !empty($output)) {
                $pid = trim((string) end($output));
            }
        }

        if ($pid === '' && function_exists('shell_exec')) {
            $pid = trim((string) shell_exec($command));
        }

        if ($pid === '' && function_exists('popen')) {
            $handle = popen($command, 'r');
            if (is_resource($handle)) {
                $pid = trim((string) stream_get_contents($handle));
                pclose($handle);
            }
        }

        if ($pid === '') {
            return new \WP_Error('runner_launch_failed', __('Failed to start the background shell runner.', PROCYON_IMAGE_PACKER_DOMAIN));
        }

        return [
            'pid' => $pid,
            'command' => $command,
        ];
    }

    public static function clear_runtime_files(): void {
        foreach ([
            self::manifest_file(),
            self::runtime_file(),
            self::done_file(),
            self::failed_file(),
            self::log_file(),
            self::job_env_file(),
            self::pause_flag_file(),
            self::lock_file(),
        ] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }

    public static function write_json_file(string $path, array $payload): bool {
        $json = wp_json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) return false;
        return file_put_contents($path, $json . "\n") !== false;
    }

    public static function read_json_file(string $path): array {
        if (!is_file($path)) return [];
        $raw = file_get_contents($path);
        if (!is_string($raw) || trim($raw) === '') return [];
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    public static function write_key_value_file(string $path, array $payload): bool {
        $lines = [];
        foreach ($payload as $key => $value) {
            $lines[] = sanitize_key((string) $key) . '=' . self::flatten_value($value);
        }
        return file_put_contents($path, implode("\n", $lines) . "\n") !== false;
    }

    public static function read_key_value_file(string $path): array {
        if (!is_file($path)) return [];

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) return [];

        $out = [];
        foreach ($lines as $line) {
            $parts = explode('=', (string) $line, 2);
            if (count($parts) !== 2) continue;
            $out[$parts[0]] = $parts[1];
        }

        return $out;
    }

    public static function write_env_file(string $path, array $payload): bool {
        $lines = ['#!/bin/sh'];
        foreach ($payload as $key => $value) {
            $var = strtoupper((string) preg_replace('/[^A-Za-z0-9_]/', '_', (string) $key));
            if ($var === '') continue;
            $lines[] = $var . '=' . self::shell_quote((string) $value);
            $lines[] = 'export ' . $var;
        }

        return file_put_contents($path, implode("\n", $lines) . "\n") !== false;
    }

    public static function relative_upload_path(string $absolute_path): string {
        $uploads = self::uploads();
        $base = isset($uploads['basedir']) ? wp_normalize_path((string) $uploads['basedir']) : '';
        $absolute_path = wp_normalize_path($absolute_path);

        if ($base === '' || strpos($absolute_path, $base . '/') !== 0) {
            return '';
        }

        return ltrim(substr($absolute_path, strlen($base)), '/');
    }

    public static function upload_url_from_relative(string $relative_path): string {
        $uploads = self::uploads();
        $baseurl = isset($uploads['baseurl']) ? untrailingslashit((string) $uploads['baseurl']) : '';
        if ($baseurl === '') return '';

        $segments = array_map('rawurlencode', array_filter(explode('/', str_replace('\\', '/', $relative_path)), 'strlen'));
        return $baseurl . '/' . implode('/', $segments);
    }

    public static function build_job_env(array $settings, array $binaries, array $job): array {
        $profile = self::profile();

        return [
            'job_id' => (string) ($job['job_id'] ?? ''),
            'optimize_originals' => !empty($settings['optimize_originals']) ? '1' : '0',
            'generate_webp' => !empty($settings['generate_webp']) ? '1' : '0',
            'generate_avif' => !empty($settings['generate_avif']) ? '1' : '0',
            'jpegoptim' => (string) ($binaries['jpegoptim']['path'] ?? ''),
            'pngquant' => (string) ($binaries['pngquant']['path'] ?? ''),
            'cwebp' => (string) ($binaries['cwebp']['path'] ?? ''),
            'avifenc' => (string) ($binaries['avifenc']['path'] ?? ''),
            'jpeg_max_quality' => (string) $profile['jpeg_max_quality'],
            'png_quality_min' => (string) $profile['png_quality_min'],
            'png_quality_max' => (string) $profile['png_quality_max'],
            'webp_quality' => (string) $profile['webp_quality'],
            'avif_quality' => (string) $profile['avif_quality'],
            'avif_speed' => (string) $profile['avif_speed'],
            'manifest_file' => self::manifest_file(),
            'runtime_file' => self::runtime_file(),
            'done_file' => self::done_file(),
            'failed_file' => self::failed_file(),
            'pause_flag_file' => self::pause_flag_file(),
            'lock_file' => self::lock_file(),
        ];
    }

    private static function find_binary(string $binary): string {
        $path = getenv('PATH');
        $segments = is_string($path) && $path !== '' ? explode(PATH_SEPARATOR, $path) : ['/usr/local/bin', '/usr/bin', '/bin'];

        foreach ($segments as $dir) {
            $dir = trim((string) $dir);
            if ($dir === '') continue;

            $candidate = wp_normalize_path(rtrim($dir, '/') . '/' . $binary);
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return '';
    }

    private static function flatten_value($value): string {
        return trim(str_replace(["\r", "\n"], ' ', (string) $value));
    }

    private static function shell_quote(string $value): string {
        return "'" . str_replace("'", "'\"'\"'", $value) . "'";
    }
}
