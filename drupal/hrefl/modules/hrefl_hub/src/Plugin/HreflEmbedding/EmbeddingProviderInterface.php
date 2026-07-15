<?php

declare(strict_types=1);

namespace Drupal\hrefl_hub\Plugin\HreflEmbedding;

use Drupal\Component\Plugin\PluginInspectionInterface;

/**
 * Interface for Tier-B embedding providers.
 *
 * A provider turns short page texts (title + headings + meta) into fixed-length
 * vectors that the vector store compares by cosine similarity to find candidate
 * equivalents across markets. It only adapts transport/auth; the store and the
 * matching logic are provider-neutral.
 */
interface EmbeddingProviderInterface extends PluginInspectionInterface {

  /**
   * Human-readable label.
   */
  public function label(): string;

  /**
   * Whether the provider has what it needs (endpoint, model, key if required).
   */
  public function isConfigured(): bool;

  /**
   * Embed a batch of texts.
   *
   * @param string[] $texts
   *   Texts to embed.
   *
   * @return array
   *   A list of float vectors in the same order as $texts. On failure returns
   *   an empty array (the caller then skips embedding rather than storing junk).
   */
  public function embed(array $texts): array;

}
