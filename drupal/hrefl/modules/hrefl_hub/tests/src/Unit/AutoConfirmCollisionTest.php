<?php

declare(strict_types=1);

namespace Drupal\Tests\hrefl_hub\Unit;

use Drupal\hrefl_hub\Service\MappingEngine;
use Drupal\Tests\UnitTestCase;

/**
 * The auto-confirm collision guard: the engine must not confirm a second member
 * for an hreflang code already confirmed in its group.
 *
 * @coversDefaultClass \Drupal\hrefl_hub\Service\MappingEngine
 * @group hrefl_hub
 */
final class AutoConfirmCollisionTest extends UnitTestCase {

  /**
   * @covers ::collides
   */
  public function testCollidesWhenConfirmedSiblingSharesCode(): void {
    $member = ['id' => 2, 'hreflang' => 'en', 'status' => 'proposed'];
    $group = [
      ['id' => 1, 'hreflang' => 'en', 'status' => 'confirmed'],
      ['id' => 2, 'hreflang' => 'en', 'status' => 'proposed'],
    ];
    $this->assertTrue(MappingEngine::collides($member, $group));
  }

  /**
   * @covers ::collides
   */
  public function testNoCollisionWithDistinctCodes(): void {
    $member = ['id' => 2, 'hreflang' => 'de', 'status' => 'proposed'];
    $group = [
      ['id' => 1, 'hreflang' => 'en', 'status' => 'confirmed'],
      ['id' => 2, 'hreflang' => 'de', 'status' => 'proposed'],
    ];
    $this->assertFalse(MappingEngine::collides($member, $group));
  }

  /**
   * @covers ::collides
   */
  public function testProposedSiblingWithSameCodeDoesNotBlock(): void {
    // Only a *confirmed* sibling collides; two proposals can coexist until one
    // is confirmed.
    $member = ['id' => 2, 'hreflang' => 'en', 'status' => 'proposed'];
    $group = [
      ['id' => 1, 'hreflang' => 'en', 'status' => 'proposed'],
      ['id' => 2, 'hreflang' => 'en', 'status' => 'proposed'],
    ];
    $this->assertFalse(MappingEngine::collides($member, $group));
  }

  /**
   * @covers ::collides
   */
  public function testMemberDoesNotCollideWithItself(): void {
    $member = ['id' => 5, 'hreflang' => 'en', 'status' => 'confirmed'];
    $group = [['id' => 5, 'hreflang' => 'en', 'status' => 'confirmed']];
    $this->assertFalse(MappingEngine::collides($member, $group));
  }

}
