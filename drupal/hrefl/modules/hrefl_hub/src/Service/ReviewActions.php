<?php

declare(strict_types=1);

namespace Drupal\hrefl_hub\Service;

/**
 * Applies editor confirm/reject decisions with the correctness guard.
 *
 * The single place both the CSV import and the admin review UI go through, so
 * "only clean, structurally valid members go live" is enforced identically
 * regardless of how the decision was made.
 */
final class ReviewActions {

  public function __construct(
    private readonly Registry $registry,
    private readonly MappingValidator $validator,
  ) {}

  /**
   * Confirm a member if doing so keeps its group valid.
   *
   * @return string[]
   *   Violations that blocked the confirmation; empty means it was confirmed.
   */
  public function confirm(int $memberId, string $actor = 'editor'): array {
    $member = $this->registry->loadMember($memberId);
    if ($member === NULL) {
      return ['Member not found.'];
    }
    $siblings = [];
    foreach ($this->registry->membersOfGroup((string) $member['group_uuid']) as $m) {
      if ((int) $m['id'] !== $memberId && $m['status'] === 'confirmed') {
        $siblings[] = $m;
      }
    }
    $violations = $this->validator->violationsForConfirm($member, $siblings);
    if ($violations) {
      return $violations;
    }
    $this->registry->setStatus($memberId, 'confirmed', $actor);
    $this->learnGlossary($member, $siblings);
    return [];
  }

  /**
   * Feedback loop: turn a confirmed cross-language equivalence into glossary
   * entries, so the next URL-pattern pass matches these slugs deterministically
   * (docs/AUTOMATION.md §7).
   */
  private function learnGlossary(array $member, array $confirmedSiblings): void {
    $lang = (string) $member['language'];
    $slug = (string) ($member['path_key'] ?? '');
    if ($slug === '') {
      return;
    }
    foreach ($confirmedSiblings as $sibling) {
      $siblingLang = (string) $sibling['language'];
      $siblingSlug = (string) ($sibling['path_key'] ?? '');
      // Only cross-language pairs with distinct slugs teach us anything new.
      if ($siblingLang === $lang || $siblingSlug === '' || $siblingSlug === $slug) {
        continue;
      }
      $this->registry->addGlossaryEntry($lang, $siblingLang, $slug, $siblingSlug);
      $this->registry->addGlossaryEntry($siblingLang, $lang, $siblingSlug, $slug);
    }
  }

  /**
   * Reject a member (never emitted).
   */
  public function reject(int $memberId, string $actor = 'editor'): void {
    $this->registry->setStatus($memberId, 'rejected', $actor);
  }

}
