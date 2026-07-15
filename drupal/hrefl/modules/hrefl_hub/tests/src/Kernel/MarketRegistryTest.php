<?php

declare(strict_types=1);

namespace Drupal\Tests\hrefl_hub\Kernel;

use Drupal\KernelTests\KernelTestBase;

/**
 * Market ownership + host allowlist cover both path-prefix and separate-domain
 * markets.
 *
 * @group hrefl_hub
 */
final class MarketRegistryTest extends KernelTestBase {

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
    // A path-prefix market (de) plus a separate-domain market (es).
    $this->config('hrefl_hub.settings')
      ->set('markets', [
        'de' => ['prefix' => 'https://pro.boehringer-ingelheim.com/de/', 'key_name' => ''],
        'es' => ['prefix' => 'https://pro.boehringer-ingelheim.es/', 'key_name' => ''],
      ])
      ->save();
  }

  public function testOwnsUrlForPathPrefixMarket(): void {
    $registry = $this->container->get('hrefl_hub.market_registry');
    $this->assertTrue($registry->ownsUrl('de', 'https://pro.boehringer-ingelheim.com/de/ueber-uns'));
    // A DE client cannot claim a US path or another domain.
    $this->assertFalse($registry->ownsUrl('de', 'https://pro.boehringer-ingelheim.com/us/about-us'));
    $this->assertFalse($registry->ownsUrl('de', 'https://pro.boehringer-ingelheim.es/sobre-nosotros'));
  }

  public function testOwnsUrlForSeparateDomainMarket(): void {
    $registry = $this->container->get('hrefl_hub.market_registry');
    $this->assertTrue($registry->ownsUrl('es', 'https://pro.boehringer-ingelheim.es/sobre-nosotros'));
    // The ES domain market cannot claim the shared-host path.
    $this->assertFalse($registry->ownsUrl('es', 'https://pro.boehringer-ingelheim.com/de/ueber-uns'));
  }

  public function testUnknownMarketFallsBackToCanonicalHostPrefix(): void {
    $registry = $this->container->get('hrefl_hub.market_registry');
    // 'us' is not in the markets map here, so it derives /us/ under the host.
    $this->assertTrue($registry->ownsUrl('us', 'https://pro.boehringer-ingelheim.com/us/about-us'));
    $this->assertFalse($registry->ownsUrl('us', 'https://pro.boehringer-ingelheim.es/x'));
  }

  public function testAllowedHostsIncludesBothTopologies(): void {
    $hosts = $this->container->get('hrefl_hub.market_registry')->allowedHosts();
    $this->assertContains('pro.boehringer-ingelheim.com', $hosts);
    $this->assertContains('pro.boehringer-ingelheim.es', $hosts);
  }

}
