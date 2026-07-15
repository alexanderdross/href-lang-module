<?php

declare(strict_types=1);

namespace Drupal\Tests\hrefl_hub\Kernel;

use Drupal\KernelTests\KernelTestBase;

/**
 * Phase 1 URL-pattern matching: ingested pages coalesce into proposed groups
 * by shared leaf-slug, and the learned glossary bridges cross-language slugs.
 *
 * @group hrefl_hub
 */
final class PatternMatchingTest extends KernelTestBase {

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
   * Three same-slug pages in different markets converge onto one group.
   */
  public function testSameSlugPagesCoalesce(): void {
    $registry = $this->container->get('hrefl_hub.registry');
    $this->ingest($registry, 'global', 'en', 'en', 'https://pro.boehringer-ingelheim.com/about-us');
    $this->ingest($registry, 'us', 'en', 'en-US', 'https://pro.boehringer-ingelheim.com/us/about-us');
    $this->ingest($registry, 'ca', 'en', 'en-CA', 'https://pro.boehringer-ingelheim.com/ca/about-us');

    $this->runMatchPass();
    $this->runMatchPass();

    $groups = $this->groupUuids();
    $this->assertCount(1, array_unique($groups), 'All three members share one group.');
    $this->assertSame(1, $this->groupCount(), 'Orphaned singleton groups were cleaned up.');
  }

  /**
   * A German page joins the English group once the glossary knows the slug pair.
   */
  public function testGlossaryBridgesCrossLanguageSlug(): void {
    $registry = $this->container->get('hrefl_hub.registry');
    $this->ingest($registry, 'global', 'en', 'en', 'https://pro.boehringer-ingelheim.com/about-us');
    $this->ingest($registry, 'de', 'de', 'de', 'https://pro.boehringer-ingelheim.com/de/ueber-uns');

    // Without a glossary entry the slugs differ, so nothing links them.
    $this->runMatchPass();
    $this->assertGreaterThan(1, count(array_unique($this->groupUuids())), 'Unlinked before glossary.');

    // Teach the equivalence (as a confirmation would), then re-match.
    $registry->addGlossaryEntry('en', 'de', 'about-us', 'ueber-uns');
    $registry->addGlossaryEntry('de', 'en', 'ueber-uns', 'about-us');
    $this->runMatchPass();
    $this->runMatchPass();

    $this->assertCount(1, array_unique($this->groupUuids()), 'German page joined the English group.');
  }

  /**
   * Confirming a cross-language pair grows the glossary (feedback loop).
   */
  public function testConfirmationGrowsGlossary(): void {
    $registry = $this->container->get('hrefl_hub.registry');
    $review = $this->container->get('hrefl_hub.review_actions');

    $group = $registry->createGroup();
    $enId = $registry->upsertMember([
      'group_uuid' => $group,
      'market' => 'global',
      'language' => 'en',
      'hreflang' => 'en',
      'url' => 'https://pro.boehringer-ingelheim.com/about-us',
      'valid' => 1,
      'status' => 'proposed',
    ]);
    $deId = $registry->upsertMember([
      'group_uuid' => $group,
      'market' => 'de',
      'language' => 'de',
      'hreflang' => 'de',
      'url' => 'https://pro.boehringer-ingelheim.com/de/ueber-uns',
      'valid' => 1,
      'status' => 'proposed',
    ]);

    $this->assertSame([], $review->confirm($enId));
    $this->assertSame([], $review->confirm($deId));

    // The glossary now knows ueber-uns <-> about-us in both directions.
    $this->assertContains('ueber-uns', $registry->glossaryEquivalents('en', 'about-us'));
    $this->assertContains('about-us', $registry->glossaryEquivalents('de', 'ueber-uns'));
  }

  /**
   * Ingest a member into its own singleton group, as IngestController does.
   */
  private function ingest($registry, string $market, string $language, string $hreflang, string $url): void {
    $registry->upsertMember([
      'group_uuid' => $registry->createGroup(),
      'market' => $market,
      'language' => $language,
      'hreflang' => $hreflang,
      'url' => $url,
      'valid' => 1,
      'status' => 'proposed',
      'matched_by' => 'manual',
    ]);
  }

  /**
   * Run one Tier-A matching pass, mirroring hrefl_hub_cron().
   */
  private function runMatchPass(): void {
    $registry = $this->container->get('hrefl_hub.registry');
    $engine = $this->container->get('hrefl_hub.mapping_engine');
    foreach ($registry->membersNeedingMatch(200) as $member) {
      if ((int) $member['locked'] === 1) {
        continue;
      }
      $engine->match([
        'url' => $member['url'],
        'market' => $member['market'],
        'language' => $member['language'],
        'hreflang' => $member['hreflang'],
        'valid' => $member['valid'],
      ]);
    }
    $registry->deleteEmptyGroups();
  }

  /**
   * @return string[]
   */
  private function groupUuids(): array {
    return $this->container->get('database')
      ->select('hrefl_group_member', 'm')
      ->fields('m', ['group_uuid'])
      ->execute()
      ->fetchCol();
  }

  private function groupCount(): int {
    return (int) $this->container->get('database')
      ->select('hrefl_group', 'g')
      ->countQuery()
      ->execute()
      ->fetchField();
  }

}
