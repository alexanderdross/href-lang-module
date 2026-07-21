<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Pure-logic tests for the Tier B/C engine: cosine similarity, the LLM answer
 * parser, and embedding-response extraction. Standalone (no WordPress, no DB,
 * no network). Mirrors the Drupal CosineTest + parse contracts.
 */
final class AiEngineTest extends TestCase {

    /**
     * @dataProvider cosineProvider
     */
    public function testCosine(array $a, array $b, float $expected): void {
        $this->assertEqualsWithDelta($expected, Hrefl_Vector_Store::cosine($a, $b), 1e-9);
    }

    public static function cosineProvider(): array {
        return [
            'identical'   => [[1.0, 2.0, 3.0], [1.0, 2.0, 3.0], 1.0],
            'orthogonal'  => [[1.0, 0.0], [0.0, 1.0], 0.0],
            'opposite'    => [[1.0, 0.0], [-1.0, 0.0], -1.0],
            '45 degrees'  => [[1.0, 0.0], [1.0, 1.0], 1 / sqrt(2)],
            'zero vector' => [[0.0, 0.0], [1.0, 1.0], 0.0],
            'dim mismatch' => [[1.0, 2.0], [1.0], 0.0],
        ];
    }

    public function testParseAnswerValidChoice(): void {
        $v = Hrefl_Ai_Matcher::parse_answer('{"choice": 1, "confidence": 0.9, "rationale": "same page"}', 3);
        $this->assertSame(1, $v['choice']);
        $this->assertSame(0.9, $v['confidence']);
    }

    public function testParseAnswerRejectsOutOfRangeChoice(): void {
        // choice 5 with only 3 candidates -> null (never invent a match).
        $v = Hrefl_Ai_Matcher::parse_answer('{"choice": 5, "confidence": 0.9}', 3);
        $this->assertNull($v['choice']);
    }

    public function testParseAnswerNullChoiceAndClampedConfidence(): void {
        $v = Hrefl_Ai_Matcher::parse_answer('{"choice": null, "confidence": 2.5}', 3);
        $this->assertNull($v['choice']);
        $this->assertSame(1.0, $v['confidence']);
    }

    public function testParseAnswerHandlesProseWrappedJson(): void {
        $v = Hrefl_Ai_Matcher::parse_answer('Sure! {"choice": 0, "confidence": 0.7} hope that helps', 2);
        $this->assertSame(0, $v['choice']);
    }

    public function testParseAnswerUnparseableIsNoDecision(): void {
        $v = Hrefl_Ai_Matcher::parse_answer('not json at all', 3);
        $this->assertNull($v['choice']);
        $this->assertSame(0.0, $v['confidence']);
    }

    public function testExtractVectorsPreservesOrderAndCount(): void {
        $payload = ['data' => [['embedding' => [0.1, 0.2]], ['embedding' => [0.3, 0.4]]]];
        $vectors = Hrefl_Embedding_Matcher::extract_vectors($payload, 2);
        $this->assertSame([[0.1, 0.2], [0.3, 0.4]], $vectors);
    }

    public function testExtractVectorsCountMismatchReturnsEmpty(): void {
        $payload = ['data' => [['embedding' => [0.1]]]];
        $this->assertSame([], Hrefl_Embedding_Matcher::extract_vectors($payload, 2));
    }
}
