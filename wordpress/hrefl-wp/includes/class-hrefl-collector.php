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

    /**
     * @return array<int,array<string,mixed>>
     */
    public function collect(int $limit = 200): array {
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
            'orderby'          => 'modified',
            'order'            => 'DESC',
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
