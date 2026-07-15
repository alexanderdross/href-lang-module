<?php

declare(strict_types=1);

namespace Drupal\hrefl_hub\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\hrefl_hub\Service\CsvImporter;
use Drupal\hrefl_hub\Service\Registry;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * CSV review loop: export the mapping, accept editor decisions back.
 *
 * The CSV is an interchange format on top of the registry, not the live store.
 * Cells are sanitized against formula injection on the way out.
 */
final class CsvController extends ControllerBase {

  private const COLUMNS = [
    'group_uuid', 'decision', 'status', 'market', 'language', 'hreflang',
    'url', 'title', 'is_x_default', 'matched_by', 'confidence', 'valid',
    'translated_title', 'translated_slug', 'notes',
  ];

  public function __construct(
    private readonly Registry $registry,
    private readonly CsvImporter $importer,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('hrefl_hub.registry'),
      $container->get('hrefl_hub.csv_importer'),
    );
  }

  /**
   * GET /hrefl-hub/api/v1/export.csv.
   */
  public function export(): Response {
    $handle = fopen('php://temp', 'r+');
    fputcsv($handle, self::COLUMNS, ',', '"', '\\');
    foreach ($this->registry->allMembers() as $m) {
      $signals = $this->decodeSignals($m['signals'] ?? NULL);
      $row = [
        $m['group_uuid'],
        'leave',
        $m['status'],
        $m['market'],
        $m['language'],
        $m['hreflang'],
        $m['url'],
        (string) ($m['title'] ?? ''),
        // Convention: the Global member is the group's x-default (see Distributor).
        $m['market'] === 'global' ? 'yes' : '',
        $m['matched_by'],
        (string) $m['confidence'],
        (string) $m['valid'],
        // AI-proposed translation, surfaced so editors can review it.
        (string) ($signals['translated_title'] ?? ''),
        (string) ($signals['translated_slug'] ?? ''),
        '',
      ];
      fputcsv($handle, array_map($this->sanitize(...), $row), ',', '"', '\\');
    }
    rewind($handle);
    $csv = stream_get_contents($handle);
    fclose($handle);

    return new Response($csv, 200, [
      'Content-Type' => 'text/csv; charset=utf-8',
      'Content-Disposition' => 'attachment; filename="hrefl-mapping.csv"',
    ]);
  }

  /**
   * POST /hrefl-hub/api/v1/import.csv.
   */
  public function import(Request $request): JsonResponse {
    $body = (string) $request->getContent();
    if (trim($body) === '') {
      return new JsonResponse(['error' => 'empty CSV'], 400);
    }
    return new JsonResponse($this->importer->import($body));
  }

  /**
   * Neutralize spreadsheet formula injection on export.
   */
  private function sanitize(string $value): string {
    if ($value !== '' && in_array($value[0], ['=', '+', '-', '@'], TRUE)) {
      return "'" . $value;
    }
    return $value;
  }

  /**
   * Decode a member's stored signals JSON to an array.
   */
  private function decodeSignals(?string $json): array {
    if ($json === NULL || $json === '') {
      return [];
    }
    $decoded = json_decode($json, TRUE);
    return is_array($decoded) ? $decoded : [];
  }

}
