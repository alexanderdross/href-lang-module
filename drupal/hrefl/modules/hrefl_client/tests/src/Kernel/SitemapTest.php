<?php

declare(strict_types=1);

namespace Drupal\Tests\hrefl_client\Kernel;

use Drupal\KernelTests\KernelTestBase;

/**
 * The multilingual sitemap generator emits a valid urlset with alternates,
 * lastmod, and priority from the local store.
 *
 * @group hrefl_client
 */
final class SitemapTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'language', 'hrefl_client'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('hrefl_client', ['hrefl_client_alternates']);
    $this->installConfig(['hrefl_client']);
  }

  public function testSitemapContainsAlternatesLastmodPriority(): void {
    $payload = [
      'pages' => [
        [
          'url' => 'https://pro.boehringer-ingelheim.com/de/ueber-uns',
          'group_uuid' => '7f3a0000-0000-4000-8000-000000000001',
          // 2001-09-09T01:46:40Z.
          'lastmod' => 1000000000,
          'alternates' => [
            ['hreflang' => 'en', 'href' => 'https://pro.boehringer-ingelheim.com/about-us'],
            ['hreflang' => 'de', 'href' => 'https://pro.boehringer-ingelheim.com/de/ueber-uns'],
            ['hreflang' => 'x-default', 'href' => 'https://pro.boehringer-ingelheim.com/about-us'],
          ],
        ],
      ],
    ];
    $this->container->get('hrefl_client.alternates_consumer')->ingestPayload($payload);

    $xml = $this->container->get('hrefl_client.sitemap_generator')->render();

    // Well-formed and namespaced.
    $this->assertStringContainsString('<urlset', $xml);
    $this->assertStringContainsString('xmlns:xhtml="http://www.w3.org/1999/xhtml"', $xml);
    $this->assertStringContainsString('<loc>https://pro.boehringer-ingelheim.com/de/ueber-uns</loc>', $xml);
    // XML enhancements.
    $this->assertStringContainsString('<lastmod>2001-09-09T01:46:40Z</lastmod>', $xml);
    $this->assertStringContainsString('<priority>0.5</priority>', $xml);
    // Cross-backend alternates present as xhtml:link.
    $this->assertStringContainsString('<xhtml:link rel="alternate" hreflang="en" href="https://pro.boehringer-ingelheim.com/about-us"', $xml);
    $this->assertStringContainsString('hreflang="x-default"', $xml);

    // Parses as valid XML.
    $doc = simplexml_load_string($xml);
    $this->assertNotFalse($doc);
    $this->assertCount(1, $doc->url);
  }

  public function testLargeSitemapBecomesChunkedIndex(): void {
    // Force a tiny chunk size so 3 URLs overflow into an index of 2 files.
    $this->config('hrefl_client.settings')
      ->set('sitemap_chunk_size', 2)
      ->set('site_base_url', 'https://pro.boehringer-ingelheim.com')
      ->save();

    $pages = [];
    foreach (['a', 'b', 'c'] as $slug) {
      $url = "https://pro.boehringer-ingelheim.com/de/$slug";
      $pages[] = [
        'url' => $url,
        'alternates' => [
          ['hreflang' => 'de', 'href' => $url],
          ['hreflang' => 'en', 'href' => "https://pro.boehringer-ingelheim.com/$slug"],
        ],
      ];
    }
    $this->container->get('hrefl_client.alternates_consumer')->ingestPayload(['pages' => $pages]);

    $generator = $this->container->get('hrefl_client.sitemap_generator');
    $this->assertTrue($generator->isIndex());

    // Entry point is a sitemap index listing 2 chunk files.
    $index = simplexml_load_string($generator->render());
    $this->assertNotFalse($index);
    $this->assertSame('sitemapindex', $index->getName());
    $this->assertCount(2, $index->sitemap);
    $this->assertStringContainsString('/hrefl-sitemap.0.xml', (string) $index->sitemap[0]->loc);
    $this->assertStringContainsString('/hrefl-sitemap.1.xml', (string) $index->sitemap[1]->loc);

    // Chunks page the URLs: 2 on page 0, the remaining 1 on page 1.
    $chunk0 = simplexml_load_string($generator->renderChunk(0));
    $chunk1 = simplexml_load_string($generator->renderChunk(1));
    $this->assertCount(2, $chunk0->url);
    $this->assertCount(1, $chunk1->url);
  }

}
