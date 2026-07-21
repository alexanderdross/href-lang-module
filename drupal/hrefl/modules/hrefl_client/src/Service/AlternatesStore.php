<?php

declare(strict_types=1);

namespace Drupal\hrefl_client\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Database\Connection;

/**
 * Local, denormalized alternates cache.
 *
 * This is the only thing page render reads. The hub is never in the request
 * path; if it is down, pages keep rendering the last known-good alternates.
 */
final class AlternatesStore {

  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
    private readonly CacheTagsInvalidatorInterface $cacheTagsInvalidator,
  ) {}

  /**
   * The alternates for a URL, or an empty array.
   *
   * @return array
   *   List of ['hreflang' => .., 'href' => ..].
   */
  public function get(string $url): array {
    $row = $this->database->select('hrefl_client_alternates', 'a')
      ->fields('a', ['alternates'])
      ->condition('url_hash', $this->hash($url))
      ->execute()
      ->fetchAssoc();
    if (!$row) {
      return [];
    }
    $decoded = json_decode((string) $row['alternates'], TRUE);
    return is_array($decoded) ? $decoded : [];
  }

  /**
   * A page of stored pages for this backend, for the sitemap generator.
   *
   * @param int $limit
   *   Maximum rows in this page.
   * @param int $offset
   *   Row offset (for chunked sitemap generation). Ordered by URL so paging is
   *   stable across requests.
   *
   * @return array
   *   List of ['url' => string, 'lastmod' => ?int, 'alternates' => array].
   */
  public function all(int $limit = 50000, int $offset = 0): array {
    $rows = $this->database->select('hrefl_client_alternates', 'a')
      ->fields('a', ['url', 'lastmod', 'alternates'])
      ->orderBy('url')
      ->range($offset, $limit)
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC);
    $pages = [];
    foreach ($rows as $row) {
      $decoded = json_decode((string) $row['alternates'], TRUE);
      $pages[] = [
        'url' => (string) $row['url'],
        'lastmod' => $row['lastmod'] !== NULL ? (int) $row['lastmod'] : NULL,
        'alternates' => is_array($decoded) ? $decoded : [],
      ];
    }
    return $pages;
  }

  /**
   * Total stored pages (to detect sitemap overflow past the spec limit).
   */
  public function count(): int {
    return (int) $this->database->select('hrefl_client_alternates', 'a')
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * The group UUID for a URL (used for cache tagging), or NULL.
   */
  public function groupForUrl(string $url): ?string {
    $uuid = $this->database->select('hrefl_client_alternates', 'a')
      ->fields('a', ['group_uuid'])
      ->condition('url_hash', $this->hash($url))
      ->execute()
      ->fetchField();
    return $uuid ?: NULL;
  }

  /**
   * Replace the whole store for a pull cycle.
   *
   * @param array $pages
   *   List of ['url' => .., 'group_uuid' => .., 'alternates' => [...]].
   */
  public function replaceAll(array $pages): void {
    $tags = [];
    $transaction = $this->database->startTransaction();
    try {
      $this->database->truncate('hrefl_client_alternates')->execute();
      $now = $this->time->getRequestTime();
      foreach ($pages as $page) {
        $this->database->insert('hrefl_client_alternates')
          ->fields([
            'url' => $page['url'],
            'url_hash' => $this->hash($page['url']),
            'group_uuid' => $page['group_uuid'] ?? NULL,
            'alternates' => json_encode($page['alternates']),
            'lastmod' => isset($page['lastmod']) ? (int) $page['lastmod'] : NULL,
            'updated' => $now,
          ])
          ->execute();
        if (!empty($page['group_uuid'])) {
          $tags['hrefl_group:' . $page['group_uuid']] = TRUE;
        }
      }
    }
    catch (\Exception $e) {
      $transaction->rollBack();
      throw $e;
    }
    // Invalidate the affected groups' pages, plus the store-wide tag carried
    // by pages/blocks rendered before they had a group (see
    // hrefl_client_page_attachments()), so they pick up their new alternates.
    $tags['hrefl_alternates'] = TRUE;
    $this->cacheTagsInvalidator->invalidateTags(array_keys($tags));
  }

  private function hash(string $url): string {
    return hash('sha256', $url);
  }

}
