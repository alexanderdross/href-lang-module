<?php

declare(strict_types=1);

namespace Drupal\hrefl_hub\Plugin\HreflEmbedding;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Plugin\PluginBase;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\hrefl_hub\Http\RetriesHttp;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Base class for Tier-B embedding providers.
 *
 * Mirrors AiMatcherBase: shared config/secret handling so a concrete provider
 * only implements the transport. Secrets are resolved via an environment
 * variable or the (optional) key module, never stored in config.
 */
abstract class EmbeddingProviderBase extends PluginBase implements EmbeddingProviderInterface, ContainerFactoryPluginInterface {

  use StringTranslationTrait;
  use RetriesHttp;

  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    protected readonly ClientInterface $httpClient,
    protected readonly ConfigFactoryInterface $configFactory,
    protected readonly LoggerInterface $logger,
    // \Drupal\key\KeyRepositoryInterface when the optional key module is
    // installed, otherwise NULL.
    protected readonly mixed $keyRepository = NULL,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('http_client'),
      $container->get('config.factory'),
      $container->get('logger.channel.hrefl_hub'),
      $container->get('key.repository', ContainerInterface::NULL_ON_INVALID_REFERENCE),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    return (string) $this->pluginDefinition['label'];
  }

  /**
   * Per-provider settings from hrefl_hub.settings:embedding.providers.<id>.
   */
  protected function providerSettings(): array {
    $embedding = (array) $this->configFactory->get('hrefl_hub.settings')->get('embedding');
    return (array) ($embedding['providers'][$this->getPluginId()] ?? []);
  }

  /**
   * Resolve this provider's API key (env var first, then key module).
   */
  protected function resolveApiKey(string $envVar): string {
    $env = getenv($envVar);
    if (is_string($env) && $env !== '') {
      return $env;
    }
    $keyName = (string) ($this->providerSettings()['key_name'] ?? '');
    if ($keyName !== '' && is_object($this->keyRepository) && method_exists($this->keyRepository, 'getKey')) {
      $key = $this->keyRepository->getKey($keyName);
      if (is_object($key) && method_exists($key, 'getKeyValue')) {
        return (string) $key->getKeyValue();
      }
    }
    return '';
  }

}
