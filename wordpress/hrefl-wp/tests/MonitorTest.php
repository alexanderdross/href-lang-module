<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Pure-logic tests for the health-graph analysis: invalid targets, in-group
 * hreflang collisions, groups with no Global x-default, and lonely confirmed
 * members. The DB-backed counts/coverage are covered by integration tests.
 */
final class MonitorTest extends TestCase {

    /** A healthy two-member group: global + de, distinct codes, both valid. */
    public function testHealthyGroupHasNoIssues(): void {
        $r = Hrefl_Monitor::analyze([
            ['group_id' => 'g1', 'market' => 'global', 'hreflang' => 'en', 'url' => 'https://x.test/a', 'valid' => 1],
            ['group_id' => 'g1', 'market' => 'de',     'hreflang' => 'de', 'url' => 'https://x.test/de/a', 'valid' => 1],
        ]);
        $this->assertSame([], $r['invalid_targets']);
        $this->assertSame([], $r['code_collisions']);
        $this->assertSame([], $r['missing_x_default']);
        $this->assertSame([], $r['lonely_confirmed']);
    }

    public function testFlagsInvalidTarget(): void {
        $r = Hrefl_Monitor::analyze([
            ['group_id' => 'g1', 'market' => 'global', 'hreflang' => 'en', 'url' => 'https://x.test/a', 'valid' => 1],
            ['group_id' => 'g1', 'market' => 'de',     'hreflang' => 'de', 'url' => 'https://x.test/de/a', 'valid' => 0],
        ]);
        $this->assertCount(1, $r['invalid_targets']);
        $this->assertSame('https://x.test/de/a', $r['invalid_targets'][0]['url']);
    }

    public function testFlagsCodeCollision(): void {
        $r = Hrefl_Monitor::analyze([
            ['group_id' => 'g1', 'market' => 'global', 'hreflang' => 'en', 'url' => 'https://x.test/a', 'valid' => 1],
            ['group_id' => 'g1', 'market' => 'us',     'hreflang' => 'en', 'url' => 'https://x.test/us/a', 'valid' => 1],
        ]);
        $this->assertCount(1, $r['code_collisions']);
        $this->assertSame('en', $r['code_collisions'][0]['hreflang']);
        $this->assertCount(2, $r['code_collisions'][0]['urls']);
    }

    public function testFlagsMissingXDefaultWhenNoGlobalMember(): void {
        $r = Hrefl_Monitor::analyze([
            ['group_id' => 'g1', 'market' => 'de', 'hreflang' => 'de', 'url' => 'https://x.test/de/a', 'valid' => 1],
            ['group_id' => 'g1', 'market' => 'us', 'hreflang' => 'en', 'url' => 'https://x.test/us/a', 'valid' => 1],
        ]);
        $this->assertSame(['g1'], $r['missing_x_default']);
    }

    public function testFlagsLonelyConfirmedMember(): void {
        $r = Hrefl_Monitor::analyze([
            ['group_id' => 'g9', 'market' => 'de', 'hreflang' => 'de', 'url' => 'https://x.test/de/only', 'valid' => 1],
        ]);
        $this->assertCount(1, $r['lonely_confirmed']);
        // A single-member group is lonely, not "missing x-default".
        $this->assertSame([], $r['missing_x_default']);
    }
}
