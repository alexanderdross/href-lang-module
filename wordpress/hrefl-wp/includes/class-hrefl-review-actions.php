<?php
/**
 * The confirm/reject decision guard, shared by the on-screen review queue and
 * the CSV import so both apply exactly the same correctness rules.
 *
 * confirm() returns a list of human-readable violations - empty means it was
 * applied. A row that would break its group is reported, never half-applied.
 * Mirrors the Drupal ReviewActions service.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class Hrefl_Review_Actions {

    public function __construct(private Hrefl_Registry $registry) {}

    /**
     * Reject a member (idempotent). Returns true if the member existed.
     */
    public function reject(int $id): bool {
        if (!$this->registry->load_member($id)) {
            return false;
        }
        $this->registry->set_status($id, 'rejected');
        return true;
    }

    /**
     * Confirm a member if it is valid and does not collide within its group.
     *
     * @return string[]
     *   Violation messages; empty when the member was confirmed.
     */
    public function confirm(int $id): array {
        $member = $this->registry->load_member($id);
        if (!$member) {
            return [__('Member not found.', 'hrefl')];
        }
        if ((int) $member['valid'] !== 1) {
            return [__('Target is not validated (must be an indexable HTTP 200 URL).', 'hrefl')];
        }
        foreach ($this->registry->members_of_group((string) $member['group_id']) as $sib) {
            if ((int) $sib['id'] !== $id
                && $sib['status'] === 'confirmed'
                && $sib['hreflang'] === $member['hreflang']) {
                return [__('Another confirmed member already uses this hreflang code.', 'hrefl')];
            }
        }
        $this->registry->set_status($id, 'confirmed');
        // Feedback loop: turn this confirmed equivalence into glossary entries so
        // the next Tier-A pass bridges these slugs deterministically.
        $confirmed_siblings = array_values(array_filter(
            $this->registry->members_of_group((string) $member['group_id']),
            static fn(array $s): bool => (int) $s['id'] !== $id && $s['status'] === 'confirmed'
        ));
        foreach (self::glossary_pairs($member, $confirmed_siblings) as $p) {
            $this->registry->add_glossary_entry($p[0], $p[1], $p[2], $p[3]);
        }
        return [];
    }

    /**
     * The cross-language slug pairs to learn from a confirmed member and its
     * confirmed siblings, both directions. Pure for testability.
     *
     * @param array<string,mixed>              $member
     * @param array<int,array<string,mixed>>   $siblings
     *
     * @return array<int,array{0:string,1:string,2:string,3:string}>
     *   [source_lang, target_lang, source_token, target_token] tuples.
     */
    public static function glossary_pairs(array $member, array $siblings): array {
        $lang = (string) ($member['lang'] ?? '');
        $slug = (string) ($member['path_key'] ?? '');
        if ($lang === '' || $slug === '') {
            return [];
        }
        $pairs = [];
        foreach ($siblings as $sib) {
            $sib_lang = (string) ($sib['lang'] ?? '');
            $sib_slug = (string) ($sib['path_key'] ?? '');
            // Only cross-language pairs with distinct slugs teach anything new.
            if ($sib_lang === '' || $sib_slug === '' || $sib_lang === $lang || $sib_slug === $slug) {
                continue;
            }
            $pairs[] = [$lang, $sib_lang, $slug, $sib_slug];
            $pairs[] = [$sib_lang, $lang, $sib_slug, $slug];
        }
        return $pairs;
    }
}
