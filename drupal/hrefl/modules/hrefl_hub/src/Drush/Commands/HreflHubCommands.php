<?php

declare(strict_types=1);

namespace Drupal\hrefl_hub\Drush\Commands;

use Drupal\hrefl_hub\Service\Registry;
use Drupal\hrefl_hub\Service\TranslationProposer;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for the Hreflang Hub.
 *
 * AI translation is a deliberate, cost- and governance-controlled step, so it
 * is run on demand here rather than on every cron.
 */
final class HreflHubCommands extends DrushCommands {

  use AutowireTrait;

  public function __construct(
    private readonly Registry $registry,
    private readonly TranslationProposer $proposer,
  ) {
    parent::__construct();
  }

  /**
   * Run AI translation-assisted matching over pages still awaiting a match.
   *
   * For each held/proposed member, the configured provider (Copilot or
   * Anthropic) translates its title + slug into the other languages to locate an
   * existing equivalent page and propose the mapping for review. Requires a
   * configured provider.
   */
  #[CLI\Command(name: 'hrefl-hub:translate-match', aliases: ['hrtm'])]
  #[CLI\Option(name: 'limit', description: 'Maximum members to process.')]
  #[CLI\Usage(name: 'drush hrefl-hub:translate-match --limit=50', description: 'Propose mappings for up to 50 unmatched pages via translation.')]
  public function translateMatch(array $options = ['limit' => 100]): void {
    $limit = (int) $options['limit'];
    $members = $this->registry->membersNeedingMatch($limit);
    if (!$members) {
      $this->logger()->success(dt('No members awaiting a match.'));
      return;
    }
    $proposals = 0;
    $processed = 0;
    foreach ($members as $member) {
      if ((int) $member['locked'] === 1) {
        continue;
      }
      $proposals += $this->proposer->proposeForMember($member);
      $processed++;
    }
    if ($proposals) {
      // Re-homing members can orphan their old singleton groups.
      $this->registry->deleteEmptyGroups();
    }
    $this->logger()->success(dt('Processed @n member(s); proposed @p new mapping(s) for review.', [
      '@n' => $processed,
      '@p' => $proposals,
    ]));
  }

}
