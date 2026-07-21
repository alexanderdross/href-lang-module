<?php

declare(strict_types=1);

namespace Drupal\hrefl_client\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\State\StateInterface;

/**
 * Builds this backend's page inventory and matching signals.
 *
 * By default it walks published nodes; extend collectExtra() to add taxonomy
 * term pages, listing pages, and identity signals (a shared content id field,
 * schema.org relationships, existing hreflang).
 */
final class InventoryCollector {

  /**
   * State key holding the nid cursor for the paged cron walk.
   */
  private const CURSOR_KEY = 'hrefl_client.inventory_cursor';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LanguageManagerInterface $languageManager,
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
    private readonly StateInterface $state,
  ) {}

  /**
   * Collect up to $limit normalized inventory records (most recently changed).
   */
  public function collect(int $limit = 200): array {
    $storage = $this->entityTypeManager->getStorage('node');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', 1)
      ->sort('changed', 'DESC')
      ->range(0, $limit)
      ->execute();
    return $this->buildRecords($ids);
  }

  /**
   * Collect the next $limit records of a full walk over all published nodes.
   *
   * Keeps a nid cursor in state so successive cron runs cover the entire
   * corpus regardless of size, then wraps around and starts over (which also
   * refreshes stale records).
   */
  public function collectNext(int $limit = 200): array {
    $cursor = (int) $this->state->get(self::CURSOR_KEY, 0);
    $storage = $this->entityTypeManager->getStorage('node');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', 1)
      ->condition('nid', $cursor, '>')
      ->sort('nid', 'ASC')
      ->range(0, $limit)
      ->execute();
    if (!$ids) {
      // End of the corpus: wrap around for the next run.
      $this->state->set(self::CURSOR_KEY, 0);
      return [];
    }
    $this->state->set(self::CURSOR_KEY, (int) max($ids));
    return $this->buildRecords($ids);
  }

  /**
   * The normalized inventory record for one entity, or NULL if it has no URL.
   */
  public function recordFor(ContentEntityInterface $entity): ?array {
    $config = $this->configFactory->get('hrefl_client.settings');
    $map = (array) $config->get('hreflang_map');
    $langcode = $entity->language()->getId();
    try {
      $url = $entity->toUrl('canonical', ['absolute' => TRUE])->toString();
    }
    catch (\Exception $e) {
      return NULL;
    }
    return [
      'url' => $url,
      'market' => (string) $config->get('market'),
      'language' => $langcode,
      'hreflang' => $map[$langcode] ?? $langcode,
      'entity_type' => $entity->getEntityTypeId(),
      'entity_id' => (string) $entity->id(),
      'title' => (string) $entity->label(),
      'image' => $this->imageUrl($entity, (string) $config->get('thumbnail_field')),
      'changed' => method_exists($entity, 'getChangedTime') ? (int) $entity->getChangedTime() : 0,
      'indexable' => 1,
    ];
  }

  /**
   * Load a set of node ids and normalize each into an inventory record.
   */
  private function buildRecords(array $ids): array {
    if (!$ids) {
      return [];
    }
    $records = [];
    foreach ($this->entityTypeManager->getStorage('node')->loadMultiple($ids) as $node) {
      if (!$node instanceof ContentEntityInterface) {
        continue;
      }
      $record = $this->recordFor($node);
      if ($record !== NULL) {
        $records[] = $record;
      }
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
