<?php
/**
 * Activation / deactivation: create tables, schedule sync, flush rewrites.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class Hrefl_Activator {

    public static function activate(): void {
        self::create_tables();
        if (!wp_next_scheduled('hrefl_sync')) {
            wp_schedule_event(time() + 300, 'hourly', 'hrefl_sync');
        }
        if (!wp_next_scheduled('hrefl_match')) {
            wp_schedule_event(time() + 600, 'hourly', 'hrefl_match');
        }
        if (!wp_next_scheduled('hrefl_validate')) {
            wp_schedule_event(time() + 900, 'hourly', 'hrefl_validate');
        }
        // The sitemap uses rewrite rules registered on init: the entry point and
        // the numbered chunks it indexes past 50k URLs.
        add_rewrite_rule('^hrefl-sitemap\.xml$', 'index.php?hrefl_sitemap=1', 'top');
        add_rewrite_rule('^hrefl-sitemap\.([0-9]+)\.xml$', 'index.php?hrefl_sitemap=1&hrefl_chunk=$matches[1]', 'top');
        flush_rewrite_rules(false);
    }

    public static function deactivate(): void {
        wp_clear_scheduled_hook('hrefl_sync');
        wp_clear_scheduled_hook('hrefl_match');
        wp_clear_scheduled_hook('hrefl_validate');
        flush_rewrite_rules(false);
    }

    public static function create_tables(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $p = $wpdb->prefix;

        $alt = "CREATE TABLE {$p}hrefl_alternates (
            url_hash char(64) NOT NULL,
            url text NOT NULL,
            alternates longtext NOT NULL,
            lastmod bigint(20) NULL,
            updated bigint(20) NOT NULL DEFAULT 0,
            PRIMARY KEY  (url_hash)
        ) $charset;";

        $grp = "CREATE TABLE {$p}hrefl_group (
            group_id char(36) NOT NULL,
            updated bigint(20) NOT NULL DEFAULT 0,
            PRIMARY KEY  (group_id)
        ) $charset;";

        $mem = "CREATE TABLE {$p}hrefl_member (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            group_id char(36) NOT NULL,
            market varchar(32) NOT NULL,
            lang varchar(12) NOT NULL,
            hreflang varchar(16) NOT NULL,
            url text NOT NULL,
            url_hash char(64) NOT NULL,
            path_key varchar(255) NOT NULL DEFAULT '',
            title text NULL,
            status varchar(16) NOT NULL DEFAULT 'proposed',
            valid tinyint(1) NOT NULL DEFAULT 0,
            confidence float NOT NULL DEFAULT 0,
            changed bigint(20) NOT NULL DEFAULT 0,
            last_matched bigint(20) NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            UNIQUE KEY url_hash (url_hash),
            KEY group_id (group_id),
            KEY market_status (market,status),
            KEY path_key (path_key),
            KEY last_matched (last_matched)
        ) $charset;";

        $emb = "CREATE TABLE {$p}hrefl_embedding (
            url_hash char(64) NOT NULL,
            url text NOT NULL,
            market varchar(32) NOT NULL,
            language varchar(12) NOT NULL,
            content_hash char(64) NOT NULL,
            dims int NOT NULL DEFAULT 0,
            vector longtext NOT NULL,
            updated bigint(20) NOT NULL DEFAULT 0,
            PRIMARY KEY  (url_hash),
            KEY market (market)
        ) $charset;";

        dbDelta($alt);
        dbDelta($grp);
        dbDelta($mem);
        dbDelta($emb);
    }
}
