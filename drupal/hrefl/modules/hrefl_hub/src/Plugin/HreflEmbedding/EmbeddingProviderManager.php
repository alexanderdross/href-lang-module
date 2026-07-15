<?php

declare(strict_types=1);

namespace Drupal\hrefl_hub\Plugin\HreflEmbedding;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\hrefl_hub\Attribute\HreflEmbeddingProvider;

/**
 * Plugin manager for hrefl_embedding_provider plugins.
 */
final class EmbeddingProviderManager extends DefaultPluginManager {

  public function __construct(
    \Traversable $namespaces,
    CacheBackendInterface $cache_backend,
    ModuleHandlerInterface $module_handler,
  ) {
    parent::__construct(
      'Plugin/HreflEmbedding',
      $namespaces,
      $module_handler,
      EmbeddingProviderInterface::class,
      HreflEmbeddingProvider::class,
    );
    $this->alterInfo('hrefl_embedding_provider_info');
    $this->setCacheBackend($cache_backend, 'hrefl_embedding_provider_plugins');
  }

}
