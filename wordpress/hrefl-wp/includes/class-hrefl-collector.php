<?php
/**
 * Builds this site's inventory of published, public content for the hub.
 *
 * WordPress core is single-language per site (like a Drupal country backend),
 * so each record carries this site's market + language. Mirrors the Drupal
 * InventoryCollector.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class Hrefl_Collector {

    private const CURSOR = 'hrefl_publish_cursor';

    /**
     * Collect the next slice of the corpus (cursor-based), so a site of any
     * size is fully published across successive cron runs instead of only its
     * newest N posts. Ordered by ID so the walk is stable; wraps at the end.
     *
     * @return array<int,array<string,mixed>>
     */
    public function collect_next(int $limit = 200): array {
        $offset = (int) get_option(self::CURSOR, 0);
        $records = $this->collect($limit, $offset);
        if (count($records) < $limit) {
            update_option(self::CURSOR, 0, false); // wrap to the start
        } else {
            update_option(self::CURSOR, $offset + $limit, false);
        }
        return $records;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function collect(int $limit = 200, int $offset = 0): array {
        $market = (string) Hrefl_Settings::get('market');
        $lang   = self::site_lang();
        $map    = Hrefl_Settings::lang_map();
        $hreflang = $map[$lang] ?? $lang;

        $types = get_post_types(['public' => true], 'names');
        unset($types['attachment']);

        $posts = get_posts([
            'post_type'        => array_values($types),
            'post_status'      => 'publish',
            'numberposts'      => $limit,
            'offset'           => $offset,
            'orderby'          => 'ID',
            'order'            => 'ASC',
            'suppress_filters' => true,
        ]);

        $records = [];
        foreach ($posts as $post) {
            $url = get_permalink($post);
            if (!$url) {
                continue;
            }
            $records[] = [
                'url'      => $url,
                'market'   => $market,
                'language' => $lang,
                'hreflang' => $hreflang,
                'title'    => get_the_title($post),
                'changed'  => (int) get_post_modified_time('U', true, $post),
                'indexable' => 1,
            ];
        }
        return $records;
    }

    /**
     * Two-letter language code for this site (from the WP locale).
     */
    public static function site_lang(): string {
        return substr(get_locale(), 0, 2) ?: 'en';
    }
}
