<?php

declare(strict_types=1);

namespace Drupal\Tests\hrefl_client\Unit;

use Drupal\hrefl_client\Service\RequestSigner;
use Drupal\Tests\UnitTestCase;

/**
 * Locks the v2 canonical-query form (part of the HMAC signing contract).
 *
 * @coversDefaultClass \Drupal\hrefl_client\Service\RequestSigner
 * @group hrefl_client
 */
final class RequestSignerCanonicalTest extends UnitTestCase {

  /**
   * @covers ::canonicalQuery
   */
  public function testEmptyQueryIsEmptyString(): void {
    $this->assertSame('', RequestSigner::canonicalQuery([]));
  }

  /**
   * @covers ::canonicalQuery
   */
  public function testQueryIsKeySortedSoOrderDoesNotMatter(): void {
    $a = RequestSigner::canonicalQuery(['b' => '2', 'a' => '1', 'c' => '3']);
    $b = RequestSigner::canonicalQuery(['a' => '1', 'c' => '3', 'b' => '2']);
    $this->assertSame($a, $b, 'Parameter order must not change the signature');
    $this->assertSame('a=1&b=2&c=3', $a);
  }

  /**
   * @covers ::canonicalQuery
   */
  public function testValuesAreEncoded(): void {
    // A value with reserved characters must be encoded, not passed raw.
    $out = RequestSigner::canonicalQuery(['url' => 'https://ex.com/a b']);
    $this->assertStringNotContainsString(' ', $out);
    $this->assertStringContainsString('url=', $out);
  }

}
