<?php

declare(strict_types=1);

namespace Drupal\hrefl_hub\Batch;

/**
 * Batch operations for applying bulk review decisions.
 *
 * Runs in its own request cycle (progress bar), so it resolves services from
 * the container rather than via injection. Each chunk applies the same
 * ReviewActions guard, so a large bulk confirm behaves exactly like the CSV
 * import or a single-row action - only clean, valid members go live.
 */
final class ReviewBatch {

  /**
   * Process one chunk of member ids.
   *
   * @param string $op
   *   'confirm' or 'reject'.
   * @param int[] $ids
   *   Member ids in this chunk.
   * @param array $context
   *   Batch context (accumulates results across chunks).
   */
  public static function process(string $op, array $ids, array &$context): void {
    /** @var \Drupal\hrefl_hub\Service\ReviewActions $review */
    $review = \Drupal::service('hrefl_hub.review_actions');
    $actor = \Drupal::currentUser()->getAccountName() ?: 'editor';

    $context['results']['op'] = $op;
    foreach ($ids as $id) {
      $id = (int) $id;
      if ($op === 'reject') {
        $review->reject($id, $actor);
        $context['results']['rejected'] = ($context['results']['rejected'] ?? 0) + 1;
      }
      else {
        $blocked = $review->confirm($id, $actor);
        $key = $blocked ? 'blocked' : 'confirmed';
        $context['results'][$key] = ($context['results'][$key] ?? 0) + 1;
      }
      $context['results']['processed'] = ($context['results']['processed'] ?? 0) + 1;
    }
    $context['message'] = t('Processed @n mapping(s)…', ['@n' => $context['results']['processed'] ?? 0]);
  }

  /**
   * Batch finished callback: report the outcome.
   */
  public static function finished(bool $success, array $results, array $operations): void {
    $messenger = \Drupal::messenger();
    if (!$success) {
      $messenger->addError(t('The review action did not complete. Please retry.'));
      return;
    }
    if (($results['op'] ?? '') === 'reject') {
      $messenger->addStatus(t('@n mapping(s) rejected.', ['@n' => $results['rejected'] ?? 0]));
      return;
    }
    if (!empty($results['confirmed'])) {
      $messenger->addStatus(t('@n mapping(s) confirmed; they publish on the next client sync.', ['@n' => $results['confirmed']]));
    }
    if (!empty($results['blocked'])) {
      $messenger->addWarning(t('@n mapping(s) skipped (invalid target or code conflict).', ['@n' => $results['blocked']]));
    }
    if (empty($results['confirmed']) && empty($results['blocked'])) {
      $messenger->addStatus(t('Nothing to confirm.'));
    }
  }

}
