<?php

declare(strict_types=1);

namespace Drupal\Tests\hrefl_client\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * The HTTP Link header is emitted for non-HTML responses when enabled, and not
 * for HTML (which already carries the head tags).
 *
 * @group hrefl_client
 */
final class LinkHeaderTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'language', 'hrefl_client'];

  private const URL = 'http://localhost/de/handbuch.pdf';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('hrefl_client', ['hrefl_client_alternates']);
    $this->installConfig(['hrefl_client']);
    $this->config('hrefl_client.settings')->set('emit_link_header', TRUE)->save();

    $this->container->get('hrefl_client.alternates_consumer')->ingestPayload([
      'pages' => [
        [
          'url' => self::URL,
          'alternates' => [
            ['hreflang' => 'de', 'href' => self::URL],
            ['hreflang' => 'en', 'href' => 'http://localhost/handbook.pdf'],
          ],
        ],
      ],
    ]);
  }

  public function testPdfResponseGetsLinkHeader(): void {
    $response = $this->dispatch(new Response('', 200, ['Content-Type' => 'application/pdf']));
    $links = $response->headers->all('link');
    $joined = implode(' , ', $links);
    $this->assertStringContainsString('rel="alternate"', $joined);
    $this->assertStringContainsString('hreflang="en"', $joined);
    $this->assertStringContainsString('<http://localhost/handbook.pdf>', $joined);
  }

  public function testHtmlResponseIsSkipped(): void {
    $response = $this->dispatch(new Response('<html></html>', 200, ['Content-Type' => 'text/html; charset=UTF-8']));
    $this->assertSame([], $response->headers->all('link'));
  }

  /**
   * Run the subscriber against a response for the seeded URL.
   */
  private function dispatch(Response $response): Response {
    $request = Request::create(self::URL);
    $event = new ResponseEvent(
      $this->container->get('http_kernel'),
      $request,
      HttpKernelInterface::MAIN_REQUEST,
      $response,
    );
    $this->container->get('hrefl_client.link_header_subscriber')->onResponse($event);
    return $event->getResponse();
  }

}
