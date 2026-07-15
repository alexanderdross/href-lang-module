<?php

declare(strict_types=1);

namespace Drupal\Tests\hrefl_hub\Kernel;

use Drupal\hrefl_hub\Controller\CsvController;
use Drupal\KernelTests\KernelTestBase;

/**
 * The review CSV export surfaces titles and AI-proposed translations so an
 * editor can review the mappings AND the translations before publishing.
 *
 * @group hrefl_hub
 */
final class CsvExportTest extends KernelTestBase {

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

  public function testExportIncludesTitleAndTranslation(): void {
    /** @var \Drupal\hrefl_hub\Service\Registry $registry */
    $registry = $this->container->get('hrefl_hub.registry');
    $group = $registry->createGroup();
    $registry->upsertMember([
      'group_uuid' => $group,
      'market' => 'global',
      'language' => 'en',
      'hreflang' => 'en',
      'url' => 'https://pro.boehringer-ingelheim.com/about-us',
      'title' => 'About us',
      'valid' => 1,
      'status' => 'proposed',
    ]);
    $registry->upsertMember([
      'group_uuid' => $group,
      'market' => 'de',
      'language' => 'de',
      'hreflang' => 'de',
      'url' => 'https://pro.boehringer-ingelheim.com/de/ueber-uns',
      'title' => 'Über uns',
      'matched_by' => 'llm',
      'confidence' => 0.7,
      'signals' => ['translated_title' => 'Über uns', 'translated_slug' => 'ueber-uns'],
      'valid' => 1,
      'status' => 'proposed',
    ]);

    $csv = (string) CsvController::create($this->container)->export()->getContent();
    $lines = array_map('str_getcsv', array_filter(explode("\n", $csv)));
    $header = $lines[0];

    // New review columns exist.
    foreach (['title', 'is_x_default', 'translated_title', 'translated_slug'] as $col) {
      $this->assertContains($col, $header, "Column $col present");
    }

    $rows = [];
    foreach (array_slice($lines, 1) as $line) {
      $rows[] = array_combine($header, $line);
    }
    $byUrl = array_column($rows, NULL, 'url');

    // Global row: title present, marked x-default by convention.
    $global = $byUrl['https://pro.boehringer-ingelheim.com/about-us'];
    $this->assertSame('About us', $global['title']);
    $this->assertSame('yes', $global['is_x_default']);

    // German row: AI-proposed translation surfaced for review.
    $de = $byUrl['https://pro.boehringer-ingelheim.com/de/ueber-uns'];
    $this->assertSame('Über uns', $de['translated_title']);
    $this->assertSame('ueber-uns', $de['translated_slug']);
    $this->assertSame('', $de['is_x_default']);
  }

}
