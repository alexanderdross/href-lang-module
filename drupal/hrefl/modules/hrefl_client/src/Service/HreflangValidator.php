<?php

declare(strict_types=1);

namespace Drupal\hrefl_client\Service;

/**
 * Enforces the hreflang correctness rules on an alternate set before emission.
 *
 * The rules (see docs/HREFLANG-RULES.md) that this service guarantees on the
 * client, on every emission path (head tags, selector, JSON feed):
 *
 * - Absolute, fully-qualified http(s) URLs only (never relative or
 *   protocol-relative).
 * - Valid codes: ISO 639-1 language + optional ISO 3166-1 alpha-2 region,
 *   normalized to `lower-UPPER` (e.g. `en-US`, `fr-CA`), plus `x-default`.
 * - Each hreflang code appears at most once (no duplicate/colliding codes).
 * - At most one `x-default` per set.
 *
 * Reciprocity and self-referencing are guaranteed upstream by the shared-group
 * model and the consumer's self-entry guarantee; this service is the last-line
 * gate that a malformed row never reaches a page.
 */
final class HreflangValidator {

  private const X_DEFAULT = 'x-default';

  /**
   * Normalize and validate an alternate set, dropping anything non-compliant.
   *
   * @param array $alternates
   *   List of ['hreflang' => string, 'href' => string].
   *
   * @return array
   *   The cleaned list, in input order, deduplicated by hreflang code.
   */
  public function clean(array $alternates): array {
    $out = [];
    $seen = [];
    $xDefaultSeen = FALSE;

    foreach ($alternates as $alt) {
      $href = trim((string) ($alt['href'] ?? ''));
      $code = $this->normalizeCode((string) ($alt['hreflang'] ?? ''));

      if ($href === '' || $code === '' || !$this->isAbsolute($href)) {
        continue;
      }

      if ($code === self::X_DEFAULT) {
        if ($xDefaultSeen) {
          continue;
        }
        $xDefaultSeen = TRUE;
      }
      elseif (!$this->isValidCode($code)) {
        continue;
      }

      // One entry per code; keep the first occurrence.
      if (isset($seen[$code])) {
        continue;
      }
      $seen[$code] = TRUE;
      $out[] = ['hreflang' => $code, 'href' => $href];
    }

    return $out;
  }

  /**
   * Normalize a language/region code to `lower-UPPER` (or `x-default`).
   */
  public function normalizeCode(string $code): string {
    $code = str_replace('_', '-', trim($code));
    if ($code === '') {
      return '';
    }
    if (strcasecmp($code, self::X_DEFAULT) === 0) {
      return self::X_DEFAULT;
    }
    $parts = explode('-', $code);
    $lang = strtolower($parts[0]);
    if (isset($parts[1]) && $parts[1] !== '') {
      return $lang . '-' . strtoupper($parts[1]);
    }
    return $lang;
  }

  /**
   * Whether a code is a valid hreflang value (assumes already normalized).
   */
  public function isValidCode(string $code): bool {
    if ($code === self::X_DEFAULT) {
      return TRUE;
    }
    // ISO 639-1 (2 letters) or 639-2 (3 letters), optional ISO 3166-1 region.
    return (bool) preg_match('/^[a-z]{2,3}(-[A-Z]{2})?$/', $code);
  }

  /**
   * Whether an href is absolute and fully qualified over http(s).
   */
  public function isAbsolute(string $href): bool {
    $scheme = parse_url($href, PHP_URL_SCHEME);
    $host = parse_url($href, PHP_URL_HOST);
    return is_string($scheme)
      && is_string($host)
      && $host !== ''
      && in_array(strtolower($scheme), ['http', 'https'], TRUE);
  }

}
