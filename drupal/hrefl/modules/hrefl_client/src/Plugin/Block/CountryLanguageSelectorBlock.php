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
    // Alternates are keyed by the canonical URL; strip any query string so
    // /page?foo=1 resolves the same entry as /page (matching the url.path
    // cache context below).
    $currentUrl = $request ? explode('?', $request->getUri(), 2)[0] : '';
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

    // The empty case carries the same cacheability: without it, an empty
    // render would be cached with no way to invalidate once alternates arrive.
    $build = [
      '#cache' => [
        'contexts' => ['url.path'],
        'tags' => [$this->emitter->cacheTagForUrl($currentUrl) ?? 'hrefl_alternates'],
      ],
    ];
    if ($items) {
      $build += [
        '#theme' => 'item_list',
        '#items' => $items,
        '#attributes' => [
          'class' => ['hrefl-selector'],
          'aria-label' => $this->t('Country and language'),
        ],
      ];
    }
    return $build;
  }

}
