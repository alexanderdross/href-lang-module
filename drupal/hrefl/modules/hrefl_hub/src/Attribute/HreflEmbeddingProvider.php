<?php

declare(strict_types=1);

namespace Drupal\hrefl_hub\Attribute;

use Drupal\Component\Plugin\Attribute\Plugin;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Defines a hrefl_embedding_provider plugin attribute.
 *
 * An embedding provider turns page text into a vector for Tier-B semantic
 * candidate search. A self-hosted multilingual model is preferred (keeps
 * content in-house); an HTTP provider ships for that and for Azure/OpenAI-style
 * endpoints.
 *
 * @see \Drupal\hrefl_hub\Plugin\HreflEmbedding\EmbeddingProviderInterface
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class HreflEmbeddingProvider extends Plugin {

  public function __construct(
    public readonly string $id,
    public readonly TranslatableMarkup $label,
    public readonly ?TranslatableMarkup $description = NULL,
  ) {}

}
