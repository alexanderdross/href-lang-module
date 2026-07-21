<?php

declare(strict_types=1);

namespace Drupal\Tests\hrefl_client\Unit;

use Drupal\hrefl_client\HreflLocale;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\hrefl_client\HreflLocale
 * @group hrefl_client
 */
final class HreflLocaleTest extends UnitTestCase {

  /**
   * @covers ::label
   * @dataProvider labelProvider
   */
  public function testLabel(string $code, string $expected): void {
    $this->assertSame($expected, HreflLocale::label($code));
  }

  public static function labelProvider(): array {
    return [
      'language only' => ['de', 'Deutsch'],
      'language + region' => ['en-US', 'English (United States)'],
      'fr-CA' => ['fr-CA', 'Français (Canada)'],
      'x-default' => ['x-default', 'International'],
      'script ignored' => ['zh-Hant-TW', '中文 (Taiwan)'],
      'M49 region' => ['es-419', 'Español (Latin America)'],
    ];
  }

  /**
   * @covers ::label
   */
  public function testUnknownLanguageFallsBackToCode(): void {
    $label = HreflLocale::label('xx-YY');
    $this->assertNotSame('', $label);
    $this->assertStringContainsString('XX', $label);
  }

}
