<?php

declare(strict_types=1);

namespace Drupal\Tests\hrefl_hub\Kernel;

use Drupal\KernelTests\KernelTestBase;

/**
 * Importing a review CSV applies confirm/reject decisions through the guard.
 *
 * @group hrefl_hub
 */
final class CsvImporterTest extends KernelTestBase {

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

  public function testImportAppliesDecisionsAndReportsBlocked(): void {
    $registry = $this->container->get('hrefl_hub.registry');
    $group = $registry->createGroup();
    $en = $this->addMember($registry, $group, 'global', 'en', 'en', 'https://ex.com/about-us', 1);
    $de = $this->addMember($registry, $group, 'de', 'de', 'de', 'https://ex.com/de/ueber-uns', 1);
    // Invalid target: confirming it must be blocked.
    $us = $this->addMember($registry, $group, 'us', 'en', 'en-US', 'https://ex.com/us/about-us', 0);

    $csv = implode("\n", [
      'decision,url',
      'confirm,https://ex.com/about-us',
      'confirm,https://ex.com/de/ueber-uns',
      'confirm,https://ex.com/us/about-us',
      // Unknown URL is skipped.
      'confirm,https://ex.com/unknown',
      // Blank decision leaves the row alone.
      'leave,https://ex.com/about-us',
    ]);

    $result = $this->container->get('hrefl_hub.csv_importer')->import($csv);

    $this->assertSame('confirmed', $registry->loadMember($en)['status']);
    $this->assertSame('confirmed', $registry->loadMember($de)['status']);
    $this->assertSame('proposed', $registry->loadMember($us)['status']);

    $this->assertSame(2, $result['applied']);
    $this->assertArrayHasKey('https://ex.com/us/about-us', $result['blocked']);
    // The unknown URL and the 'leave' row are skipped.
    $this->assertSame(2, $result['skipped']);
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
