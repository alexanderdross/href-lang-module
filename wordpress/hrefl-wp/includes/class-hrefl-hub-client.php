<?php
/**
 * HTTP transport to the hub (signed inventory publish + alternates pull).
 *
 * Mirrors the Drupal HubClient: signs the exact body it sends. Uses the WP HTTP
 * API (wp_remote_*).
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class Hrefl_Hub_Client {

    /**
     * Hard cap on serve pages walked in one pull, so a misbehaving or malicious
     * `next` cursor cannot loop forever (500 pages x 500 rows = 250k URLs).
     */
    private const MAX_PAGES = 500;

    private function base(): string {
        return rtrim((string) Hrefl_Settings::get('hub_url'), '/');
    }

    public function publish_inventory(array $records): void {
        $base = $this->base();
        if ($base === '' || !$records) {
            return;
        }
        $body = wp_json_encode([
            'market'  => Hrefl_Settings::get('market'),
            'records' => $records,
        ]);
        $path = Hrefl_Signer::route_path('inventory');
        $resp = wp_remote_post($base . '/inventory', [
            'headers' => array_merge(
                ['Content-Type' => 'application/json'],
                Hrefl_Signer::headers('POST', $path, [], (string) $body)
            ),
            'body'    => $body,
            'timeout' => 30,
        ]);
        if (is_wp_error($resp)) {
            error_log('[hrefl] inventory publish failed: ' . $resp->get_error_message());
        }
    }

    /**
     * Pull the resolved alternates for this backend's market.
     *
     * Walks the hub's cursor pagination (`next`) and accumulates every page, so
     * the store still swaps the whole set atomically while neither side has to
     * build a large market in a single request.
     */
    public function pull_alternates(): array {
        $base = $this->base();
        if ($base === '') {
            return [];
        }
        // The market travels in the signed X-Hrefl-Market header, not the query
        // string, so it cannot be tampered with; the hub reads the header only.
        $path  = Hrefl_Signer::route_path('alternates');
        $pages = [];
        $after = 0;
        for ($i = 0; $i < self::MAX_PAGES; $i++) {
            // Only `after` is signed/sent; `market` stays in the header.
            $query = $after > 0 ? ['after' => (string) $after] : [];
            $url   = $base . '/alternates';
            if ($query) {
                $url = add_query_arg($query, $url);
            }
            $resp = wp_remote_get($url, [
                'headers' => Hrefl_Signer::headers('GET', $path, $query, ''),
                'timeout' => 30,
            ]);
            if (is_wp_error($resp)) {
                error_log('[hrefl] alternates pull failed: ' . $resp->get_error_message());
                // Return nothing so the store's empty-payload guard keeps the
                // last known-good set rather than swapping in a partial one.
                return [];
            }
            $data = json_decode((string) wp_remote_retrieve_body($resp), true);
            if (!is_array($data)) {
                return [];
            }
            $batch = $data['pages'] ?? [];
            if (is_array($batch)) {
                $pages = array_merge($pages, $batch);
            }
            $next = $data['next'] ?? null;
            if ($next === null || (int) $next <= $after) {
                // Exhausted, or a non-advancing cursor: stop rather than loop.
                return ['market' => Hrefl_Settings::get('market'), 'pages' => $pages];
            }
            $after = (int) $next;
        }
        error_log('[hrefl] alternates pull hit the page cap; serving a partial set.');
        return ['market' => Hrefl_Settings::get('market'), 'pages' => $pages];
    }
}
