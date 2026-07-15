<?php

declare(strict_types=1);

namespace Drupal\hrefl_client\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\hrefl_client\Service\HreflangEmitter;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Headless feed of a page's alternates, for a decoupled front end.
 *
 * Reads only the local store. GET /hrefl/selector?url=<absolute-url>.
 */
final class SelectorController extends ControllerBase {

  public function __construct(
    private readonly HreflangEmitter $emitter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('hrefl_client.emitter'));
  }

  /**
   * Return the current page's alternates as JSON.
   */
  public function feed(Request $request): JsonResponse {
    $url = (string) $request->query->get('url', $request->headers->get('referer', ''));
    $alternates = $url ? $this->emitter->alternates($url) : [];
    $response = new JsonResponse([
      'url' => $url,
      'alternates' => $alternates,
    ]);
    // Safe to cache at the edge: this is per-URL, not per-user.
    $response->setPublic();
    $response->setMaxAge(300);
    return $response;
  }

}
