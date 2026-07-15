<?php

declare(strict_types=1);

namespace Drupal\hrefl_hub\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\hrefl_hub\Service\MarketRegistry;
use Drupal\hrefl_hub\Service\Registry;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Receives page-inventory batches from a backend.
 *
 * Enforces URL ownership: a backend may only assert URLs under its own market's
 * prefix (a path prefix or a whole domain). This blocks mapping poisoning by a
 * compromised or misconfigured backend.
 */
final class IngestController extends ControllerBase {

  public function __construct(
    private readonly Registry $registry,
    private readonly MarketRegistry $marketRegistry,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('hrefl_hub.registry'),
      $container->get('hrefl_hub.market_registry'),
    );
  }

  /**
   * POST /hrefl-hub/api/v1/inventory.
   */
  public function ingest(Request $request): JsonResponse {
    $payload = json_decode((string) $request->getContent(), TRUE);
    if (!is_array($payload) || empty($payload['market']) || !isset($payload['records'])) {
      return new JsonResponse(['error' => 'invalid payload'], 400);
    }
    $market = (string) $payload['market'];

    $accepted = 0;
    $rejected = 0;
    foreach ((array) $payload['records'] as $record) {
      if (empty($record['url']) || !$this->marketRegistry->ownsUrl($market, (string) $record['url'])) {
        // Ownership violation: drop it.
        $rejected++;
        continue;
      }
      $this->registry->upsertMember([
        'group_uuid' => $this->registry->groupForUrl($record['url']) ?? $this->registry->createGroup(),
        'market' => $market,
        'language' => (string) ($record['language'] ?? ''),
        'hreflang' => (string) ($record['hreflang'] ?? ''),
        'url' => (string) $record['url'],
        'title' => isset($record['title']) ? (string) $record['title'] : NULL,
        'image' => isset($record['image']) ? (string) $record['image'] : NULL,
        'entity_type' => $record['entity_type'] ?? NULL,
        'entity_id' => isset($record['entity_id']) ? (string) $record['entity_id'] : NULL,
        'status' => 'proposed',
        'matched_by' => 'manual',
        'asserted_by' => $market,
        'source_changed' => isset($record['changed']) ? (int) $record['changed'] : NULL,
        'valid' => (int) ($record['indexable'] ?? 0),
        '_via' => 'automation',
      ]);
      $accepted++;
    }

    return new JsonResponse([
      'accepted' => $accepted,
      'rejected' => $rejected,
      'flagged_for_match' => $accepted,
    ]);
  }

}
