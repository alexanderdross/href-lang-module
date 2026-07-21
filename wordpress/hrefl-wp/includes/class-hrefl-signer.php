<?php
/**
 * HMAC request signing + verification (shared by client and hub).
 *
 * Canonical string (identical on both sides, signing protocol v2):
 *   METHOD \n PATH \n QUERY \n TIMESTAMP \n sha256(body)
 * QUERY is the query parameters key-sorted and re-encoded with
 * http_build_query() ('' when there are none), so both sides derive the same
 * bytes regardless of original parameter order. Signature =
 * HMAC-SHA256(canonical, secret). Timestamps outside a 5-minute window are
 * rejected to bound replay. Mirrors the Drupal RequestSigner /
 * SignedRequestAccessCheck.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class Hrefl_Signer {

    private const MAX_SKEW = 300;

    /**
     * Signed headers for an outbound request, or [] if no secret is set.
     *
     * @param array<string,mixed> $query Query parameters covered by the signature.
     */
    public static function headers(string $method, string $path, array $query = [], string $body = ''): array {
        $secret = Hrefl_Settings::secret();
        $market = (string) Hrefl_Settings::get('market');
        if ($secret === '' || $market === '') {
            return [];
        }
        $ts = time();
        return [
            'X-Hrefl-Market'    => $market,
            'X-Hrefl-Timestamp' => (string) $ts,
            'X-Hrefl-Signature' => self::sign($method, $path, self::canonical_query($query), $ts, $body, $secret),
        ];
    }

    /**
     * Verify an incoming REST request against the market's secret.
     */
    public static function verify(WP_REST_Request $request): bool {
        $market = (string) $request->get_header('x_hrefl_market');
        $ts     = (int) $request->get_header('x_hrefl_timestamp');
        $sig    = (string) $request->get_header('x_hrefl_signature');
        if ($market === '' || $sig === '' || $ts === 0) {
            return false;
        }
        if (abs(time() - $ts) > self::MAX_SKEW) {
            return false;
        }
        $secret = Hrefl_Markets::secret_for($market);
        if ($secret === '') {
            return false;
        }
        // REST path as the client signed it (route path under the site).
        $path     = self::request_path($request);
        $query    = self::canonical_query((array) $request->get_query_params());
        $expected = self::sign($request->get_method(), $path, $query, $ts, $request->get_body(), $secret);
        return hash_equals($expected, $sig);
    }

    private static function sign(string $method, string $path, string $query, int $ts, string $body, string $secret): string {
        return hash_hmac('sha256', self::canonical($method, $path, $query, $ts, $body), $secret);
    }

    /**
     * The canonical string that is HMAC-signed. Public for testability; it must
     * stay byte-for-byte identical between the client signer and hub verifier.
     */
    public static function canonical(string $method, string $path, string $query, int $ts, string $body): string {
        return implode("\n", [
            strtoupper($method),
            $path,
            $query,
            (string) $ts,
            hash('sha256', $body),
        ]);
    }

    /**
     * The canonical (key-sorted, re-encoded) form of a query parameter set.
     * Must match the Drupal RequestSigner::canonicalQuery() output.
     */
    public static function canonical_query(array $query): string {
        if (!$query) {
            return '';
        }
        ksort($query);
        return http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * The path the client signs / the hub verifies: /wp-json/hrefl/v1/<route>.
     */
    public static function route_path(string $route): string {
        return '/wp-json/' . HREFL_REST_NS . '/' . ltrim($route, '/');
    }

    private static function request_path(WP_REST_Request $request): string {
        return '/wp-json/' . ltrim((string) $request->get_route(), '/');
    }
}
