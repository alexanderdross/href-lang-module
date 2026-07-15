<?php

declare(strict_types=1);

namespace Drupal\Tests\hrefl_hub\Unit;

use Drupal\hrefl_hub\Service\VectorStore;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\hrefl_hub\Service\VectorStore
 * @group hrefl_hub
 */
final class CosineTest extends UnitTestCase {

  /**
   * @covers ::cosine
   * @dataProvider cosineProvider
   */
  public function testCosine(array $a, array $b, float $expected): void {
    $this->assertEqualsWithDelta($expected, VectorStore::cosine($a, $b), 1e-9);
  }

  public static function cosineProvider(): array {
    return [
      'identical' => [[1.0, 2.0, 3.0], [1.0, 2.0, 3.0], 1.0],
      'orthogonal' => [[1.0, 0.0], [0.0, 1.0], 0.0],
      'opposite' => [[1.0, 0.0], [-1.0, 0.0], -1.0],
      '45 degrees' => [[1.0, 0.0], [1.0, 1.0], 1 / sqrt(2)],
      'zero vector' => [[0.0, 0.0], [1.0, 1.0], 0.0],
      'empty' => [[], [1.0], 0.0],
    ];
  }

}
