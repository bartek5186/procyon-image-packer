<?php
/**
 * Plugin Name: Procyon Image Packer
 * Description: Batch optimizer for WordPress media originals with sibling WebP/AVIF generation via external shell runner.
 * Version: 0.1.0
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Author: bartek5186
 * Text Domain: procyon-image-packer
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) exit;

define('PROCYON_IMAGE_PACKER_VER', '0.1.0');
define('PROCYON_IMAGE_PACKER_PATH', plugin_dir_path(__FILE__));
define('PROCYON_IMAGE_PACKER_DOMAIN', 'procyon-image-packer');

require_once PROCYON_IMAGE_PACKER_PATH . 'includes/class-environment.php';
require_once PROCYON_IMAGE_PACKER_PATH . 'includes/class-settings.php';
require_once PROCYON_IMAGE_PACKER_PATH . 'includes/class-registry.php';
require_once PROCYON_IMAGE_PACKER_PATH . 'includes/class-manifest.php';
require_once PROCYON_IMAGE_PACKER_PATH . 'includes/class-runner.php';
require_once PROCYON_IMAGE_PACKER_PATH . 'includes/class-rest.php';
require_once PROCYON_IMAGE_PACKER_PATH . 'includes/class-frontend.php';
require_once PROCYON_IMAGE_PACKER_PATH . 'includes/class-cli.php';

register_activation_hook(__FILE__, function () {
    \Procyon\ImagePacker\Environment::ensure_storage();
    \Procyon\ImagePacker\Settings::install_defaults();
});

register_deactivation_hook(__FILE__, function () {
    \Procyon\ImagePacker\Runner::clear_scheduled_dirty_run();
});

add_action('plugins_loaded', function () {
    load_plugin_textdomain(PROCYON_IMAGE_PACKER_DOMAIN, false, dirname(plugin_basename(__FILE__)) . '/languages');

    \Procyon\ImagePacker\Settings::init();
    \Procyon\ImagePacker\Runner::init_hooks();
    \Procyon\ImagePacker\Rest::init();
    \Procyon\ImagePacker\Frontend::init();

    if (defined('WP_CLI') && WP_CLI) {
        \Procyon\ImagePacker\Cli::register();
    }
});
