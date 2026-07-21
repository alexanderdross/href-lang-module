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
if (!function_exists('__')) {
    function __(string $text, string $domain = 'default'): string
    {
        return $text;
    }
}

// Minimal WP_REST_* stubs so the REST controller's reject paths (which return
// before any DB access) can be unit-tested without a WordPress install.
if (!class_exists('WP_REST_Request')) {
    class WP_REST_Request {
        /** @param array<string,mixed> $json @param array<string,string> $headers */
        public function __construct(
            private array $json = [],
            private array $headers = [],
            private string $method = 'POST'
        ) {}
        public function get_json_params() { return $this->json; }
        public function get_header(string $key): string { return $this->headers[$key] ?? ''; }
        public function get_method(): string { return $this->method; }
        public function get_route(): string { return '/hrefl/v1/inventory'; }
        public function get_body(): string { return ''; }
        public function get_query_params(): array { return []; }
        public function get_param(string $key) { return null; }
    }
}
if (!class_exists('WP_REST_Response')) {
    class WP_REST_Response {
        public function __construct(private mixed $data = null, private int $status = 200) {}
        public function get_status(): int { return $this->status; }
        public function get_data(): mixed { return $this->data; }
    }
}

$plugin = dirname(__DIR__);
require_once $plugin . '/includes/class-hrefl-validator.php';
require_once $plugin . '/includes/class-hrefl-locale.php';
require_once $plugin . '/includes/class-hrefl-signer.php';
require_once $plugin . '/includes/class-hrefl-registry.php';
require_once $plugin . '/includes/class-hrefl-settings.php';
require_once $plugin . '/includes/class-hrefl-markets.php';
require_once $plugin . '/includes/class-hrefl-distributor.php';
require_once $plugin . '/includes/class-hrefl-rest.php';
require_once $plugin . '/includes/class-hrefl-http.php';
require_once $plugin . '/includes/class-hrefl-vector-store.php';
require_once $plugin . '/includes/class-hrefl-embedding-matcher.php';
require_once $plugin . '/includes/class-hrefl-ai-matcher.php';
require_once $plugin . '/includes/class-hrefl-review-actions.php';
require_once $plugin . '/includes/class-hrefl-csv.php';
require_once $plugin . '/includes/class-hrefl-monitor.php';
