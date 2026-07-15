<?php
/**
 * Tier-A URL-pattern matching: coalesce same-slug pages across markets.
 *
 * Runs on cron. Same-slug pages in different markets are proposed as one group,
 * choosing a deterministic anchor so singletons converge. Mirrors the Drupal
 * DeterministicMatcher (slug tier) + MappingEngine placement.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class Hrefl_Matcher {

    public function __construct(private Hrefl_Registry $registry) {}

    public function run(int $limit = 200): void {
        $matched = 0;
        foreach ($this->registry->members_needing_match($limit) as $member) {
            if ($this->match_one($member)) {
                $matched++;
            }
        }
        if ($matched) {
            $this->registry->delete_empty_groups();
        }
    }

    private function match_one(array $member): bool {
        $slug = Hrefl_Registry::slug((string) $member['url']);
        if ($slug === '') {
            return false;
        }
        $peers = $this->registry->members_by_slug([$slug], (string) $member['market']);
        if (!$peers) {
            return false;
        }
        // Anchor: prefer the global market, else the smallest group id.
        $anchor = null;
        foreach ($peers as $peer) {
            if ($peer['market'] === 'global') {
                $anchor = $peer;
                break;
            }
            if ($anchor === null || strcmp((string) $peer['group_id'], (string) $anchor['group_id']) < 0) {
                $anchor = $peer;
            }
        }
        // Join the anchor's group (stays 'proposed' for human review).
        $this->registry->upsert_member([
            'group_id' => (string) $anchor['group_id'],
            'market'   => (string) $member['market'],
            'language' => (string) $member['lang'],
            'hreflang' => (string) $member['hreflang'],
            'url'      => (string) $member['url'],
            'title'    => $member['title'] ?? null,
            'status'   => 'proposed',
            'valid'    => (int) $member['valid'],
        ]);
        return true;
    }
}
