<?php

declare(strict_types=1);

namespace Drupal\hrefl_hub\Service;

use Drupal\Component\Datetime\TimeInterface;

/**
 * Continuously validates the translation graph and reports its health.
 *
 * Reciprocity is structural (a shared group), so the Monitor is belt-and-braces
 * for everything else: coverage, targets that failed validation, hreflang code
 * collisions inside a confirmed group, groups with no x-default, confirmed
 * members with nothing to link to, and stale validation. It only reads; fixing
 * is the editor's job via the review queue / CSV.
 */
final class Monitor {

  /**
   * Confirmed members re-validated longer ago than this are "stale".
   */
  private const STALE_AFTER = 604800;

  public function __construct(
    private readonly Registry $registry,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Build the health report consumed by the dashboard.
   */
  public function report(): array {
    $statusCounts = $this->registry->statusCounts();
    $confirmed = $this->registry->allConfirmedMembers();
    $staleBefore = $this->time->getRequestTime() - self::STALE_AFTER;

    $invalidTargets = [];
    $staleValidation = 0;
    $byGroup = [];
    foreach ($confirmed as $m) {
      if ((int) $m['valid'] !== 1) {
        $invalidTargets[] = ['url' => $m['url'], 'market' => $m['market'], 'hreflang' => $m['hreflang']];
      }
      if ($m['last_validated'] === NULL || (int) $m['last_validated'] < $staleBefore) {
        $staleValidation++;
      }
      $byGroup[$m['group_uuid']][] = $m;
    }

    $codeCollisions = [];
    $missingXDefault = [];
    $lonelyConfirmed = [];
    foreach ($byGroup as $groupUuid => $members) {
      $this->collectCodeCollisions($groupUuid, $members, $codeCollisions);
      if (!$this->hasGlobalMember($members) && count($members) >= 2) {
        $missingXDefault[] = $groupUuid;
      }
      if (count($members) === 1) {
        $lonelyConfirmed[] = ['group_uuid' => $groupUuid, 'url' => $members[0]['url']];
      }
    }

    $total = array_sum($statusCounts);
    $eligible = $total - ($statusCounts['rejected'] ?? 0);
    $confirmedValid = count($confirmed) - count($invalidTargets);

    return [
      'totals' => [
        'groups' => $this->registry->countGroups(),
        'members' => $total,
        'confirmed' => $statusCounts['confirmed'] ?? 0,
        'proposed' => $statusCounts['proposed'] ?? 0,
        'held' => $statusCounts['held'] ?? 0,
        'rejected' => $statusCounts['rejected'] ?? 0,
      ],
      'coverage' => $eligible > 0 ? round($confirmedValid / $eligible, 4) : 0.0,
      'issues' => [
        'invalid_targets' => $invalidTargets,
        'code_collisions' => array_values($codeCollisions),
        'missing_x_default' => $missingXDefault,
        'lonely_confirmed' => $lonelyConfirmed,
        'stale_validation' => $staleValidation,
      ],
      'healthy' => !$invalidTargets && !$codeCollisions && !$missingXDefault && !$lonelyConfirmed,
    ];
  }

  /**
   * Record any hreflang code used by more than one confirmed member in a group.
   */
  private function collectCodeCollisions(string $groupUuid, array $members, array &$out): void {
    $byCode = [];
    foreach ($members as $m) {
      $byCode[$m['hreflang']][] = $m['url'];
    }
    foreach ($byCode as $code => $urls) {
      if (count($urls) > 1) {
        $out[] = ['group_uuid' => $groupUuid, 'hreflang' => $code, 'urls' => $urls];
      }
    }
  }

  /**
   * Whether a group has a confirmed Global member (the x-default by convention).
   */
  private function hasGlobalMember(array $members): bool {
    foreach ($members as $m) {
      if ($m['market'] === 'global') {
        return TRUE;
      }
    }
    return FALSE;
  }

}
