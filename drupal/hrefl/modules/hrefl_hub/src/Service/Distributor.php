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

  public function __construct(
    private readonly Registry $registry,
  ) {}

  /**
   * Build the resolved alternates for every confirmed URL in a market.
   *
   * @return array
   *   List of ['url' => string, 'alternates' => [['hreflang' => .., 'href' => ..], ...]].
   */
  public function alternatesForMarket(string $market): array {
    $pages = [];
    $seenGroups = [];
    foreach ($this->registry->confirmedMembersForMarket($market) as $member) {
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
    return $pages;
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
