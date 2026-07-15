<?php

declare(strict_types=1);

namespace Drupal\hrefl_hub\Plugin\HreflAiMatcher;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\hrefl_hub\Attribute\HreflAiMatcher;

/**
 * Plugin manager for hrefl_ai_matcher providers.
 */
final class AiMatcherManager extends DefaultPluginManager {

  public function __construct(
    \Traversable $namespaces,
    CacheBackendInterface $cache_backend,
    ModuleHandlerInterface $module_handler,
  ) {
    parent::__construct(
      'Plugin/HreflAiMatcher',
      $namespaces,
      $module_handler,
      AiMatcherInterface::class,
      HreflAiMatcher::class,
    );
    $this->alterInfo('hrefl_ai_matcher_info');
    $this->setCacheBackend($cache_backend, 'hrefl_ai_matcher_plugins');
  }

}
