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
            // Tier A (slug) first; fall back to Tier B/C (embeddings + LLM).
            if ($this->match_one($member) || $this->ai_match($member)) {
                $matched++;
            }
            // Stamp every processed member (matched or not) so the next run moves
            // on to the backlog instead of re-checking the same rows.
            $this->registry->stamp_matched((int) $member['id']);
        }
        if ($matched) {
            $this->registry->delete_empty_groups();
        }
    }

    /**
     * Tier B/C: embeddings surface candidates, the LLM adjudicates. Joins the
     * chosen candidate's group (stays 'proposed' for human review). No-op unless
     * embeddings are configured.
     *
     * @param array<string,mixed> $member
     */
    private function ai_match(array $member): bool {
        $embed = new Hrefl_Embedding_Matcher($this->registry, new Hrefl_Vector_Store());
        if (!$embed->is_configured()) {
            return false;
        }
        $embed->ensure_embedded($member);
        $candidates = $embed->candidates_for($member);
        if (!$candidates) {
            return false;
        }

        $ai = new Hrefl_Ai_Matcher();
        if ($ai->is_configured()) {
            $verdict = $ai->adjudicate($this->source_record($member), $candidates);
            $min = (float) Hrefl_Settings::get('ai_confidence', 0.6);
            if ($verdict['choice'] !== null && $verdict['confidence'] >= $min) {
                return $this->join_group($member, (string) $candidates[$verdict['choice']]['group_id'], (float) $verdict['confidence']);
            }
            return false;
        }

        // No LLM: accept the top embedding candidate only if very confident.
        $top = $candidates[0];
        $score = (float) ($top['embedding_score'] ?? 0.0);
        if ($score >= (float) Hrefl_Settings::get('embedding_autojoin', 0.9)) {
            return $this->join_group($member, (string) $top['group_id'], $score);
        }
        return false;
    }

    /**
     * @param array<string,mixed> $member
     *
     * @return array<string,mixed>
     */
    private function source_record(array $member): array {
        return [
            'url'      => (string) $member['url'],
            'market'   => (string) $member['market'],
            'language' => (string) ($member['lang'] ?? ''),
            'title'    => (string) ($member['title'] ?? ''),
        ];
    }

    /**
     * Move a member into an existing group (proposed - human still confirms).
     *
     * @param array<string,mixed> $member
     */
    private function join_group(array $member, string $group_id, float $confidence): bool {
        if ($group_id === '' || $group_id === (string) $member['group_id']) {
            return false;
        }
        $this->registry->upsert_member([
            'group_id'   => $group_id,
            'market'     => (string) $member['market'],
            'language'   => (string) ($member['lang'] ?? ''),
            'hreflang'   => (string) ($member['hreflang'] ?? ''),
            'url'        => (string) $member['url'],
            'title'      => $member['title'] ?? null,
            'status'     => 'proposed',
            'valid'      => (int) ($member['valid'] ?? 0),
            'changed'    => (int) ($member['changed'] ?? 0),
            'confidence' => $confidence,
        ]);
        return true;
    }

    private function match_one(array $member): bool {
        $slug = Hrefl_Registry::slug((string) $member['url']);
        if ($slug === '') {
            return false;
        }
        // Bridge across languages via the learned glossary (about-us <-> ueber-uns),
        // grown from prior confirmations; falls back to the identical-slug case.
        $slugs = array_merge([$slug], $this->registry->glossary_equivalents((string) ($member['lang'] ?? ''), $slug));
        $peers = $this->registry->members_by_slug($slugs, (string) $member['market']);
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
        // Join the anchor's group (stays 'proposed' for human review). A slug
        // match is deterministic -> confidence 1.0.
        $this->registry->upsert_member([
            'group_id'   => (string) $anchor['group_id'],
            'market'     => (string) $member['market'],
            'language'   => (string) $member['lang'],
            'hreflang'   => (string) $member['hreflang'],
            'url'        => (string) $member['url'],
            'title'      => $member['title'] ?? null,
            'status'     => 'proposed',
            'valid'      => (int) $member['valid'],
            'changed'    => (int) ($member['changed'] ?? 0),
            'confidence' => 1.0,
        ]);
        return true;
    }
}
