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

$plugin = dirname(__DIR__);
require_once $plugin . '/includes/class-hrefl-validator.php';
require_once $plugin . '/includes/class-hrefl-signer.php';
require_once $plugin . '/includes/class-hrefl-registry.php';
