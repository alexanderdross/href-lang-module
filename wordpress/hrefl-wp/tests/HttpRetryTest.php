<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The retry policy for outbound AI/embedding calls: which statuses are retried
 * and how long to back off. Pure - no network, no sleep.
 */
final class HttpRetryTest extends TestCase {

    /**
     * @dataProvider retriableProvider
     */
    public function testRetriable(?int $status, bool $expected): void {
        $this->assertSame($expected, Hrefl_Http::retriable($status));
    }

    public static function retriableProvider(): array {
        return [
            'transport error (null)' => [null, true],
            '429 rate limited'       => [429, true],
            '500'                    => [500, true],
            '503'                    => [503, true],
            '504'                    => [504, true],
            '200 ok'                 => [200, false],
            '400 bad request'        => [400, false],
            '401 unauthorized'       => [401, false],
            '404'                    => [404, false],
        ];
    }

    public function testBackoffIsExponentialAndCapped(): void {
        $this->assertSame(1, Hrefl_Http::backoff_delay(0, null));
        $this->assertSame(2, Hrefl_Http::backoff_delay(1, null));
        $this->assertSame(4, Hrefl_Http::backoff_delay(2, null));
        $this->assertSame(8, Hrefl_Http::backoff_delay(3, null));
        // Capped at 8.
        $this->assertSame(8, Hrefl_Http::backoff_delay(6, null));
    }

    public function testRetryAfterWinsAndIsCapped(): void {
        $this->assertSame(5, Hrefl_Http::backoff_delay(0, 5));
        // Even a big Retry-After is capped at 30s.
        $this->assertSame(30, Hrefl_Http::backoff_delay(0, 120));
        // A zero/negative Retry-After is ignored, exponential applies.
        $this->assertSame(1, Hrefl_Http::backoff_delay(0, 0));
    }
}
