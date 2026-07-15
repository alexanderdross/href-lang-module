<?php

declare(strict_types=1);

namespace Drupal\hrefl_hub\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\hrefl_hub\Service\ReviewActions;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Per-row confirm/reject actions for the review queue.
 *
 * The queue itself is ReviewQueueForm (bulk selection + previews); this handles
 * the single-row quick-action links, which are CSRF-protected. Both paths go
 * through ReviewActions, so a confirmation is subject to the same guard.
 */
final class ReviewController extends ControllerBase {

  public function __construct(
    private readonly ReviewActions $reviewActions,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('hrefl_hub.review_actions'));
  }

  /**
   * Confirm or reject a single member, then return to the queue.
   *
   * Route params arrive as strings (strict types); $member is cast here.
   */
  public function act(string $member, string $op): RedirectResponse {
    $memberId = (int) $member;
    $actor = $this->currentUser()->getAccountName();
    if ($op === 'reject') {
      $this->reviewActions->reject($memberId, $actor);
      $this->messenger()->addStatus($this->t('Mapping rejected.'));
    }
    elseif ($op === 'confirm') {
      $violations = $this->reviewActions->confirm($memberId, $actor);
      if ($violations) {
        $this->messenger()->addError($this->t('Could not confirm: @why', ['@why' => implode(' ', $violations)]));
      }
      else {
        $this->messenger()->addStatus($this->t('Mapping confirmed; it will publish on the next client sync.'));
      }
    }
    return $this->redirect('hrefl_hub.review');
  }

}
