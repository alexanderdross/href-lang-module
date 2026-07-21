<?php

declare(strict_types=1);

namespace Drupal\hrefl_hub\Service;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * The source of truth for what each market owns and how it authenticates.
 *
 * A market owns an absolute URL prefix that is either a path prefix under the
 * shared host (`…/de/`) or a separate domain (`…es/`), so the same code covers
 * both topologies. The owned prefix drives URL-ownership enforcement and the
 * SSRF host allowlist; the per-market key names the shared HMAC secret used to
 * authenticate that market's client.
 */
final class MarketRegistry {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    // \Drupal\key\KeyRepositoryInterface when the optional key module is
    // installed, otherwise NULL.
    private readonly mixed $keyRepository = NULL,
  ) {}

  /**
   * The absolute URL prefix a market is allowed to assert (always ends in '/').
   */
  public function prefixFor(string $market): string {
    $markets = $this->markets();
    if (!empty($markets[$market]['prefix'])) {
      return rtrim((string) $markets[$market]['prefix'], '/') . '/';
    }
    // Backward-compatible fallback: derive from the canonical host.
    $host = rtrim((string) $this->settings()->get('canonical_host'), '/');
    return $market === 'global' ? $host . '/' : $host . '/' . trim($market, '/') . '/';
  }

  /**
   * Whether a market is allowed to claim a URL (ownership enforcement).
   */
  public function ownsUrl(string $market, string $url): bool {
    if ($market === '' || $url === '') {
      return FALSE;
    }
    return str_starts_with($url, $this->prefixFor($market));
  }

  /**
   * Every host that may appear in a family URL (SSRF validation allowlist).
   *
   * @return string[]
   */
  public function allowedHosts(): array {
    $hosts = [];
    $canonical = parse_url((string) $this->settings()->get('canonical_host'), PHP_URL_HOST);
    if (is_string($canonical) && $canonical !== '') {
      $hosts[strtolower($canonical)] = TRUE;
    }
    foreach ($this->markets() as $market) {
      if (empty($market['prefix'])) {
        continue;
      }
      $host = parse_url((string) $market['prefix'], PHP_URL_HOST);
      if (is_string($host) && $host !== '') {
        $hosts[strtolower($host)] = TRUE;
      }
    }
    return array_keys($hosts);
  }

  /**
   * The key-module key name that holds a market's HMAC secret.
   */
  public function keyNameFor(string $market): string {
    return (string) ($this->markets()[$market]['key_name'] ?? '');
  }

  /**
   * Resolve a market's shared HMAC secret.
   *
   * Returns '' when no secret is configured — callers must fail closed:
   * - Unknown (unconfigured) markets never get a secret.
   * - A configured key_name that fails to resolve is an error, not a
   *   fall-through: no env fallback, so a broken key config cannot silently
   *   downgrade every market to one shared secret.
   * - The HREFL_HUB_SECRET env fallback applies only to configured markets
   *   with no key_name (local development).
   */
  public function secretFor(string $market): string {
    if (!array_key_exists($market, $this->markets())) {
      return '';
    }
    $keyName = $this->keyNameFor($market);
    if ($keyName !== '') {
      if (is_object($this->keyRepository) && method_exists($this->keyRepository, 'getKey')) {
        $key = $this->keyRepository->getKey($keyName);
        if (is_object($key) && method_exists($key, 'getKeyValue')) {
          return (string) $key->getKeyValue();
        }
      }
      return '';
    }
    $env = getenv('HREFL_HUB_SECRET');
    return is_string($env) ? $env : '';
  }

  /**
   * The configured markets map.
   */
  private function markets(): array {
    return (array) ($this->settings()->get('markets') ?? []);
  }

  private function settings() {
    return $this->configFactory->get('hrefl_hub.settings');
  }

}
