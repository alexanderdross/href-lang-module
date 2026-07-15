<?php

declare(strict_types=1);

namespace Drupal\Tests\hrefl_hub\Unit;

use Drupal\hrefl_hub\Service\SlugNormalizer;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\hrefl_hub\Service\SlugNormalizer
 * @group hrefl_hub
 */
final class SlugNormalizerTest extends UnitTestCase {

  /**
   * @covers ::slug
   * @dataProvider slugProvider
   */
  public function testSlug(string $url, string $expected): void {
    $this->assertSame($expected, (new SlugNormalizer())->slug($url));
  }

  public static function slugProvider(): array {
    return [
      'global leaf' => ['https://ex.com/about-us', 'about-us'],
      'market-prefixed leaf matches global' => ['https://ex.com/us/about-us', 'about-us'],
      'german leaf' => ['https://ex.com/de/ueber-uns', 'ueber-uns'],
      'canada french leaf' => ['https://ex.com/ca/fr/a-propos', 'a-propos'],
      'trailing slash ignored' => ['https://ex.com/us/about-us/', 'about-us'],
      'lowercased' => ['https://ex.com/US/About-Us', 'about-us'],
      'url-decoded' => ['https://ex.com/de/%C3%BCber', 'über'],
      'root is empty' => ['https://ex.com/', ''],
    ];
  }

}
