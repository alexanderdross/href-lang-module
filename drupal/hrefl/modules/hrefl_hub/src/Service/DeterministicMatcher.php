<?php

declare(strict_types=1);

namespace Drupal\hrefl_hub\Service;

/**
 * Tier A: cheap, exact, explainable matching.
 *
 * Resolves equivalence from strong signals before any embedding or LLM work:
 * a shared content id, schema.org relationships, an already-declared hreflang,
 * or a learned slug-glossary hit. A hit here is confidence 1.0.
 */
final class DeterministicMatcher {

  /**
   * Confidence for a URL-pattern (slug/glossary) match.
   *
   * Lower than an identity key match (1.0) so it routes to review rather than
   * auto-confirm: a shared slug is strong evidence but not proof of equivalence.
   */
  private const PATTERN_CONFIDENCE = 0.7;

  public function __construct(
    private readonly Registry $registry,
    private readonly SlugNormalizer $slug,
  ) {}

  /**
   * Try to resolve the group a record belongs to using deterministic signals.
   *
   * @param array $record
   *   Normalized inventory record.
   * @param array $signals
   *   Extra identity signals: global_content_id, schema_id, same_as (array),
   *   existing_hreflang (array of [hreflang => url]).
   *
   * @return array{group_uuid: ?string, matched_by: ?string, confidence: float, signals: array}
   */
  public function resolve(array $record, array $signals = []): array {
    // 1. Shared, explicit content id (strongest).
    foreach (['global_content_id', 'schema_id'] as $key) {
      if (!empty($signals[$key])) {
        $uuid = $this->groupByPeerUrl($this->urlForIdentity($key, $signals[$key]));
        if ($uuid) {
          return $this->hit($uuid, 'key', ['identity' => $key]);
        }
      }
    }

    // 2. schema.org sameAs / translationOfWork pointing at a known peer URL.
    foreach ((array) ($signals['same_as'] ?? []) as $peer) {
      $uuid = $this->registry->groupForUrl($peer);
      if ($uuid) {
        return $this->hit($uuid, 'schema', ['same_as' => $peer]);
      }
    }

    // 3. Existing hreflang already declared on the page.
    foreach ((array) ($signals['existing_hreflang'] ?? []) as $url) {
      $uuid = $this->registry->groupForUrl($url);
      if ($uuid) {
        return $this->hit($uuid, 'key', ['existing_hreflang' => $url]);
      }
    }

    // 4. URL-pattern match: identical leaf slug across markets, bridged by the
    // learned glossary for cross-language slugs (about-us <-> ueber-uns).
    // The glossary is grown from confirmations (docs/AUTOMATION.md §7), so this
    // tier gets stronger over time.
    $patternHit = $this->resolveByPattern($record);
    if ($patternHit) {
      return $patternHit;
    }

    return ['group_uuid' => NULL, 'matched_by' => NULL, 'confidence' => 0.0, 'signals' => []];
  }

  /**
   * Resolve a peer group by leaf-slug + glossary, or NULL.
   *
   * Chooses a deterministic anchor group among the matching peers (prefer the
   * Global market, else the smallest group UUID) so that a set of same-slug
   * singletons converges onto one group over successive matching passes.
   */
  private function resolveByPattern(array $record): ?array {
    $market = (string) ($record['market'] ?? '');
    $lang = (string) ($record['language'] ?? '');
    $slug = $this->slug->slug((string) ($record['url'] ?? ''));
    if ($slug === '' || $market === '') {
      return NULL;
    }

    $candidateSlugs = [$slug, ...$this->registry->glossaryEquivalents($lang, $slug)];
    $peers = $this->registry->membersBySlug($candidateSlugs, $market);
    if (!$peers) {
      return NULL;
    }

    $anchor = NULL;
    foreach ($peers as $peer) {
      if ($peer['market'] === 'global') {
        $anchor = $peer;
        break;
      }
      if ($anchor === NULL || strcmp((string) $peer['group_uuid'], (string) $anchor['group_uuid']) < 0) {
        $anchor = $peer;
      }
    }

    return [
      'group_uuid' => (string) $anchor['group_uuid'],
      'matched_by' => 'glossary',
      'confidence' => self::PATTERN_CONFIDENCE,
      'signals' => [
        'glossary' => self::PATTERN_CONFIDENCE,
        'slug' => $slug,
        'matched_slug' => $anchor['path_key'] ?? $slug,
      ],
    ];
  }

  private function hit(string $uuid, string $matchedBy, array $signals): array {
    return [
      'group_uuid' => $uuid,
      'matched_by' => $matchedBy,
      'confidence' => 1.0,
      'signals' => $signals + [$matchedBy => 1.0],
    ];
  }

  /**
   * Placeholder resolver for an identity index (content id -> a peer URL).
   *
   * In a full build this reads a small identity index table; here it returns an
   * empty string so the caller falls through to the next signal.
   */
  private function urlForIdentity(string $key, string $value): string {
    return '';
  }

  private function groupByPeerUrl(string $url): ?string {
    return $url ? $this->registry->groupForUrl($url) : NULL;
  }

}
