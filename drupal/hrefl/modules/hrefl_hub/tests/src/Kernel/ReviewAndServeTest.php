<?php

declare(strict_types=1);

namespace Drupal\Tests\hrefl_hub\Kernel;

use Drupal\KernelTests\KernelTestBase;

/**
 * Phase 1 hub flow: a member only goes live once confirmed AND target-valid,
 * and the serve endpoint then returns the reciprocal alternate set.
 *
 * @group hrefl_hub
 */
final class ReviewAndServeTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'hrefl_hub'];

  private const GLOBAL_URL = 'https://pro.boehringer-ingelheim.com/about-us';
  private const DE_URL = 'https://pro.boehringer-ingelheim.com/de/ueber-uns';

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
   * Confirm is blocked until the target is valid; serve reflects confirmations.
   */
  public function testConfirmGuardAndServe(): void {
    /** @var \Drupal\hrefl_hub\Service\Registry $registry */
    $registry = $this->container->get('hrefl_hub.registry');
    /** @var \Drupal\hrefl_hub\Service\ReviewActions $review */
    $review = $this->container->get('hrefl_hub.review_actions');
    /** @var \Drupal\hrefl_hub\Service\Distributor $distributor */
    $distributor = $this->container->get('hrefl_hub.distributor');

    $group = $registry->createGroup();
    $globalId = $registry->upsertMember([
      'group_uuid' => $group,
      'market' => 'global',
      'language' => 'en',
      'hreflang' => 'en',
      'url' => self::GLOBAL_URL,
      'valid' => 1,
      'status' => 'proposed',
    ]);
    $deId = $registry->upsertMember([
      'group_uuid' => $group,
      'market' => 'de',
      'language' => 'de',
      'hreflang' => 'de',
      'url' => self::DE_URL,
      // Target not yet validated.
      'valid' => 0,
      'status' => 'proposed',
    ]);

    // Cannot confirm a member whose target has not passed validation.
    $violations = $review->confirm($deId);
    $this->assertNotEmpty($violations);
    $this->assertSame([], $distributor->alternatesForMarket('de'), 'Nothing served before confirmation.');

    // Validate the target, then both confirmations succeed.
    $registry->setValid($deId, TRUE, 1000);
    $this->assertSame([], $review->confirm($globalId));
    $this->assertSame([], $review->confirm($deId));

    // The DE page is now served with the reciprocal set + x-default.
    $pages = $distributor->alternatesForMarket('de');
    $this->assertCount(1, $pages);
    $this->assertSame(self::DE_URL, $pages[0]['url']);

    $byCode = [];
    foreach ($pages[0]['alternates'] as $alt) {
      $byCode[$alt['hreflang']] = $alt['href'];
    }
    $this->assertSame(self::GLOBAL_URL, $byCode['en']);
    $this->assertSame(self::DE_URL, $byCode['de']);
    $this->assertSame(self::GLOBAL_URL, $byCode['x-default']);
  }

  /**
   * A code collision with a confirmed sibling blocks confirmation.
   */
  public function testConfirmBlockedOnCodeCollision(): void {
    /** @var \Drupal\hrefl_hub\Service\Registry $registry */
    $registry = $this->container->get('hrefl_hub.registry');
    /** @var \Drupal\hrefl_hub\Service\ReviewActions $review */
    $review = $this->container->get('hrefl_hub.review_actions');

    $group = $registry->createGroup();
    $firstId = $registry->upsertMember([
      'group_uuid' => $group,
      'market' => 'us',
      'language' => 'en',
      'hreflang' => 'en-US',
      'url' => 'https://pro.boehringer-ingelheim.com/us/about-us',
      'valid' => 1,
      'status' => 'proposed',
    ]);
    $dupId = $registry->upsertMember([
      'group_uuid' => $group,
      'market' => 'us',
      'language' => 'en',
      'hreflang' => 'en-US',
      'url' => 'https://pro.boehringer-ingelheim.com/us/about-us-2',
      'valid' => 1,
      'status' => 'proposed',
    ]);

    $this->assertSame([], $review->confirm($firstId));
    $violations = $review->confirm($dupId);
    $this->assertNotEmpty($violations);
    $this->assertStringContainsString('Duplicate hreflang code', implode(' ', $violations));
  }

}
