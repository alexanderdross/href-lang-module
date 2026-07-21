<?php

declare(strict_types=1);

namespace Drupal\hrefl_client\Plugin\QueueWorker;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
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
    private readonly EntityTypeManagerInterface $entityTypeManager,
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
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   *
   * @param mixed $data
   *   Queue item: ['url', 'entity_type', 'entity_id', 'deleted' => bool].
   *
   * @throws \GuzzleHttp\Exception\GuzzleException
   *   Propagated from the publish so the queue retries the item.
   */
  public function processItem($data): void {
    $url = (string) ($data['url'] ?? '');
    if ($url === '') {
      return;
    }

    // A deleted (or now unloadable) entity is retired at the hub by publishing
    // its URL as non-indexable; the hub stops serving it as an alternate.
    $entity = NULL;
    if (empty($data['deleted']) && !empty($data['entity_type']) && !empty($data['entity_id'])) {
      $entity = $this->entityTypeManager
        ->getStorage((string) $data['entity_type'])
        ->load($data['entity_id']);
    }
    if ($entity instanceof ContentEntityInterface) {
      $record = $this->collector->recordFor($entity);
      // Unpublished content is retired the same way as deleted content.
      if ($record !== NULL && method_exists($entity, 'isPublished') && !$entity->isPublished()) {
        $record['indexable'] = 0;
      }
    }
    else {
      $record = ['url' => $url, 'language' => '', 'hreflang' => '', 'indexable' => 0];
    }

    if ($record !== NULL) {
      $this->hubClient->publishInventory([$record]);
    }
  }

}
