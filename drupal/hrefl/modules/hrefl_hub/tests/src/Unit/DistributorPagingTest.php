<?php

declare(strict_types=1);

namespace Drupal\Tests\hrefl_hub\Unit;

use Drupal\hrefl_hub\Service\Distributor;
use Drupal\Tests\UnitTestCase;

/**
 * The serve cursor decision: a full batch pages on, a short/empty batch ends.
 * Guards the pagination that keeps a large market off a single response.
 *
 * @coversDefaultClass \Drupal\hrefl_hub\Service\Distributor
 * @group hrefl_hub
 */
final class DistributorPagingTest extends UnitTestCase {

  /**
   * @covers ::nextCursor
   */
  public function testFullBatchPagesFromLastId(): void {
    // Scanned exactly the limit -> more may follow -> cursor is the last id.
    $this->assertSame(4200, Distributor::nextCursor(500, 500, 4200));
  }

  /**
   * @covers ::nextCursor
   */
  public function testShortBatchEndsPaging(): void {
    // Fewer than the limit -> this was the last page.
    $this->assertNull(Distributor::nextCursor(37, 500, 4200));
  }

  /**
   * @covers ::nextCursor
   */
  public function testEmptyBatchEndsPaging(): void {
    $this->assertNull(Distributor::nextCursor(0, 500, NULL));
  }

  /**
   * @covers ::nextCursor
   */
  public function testFullBatchButNoIdSeenEndsPaging(): void {
    // Defensive: a full count with no id can't advance a cursor safely.
    $this->assertNull(Distributor::nextCursor(500, 500, NULL));
  }

}
