<?php

declare(strict_types=1);

namespace Drupal\hrefl_hub\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\hrefl_hub\Service\Distributor;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Serves each backend its confirmed, resolved alternate set.
 */
final class ServeController extends ControllerBase {

  public function __construct(
    private readonly Distributor $distributor,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('hrefl_hub.distributor'));
  }

  /**
   * GET /hrefl-hub/api/v1/alternates.
   *
   * The market is the signed request identity (X-Hrefl-Market, verified by
   * the HMAC access check), so a client can only ever pull its own market.
   */
  public function alternates(Request $request): JsonResponse {
    $market = (string) $request->headers->get('X-Hrefl-Market', '');
    if ($market === '') {
      return new JsonResponse(['error' => 'market required'], 400);
    }
    // Cursor pagination: the client pages the market with ?after=<id>, so a
    // large corpus is never built or serialized in a single response.
    $after = max(0, (int) $request->query->get('after', 0));
    $limit = (int) $request->query->get('limit', Distributor::PAGE_SIZE);
    $limit = max(1, min($limit, Distributor::PAGE_SIZE));
    $batch = $this->distributor->servePage($market, $after, $limit);
    return new JsonResponse([
      'market' => $market,
      'generated_at' => gmdate('c'),
      'pages' => $batch['pages'],
      // NULL when the market is exhausted; the client stops paging then.
      'next' => $batch['next'],
    ]);
  }

}
