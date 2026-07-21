<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Covers the ingest authorization + validation reject paths (finding F1).
 *
 * These paths return before any DB access, so they are unit-testable with the
 * WP_REST_* stubs in bootstrap.php. The success path (which writes members) is
 * left to the WP integration framework.
 */
final class RestIngestTest extends TestCase {

    private function rest(): Hrefl_Rest {
        // The registry/distributor are not touched on the reject paths, but the
        // constructor is typed, so pass real (DB-idle) instances.
        return new Hrefl_Rest(new Hrefl_Registry(), new Hrefl_Distributor(new Hrefl_Registry()));
    }

    public function testRejectsPayloadMarketNotMatchingSignedHeader(): void {
        // Signed as 'de' but claiming records for 'us' - the mapping-poisoning
        // vector. Must be 403, and must not reach the registry.
        $req = new WP_REST_Request(
            ['market' => 'us', 'records' => [['url' => 'https://x/us/a']]],
            ['x_hrefl_market' => 'de']
        );
        $resp = $this->rest()->ingest($req);
        $this->assertSame(403, $resp->get_status());
    }

    public function testAcceptsMatchingMarketPastTheAuthGate(): void {
        // Matching market with zero records passes auth + the cap; it may then
        // reach the registry, so we only assert it is NOT a 403/413/400 reject.
        $req = new WP_REST_Request(
            ['market' => 'de', 'records' => []],
            ['x_hrefl_market' => 'de']
        );
        $resp = $this->rest()->ingest($req);
        $this->assertNotSame(403, $resp->get_status());
        $this->assertNotSame(413, $resp->get_status());
        $this->assertNotSame(400, $resp->get_status());
    }

    public function testRejectsOversizedBatch(): void {
        $records = array_fill(0, 501, ['url' => 'https://x/de/a']);
        $req = new WP_REST_Request(
            ['market' => 'de', 'records' => $records],
            ['x_hrefl_market' => 'de']
        );
        $resp = $this->rest()->ingest($req);
        $this->assertSame(413, $resp->get_status());
    }

    public function testRejectsInvalidPayload(): void {
        $req = new WP_REST_Request(['market' => ''], ['x_hrefl_market' => 'de']);
        $resp = $this->rest()->ingest($req);
        $this->assertSame(400, $resp->get_status());
    }
}
