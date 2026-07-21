<?php
/**
 * Outbound HTTP with bounded retry-and-backoff, for the AI / embedding calls.
 *
 * A transport error (WP_Error) or a transient status (429/5xx) is retried up to
 * $max_retries times, honoring a server Retry-After; anything else returns as-is.
 * The retry policy (`retriable`, `backoff_delay`) is pure and unit-tested;
 * `post_json` adds the wp_remote_post + sleep around it. Mirrors the Drupal
 * Backoff/RetriesHttp pair.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class Hrefl_Http {

    /**
     * POST with retry. Returns the final wp_remote_post result (response array
     * or WP_Error), exactly like wp_remote_post.
     *
     * @param array<string,mixed> $args
     * @return array<string,mixed>|WP_Error
     */
    public static function post_json(string $url, array $args, int $max_retries = 2) {
        $attempt = 0;
        while (true) {
            $resp   = wp_remote_post($url, $args);
            $status = is_wp_error($resp) ? null : (int) wp_remote_retrieve_response_code($resp);
            if ($attempt >= $max_retries || !self::retriable($status)) {
                return $resp;
            }
            $retry_after = is_wp_error($resp) ? null : self::header_int($resp, 'retry-after');
            $delay = self::backoff_delay($attempt, $retry_after);
            if ($delay > 0) {
                sleep($delay);
            }
            $attempt++;
        }
    }

    /**
     * Whether a response status warrants a retry. A null status means a
     * transport error (WP_Error), which is retriable.
     */
    public static function retriable(?int $status): bool {
        return $status === null || in_array($status, [429, 500, 502, 503, 504], true);
    }

    /**
     * Seconds to wait before the next attempt: a capped server Retry-After when
     * given, else exponential (2^attempt) capped at 8. attempt is 0-based.
     */
    public static function backoff_delay(int $attempt, ?int $retry_after): int {
        if ($retry_after !== null && $retry_after > 0) {
            return min($retry_after, 30);
        }
        return (int) min(1 << max(0, $attempt), 8);
    }

    /**
     * Read a numeric response header, or null.
     *
     * @param array<string,mixed> $resp
     */
    private static function header_int(array $resp, string $name): ?int {
        $v = wp_remote_retrieve_header($resp, $name);
        return is_numeric($v) ? (int) $v : null;
    }
}
