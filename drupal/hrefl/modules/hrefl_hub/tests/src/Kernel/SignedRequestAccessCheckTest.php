<?php

declare(strict_types=1);

namespace Drupal\Tests\hrefl_hub\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Symfony\Component\HttpFoundation\Request;

/**
 * The signed-request access check grants a validly signed request and denies
 * anything unsigned, mis-signed, or stale (replay).
 *
 * @group hrefl_hub
 */
final class SignedRequestAccessCheckTest extends KernelTestBase {

  private const SECRET = 'test-shared-secret-value';
  private const PATH = '/hrefl-hub/api/v1/inventory';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'hrefl_hub'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['hrefl_hub']);
    // The market's secret falls back to this env var (no key module in test).
    putenv('HREFL_HUB_SECRET=' . self::SECRET);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    putenv('HREFL_HUB_SECRET');
    parent::tearDown();
  }

  public function testValidSignatureIsAllowed(): void {
    $body = '{"market":"de","records":[]}';
    $ts = $this->container->get('datetime.time')->getRequestTime();
    $request = $this->signedRequest($body, self::SECRET, $ts, 'de');
    $this->assertTrue($this->check($request)->isAllowed());
  }

  public function testWrongSecretIsForbidden(): void {
    $body = '{"market":"de","records":[]}';
    $ts = $this->container->get('datetime.time')->getRequestTime();
    $request = $this->signedRequest($body, 'the-wrong-secret', $ts, 'de');
    $this->assertFalse($this->check($request)->isAllowed());
  }

  public function testStaleTimestampIsForbidden(): void {
    $body = '{"market":"de","records":[]}';
    // 10 minutes old — outside the 5-minute replay window.
    $ts = $this->container->get('datetime.time')->getRequestTime() - 600;
    $request = $this->signedRequest($body, self::SECRET, $ts, 'de');
    $this->assertFalse($this->check($request)->isAllowed());
  }

  public function testUnsignedRequestIsForbidden(): void {
    $request = Request::create('https://pro.boehringer-ingelheim.com' . self::PATH, 'POST', [], [], [], [], '{}');
    $this->assertFalse($this->check($request)->isAllowed());
  }

  /**
   * Build a POST request signed with the given secret and timestamp.
   */
  private function signedRequest(string $body, string $secret, int $timestamp, string $market): Request {
    $request = Request::create('https://pro.boehringer-ingelheim.com' . self::PATH, 'POST', [], [], [], [], $body);
    $canonical = implode("\n", ['POST', self::PATH, (string) $timestamp, hash('sha256', $body)]);
    $request->headers->set('X-Hrefl-Market', $market);
    $request->headers->set('X-Hrefl-Timestamp', (string) $timestamp);
    $request->headers->set('X-Hrefl-Signature', hash_hmac('sha256', $canonical, $secret));
    return $request;
  }

  private function check(Request $request) {
    return $this->container->get('hrefl_hub.signed_request_access_check')->access($request);
  }

}
