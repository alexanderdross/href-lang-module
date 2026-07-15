<?php

declare(strict_types=1);

namespace Drupal\Tests\hrefl_hub\Kernel;

use Drupal\KernelTests\KernelTestBase;

/**
 * The Monitor reports coverage and flags graph-validation issues.
 *
 * @group hrefl_hub
 */
final class MonitorTest extends KernelTestBase {

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

  public function testHealthyGroupReportsFullCoverageNoIssues(): void {
    $registry = $this->container->get('hrefl_hub.registry');
    $group = $registry->createGroup();
    $this->addConfirmed($registry, $group, 'global', 'en', 'en', 'https://ex.com/about-us', 1);
    $this->addConfirmed($registry, $group, 'de', 'de', 'de', 'https://ex.com/de/ueber-uns', 1);

    $report = $this->container->get('hrefl_hub.monitor')->report();

    $this->assertSame(2, $report['totals']['confirmed']);
    $this->assertSame(1.0, $report['coverage']);
    $this->assertTrue($report['healthy']);
    $this->assertSame([], $report['issues']['invalid_targets']);
    $this->assertSame([], $report['issues']['missing_x_default']);
    $this->assertSame([], $report['issues']['lonely_confirmed']);
  }

  public function testIssuesAreDetected(): void {
    $registry = $this->container->get('hrefl_hub.registry');

    // Group A: no Global member (missing x-default) + a code collision.
    $a = $registry->createGroup();
    $this->addConfirmed($registry, $a, 'us', 'en', 'en-US', 'https://ex.com/us/about-us', 1);
    $this->addConfirmed($registry, $a, 'ca', 'en', 'en-US', 'https://ex.com/ca/about-us', 1);

    // Group B: a single confirmed member with an invalid target.
    $b = $registry->createGroup();
    $this->addConfirmed($registry, $b, 'de', 'de', 'de', 'https://ex.com/de/x', 0);

    $issues = $this->container->get('hrefl_hub.monitor')->report()['issues'];

    // Invalid target flagged.
    $this->assertCount(1, $issues['invalid_targets']);
    $this->assertSame('https://ex.com/de/x', $issues['invalid_targets'][0]['url']);

    // Code collision (en-US twice) flagged.
    $this->assertCount(1, $issues['code_collisions']);
    $this->assertSame('en-US', $issues['code_collisions'][0]['hreflang']);

    // Group A has >=2 confirmed but no Global member.
    $this->assertContains($a, $issues['missing_x_default']);

    // Group B has a single confirmed member (nothing to link to).
    $lonelyGroups = array_column($issues['lonely_confirmed'], 'group_uuid');
    $this->assertContains($b, $lonelyGroups);
  }

  private function addConfirmed($registry, string $group, string $market, string $language, string $hreflang, string $url, int $valid): void {
    $id = $registry->upsertMember([
      'group_uuid' => $group,
      'market' => $market,
      'language' => $language,
      'hreflang' => $hreflang,
      'url' => $url,
      'valid' => $valid,
      'status' => 'proposed',
    ]);
    $registry->setStatus($id, 'confirmed', 'test');
    $registry->setValid($id, (bool) $valid, 1000);
  }

}
