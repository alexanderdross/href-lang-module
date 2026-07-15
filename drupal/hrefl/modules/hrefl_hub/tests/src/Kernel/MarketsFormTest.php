<?php

declare(strict_types=1);

namespace Drupal\Tests\hrefl_hub\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\hrefl_hub\Form\MarketsForm;
use Drupal\KernelTests\KernelTestBase;

/**
 * The markets admin screen adds and removes markets in config.
 *
 * @group hrefl_hub
 */
final class MarketsFormTest extends KernelTestBase {

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

  public function testAddMarketWritesConfig(): void {
    $formState = new FormState();
    $formState->setValue('existing', $this->existingRows());
    $formState->setValue('add', [
      'market' => 'es',
      'prefix' => 'https://pro.boehringer-ingelheim.es',
      'key_name' => '',
    ]);

    $this->submit($formState);

    $markets = $this->config('hrefl_hub.settings')->get('markets');
    $this->assertArrayHasKey('es', $markets);
    // Prefix is normalized to a trailing slash.
    $this->assertSame('https://pro.boehringer-ingelheim.es/', $markets['es']['prefix']);
    // Existing markets are preserved.
    $this->assertArrayHasKey('de', $markets);
  }

  public function testRemoveMarketDropsItFromConfig(): void {
    $rows = $this->existingRows();
    $rows['us']['remove'] = 1;

    $formState = new FormState();
    $formState->setValue('existing', $rows);
    $formState->setValue('add', ['market' => '', 'prefix' => '', 'key_name' => '']);

    $this->submit($formState);

    $markets = $this->config('hrefl_hub.settings')->get('markets');
    $this->assertArrayNotHasKey('us', $markets);
    $this->assertArrayHasKey('de', $markets);
  }

  /**
   * The existing-market rows as the form would submit them (no removals).
   */
  private function existingRows(): array {
    $rows = [];
    foreach ((array) $this->config('hrefl_hub.settings')->get('markets') as $key => $market) {
      $rows[$key] = [
        'prefix' => $market['prefix'] ?? '',
        'key_name' => $market['key_name'] ?? '',
        'remove' => 0,
      ];
    }
    return $rows;
  }

  private function submit(FormState $formState): void {
    $form = MarketsForm::create($this->container);
    $built = [];
    $form->submitForm($built, $formState);
  }

}
