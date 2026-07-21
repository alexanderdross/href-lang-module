<?php

declare(strict_types=1);

namespace Drupal\hrefl_hub\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;

/**
 * Stores one embedding vector per URL and finds nearest neighbours by cosine.
 *
 * The search is an exact brute-force cosine scan, which is fine at the family's
 * scale (a few thousand pages) and needs no external vector database. The seam
 * is deliberately small so a true ANN index (pgvector, a vector DB) can replace
 * `nearest()` later without touching the matcher.
 */
final class VectorStore {

  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Upsert a URL's vector, keyed by URL.
   */
  public function upsert(string $url, string $market, string $language, string $contentHash, array $vector): void {
    $this->database->merge('hrefl_embedding')
      ->keys(['url_hash' => $this->hash($url)])
      ->fields([
        'url' => $url,
        'market' => $market,
        'language' => $language,
        'content_hash' => $contentHash,
        'dims' => count($vector),
        'vector' => json_encode(array_map('floatval', $vector)),
        'updated' => $this->time->getRequestTime(),
      ])
      ->execute();
  }

  /**
   * The content hash currently stored for a URL, or NULL if not embedded.
   */
  public function contentHashFor(string $url): ?string {
    $hash = $this->database->select('hrefl_embedding', 'e')
      ->fields('e', ['content_hash'])
      ->condition('url_hash', $this->hash($url))
      ->execute()
      ->fetchField();
    return $hash !== FALSE && $hash !== NULL ? (string) $hash : NULL;
  }

  /**
   * Nearest neighbours to a query vector, in markets other than $excludeMarket.
   *
   * @return array
   *   Up to $topK rows ['url','market','language','score'], score descending,
   *   filtered to cosine >= $threshold.
   */
  public function nearest(array $query, string $excludeMarket, int $topK = 5, float $threshold = 0.0): array {
    $rows = $this->database->select('hrefl_embedding', 'e')
      ->fields('e', ['url', 'market', 'language', 'vector'])
      ->condition('market', $excludeMarket, '<>')
      ->execute()
      ->fetchAll(\PDO::FETCH_ASSOC);

    $scored = [];
    foreach ($rows as $row) {
      $vector = json_decode((string) $row['vector'], TRUE);
      if (!is_array($vector) || !$vector) {
        continue;
      }
      $score = self::cosine($query, $vector);
      if ($score >= $threshold) {
        $scored[] = [
          'url' => (string) $row['url'],
          'market' => (string) $row['market'],
          'language' => (string) $row['language'],
          'score' => $score,
        ];
      }
    }
    usort($scored, static fn($a, $b) => $b['score'] <=> $a['score']);
    return array_slice($scored, 0, max(0, $topK));
  }

  /**
   * Total stored vectors.
   */
  public function count(): int {
    return (int) $this->database->select('hrefl_embedding', 'e')
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * Cosine similarity of two equal-length vectors (0 if either is degenerate).
   *
   * Mismatched dimensions (e.g. vectors from different embedding models) are
   * not comparable and score 0 rather than a truncated, garbage similarity.
   */
  public static function cosine(array $a, array $b): float {
    $n = count($a);
    if ($n === 0 || $n !== count($b)) {
      return 0.0;
    }
    $dot = 0.0;
    $na = 0.0;
    $nb = 0.0;
    for ($i = 0; $i < $n; $i++) {
      $x = (float) $a[$i];
      $y = (float) $b[$i];
      $dot += $x * $y;
      $na += $x * $x;
      $nb += $y * $y;
    }
    if ($na <= 0.0 || $nb <= 0.0) {
      return 0.0;
    }
    return $dot / (sqrt($na) * sqrt($nb));
  }

  private function hash(string $url): string {
    return hash('sha256', $url);
  }

}
