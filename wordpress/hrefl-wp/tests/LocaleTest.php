<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Human-readable selector labels. Deterministic for the curated languages,
 * regardless of the intl extension. Standalone (no WordPress).
 */
final class LocaleTest extends TestCase {

    public function testLanguageOnly(): void {
        $this->assertSame('English', Hrefl_Locale::label('en'));
        $this->assertSame('Deutsch', Hrefl_Locale::label('de'));
        $this->assertSame('Français', Hrefl_Locale::label('fr'));
    }

    public function testLanguageWithRegion(): void {
        $this->assertSame('English (United States)', Hrefl_Locale::label('en-US'));
        $this->assertSame('Français (Canada)', Hrefl_Locale::label('fr-CA'));
    }

    public function testXDefaultIsInternational(): void {
        $this->assertSame('International', Hrefl_Locale::label('x-default'));
    }

    public function testScriptSubtagIsIgnoredForTheLabel(): void {
        // zh-Hant-TW: language + region, the script subtag does not break it.
        $this->assertSame('中文 (Taiwan)', Hrefl_Locale::label('zh-Hant-TW'));
    }

    public function testM49Region(): void {
        $this->assertSame('Español (Latin America)', Hrefl_Locale::label('es-419'));
    }

    public function testUnknownLanguageFallsBackToCode(): void {
        // No intl endonym in the curated map and (in CI) no intl data - the
        // fallback is the uppercased code, never an empty string.
        $label = Hrefl_Locale::label('xx-YY');
        $this->assertNotSame('', $label);
        $this->assertStringContainsString('XX', $label);
    }
}
