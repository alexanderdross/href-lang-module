<?php

declare(strict_types=1);

namespace Drupal\hrefl_client\Service;

/**
 * Builds the hreflang head elements for the current page.
 *
 * Reads only the local store. Output is absolute, reciprocal and self-
 * referencing by construction (the hub resolved the full set).
 */
final class HreflangEmitter {

  public function __construct(
    private readonly AlternatesStore $store,
    private readonly HreflangValidator $validator,
  ) {}

  /**
   * html_head elements keyed for hook_page_attachments().
   *
   * @return array
   *   Keyed list of Drupal render-array <link> elements.
   */
  public function headElements(string $currentUrl): array {
    $elements = [];
    foreach ($this->alternates($currentUrl) as $i => $alt) {
      $elements['hrefl_' . $i] = [
        '#tag' => 'link',
        '#attributes' => [
          'rel' => 'alternate',
          'hreflang' => $alt['hreflang'],
          'href' => $alt['href'],
        ],
      ];
    }
    return $elements;
  }

  /**
   * The cache tag that invalidates this page when its mapping changes.
   */
  public function cacheTagForUrl(string $currentUrl): ?string {
    $uuid = $this->store->groupForUrl($currentUrl);
    return $uuid ? 'hrefl_group:' . $uuid : NULL;
  }

  /**
   * The validated alternate list (used by head tags, the selector, the feed).
   *
   * Every consumer reads through here so the rule-enforced set is single-
   * sourced: head tags, sitemap, and selector can never disagree.
   */
  public function alternates(string $currentUrl): array {
    return $this->validator->clean($this->store->get($currentUrl));
  }

}
