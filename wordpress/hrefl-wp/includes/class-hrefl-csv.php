<?php
/**
 * CSV review loop: export the mapping for editors, accept their decisions back.
 *
 * The CSV is an interchange format on top of the registry, not the live store.
 * Cells are neutralized against spreadsheet formula injection on the way out;
 * imports round-trip RFC 4180 quoted fields (titles with commas / newlines).
 * Every `confirm` passes the same Hrefl_Review_Actions guard as the in-app
 * queue, so the on-screen and CSV paths behave identically. Mirrors the Drupal
 * CsvController + CsvImporter.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class Hrefl_Csv {

    /** @var string[] The export/import column order. */
    public const COLUMNS = [
        'group_id',
        'decision',
        'status',
        'market',
        'lang',
        'hreflang',
        'url',
        'title',
        'is_x_default',
        'valid',
        'notes',
    ];

    public function __construct(
        private Hrefl_Registry $registry,
        private Hrefl_Review_Actions $actions
    ) {}

    /**
     * Build the review CSV for every member. `decision` defaults to `leave`.
     */
    public function export(): string {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, self::COLUMNS, ',', '"', '\\');
        foreach ($this->registry->all_members() as $m) {
            $row = [
                (string) ($m['group_id'] ?? ''),
                'leave',
                (string) ($m['status'] ?? ''),
                (string) ($m['market'] ?? ''),
                (string) ($m['lang'] ?? ''),
                (string) ($m['hreflang'] ?? ''),
                (string) ($m['url'] ?? ''),
                (string) ($m['title'] ?? ''),
                // Convention: the Global member is the group's x-default.
                ($m['market'] ?? '') === 'global' ? 'yes' : '',
                (string) ((int) ($m['valid'] ?? 0)),
                '',
            ];
            fputcsv($handle, array_map([self::class, 'sanitize'], $row), ',', '"', '\\');
        }
        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);
        return $csv;
    }

    /**
     * Apply editor decisions from a CSV string.
     *
     * @return array{applied:int,skipped:int,blocked:array<string,string[]>}
     */
    public function import(string $csv): array {
        $rows = self::parse($csv);
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
            $id = $this->registry->member_id_for_url((string) $data['url']);
            if ($id === null) {
                $skipped++;
                continue;
            }
            if ($decision === 'reject') {
                $this->actions->reject($id);
                $applied++;
            } elseif ($decision === 'confirm') {
                $violations = $this->actions->confirm($id);
                if ($violations) {
                    $blocked[(string) $data['url']] = $violations;
                } else {
                    $applied++;
                }
            } else {
                // 'leave' or anything else: no change.
                $skipped++;
            }
        }
        return ['applied' => $applied, 'skipped' => $skipped, 'blocked' => $blocked];
    }

    /**
     * Parse a CSV string into rows (header included). Public for testability.
     *
     * @return array<int,array<int,string>>
     */
    public static function parse(string $csv): array {
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $csv);
        rewind($stream);
        $rows = [];
        while (($row = fgetcsv($stream, 0, ',', '"', '\\')) !== false) {
            if ($row !== [null]) {
                $rows[] = array_map(static fn ($c): string => (string) $c, $row);
            }
        }
        fclose($stream);
        return $rows;
    }

    /**
     * Neutralize spreadsheet formula injection on export. Public for testability.
     */
    public static function sanitize(string $value): string {
        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@'], true)) {
            return "'" . $value;
        }
        return $value;
    }
}
