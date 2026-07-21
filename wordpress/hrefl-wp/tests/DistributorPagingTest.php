<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The serve cursor decision: a full batch pages on, a short/empty batch ends.
 * Guards the pagination that keeps a large market off a single response.
 */
final class DistributorPagingTest extends TestCase {

    public function testFullBatchPagesFromLastId(): void {
        // Scanned exactly the limit -> more may follow -> cursor is the last id.
        $this->assertSame(4200, Hrefl_Distributor::next_cursor(500, 500, 4200));
    }

    public function testShortBatchEndsPaging(): void {
        // Fewer than the limit -> this was the last page.
        $this->assertNull(Hrefl_Distributor::next_cursor(37, 500, 4200));
    }

    public function testEmptyBatchEndsPaging(): void {
        $this->assertNull(Hrefl_Distributor::next_cursor(0, 500, null));
    }

    public function testFullBatchButNoIdSeenEndsPaging(): void {
        // Defensive: a full count with no id can't advance a cursor safely.
        $this->assertNull(Hrefl_Distributor::next_cursor(500, 500, null));
    }
}
