<?php

declare(strict_types=1);

namespace Drupal\hrefl_client\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\hrefl_client\Service\HubClient;
use Drupal\hrefl_client\Service\InventoryCollector;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Publishes changed URLs to the hub in the background (event-driven matching).
 */
#[QueueWorker(
  id: 'hrefl_client_publish',
  title: new TranslatableMarkup('Hreflang: publish changed URLs'),
  cron: ['time' => 30],
)]
final class PublishQueueWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    private readonly InventoryCollector $collector,
    private readonly HubClient $hubClient,
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
      $container->get('hrefl_client.inventory_collector'),
      $container->get('hrefl_client.hub_client'),
    );
  }

  /**
   * {@inheritdoc}
   *
   * @param mixed $data
   *   Queue item: ['url' => string].
   */
  public function processItem($data): void {
    // A full build re-collects just this URL's record; here we trigger a fresh
    // inventory publish so the hub re-matches the changed page promptly.
    $records = $this->collector->collect(50);
    if ($records) {
      $this->hubClient->publishInventory($records);
    }
  }

}
