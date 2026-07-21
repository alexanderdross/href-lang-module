<?php
/**
 * Bootstraps the plugin and wires hooks according to the configured role.
 *
 * The main site is usually "both" (client + hub). Client behaviour emits tags,
 * sitemap and selector and syncs on cron; hub behaviour exposes the signed REST
 * API and runs matching on cron. Mirrors how the Drupal Global backend runs both
 * submodules over the same public API.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class Hrefl_Plugin {

    public function boot(): void {
        load_plugin_textdomain('hrefl', false, dirname(plugin_basename(HREFL_FILE)) . '/languages');

        // Keep the schema current on code updates that did not re-activate the
        // plugin (dbDelta is idempotent and only adds what is missing).
        add_action('admin_init', [$this, 'maybe_upgrade']);

        $store       = new Hrefl_Store();
        $registry    = new Hrefl_Registry();
        $emitter     = new Hrefl_Emitter($store);
        $sitemap     = new Hrefl_Sitemap($store);
        $distributor = new Hrefl_Distributor($registry);

        // Sitemap endpoint is always wired (gated by its own setting).
        add_action('init', [$sitemap, 'register']);

        // Admin pages.
        (new Hrefl_Admin($registry))->register();

        // Client behaviour.
        if (Hrefl_Settings::is_client()) {
            add_action('wp_head', [$emitter, 'render_head'], 1);
            add_shortcode('hrefl_selector', [$emitter, 'render_selector']);
            add_action('hrefl_sync', [$this, 'cron_sync']);
        }

        // Hub behaviour.
        if (Hrefl_Settings::is_hub()) {
            add_action('rest_api_init', static function () use ($registry, $distributor): void {
                (new Hrefl_Rest($registry, $distributor))->register();
            });
            add_action('hrefl_match', [$this, 'cron_match']);
            add_action('hrefl_validate', [$this, 'cron_validate']);
        }
    }

    /**
     * Runs dbDelta when the stored schema version is behind the plugin version.
     */
    public function maybe_upgrade(): void {
        if (get_option('hrefl_db_version') === HREFL_VERSION) {
            return;
        }
        Hrefl_Activator::create_tables();
        update_option('hrefl_db_version', HREFL_VERSION, false);
    }

    /**
     * Client cron: publish inventory to the hub, pull resolved alternates.
     */
    public function cron_sync(): void {
        if (!Hrefl_Settings::is_client()) {
            return;
        }
        $hub = new Hrefl_Hub_Client();
        // Cursor-based walk so the whole corpus syncs across runs.
        $records = (new Hrefl_Collector())->collect_next();
        if ($records) {
            $hub->publish_inventory($records);
        }
        $payload = $hub->pull_alternates();
        $pages = $payload['pages'] ?? [];
        if (is_array($pages)) {
            (new Hrefl_Store())->replace_all($pages);
        }
    }

    /**
     * Hub cron: run URL-pattern matching over pages awaiting a match.
     */
    public function cron_match(): void {
        if (!Hrefl_Settings::is_hub()) {
            return;
        }
        (new Hrefl_Matcher(new Hrefl_Registry()))->run();
    }

    /**
     * Hub cron: SSRF-safe target validation, sets the valid flag.
     */
    public function cron_validate(): void {
        if (!Hrefl_Settings::is_hub()) {
            return;
        }
        $registry = new Hrefl_Registry();
        $validator = new Hrefl_Target_Validator();
        foreach ($registry->members_needing_validation(100) as $member) {
            $registry->set_valid((int) $member['id'], $validator->validate((string) $member['url']));
        }
    }
}
