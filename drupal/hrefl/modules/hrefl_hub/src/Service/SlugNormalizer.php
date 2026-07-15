<?php

declare(strict_types=1);

namespace Drupal\hrefl_hub\Service;

/**
 * Derives a comparable slug key from a URL.
 *
 * URL-pattern matching (Phase 1, docs/ROADMAP.md) compares the leaf slug of a
 * page across markets: `/us/about-us`, `/ca/about-us`, and `/about-us` all
 * reduce to `about-us`, so same-slug pages in different markets are candidate
 * equivalents; the learned glossary then bridges cross-language slugs
 * (`ueber-uns` ↔ `about-us`).
 */
final class SlugNormalizer {

  /**
   * The normalized leaf slug of a URL (last path segment, lowercased).
   *
   * @return string
   *   The slug, or '' for a root URL.
   */
  public function slug(string $url): string {
    $path = (string) parse_url($url, PHP_URL_PATH);
    $segments = array_values(array_filter(explode('/', $path), static fn($s) => $s !== ''));
    if (!$segments) {
      return '';
    }
    return strtolower(rawurldecode((string) end($segments)));
  }

}
