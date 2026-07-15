<?php

declare(strict_types=1);

namespace Drupal\hrefl_hub\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Core\Url;
use Drupal\hrefl_hub\Batch\ReviewBatch;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Confirmation step for a bulk review action, then runs it as a batch.
 *
 * The queue form stages the selection (operation + member ids) in the private
 * tempstore and redirects here; this shows "Confirm/Reject N mappings?" and, on
 * confirm, applies the decision in a progress-tracked batch.
 */
final class ReviewConfirmForm extends ConfirmFormBase {

  private const COLLECTION = 'hrefl_hub_review';

  /**
   * The staged selection: ['op' => string, 'ids' => int[]].
   */
  private array $selection = ['op' => 'confirm', 'ids' => []];

  public function __construct(
    private readonly PrivateTempStoreFactory $tempStoreFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('tempstore.private'));
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'hrefl_hub_review_confirm';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $staged = $this->tempStoreFactory->get(self::COLLECTION)->get('selection');
    if (is_array($staged) && !empty($staged['ids'])) {
      $this->selection = [
        'op' => ($staged['op'] ?? 'confirm') === 'reject' ? 'reject' : 'confirm',
        'ids' => array_map('intval', (array) $staged['ids']),
      ];
    }
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    $n = count($this->selection['ids']);
    return $this->selection['op'] === 'reject'
      ? $this->formatPlural($n, 'Reject 1 mapping?', 'Reject @count mappings?')
      : $this->formatPlural($n, 'Confirm 1 mapping?', 'Confirm @count mappings?');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->selection['op'] === 'reject'
      ? $this->t('Rejected mappings are never emitted; this changes only the mapping, not the pages.')
      : $this->t('Only members with a validated target and a structurally valid group are confirmed; any others are skipped and reported.');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->selection['op'] === 'reject' ? $this->t('Reject') : $this->t('Confirm');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return Url::fromRoute('hrefl_hub.review');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $store = $this->tempStoreFactory->get(self::COLLECTION);
    $staged = $store->get('selection');
    $store->delete('selection');

    if (!is_array($staged) || empty($staged['ids'])) {
      $this->messenger()->addWarning($this->t('The selection expired; please choose the rows again.'));
      $form_state->setRedirectUrl($this->getCancelUrl());
      return;
    }

    $op = ($staged['op'] ?? 'confirm') === 'reject' ? 'reject' : 'confirm';
    $ids = array_map('intval', (array) $staged['ids']);

    $operations = [];
    foreach (array_chunk($ids, 25) as $chunk) {
      $operations[] = [[ReviewBatch::class, 'process'], [$op, $chunk]];
    }
    batch_set([
      'title' => $op === 'reject' ? $this->t('Rejecting mappings…') : $this->t('Confirming mappings…'),
      'operations' => $operations,
      'finished' => [ReviewBatch::class, 'finished'],
      'progress_message' => $this->t('Processed @current of @total.'),
    ]);
    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}
