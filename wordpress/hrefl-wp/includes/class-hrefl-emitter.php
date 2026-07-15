<?php
/**
 * Emits hreflang <link> tags in <head> and powers the selector shortcode.
 *
 * Reads only the local store, validated on the way out. Mirrors the Drupal
 * HreflangEmitter + selector block.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class Hrefl_Emitter {

    public function __construct(private Hrefl_Store $store) {}

    /**
     * The validated alternates for a URL.
     *
     * @return array<int,array{hreflang:string,href:string}>
     */
    public function alternates(string $url): array {
        return Hrefl_Validator::clean($this->store->get($url));
    }

    /**
     * wp_head callback: print the hreflang links for the current page.
     */
    public function render_head(): void {
        if (!Hrefl_Settings::get('emit_head')) {
            return;
        }
        foreach ($this->alternates(self::current_url()) as $alt) {
            printf(
                '<link rel="alternate" hreflang="%s" href="%s" />' . "\n",
                esc_attr($alt['hreflang']),
                esc_url($alt['href'])
            );
        }
    }

    /**
     * Shortcode [hrefl_selector]: a crawlable country/language switcher.
     */
    public function render_selector($atts = []): string {
        $items = [];
        foreach ($this->alternates(self::current_url()) as $alt) {
            if ($alt['hreflang'] === 'x-default') {
                continue;
            }
            $items[] = sprintf(
                '<li><a hreflang="%1$s" lang="%1$s" rel="alternate" href="%2$s">%1$s</a></li>',
                esc_attr($alt['hreflang']),
                esc_url($alt['href'])
            );
        }
        if (!$items) {
            return '';
        }
        return '<ul class="hrefl-selector" aria-label="' . esc_attr__('Country and language', 'hrefl') . '">'
            . implode('', $items) . '</ul>';
    }

    /**
     * The current front-end URL, matched against the store keys (permalinks).
     */
    public static function current_url(): string {
        if (is_singular()) {
            $link = get_permalink(get_queried_object_id());
            if ($link) {
                return $link;
            }
        }
        if (is_front_page() || is_home()) {
            return home_url('/');
        }
        $scheme = is_ssl() ? 'https' : 'http';
        return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '/');
    }
}
