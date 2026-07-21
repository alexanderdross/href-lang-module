<?php
/**
 * SSRF-safe validation crawl: is a URL a clean hreflang target?
 *
 * A member may only be confirmed/served if its URL is HTTP 200, self-canonical,
 * and indexable. The fetch is sandboxed: host must be on the family allowlist,
 * the resolved IP must not be private/reserved, no redirects are followed, and
 * the body is capped. Mirrors the Drupal TargetValidator.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class Hrefl_Target_Validator {

    private const MAX_BODY = 65536;

    public function validate(string $url): bool {
        if (!$this->is_safe_url($url)) {
            return false;
        }
        // Pin the request to the vetted public IP so it cannot be re-pointed at
        // an internal address between the check and the fetch (DNS rebinding).
        // Only affects the curl transport; the stream transport keeps the host
        // allowlist as its control.
        $pin = $this->pin_for($url);
        $pin_cb = null;
        if ($pin !== null && function_exists('add_action')) {
            $pin_cb = static function ($handle) use ($pin): void {
                if (defined('CURLOPT_RESOLVE') && is_resource($handle)) {
                    curl_setopt($handle, CURLOPT_RESOLVE, [
                        $pin['host'] . ':443:' . $pin['ip'],
                        $pin['host'] . ':80:' . $pin['ip'],
                    ]);
                }
            };
            add_action('http_api_curl', $pin_cb, 10, 1);
        }
        $resp = wp_remote_get($url, [
            'redirection' => 0,
            'timeout'     => 10,
            'sslverify'   => true,
            'headers'     => ['Accept' => 'text/html'],
        ]);
        if ($pin_cb !== null) {
            remove_action('http_api_curl', $pin_cb, 10);
        }
        if (is_wp_error($resp) || (int) wp_remote_retrieve_response_code($resp) !== 200) {
            return false;
        }
        $body = substr((string) wp_remote_retrieve_body($resp), 0, self::MAX_BODY);
        if ($this->is_noindex($resp, $body)) {
            return false;
        }
        return $this->is_self_canonical($url, $body);
    }

    public function is_safe_url(string $url): bool {
        $scheme = strtolower((string) wp_parse_url($url, PHP_URL_SCHEME));
        $host   = (string) wp_parse_url($url, PHP_URL_HOST);
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            return false;
        }
        if (!in_array(strtolower($host), Hrefl_Markets::allowed_hosts(), true)) {
            return false;
        }
        foreach ($this->resolve_ips($host) as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return false;
            }
        }
        return true;
    }

    /**
     * The host + first vetted public IP to pin the request to, or null when
     * there is nothing to pin (literal-IP host, no curl, or no public IP).
     *
     * @return array{host:string,ip:string}|null
     */
    private function pin_for(string $url): ?array {
        if (!extension_loaded('curl')) {
            return null;
        }
        $host = (string) wp_parse_url($url, PHP_URL_HOST);
        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP)) {
            return null;
        }
        foreach ($this->resolve_ips($host) as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return ['host' => $host, 'ip' => $ip];
            }
        }
        return null;
    }

    /**
     * @return string[]
     */
    private function resolve_ips(string $host): array {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }
        $ips = [];
        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];
        foreach ($records as $record) {
            if (!empty($record['ip'])) {
                $ips[] = $record['ip'];
            }
            if (!empty($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }
        // Fail closed if resolution yields nothing.
        return $ips ?: ['0.0.0.0'];
    }

    private function is_noindex($resp, string $body): bool {
        $header = strtolower((string) wp_remote_retrieve_header($resp, 'x-robots-tag'));
        if (str_contains($header, 'noindex')) {
            return true;
        }
        return (bool) preg_match('/<meta[^>]+name=["\']robots["\'][^>]+content=["\'][^"\']*noindex/i', $body);
    }

    private function is_self_canonical(string $url, string $body): bool {
        if (!preg_match('/<link[^>]+rel=["\']canonical["\'][^>]+href=["\']([^"\']+)["\']/i', $body, $m)) {
            return true;
        }
        return $this->normalize($m[1]) === $this->normalize($url);
    }

    private function normalize(string $url): string {
        $url = strtok($url, '#');
        return rtrim((string) $url, '/');
    }
}
