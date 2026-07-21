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
     *
     * The visible text is a human-readable label ("English (United States)"),
     * while hreflang/lang stay the machine code.
     */
    public function render_selector($atts = []): string {
        $items = [];
        foreach ($this->alternates(self::current_url()) as $alt) {
            if ($alt['hreflang'] === 'x-default') {
                continue;
            }
            $items[] = sprintf(
                '<li><a hreflang="%1$s" lang="%1$s" rel="alternate" href="%2$s">%3$s</a></li>',
                esc_attr($alt['hreflang']),
                esc_url($alt['href']),
                esc_html(Hrefl_Locale::label($alt['hreflang']))
            );
        }
        if (!$items) {
            return '';
        }
        return '<ul class="hrefl-selector" aria-label="' . esc_attr__('Country and language', 'hrefl') . '">'
            . implode('', $items) . '</ul>';
    }

    /**
     * The selector as data, for a headless / decoupled front end.
     *
     * @return array<int,array{hreflang:string,label:string,href:string}>
     */
    public function selector_data(string $url): array {
        $out = [];
        foreach ($this->alternates($url) as $alt) {
            if ($alt['hreflang'] === 'x-default') {
                continue;
            }
            $out[] = [
                'hreflang' => $alt['hreflang'],
                'label'    => Hrefl_Locale::label($alt['hreflang']),
                'href'     => $alt['href'],
            ];
        }
        return $out;
    }

    /**
     * template_redirect callback: emit hreflang as HTTP Link headers on
     * non-HTML responses (feeds, attachments), where a <head> tag is not an
     * option. Mirrors the Drupal LinkHeaderSubscriber.
     */
    public function send_link_header(): void {
        if (headers_sent() || !Hrefl_Settings::get('emit_head')) {
            return;
        }
        if (!is_feed() && !is_attachment()) {
            return;
        }
        foreach ($this->alternates(self::current_url()) as $alt) {
            header(sprintf(
                'Link: <%s>; rel="alternate"; hreflang="%s"',
                esc_url_raw($alt['href']),
                $alt['hreflang']
            ), false);
        }
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
