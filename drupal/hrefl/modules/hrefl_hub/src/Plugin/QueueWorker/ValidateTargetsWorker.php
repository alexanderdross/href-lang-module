<?php

declare(strict_types=1);

namespace Drupal\hrefl_hub\Plugin\QueueWorker;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\hrefl_hub\Service\Registry;
use Drupal\hrefl_hub\Service\TargetValidator;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Runs the SSRF-safe target check and writes the member's valid flag.
 *
 * Off the request path (queue), rate-limitable, and idempotent: re-running a
 * member simply re-stamps its validity.
 */
#[QueueWorker(
  id: 'hrefl_hub_validate_targets',
  title: new TranslatableMarkup('Hreflang Hub: validate alternate targets'),
  cron: ['time' => 30],
)]
final class ValidateTargetsWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    string $plugin_id,
    mixed $plugin_definition,
    private readonly TargetValidator $targetValidator,
    private readonly Registry $registry,
    private readonly TimeInterface $time,
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
      $container->get('hrefl_hub.target_validator'),
      $container->get('hrefl_hub.registry'),
      $container->get('datetime.time'),
    );
  }

  /**
   * {@inheritdoc}
   *
   * @param mixed $data
   *   Queue item: ['member_id' => int, 'url' => string].
   */
  public function processItem($data): void {
    $memberId = (int) ($data['member_id'] ?? 0);
    $url = (string) ($data['url'] ?? '');
    if ($memberId === 0 || $url === '') {
      return;
    }
    $valid = $this->targetValidator->validate($url);
    $this->registry->setValid($memberId, $valid, $this->time->getRequestTime());
  }

}
