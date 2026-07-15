<?php

declare(strict_types=1);

namespace Drupal\hrefl_client\Drush\Commands;

use Drupal\hrefl_client\Service\AlternatesConsumer;
use Drupal\hrefl_client\Service\HreflangEmitter;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for the Hreflang Client.
 *
 * Phase 0 (POC): seed a hand-authored mapping straight into the local store
 * without standing up the hub, and inspect the emitted tags for a URL to
 * verify the reciprocal pair — the roadmap's "view-source" exit check.
 */
final class HreflClientCommands extends DrushCommands {

  use AutowireTrait;

  public function __construct(
    private readonly AlternatesConsumer $consumer,
    private readonly HreflangEmitter $emitter,
  ) {
    parent::__construct();
  }

  /**
   * Seed the local alternates store from a serve-shaped JSON file (no hub).
   *
   * The file has the same shape the hub serve endpoint returns:
   * {"pages": [{"url": "...", "group_uuid": "...", "alternates": [
   *   {"hreflang": "de", "href": "https://..."}, ...]}]}.
   */
  #[CLI\Command(name: 'hrefl:seed', aliases: ['hrseed'])]
  #[CLI\Argument(name: 'file', description: 'Path to a serve-shaped JSON seed file.')]
  #[CLI\Usage(name: 'drush hrefl:seed modules/custom/hrefl/modules/hrefl_client/seed/example.seed.json', description: 'Load the example About-us group into the local store.')]
  public function seed(string $file): void {
    if (!is_file($file) || !is_readable($file)) {
      throw new \InvalidArgumentException(sprintf('Seed file not found or unreadable: %s', $file));
    }
    $payload = json_decode((string) file_get_contents($file), TRUE);
    if (!is_array($payload)) {
      throw new \InvalidArgumentException('Seed file is not valid JSON.');
    }
    $count = $this->consumer->ingestPayload($payload);
    $this->logger()->success(dt('Seeded @count page(s) into the local alternates store.', ['@count' => $count]));
  }

  /**
   * Print the hreflang link tags this backend would emit for a URL.
   *
   * Reads the local store through the same validated path as page render, so
   * the output is exactly what ships in the page <head>.
   */
  #[CLI\Command(name: 'hrefl:show', aliases: ['hrshow'])]
  #[CLI\Argument(name: 'url', description: 'Absolute URL to inspect.')]
  #[CLI\Usage(name: 'drush hrefl:show https://pro.boehringer-ingelheim.com/de/ueber-uns', description: 'Show the emitted hreflang tags for the German About-us page.')]
  public function show(string $url): void {
    $alternates = $this->emitter->alternates($url);
    if (!$alternates) {
      $this->logger()->warning(dt('No stored alternates for @url (falls back to the safe subset at render).', ['@url' => $url]));
      return;
    }
    foreach ($alternates as $alt) {
      $this->output()->writeln(sprintf(
        '<link rel="alternate" hreflang="%s" href="%s" />',
        $alt['hreflang'],
        $alt['href'],
      ));
    }
  }

}
