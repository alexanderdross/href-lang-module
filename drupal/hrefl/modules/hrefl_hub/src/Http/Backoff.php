<?php

declare(strict_types=1);

namespace Drupal\hrefl_hub\Http;

/**
 * Retry policy for outbound AI / embedding calls. Pure and testable: no clock,
 * no I/O - the caller sleeps for `seconds()` and decides with `retriable()`.
 */
final class Backoff {

  /**
   * HTTP statuses worth retrying (transient). A NULL status means a transport
   * error (no response), which is also retriable.
   */
  private const RETRIABLE = [429, 500, 502, 503, 504];

  /**
   * Whether a response status warrants a retry.
   */
  public static function retriable(?int $status): bool {
    return $status === NULL || in_array($status, self::RETRIABLE, TRUE);
  }

  /**
   * Seconds to wait before the next attempt.
   *
   * Honors a server `Retry-After` (capped at 30s) when given; otherwise
   * exponential: base·2^attempt, capped. attempt is 0-based.
   */
  public static function seconds(int $attempt, ?int $retryAfter = NULL, int $base = 1, int $cap = 8): int {
    if ($retryAfter !== NULL && $retryAfter > 0) {
      return min($retryAfter, 30);
    }
    return min($base * (2 ** max(0, $attempt)), $cap);
  }

}
