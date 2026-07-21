<?php

declare(strict_types=1);

namespace Drupal\hrefl_hub\Service;

/**
 * Applies editor decisions from a review CSV.
 *
 * Shared by the HTTP import endpoint and the admin upload form, so the on-screen
 * upload and the API behave identically. Every `confirm` passes the same
 * ReviewActions guard as the in-app review queue; a row that would break its
 * group is reported as blocked, never half-applied.
 */
final class CsvImporter {

  public function __construct(
    private readonly Registry $registry,
    private readonly ReviewActions $reviewActions,
    private readonly string $actor = 'editor',
  ) {}

  /**
   * Apply a CSV string.
   *
   * @return array
   *   ['applied' => int, 'skipped' => int, 'blocked' => array<string,string[]>].
   */
  public function import(string $csv): array {
    // Parse with fgetcsv() so RFC 4180 quoted fields (titles containing
    // newlines or commas, as fputcsv writes on export) round-trip correctly.
    $stream = fopen('php://temp', 'r+');
    fwrite($stream, $csv);
    rewind($stream);
    $rows = [];
    while (($row = fgetcsv($stream, 0, ',', '"', '\\')) !== FALSE) {
      if ($row !== [NULL]) {
        $rows[] = $row;
      }
    }
    fclose($stream);
    if (!$rows) {
      return ['applied' => 0, 'skipped' => 0, 'blocked' => []];
    }
    $header = array_map('trim', array_shift($rows));

    $applied = 0;
    $skipped = 0;
    $blocked = [];
    foreach ($rows as $row) {
      $data = @array_combine($header, array_pad($row, count($header), ''));
      if (!$data || empty($data['url'])) {
        $skipped++;
        continue;
      }
      $decision = strtolower(trim((string) ($data['decision'] ?? 'leave')));
      $memberId = $this->registry->memberIdForUrl((string) $data['url']);
      if ($memberId === NULL) {
        $skipped++;
        continue;
      }
      if ($decision === 'reject') {
        $this->reviewActions->reject($memberId, $this->actor);
        $applied++;
      }
      elseif ($decision === 'confirm') {
        $violations = $this->reviewActions->confirm($memberId, $this->actor);
        if ($violations) {
          $blocked[(string) $data['url']] = $violations;
        }
        else {
          $applied++;
        }
      }
      else {
        // 'leave' or anything else: no change.
        $skipped++;
      }
    }
    return ['applied' => $applied, 'skipped' => $skipped, 'blocked' => $blocked];
  }

}
