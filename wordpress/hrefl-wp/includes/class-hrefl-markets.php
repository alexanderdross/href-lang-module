<?php
/**
 * Market definitions: URL-ownership, host allowlist, per-market secret.
 *
 * A market owns an absolute URL prefix - a path or a whole domain - so both
 * topologies work. Mirrors the Drupal MarketRegistry. Secrets come from the
 * HREFL_HUB_SECRET constant or the stored per-market value.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class Hrefl_Markets {

    private static function markets(): array {
        return (array) Hrefl_Settings::get('markets', []);
    }

    public static function prefix_for(string $market): string {
        $m = self::markets();
        if (!empty($m[$market]['prefix'])) {
            return rtrim((string) $m[$market]['prefix'], '/') . '/';
        }
        // Fallback: this site owns its own home URL.
        return trailingslashit(home_url('/'));
    }

    public static function owns_url(string $market, string $url): bool {
        if ($market === '' || $url === '') {
            return false;
        }
        return str_starts_with($url, self::prefix_for($market));
    }

    /**
     * @return string[]
     */
    public static function allowed_hosts(): array {
        $hosts = [];
        $self = wp_parse_url(home_url('/'), PHP_URL_HOST);
        if (is_string($self) && $self !== '') {
            $hosts[strtolower($self)] = true;
        }
        foreach (self::markets() as $m) {
            if (empty($m['prefix'])) {
                continue;
            }
            $h = wp_parse_url((string) $m['prefix'], PHP_URL_HOST);
            if (is_string($h) && $h !== '') {
                $hosts[strtolower($h)] = true;
            }
        }
        return array_keys($hosts);
    }

    public static function secret_for(string $market): string {
        if (defined('HREFL_HUB_SECRET') && HREFL_HUB_SECRET) {
            return (string) HREFL_HUB_SECRET;
        }
        $m = self::markets();
        return (string) ($m[$market]['secret'] ?? Hrefl_Settings::get('secret', ''));
    }
}
