<?php
/**
 * One embedding vector per URL, with nearest-neighbour search by cosine.
 *
 * Brute-force cosine scan - fine at the family's scale (a few thousand pages)
 * and needs no external vector database. Mirrors the Drupal VectorStore; the
 * seam is small so an ANN index could replace nearest() later.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class Hrefl_Vector_Store {

    private function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'hrefl_embedding';
    }

    /**
     * Upsert a URL's vector, keyed by URL.
     *
     * @param array<int,float> $vector
     */
    public function upsert(string $url, string $market, string $language, string $content_hash, array $vector): void {
        global $wpdb;
        $wpdb->replace($this->table(), [
            'url_hash'     => hash('sha256', $url),
            'url'          => $url,
            'market'       => $market,
            'language'     => $language,
            'content_hash' => $content_hash,
            'dims'         => count($vector),
            'vector'       => wp_json_encode(array_map('floatval', $vector)),
            'updated'      => time(),
        ]);
    }

    /**
     * The content hash currently stored for a URL, or null if not embedded.
     */
    public function content_hash_for(string $url): ?string {
        global $wpdb;
        $hash = $wpdb->get_var($wpdb->prepare(
            "SELECT content_hash FROM {$this->table()} WHERE url_hash = %s",
            hash('sha256', $url)
        ));
        return $hash !== null ? (string) $hash : null;
    }

    /**
     * Nearest neighbours to a query vector, in markets other than $exclude.
     *
     * @param array<int,float> $query
     *
     * @return array<int,array{url:string,market:string,language:string,score:float}>
     */
    public function nearest(array $query, string $exclude_market, int $top_k = 5, float $threshold = 0.0): array {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT url, market, language, vector FROM {$this->table()} WHERE market <> %s",
            $exclude_market
        ), ARRAY_A);

        $scored = [];
        foreach ((array) $rows as $row) {
            $vector = json_decode((string) $row['vector'], true);
            if (!is_array($vector) || !$vector) {
                continue;
            }
            $score = self::cosine($query, $vector);
            if ($score >= $threshold) {
                $scored[] = [
                    'url'      => (string) $row['url'],
                    'market'   => (string) $row['market'],
                    'language' => (string) $row['language'],
                    'score'    => $score,
                ];
            }
        }
        usort($scored, static fn($a, $b) => $b['score'] <=> $a['score']);
        return array_slice($scored, 0, max(0, $top_k));
    }

    public function count(): int {
        global $wpdb;
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->table()}");
    }

    /**
     * Cosine similarity of two equal-length vectors (0 if degenerate or the
     * dimensions differ - vectors from different models are not comparable).
     *
     * @param array<int,float> $a
     * @param array<int,float> $b
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
}
