<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the leaf-slug extraction used by URL-pattern matching.
 * Standalone - no WordPress required.
 */
final class SlugTest extends TestCase {

    public function testSlug(): void {
        $this->assertSame('about-us', Hrefl_Registry::slug('https://ex.com/about-us'));
        $this->assertSame('about-us', Hrefl_Registry::slug('https://ex.com/us/about-us'));
        $this->assertSame('ueber-uns', Hrefl_Registry::slug('https://ex.com/de/ueber-uns'));
        $this->assertSame('a-propos', Hrefl_Registry::slug('https://ex.com/ca/fr/a-propos/'));
        $this->assertSame('about-us', Hrefl_Registry::slug('https://ex.com/US/About-Us'));
        $this->assertSame('', Hrefl_Registry::slug('https://ex.com/'));
    }
}
