<?php

declare(strict_types=1);

namespace Drupal\hrefl_client\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\hrefl_client\Service\HreflangEmitter;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Country / language selector.
 *
 * Renders crawlable <a> links to the equivalent page in each market, from the
 * local store. Context-preserving and, by design, no IP/geo auto-redirect: the
 * user chooses. JavaScript may enhance interaction, but the links work without.
 */
#[Block(
  id: 'hrefl_country_language_selector',
  admin_label: new TranslatableMarkup('Country / language selector (hreflang)'),
  category: new TranslatableMarkup('SEO'),
)]
final class CountryLanguageSelectorBlock extends BlockBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    private readonly HreflangEmitter $emitter,
    private readonly RequestStack $requestStack,
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
      $container->get('hrefl_client.emitter'),
      $container->get('request_stack'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $request = $this->requestStack->getCurrentRequest();
    $currentUrl = $request ? $request->getUri() : '';
    $items = [];
    foreach ($this->emitter->alternates($currentUrl) as $alt) {
      if (($alt['hreflang'] ?? '') === 'x-default' || empty($alt['href'])) {
        continue;
      }
      $items[] = [
        '#type' => 'link',
        '#title' => $alt['hreflang'],
        '#url' => Url::fromUri($alt['href']),
        '#attributes' => [
          'hreflang' => $alt['hreflang'],
          'lang' => $alt['hreflang'],
          'rel' => 'alternate',
        ],
      ];
    }

    if (!$items) {
      return [];
    }

    return [
      '#theme' => 'item_list',
      '#items' => $items,
      '#attributes' => ['class' => ['hrefl-selector'], 'aria-label' => 'Country and language'],
      '#cache' => [
        'contexts' => ['url.path'],
        'tags' => array_filter([$this->emitter->cacheTagForUrl($currentUrl)]),
      ],
    ];
  }

}
