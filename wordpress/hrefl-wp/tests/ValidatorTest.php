<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Hrefl_Validator (hreflang correctness rules).
 * Standalone — no WordPress required.
 */
final class ValidatorTest extends TestCase {

    public function testNormalizeCode(): void {
        $this->assertSame('de', Hrefl_Validator::normalize_code('DE'));
        $this->assertSame('en-US', Hrefl_Validator::normalize_code('en-us'));
        $this->assertSame('fr-CA', Hrefl_Validator::normalize_code('Fr-cA'));
        $this->assertSame('en-GB', Hrefl_Validator::normalize_code('en_GB'));
        $this->assertSame('x-default', Hrefl_Validator::normalize_code('X-Default'));
    }

    public function testIsValidCode(): void {
        $this->assertTrue(Hrefl_Validator::is_valid_code('en'));
        $this->assertTrue(Hrefl_Validator::is_valid_code('en-US'));
        $this->assertTrue(Hrefl_Validator::is_valid_code('x-default'));
        $this->assertFalse(Hrefl_Validator::is_valid_code('US'));
        $this->assertFalse(Hrefl_Validator::is_valid_code('english'));
    }

    public function testIsAbsolute(): void {
        $this->assertTrue(Hrefl_Validator::is_absolute('https://ex.com/de/x'));
        $this->assertFalse(Hrefl_Validator::is_absolute('/de/x'));
        $this->assertFalse(Hrefl_Validator::is_absolute('//ex.com/de'));
        $this->assertFalse(Hrefl_Validator::is_absolute('ftp://ex.com/x'));
    }

    public function testCleanNormalizesDedupesAndDropsInvalid(): void {
        $cleaned = Hrefl_Validator::clean([
            ['hreflang' => 'DE', 'href' => 'https://ex.com/de/ueber-uns'],
            ['hreflang' => 'en-us', 'href' => 'https://ex.com/us/about-us'],
            ['hreflang' => 'de', 'href' => 'https://ex.com/dup'],        // dup code -> dropped
            ['hreflang' => 'bogus', 'href' => 'https://ex.com/x'],       // invalid -> dropped
            ['hreflang' => 'fr', 'href' => '/relative'],                 // not absolute -> dropped
            ['hreflang' => 'x-default', 'href' => 'https://ex.com/about-us'],
            ['hreflang' => 'x-default', 'href' => 'https://ex.com/second'], // 2nd x-default -> dropped
        ]);
        $this->assertSame([
            ['hreflang' => 'de', 'href' => 'https://ex.com/de/ueber-uns'],
            ['hreflang' => 'en-US', 'href' => 'https://ex.com/us/about-us'],
            ['hreflang' => 'x-default', 'href' => 'https://ex.com/about-us'],
        ], $cleaned);
    }
}
