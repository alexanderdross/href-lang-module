<?php

declare(strict_types=1);

namespace Drupal\Tests\hrefl_hub\Kernel;

use Drupal\hrefl_hub\Controller\CsvController;
use Drupal\hrefl_hub\Controller\IngestController;
use Drupal\KernelTests\KernelTestBase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Security controls: URL-ownership, CSV formula-injection, SSRF allowlist.
 *
 * See docs/SECURITY-TEST-PLAN.md. HMAC auth is covered separately by
 * SignedRequestAccessCheckTest.
 *
 * @group hrefl_hub
 */
final class SecurityTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'hrefl_hub'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('hrefl_hub', [
      'hrefl_group',
      'hrefl_group_member',
      'hrefl_glossary',
      'hrefl_feedback',
    ]);
    $this->installConfig(['hrefl_hub']);
  }

  /**
   * A backend may only assert URLs under its own market prefix.
   */
  public function testIngestRejectsCrossMarketUrls(): void {
    $payload = json_encode([
      'market' => 'de',
      'records' => [
        // Owned by DE — accepted.
        ['url' => 'https://pro.boehringer-ingelheim.com/de/ueber-uns', 'language' => 'de', 'hreflang' => 'de'],
        // A US URL claimed by the DE client — must be rejected.
        ['url' => 'https://pro.boehringer-ingelheim.com/us/about-us', 'language' => 'en', 'hreflang' => 'en-US'],
      ],
    ]);
    $request = Request::create('/hrefl-hub/api/v1/inventory', 'POST', [], [], [], [], $payload);
    // In production this header is verified by the HMAC access check.
    $request->headers->set('X-Hrefl-Market', 'de');

    $response = IngestController::create($this->container)->ingest($request);
    $body = json_decode((string) $response->getContent(), TRUE);

    $this->assertSame(1, $body['accepted']);
    $this->assertSame(1, $body['rejected']);

    $registry = $this->container->get('hrefl_hub.registry');
    $this->assertNotNull($registry->memberByUrl('https://pro.boehringer-ingelheim.com/de/ueber-uns'));
    $this->assertNull($registry->memberByUrl('https://pro.boehringer-ingelheim.com/us/about-us'));
  }

  /**
   * The payload market must match the HMAC-authenticated market header.
   */
  public function testIngestRejectsMarketMismatch(): void {
    $payload = json_encode([
      'market' => 'us',
      'records' => [
        ['url' => 'https://pro.boehringer-ingelheim.com/us/about-us', 'language' => 'en', 'hreflang' => 'en-US'],
      ],
    ]);
    $request = Request::create('/hrefl-hub/api/v1/inventory', 'POST', [], [], [], [], $payload);
    // Signed as DE but asserting records as US: must be refused.
    $request->headers->set('X-Hrefl-Market', 'de');

    $response = IngestController::create($this->container)->ingest($request);

    $this->assertSame(403, $response->getStatusCode());
    $registry = $this->container->get('hrefl_hub.registry');
    $this->assertNull($registry->memberByUrl('https://pro.boehringer-ingelheim.com/us/about-us'));
  }

  /**
   * Spreadsheet formula injection is neutralized in the exported CSV.
   */
  public function testCsvExportNeutralizesFormulaInjection(): void {
    $registry = $this->container->get('hrefl_hub.registry');
    $registry->upsertMember([
      'group_uuid' => $registry->createGroup(),
      'market' => 'global',
      'language' => 'en',
      'hreflang' => 'en',
      'url' => 'https://ex.com/x',
      // A malicious title that Excel would execute as a formula.
      'title' => '=HYPERLINK("http://evil")',
      'valid' => 1,
      'status' => 'proposed',
    ]);

    $csv = (string) CsvController::create($this->container)->export()->getContent();

    // The dangerous cell is present but prefixed with a quote, so it is inert.
    $this->assertStringContainsString("'=HYPERLINK", $csv);
    $this->assertStringNotContainsString(',=HYPERLINK', $csv);
  }

  /**
   * SSRF allowlist: a non-family host is refused before any fetch.
   */
  public function testSsrfAllowlistRejectsForeignHost(): void {
    $validator = $this->container->get('hrefl_hub.target_validator');
    // Foreign host — refused at the allowlist (no DNS/fetch happens).
    $this->assertFalse($validator->isSafeUrl('https://evil.example/phish'));
    // Non-http scheme — refused.
    $this->assertFalse($validator->isSafeUrl('ftp://pro.boehringer-ingelheim.com/x'));
    // Not absolute — refused.
    $this->assertFalse($validator->isSafeUrl('/relative/path'));
  }

}
