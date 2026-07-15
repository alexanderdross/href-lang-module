<?php

declare(strict_types=1);

namespace Drupal\hrefl_client\Service;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Pulls resolved alternates from the hub and writes the local store.
 *
 * Runs on cron. It merges the hub's cross-backend alternates with the self
 * entry, validates the set against the correctness rules, then swaps the local
 * store atomically. The same ingest path backs the Phase 0 seed command, so a
 * hand-authored mapping is stored exactly as a hub pull would be.
 */
final class AlternatesConsumer {

  public function __construct(
    private readonly HubClient $hubClient,
    private readonly AlternatesStore $store,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly HreflangValidator $validator,
  ) {}

  /**
   * Pull from the hub and persist. Returns the number of pages stored.
   */
  public function pull(): int {
    return $this->ingestPayload($this->hubClient->pullAlternates());
  }

  /**
   * Persist a serve-shaped payload into the local store.
   *
   * Shared by the cron pull and the Phase 0 seed command. Shape:
   * `{ pages: [ { url, group_uuid?, alternates: [ { hreflang, href } ] } ] }`.
   *
   * @param array $payload
   *   Decoded serve payload.
   *
   * @return int
   *   Number of pages stored.
   */
  public function ingestPayload(array $payload): int {
    $pages = $payload['pages'] ?? [];
    if (!is_array($pages) || !$pages) {
      return 0;
    }

    $prepared = [];
    foreach ($pages as $page) {
      $url = (string) ($page['url'] ?? '');
      $alternates = $page['alternates'] ?? [];
      if ($url === '' || !is_array($alternates) || !$alternates) {
        continue;
      }
      $alternates = $this->ensureSelf($url, $alternates);
      $alternates = $this->validator->clean($alternates);
      if (!$alternates) {
        continue;
      }
      $prepared[] = [
        'url' => $url,
        'group_uuid' => $page['group_uuid'] ?? NULL,
        'alternates' => $alternates,
        'lastmod' => $page['lastmod'] ?? NULL,
      ];
    }
    $this->store->replaceAll($prepared);
    return count($prepared);
  }

  /**
   * Guarantee a self-referencing entry is present (Google requires it).
   */
  private function ensureSelf(string $url, array $alternates): array {
    foreach ($alternates as $alt) {
      if (($alt['href'] ?? '') === $url) {
        return $alternates;
      }
    }
    // Derive this page's own hreflang from the market's default if the hub did
    // not include a self entry.
    $config = $this->configFactory->get('hrefl_client.settings');
    $map = (array) $config->get('hreflang_map');
    $self = ['hreflang' => reset($map) ?: 'en', 'href' => $url];
    array_unshift($alternates, $self);
    return $alternates;
  }

}
