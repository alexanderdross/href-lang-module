<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The glossary-learning decision: which cross-language slug pairs a confirmation
 * teaches. Pure - the DB write (add_glossary_entry) is covered by integration.
 */
final class GlossaryTest extends TestCase {

    public function testLearnsBothDirectionsForCrossLanguageSibling(): void {
        $member = ['id' => 1, 'lang' => 'en', 'path_key' => 'about-us'];
        $siblings = [
            ['id' => 2, 'lang' => 'de', 'path_key' => 'ueber-uns', 'status' => 'confirmed'],
        ];
        $pairs = Hrefl_Review_Actions::glossary_pairs($member, $siblings);
        $this->assertContains(['en', 'de', 'about-us', 'ueber-uns'], $pairs);
        $this->assertContains(['de', 'en', 'ueber-uns', 'about-us'], $pairs);
        $this->assertCount(2, $pairs);
    }

    public function testSkipsSameLanguageSibling(): void {
        // Two English pages in one group teach nothing about translation.
        $member = ['id' => 1, 'lang' => 'en', 'path_key' => 'about-us'];
        $siblings = [['id' => 2, 'lang' => 'en', 'path_key' => 'about', 'status' => 'confirmed']];
        $this->assertSame([], Hrefl_Review_Actions::glossary_pairs($member, $siblings));
    }

    public function testSkipsIdenticalSlug(): void {
        // Same slug across languages (cookies <-> cookies) adds no new bridge.
        $member = ['id' => 1, 'lang' => 'en', 'path_key' => 'cookies'];
        $siblings = [['id' => 2, 'lang' => 'es', 'path_key' => 'cookies', 'status' => 'confirmed']];
        $this->assertSame([], Hrefl_Review_Actions::glossary_pairs($member, $siblings));
    }

    public function testEmptyMemberSlugOrLangLearnsNothing(): void {
        $this->assertSame([], Hrefl_Review_Actions::glossary_pairs(['lang' => 'en', 'path_key' => ''], [['id' => 2, 'lang' => 'de', 'path_key' => 'x']]));
        $this->assertSame([], Hrefl_Review_Actions::glossary_pairs(['lang' => '', 'path_key' => 'a'], [['id' => 2, 'lang' => 'de', 'path_key' => 'x']]));
    }

    public function testLearnsFromEachCrossLanguageSibling(): void {
        $member = ['id' => 1, 'lang' => 'en', 'path_key' => 'about-us'];
        $siblings = [
            ['id' => 2, 'lang' => 'de', 'path_key' => 'ueber-uns', 'status' => 'confirmed'],
            ['id' => 3, 'lang' => 'fr', 'path_key' => 'a-propos', 'status' => 'confirmed'],
        ];
        $pairs = Hrefl_Review_Actions::glossary_pairs($member, $siblings);
        // Two directions per sibling.
        $this->assertCount(4, $pairs);
    }
}
