<?php

declare(strict_types=1);

namespace Drupal\hrefl_client\Controller;

use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\Core\Cache\CacheableMetadata;
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
   * Return a page's alternates as JSON. Requires an explicit ?url=.
   *
   * No Referer fallback: a header-derived response must never enter a shared
   * cache keyed only by the request URL.
   */
  public function feed(Request $request): JsonResponse {
    $url = (string) $request->query->get('url', '');
    if ($url === '') {
      return new JsonResponse(['error' => 'missing url parameter'], 400);
    }
    $response = new CacheableJsonResponse([
      'url' => $url,
      'alternates' => $this->emitter->alternates($url),
    ]);
    $meta = (new CacheableMetadata())
      ->addCacheContexts(['url.query_args:url'])
      ->addCacheTags([$this->emitter->cacheTagForUrl($url) ?? 'hrefl_alternates'])
      ->setCacheMaxAge(300);
    $response->addCacheableDependency($meta);
    // Safe to cache at the edge: this is per-URL, not per-user.
    $response->setPublic();
    $response->setMaxAge(300);
    return $response;
  }

}
