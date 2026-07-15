<?php

declare(strict_types=1);

namespace Drupal\Tests\hrefl_hub\Kernel;

use Drupal\hrefl_hub\Batch\ReviewBatch;
use Drupal\KernelTests\KernelTestBase;

/**
 * The bulk-review batch applies the guard to every member and accumulates the
 * outcome across chunks.
 *
 * @group hrefl_hub
 */
final class ReviewBatchTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'hrefl_hub'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('hrefl_hub', [
      'hrefl_group',
      'hrefl_group_member',
      'hrefl_glossary',
      'hrefl_feedback',
    ]);
    $this->installConfig(['hrefl_hub']);
  }

  public function testBatchConfirmGuardsAndCounts(): void {
    $registry = $this->container->get('hrefl_hub.registry');
    $group = $registry->createGroup();
    $en = $this->addMember($registry, $group, 'global', 'en', 'en', 'https://ex.com/about-us', 1);
    $de = $this->addMember($registry, $group, 'de', 'de', 'de', 'https://ex.com/de/ueber-uns', 1);
    $us = $this->addMember($registry, $group, 'us', 'en', 'en-US', 'https://ex.com/us/about-us', 0);

    $context = [];
    ReviewBatch::process('confirm', [$en, $de, $us], $context);

    // The two valid members are confirmed; the invalid-target one is skipped.
    $this->assertSame('confirmed', $registry->loadMember($en)['status']);
    $this->assertSame('confirmed', $registry->loadMember($de)['status']);
    $this->assertSame('proposed', $registry->loadMember($us)['status']);

    // Result counters accumulate for the finished summary.
    $this->assertSame(2, $context['results']['confirmed']);
    $this->assertSame(1, $context['results']['blocked']);
    $this->assertSame(3, $context['results']['processed']);
  }

  public function testBatchAccumulatesAcrossChunks(): void {
    $registry = $this->container->get('hrefl_hub.registry');
    $group = $registry->createGroup();
    $a = $this->addMember($registry, $group, 'de', 'de', 'de', 'https://ex.com/de/a', 1);
    $b = $this->addMember($registry, $group, 'us', 'en', 'en-US', 'https://ex.com/us/a', 1);

    // Two separate chunks writing into the same context (as the batch does).
    $context = [];
    ReviewBatch::process('reject', [$a], $context);
    ReviewBatch::process('reject', [$b], $context);

    $this->assertSame('rejected', $registry->loadMember($a)['status']);
    $this->assertSame('rejected', $registry->loadMember($b)['status']);
    $this->assertSame(2, $context['results']['rejected']);
  }

  private function addMember($registry, string $group, string $market, string $language, string $hreflang, string $url, int $valid): int {
    return $registry->upsertMember([
      'group_uuid' => $group,
      'market' => $market,
      'language' => $language,
      'hreflang' => $hreflang,
      'url' => $url,
      'valid' => $valid,
      'status' => 'proposed',
    ]);
  }

}
