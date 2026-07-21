<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Covers the fail-closed, per-market secret resolution (assessment finding F2).
 *
 * Standalone: uses the option stub from bootstrap.php (no WordPress, no DB,
 * and no HREFL_HUB_SECRET constant defined in this suite).
 */
final class MarketsSecretTest extends TestCase {

    protected function setUp(): void {
        $GLOBALS['hrefl_test_options']['hrefl_settings'] = [
            'market'  => 'global',
            'secret'  => 'SHARED',
            'markets' => [
                'de' => ['prefix' => 'https://example.test/de/', 'secret' => 'DE-SECRET'],
                'us' => ['prefix' => 'https://example.test/us/'],
            ],
        ];
    }

    public function testUnknownAndEmptyMarketGetNoSecret(): void {
        $this->assertSame('', Hrefl_Markets::secret_for(''));
        $this->assertSame('', Hrefl_Markets::secret_for('zz'), 'Unconfigured market fails closed');
    }

    public function testOwnMarketUsesTheLocalSecret(): void {
        $this->assertSame('SHARED', Hrefl_Markets::secret_for('global'));
    }

    public function testPerMarketSecretWinsOverShared(): void {
        $this->assertSame('DE-SECRET', Hrefl_Markets::secret_for('de'));
    }

    public function testConfiguredMarketWithoutOwnSecretFallsBackToShared(): void {
        $this->assertSame('SHARED', Hrefl_Markets::secret_for('us'));
    }
}
