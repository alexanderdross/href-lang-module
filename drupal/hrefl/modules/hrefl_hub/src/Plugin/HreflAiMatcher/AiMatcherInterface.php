<?php

declare(strict_types=1);

namespace Drupal\hrefl_hub\Plugin\HreflAiMatcher;

use Drupal\Component\Plugin\PluginInspectionInterface;

/**
 * Interface for AI matcher (Tier C) providers.
 *
 * A provider is asked to choose the true equivalent of a source page from a
 * short list of candidates that Tier B already surfaced. The provider must
 * choose one of the supplied candidates or "none"; it must never invent a URL.
 */
interface AiMatcherInterface extends PluginInspectionInterface {

  /**
   * Human-readable label.
   */
  public function label(): string;

  /**
   * Whether the provider has the configuration it needs (endpoint, key, model).
   */
  public function isConfigured(): bool;

  /**
   * Adjudicate which candidate is the equivalent of the source page.
   *
   * @param array $source
   *   Normalized source record: keys url, market, language, title,
   *   meta_description, headings (array), breadcrumb (array).
   * @param array $candidates
   *   List of normalized candidate records (same shape as $source).
   *
   * @return array
   *   ['choice' => int|null, 'confidence' => float, 'rationale' => string]
   *   where choice is the index into $candidates, or NULL for "no match".
   */
  public function adjudicate(array $source, array $candidates): array;

  /**
   * Translate a page's title and URL slug into a target language.
   *
   * Used to locate/propose the equivalent page across the language sites (the
   * translated slug feeds the URL-pattern matcher) and to give reviewers a
   * localized title/slug to check. The output is always a *proposal* subject
   * to human review; it never publishes anything on its own.
   *
   * @param array $source
   *   Normalized source record (title, language, url at minimum).
   * @param string $targetLanguage
   *   Target ISO 639-1 language code.
   *
   * @return array
   *   ['title' => string, 'slug' => string]; empty strings if unavailable.
   */
  public function translate(array $source, string $targetLanguage): array;

}
