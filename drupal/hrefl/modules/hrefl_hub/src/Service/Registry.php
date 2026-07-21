<?php

declare(strict_types=1);

namespace Drupal\hrefl_hub\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Uuid\UuidInterface;
use Drupal\Core\Database\Connection;

/**
 * The translation-group registry: the hub's source of truth.
 *
 * Reciprocity is guaranteed by construction: equivalence is stored once as a
 * group that every member shares, so a member can never link one-way.
 */
final class Registry {

  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
    private readonly UuidInterface $uuidService,
    private readonly SlugNormalizer $slug,
  ) {}

  /**
   * Insert or update a member row keyed by its (unique) URL.
   *
   * @return int
   *   The member id.
   */
  public function upsertMember(array $member): int {
    $now = $this->time->getRequestTime();
    $member['path_key'] = $this->slug->slug((string) ($member['url'] ?? ''));
    $existing = $this->database->select('hrefl_group_member', 'm')
      ->fields('m', ['id'])
      ->condition('url', $member['url'])
      ->execute()
      ->fetchField();

    $fields = array_intersect_key($member, array_flip([
      'group_uuid', 'market', 'language', 'hreflang', 'url', 'path_key',
      'title', 'image', 'entity_type', 'entity_id', 'status', 'matched_by',
      'confidence', 'signals', 'asserted_by', 'source_changed', 'last_validated',
      'valid', 'locked',
    ]));
    if (isset($fields['signals']) && is_array($fields['signals'])) {
      $fields['signals'] = json_encode($fields['signals']);
    }

    if ($existing) {
      $current = $this->database->select('hrefl_group_member', 'm')
        ->fields('m', ['locked', 'status'])
        ->condition('id', $existing)
        ->execute()
        ->fetchAssoc();
      $via = $member['_via'] ?? 'manual';
      // Never silently overwrite an editor-locked row via automation.
      if (!empty($current['locked']) && $via !== 'manual') {
        return (int) $existing;
      }
      // An editor decision (confirmed/rejected) survives routine re-ingest:
      // automation may refresh the row's data but not downgrade its status.
      if ($via !== 'manual' && in_array($current['status'] ?? '', ['confirmed', 'rejected'], TRUE)) {
        unset($fields['status']);
      }
      $this->database->update('hrefl_group_member')
        ->fields($fields)
        ->condition('id', $existing)
        ->execute();
      return (int) $existing;
    }

    return (int) $this->database->insert('hrefl_group_member')
      ->fields($fields + ['source_changed' => $member['source_changed'] ?? $now])
      ->execute();
  }

  /**
   * Create an empty group and return its UUID.
   */
  public function createGroup(): string {
    $uuid = $this->uuidService->generate();
    $now = $this->time->getRequestTime();
    $this->database->insert('hrefl_group')
      ->fields([
        'group_uuid' => $uuid,
        'version' => 1,
        'created' => $now,
        'updated' => $now,
      ])
      ->execute();
    return $uuid;
  }

  /**
   * Load the group UUID a URL belongs to, if any.
   */
  public function groupForUrl(string $url): ?string {
    $uuid = $this->database->select('hrefl_group_member', 'm')
      ->fields('m', ['group_uuid'])
      ->condition('url', $url)
      ->execute()
      ->fetchField();
    return $uuid ?: NULL;
  }

  /**
   * Load a single member row by id, or NULL.
   */
  public function loadMember(int $id): ?array {
    $row = $this->database->select('hrefl_group_member', 'm')
      ->fields('m')
      ->condition('id', $id)
      ->execute()
      ->fetchAssoc();
    return $row ?: NULL;
  }

  /**
   * Load a single member row by its (unique) URL, or NULL.
   */
  public function memberByUrl(string $url): ?array {
    $row = $this->database->select('hrefl_group_member', 'm')
      ->fields('m')
      ->condition('url', $url)
      ->execute()
      ->fetchAssoc();
    return $row ?: NULL;
  }

  /**
   * The member id for a URL (members are unique by URL), or NULL.
   */
  public function memberIdForUrl(string $url): ?int {
    $id = $this->database->select('hrefl_group_member', 'm')
      ->fields('m', ['id'])
      ->condition('url', $url)
      ->execute()
      ->fetchField();
    return $id !== FALSE && $id !== NULL ? (int) $id : NULL;
  }

  /**
   * Load all members of a group.
   */
  public function membersOfGroup(string $groupUuid): array {
    return $this->database->select('hrefl_group_member', 'm')
      ->fields('m')
      ->condition('group_uuid', $groupUuid)
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC);
  }

  /**
   * Set a member's review status and log the decision.
   */
  public function setStatus(int $memberId, string $status, string $actor = 'system'): void {
    $this->database->update('hrefl_group_member')
      ->fields(['status' => $status])
      ->condition('id', $memberId)
      ->execute();
    $this->logDecision(NULL, $memberId, 'status:' . $status, $actor);
  }

  /**
   * Record a decision in the feedback/audit log.
   */
  public function logDecision(?string $groupUuid, ?int $memberId, string $action, string $actor, array $signals = []): void {
    $this->database->insert('hrefl_feedback')
      ->fields([
        'group_uuid' => $groupUuid,
        'member_id' => $memberId,
        'action' => $action,
        'actor' => $actor,
        'signals' => $signals ? json_encode($signals) : NULL,
        'created' => $this->time->getRequestTime(),
      ])
      ->execute();
  }

  /**
   * Set (or clear) a member's target-validity flag and stamp the check time.
   */
  public function setValid(int $memberId, bool $valid, int $checkedAt): void {
    $this->database->update('hrefl_group_member')
      ->fields(['valid' => $valid ? 1 : 0, 'last_validated' => $checkedAt])
      ->condition('id', $memberId)
      ->execute();
  }

  /**
   * Members whose target has never been validated (or not since $before).
   *
   * @param int $limit
   *   Batch size.
   * @param int|null $before
   *   Re-check members validated before this timestamp; NULL means only ones
   *   never validated.
   */
  public function membersNeedingValidation(int $limit = 100, ?int $before = NULL): array {
    $query = $this->database->select('hrefl_group_member', 'm')->fields('m');
    if ($before === NULL) {
      $query->isNull('last_validated');
    }
    else {
      $group = $query->orConditionGroup()
        ->isNull('last_validated')
        ->condition('last_validated', $before, '<');
      $query->condition($group);
    }
    return $query->range(0, $limit)
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC);
  }

  /**
   * Member counts keyed by status (for the Monitor dashboard).
   *
   * @return array<string,int>
   */
  public function statusCounts(): array {
    $query = $this->database->select('hrefl_group_member', 'm');
    $query->addField('m', 'status');
    $query->addExpression('COUNT(*)', 'n');
    $query->groupBy('status');
    $counts = [];
    foreach ($query->execute() as $row) {
      $counts[(string) $row->status] = (int) $row->n;
    }
    return $counts;
  }

  /**
   * Total number of translation groups.
   */
  public function countGroups(): int {
    return (int) $this->database->select('hrefl_group', 'g')
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * All confirmed members across every market (for graph validation).
   */
  public function allConfirmedMembers(): array {
    return $this->database->select('hrefl_group_member', 'm')
      ->fields('m')
      ->condition('status', 'confirmed')
      ->orderBy('group_uuid')
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC);
  }

  /**
   * All confirmed, valid members for a market (used by the Distributor).
   */
  public function confirmedMembersForMarket(string $market, int $afterId = 0, int $limit = 0): array {
    // Ordered by the serial PK so a cursor (last id seen) can page the whole
    // market deterministically across serve requests, instead of loading every
    // confirmed page of a large corpus into one response.
    $query = $this->database->select('hrefl_group_member', 'm')
      ->fields('m')
      ->condition('market', $market)
      ->condition('status', 'confirmed')
      ->condition('valid', 1)
      ->orderBy('id', 'ASC');
    if ($afterId > 0) {
      $query->condition('id', $afterId, '>');
    }
    if ($limit > 0) {
      $query->range(0, $limit);
    }
    return $query->execute()->fetchAll(\PDO::FETCH_ASSOC);
  }

  /**
   * URLs that need (re)matching: proposed/held, never-matched first.
   *
   * Ordering by last_matched (NULLs first) makes the cron pass fair: every
   * member gets a turn before any member is re-matched, so a large backlog
   * cannot starve the tail or re-spend LLM calls on the same rows every run.
   */
  public function membersNeedingMatch(int $limit = 200): array {
    return $this->database->select('hrefl_group_member', 'm')
      ->fields('m')
      ->condition('status', ['proposed', 'held'], 'IN')
      ->orderBy('last_matched', 'ASC')
      ->orderBy('id', 'ASC')
      ->range(0, $limit)
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC);
  }

  /**
   * Stamp when the matching engine processed a member.
   */
  public function markMatched(int $memberId): void {
    $this->database->update('hrefl_group_member')
      ->fields(['last_matched' => $this->time->getRequestTime()])
      ->condition('id', $memberId)
      ->execute();
  }

  /**
   * Members that have no stored embedding vector yet (for the cron warm-up).
   */
  public function membersMissingEmbedding(int $limit = 200): array {
    $query = $this->database->select('hrefl_group_member', 'm')->fields('m');
    $query->leftJoin('hrefl_embedding', 'e', 'e.url = m.url');
    $query->isNull('e.url_hash');
    return $query->range(0, $limit)
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC);
  }

  /**
   * All members regardless of status, grouped for CSV export/review.
   *
   * Ordered by group so the exported CSV keeps each translation group's rows
   * together for the reviewer.
   */
  public function allMembers(int $limit = 100000): array {
    return $this->database->select('hrefl_group_member', 'm')
      ->fields('m')
      ->orderBy('group_uuid')
      ->orderBy('market')
      ->range(0, $limit)
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC);
  }

  /**
   * Members in other markets whose slug is in the given set (URL-pattern match).
   *
   * @param string[] $slugs
   *   Candidate leaf slugs (self slug plus any glossary equivalents).
   * @param string $excludeMarket
   *   The source market, excluded so a page never matches within its own market.
   */
  public function membersBySlug(array $slugs, string $excludeMarket): array {
    $slugs = array_values(array_filter(array_unique($slugs), static fn($s) => $s !== ''));
    if (!$slugs) {
      return [];
    }
    return $this->database->select('hrefl_group_member', 'm')
      ->fields('m')
      ->condition('path_key', $slugs, 'IN')
      ->condition('market', $excludeMarket, '<>')
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC);
  }

  /**
   * Distinct languages present across all members (for translation targets).
   *
   * @return string[]
   */
  public function distinctLanguages(): array {
    return $this->database->select('hrefl_group_member', 'm')
      ->distinct()
      ->fields('m', ['language'])
      ->execute()
      ->fetchCol();
  }

  /**
   * Glossary-equivalent slugs for a slug (bidirectional lookup).
   *
   * @return string[]
   */
  public function glossaryEquivalents(string $lang, string $slug): array {
    if ($slug === '') {
      return [];
    }
    $forward = $this->database->select('hrefl_glossary', 'g')
      ->fields('g', ['target_token'])
      ->condition('source_lang', $lang)
      ->condition('source_token', $slug)
      ->execute()
      ->fetchCol();
    $reverse = $this->database->select('hrefl_glossary', 'g')
      ->fields('g', ['source_token'])
      ->condition('target_token', $slug)
      ->execute()
      ->fetchCol();
    return array_values(array_unique([...$forward, ...$reverse]));
  }

  /**
   * Delete groups that have no members (orphaned by re-homing). Returns count.
   */
  public function deleteEmptyGroups(): int {
    $allGroups = $this->database->select('hrefl_group', 'g')
      ->fields('g', ['group_uuid'])
      ->execute()
      ->fetchCol();
    $usedGroups = $this->database->select('hrefl_group_member', 'm')
      ->distinct()
      ->fields('m', ['group_uuid'])
      ->execute()
      ->fetchCol();
    $empty = array_diff($allGroups, $usedGroups);
    if (!$empty) {
      return 0;
    }
    $this->database->delete('hrefl_group')
      ->condition('group_uuid', array_values($empty), 'IN')
      ->execute();
    return count($empty);
  }

  /**
   * Grow the learned slug glossary from a confirmed equivalence.
   */
  public function addGlossaryEntry(string $sourceLang, string $targetLang, string $sourceToken, string $targetToken, float $weight = 1.0): void {
    $this->database->merge('hrefl_glossary')
      ->keys([
        'source_lang' => $sourceLang,
        'target_lang' => $targetLang,
        'source_token' => $sourceToken,
        'target_token' => $targetToken,
      ])
      ->fields(['weight' => $weight])
      ->execute();
  }

}
