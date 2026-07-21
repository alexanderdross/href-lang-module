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

    public function pull_alternates(): array {
        $base = $this->base();
        if ($base === '') {
            return [];
        }
        // The market travels in the signed X-Hrefl-Market header, not the query
        // string, so it cannot be tampered with; the hub reads the header only.
        $path = Hrefl_Signer::route_path('alternates');
        $resp = wp_remote_get($base . '/alternates', [
            'headers' => Hrefl_Signer::headers('GET', $path, [], ''),
            'timeout' => 30,
        ]);
        if (is_wp_error($resp)) {
            error_log('[hrefl] alternates pull failed: ' . $resp->get_error_message());
            return [];
        }
        $data = json_decode((string) wp_remote_retrieve_body($resp), true);
        return is_array($data) ? $data : [];
    }
}
