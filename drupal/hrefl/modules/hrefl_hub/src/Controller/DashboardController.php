<?php

declare(strict_types=1);

namespace Drupal\hrefl_hub\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\hrefl_hub\Service\Monitor;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Health dashboard: coverage KPIs and the graph-validation issue lists.
 */
final class DashboardController extends ControllerBase {

  public function __construct(
    private readonly Monitor $monitor,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('hrefl_hub.monitor'));
  }

  /**
   * The dashboard page.
   */
  public function overview(): array {
    $report = $this->monitor->report();
    $t = $report['totals'];
    $issues = $report['issues'];

    $build = [];
    $build['#cache']['max-age'] = 0;

    $build['summary'] = [
      '#theme' => 'item_list',
      '#title' => $this->t('Coverage: @pct%', ['@pct' => round($report['coverage'] * 100, 1)]),
      '#items' => [
        $this->t('Groups: @n', ['@n' => $t['groups']]),
        $this->t('Members: @n (confirmed @c, proposed @p, held @h, rejected @r)', [
          '@n' => $t['members'],
          '@c' => $t['confirmed'],
          '@p' => $t['proposed'],
          '@h' => $t['held'],
          '@r' => $t['rejected'],
        ]),
        $this->t('Stale validations: @n', ['@n' => $issues['stale_validation']]),
        $report['healthy']
          ? $this->t('✔ No structural issues detected.')
          : $this->t('⚠ Issues need attention (below).'),
      ],
    ];

    $build['review_link'] = [
      '#type' => 'link',
      '#title' => $this->t('Go to the review queue'),
      '#url' => Url::fromRoute('hrefl_hub.review'),
      '#attributes' => ['class' => ['button']],
    ];

    $build['invalid'] = $this->issueTable(
      $this->t('Confirmed members with an invalid target (dropped from serving until they pass 200 / canonical / index)'),
      [$this->t('Market'), $this->t('hreflang'), $this->t('URL')],
      array_map(static fn($i) => [$i['market'], $i['hreflang'], $i['url']], $issues['invalid_targets']),
    );

    $build['collisions'] = $this->issueTable(
      $this->t('hreflang code collisions within a confirmed group'),
      [$this->t('Group'), $this->t('hreflang'), $this->t('URLs')],
      array_map(static fn($i) => [substr($i['group_uuid'], 0, 8), $i['hreflang'], implode(', ', $i['urls'])], $issues['code_collisions']),
    );

    $build['missing_x_default'] = $this->issueTable(
      $this->t('Confirmed groups with no x-default (no Global member)'),
      [$this->t('Group')],
      array_map(static fn($g) => [substr($g, 0, 8)], $issues['missing_x_default']),
    );

    $build['lonely'] = $this->issueTable(
      $this->t('Confirmed members with no sibling to link to'),
      [$this->t('Group'), $this->t('URL')],
      array_map(static fn($i) => [substr($i['group_uuid'], 0, 8), $i['url']], $issues['lonely_confirmed']),
    );

    return $build;
  }

  /**
   * A titled table that renders an "all clear" note when empty.
   */
  private function issueTable($title, array $header, array $rows): array {
    return [
      '#type' => 'table',
      '#caption' => $title,
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $this->t('None.'),
    ];
  }

}
