<?php

declare(strict_types=1);

namespace Drupal\hrefl_client\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Signs outbound hub requests with this backend's shared HMAC secret.
 *
 * Must stay byte-for-byte in sync with the hub's SignedRequestAccessCheck:
 *   canonical = METHOD \n PATH \n QUERY \n TIMESTAMP \n sha256(body)
 *   X-Hrefl-Signature = HMAC-SHA256(canonical, secret)
 * QUERY is the query parameters key-sorted and re-encoded with
 * http_build_query() ('' when there are none), so both sides derive the same
 * bytes regardless of original parameter order. The secret comes from the key
 * module (by `hub_key_name`) or the HREFL_HUB_SECRET environment variable for
 * local development.
 */
final class RequestSigner {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly TimeInterface $time,
    // \Drupal\key\KeyRepositoryInterface when the optional key module is
    // installed, otherwise NULL.
    private readonly mixed $keyRepository = NULL,
  ) {}

  /**
   * Signed auth headers for a request, or [] when no secret is configured.
   *
   * @param string $method
   *   HTTP method.
   * @param string $path
   *   Request path (no host, no query), as the hub sees it.
   * @param string $body
   *   The exact request body bytes (empty for GET).
   * @param array $query
   *   Query parameters, exactly as sent (they are covered by the signature).
   *
   * @return array
   *   The X-Hrefl-* headers, or [] when market/secret are not configured.
   */
  public function headers(string $method, string $path, string $body, array $query = []): array {
    $config = $this->configFactory->get('hrefl_client.settings');
    $market = (string) $config->get('market');
    $secret = $this->secret($config);
    if ($market === '' || $secret === '') {
      return [];
    }
    $timestamp = $this->time->getRequestTime();
    $canonical = implode("\n", [
      strtoupper($method),
      $path,
      self::canonicalQuery($query),
      (string) $timestamp,
      hash('sha256', $body),
    ]);
    return [
      'X-Hrefl-Market' => $market,
      'X-Hrefl-Timestamp' => (string) $timestamp,
      'X-Hrefl-Signature' => hash_hmac('sha256', $canonical, $secret),
    ];
  }

  /**
   * The canonical (key-sorted, re-encoded) form of a query parameter set.
   */
  public static function canonicalQuery(array $query): string {
    ksort($query);
    return http_build_query($query);
  }

  /**
   * Resolve the shared secret (key module, then env fallback).
   */
  private function secret($config): string {
    $keyName = (string) $config->get('hub_key_name');
    if ($keyName !== '' && is_object($this->keyRepository) && method_exists($this->keyRepository, 'getKey')) {
      $key = $this->keyRepository->getKey($keyName);
      if (is_object($key) && method_exists($key, 'getKeyValue')) {
        return (string) $key->getKeyValue();
      }
    }
    $env = getenv('HREFL_HUB_SECRET');
    return is_string($env) ? $env : '';
  }

}
