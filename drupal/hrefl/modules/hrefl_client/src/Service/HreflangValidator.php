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
 * - Valid codes: ISO 639 language, optional ISO 15924 script (`zh-Hans`),
 *   optional ISO 3166-1 alpha-2 or UN M49 numeric region (`en-US`, `es-419`),
 *   normalized to `lower-Title-UPPER` casing, plus `x-default`.
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
   * Normalize a code to BCP 47 casing: lang lower, Script Title, REGION upper.
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
    $normalized = [strtolower(array_shift($parts))];
    foreach ($parts as $part) {
      if (preg_match('/^[A-Za-z]{4}$/', $part)) {
        // Script subtag (Hans, Latn): title case.
        $normalized[] = ucfirst(strtolower($part));
      }
      elseif (preg_match('/^[A-Za-z]{2}$/', $part)) {
        // Alpha-2 region: upper case.
        $normalized[] = strtoupper($part);
      }
      else {
        // Numeric region (419) or anything unknown; isValidCode() decides.
        $normalized[] = $part;
      }
    }
    return implode('-', $normalized);
  }

  /**
   * Whether a code is a valid hreflang value (assumes already normalized).
   */
  public function isValidCode(string $code): bool {
    if ($code === self::X_DEFAULT) {
      return TRUE;
    }
    // ISO 639-1/2 language, optional ISO 15924 script, optional ISO 3166-1
    // alpha-2 or UN M49 numeric region (e.g. en, en-US, zh-Hans, zh-Hant-TW,
    // es-419).
    return (bool) preg_match('/^[a-z]{2,3}(-[A-Z][a-z]{3})?(-([A-Z]{2}|\d{3}))?$/', $code);
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
