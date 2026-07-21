<?php

declare(strict_types=1);

namespace Drupal\hrefl_client\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

/**
 * HTTP transport to the hub (ingest + serve).
 *
 * In production, put a signed request / OAuth2 client-credentials layer in
 * front of these calls and store the credential via the key module.
 */
final class HubClient {

  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerInterface $logger,
    private readonly RequestSigner $signer,
  ) {}

  /**
   * Publish a batch of inventory records to the hub.
   *
   * @throws \GuzzleHttp\Exception\GuzzleException
   *   When the hub is unreachable or rejects the request, so queue-driven
   *   callers keep the item for retry instead of silently dropping it.
   */
  public function publishInventory(array $records): void {
    $config = $this->configFactory->get('hrefl_client.settings');
    $base = rtrim((string) $config->get('hub_base_url'), '/');
    $url = $base . '/inventory';
    // Serialize once so the signature covers the exact bytes we send.
    $body = json_encode([
      'market' => $config->get('market'),
      'published_at' => gmdate('c'),
      'records' => $records,
    ], JSON_THROW_ON_ERROR);
    try {
      $this->httpClient->request('POST', $url, [
        'body' => $body,
        'headers' => ['Content-Type' => 'application/json'] + $this->signer->headers('POST', $this->pathOf($url), $body),
        'timeout' => 30,
      ]);
    }
    catch (GuzzleException $e) {
      $this->logger->error('Inventory publish failed: @m', ['@m' => $e->getMessage()]);
      throw $e;
    }
  }

  /**
   * Hard cap on serve pages walked in one pull, so a misbehaving or malicious
   * `next` cursor cannot loop forever (500 pages x 500 rows = 250k URLs).
   */
  private const MAX_PAGES = 500;

  /**
   * Pull the resolved alternates for this backend's market.
   *
   * Walks the hub's cursor pagination (`next`) and accumulates every page, so
   * the caller still swaps the whole set atomically while neither side has to
   * build a large market in a single request.
   *
   * @return array
   *   Decoded serve payload: ['market' => .., 'pages' => [...]].
   */
  public function pullAlternates(): array {
    $config = $this->configFactory->get('hrefl_client.settings');
    $base = rtrim((string) $config->get('hub_base_url'), '/');
    $url = $base . '/alternates';
    $market = (string) $config->get('market');
    $pages = [];
    $after = 0;
    for ($i = 0; $i < self::MAX_PAGES; $i++) {
      $query = ['market' => $market];
      if ($after > 0) {
        $query['after'] = (string) $after;
      }
      try {
        // Send the canonical (key-sorted) encoding so the bytes on the wire are
        // exactly what the signature covers.
        $response = $this->httpClient->request('GET', $url, [
          'query' => RequestSigner::canonicalQuery($query),
          // GET has no body; the signature covers method + path + query + time.
          'headers' => $this->signer->headers('GET', $this->pathOf($url), '', $query),
          'timeout' => 30,
        ]);
        $data = json_decode((string) $response->getBody(), TRUE);
      }
      catch (GuzzleException $e) {
        $this->logger->error('Alternates pull failed: @m', ['@m' => $e->getMessage()]);
        // Return nothing so the consumer's empty-payload guard keeps the last
        // known-good store rather than swapping in a partial set.
        return [];
      }
      if (!is_array($data)) {
        return [];
      }
      $batch = $data['pages'] ?? [];
      if (is_array($batch)) {
        $pages = array_merge($pages, $batch);
      }
      $next = $data['next'] ?? NULL;
      if ($next === NULL || (int) $next <= $after) {
        // Exhausted, or a non-advancing cursor: stop rather than loop.
        return ['market' => $market, 'pages' => $pages];
      }
      $after = (int) $next;
    }
    $this->logger->warning('Alternates pull hit the page cap (@n); serving a partial set.', ['@n' => self::MAX_PAGES]);
    return ['market' => $market, 'pages' => $pages];
  }

  /**
   * The path portion of a URL, as the hub's signature check sees it.
   */
  private function pathOf(string $url): string {
    return (string) parse_url($url, PHP_URL_PATH);
  }

}
