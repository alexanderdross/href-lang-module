<?php

declare(strict_types=1);

namespace Drupal\Tests\hrefl_client\Kernel;

use Drupal\KernelTests\KernelTestBase;

/**
 * Proves the Phase 0 exit criteria: a hand-authored mapping, seeded into the
 * local store, emits a correct, reciprocal, self-referencing hreflang set.
 *
 * @group hrefl_client
 */
final class SeedAndEmitTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'language', 'hrefl_client'];

  private const GLOBAL_URL = 'https://pro.boehringer-ingelheim.com/about-us';
  private const DE_URL = 'https://pro.boehringer-ingelheim.com/de/ueber-uns';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('hrefl_client', ['hrefl_client_alternates']);
    $this->installConfig(['hrefl_client']);
  }

  /**
   * Seed the example mapping, then assert both members emit the full set.
   */
  public function testSeededMappingEmitsReciprocalSet(): void {
    $payload = json_decode(
      (string) file_get_contents(__DIR__ . '/../../../seed/example.seed.json'),
      TRUE,
    );

    $consumer = $this->container->get('hrefl_client.alternates_consumer');
    $stored = $consumer->ingestPayload($payload);
    $this->assertSame(2, $stored);

    $emitter = $this->container->get('hrefl_client.emitter');

    $expected = [
      'en' => self::GLOBAL_URL,
      'de' => self::DE_URL,
      'en-US' => 'https://pro.boehringer-ingelheim.com/us/about-us',
      'en-CA' => 'https://pro.boehringer-ingelheim.com/ca/about-us',
      'fr-CA' => 'https://pro.boehringer-ingelheim.com/ca/fr/a-propos',
      'x-default' => self::GLOBAL_URL,
    ];

    // Both members emit the identical, complete set (reciprocity + self).
    foreach ([self::GLOBAL_URL, self::DE_URL] as $url) {
      $byCode = [];
      foreach ($emitter->alternates($url) as $alt) {
        $byCode[$alt['hreflang']] = $alt['href'];
      }
      $this->assertSame($expected, $byCode, "Emitted set for $url");
      // Self-referencing: the page lists itself.
      $this->assertContains($url, $byCode, "Self entry present for $url");
      // Exactly one x-default.
      $this->assertSame(1, array_count_values(array_column($emitter->alternates($url), 'hreflang'))['x-default']);
    }
  }

  /**
   * A page with no stored mapping degrades to the safe (empty) subset.
   */
  public function testUnmappedUrlEmitsNothing(): void {
    $emitter = $this->container->get('hrefl_client.emitter');
    $this->assertSame([], $emitter->alternates('https://pro.boehringer-ingelheim.com/unmapped'));
  }

}
