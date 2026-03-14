<?php
namespace Procyon\ImagePacker;

if (!defined('ABSPATH')) exit;

class Frontend {
    public static function init(): void {
        add_filter('wp_get_attachment_url', [__CLASS__, 'filter_attachment_url'], 20, 2);
        add_filter('wp_get_attachment_image_src', [__CLASS__, 'filter_attachment_image_src'], 20, 4);
        add_filter('wp_calculate_image_srcset', [__CLASS__, 'filter_attachment_srcset'], 20, 5);
    }

    public static function filter_attachment_url(string $url, int $attachment_id): string {
        unset($attachment_id);
        return self::rewrite_url($url);
    }

    public static function filter_attachment_image_src($image, int $attachment_id, $size, bool $icon) {
        unset($attachment_id, $size, $icon);

        if (!is_array($image) || empty($image[0])) {
            return $image;
        }

        $image[0] = self::rewrite_url((string) $image[0]);
        return $image;
    }

    public static function filter_attachment_srcset(array $sources, array $size_array, string $image_src, array $image_meta, int $attachment_id): array {
        unset($size_array, $image_src, $image_meta, $attachment_id);

        if (!$sources) return $sources;

        foreach ($sources as $descriptor => $source) {
            if (!is_array($source) || empty($source['url'])) continue;
            $sources[$descriptor]['url'] = self::rewrite_url((string) $source['url']);
        }

        return $sources;
    }

    private static function rewrite_url(string $url): string {
        if ($url === '' || is_admin() || wp_doing_ajax()) return $url;

        $settings = Settings::settings();
        if (empty($settings['serve_optimized_formats'])) return $url;

        $preferred_extension = self::preferred_extension($settings);
        if ($preferred_extension === '') return $url;

        $absolute_path = self::absolute_path_from_url($url);
        if ($absolute_path === '' || !is_file($absolute_path)) return $url;

        $candidate_path = preg_replace('/\.[^.]+$/', '.' . $preferred_extension, $absolute_path);
        if (!is_string($candidate_path) || !is_file($candidate_path)) {
            if ($preferred_extension === 'avif' && !empty($settings['generate_webp']) && self::supports_webp()) {
                $fallback = preg_replace('/\.[^.]+$/', '.webp', $absolute_path);
                if (is_string($fallback) && is_file($fallback)) {
                    return self::url_from_path($url, $fallback);
                }
            }

            return $url;
        }

        return self::url_from_path($url, $candidate_path);
    }

    private static function preferred_extension(array $settings): string {
        if (!empty($settings['generate_avif']) && self::supports_avif()) {
            return 'avif';
        }

        if (!empty($settings['generate_webp']) && self::supports_webp()) {
            return 'webp';
        }

        return '';
    }

    private static function supports_avif(): bool {
        return strpos((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'image/avif') !== false;
    }

    private static function supports_webp(): bool {
        return strpos((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'image/webp') !== false;
    }

    private static function absolute_path_from_url(string $url): string {
        $uploads = Environment::uploads();
        $baseurl = isset($uploads['baseurl']) ? untrailingslashit((string) $uploads['baseurl']) : '';
        $basedir = isset($uploads['basedir']) ? wp_normalize_path((string) $uploads['basedir']) : '';

        if ($baseurl === '' || $basedir === '') return '';

        $cut = strcspn($url, '?#');
        $base = substr($url, 0, $cut);
        if (strpos($base, $baseurl) !== 0) return '';

        $relative = ltrim(substr($base, strlen($baseurl)), '/');
        if ($relative === '') return '';

        return wp_normalize_path(trailingslashit($basedir) . rawurldecode($relative));
    }

    private static function url_from_path(string $original_url, string $path): string {
        $relative = Environment::relative_upload_path($path);
        if ($relative === '') return $original_url;

        $suffix = '';
        $cut = strcspn($original_url, '?#');
        if ($cut < strlen($original_url)) {
            $suffix = substr($original_url, $cut);
        }

        $new_url = Environment::upload_url_from_relative($relative);
        if ($new_url === '') return $original_url;

        return $new_url . $suffix;
    }
}
