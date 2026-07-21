<?php

declare(strict_types=1);

namespace Drupal\hrefl_hub\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\hrefl_hub\Plugin\HreflAiMatcher\AiMatcherManager;
use Psr\Log\LoggerInterface;

/**
 * Orchestrates the tiered auto-mapping pipeline.
 *
 * Tier A (deterministic) -> Tier B (embeddings, extension point) ->
 * Tier C (LLM adjudication). Score fusion picks a confidence tier which the
 * router turns into auto-confirm / review / hold. See docs/AUTOMATION.md.
 */
final class MappingEngine {

  public function __construct(
    private readonly Registry $registry,
    private readonly DeterministicMatcher $deterministic,
    private readonly AiMatcherManager $matcherManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerInterface $logger,
    private readonly EmbeddingMatcher $embeddingMatcher,
  ) {}

  /**
   * Match a single record and route the result into the registry.
   *
   * @param array $record
   *   Normalized inventory record (must include url, market, language,
   *   hreflang).
   * @param array $signals
   *   Identity signals for Tier A (see DeterministicMatcher::resolve()).
   * @param array $candidates
   *   Optional pre-computed Tier B candidates (normalized records). In a full
   *   build these come from the embedding/ANN search; passing them here keeps
   *   the engine testable and the vector store pluggable.
   */
  public function match(array $record, array $signals = [], array $candidates = []): void {
    $settings = $this->configFactory->get('hrefl_hub.settings');
    $confirm = (float) $settings->get('thresholds.confirm');
    $floor = (float) $settings->get('thresholds.floor');
    $autoConfirm = (bool) $settings->get('auto_confirm_enabled');

    // Tier A: deterministic. Honor the matcher's own provenance/confidence - an
    // identity key match is 1.0 (auto-confirmable), a URL-pattern match is lower
    // and routes to review.
    $a = $this->deterministic->resolve($record, $signals);
    if ($a['group_uuid']) {
      $this->place($record, $a['group_uuid'], $a['matched_by'] ?? 'key', $a['confidence'], $a['signals']);
      $this->route($record['url'], $a['confidence'], $confirm, $floor, $autoConfirm);
      return;
    }

    // Tier B: embeddings. If the caller did not pre-supply candidates, ask the
    // embedding matcher for semantic neighbours in other markets. This is a
    // no-op (returns []) until an embedding provider is configured.
    if (!$candidates) {
      $candidates = $this->embeddingMatcher->candidatesFor($record);
    }
    // Still nothing confident to escalate: hold for review.
    if (!$candidates) {
      $this->placeUnmatched($record);
      $this->route($record['url'], 0.0, $confirm, $floor, $autoConfirm);
      return;
    }

    // Tier C: LLM adjudication over the Tier B candidates.
    $provider = $this->activeProvider();
    if (!$provider) {
      $this->placeUnmatched($record);
      $this->route($record['url'], 0.0, $confirm, $floor, $autoConfirm);
      return;
    }
    $verdict = $provider->adjudicate($record, $candidates);
    if ($verdict['choice'] === NULL) {
      $this->placeUnmatched($record);
      $this->route($record['url'], $verdict['confidence'], $confirm, $floor, $autoConfirm);
      return;
    }

    $peer = $candidates[$verdict['choice']];
    $uuid = $this->registry->groupForUrl($peer['url']) ?? $this->registry->createGroup();
    $this->place($record, $uuid, 'llm', $verdict['confidence'], [
      'llm' => $verdict['confidence'],
      'rationale' => $verdict['rationale'],
    ]);
    $this->route($record['url'], $verdict['confidence'], $confirm, $floor, $autoConfirm);
  }

  /**
   * Place a record into a group with the given provenance.
   */
  private function place(array $record, string $groupUuid, string $matchedBy, float $confidence, array $signals): void {
    $this->registry->upsertMember([
      'group_uuid' => $groupUuid,
      'market' => $record['market'],
      'language' => $record['language'],
      'hreflang' => $record['hreflang'],
      'url' => $record['url'],
      'entity_type' => $record['entity_type'] ?? NULL,
      'entity_id' => $record['entity_id'] ?? NULL,
      'matched_by' => $matchedBy,
      'confidence' => $confidence,
      'signals' => $signals,
      'asserted_by' => $record['market'],
      'source_changed' => $record['changed'] ?? NULL,
      'valid' => (int) ($record['valid'] ?? 0),
      'status' => 'proposed',
      '_via' => 'automation',
    ]);
  }

  /**
   * Place a record in its own single-member group (no confident peer).
   */
  private function placeUnmatched(array $record): void {
    $uuid = $this->registry->groupForUrl($record['url']) ?? $this->registry->createGroup();
    $this->place($record, $uuid, 'manual', 0.0, ['unmatched' => TRUE]);
  }

  /**
   * Confidence router: auto-confirm / review / hold.
   */
  private function route(string $url, float $confidence, float $confirm, float $floor, bool $autoConfirm): void {
    $memberId = $this->registry->memberIdForUrl($url);
    if ($memberId === NULL) {
      return;
    }
    if ($autoConfirm && $confidence >= $confirm) {
      $this->registry->setStatus($memberId, 'confirmed', 'engine');
    }
    elseif ($confidence < $floor) {
      $this->registry->setStatus($memberId, 'held', 'engine');
    }
    else {
      $this->registry->setStatus($memberId, 'proposed', 'engine');
    }
  }

  /**
   * The configured, ready provider, or NULL.
   */
  private function activeProvider() {
    // Falls back to the install default when unset; kept in sync with
    // TranslationProposer so both call sites resolve the same provider.
    $id = $this->configFactory->get('hrefl_hub.settings')->get('ai_matcher.provider') ?: 'copilot';
    try {
      /** @var \Drupal\hrefl_hub\Plugin\HreflAiMatcher\AiMatcherInterface $provider */
      $provider = $this->matcherManager->createInstance($id);
      return $provider->isConfigured() ? $provider : NULL;
    }
    catch (\Throwable $e) {
      $this->logger->warning('AI matcher @id unavailable: @m', ['@id' => $id, '@m' => $e->getMessage()]);
      return NULL;
    }
  }

}
