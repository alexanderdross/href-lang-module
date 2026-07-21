<?php

declare(strict_types=1);

namespace Drupal\hrefl_hub\Plugin\HreflAiMatcher;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\hrefl_hub\Attribute\HreflAiMatcher;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Anthropic Messages API matcher provider.
 */
#[HreflAiMatcher(
  id: 'anthropic',
  label: new TranslatableMarkup('Anthropic'),
  description: new TranslatableMarkup('Adjudicates ambiguous matches via the Anthropic Messages API.'),
)]
final class Anthropic extends AiMatcherBase {

  /**
   * {@inheritdoc}
   */
  public function isConfigured(): bool {
    $s = $this->providerSettings();
    return !empty($s['endpoint']) && !empty($s['model']) && $this->apiKey() !== '';
  }

  /**
   * {@inheritdoc}
   */
  public function adjudicate(array $source, array $candidates): array {
    if (!$candidates) {
      return $this->noDecision('no candidates');
    }
    if (!$this->isConfigured()) {
      return $this->noDecision('provider not configured');
    }
    $text = $this->chat($this->systemInstruction(), $this->userMessage($source, $candidates));
    if ($text === NULL) {
      return $this->noDecision('request failed');
    }
    return $this->parseAnswer($text, count($candidates));
  }

  /**
   * {@inheritdoc}
   */
  public function translate(array $source, string $targetLanguage): array {
    if ($targetLanguage === '' || !$this->isConfigured()) {
      return $this->noTranslation();
    }
    $text = $this->chat(
      $this->translationInstruction($targetLanguage),
      $this->translationUserMessage($source, $targetLanguage),
    );
    return $text === NULL ? $this->noTranslation() : $this->parseTranslation($text);
  }

  /**
   * One Messages API call; returns the response text, or NULL on failure.
   */
  private function chat(string $system, string $user): ?string {
    $s = $this->providerSettings();
    try {
      $response = $this->requestWithRetry('POST', $s['endpoint'], [
        'headers' => [
          'x-api-key' => $this->apiKey(),
          'anthropic-version' => $s['api_version'] ?? '2023-06-01',
          'content-type' => 'application/json',
        ],
        'json' => [
          'model' => $s['model'],
          'max_tokens' => 300,
          'temperature' => 0.0,
          'system' => $system,
          'messages' => [
            ['role' => 'user', 'content' => $user],
          ],
        ],
        'timeout' => 30,
      ]);
      $payload = json_decode((string) $response->getBody(), TRUE);
      return (string) ($payload['content'][0]['text'] ?? '');
    }
    catch (GuzzleException $e) {
      $this->logger->error('Anthropic request failed: @m', ['@m' => $e->getMessage()]);
      return NULL;
    }
  }

  /**
   * Resolve the API key: HREFL_ANTHROPIC_KEY env var, else the key module.
   */
  private function apiKey(): string {
    return $this->resolveApiKey('HREFL_ANTHROPIC_KEY');
  }

}
