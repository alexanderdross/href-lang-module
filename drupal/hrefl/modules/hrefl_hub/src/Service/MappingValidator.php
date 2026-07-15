<?php

declare(strict_types=1);

namespace Drupal\hrefl_hub\Service;

/**
 * Structural validation of a translation group before its members go live.
 *
 * Reciprocity is guaranteed by the shared-group model, so this is the
 * belt-and-braces check for the rest of docs/HREFLANG-RULES.md: valid codes,
 * absolute URLs, code uniqueness within the group, and at most one x-default.
 * Target reachability (200/canonical/index) is a separate concern handled by
 * TargetValidator; this service never makes a network call.
 */
final class MappingValidator {

  private const X_DEFAULT = 'x-default';

  /**
   * Validate a set of member rows.
   *
   * @param array $members
   *   Member rows (as returned by Registry), each with hreflang + url.
   *
   * @return string[]
   *   Human-readable violation messages; empty means the set is valid.
   */
  public function validateMemberSet(array $members): array {
    $violations = [];
    $codes = [];
    $xDefaultCount = 0;

    foreach ($members as $member) {
      $code = (string) ($member['hreflang'] ?? '');
      $url = (string) ($member['url'] ?? '');

      if (!$this->isValidCode($code)) {
        $violations[] = sprintf('Invalid hreflang code "%s" for %s.', $code, $url ?: '(no URL)');
      }
      if (!$this->isAbsolute($url)) {
        $violations[] = sprintf('Non-absolute URL "%s".', $url);
      }
      if ($code === self::X_DEFAULT) {
        $xDefaultCount++;
      }
      else {
        $codes[$code][] = $url;
      }
    }

    foreach ($codes as $code => $urls) {
      if (count($urls) > 1) {
        $violations[] = sprintf('Duplicate hreflang code "%s" within the group (%s).', $code, implode(', ', $urls));
      }
    }
    if ($xDefaultCount > 1) {
      $violations[] = sprintf('A group must have at most one x-default; found %d.', $xDefaultCount);
    }

    return $violations;
  }

  /**
   * Whether confirming this member keeps the group's confirmed set valid.
   *
   * @param array $candidate
   *   The member being confirmed.
   * @param array $confirmedSiblings
   *   The group's already-confirmed members (excluding the candidate).
   *
   * @return string[]
   *   Violations that would block the confirmation; empty means allowed.
   */
  public function violationsForConfirm(array $candidate, array $confirmedSiblings): array {
    $violations = [];
    if ((int) ($candidate['valid'] ?? 0) !== 1) {
      $violations[] = sprintf('Target has not passed 200/canonical/index validation: %s', $candidate['url'] ?? '');
    }
    // Validate the prospective confirmed set (siblings + candidate).
    return array_merge($violations, $this->validateMemberSet([...$confirmedSiblings, $candidate]));
  }

  /**
   * Whether a code is a valid hreflang value.
   */
  public function isValidCode(string $code): bool {
    if ($code === self::X_DEFAULT) {
      return TRUE;
    }
    return (bool) preg_match('/^[a-z]{2,3}(-[A-Z]{2})?$/', $code);
  }

  /**
   * Whether an href is absolute and fully qualified over http(s).
   */
  public function isAbsolute(string $url): bool {
    $scheme = parse_url($url, PHP_URL_SCHEME);
    $host = parse_url($url, PHP_URL_HOST);
    return is_string($scheme)
      && is_string($host)
      && $host !== ''
      && in_array(strtolower($scheme), ['http', 'https'], TRUE);
  }

}
