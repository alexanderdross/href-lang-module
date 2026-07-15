<?php

declare(strict_types=1);

namespace Drupal\Tests\hrefl_hub\Kernel;

use Drupal\hrefl_hub\Controller\CsvController;
use Drupal\KernelTests\KernelTestBase;

/**
 * Guards specific correctness fixes so they cannot silently regress.
 *
 * See docs/BEST-PRACTICES-AUDIT.md §3 for the history of each.
 *
 * @group hrefl_hub
 */
final class RegressionTest extends KernelTestBase {

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

  /**
   * Bug: CSV export only listed proposed/held rows, so confirmed rows vanished.
   */
  public function testExportIncludesConfirmed(): void {
    $registry = $this->container->get('hrefl_hub.registry');
    $id = $registry->upsertMember([
      'group_uuid' => $registry->createGroup(),
      'market' => 'global',
      'language' => 'en',
      'hreflang' => 'en',
      'url' => 'https://ex.com/confirmed-page',
      'valid' => 1,
      'status' => 'proposed',
    ]);
    $registry->setStatus($id, 'confirmed', 'test');

    $csv = (string) CsvController::create($this->container)->export()->getContent();
    $this->assertStringContainsString('https://ex.com/confirmed-page', $csv);
  }

  /**
   * Bug: member lookup scanned only need-match rows, missing confirmed members.
   */
  public function testMemberIdForUrlFindsConfirmed(): void {
    $registry = $this->container->get('hrefl_hub.registry');
    $id = $registry->upsertMember([
      'group_uuid' => $registry->createGroup(),
      'market' => 'de',
      'language' => 'de',
      'hreflang' => 'de',
      'url' => 'https://ex.com/de/x',
      'valid' => 1,
      'status' => 'proposed',
    ]);
    $registry->setStatus($id, 'confirmed', 'test');

    $this->assertSame($id, $registry->memberIdForUrl('https://ex.com/de/x'));
    $this->assertNull($registry->memberIdForUrl('https://ex.com/de/missing'));
  }

  /**
   * Bug: re-homing a member orphaned its old singleton group.
   */
  public function testEmptyGroupsCleaned(): void {
    $registry = $this->container->get('hrefl_hub.registry');
    $g1 = $registry->createGroup();
    $g2 = $registry->createGroup();
    $registry->upsertMember([
      'group_uuid' => $g1,
      'market' => 'us',
      'language' => 'en',
      'hreflang' => 'en-US',
      'url' => 'https://ex.com/us/x',
      'valid' => 1,
      'status' => 'proposed',
    ]);
    // Move the member to g2, orphaning g1.
    $registry->upsertMember([
      'group_uuid' => $g2,
      'market' => 'us',
      'language' => 'en',
      'hreflang' => 'en-US',
      'url' => 'https://ex.com/us/x',
      'valid' => 1,
      'status' => 'proposed',
    ]);

    $this->assertSame(2, $registry->countGroups());
    $registry->deleteEmptyGroups();
    $this->assertSame(1, $registry->countGroups());
  }

}
