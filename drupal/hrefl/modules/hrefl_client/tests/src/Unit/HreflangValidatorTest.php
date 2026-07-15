<?php

declare(strict_types=1);

namespace Drupal\Tests\hrefl_client\Unit;

use Drupal\hrefl_client\Service\HreflangValidator;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\hrefl_client\Service\HreflangValidator
 * @group hrefl_client
 */
final class HreflangValidatorTest extends UnitTestCase {

  private HreflangValidator $validator;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->validator = new HreflangValidator();
  }

  /**
   * @covers ::normalizeCode
   * @dataProvider normalizeCodeProvider
   */
  public function testNormalizeCode(string $input, string $expected): void {
    $this->assertSame($expected, $this->validator->normalizeCode($input));
  }

  public static function normalizeCodeProvider(): array {
    return [
      'lowercase language' => ['DE', 'de'],
      'region uppercased' => ['en-us', 'en-US'],
      'mixed case' => ['Fr-cA', 'fr-CA'],
      'underscore separator' => ['en_GB', 'en-GB'],
      'x-default preserved' => ['X-Default', 'x-default'],
      'whitespace trimmed' => ['  de-DE  ', 'de-DE'],
    ];
  }

  /**
   * @covers ::isValidCode
   */
  public function testIsValidCode(): void {
    $this->assertTrue($this->validator->isValidCode('en'));
    $this->assertTrue($this->validator->isValidCode('en-US'));
    $this->assertTrue($this->validator->isValidCode('fr-CA'));
    $this->assertTrue($this->validator->isValidCode('x-default'));
    // Region-only or malformed codes are rejected.
    $this->assertFalse($this->validator->isValidCode('US'));
    $this->assertFalse($this->validator->isValidCode('english'));
    $this->assertFalse($this->validator->isValidCode('en-usa'));
    $this->assertFalse($this->validator->isValidCode(''));
  }

  /**
   * @covers ::isAbsolute
   */
  public function testIsAbsolute(): void {
    $this->assertTrue($this->validator->isAbsolute('https://example.com/de/ueber-uns'));
    $this->assertTrue($this->validator->isAbsolute('http://example.com/'));
    $this->assertFalse($this->validator->isAbsolute('/de/ueber-uns'));
    $this->assertFalse($this->validator->isAbsolute('//example.com/de'));
    $this->assertFalse($this->validator->isAbsolute('ftp://example.com/x'));
  }

  /**
   * @covers ::clean
   */
  public function testCleanNormalizesAndKeepsValidEntries(): void {
    $cleaned = $this->validator->clean([
      ['hreflang' => 'DE', 'href' => 'https://ex.com/de/ueber-uns'],
      ['hreflang' => 'en-us', 'href' => 'https://ex.com/us/about-us'],
    ]);
    $this->assertSame([
      ['hreflang' => 'de', 'href' => 'https://ex.com/de/ueber-uns'],
      ['hreflang' => 'en-US', 'href' => 'https://ex.com/us/about-us'],
    ], $cleaned);
  }

  /**
   * @covers ::clean
   */
  public function testCleanDropsRelativeAndInvalid(): void {
    $cleaned = $this->validator->clean([
      ['hreflang' => 'de', 'href' => '/de/ueber-uns'],
      ['hreflang' => 'bogus-code', 'href' => 'https://ex.com/x'],
      ['hreflang' => '', 'href' => 'https://ex.com/y'],
      ['hreflang' => 'en', 'href' => ''],
      ['hreflang' => 'fr-CA', 'href' => 'https://ex.com/ca/fr/a-propos'],
    ]);
    $this->assertSame([
      ['hreflang' => 'fr-CA', 'href' => 'https://ex.com/ca/fr/a-propos'],
    ], $cleaned);
  }

  /**
   * @covers ::clean
   */
  public function testCleanDeduplicatesCodesAndSingleXDefault(): void {
    $cleaned = $this->validator->clean([
      ['hreflang' => 'x-default', 'href' => 'https://ex.com/about-us'],
      ['hreflang' => 'en', 'href' => 'https://ex.com/about-us'],
      // Duplicate code — dropped.
      ['hreflang' => 'EN', 'href' => 'https://ex.com/other'],
      // Second x-default — dropped.
      ['hreflang' => 'x-default', 'href' => 'https://ex.com/second'],
    ]);
    $this->assertSame([
      ['hreflang' => 'x-default', 'href' => 'https://ex.com/about-us'],
      ['hreflang' => 'en', 'href' => 'https://ex.com/about-us'],
    ], $cleaned);
  }

}
