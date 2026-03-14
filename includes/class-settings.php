<?php
namespace Procyon\ImagePacker;

if (!defined('ABSPATH')) exit;

class Settings {
    private const OPTION_NAME = 'procyon_image_packer_settings';
    private const OPTION_GROUP = 'procyon_image_packer_settings_group';
    private const PAGE_SLUG = 'procyon-image-packer';

    public static function init(): void {
        if (!is_admin()) return;

        add_action('admin_menu', [__CLASS__, 'register_menu']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
    }

    public static function install_defaults(): void {
        add_option(self::OPTION_NAME, self::defaults(), '', false);
    }

    public static function defaults(): array {
        return [
            'optimize_originals' => true,
            'generate_webp' => true,
            'generate_avif' => false,
            'serve_optimized_formats' => true,
            'repair_missing_subsizes' => true,
            'auto_queue_new_uploads' => true,
        ];
    }

    public static function settings(): array {
        $raw = get_option(self::OPTION_NAME, []);
        if (!is_array($raw)) $raw = [];
        return self::sanitize_settings_option($raw);
    }

    public static function register_menu(): void {
        add_media_page(
            __('Procyon Image Packer', PROCYON_IMAGE_PACKER_DOMAIN),
            __('Procyon Image Packer', PROCYON_IMAGE_PACKER_DOMAIN),
            'manage_options',
            self::PAGE_SLUG,
            [__CLASS__, 'render_page']
        );
    }

    public static function register_settings(): void {
        register_setting(self::OPTION_GROUP, self::OPTION_NAME, [
            'type' => 'array',
            'sanitize_callback' => [__CLASS__, 'sanitize_settings_option'],
            'default' => self::defaults(),
        ]);
    }

    public static function sanitize_settings_option($value): array {
        $value = is_array($value) ? $value : [];
        $defaults = self::defaults();

        $out = [];
        foreach ($defaults as $key => $default) {
            $out[$key] = self::to_bool($value[$key] ?? $default);
        }

        if (!$out['generate_webp'] && !$out['generate_avif'] && !$out['optimize_originals']) {
            $out['optimize_originals'] = true;
        }

        return $out;
    }

    public static function enqueue_assets(string $hook): void {
        if ($hook !== 'media_page_' . self::PAGE_SLUG) return;

        wp_enqueue_style(
            'procyon-image-packer-admin',
            plugins_url('assets/admin.css', dirname(__DIR__) . '/procyon-image-packer.php'),
            [],
            PROCYON_IMAGE_PACKER_VER
        );

        wp_enqueue_script(
            'procyon-image-packer-admin',
            plugins_url('assets/admin.js', dirname(__DIR__) . '/procyon-image-packer.php'),
            [],
            PROCYON_IMAGE_PACKER_VER,
            true
        );

        wp_localize_script('procyon-image-packer-admin', 'ProcyonImagePackerAdmin', [
            'root' => esc_url_raw(rest_url('procyon-image-packer/v1')),
            'nonce' => wp_create_nonce('wp_rest'),
            'initialStatus' => Runner::status_payload(),
            'i18n' => [
                'statusLabels' => self::status_labels(),
                'modeLabels' => self::mode_labels(),
                'environmentReady' => __('Environment and settings are ready to work.', PROCYON_IMAGE_PACKER_DOMAIN),
                'genericRestError' => __('REST request failed.', PROCYON_IMAGE_PACKER_DOMAIN),
            ],
        ]);
    }

    public static function render_page(): void {
        if (!current_user_can('manage_options')) return;

        $settings = self::settings();
        $status = Runner::status_payload();
        $binaries = $status['binaries'] ?? Environment::detect_binaries();
        $issues = $status['issues'] ?? ['errors' => [], 'warnings' => []];
        ?>
        <div class="wrap procyon-image-packer-page" data-procyon-image-packer-root>
            <h1><?php echo esc_html__('Procyon Image Packer', PROCYON_IMAGE_PACKER_DOMAIN); ?></h1>
            <p><?php echo esc_html__('The batch runs outside WordPress through a shell script. The plugin builds an attachment manifest, keeps sub-sizes in sync and reports processing status.', PROCYON_IMAGE_PACKER_DOMAIN); ?></p>

            <?php if (!empty($_GET['settings-updated'])): ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php echo esc_html__('Settings saved.', PROCYON_IMAGE_PACKER_DOMAIN); ?></p>
                </div>
            <?php endif; ?>

            <div class="procyon-image-packer-grid">
                <section class="procyon-image-packer-card">
                    <h2><?php echo esc_html__('Batch status', PROCYON_IMAGE_PACKER_DOMAIN); ?></h2>
                    <div class="procyon-image-packer-progress" aria-hidden="true">
                        <span data-progress-bar style="width: <?php echo esc_attr((string) ($status['job']['progress_percent'] ?? 0)); ?>%;"></span>
                    </div>
                    <div class="procyon-image-packer-stats">
                        <div>
                            <strong data-field="status_label"><?php echo esc_html(self::status_label((string) ($status['job']['status'] ?? 'idle'))); ?></strong>
                            <span><?php echo esc_html__('Status', PROCYON_IMAGE_PACKER_DOMAIN); ?></span>
                        </div>
                        <div>
                            <strong data-field="progress_text"><?php echo esc_html((string) (($status['job']['processed'] ?? 0) . ' / ' . ($status['job']['total'] ?? 0))); ?></strong>
                            <span><?php echo esc_html__('Processed', PROCYON_IMAGE_PACKER_DOMAIN); ?></span>
                        </div>
                        <div>
                            <strong data-field="optimized_text"><?php echo esc_html((string) (($status['job']['success'] ?? 0) . ' / ' . ($status['job']['total'] ?? 0))); ?></strong>
                            <span><?php echo esc_html__('Optimized', PROCYON_IMAGE_PACKER_DOMAIN); ?></span>
                        </div>
                        <div>
                            <strong data-field="failed"><?php echo esc_html((string) ($status['job']['failed'] ?? 0)); ?></strong>
                            <span><?php echo esc_html__('Errors', PROCYON_IMAGE_PACKER_DOMAIN); ?></span>
                        </div>
                    </div>

                    <div class="procyon-image-packer-meta">
                        <p><strong><?php echo esc_html__('Job ID:', PROCYON_IMAGE_PACKER_DOMAIN); ?></strong> <span data-field="job_id"><?php echo esc_html((string) ($status['job']['job_id'] ?? '')); ?></span></p>
                        <p><strong><?php echo esc_html__('Mode:', PROCYON_IMAGE_PACKER_DOMAIN); ?></strong> <span data-field="mode"><?php echo esc_html(self::mode_label((string) ($status['job']['mode'] ?? 'full'))); ?></span></p>
                        <p><strong><?php echo esc_html__('Attachments scanned:', PROCYON_IMAGE_PACKER_DOMAIN); ?></strong> <span data-field="attachments_scanned"><?php echo esc_html((string) ($status['job']['attachments_scanned'] ?? 0)); ?></span></p>
                        <p><strong><?php echo esc_html__('Attachments queued:', PROCYON_IMAGE_PACKER_DOMAIN); ?></strong> <span data-field="attachments_queued"><?php echo esc_html((string) ($status['job']['attachments_queued'] ?? 0)); ?></span></p>
                        <p><strong><?php echo esc_html__('Current file:', PROCYON_IMAGE_PACKER_DOMAIN); ?></strong> <code data-field="current_file"><?php echo esc_html((string) ($status['job']['current_file'] ?? '')); ?></code></p>
                        <p><strong><?php echo esc_html__('Last update:', PROCYON_IMAGE_PACKER_DOMAIN); ?></strong> <span data-field="updated_at"><?php echo esc_html((string) ($status['job']['updated_at'] ?? '')); ?></span></p>
                    </div>

                    <div class="procyon-image-packer-actions">
                        <button type="button" class="button button-primary" data-action="start" <?php disabled(empty($status['can_start'])); ?>><?php echo esc_html__('Start full batch', PROCYON_IMAGE_PACKER_DOMAIN); ?></button>
                        <button type="button" class="button" data-action="pause" <?php disabled(empty($status['can_pause'])); ?>><?php echo esc_html__('Pause', PROCYON_IMAGE_PACKER_DOMAIN); ?></button>
                        <button type="button" class="button" data-action="resume" <?php disabled(empty($status['can_resume'])); ?>><?php echo esc_html__('Resume', PROCYON_IMAGE_PACKER_DOMAIN); ?></button>
                        <button type="button" class="button" data-action="refresh"><?php echo esc_html__('Refresh', PROCYON_IMAGE_PACKER_DOMAIN); ?></button>
                    </div>

                    <div class="procyon-image-packer-messages" data-messages>
                        <?php echo self::render_issue_list($issues); ?>
                    </div>
                </section>

                <section class="procyon-image-packer-card">
                    <h2><?php echo esc_html__('Plugin options', PROCYON_IMAGE_PACKER_DOMAIN); ?></h2>
                    <form method="post" action="options.php">
                        <?php settings_fields(self::OPTION_GROUP); ?>

                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row"><?php echo esc_html__('Original optimization', PROCYON_IMAGE_PACKER_DOMAIN); ?></th>
                                <td>
                                    <input type="hidden" name="<?php echo esc_attr(self::OPTION_NAME); ?>[optimize_originals]" value="0" />
                                    <label>
                                        <input type="checkbox" name="<?php echo esc_attr(self::OPTION_NAME); ?>[optimize_originals]" value="1" <?php checked(!empty($settings['optimize_originals'])); ?> />
                                        <?php echo esc_html__('Run `jpegoptim` and `pngquant` on source files', PROCYON_IMAGE_PACKER_DOMAIN); ?>
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php echo esc_html__('Generate WebP', PROCYON_IMAGE_PACKER_DOMAIN); ?></th>
                                <td>
                                    <input type="hidden" name="<?php echo esc_attr(self::OPTION_NAME); ?>[generate_webp]" value="0" />
                                    <label>
                                        <input type="checkbox" name="<?php echo esc_attr(self::OPTION_NAME); ?>[generate_webp]" value="1" <?php checked(!empty($settings['generate_webp'])); ?> />
                                        <?php echo esc_html__('Create `.webp` files next to originals and sub-sizes', PROCYON_IMAGE_PACKER_DOMAIN); ?>
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php echo esc_html__('Generate AVIF', PROCYON_IMAGE_PACKER_DOMAIN); ?></th>
                                <td>
                                    <input type="hidden" name="<?php echo esc_attr(self::OPTION_NAME); ?>[generate_avif]" value="0" />
                                    <label>
                                        <input type="checkbox" name="<?php echo esc_attr(self::OPTION_NAME); ?>[generate_avif]" value="1" <?php checked(!empty($settings['generate_avif'])); ?> />
                                        <?php echo esc_html__('Create `.avif` files next to originals and sub-sizes', PROCYON_IMAGE_PACKER_DOMAIN); ?>
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php echo esc_html__('Frontend', PROCYON_IMAGE_PACKER_DOMAIN); ?></th>
                                <td>
                                    <input type="hidden" name="<?php echo esc_attr(self::OPTION_NAME); ?>[serve_optimized_formats]" value="0" />
                                    <label>
                                        <input type="checkbox" name="<?php echo esc_attr(self::OPTION_NAME); ?>[serve_optimized_formats]" value="1" <?php checked(!empty($settings['serve_optimized_formats'])); ?> />
                                        <?php echo esc_html__('Rewrite attachment URLs and `srcset` to AVIF/WebP when files exist', PROCYON_IMAGE_PACKER_DOMAIN); ?>
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php echo esc_html__('Sub-sizes', PROCYON_IMAGE_PACKER_DOMAIN); ?></th>
                                <td>
                                    <input type="hidden" name="<?php echo esc_attr(self::OPTION_NAME); ?>[repair_missing_subsizes]" value="0" />
                                    <label>
                                        <input type="checkbox" name="<?php echo esc_attr(self::OPTION_NAME); ?>[repair_missing_subsizes]" value="1" <?php checked(!empty($settings['repair_missing_subsizes'])); ?> />
                                        <?php echo esc_html__('Repair missing sizes via `wp_get_missing_image_subsizes()` / `wp_update_image_subsizes()`', PROCYON_IMAGE_PACKER_DOMAIN); ?>
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php echo esc_html__('New uploads', PROCYON_IMAGE_PACKER_DOMAIN); ?></th>
                                <td>
                                    <input type="hidden" name="<?php echo esc_attr(self::OPTION_NAME); ?>[auto_queue_new_uploads]" value="0" />
                                    <label>
                                        <input type="checkbox" name="<?php echo esc_attr(self::OPTION_NAME); ?>[auto_queue_new_uploads]" value="1" <?php checked(!empty($settings['auto_queue_new_uploads'])); ?> />
                                        <?php echo esc_html__('Mark new attachments as dirty and schedule an automatic dirty-run batch', PROCYON_IMAGE_PACKER_DOMAIN); ?>
                                    </label>
                                </td>
                            </tr>
                        </table>

                        <?php submit_button(__('Save settings', PROCYON_IMAGE_PACKER_DOMAIN)); ?>
                    </form>
                </section>
            </div>

            <section class="procyon-image-packer-card">
                <h2><?php echo esc_html__('Available server tools', PROCYON_IMAGE_PACKER_DOMAIN); ?></h2>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php echo esc_html__('Tool', PROCYON_IMAGE_PACKER_DOMAIN); ?></th>
                            <th><?php echo esc_html__('Status', PROCYON_IMAGE_PACKER_DOMAIN); ?></th>
                            <th><?php echo esc_html__('Path', PROCYON_IMAGE_PACKER_DOMAIN); ?></th>
                            <th><?php echo esc_html__('Install', PROCYON_IMAGE_PACKER_DOMAIN); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($binaries as $binary => $meta): ?>
                            <tr>
                                <td><code><?php echo esc_html($binary); ?></code></td>
                                <td><?php echo !empty($meta['available']) ? esc_html__('Available', PROCYON_IMAGE_PACKER_DOMAIN) : esc_html__('Missing', PROCYON_IMAGE_PACKER_DOMAIN); ?></td>
                                <td><code><?php echo esc_html((string) ($meta['path'] ?? '')); ?></code></td>
                                <td><code><?php echo esc_html((string) ($meta['install_command'] ?? '')); ?></code></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <p class="description"><?php echo esc_html__('If you enable WebP or AVIF, the required binaries must be available before starting a batch.', PROCYON_IMAGE_PACKER_DOMAIN); ?></p>
            </section>
        </div>
        <?php
    }

    private static function to_bool($value): bool {
        return (bool) ((int) $value === 1 || $value === '1' || $value === true);
    }

    private static function status_label(string $status): string {
        $labels = self::status_labels();

        return $labels[$status] ?? ucfirst($status);
    }

    private static function mode_label(string $mode): string {
        $labels = self::mode_labels();

        return $labels[$mode] ?? $mode;
    }

    public static function status_labels(): array {
        return [
            'idle' => __('Idle', PROCYON_IMAGE_PACKER_DOMAIN),
            'queued' => __('Queued', PROCYON_IMAGE_PACKER_DOMAIN),
            'running' => __('Running', PROCYON_IMAGE_PACKER_DOMAIN),
            'paused' => __('Paused', PROCYON_IMAGE_PACKER_DOMAIN),
            'completed' => __('Completed', PROCYON_IMAGE_PACKER_DOMAIN),
            'failed' => __('Failed', PROCYON_IMAGE_PACKER_DOMAIN),
        ];
    }

    public static function mode_labels(): array {
        return [
            'full' => __('Full batch', PROCYON_IMAGE_PACKER_DOMAIN),
            'dirty' => __('Dirty queue', PROCYON_IMAGE_PACKER_DOMAIN),
        ];
    }

    private static function render_issue_list(array $issues): string {
        $errors = isset($issues['errors']) && is_array($issues['errors']) ? $issues['errors'] : [];
        $warnings = isset($issues['warnings']) && is_array($issues['warnings']) ? $issues['warnings'] : [];

        $html = '';
        if ($errors) {
            $html .= '<div class="notice notice-error inline"><ul>';
            foreach ($errors as $error) {
                $html .= '<li>' . esc_html((string) $error) . '</li>';
            }
            $html .= '</ul></div>';
        }

        if ($warnings) {
            $html .= '<div class="notice notice-warning inline"><ul>';
            foreach ($warnings as $warning) {
                $html .= '<li>' . esc_html((string) $warning) . '</li>';
            }
            $html .= '</ul></div>';
        }

        if ($html === '') {
            $html = '<div class="notice notice-success inline"><p>' . esc_html__('Environment looks good. The batch can use the currently selected options.', PROCYON_IMAGE_PACKER_DOMAIN) . '</p></div>';
        }

        return $html;
    }
}
