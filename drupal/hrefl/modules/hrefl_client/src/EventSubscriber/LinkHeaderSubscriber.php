<?php

declare(strict_types=1);

namespace Drupal\hrefl_client\EventSubscriber;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\hrefl_client\Service\HreflangEmitter;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Emits hreflang as an HTTP `Link` header — the third emission method.
 *
 * Head `<link>` tags cannot be injected into non-HTML responses (e.g. PDFs), so
 * for those this adds `Link: <url>; rel="alternate"; hreflang="…"` from the same
 * local store. HTML responses already carry the head tags, so they are skipped
 * to avoid duplicating the signal. Gated by the `emit_link_header` setting.
 */
final class LinkHeaderSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly HreflangEmitter $emitter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [KernelEvents::RESPONSE => 'onResponse'];
  }

  /**
   * Attach the Link header to eligible responses.
   */
  public function onResponse(ResponseEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }
    if (!$this->configFactory->get('hrefl_client.settings')->get('emit_link_header')) {
      return;
    }
    $response = $event->getResponse();
    // HTML pages already carry the <head> tags; only add the header elsewhere.
    $contentType = (string) $response->headers->get('Content-Type', '');
    if ($contentType === '' || str_contains($contentType, 'text/html')) {
      return;
    }

    $url = $event->getRequest()->getUri();
    $parts = [];
    foreach ($this->emitter->alternates($url) as $alt) {
      $parts[] = sprintf('<%s>; rel="alternate"; hreflang="%s"', $alt['href'], $alt['hreflang']);
    }
    if (!$parts) {
      return;
    }
    // Append rather than overwrite any existing Link header.
    foreach ($parts as $part) {
      $response->headers->set('Link', $part, FALSE);
    }
  }

}
