<?php

declare(strict_types=1);

namespace Drupal\hrefl_hub\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\hrefl_hub\Plugin\HreflAiMatcher\AiMatcherManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Hub configuration: canonical host, thresholds, and the AI matcher provider.
 *
 * The admin can run **either** Microsoft Copilot or Anthropic — both providers
 * are fully supported. Each one's connection (endpoint, model, API key) is
 * configured here, and the "Active AI matcher provider" select decides which of
 * them the engine actually calls (for both adjudication and translation).
 */
final class HubSettingsForm extends ConfigFormBase {

  private const SETTINGS = 'hrefl_hub.settings';

  /**
   * Providers offered in the UI, in display order.
   */
  private const PROVIDERS = ['copilot', 'anthropic'];

  /**
   * The AI matcher provider plugin manager (for readiness hints).
   */
  protected AiMatcherManager $matcherManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = parent::create($container);
    $instance->matcherManager = $container->get('plugin.manager.hrefl_ai_matcher');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'hrefl_hub_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return [self::SETTINGS];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config(self::SETTINGS);

    $form['canonical_host'] = [
      '#type' => 'url',
      '#title' => $this->t('Canonical host'),
      '#default_value' => $config->get('canonical_host'),
      '#description' => $this->t('Absolute host all backends live under. Used to enforce URL ownership.'),
      '#required' => TRUE,
    ];
    $form['auto_confirm_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable auto-confirm for high-confidence matches'),
      '#default_value' => $config->get('auto_confirm_enabled'),
      '#description' => $this->t('Leave off for a cautious launch (review everything first).'),
    ];
    $form['confirm'] = [
      '#type' => 'number',
      '#title' => $this->t('Auto-confirm threshold'),
      '#min' => 0,
      '#max' => 1,
      '#step' => 0.01,
      '#default_value' => $config->get('thresholds.confirm'),
    ];
    $form['floor'] = [
      '#type' => 'number',
      '#title' => $this->t('Floor threshold (below this, hold for attention)'),
      '#min' => 0,
      '#max' => 1,
      '#step' => 0.01,
      '#default_value' => $config->get('thresholds.floor'),
    ];

    $form['ai'] = [
      '#type' => 'details',
      '#title' => $this->t('AI matcher (Tier C)'),
      '#open' => TRUE,
      '#description' => $this->t('Copilot and Anthropic are both fully supported — neither is mandated. Configure either (or both) below, then choose which one the engine uses. The selected provider handles both adjudication and title/URL translation.'),
    ];
    $form['ai']['provider'] = [
      '#type' => 'select',
      '#title' => $this->t('Active AI matcher provider'),
      '#options' => [
        'copilot' => $this->t('Microsoft Copilot'),
        'anthropic' => $this->t('Anthropic'),
      ],
      '#default_value' => $config->get('ai_matcher.provider') ?: 'copilot',
      '#description' => $this->t('Which provider the engine calls at run time. The other can stay configured as a ready alternative you can switch to at any time.'),
    ];
    $form['ai']['data_scope'] = [
      '#type' => 'select',
      '#title' => $this->t('AI data scope'),
      '#options' => [
        'metadata' => $this->t('Metadata only (recommended)'),
        'full' => $this->t('Metadata + body excerpt (gated)'),
      ],
      '#default_value' => $config->get('ai_matcher.data_scope') ?: 'metadata',
      '#description' => $this->t('Applies to whichever provider is active.'),
    ];

    // Per-provider connection settings, nested so submit reads them as a tree.
    $form['ai']['providers'] = ['#type' => 'container', '#tree' => TRUE];
    $form['ai']['providers']['copilot'] = $this->providerFieldset(
      'copilot',
      $this->t('Microsoft Copilot'),
      $config,
      FALSE,
      $this->t('Approved, region-resident Copilot / Azure OpenAI chat-completions URL.'),
      'gpt-4o',
      'HREFL_COPILOT_KEY',
    );
    $form['ai']['providers']['anthropic'] = $this->providerFieldset(
      'anthropic',
      $this->t('Anthropic'),
      $config,
      TRUE,
      $this->t('Anthropic Messages API URL, e.g. https://api.anthropic.com/v1/messages.'),
      'claude-sonnet-4-5',
      'HREFL_ANTHROPIC_KEY',
    );

    // Tier B: semantic candidate generation via embeddings (optional).
    $embedding = (array) $config->get('embedding');
    $http = (array) ($embedding['providers']['http'] ?? []);
    $form['embedding'] = [
      '#type' => 'details',
      '#title' => $this->t('Semantic matching (Tier B embeddings)'),
      '#open' => FALSE,
      '#description' => $this->t('Optional. Surfaces candidate equivalents by meaning before the LLM adjudicates. A self-hosted multilingual model is preferred to keep content in-house. Inert until an endpoint is set.'),
    ];
    $form['embedding']['embedding_provider'] = [
      '#type' => 'select',
      '#title' => $this->t('Active embedding provider'),
      '#options' => ['http' => $this->t('HTTP embedding endpoint')],
      '#default_value' => $embedding['provider'] ?? 'http',
    ];
    $form['embedding']['embedding_threshold'] = [
      '#type' => 'number',
      '#title' => $this->t('Similarity threshold (cosine)'),
      '#min' => 0,
      '#max' => 1,
      '#step' => 0.01,
      '#default_value' => $embedding['threshold'] ?? 0.82,
    ];
    $form['embedding']['embedding_top_k'] = [
      '#type' => 'number',
      '#title' => $this->t('Candidates per page'),
      '#min' => 1,
      '#max' => 50,
      '#step' => 1,
      '#default_value' => $embedding['top_k'] ?? 5,
    ];
    $form['embedding']['embedding_providers'] = ['#type' => 'container', '#tree' => TRUE];
    $form['embedding']['embedding_providers']['http'] = [
      '#type' => 'details',
      '#title' => $this->t('HTTP embedding endpoint'),
      '#open' => TRUE,
    ];
    $form['embedding']['embedding_providers']['http']['endpoint'] = [
      '#type' => 'url',
      '#title' => $this->t('API endpoint'),
      '#default_value' => $http['endpoint'] ?? '',
      '#description' => $this->t('Self-hosted or Azure/OpenAI-style embeddings URL.'),
    ];
    $form['embedding']['embedding_providers']['http']['model'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Model'),
      '#default_value' => $http['model'] ?? '',
    ];
    $form['embedding']['embedding_providers']['http']['key_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('API key (key module key name, optional)'),
      '#default_value' => $http['key_name'] ?? '',
      '#description' => $this->t('Optional; a self-hosted server may need none. For local dev set the <code>HREFL_EMBEDDING_KEY</code> environment variable.'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * Build one provider's connection fieldset.
   */
  private function providerFieldset(string $id, TranslatableMarkup $label, $config, bool $with_api_version, TranslatableMarkup $endpoint_help, string $model_example, string $env_var): array {
    $settings = (array) ($config->get('providers')[$id] ?? []);

    $ready = FALSE;
    try {
      $ready = $this->matcherManager->createInstance($id)->isConfigured();
    }
    catch (\Throwable $e) {
      // Leave $ready FALSE if the plugin cannot be built.
    }
    $status = $ready
      ? $this->t('✔ Ready — endpoint, model and API key are all set.')
      : $this->t('⚠ Not fully configured yet — needs an endpoint, a model and an API key.');

    $set = [
      '#type' => 'details',
      '#title' => $label,
      // Open the fieldset that still needs attention.
      '#open' => !$ready,
      '#description' => $status,
    ];
    $set['endpoint'] = [
      '#type' => 'url',
      '#title' => $this->t('API endpoint'),
      '#default_value' => $settings['endpoint'] ?? '',
      '#description' => $endpoint_help,
    ];
    $set['model'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Model'),
      '#default_value' => $settings['model'] ?? '',
      '#description' => $this->t('Model / deployment name, e.g. @ex.', ['@ex' => $model_example]),
    ];
    if ($with_api_version) {
      $set['api_version'] = [
        '#type' => 'textfield',
        '#title' => $this->t('API version'),
        '#default_value' => $settings['api_version'] ?? '2023-06-01',
        '#description' => $this->t('Value sent as the <code>anthropic-version</code> header.'),
      ];
    }
    $set['key_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('API key (key module key name)'),
      '#default_value' => $settings['key_name'] ?? '',
      '#description' => $this->t('Name of a key defined in the <em>key</em> module that holds this provider’s API key. Secrets are never stored in this config. For local development you can instead set the <code>@env</code> environment variable.', ['@env' => $env_var]),
    ];
    return $set;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $providers = (array) $form_state->getValue('providers');

    $config = $this->config(self::SETTINGS)
      ->set('canonical_host', rtrim((string) $form_state->getValue('canonical_host'), '/'))
      ->set('auto_confirm_enabled', (bool) $form_state->getValue('auto_confirm_enabled'))
      ->set('thresholds.confirm', (float) $form_state->getValue('confirm'))
      ->set('thresholds.floor', (float) $form_state->getValue('floor'))
      ->set('ai_matcher.provider', (string) $form_state->getValue('provider'))
      ->set('ai_matcher.data_scope', (string) $form_state->getValue('data_scope'));

    foreach (self::PROVIDERS as $id) {
      $p = (array) ($providers[$id] ?? []);
      $config
        ->set("providers.$id.endpoint", trim((string) ($p['endpoint'] ?? '')))
        ->set("providers.$id.model", trim((string) ($p['model'] ?? '')))
        ->set("providers.$id.key_name", trim((string) ($p['key_name'] ?? '')));
      if (array_key_exists('api_version', $p)) {
        $config->set("providers.$id.api_version", trim((string) $p['api_version']) ?: '2023-06-01');
      }
    }

    // Tier B embeddings.
    $embeddingProviders = (array) $form_state->getValue('embedding_providers');
    $http = (array) ($embeddingProviders['http'] ?? []);
    $config
      ->set('embedding.provider', (string) $form_state->getValue('embedding_provider'))
      ->set('embedding.threshold', (float) $form_state->getValue('embedding_threshold'))
      ->set('embedding.top_k', (int) $form_state->getValue('embedding_top_k'))
      ->set('embedding.providers.http.endpoint', trim((string) ($http['endpoint'] ?? '')))
      ->set('embedding.providers.http.model', trim((string) ($http['model'] ?? '')))
      ->set('embedding.providers.http.key_name', trim((string) ($http['key_name'] ?? '')));

    $config->save();

    parent::submitForm($form, $form_state);
  }

}
