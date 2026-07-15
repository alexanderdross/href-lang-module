<?php
/**
 * Client-side local store of resolved alternates (what page render reads).
 *
 * Page output never calls the hub; it reads this table. Mirrors the Drupal
 * AlternatesStore.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class Hrefl_Store {

    private function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'hrefl_alternates';
    }

    /**
     * @return array<int,array{hreflang:string,href:string}>
     */
    public function get(string $url): array {
        global $wpdb;
        $row = $wpdb->get_var($wpdb->prepare(
            "SELECT alternates FROM {$this->table()} WHERE url_hash = %s",
            self::hash($url)
        ));
        if (!$row) {
            return [];
        }
        $decoded = json_decode((string) $row, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Replace the whole store from a serve payload.
     *
     * @param array<int,array{url:string,alternates:array,lastmod?:int}> $pages
     */
    public function replace_all(array $pages): void {
        global $wpdb;
        $t = $this->table();
        $wpdb->query("TRUNCATE TABLE {$t}");
        $now = time();
        foreach ($pages as $page) {
            if (empty($page['url']) || empty($page['alternates'])) {
                continue;
            }
            $wpdb->replace($t, [
                'url_hash'   => self::hash((string) $page['url']),
                'url'        => (string) $page['url'],
                'alternates' => wp_json_encode(array_values($page['alternates'])),
                'lastmod'    => isset($page['lastmod']) ? (int) $page['lastmod'] : null,
                'updated'    => $now,
            ]);
        }
    }

    /**
     * All stored pages (for the sitemap).
     *
     * @return array<int,array{url:string,lastmod:?int,alternates:array}>
     */
    public function all(int $limit = 50000, int $offset = 0): array {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT url, lastmod, alternates FROM {$this->table()} ORDER BY url LIMIT %d OFFSET %d",
            $limit,
            $offset
        ), ARRAY_A);
        $out = [];
        foreach ((array) $rows as $row) {
            $decoded = json_decode((string) $row['alternates'], true);
            $out[] = [
                'url'        => (string) $row['url'],
                'lastmod'    => $row['lastmod'] !== null ? (int) $row['lastmod'] : null,
                'alternates' => is_array($decoded) ? $decoded : [],
            ];
        }
        return $out;
    }

    public static function hash(string $url): string {
        return hash('sha256', $url);
    }
}
