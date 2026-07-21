<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Guards the safe default: the human-in-the-loop is on unless an operator
 * explicitly opts out. `auto_confirm` must default to falsy (unset/empty), and
 * only an explicit truthy value flips it.
 */
final class AutoConfirmTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['hrefl_test_options'] = [];
    }

    public function testAutoConfirmDefaultsOff(): void {
        // No stored option -> the default wins -> human review required.
        $this->assertEmpty(Hrefl_Settings::get('auto_confirm'));
        $this->assertFalse((bool) Hrefl_Settings::get('auto_confirm'));
    }

    public function testAutoConfirmOnlyWhenExplicitlySet(): void {
        $GLOBALS['hrefl_test_options']['hrefl_settings'] = ['auto_confirm' => 1];
        $this->assertTrue((bool) Hrefl_Settings::get('auto_confirm'));
    }
}
