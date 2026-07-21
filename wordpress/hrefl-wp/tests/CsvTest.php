<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Pure-logic tests for the CSV review loop: formula-injection neutralization on
 * export and RFC 4180 round-tripping on import. The apply path (confirm/reject
 * guard) is covered by Hrefl_Review_Actions via the DB-backed integration tests.
 */
final class CsvTest extends TestCase {

    /**
     * @dataProvider sanitizeProvider
     */
    public function testSanitizeNeutralizesFormulaTriggers(string $in, string $out): void {
        $this->assertSame($out, Hrefl_Csv::sanitize($in));
    }

    public static function sanitizeProvider(): array {
        return [
            'equals'      => ['=1+1', "'=1+1"],
            'plus'        => ['+ping', "'+ping"],
            'minus'       => ['-2', "'-2"],
            'at'          => ['@SUM(A1)', "'@SUM(A1)"],
            'plain title' => ['About us', 'About us'],
            'empty'       => ['', ''],
            'inner equals'=> ['a=b', 'a=b'],
        ];
    }

    public function testParseRoundTripsQuotedFields(): void {
        // A title with a comma and a quote must survive fputcsv -> parse.
        $csv = "decision,url,title\r\n"
             . 'confirm,https://x.test/a,"Hello, ""world"""' . "\r\n";
        $rows = Hrefl_Csv::parse($csv);
        $this->assertCount(2, $rows);
        $this->assertSame(['decision', 'url', 'title'], $rows[0]);
        $this->assertSame('confirm', $rows[1][0]);
        $this->assertSame('https://x.test/a', $rows[1][1]);
        $this->assertSame('Hello, "world"', $rows[1][2]);
    }

    public function testColumnsHaveDecisionSecond(): void {
        // The editor edits the `decision` column; keep it stable and early.
        $this->assertSame('group_id', Hrefl_Csv::COLUMNS[0]);
        $this->assertSame('decision', Hrefl_Csv::COLUMNS[1]);
        $this->assertContains('url', Hrefl_Csv::COLUMNS);
    }
}
