<?php

declare(strict_types=1);

namespace Drupal\hrefl_hub\Service;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

/**
 * SSRF-safe validation crawl: is a URL a clean hreflang target?
 *
 * A member may only be served/confirmed if its URL is HTTP 200, self-canonical,
 * and indexable (docs/HREFLANG-RULES.md §7). The fetch that checks this is a
 * sandboxed capability (docs/SECURITY.md §3):
 *
 * - Host must be on the family allowlist (the configured canonical host).
 * - The resolved IP must not be private/reserved (blocks DNS-rebinding to
 *   internal services and metadata endpoints).
 * - No redirects are followed, no credentials are sent, and the response body
 *   is capped and time-limited.
 */
final class TargetValidator {

  /**
   * How many bytes of the response body to inspect for canonical/robots.
   */
  private const MAX_BODY_BYTES = 65536;

  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly MarketRegistry $marketRegistry,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Validate a single target URL.
   *
   * @return bool
   *   TRUE only if the URL is safe to fetch AND is 200 + self-canonical +
   *   indexable. Any doubt returns FALSE (never advertise a dubious target).
   */
  public function validate(string $url): bool {
    if (!$this->isSafeUrl($url)) {
      $this->logger->warning('Refused unsafe validation fetch: @url', ['@url' => $url]);
      return FALSE;
    }

    try {
      $response = $this->httpClient->request('GET', $url, [
        'allow_redirects' => FALSE,
        'http_errors' => FALSE,
        'timeout' => 10,
        'connect_timeout' => 5,
        'headers' => ['Accept' => 'text/html'],
        // No auth: validation fetches are anonymous by design.
        'auth' => NULL,
        // Pin the connection to the IP we just vetted so the fetch cannot be
        // re-pointed at an internal address between the check and the request
        // (DNS rebinding / TOCTOU). Ignored by non-curl transports, which fall
        // back to the host allowlist.
        'curl' => $this->pinnedCurl($url),
      ]);
    }
    catch (GuzzleException $e) {
      $this->logger->info('Validation fetch failed for @url: @m', ['@url' => $url, '@m' => $e->getMessage()]);
      return FALSE;
    }

    if ($response->getStatusCode() !== 200) {
      return FALSE;
    }

    $body = $this->readCapped($response->getBody());
    if ($this->isNoindex($response, $body)) {
      return FALSE;
    }
    return $this->isSelfCanonical($url, $body);
  }

  /**
   * Whether a URL is allowed to be fetched at all (allowlist + no internal IP).
   */
  public function isSafeUrl(string $url): bool {
    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
    $host = (string) parse_url($url, PHP_URL_HOST);
    if (!in_array($scheme, ['http', 'https'], TRUE) || $host === '') {
      return FALSE;
    }

    // Allowlist: the host must be one of the family's market hosts (covers both
    // path-prefix markets and separate-domain markets).
    if (!in_array(strtolower($host), $this->marketRegistry->allowedHosts(), TRUE)) {
      return FALSE;
    }

    // Resolve and reject private/reserved ranges (defense in depth against
    // DNS rebinding / internal targets).
    foreach ($this->resolveIps($host) as $ip) {
      if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Curl options that pin the request to a freshly vetted public IP.
   *
   * Returns [CURLOPT_RESOLVE => ["host:443:ip", "host:80:ip"]] for the first
   * public IP the host resolves to, or [] when there is nothing to pin (a
   * literal-IP host, curl unavailable, or - defensively - no public IP, in
   * which case isSafeUrl already refused the fetch).
   *
   * @return array<int,mixed>
   */
  private function pinnedCurl(string $url): array {
    if (!\extension_loaded('curl')) {
      return [];
    }
    $host = (string) parse_url($url, PHP_URL_HOST);
    if ($host === '' || filter_var($host, FILTER_VALIDATE_IP)) {
      return [];
    }
    foreach ($this->resolveIps($host) as $ip) {
      if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        return [CURLOPT_RESOLVE => [$host . ':443:' . $ip, $host . ':80:' . $ip]];
      }
    }
    return [];
  }

  /**
   * Resolve a host to its A/AAAA addresses; empty result blocks the fetch.
   *
   * @return string[]
   */
  private function resolveIps(string $host): array {
    // A literal IP host is validated directly.
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
    // If resolution yields nothing, fail closed by returning an unroutable
    // sentinel so the private-range check rejects it.
    return $ips ?: ['0.0.0.0'];
  }

  /**
   * Read at most MAX_BODY_BYTES from a PSR-7 stream.
   */
  private function readCapped($body): string {
    $read = '';
    while (!$body->eof() && strlen($read) < self::MAX_BODY_BYTES) {
      $chunk = $body->read(8192);
      if ($chunk === '') {
        break;
      }
      $read .= $chunk;
    }
    return $read;
  }

  /**
   * Whether the response asks not to be indexed (header or meta robots).
   */
  private function isNoindex($response, string $body): bool {
    $header = strtolower(implode(' ', $response->getHeader('X-Robots-Tag')));
    if (str_contains($header, 'noindex')) {
      return TRUE;
    }
    return (bool) preg_match('/<meta[^>]+name=["\']robots["\'][^>]+content=["\'][^"\']*noindex/i', $body);
  }

  /**
   * Whether the page's canonical link points at itself (or is absent).
   *
   * A page that canonicalizes elsewhere must not be advertised as an alternate.
   */
  private function isSelfCanonical(string $url, string $body): bool {
    if (!preg_match('/<link[^>]+rel=["\']canonical["\'][^>]+href=["\']([^"\']+)["\']/i', $body, $m)) {
      // No explicit canonical: treat the URL as self-canonical.
      return TRUE;
    }
    return $this->normalizeUrl($m[1]) === $this->normalizeUrl($url);
  }

  /**
   * Normalize a URL for canonical comparison (drop fragment + trailing slash).
   */
  private function normalizeUrl(string $url): string {
    $url = strtok($url, '#');
    return rtrim($url, '/');
  }

}
