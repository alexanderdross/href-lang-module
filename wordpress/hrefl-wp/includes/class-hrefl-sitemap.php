<?php
/**
 * The site's own multilingual XML sitemap at /hrefl-sitemap.xml.
 *
 * Each <url> lists <lastmod>, <priority>, and every alternate as an
 * <xhtml:link>. Reads only the local store. Past 50,000 URLs the entry point
 * becomes a <sitemapindex> of numbered chunks (/hrefl-sitemap.N.xml), matching
 * the Drupal SitemapGenerator and Google's per-file limit.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class Hrefl_Sitemap {

    private const MAX_URLS = 50000;

    public function __construct(private Hrefl_Store $store) {}

    public function register(): void {
        add_rewrite_rule('^hrefl-sitemap\.xml$', 'index.php?hrefl_sitemap=1', 'top');
        add_rewrite_rule('^hrefl-sitemap\.([0-9]+)\.xml$', 'index.php?hrefl_sitemap=1&hrefl_chunk=$matches[1]', 'top');
        add_filter('query_vars', static function (array $vars): array {
            $vars[] = 'hrefl_sitemap';
            $vars[] = 'hrefl_chunk';
            return $vars;
        });
        add_action('template_redirect', [$this, 'maybe_render']);
    }

    public function maybe_render(): void {
        if (!get_query_var('hrefl_sitemap') || !Hrefl_Settings::get('sitemap_enabled')) {
            return;
        }
        $total = $this->store->count();
        $chunkParam = get_query_var('hrefl_chunk');

        header('Content-Type: application/xml; charset=utf-8');
        // Edge/browser cache: the store only changes on cron pulls.
        header('Cache-Control: public, max-age=3600');

        if ($chunkParam === '' && $total > self::MAX_URLS) {
            echo $this->render_index($total);
        } else {
            $chunk = $chunkParam === '' ? 0 : max(0, (int) $chunkParam);
            echo $this->render_chunk($chunk);
        }
        exit;
    }

    /**
     * A <sitemapindex> pointing at the numbered chunks.
     */
    private function render_index(int $total): string {
        $chunks = (int) ceil($total / self::MAX_URLS);
        $writer = new XMLWriter();
        $writer->openMemory();
        $writer->startDocument('1.0', 'UTF-8');
        $writer->setIndent(true);
        $writer->startElement('sitemapindex');
        $writer->writeAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        for ($i = 0; $i < $chunks; $i++) {
            $writer->startElement('sitemap');
            $writer->writeElement('loc', home_url('/hrefl-sitemap.' . $i . '.xml'));
            $writer->endElement();
        }
        $writer->endElement();
        $writer->endDocument();
        return $writer->outputMemory();
    }

    /**
     * One <urlset> for the given chunk (offset = chunk * MAX_URLS).
     */
    public function render_chunk(int $chunk): string {
        $priority = self::priority();
        $pages = $this->store->all(self::MAX_URLS, $chunk * self::MAX_URLS);

        $writer = new XMLWriter();
        $writer->openMemory();
        $writer->startDocument('1.0', 'UTF-8');
        $writer->setIndent(true);
        $writer->startElement('urlset');
        $writer->writeAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        $writer->writeAttribute('xmlns:xhtml', 'http://www.w3.org/1999/xhtml');

        foreach ($pages as $page) {
            $alts = Hrefl_Validator::clean($page['alternates']);
            if (!$alts) {
                continue;
            }
            $writer->startElement('url');
            $writer->writeElement('loc', $page['url']);
            if (!empty($page['lastmod'])) {
                $writer->writeElement('lastmod', gmdate('Y-m-d\TH:i:s\Z', (int) $page['lastmod']));
            }
            $writer->writeElement('priority', $priority);
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

    /**
     * Backward-compatible single-file render (used when total <= MAX_URLS).
     */
    public function render(): string {
        return $this->render_chunk(0);
    }

    /**
     * The configured sitemap priority, clamped to [0.0, 1.0].
     */
    private static function priority(): string {
        $p = (float) Hrefl_Settings::get('sitemap_priority', 0.5);
        $p = max(0.0, min(1.0, $p));
        return number_format($p, 1);
    }
}
