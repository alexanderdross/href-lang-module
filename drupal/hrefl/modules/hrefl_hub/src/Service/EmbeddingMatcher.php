<?php

declare(strict_types=1);

namespace Drupal\hrefl_hub\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\hrefl_hub\Plugin\HreflEmbedding\EmbeddingProviderManager;
use Psr\Log\LoggerInterface;

/**
 * Tier B: generate cross-market candidate equivalents via embeddings.
 *
 * Embeds a page's text (cached per content version), stores the vector, and
 * finds the nearest pages in *other* markets. The resulting candidates are what
 * Tier C (the LLM) adjudicates — embeddings are the workhorse that surfaces
 * `à-propos ≈ about-us` without a glossary and without an LLM call per pair.
 *
 * The provider is inert until an endpoint is configured (like the AI matchers),
 * so this is a no-op that returns no candidates when Tier B is not set up.
 */
final class EmbeddingMatcher {

  public function __construct(
    private readonly EmbeddingProviderManager $providerManager,
    private readonly VectorStore $vectorStore,
    private readonly Registry $registry,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Candidate equivalent records for a source member (for Tier C).
   *
   * @return array
   *   List of normalized candidate records (url, market, language, title).
   */
  public function candidatesFor(array $member, ?int $topK = NULL, ?float $threshold = NULL): array {
    $provider = $this->activeProvider();
    if (!$provider) {
      return [];
    }
    $vectors = $provider->embed([$this->embedText($member)]);
    if (!$vectors) {
      return [];
    }
    $embedding = (array) $this->configFactory->get('hrefl_hub.settings')->get('embedding');
    $topK ??= (int) ($embedding['top_k'] ?? 5);
    $threshold ??= (float) ($embedding['threshold'] ?? 0.82);

    $candidates = [];
    foreach ($this->vectorStore->nearest($vectors[0], (string) $member['market'], $topK, $threshold) as $hit) {
      $peer = $this->registry->memberByUrl($hit['url']);
      if (!$peer) {
        continue;
      }
      $candidates[] = [
        'url' => (string) $peer['url'],
        'market' => (string) $peer['market'],
        'language' => (string) $peer['language'],
        'title' => (string) ($peer['title'] ?? ''),
        'embedding_score' => $hit['score'],
      ];
    }
    return $candidates;
  }

  /**
   * Embed a member if its text changed; returns TRUE if a vector is now stored.
   */
  public function ensureEmbedded(array $member): bool {
    $provider = $this->activeProvider();
    if (!$provider) {
      return FALSE;
    }
    $url = (string) $member['url'];
    $text = $this->embedText($member);
    if ($text === '') {
      return FALSE;
    }
    $hash = hash('sha256', $text);
    if ($this->vectorStore->contentHashFor($url) === $hash) {
      return TRUE;
    }
    $vectors = $provider->embed([$text]);
    if (!$vectors) {
      return FALSE;
    }
    $this->vectorStore->upsert($url, (string) $member['market'], (string) $member['language'], $hash, $vectors[0]);
    return TRUE;
  }

  /**
   * Embed a bounded batch of members (cron warm-up). Returns count embedded.
   */
  public function embedPass(int $limit = 200): int {
    if (!$this->activeProvider()) {
      return 0;
    }
    $embedded = 0;
    foreach ($this->registry->allMembers($limit) as $member) {
      if ($this->ensureEmbedded($member)) {
        $embedded++;
      }
    }
    return $embedded;
  }

  /**
   * The text embedded for a member: title plus its slug.
   */
  private function embedText(array $member): string {
    return trim(((string) ($member['title'] ?? '')) . ' ' . ((string) ($member['path_key'] ?? '')));
  }

  /**
   * The configured, ready embedding provider, or NULL.
   */
  private function activeProvider() {
    $id = (string) ($this->configFactory->get('hrefl_hub.settings')->get('embedding')['provider'] ?? 'http');
    try {
      /** @var \Drupal\hrefl_hub\Plugin\HreflEmbedding\EmbeddingProviderInterface $provider */
      $provider = $this->providerManager->createInstance($id);
      return $provider->isConfigured() ? $provider : NULL;
    }
    catch (\Throwable $e) {
      $this->logger->warning('Embedding provider @id unavailable: @m', ['@id' => $id, '@m' => $e->getMessage()]);
      return NULL;
    }
  }

}
