<?php

declare(strict_types=1);

namespace Drupal\hrefl_hub\Form;

use Drupal\Component\Utility\Html;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Core\Url;
use Drupal\hrefl_hub\Service\Registry;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Review queue with bulk confirm/reject and preview thumbnails.
 *
 * Editors select rows and act on them in one go; each confirmation still passes
 * the same ReviewActions guard (target validity + structural correctness), and
 * any blocked confirmations are reported rather than silently applied.
 */
final class ReviewQueueForm extends FormBase {

  private const COLLECTION = 'hrefl_hub_review';

  public function __construct(
    private readonly Registry $registry,
    private readonly PrivateTempStoreFactory $tempStoreFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('hrefl_hub.registry'),
      $container->get('tempstore.private'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'hrefl_hub_review_queue';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['help'] = [
      '#markup' => '<p>' . $this->t('Select rows and confirm or reject them together. Confirming publishes on the next client sync; only members with a validated target and a structurally valid group can be confirmed.') . '</p>',
    ];

    $header = [
      'group' => $this->t('Group'),
      'preview' => $this->t('Preview (thumbnail · title · AI translation)'),
      'market' => $this->t('Market'),
      'hreflang' => $this->t('hreflang'),
      'status' => $this->t('Status'),
      'confidence' => $this->t('Conf.'),
      'valid' => $this->t('Valid'),
      'quick' => $this->t('Quick'),
    ];

    $options = [];
    foreach ($this->registry->membersNeedingMatch(500) as $member) {
      $id = (int) $member['id'];
      $options[$id] = [
        'group' => substr((string) $member['group_uuid'], 0, 8),
        'preview' => ['data' => $this->preview($member)],
        'market' => $member['market'],
        'hreflang' => $member['hreflang'],
        'status' => $member['status'],
        'confidence' => $member['confidence'] !== NULL ? sprintf('%.2f', (float) $member['confidence']) : '—',
        'valid' => ((int) $member['valid'] === 1) ? $this->t('yes') : $this->t('no'),
        'quick' => ['data' => $this->quickActions($id)],
      ];
    }

    $form['members'] = [
      '#type' => 'tableselect',
      '#header' => $header,
      '#options' => $options,
      '#empty' => $this->t('Nothing awaiting review. New or changed pages appear here after the next ingest.'),
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['confirm'] = [
      '#type' => 'submit',
      '#value' => $this->t('Confirm selected'),
      '#submit' => ['::submitConfirm'],
    ];
    $form['actions']['reject'] = [
      '#type' => 'submit',
      '#value' => $this->t('Reject selected'),
      '#submit' => ['::submitReject'],
      '#button_type' => 'danger',
    ];
    $form['actions']['export'] = [
      '#type' => 'link',
      '#title' => $this->t('Export CSV'),
      '#url' => Url::fromRoute('hrefl_hub.api.csv_export'),
      '#attributes' => ['class' => ['button']],
    ];
    $form['actions']['import'] = [
      '#type' => 'link',
      '#title' => $this->t('Import CSV'),
      '#url' => Url::fromRoute('hrefl_hub.csv_import_form'),
      '#attributes' => ['class' => ['button']],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   *
   * The two action buttons carry their own #submit handlers; this is unused.
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {}

  /**
   * Stage a bulk confirm for the confirmation step.
   */
  public function submitConfirm(array &$form, FormStateInterface $form_state): void {
    $this->stage('confirm', $form_state);
  }

  /**
   * Stage a bulk reject for the confirmation step.
   */
  public function submitReject(array &$form, FormStateInterface $form_state): void {
    $this->stage('reject', $form_state);
  }

  /**
   * Save the selection to tempstore and go to the confirmation page.
   */
  private function stage(string $op, FormStateInterface $form_state): void {
    $ids = $this->selectedIds($form_state);
    if (!$ids) {
      $this->messenger()->addWarning($this->t('No rows were selected.'));
      return;
    }
    $this->tempStoreFactory->get(self::COLLECTION)->set('selection', ['op' => $op, 'ids' => $ids]);
    $form_state->setRedirect('hrefl_hub.review_confirm');
  }

  /**
   * The selected member ids (checked rows) as ints.
   *
   * @return int[]
   */
  private function selectedIds(FormStateInterface $form_state): array {
    $selected = array_filter((array) $form_state->getValue('members'));
    return array_map('intval', array_values($selected));
  }

  /**
   * Preview cell: thumbnail (if any) + title + AI-proposed translation + link.
   */
  private function preview(array $member): array {
    $build = ['#type' => 'container'];
    if (!empty($member['image'])) {
      $build['image'] = [
        '#theme' => 'image',
        '#uri' => $member['image'],
        '#alt' => (string) ($member['title'] ?? ''),
        '#attributes' => ['width' => 96, 'loading' => 'lazy', 'style' => 'display:block;max-width:96px;height:auto'],
      ];
    }
    if (!empty($member['title'])) {
      $build['title'] = ['#markup' => '<div>' . Html::escape((string) $member['title']) . '</div>'];
    }
    $translation = $this->proposedTranslation($member);
    if ($translation !== '') {
      $build['translation'] = ['#markup' => '<div><em>' . Html::escape($translation) . '</em></div>'];
    }
    // A malformed stored URL must degrade to plain text, not take down the
    // whole queue with an unhandled InvalidArgumentException.
    try {
      $build['link'] = [
        '#type' => 'link',
        '#title' => $member['url'],
        '#url' => Url::fromUri($member['url']),
        '#attributes' => ['class' => ['hrefl-review-url']],
      ];
    }
    catch (\InvalidArgumentException $e) {
      $build['link'] = ['#plain_text' => (string) $member['url']];
    }
    return $build;
  }

  /**
   * The AI-proposed translation ("title / slug") or '' if none.
   */
  private function proposedTranslation(array $member): string {
    $signals = json_decode((string) ($member['signals'] ?? ''), TRUE);
    if (!is_array($signals)) {
      return '';
    }
    $title = trim((string) ($signals['translated_title'] ?? ''));
    $slug = trim((string) ($signals['translated_slug'] ?? ''));
    return ($title === '' && $slug === '') ? '' : trim($title . ' / ' . $slug, ' /');
  }

  /**
   * Per-row quick confirm/reject links (CSRF-protected single actions).
   */
  private function quickActions(int $memberId): array {
    return [
      '#theme' => 'links',
      '#links' => [
        'confirm' => [
          'title' => $this->t('Confirm'),
          'url' => Url::fromRoute('hrefl_hub.review_action', ['member' => $memberId, 'op' => 'confirm']),
        ],
        'reject' => [
          'title' => $this->t('Reject'),
          'url' => Url::fromRoute('hrefl_hub.review_action', ['member' => $memberId, 'op' => 'reject']),
        ],
      ],
    ];
  }

}
