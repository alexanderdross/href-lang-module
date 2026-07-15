<?php

declare(strict_types=1);

namespace Drupal\hrefl_client\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Language\LanguageManagerInterface;

/**
 * Builds this backend's page inventory and matching signals.
 *
 * By default it walks published nodes; extend collectExtra() to add taxonomy
 * term pages, listing pages, and identity signals (a shared content id field,
 * schema.org relationships, existing hreflang).
 */
final class InventoryCollector {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LanguageManagerInterface $languageManager,
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
  ) {}

  /**
   * Collect up to $limit normalized inventory records.
   */
  public function collect(int $limit = 200): array {
    $config = $this->configFactory->get('hrefl_client.settings');
    $market = (string) $config->get('market');
    $map = (array) $config->get('hreflang_map');
    $imageField = (string) $config->get('thumbnail_field');

    $storage = $this->entityTypeManager->getStorage('node');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', 1)
      ->sort('changed', 'DESC')
      ->range(0, $limit)
      ->execute();
    if (!$ids) {
      return [];
    }

    $records = [];
    foreach ($storage->loadMultiple($ids) as $node) {
      if (!$node instanceof ContentEntityInterface) {
        continue;
      }
      $langcode = $node->language()->getId();
      try {
        $url = $node->toUrl('canonical', ['absolute' => TRUE])->toString();
      }
      catch (\Exception $e) {
        continue;
      }
      $records[] = [
        'url' => $url,
        'market' => $market,
        'language' => $langcode,
        'hreflang' => $map[$langcode] ?? $langcode,
        'entity_type' => 'node',
        'entity_id' => (string) $node->id(),
        'title' => (string) $node->label(),
        'image' => $this->imageUrl($node, $imageField),
        'changed' => (int) $node->getChangedTime(),
        'indexable' => 1,
      ];
    }
    return $records;
  }

  /**
   * Best-effort absolute URL of a node's thumbnail image, or ''.
   *
   * Supports a plain image field (entity reference to a file). Other patterns
   * (media reference, responsive image) can be handled by overriding the
   * `thumbnail_field` extraction for the site; anything unexpected returns ''.
   */
  private function imageUrl(ContentEntityInterface $node, string $field): string {
    if ($field === '' || !$node->hasField($field) || $node->get($field)->isEmpty()) {
      return '';
    }
    try {
      $file = $node->get($field)->first()?->get('entity')?->getValue();
      if (is_object($file) && method_exists($file, 'getFileUri')) {
        return $this->fileUrlGenerator->generateAbsoluteString($file->getFileUri());
      }
    }
    catch (\Throwable $e) {
      // Any unexpected field shape yields no thumbnail rather than an error.
    }
    return '';
  }

}
