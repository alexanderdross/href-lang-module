<?php

declare(strict_types=1);

namespace Drupal\hrefl_hub\Access;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\hrefl_hub\Service\MarketRegistry;
use Symfony\Component\HttpFoundation\Request;

/**
 * Access check for HMAC-signed service-to-service requests (`_hrefl_signed`).
 *
 * A client signs each request with its market's shared secret:
 *   canonical = METHOD \n PATH \n QUERY \n TIMESTAMP \n sha256(body)
 *   X-Hrefl-Signature = HMAC-SHA256(canonical, secret)
 * plus X-Hrefl-Market and X-Hrefl-Timestamp headers. QUERY is the query
 * parameters key-sorted and re-encoded with http_build_query() ('' when there
 * are none), matching the client's RequestSigner byte for byte. The hub
 * recomputes the signature with that market's secret and compares in constant
 * time, and rejects stale timestamps to bound replay. Fails closed: any
 * missing/invalid element denies access.
 *
 * Replays of an identical request within the timestamp window are accepted
 * (there is no nonce store); since the query and body are signed, a replay
 * cannot be redirected at other data, only repeat an idempotent operation.
 */
final class SignedRequestAccessCheck implements AccessInterface {

  /**
   * Maximum accepted clock skew / replay window, in seconds.
   */
  private const MAX_SKEW = 300;

  public function __construct(
    private readonly MarketRegistry $marketRegistry,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Grant access only to a validly signed request.
   */
  public function access(Request $request): AccessResultInterface {
    $ok = $this->verify($request);
    // Signature depends on request headers; never cache the decision.
    return ($ok ? AccessResult::allowed() : AccessResult::forbidden('invalid or missing request signature'))
      ->setCacheMaxAge(0);
  }

  /**
   * Verify the signature, market secret and timestamp freshness.
   */
  private function verify(Request $request): bool {
    $market = (string) $request->headers->get('X-Hrefl-Market', '');
    $timestamp = (int) $request->headers->get('X-Hrefl-Timestamp', '0');
    $signature = (string) $request->headers->get('X-Hrefl-Signature', '');
    if ($market === '' || $signature === '' || $timestamp === 0) {
      return FALSE;
    }
    if (abs($this->time->getRequestTime() - $timestamp) > self::MAX_SKEW) {
      return FALSE;
    }
    $secret = $this->marketRegistry->secretFor($market);
    if ($secret === '') {
      return FALSE;
    }
    $canonical = implode("\n", [
      strtoupper($request->getMethod()),
      $request->getPathInfo(),
      self::canonicalQuery($request),
      (string) $timestamp,
      hash('sha256', (string) $request->getContent()),
    ]);
    $expected = hash_hmac('sha256', $canonical, $secret);
    return hash_equals($expected, $signature);
  }

  /**
   * The canonical (key-sorted, re-encoded) form of the request's query string.
   *
   * Must produce the same bytes as RequestSigner::canonicalQuery() on the
   * client for the same parameter set.
   */
  private static function canonicalQuery(Request $request): string {
    $params = $request->query->all();
    ksort($params);
    return http_build_query($params);
  }

}
