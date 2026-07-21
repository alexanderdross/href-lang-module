<?php

declare(strict_types=1);

namespace Drupal\hrefl_hub\Service;

/**
 * Pre-computes the per-URL resolved alternate set served to clients.
 *
 * Output is confirmed + valid only, absolute, with one x-default per group.
 * Clients cache this verbatim; page render never recomputes reciprocity.
 */
final class Distributor {

  /**
   * Default serve page size (confirmed members scanned per request).
   */
  public const PAGE_SIZE = 500;

  public function __construct(
    private readonly Registry $registry,
  ) {}

  /**
   * Build the resolved alternates for every confirmed URL in a market.
   *
   * Convenience wrapper that walks all pages; prefer servePage() on the request
   * path so a large market never lands in one response.
   *
   * @return array
   *   List of ['url' => string, 'alternates' => [['hreflang' => .., 'href' => ..], ...]].
   */
  public function alternatesForMarket(string $market): array {
    $pages = [];
    $after = 0;
    do {
      $batch = $this->servePage($market, $after, self::PAGE_SIZE);
      $pages = array_merge($pages, $batch['pages']);
      $after = $batch['next'];
    } while ($after !== NULL);
    return $pages;
  }

  /**
   * One cursor page of resolved alternates for a market.
   *
   * @param string $market
   *   The requesting market.
   * @param int $afterId
   *   Return members with a serial id greater than this (0 for the first page).
   * @param int $limit
   *   Maximum confirmed members to scan this page.
   *
   * @return array{pages: array, next: ?int}
   *   The built pages and the cursor for the next request, or NULL when the
   *   market is exhausted. The cursor is the last member *scanned* (not the
   *   last emitted), so self-only groups that are skipped still advance it.
   */
  public function servePage(string $market, int $afterId = 0, int $limit = self::PAGE_SIZE): array {
    $limit = max(1, $limit);
    $members = $this->registry->confirmedMembersForMarket($market, $afterId, $limit);
    $pages = [];
    $seenGroups = [];
    $lastId = NULL;
    foreach ($members as $member) {
      $lastId = (int) $member['id'];
      $groupUuid = $member['group_uuid'];
      // Resolve the group's alternate set once, reuse for each member page.
      if (!isset($seenGroups[$groupUuid])) {
        $seenGroups[$groupUuid] = $this->resolveGroup($groupUuid);
      }
      $alternates = $seenGroups[$groupUuid];
      if (count($alternates) <= 1) {
        // Only a self entry: nothing cross-market to advertise yet.
        continue;
      }
      $pages[] = [
        'url' => $member['url'],
        'alternates' => $alternates,
        // Feeds the client sitemap's <lastmod> (content change time).
        'lastmod' => $member['source_changed'] !== NULL ? (int) $member['source_changed'] : NULL,
      ];
    }
    return ['pages' => $pages, 'next' => self::nextCursor(count($members), $limit, $lastId)];
  }

  /**
   * The cursor for the next serve page, or NULL when the market is exhausted.
   *
   * A full batch (scanned === limit) means there may be more, so page from the
   * last id seen; a short batch is the final page. Pure for testability.
   */
  public static function nextCursor(int $scanned, int $limit, ?int $lastId): ?int {
    return ($scanned === $limit && $lastId !== NULL) ? $lastId : NULL;
  }

  /**
   * Resolve one group's alternates (confirmed + valid members + x-default).
   */
  private function resolveGroup(string $groupUuid): array {
    $alternates = [];
    $xDefaultHref = NULL;
    foreach ($this->registry->membersOfGroup($groupUuid) as $member) {
      if ($member['status'] !== 'confirmed' || (int) $member['valid'] !== 1) {
        continue;
      }
      $alternates[] = [
        'hreflang' => $member['hreflang'],
        'href' => $member['url'],
      ];
      // Convention: the group's designated x-default, else the Global market.
      if ($member['market'] === 'global' && $xDefaultHref === NULL) {
        $xDefaultHref = $member['url'];
      }
    }
    if ($xDefaultHref !== NULL) {
      $alternates[] = ['hreflang' => 'x-default', 'href' => $xDefaultHref];
    }
    return $alternates;
  }

}
