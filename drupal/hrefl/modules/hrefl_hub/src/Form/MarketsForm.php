<?php

declare(strict_types=1);

namespace Drupal\hrefl_hub\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\hrefl_hub\Service\MarketRegistry;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Guided market management: add, edit, and remove the family's markets.
 *
 * A market owns an absolute URL prefix - a path under the shared host
 * (https://host/de/) or a whole domain (https://host.es/) - and names the
 * key-module key holding its shared HMAC secret. This is the onboarding entry
 * point: add a market here, set the matching secret on its client, done.
 */
final class MarketsForm extends ConfigFormBase {

  private const SETTINGS = 'hrefl_hub.settings';

  /**
   * The market registry (for secret-readiness hints).
   */
  protected MarketRegistry $marketRegistry;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = parent::create($container);
    $instance->marketRegistry = $container->get('hrefl_hub.market_registry');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'hrefl_hub_markets';
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
    $markets = (array) $this->config(self::SETTINGS)->get('markets');

    $form['intro'] = [
      '#markup' => '<p>' . $this->t('Each market owns an absolute URL prefix (a path like <code>https://host/de/</code> or a whole domain like <code>https://host.es/</code>) and authenticates its client with a shared HMAC secret.') . '</p>',
    ];

    $form['existing'] = [
      '#type' => 'container',
      '#tree' => TRUE,
    ];
    foreach ($markets as $key => $market) {
      $ready = $this->marketRegistry->secretFor((string) $key) !== '';
      $form['existing'][$key] = [
        '#type' => 'details',
        '#title' => $this->t('Market: @key', ['@key' => $key]),
        '#open' => FALSE,
      ];
      $form['existing'][$key]['prefix'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Owned URL prefix'),
        '#default_value' => $market['prefix'] ?? '',
      ];
      $form['existing'][$key]['key_name'] = [
        '#type' => 'textfield',
        '#title' => $this->t('HMAC secret key name'),
        '#default_value' => $market['key_name'] ?? '',
        '#description' => $ready
          ? $this->t('✔ A secret resolves for this market.')
          : $this->t('⚠ No secret resolves yet (set a key, or the HREFL_HUB_SECRET env var).'),
      ];
      $form['existing'][$key]['remove'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Remove this market'),
      ];
    }

    $form['add'] = [
      '#type' => 'details',
      '#title' => $this->t('Add a market'),
      '#open' => empty($markets),
      '#tree' => TRUE,
    ];
    $form['add']['market'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Market key'),
      '#description' => $this->t('Lowercase token, e.g. fr, es, global.'),
    ];
    $form['add']['prefix'] = [
      '#type' => 'url',
      '#title' => $this->t('Owned URL prefix'),
      '#description' => $this->t('Absolute URL prefix - a path (https://host/fr/) or a domain (https://host.fr/).'),
    ];
    $form['add']['key_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('HMAC secret key name (optional)'),
    ];

    $form = parent::buildForm($form, $form_state);
    $form['actions']['generate'] = [
      '#type' => 'submit',
      '#value' => $this->t('Generate a shared secret'),
      '#submit' => ['::generateSecret'],
      '#limit_validation_errors' => [],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    foreach ((array) $form_state->getValue('existing') as $key => $row) {
      $prefix = trim((string) ($row['prefix'] ?? ''));
      if (empty($row['remove']) && !$this->isAbsolute($prefix)) {
        $form_state->setErrorByName("existing][$key][prefix", $this->t('The prefix for market %m must be an absolute http(s) URL.', ['%m' => $key]));
      }
    }

    $add = (array) $form_state->getValue('add');
    $market = trim((string) ($add['market'] ?? ''));
    if ($market === '') {
      return;
    }
    if (!preg_match('/^[a-z0-9_-]+$/', $market)) {
      $form_state->setErrorByName('add][market', $this->t('The market key may contain only lowercase letters, numbers, hyphens and underscores.'));
    }
    if (!$this->isAbsolute(trim((string) ($add['prefix'] ?? '')))) {
      $form_state->setErrorByName('add][prefix', $this->t('A new market needs an absolute http(s) URL prefix.'));
    }
    if (array_key_exists($market, (array) $this->config(self::SETTINGS)->get('markets'))) {
      $form_state->setErrorByName('add][market', $this->t('Market %m already exists; edit it above.', ['%m' => $market]));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $markets = [];
    foreach ((array) $form_state->getValue('existing') as $key => $row) {
      if (!empty($row['remove'])) {
        continue;
      }
      $markets[$key] = [
        'prefix' => $this->normalizePrefix((string) ($row['prefix'] ?? '')),
        'key_name' => trim((string) ($row['key_name'] ?? '')),
      ];
    }

    $add = (array) $form_state->getValue('add');
    $market = trim((string) ($add['market'] ?? ''));
    if ($market !== '') {
      $markets[$market] = [
        'prefix' => $this->normalizePrefix((string) ($add['prefix'] ?? '')),
        'key_name' => trim((string) ($add['key_name'] ?? '')),
      ];
    }

    $this->config(self::SETTINGS)->set('markets', $markets)->save();
    parent::submitForm($form, $form_state);
  }

  /**
   * Secondary action: generate a random shared secret to copy out (not saved).
   */
  public function generateSecret(array &$form, FormStateInterface $form_state): void {
    $secret = bin2hex(random_bytes(32));
    $this->messenger()->addStatus($this->t('New shared secret (copy it now - it is not stored): @s', ['@s' => $secret]));
    $this->messenger()->addStatus($this->t('Store it in a key (key module) and enter the key name for the market, or set HREFL_HUB_SECRET on the hub and the matching client.'));
    $form_state->setRebuild();
  }

  /**
   * Ensure a non-empty prefix ends in a single slash.
   */
  private function normalizePrefix(string $prefix): string {
    $prefix = trim($prefix);
    return $prefix === '' ? '' : rtrim($prefix, '/') . '/';
  }

  /**
   * Whether a value is an absolute http(s) URL.
   */
  private function isAbsolute(string $url): bool {
    $scheme = parse_url($url, PHP_URL_SCHEME);
    $host = parse_url($url, PHP_URL_HOST);
    return is_string($scheme) && is_string($host) && $host !== '' && in_array(strtolower($scheme), ['http', 'https'], TRUE);
  }

}
