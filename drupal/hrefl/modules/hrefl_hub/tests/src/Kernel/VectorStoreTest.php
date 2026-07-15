<?php

declare(strict_types=1);

namespace Drupal\Tests\hrefl_hub\Kernel;

use Drupal\KernelTests\KernelTestBase;

/**
 * The vector store finds the nearest cross-market neighbour above threshold and
 * excludes the source market.
 *
 * @group hrefl_hub
 */
final class VectorStoreTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'hrefl_hub'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('hrefl_hub', ['hrefl_embedding']);
  }

  public function testNearestExcludesSourceMarketAndThreshold(): void {
    /** @var \Drupal\hrefl_hub\Service\VectorStore $store */
    $store = $this->container->get('hrefl_hub.vector_store');

    $store->upsert('https://ex.com/about-us', 'global', 'en', 'h1', [1.0, 0.0, 0.0]);
    $store->upsert('https://ex.com/us/about-us', 'us', 'en', 'h2', [0.9, 0.1, 0.0]);
    $store->upsert('https://ex.com/de/impressum', 'de', 'de', 'h3', [0.0, 0.0, 1.0]);

    $this->assertSame(3, $store->count());

    // Query with the Global page's vector, excluding its own market.
    $hits = $store->nearest([1.0, 0.0, 0.0], 'global', 5, 0.5);

    // Only the US page is a strong neighbour; the DE page is below threshold,
    // and the Global page is excluded by market.
    $this->assertCount(1, $hits);
    $this->assertSame('https://ex.com/us/about-us', $hits[0]['url']);
    $this->assertSame('us', $hits[0]['market']);
    $this->assertGreaterThan(0.9, $hits[0]['score']);
  }

  public function testContentHashRoundTrip(): void {
    /** @var \Drupal\hrefl_hub\Service\VectorStore $store */
    $store = $this->container->get('hrefl_hub.vector_store');
    $this->assertNull($store->contentHashFor('https://ex.com/x'));
    $store->upsert('https://ex.com/x', 'us', 'en', 'abc123', [0.1, 0.2]);
    $this->assertSame('abc123', $store->contentHashFor('https://ex.com/x'));
    // Re-upsert with a new hash overwrites (same URL key).
    $store->upsert('https://ex.com/x', 'us', 'en', 'def456', [0.3, 0.4]);
    $this->assertSame('def456', $store->contentHashFor('https://ex.com/x'));
    $this->assertSame(1, $store->count());
  }

}
