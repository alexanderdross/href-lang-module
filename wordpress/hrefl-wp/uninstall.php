<?php
/**
 * Uninstall: remove options and tables.
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

delete_option('hrefl_settings');

foreach (['hrefl_alternates', 'hrefl_group', 'hrefl_member'] as $table) {
    $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}{$table}");
}

wp_clear_scheduled_hook('hrefl_sync');
wp_clear_scheduled_hook('hrefl_match');
