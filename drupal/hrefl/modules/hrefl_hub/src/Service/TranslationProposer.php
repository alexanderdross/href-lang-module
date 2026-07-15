<?php

declare(strict_types=1);

namespace Drupal\hrefl_hub\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\hrefl_hub\Plugin\HreflAiMatcher\AiMatcherManager;
use Psr\Log\LoggerInterface;

/**
 * AI-assisted mapping via translation (Tier C, Copilot or Anthropic).
 *
 * For a page with no cross-market group, ask the provider to translate its
 * title + slug into each other language present in the registry. The translated
 * slug is used to look up an existing target-market page; if one is found the
 * two are proposed as a group (for human review) and the confirmed-later slug
 * pair is also learned into the glossary, so the URL-pattern tier catches it
 * deterministically next time.
 *
 * Everything this produces is `proposed`: it never confirms or publishes. AI
 * calls are deliberate (invoked on demand / for held pages), not on every cron,
 * to keep cost and data governance under control.
 */
final class TranslationProposer {

  /**
   * Confidence recorded for a translation-driven proposal (needs review).
   */
  private const CONFIDENCE = 0.7;

  public function __construct(
    private readonly Registry $registry,
    private readonly AiMatcherManager $matcherManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Propose cross-market equivalents for one member via translation.
   *
   * @return int
   *   Number of proposals made.
   */
  public function proposeForMember(array $member): int {
    $provider = $this->activeProvider();
    if (!$provider) {
      return 0;
    }
    $source = [
      'url' => (string) $member['url'],
      'market' => (string) $member['market'],
      'language' => (string) $member['language'],
      'title' => (string) ($member['title'] ?? ''),
    ];

    $made = 0;
    foreach ($this->registry->distinctLanguages() as $lang) {
      if ($lang === $member['language']) {
        continue;
      }
      $translation = $provider->translate($source, $lang);
      $slug = (string) ($translation['slug'] ?? '');
      if ($slug === '') {
        continue;
      }
      foreach ($this->registry->membersBySlug([$slug], (string) $member['market']) as $peer) {
        if ($peer['language'] !== $lang) {
          continue;
        }
        $this->joinPeerGroup($member, $peer, $translation);
        // Seed the glossary so the deterministic tier catches this next time.
        $this->registry->addGlossaryEntry((string) $member['language'], $lang, (string) $member['path_key'], $slug);
        $this->registry->addGlossaryEntry($lang, (string) $member['language'], $slug, (string) $member['path_key']);
        $made++;
        break;
      }
    }
    return $made;
  }

  /**
   * Move the source member into the peer's group as a reviewable proposal.
   */
  private function joinPeerGroup(array $member, array $peer, array $translation): void {
    $this->registry->upsertMember([
      'group_uuid' => (string) $peer['group_uuid'],
      'market' => (string) $member['market'],
      'language' => (string) $member['language'],
      'hreflang' => (string) $member['hreflang'],
      'url' => (string) $member['url'],
      'title' => $member['title'] ?? NULL,
      'entity_type' => $member['entity_type'] ?? NULL,
      'entity_id' => $member['entity_id'] ?? NULL,
      'status' => 'proposed',
      'matched_by' => 'llm',
      'confidence' => self::CONFIDENCE,
      'signals' => [
        'llm' => self::CONFIDENCE,
        'translated_title' => $translation['title'] ?? '',
        'translated_slug' => $translation['slug'] ?? '',
        'matched_peer' => $peer['url'],
      ],
      'asserted_by' => (string) $member['market'],
      'valid' => (int) ($member['valid'] ?? 0),
      '_via' => 'automation',
    ]);
    $this->registry->logDecision(
      (string) $peer['group_uuid'],
      (int) $member['id'],
      'ai-translate-propose',
      'engine',
      ['translated_slug' => $translation['slug'] ?? ''],
    );
  }

  /**
   * The configured, ready provider, or NULL.
   */
  private function activeProvider() {
    $id = $this->configFactory->get('hrefl_hub.settings')->get('ai_matcher.provider') ?: 'copilot';
    try {
      /** @var \Drupal\hrefl_hub\Plugin\HreflAiMatcher\AiMatcherInterface $provider */
      $provider = $this->matcherManager->createInstance($id);
      return $provider->isConfigured() ? $provider : NULL;
    }
    catch (\Throwable $e) {
      $this->logger->warning('AI provider @id unavailable for translation: @m', ['@id' => $id, '@m' => $e->getMessage()]);
      return NULL;
    }
  }

}
