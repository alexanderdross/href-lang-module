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
        return [];
    }
}
