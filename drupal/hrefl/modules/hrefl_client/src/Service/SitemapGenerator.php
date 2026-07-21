<?php

declare(strict_types=1);

namespace Drupal\hrefl_client\Service;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Builds this backend's multilingual XML sitemap from the local store.
 *
 * This is the module's own multilingual carrier: `simple_sitemap` here only
 * serves a single language, so it cannot express the cross-backend alternates.
 * Each `<url>` lists the page's `<lastmod>` and `<priority>` plus every
 * confirmed alternate as an `<xhtml:link rel="alternate" hreflang="…">`, which
 * is exactly the Google-conformant multilingual sitemap format.
 *
 * The alternates come from the same local store that feeds the head tags and
 * the selector, so all three carriers always agree. Everything the sitemap
 * needs is pre-resolved; generating it makes no cross-backend call. Beyond the
 * per-file URL limit the entry point returns a sitemap *index* pointing at
 * numbered chunk sitemaps, so arbitrarily large sites stay within spec.
 */
final class SitemapGenerator {

  /**
   * Hard ceiling per sitemap file (Google's 50,000-URL limit).
   */
  private const MAX_URLS = 50000;

  private const SITEMAP_NS = 'http://www.sitemaps.org/schemas/sitemap/0.9';
  private const XHTML_NS = 'http://www.w3.org/1999/xhtml';

  public function __construct(
    private readonly AlternatesStore $store,
    private readonly HreflangValidator $validator,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * The sitemap entry point: a single urlset, or an index if it does not fit.
   */
  public function render(): string {
    $total = $this->store->count();
    return $total > $this->chunkSize()
      ? $this->renderIndex($total)
      : $this->renderChunk(0);
  }

  /**
   * Whether the sitemap is served as a chunked index.
   */
  public function isIndex(): bool {
    return $this->store->count() > $this->chunkSize();
  }

  /**
   * Render one chunk (page) of the sitemap as a urlset.
   */
  public function renderChunk(int $page): string {
    $chunk = $this->chunkSize();
    $priority = $this->clampPriority((float) $this->configFactory->get('hrefl_client.settings')->get('sitemap_priority'));

    $writer = new \XMLWriter();
    $writer->openMemory();
    $writer->startDocument('1.0', 'UTF-8');
    $writer->setIndent(TRUE);
    $writer->startElement('urlset');
    $writer->writeAttribute('xmlns', self::SITEMAP_NS);
    $writer->writeAttribute('xmlns:xhtml', self::XHTML_NS);

    foreach ($this->store->all($chunk, max(0, $page) * $chunk) as $entry) {
      $alternates = $this->validator->clean($entry['alternates']);
      if (!$alternates) {
        continue;
      }
      $writer->startElement('url');
      $writer->writeElement('loc', $entry['url']);
      if (!empty($entry['lastmod'])) {
        $writer->writeElement('lastmod', gmdate('Y-m-d\TH:i:s\Z', (int) $entry['lastmod']));
      }
      $writer->writeElement('priority', number_format($priority, 1));
      foreach ($alternates as $alt) {
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
   * Render the sitemap index listing each chunk file.
   */
  private function renderIndex(int $total): string {
    $chunk = $this->chunkSize();
    $pages = (int) ceil($total / $chunk);
    $base = rtrim((string) $this->configFactory->get('hrefl_client.settings')->get('site_base_url'), '/');

    $writer = new \XMLWriter();
    $writer->openMemory();
    $writer->startDocument('1.0', 'UTF-8');
    $writer->setIndent(TRUE);
    $writer->startElement('sitemapindex');
    $writer->writeAttribute('xmlns', self::SITEMAP_NS);
    for ($i = 0; $i < $pages; $i++) {
      $writer->startElement('sitemap');
      $writer->writeElement('loc', $base . '/hrefl-sitemap.' . $i . '.xml');
      $writer->endElement();
    }
    $writer->endElement();
    $writer->endDocument();
    return $writer->outputMemory();
  }

  /**
   * The configured per-file URL cap, bounded by the spec ceiling.
   */
  private function chunkSize(): int {
    $configured = (int) $this->configFactory->get('hrefl_client.settings')->get('sitemap_chunk_size');
    if ($configured < 1) {
      $configured = self::MAX_URLS;
    }
    return min($configured, self::MAX_URLS);
  }

  private function clampPriority(float $priority): float {
    return max(0.0, min(1.0, $priority));
  }

}
