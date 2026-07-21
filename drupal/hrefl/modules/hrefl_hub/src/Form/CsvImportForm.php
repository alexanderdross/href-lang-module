<?php

declare(strict_types=1);

namespace Drupal\hrefl_hub\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\file\FileInterface;
use Drupal\hrefl_hub\Service\CsvImporter;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Upload an edited review CSV — the point-and-click counterpart to the API.
 *
 * Lets a non-technical editor take the exported CSV, edit decisions in a
 * spreadsheet, and upload it here. Rows are applied through CsvImporter, so the
 * same guard as the in-app review queue runs (blocked rows are reported).
 */
final class CsvImportForm extends FormBase {

  public function __construct(
    private readonly CsvImporter $importer,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('hrefl_hub.csv_importer'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'hrefl_hub_csv_import';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['help'] = [
      '#markup' => '<p>' . $this->t('Upload a review CSV (the file you exported and edited). Set the <em>decision</em> column to <code>confirm</code> or <code>reject</code> per row; <code>leave</code> keeps a row unchanged. Confirmations run the same checks as the review queue.') . '</p>',
    ];
    // Managed upload: goes through Drupal's upload pipeline (extension and
    // size validators, sanitized filename, temporary managed file that cron
    // garbage-collects).
    $form['csv'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('CSV file'),
      '#description' => $this->t('A .csv file exported from this hub.'),
      '#required' => TRUE,
      '#upload_location' => 'temporary://',
      '#upload_validators' => [
        'FileExtension' => ['extensions' => 'csv'],
      ],
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Upload and apply'),
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $content = '';
    $fids = (array) $form_state->getValue('csv');
    $file = $fids ? $this->entityTypeManager->getStorage('file')->load(reset($fids)) : NULL;
    if ($file instanceof FileInterface) {
      $content = (string) file_get_contents($file->getFileUri());
      // One-shot input: never promote it to a permanent file.
      $file->delete();
    }
    $result = $this->importer->import($content);

    $this->messenger()->addStatus($this->t('Applied @a decision(s); skipped @s row(s).', [
      '@a' => $result['applied'],
      '@s' => $result['skipped'],
    ]));
    if (!empty($result['blocked'])) {
      $this->messenger()->addWarning($this->t('@n row(s) could not be confirmed (invalid target or code conflict) and were skipped: @urls', [
        '@n' => count($result['blocked']),
        '@urls' => implode(', ', array_keys($result['blocked'])),
      ]));
    }
    $form_state->setRedirect('hrefl_hub.review');
  }

}
