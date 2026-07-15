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
    ]);
    try {
      $this->httpClient->request('POST', $url, [
        'body' => $body,
        'headers' => ['Content-Type' => 'application/json'] + $this->signer->headers('POST', $this->pathOf($url), $body),
        'timeout' => 30,
      ]);
    }
    catch (GuzzleException $e) {
      $this->logger->error('Inventory publish failed: @m', ['@m' => $e->getMessage()]);
    }
  }

  /**
   * Pull the resolved alternates for this backend's market.
   *
   * @return array
   *   Decoded serve payload: ['market' => .., 'pages' => [...]].
   */
  public function pullAlternates(): array {
    $config = $this->configFactory->get('hrefl_client.settings');
    $base = rtrim((string) $config->get('hub_base_url'), '/');
    $url = $base . '/alternates';
    try {
      $response = $this->httpClient->request('GET', $url, [
        'query' => ['market' => $config->get('market')],
        // GET has no body; the signature covers method + path + timestamp.
        'headers' => $this->signer->headers('GET', $this->pathOf($url), ''),
        'timeout' => 30,
      ]);
      $data = json_decode((string) $response->getBody(), TRUE);
      return is_array($data) ? $data : [];
    }
    catch (GuzzleException $e) {
      $this->logger->error('Alternates pull failed: @m', ['@m' => $e->getMessage()]);
      return [];
    }
  }

  /**
   * The path portion of a URL, as the hub's signature check sees it.
   */
  private function pathOf(string $url): string {
    return (string) parse_url($url, PHP_URL_PATH);
  }

}
