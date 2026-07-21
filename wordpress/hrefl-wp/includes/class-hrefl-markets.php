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

    /**
     * Resolve a market's HMAC secret. Fails closed and prefers per-market
     * secrets over the shared one:
     *
     * - Empty or unknown (unconfigured) markets get no secret.
     * - This site's own market authenticates with the local secret (it is
     *   signing as itself).
     * - A per-market secret - a `HREFL_HUB_SECRET_<MARKET>` constant or a stored
     *   per-market value - wins, giving true per-market isolation.
     * - Only then does it fall back to the shared `HREFL_HUB_SECRET` (the
     *   simpler, lower-isolation setup): acceptable now that ingest binds the
     *   payload market to this signed identity and enforces URL ownership.
     */
    public static function secret_for(string $market): string {
        if ($market === '') {
            return '';
        }
        // This site's own market: authenticated by the local secret.
        if ($market === (string) Hrefl_Settings::get('market')) {
            return Hrefl_Settings::secret();
        }
        $m = self::markets();
        if (!array_key_exists($market, $m)) {
            return '';
        }
        $const = 'HREFL_HUB_SECRET_' . strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '_', $market));
        if (defined($const) && constant($const)) {
            return (string) constant($const);
        }
        if (!empty($m[$market]['secret'])) {
            return (string) $m[$market]['secret'];
        }
        if (defined('HREFL_HUB_SECRET') && HREFL_HUB_SECRET) {
            return (string) HREFL_HUB_SECRET;
        }
        return (string) Hrefl_Settings::get('secret', '');
    }
}
