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
   * GET /hrefl-hub/api/v1/alternates?market=de.
   *
   * The market is taken from the signed request header when present (so a
   * client can only pull its own market), falling back to the query parameter.
   */
  public function alternates(Request $request): JsonResponse {
    $market = (string) $request->headers->get('X-Hrefl-Market', '');
    if ($market === '') {
      $market = (string) $request->query->get('market', '');
    }
    if ($market === '') {
      return new JsonResponse(['error' => 'market required'], 400);
    }
    return new JsonResponse([
      'market' => $market,
      'generated_at' => gmdate('c'),
      'pages' => $this->distributor->alternatesForMarket($market),
    ]);
  }

}
