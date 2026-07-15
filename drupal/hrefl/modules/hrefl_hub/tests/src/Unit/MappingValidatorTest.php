<?php

declare(strict_types=1);

namespace Drupal\Tests\hrefl_hub\Unit;

use Drupal\hrefl_hub\Service\MappingValidator;
use Drupal\Tests\UnitTestCase;

/**
 * @coversDefaultClass \Drupal\hrefl_hub\Service\MappingValidator
 * @group hrefl_hub
 */
final class MappingValidatorTest extends UnitTestCase {

  private MappingValidator $validator;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->validator = new MappingValidator();
  }

  /**
   * @covers ::validateMemberSet
   */
  public function testValidSetHasNoViolations(): void {
    $members = [
      ['hreflang' => 'en', 'url' => 'https://ex.com/about-us'],
      ['hreflang' => 'de', 'url' => 'https://ex.com/de/ueber-uns'],
      ['hreflang' => 'x-default', 'url' => 'https://ex.com/about-us'],
    ];
    $this->assertSame([], $this->validator->validateMemberSet($members));
  }

  /**
   * @covers ::validateMemberSet
   */
  public function testDuplicateCodeIsReported(): void {
    $members = [
      ['hreflang' => 'en', 'url' => 'https://ex.com/a'],
      ['hreflang' => 'en', 'url' => 'https://ex.com/b'],
    ];
    $violations = $this->validator->validateMemberSet($members);
    $this->assertCount(1, $violations);
    $this->assertStringContainsString('Duplicate hreflang code "en"', $violations[0]);
  }

  /**
   * @covers ::validateMemberSet
   */
  public function testTwoXDefaultsAreReported(): void {
    $members = [
      ['hreflang' => 'x-default', 'url' => 'https://ex.com/a'],
      ['hreflang' => 'x-default', 'url' => 'https://ex.com/b'],
    ];
    $violations = $this->validator->validateMemberSet($members);
    $this->assertNotEmpty($violations);
    $this->assertStringContainsString('at most one x-default', end($violations));
  }

  /**
   * @covers ::validateMemberSet
   */
  public function testInvalidCodeAndRelativeUrlReported(): void {
    $members = [
      ['hreflang' => 'english', 'url' => '/relative/path'],
    ];
    $violations = $this->validator->validateMemberSet($members);
    $this->assertCount(2, $violations);
  }

  /**
   * @covers ::violationsForConfirm
   */
  public function testConfirmBlockedWhenTargetNotValid(): void {
    $candidate = ['hreflang' => 'de', 'url' => 'https://ex.com/de/ueber-uns', 'valid' => 0];
    $violations = $this->validator->violationsForConfirm($candidate, []);
    $this->assertNotEmpty($violations);
    $this->assertStringContainsString('200/canonical/index', $violations[0]);
  }

  /**
   * @covers ::violationsForConfirm
   */
  public function testConfirmAllowedForValidTargetAndCleanGroup(): void {
    $candidate = ['hreflang' => 'de', 'url' => 'https://ex.com/de/ueber-uns', 'valid' => 1];
    $siblings = [
      ['hreflang' => 'en', 'url' => 'https://ex.com/about-us'],
    ];
    $this->assertSame([], $this->validator->violationsForConfirm($candidate, $siblings));
  }

  /**
   * @covers ::violationsForConfirm
   */
  public function testConfirmBlockedOnCodeCollisionWithSibling(): void {
    $candidate = ['hreflang' => 'en', 'url' => 'https://ex.com/other', 'valid' => 1];
    $siblings = [
      ['hreflang' => 'en', 'url' => 'https://ex.com/about-us'],
    ];
    $violations = $this->validator->violationsForConfirm($candidate, $siblings);
    $this->assertNotEmpty($violations);
    $this->assertStringContainsString('Duplicate hreflang code "en"', implode(' ', $violations));
  }

}
