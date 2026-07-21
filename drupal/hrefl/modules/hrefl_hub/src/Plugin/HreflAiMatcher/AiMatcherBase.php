<?php

declare(strict_types=1);

namespace Drupal\hrefl_hub\Plugin\HreflAiMatcher;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Plugin\PluginBase;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\hrefl_hub\Http\RetriesHttp;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Base class for AI matcher providers.
 *
 * Provides shared prompt construction and a strict response contract so the two
 * shipped providers (Anthropic, Copilot) only differ in transport and auth.
 */
abstract class AiMatcherBase extends PluginBase implements AiMatcherInterface, ContainerFactoryPluginInterface {

  use StringTranslationTrait;
  use RetriesHttp;

  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    protected readonly ClientInterface $httpClient,
    protected readonly ConfigFactoryInterface $configFactory,
    protected readonly LoggerInterface $logger,
    // \Drupal\key\KeyRepositoryInterface when the (optional) key module is
    // installed, otherwise NULL. Typed loosely so the class still loads without
    // the key module present.
    protected readonly mixed $keyRepository = NULL,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('http_client'),
      $container->get('config.factory'),
      $container->get('logger.channel.hrefl_hub'),
      // Optional: absent unless the key module is enabled.
      $container->get('key.repository', ContainerInterface::NULL_ON_INVALID_REFERENCE),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    return (string) $this->pluginDefinition['label'];
  }

  /**
   * Per-provider settings from hrefl_hub.settings:providers.<id>.
   */
  protected function providerSettings(): array {
    $settings = $this->configFactory->get('hrefl_hub.settings');
    return (array) ($settings->get('providers')[$this->getPluginId()] ?? []);
  }

  /**
   * Resolve this provider's API key without ever storing it in config.
   *
   * Order: (1) an environment variable (handy for local/dev), then (2) the
   * key module, looked up by this provider's configured `key_name`. Returns an
   * empty string when no key is available (the caller then reports "not
   * configured" rather than making an unauthenticated request).
   *
   * @param string $envVar
   *   Name of the environment variable to check first.
   */
  protected function resolveApiKey(string $envVar): string {
    $env = getenv($envVar);
    if (is_string($env) && $env !== '') {
      return $env;
    }
    $keyName = (string) ($this->providerSettings()['key_name'] ?? '');
    if ($keyName !== '' && is_object($this->keyRepository) && method_exists($this->keyRepository, 'getKey')) {
      $key = $this->keyRepository->getKey($keyName);
      if (is_object($key) && method_exists($key, 'getKeyValue')) {
        return (string) $key->getKeyValue();
      }
    }
    return '';
  }

  /**
   * Whether AI may see full page body, or metadata only (default).
   */
  protected function fullScopeAllowed(): bool {
    return $this->configFactory->get('hrefl_hub.settings')->get('ai_matcher.data_scope') === 'full';
  }

  /**
   * Build the provider-neutral instruction shared by every request.
   */
  protected function systemInstruction(): string {
    return implode(' ', [
      'You match localized versions of web pages across country markets.',
      'Given a SOURCE page and a numbered list of CANDIDATE pages, choose the single candidate',
      'that is the same content localized for another market, or none if there is no good match.',
      'Only choose from the supplied candidates. Never invent a URL.',
      'Respond with strict JSON only: {"choice": <index or null>, "confidence": <0..1>, "rationale": "<short>"}.',
    ]);
  }

  /**
   * Render the source + candidates into a compact user message.
   */
  protected function userMessage(array $source, array $candidates): string {
    $lines = ['SOURCE:', $this->renderRecord($source), '', 'CANDIDATES:'];
    foreach (array_values($candidates) as $i => $candidate) {
      $lines[] = '[' . $i . '] ' . $this->renderRecord($candidate);
    }
    return implode("\n", $lines);
  }

  /**
   * Render one record to the allowed data scope (metadata by default).
   */
  protected function renderRecord(array $record): string {
    $parts = [
      'market=' . ($record['market'] ?? ''),
      'lang=' . ($record['language'] ?? ''),
      'title=' . ($record['title'] ?? ''),
      'desc=' . ($record['meta_description'] ?? ''),
      'headings=' . implode(' | ', array_slice($record['headings'] ?? [], 0, 5)),
      'url=' . ($record['url'] ?? ''),
    ];
    if ($this->fullScopeAllowed() && !empty($record['body_excerpt'])) {
      $parts[] = 'body=' . mb_substr((string) $record['body_excerpt'], 0, 1500);
    }
    return implode(' ; ', $parts);
  }

  /**
   * Parse and validate a provider's JSON answer against the candidate count.
   */
  protected function parseAnswer(string $raw, int $candidateCount): array {
    // Providers sometimes wrap JSON in prose or code fences; extract the object.
    if (preg_match('/\{.*\}/s', $raw, $m)) {
      $raw = $m[0];
    }
    $data = json_decode($raw, TRUE);
    if (!is_array($data)) {
      return ['choice' => NULL, 'confidence' => 0.0, 'rationale' => 'unparseable response'];
    }
    $choice = $data['choice'] ?? NULL;
    if (!is_int($choice) || $choice < 0 || $choice >= $candidateCount) {
      $choice = NULL;
    }
    $confidence = (float) ($data['confidence'] ?? 0.0);
    $confidence = max(0.0, min(1.0, $confidence));
    return [
      'choice' => $choice,
      'confidence' => $confidence,
      'rationale' => (string) ($data['rationale'] ?? ''),
    ];
  }

  /**
   * The safe "no decision" answer used on any error.
   */
  protected function noDecision(string $reason): array {
    return ['choice' => NULL, 'confidence' => 0.0, 'rationale' => $reason];
  }

  /**
   * Instruction for the translation task.
   */
  protected function translationInstruction(string $targetLanguage): string {
    return implode(' ', [
      'You localize web-page metadata for an international site.',
      'Translate the SOURCE page title into the target language (' . $targetLanguage . '),',
      'and produce a URL slug for that translated title:',
      'lowercase, words separated by single hyphens, ASCII where reasonable, no leading/trailing hyphen.',
      'Do not translate brand or product names that should stay identical across markets.',
      'Respond with strict JSON only: {"title": "<translated title>", "slug": "<translated-slug>"}.',
    ]);
  }

  /**
   * Render the source record for the translation prompt.
   */
  protected function translationUserMessage(array $source, string $targetLanguage): string {
    return implode("\n", [
      'TARGET_LANGUAGE: ' . $targetLanguage,
      'SOURCE_LANGUAGE: ' . ($source['language'] ?? ''),
      'SOURCE_TITLE: ' . ($source['title'] ?? ''),
      'SOURCE_URL: ' . ($source['url'] ?? ''),
    ]);
  }

  /**
   * Parse and sanitize a translation answer.
   *
   * @return array
   *   ['title' => string, 'slug' => string].
   */
  protected function parseTranslation(string $raw): array {
    if (preg_match('/\{.*\}/s', $raw, $m)) {
      $raw = $m[0];
    }
    $data = json_decode($raw, TRUE);
    if (!is_array($data)) {
      return ['title' => '', 'slug' => ''];
    }
    return [
      'title' => trim((string) ($data['title'] ?? '')),
      'slug' => $this->sanitizeSlug((string) ($data['slug'] ?? '')),
    ];
  }

  /**
   * Normalize an AI-proposed slug to a safe URL token.
   */
  protected function sanitizeSlug(string $slug): string {
    $slug = mb_strtolower(trim($slug));
    // Spaces and underscores to hyphens.
    $slug = preg_replace('/[\s_]+/u', '-', $slug);
    // Drop anything that is not a unicode letter/number or hyphen.
    $slug = preg_replace('/[^\p{L}\p{N}-]+/u', '', $slug);
    // Collapse repeats and trim hyphens.
    $slug = preg_replace('/-+/', '-', (string) $slug);
    return trim((string) $slug, '-');
  }

  /**
   * The empty translation used on any error.
   */
  protected function noTranslation(): array {
    return ['title' => '', 'slug' => ''];
  }

}
