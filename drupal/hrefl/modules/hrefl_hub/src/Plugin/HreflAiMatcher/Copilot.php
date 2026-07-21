<?php

declare(strict_types=1);

namespace Drupal\hrefl_hub\Plugin\HreflAiMatcher;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\hrefl_hub\Attribute\HreflAiMatcher;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Copilot API matcher provider (chat-completions style endpoint).
 *
 * Works against an approved, region-resident Copilot / Azure OpenAI deployment.
 * The endpoint and model are configured per environment.
 */
#[HreflAiMatcher(
  id: 'copilot',
  label: new TranslatableMarkup('Copilot'),
  description: new TranslatableMarkup('Adjudicates ambiguous matches via an approved Copilot API endpoint.'),
)]
final class Copilot extends AiMatcherBase {

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
   * One chat-completions call; returns the message text, or NULL on failure.
   */
  private function chat(string $system, string $user): ?string {
    $s = $this->providerSettings();
    try {
      $response = $this->requestWithRetry('POST', $s['endpoint'], [
        'headers' => [
          'authorization' => 'Bearer ' . $this->apiKey(),
          'content-type' => 'application/json',
        ],
        'json' => [
          'model' => $s['model'],
          'temperature' => 0.0,
          'max_tokens' => 300,
          'response_format' => ['type' => 'json_object'],
          'messages' => [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
          ],
        ],
        'timeout' => 30,
      ]);
      $payload = json_decode((string) $response->getBody(), TRUE);
      return (string) ($payload['choices'][0]['message']['content'] ?? '');
    }
    catch (GuzzleException $e) {
      $this->logger->error('Copilot request failed: @m', ['@m' => $e->getMessage()]);
      return NULL;
    }
  }

  /**
   * Resolve the API key: HREFL_COPILOT_KEY env var, else the key module.
   */
  private function apiKey(): string {
    return $this->resolveApiKey('HREFL_COPILOT_KEY');
  }

}
