<?php

declare(strict_types=1);

namespace Drupal\Tests\hrefl_hub\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\hrefl_hub\Form\HubSettingsForm;
use Drupal\KernelTests\KernelTestBase;

/**
 * The hub settings form lets the admin pick and fully configure EITHER AI
 * provider, and both providers' connection settings round-trip through config.
 *
 * @group hrefl_hub
 * @covers \Drupal\hrefl_hub\Form\HubSettingsForm
 */
final class HubSettingsFormTest extends KernelTestBase {

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
  }

  /**
   * Submitting the form saves the selected provider and BOTH providers' config.
   */
  public function testBothProvidersRoundTrip(): void {
    $form_state = (new FormState())->setValues([
      'canonical_host' => 'https://pro.example.com',
      'auto_confirm_enabled' => TRUE,
      'confirm' => 0.9,
      'floor' => 0.5,
      // Pick Anthropic as the active provider...
      'provider' => 'anthropic',
      'data_scope' => 'full',
      // ...but configure BOTH, so either can be selected later.
      'providers' => [
        'copilot' => [
          'endpoint' => 'https://copilot.example.com/v1/chat',
          'model' => 'gpt-4o-mini',
          'key_name' => 'copilot_api_key',
        ],
        'anthropic' => [
          'endpoint' => 'https://api.anthropic.com/v1/messages',
          'model' => 'claude-sonnet-4-5',
          'api_version' => '2024-01-01',
          'key_name' => 'anthropic_api_key',
        ],
      ],
      // Tier B embedding fields the submit handler also reads.
      'embedding_provider' => 'http',
      'embedding_threshold' => 0.8,
      'embedding_top_k' => 7,
      'embedding_providers' => [
        'http' => [
          'endpoint' => 'https://emb.example.com/v1/embeddings',
          'model' => 'multilingual-e5-base',
          'key_name' => '',
        ],
      ],
    ]);

    $this->container->get('form_builder')->submitForm(HubSettingsForm::class, $form_state);
    $this->assertEmpty($form_state->getErrors(), 'Form submitted without validation errors.');

    $config = $this->config('hrefl_hub.settings');

    // The active provider selection round-trips.
    $this->assertSame('anthropic', $config->get('ai_matcher.provider'));
    $this->assertSame('full', $config->get('ai_matcher.data_scope'));

    // Copilot config round-trips (no api_version field on Copilot).
    $this->assertSame('https://copilot.example.com/v1/chat', $config->get('providers.copilot.endpoint'));
    $this->assertSame('gpt-4o-mini', $config->get('providers.copilot.model'));
    $this->assertSame('copilot_api_key', $config->get('providers.copilot.key_name'));
    $this->assertNull($config->get('providers.copilot.api_version'));

    // Anthropic config round-trips, including its api_version.
    $this->assertSame('https://api.anthropic.com/v1/messages', $config->get('providers.anthropic.endpoint'));
    $this->assertSame('claude-sonnet-4-5', $config->get('providers.anthropic.model'));
    $this->assertSame('2024-01-01', $config->get('providers.anthropic.api_version'));
    $this->assertSame('anthropic_api_key', $config->get('providers.anthropic.key_name'));

    // Governance: the key is referenced by name only - no raw secret in config.
    $this->assertNull($config->get('providers.copilot.api_key'));
    $this->assertNull($config->get('providers.anthropic.api_key'));
  }

  /**
   * Switching the active provider to Copilot is persisted as-is.
   */
  public function testProviderSelectionSwitches(): void {
    $form_state = (new FormState())->setValues([
      'canonical_host' => 'https://pro.example.com',
      'auto_confirm_enabled' => FALSE,
      'confirm' => 0.95,
      'floor' => 0.6,
      'provider' => 'copilot',
      'data_scope' => 'metadata',
      'providers' => [
        'copilot' => [
          'endpoint' => 'https://copilot.example.com/v1/chat',
          'model' => 'gpt-4o',
          'key_name' => 'copilot_api_key',
        ],
        'anthropic' => [
          'endpoint' => '',
          'model' => '',
          'api_version' => '2023-06-01',
          'key_name' => '',
        ],
      ],
      'embedding_provider' => 'http',
      'embedding_threshold' => 0.82,
      'embedding_top_k' => 5,
      'embedding_providers' => ['http' => ['endpoint' => '', 'model' => 'multilingual-e5-base', 'key_name' => '']],
    ]);

    $this->container->get('form_builder')->submitForm(HubSettingsForm::class, $form_state);
    $this->assertEmpty($form_state->getErrors());

    $this->assertSame('copilot', $this->config('hrefl_hub.settings')->get('ai_matcher.provider'));
  }

}
