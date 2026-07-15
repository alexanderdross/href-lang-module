<?php

declare(strict_types=1);

namespace Drupal\hrefl_hub\Attribute;

use Drupal\Component\Plugin\Attribute\Plugin;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Defines a hrefl_ai_matcher plugin attribute.
 *
 * A matcher provider adjudicates ambiguous cross-market equivalence candidates
 * (Tier C). Both an Anthropic and a Copilot provider ship with the module.
 *
 * @see \Drupal\hrefl_hub\Plugin\HreflAiMatcher\AiMatcherInterface
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class HreflAiMatcher extends Plugin {

  public function __construct(
    public readonly string $id,
    public readonly TranslatableMarkup $label,
    public readonly ?TranslatableMarkup $description = NULL,
  ) {}

}
