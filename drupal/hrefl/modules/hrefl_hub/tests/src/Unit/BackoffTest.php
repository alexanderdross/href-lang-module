<?php

declare(strict_types=1);

namespace Drupal\Tests\hrefl_hub\Unit;

use Drupal\hrefl_hub\Http\Backoff;
use Drupal\Tests\UnitTestCase;

/**
 * The retry policy for outbound AI/embedding calls: which statuses are retried
 * and how long to back off. Pure - no network, no sleep.
 *
 * @coversDefaultClass \Drupal\hrefl_hub\Http\Backoff
 * @group hrefl_hub
 */
final class BackoffTest extends UnitTestCase {

  /**
   * @covers ::retriable
   * @dataProvider retriableProvider
   */
  public function testRetriable(?int $status, bool $expected): void {
    $this->assertSame($expected, Backoff::retriable($status));
  }

  public static function retriableProvider(): array {
    return [
      'transport error (null)' => [NULL, TRUE],
      '429' => [429, TRUE],
      '500' => [500, TRUE],
      '503' => [503, TRUE],
      '504' => [504, TRUE],
      '200' => [200, FALSE],
      '400' => [400, FALSE],
      '401' => [401, FALSE],
    ];
  }

  /**
   * @covers ::seconds
   */
  public function testExponentialAndCapped(): void {
    $this->assertSame(1, Backoff::seconds(0));
    $this->assertSame(2, Backoff::seconds(1));
    $this->assertSame(4, Backoff::seconds(2));
    $this->assertSame(8, Backoff::seconds(3));
    $this->assertSame(8, Backoff::seconds(6));
  }

  /**
   * @covers ::seconds
   */
  public function testRetryAfterWinsAndIsCapped(): void {
    $this->assertSame(5, Backoff::seconds(0, 5));
    $this->assertSame(30, Backoff::seconds(0, 120));
    // Zero/negative Retry-After ignored -> exponential.
    $this->assertSame(1, Backoff::seconds(0, 0));
  }

}
