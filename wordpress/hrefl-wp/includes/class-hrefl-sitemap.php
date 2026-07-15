<?php
/**
 * The site's own multilingual XML sitemap at /hrefl-sitemap.xml.
 *
 * Each <url> lists <lastmod>, <priority>, and every alternate as an
 * <xhtml:link>. Reads only the local store. Mirrors the Drupal SitemapGenerator.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class Hrefl_Sitemap {

    public function __construct(private Hrefl_Store $store) {}

    public function register(): void {
        add_rewrite_rule('^hrefl-sitemap\.xml$', 'index.php?hrefl_sitemap=1', 'top');
        add_filter('query_vars', static function (array $vars): array {
            $vars[] = 'hrefl_sitemap';
            return $vars;
        });
        add_action('template_redirect', [$this, 'maybe_render']);
    }

    public function maybe_render(): void {
        if (!get_query_var('hrefl_sitemap') || !Hrefl_Settings::get('sitemap_enabled')) {
            return;
        }
        header('Content-Type: application/xml; charset=utf-8');
        echo $this->render();
        exit;
    }

    public function render(): string {
        $writer = new XMLWriter();
        $writer->openMemory();
        $writer->startDocument('1.0', 'UTF-8');
        $writer->setIndent(true);
        $writer->startElement('urlset');
        $writer->writeAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        $writer->writeAttribute('xmlns:xhtml', 'http://www.w3.org/1999/xhtml');

        foreach ($this->store->all() as $page) {
            $alts = Hrefl_Validator::clean($page['alternates']);
            if (!$alts) {
                continue;
            }
            $writer->startElement('url');
            $writer->writeElement('loc', $page['url']);
            if (!empty($page['lastmod'])) {
                $writer->writeElement('lastmod', gmdate('Y-m-d\TH:i:s\Z', (int) $page['lastmod']));
            }
            $writer->writeElement('priority', '0.5');
            foreach ($alts as $alt) {
                $writer->startElement('xhtml:link');
                $writer->writeAttribute('rel', 'alternate');
                $writer->writeAttribute('hreflang', $alt['hreflang']);
                $writer->writeAttribute('href', $alt['href']);
                $writer->endElement();
            }
            $writer->endElement();
        }

        $writer->endElement();
        $writer->endDocument();
        return $writer->outputMemory();
    }
}
