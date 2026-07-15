<?php

declare(strict_types=1);

namespace Drupal\Tests\hrefl_hub\Unit;

use Drupal\hrefl_hub\Plugin\HreflAiMatcher\AiMatcherBase;
use Drupal\Tests\UnitTestCase;

/**
 * Covers the provider-neutral translation parsing + slug sanitization in
 * AiMatcherBase (pure string logic, no injected services).
 *
 * @group hrefl_hub
 */
final class TranslationParseTest extends UnitTestCase {

  private AiMatcherBaseTestProxy $proxy;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->proxy = new AiMatcherBaseTestProxy();
  }

  /**
   * @dataProvider slugProvider
   */
  public function testSanitizeSlug(string $input, string $expected): void {
    $this->assertSame($expected, $this->proxy->sanitize($input));
  }

  public static function slugProvider(): array {
    return [
      'spaces to hyphens' => ['Über Uns', 'über-uns'],
      'underscores to hyphens' => ['about_us', 'about-us'],
      'collapse repeats' => ['a--b   c', 'a-b-c'],
      'strip punctuation' => ['hello, world!', 'hello-world'],
      'trim hyphens' => ['-edge-', 'edge'],
      'lowercased' => ['About-US', 'about-us'],
    ];
  }

  /**
   * @dataProvider translationProvider
   */
  public function testParseTranslation(string $raw, array $expected): void {
    $this->assertSame($expected, $this->proxy->parse($raw));
  }

  public static function translationProvider(): array {
    return [
      'clean json' => [
        '{"title": "Über uns", "slug": "ueber-uns"}',
        ['title' => 'Über uns', 'slug' => 'ueber-uns'],
      ],
      'json in prose' => [
        'Here is the result: {"title":"À propos","slug":"a propos"} done.',
        ['title' => 'À propos', 'slug' => 'a-propos'],
      ],
      'unparseable' => [
        'sorry, no json here',
        ['title' => '', 'slug' => ''],
      ],
    ];
  }

}

/**
 * Concrete AiMatcherBase whose constructor is bypassed (the tested helpers use
 * no injected services), exposing the two protected helpers publicly.
 */
final class AiMatcherBaseTestProxy extends AiMatcherBase {

  public function __construct() {
    // Intentionally does not call parent::__construct(): sanitizeSlug() and
    // parseTranslation() are pure and touch no plugin state.
  }

  public function isConfigured(): bool {
    return FALSE;
  }

  public function adjudicate(array $source, array $candidates): array {
    return $this->noDecision('n/a');
  }

  public function translate(array $source, string $targetLanguage): array {
    return $this->noTranslation();
  }

  public function sanitize(string $slug): string {
    return $this->sanitizeSlug($slug);
  }

  public function parse(string $raw): array {
    return $this->parseTranslation($raw);
  }

}
