<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Regression test for the HMAC canonical string (signing protocol v2).
 *
 * The canonical format is a contract: the client signer and the hub verifier
 * must produce identical bytes, or every request fails. This locks the v2
 * format (which now includes the query string) so it cannot drift.
 * Standalone - no WordPress required.
 */
final class SignerTest extends TestCase {

    public function testCanonicalFormatIsStable(): void {
        $canonical = Hrefl_Signer::canonical('post', '/wp-json/hrefl/v1/inventory', '', 1000, '{"a":1}');
        $expected = implode("\n", [
            'POST',
            '/wp-json/hrefl/v1/inventory',
            '',
            '1000',
            hash('sha256', '{"a":1}'),
        ]);
        $this->assertSame($expected, $canonical);
    }

    public function testQueryIsCanonicalisedKeySorted(): void {
        // Different parameter order must produce the same canonical query.
        $a = Hrefl_Signer::canonical_query(['b' => '2', 'a' => '1']);
        $b = Hrefl_Signer::canonical_query(['a' => '1', 'b' => '2']);
        $this->assertSame($a, $b);
        $this->assertSame('a=1&b=2', $a);
        $this->assertSame('', Hrefl_Signer::canonical_query([]));
    }

    public function testQueryIsPartOfTheSignature(): void {
        $base = Hrefl_Signer::canonical('GET', '/p', '', 5, '');
        $withQuery = Hrefl_Signer::canonical('GET', '/p', 'market=de', 5, '');
        $this->assertNotSame($base, $withQuery, 'The query string is signed');
    }

    public function testSignatureIsDeterministicAndSecretDependent(): void {
        $a = hash_hmac('sha256', Hrefl_Signer::canonical('GET', '/p', '', 5, ''), 'secret-1');
        $b = hash_hmac('sha256', Hrefl_Signer::canonical('GET', '/p', '', 5, ''), 'secret-1');
        $c = hash_hmac('sha256', Hrefl_Signer::canonical('GET', '/p', '', 5, ''), 'secret-2');
        $this->assertSame($a, $b, 'Same input + secret is deterministic');
        $this->assertNotSame($a, $c, 'A different secret yields a different signature');
    }

    public function testBodyMethodPathChangeTheCanonical(): void {
        $base = Hrefl_Signer::canonical('GET', '/p', '', 5, '');
        $this->assertNotSame($base, Hrefl_Signer::canonical('POST', '/p', '', 5, ''));
        $this->assertNotSame($base, Hrefl_Signer::canonical('GET', '/p', '', 5, 'x'));
        $this->assertNotSame($base, Hrefl_Signer::canonical('GET', '/other', '', 5, ''));
    }
}
