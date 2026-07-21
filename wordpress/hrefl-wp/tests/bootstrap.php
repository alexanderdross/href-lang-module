<?php
/**
 * Standalone test bootstrap for the pure-logic unit tests.
 *
 * These tests do NOT require a full WordPress install - they stub the handful
 * of WP functions the tested classes use, then load those classes directly.
 * Database/REST-dependent behaviour is covered by the WP integration test
 * framework instead (see README / TEST-EXECUTION-REPORT).
 *
 * Run: composer require --dev phpunit/phpunit && vendor/bin/phpunit
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

// Minimal WordPress function stubs used by the pure-logic classes.
if (!function_exists('wp_parse_url')) {
    function wp_parse_url(string $url, int $component = -1)
    {
        return parse_url($url, $component);
    }
}
if (!function_exists('home_url')) {
    function home_url(string $path = ''): string
    {
        return 'https://example.test' . $path;
    }
}
if (!function_exists('trailingslashit')) {
    function trailingslashit(string $s): string
    {
        return rtrim($s, '/') . '/';
    }
}
// Settable option store for the pure-logic settings/markets tests.
$GLOBALS['hrefl_test_options'] = [];
if (!function_exists('get_option')) {
    function get_option(string $key, $default = false)
    {
        return $GLOBALS['hrefl_test_options'][$key] ?? $default;
    }
}

$plugin = dirname(__DIR__);
require_once $plugin . '/includes/class-hrefl-validator.php';
require_once $plugin . '/includes/class-hrefl-signer.php';
require_once $plugin . '/includes/class-hrefl-registry.php';
require_once $plugin . '/includes/class-hrefl-settings.php';
require_once $plugin . '/includes/class-hrefl-markets.php';
