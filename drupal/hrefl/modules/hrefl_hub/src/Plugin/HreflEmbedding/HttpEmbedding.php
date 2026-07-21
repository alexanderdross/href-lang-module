<?php

declare(strict_types=1);

namespace Drupal\hrefl_hub\Plugin\HreflEmbedding;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\hrefl_hub\Attribute\HreflEmbeddingProvider;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Generic HTTP embedding provider.
 *
 * Works against a self-hosted multilingual embedding server (preferred, keeps
 * content in-house) or an approved Azure/OpenAI-style endpoint. Expects the
 * common request `{model, input: [texts]}` and response
 * `{data: [{embedding: [...]}, ...]}` shape; the endpoint and model are
 * configured per environment.
 */
#[HreflEmbeddingProvider(
  id: 'http',
  label: new TranslatableMarkup('HTTP embedding endpoint'),
  description: new TranslatableMarkup('Self-hosted or Azure/OpenAI-style embeddings endpoint.'),
)]
final class HttpEmbedding extends EmbeddingProviderBase {

  /**
   * {@inheritdoc}
   */
  public function isConfigured(): bool {
    $s = $this->providerSettings();
    return !empty($s['endpoint']) && !empty($s['model']);
  }

  /**
   * {@inheritdoc}
   */
  public function embed(array $texts): array {
    $texts = array_values(array_filter($texts, static fn($t) => $t !== ''));
    if (!$texts || !$this->isConfigured()) {
      return [];
    }
    $s = $this->providerSettings();
    $headers = ['content-type' => 'application/json'];
    // Auth is optional for a self-hosted server; send a bearer if a key exists.
    $key = $this->resolveApiKey('HREFL_EMBEDDING_KEY');
    if ($key !== '') {
      $headers['authorization'] = 'Bearer ' . $key;
    }
    try {
      $response = $this->requestWithRetry('POST', $s['endpoint'], [
        'headers' => $headers,
        'json' => ['model' => $s['model'], 'input' => $texts],
        'timeout' => 30,
      ]);
      $payload = json_decode((string) $response->getBody(), TRUE);
      return $this->extractVectors($payload, count($texts));
    }
    catch (GuzzleException $e) {
      $this->logger->error('Embedding request failed: @m', ['@m' => $e->getMessage()]);
      return [];
    }
  }

  /**
   * Pull the vectors out of the response, preserving input order.
   */
  private function extractVectors(mixed $payload, int $expected): array {
    if (!is_array($payload) || !isset($payload['data']) || !is_array($payload['data'])) {
      return [];
    }
    $vectors = [];
    foreach ($payload['data'] as $item) {
      $vector = $item['embedding'] ?? NULL;
      if (!is_array($vector) || !$vector) {
        return [];
      }
      $vectors[] = array_map('floatval', $vector);
    }
    // Only trust a complete, aligned batch.
    return count($vectors) === $expected ? $vectors : [];
  }

}
