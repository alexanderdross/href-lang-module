<?php

declare(strict_types=1);

namespace Drupal\hrefl_client\Controller;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\hrefl_client\Service\SitemapGenerator;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves the module's own multilingual sitemap at /hrefl/sitemap.xml.
 *
 * Reads only the local store (no cross-backend call), and is edge-cacheable.
 */
final class SitemapController extends ControllerBase {

  public function __construct(
    private readonly SitemapGenerator $generator,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('hrefl_client.sitemap_generator'),
      $container->get('config.factory'),
    );
  }

  /**
   * GET /hrefl/sitemap.xml — a urlset, or a sitemap index for large sites.
   */
  public function sitemap(): Response {
    if (!$this->enabled()) {
      return new Response('Sitemap disabled.', 404);
    }
    return $this->xmlResponse($this->generator->render());
  }

  /**
   * GET /hrefl/sitemap.{page}.xml — one chunk of a large sitemap.
   *
   * Route params arrive as strings (strict types); $page is cast here.
   */
  public function chunk(string $page): Response {
    if (!$this->enabled()) {
      return new Response('Sitemap disabled.', 404);
    }
    return $this->xmlResponse($this->generator->renderChunk((int) $page));
  }

  private function enabled(): bool {
    return (bool) $this->configFactory->get('hrefl_client.settings')->get('sitemap_enabled');
  }

  private function xmlResponse(string $xml): Response {
    $response = new Response($xml, 200, ['Content-Type' => 'application/xml; charset=utf-8']);
    // Per-URL data, not per-user: safe to cache at the edge.
    $response->setPublic();
    $response->setMaxAge(3600);
    return $response;
  }

}
