<?php
/**
 * Plugin Name:       Hreflang Cross-Site (hrefl)
 * Plugin URI:        https://github.com/boehringer-ingelheim/hrefl
 * Description:       Cross-site hreflang for a family of independent WordPress sites: a client on every site and a hub on one, connected by a signed API. Emits reciprocal hreflang tags, a multilingual sitemap, and a country/language selector.
 * Version:           0.2.0
 * Requires at least: 6.2
 * Requires PHP:      8.0
 * Author:            Boehringer Ingelheim
 * License:           GPL-2.0-or-later
 * Text Domain:       hrefl
 *
 * WordPress port of the Drupal `hrefl` module. Self-contained: a WordPress-only
 * family uses this; it does not interoperate with the Drupal hub (by design).
 * The architecture mirrors the Drupal version - see README.md for the mapping.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('HREFL_VERSION', '0.2.0');
define('HREFL_FILE', __FILE__);
define('HREFL_DIR', plugin_dir_path(__FILE__));
define('HREFL_REST_NS', 'hrefl/v1');

// Lightweight loader (no Composer needed for a plugin).
foreach ([
    'includes/class-hrefl-settings.php',
    'includes/class-hrefl-signer.php',
    'includes/class-hrefl-validator.php',
    'includes/class-hrefl-locale.php',
    'includes/class-hrefl-activator.php',
    'includes/class-hrefl-store.php',
    'includes/class-hrefl-collector.php',
    'includes/class-hrefl-hub-client.php',
    'includes/class-hrefl-emitter.php',
    'includes/class-hrefl-sitemap.php',
    'includes/class-hrefl-registry.php',
    'includes/class-hrefl-markets.php',
    'includes/class-hrefl-target-validator.php',
    'includes/class-hrefl-vector-store.php',
    'includes/class-hrefl-embedding-matcher.php',
    'includes/class-hrefl-ai-matcher.php',
    'includes/class-hrefl-matcher.php',
    'includes/class-hrefl-distributor.php',
    'includes/class-hrefl-rest.php',
    'includes/class-hrefl-admin.php',
    'includes/class-hrefl-plugin.php',
] as $file) {
    require_once HREFL_DIR . $file;
}

register_activation_hook(__FILE__, ['Hrefl_Activator', 'activate']);
register_deactivation_hook(__FILE__, ['Hrefl_Activator', 'deactivate']);

/**
 * Boot the plugin once WordPress is ready.
 */
add_action('plugins_loaded', static function (): void {
    (new Hrefl_Plugin())->boot();
});
