<?php
/**
 * Hub REST API: signed inventory ingest + alternates serve.
 *
 * Both routes require a valid HMAC signature (permission callback). Ingest
 * enforces per-market URL ownership. Mirrors the Drupal Ingest/Serve controllers
 * behind the SignedRequestAccessCheck.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class Hrefl_Rest {

    /**
     * Maximum records accepted per ingest request (clients publish in batches).
     */
    private const MAX_RECORDS = 500;

    public function __construct(
        private Hrefl_Registry $registry,
        private Hrefl_Distributor $distributor
    ) {}

    public function register(): void {
        register_rest_route(HREFL_REST_NS, '/inventory', [
            'methods'             => 'POST',
            'callback'            => [$this, 'ingest'],
            'permission_callback' => ['Hrefl_Signer', 'verify'],
        ]);
        register_rest_route(HREFL_REST_NS, '/alternates', [
            'methods'             => 'GET',
            'callback'            => [$this, 'alternates'],
            'permission_callback' => ['Hrefl_Signer', 'verify'],
        ]);
    }

    public function ingest(WP_REST_Request $request): WP_REST_Response {
        $payload = $request->get_json_params();
        if (!is_array($payload) || empty($payload['market']) || !isset($payload['records'])) {
            return new WP_REST_Response(['error' => 'invalid payload'], 400);
        }
        $market = (string) $payload['market'];
        // The payload market must be the identity the HMAC check authenticated
        // (the signature was made with that market's secret); otherwise a client
        // could sign as itself yet assert records for another market.
        if ($market !== (string) $request->get_header('x_hrefl_market')) {
            return new WP_REST_Response(['error' => 'payload market does not match signed market'], 403);
        }
        if (count((array) $payload['records']) > self::MAX_RECORDS) {
            return new WP_REST_Response(['error' => 'too many records', 'max' => self::MAX_RECORDS], 413);
        }
        $accepted = 0;
        $rejected = 0;
        foreach ((array) $payload['records'] as $record) {
            $url = (string) ($record['url'] ?? '');
            if ($url === '' || !Hrefl_Markets::owns_url($market, $url)) {
                $rejected++;
                continue;
            }
            $this->registry->upsert_member([
                'group_id' => $this->registry->group_for_url($url) ?? $this->registry->create_group(),
                'market'   => $market,
                'language' => (string) ($record['language'] ?? ''),
                'hreflang' => (string) ($record['hreflang'] ?? ''),
                'url'      => $url,
                'title'    => $record['title'] ?? null,
                'changed'  => (int) ($record['changed'] ?? 0),
                'status'   => 'proposed',
                // Unvalidated on ingest; the validation cron checks the target
                // (200 / canonical / index) and sets valid=1 before it can be
                // confirmed.
                'valid'    => 0,
            ]);
            $accepted++;
        }
        return new WP_REST_Response([
            'accepted' => $accepted,
            'rejected' => $rejected,
        ], 200);
    }

    public function alternates(WP_REST_Request $request): WP_REST_Response {
        // Market comes only from the signed header - never an unsigned query
        // param - so it cannot be swapped to read another market's data.
        $market = (string) $request->get_header('x_hrefl_market');
        if ($market === '') {
            return new WP_REST_Response(['error' => 'market required'], 400);
        }
        return new WP_REST_Response([
            'market' => $market,
            'pages'  => $this->distributor->for_market($market),
        ], 200);
    }
}
