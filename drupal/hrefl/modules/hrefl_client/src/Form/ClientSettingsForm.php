<?php

declare(strict_types=1);

namespace Drupal\hrefl_client\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Client configuration: hub URL, this market, base URL, langcode map.
 */
final class ClientSettingsForm extends ConfigFormBase {

  private const SETTINGS = 'hrefl_client.settings';

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'hrefl_client_settings';
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

    $form['hub_base_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Hub base URL'),
      '#default_value' => $config->get('hub_base_url'),
      '#required' => TRUE,
    ];
    $form['market'] = [
      '#type' => 'textfield',
      '#title' => $this->t('This market key'),
      '#default_value' => $config->get('market'),
      '#description' => $this->t('For example: global, de, us, ca.'),
      '#required' => TRUE,
    ];
    $form['hub_key_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Hub HMAC secret (key module key name)'),
      '#default_value' => $config->get('hub_key_name'),
      '#description' => $this->t('Name of a key holding the shared secret used to sign hub requests. For local development you can set the <code>HREFL_HUB_SECRET</code> environment variable instead.'),
    ];
    $form['site_base_url'] = [
      '#type' => 'url',
      '#title' => $this->t('This backend base URL'),
      '#default_value' => $config->get('site_base_url'),
      '#required' => TRUE,
    ];
    $form['emit_head_tags'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Emit hreflang head tags on this backend'),
      '#default_value' => $config->get('emit_head_tags'),
    ];
    $form['sitemap_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Serve the multilingual sitemap at /hrefl/sitemap.xml'),
      '#default_value' => $config->get('sitemap_enabled'),
    ];
    $form['sitemap_priority'] = [
      '#type' => 'number',
      '#title' => $this->t('Default sitemap priority'),
      '#min' => 0,
      '#max' => 1,
      '#step' => 0.1,
      '#default_value' => $config->get('sitemap_priority'),
    ];
    $form['emit_link_header'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Emit hreflang HTTP Link header on non-HTML responses (e.g. PDFs)'),
      '#default_value' => $config->get('emit_link_header'),
    ];
    $form['thumbnail_field'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Thumbnail image field'),
      '#default_value' => $config->get('thumbnail_field'),
      '#description' => $this->t('Optional. Machine name of an image field (e.g. field_image) whose image is sent as the review preview thumbnail. Leave empty to send none.'),
    ];
    $form['hreflang_map'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Langcode to hreflang map'),
      '#default_value' => $this->encodeMap((array) $config->get('hreflang_map')),
      '#description' => $this->t('One per line as langcode|hreflang, e.g. en|en-US.'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config(self::SETTINGS)
      ->set('hub_base_url', rtrim((string) $form_state->getValue('hub_base_url'), '/'))
      ->set('market', trim((string) $form_state->getValue('market')))
      ->set('hub_key_name', trim((string) $form_state->getValue('hub_key_name')))
      ->set('site_base_url', rtrim((string) $form_state->getValue('site_base_url'), '/'))
      ->set('emit_head_tags', (bool) $form_state->getValue('emit_head_tags'))
      ->set('sitemap_enabled', (bool) $form_state->getValue('sitemap_enabled'))
      ->set('sitemap_priority', (float) $form_state->getValue('sitemap_priority'))
      ->set('emit_link_header', (bool) $form_state->getValue('emit_link_header'))
      ->set('thumbnail_field', trim((string) $form_state->getValue('thumbnail_field')))
      ->set('hreflang_map', $this->decodeMap((string) $form_state->getValue('hreflang_map')))
      ->save();
    parent::submitForm($form, $form_state);
  }

  private function encodeMap(array $map): string {
    $lines = [];
    foreach ($map as $langcode => $hreflang) {
      $lines[] = $langcode . '|' . $hreflang;
    }
    return implode("\n", $lines);
  }

  private function decodeMap(string $text): array {
    $map = [];
    foreach (preg_split('/\r?\n/', $text) as $line) {
      $line = trim($line);
      if ($line === '' || !str_contains($line, '|')) {
        continue;
      }
      [$langcode, $hreflang] = array_map('trim', explode('|', $line, 2));
      if ($langcode !== '') {
        $map[$langcode] = $hreflang;
      }
    }
    return $map;
  }

}
