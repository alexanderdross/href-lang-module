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
        $resp = wp_remote_get($url, [
            'redirection' => 0,
            'timeout'     => 10,
            'sslverify'   => true,
            'headers'     => ['Accept' => 'text/html'],
        ]);
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
