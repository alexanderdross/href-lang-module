<?php
/**
 * Tier B: cross-market candidate equivalents via embeddings.
 *
 * Embeds a page's text (title + slug, cached per content version), stores the
 * vector, and finds the nearest pages in other markets - the candidate set that
 * Tier C (the LLM) then adjudicates. Inert until an embedding endpoint is
 * configured. Mirrors the Drupal EmbeddingMatcher + HttpEmbedding provider.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class Hrefl_Embedding_Matcher {

    public function __construct(
        private Hrefl_Registry $registry,
        private Hrefl_Vector_Store $store
    ) {}

    public function is_configured(): bool {
        return (string) Hrefl_Settings::get('embedding_endpoint', '') !== ''
            && (string) Hrefl_Settings::get('embedding_model', '') !== '';
    }

    /**
     * Candidate equivalent records for a source member (for Tier C).
     *
     * @param array<string,mixed> $member
     *
     * @return array<int,array<string,mixed>>
     */
    public function candidates_for(array $member, ?int $top_k = null, ?float $threshold = null): array {
        if (!$this->is_configured()) {
            return [];
        }
        $vectors = $this->embed([$this->embed_text($member)]);
        if (!$vectors) {
            return [];
        }
        $top_k ??= (int) Hrefl_Settings::get('embedding_top_k', 5);
        $threshold ??= (float) Hrefl_Settings::get('embedding_threshold', 0.82);

        $candidates = [];
        foreach ($this->store->nearest($vectors[0], (string) $member['market'], $top_k, $threshold) as $hit) {
            $peer = $this->registry->member_by_url($hit['url']);
            if (!$peer) {
                continue;
            }
            $candidates[] = [
                'url'             => (string) $peer['url'],
                'market'          => (string) $peer['market'],
                'language'        => (string) $peer['lang'],
                'title'           => (string) ($peer['title'] ?? ''),
                'group_id'        => (string) $peer['group_id'],
                'embedding_score' => $hit['score'],
            ];
        }
        return $candidates;
    }

    /**
     * Embed a member if its text changed; true if a vector is now stored.
     *
     * @param array<string,mixed> $member
     */
    public function ensure_embedded(array $member): bool {
        if (!$this->is_configured()) {
            return false;
        }
        $text = $this->embed_text($member);
        if ($text === '') {
            return false;
        }
        $hash = hash('sha256', $text);
        if ($this->store->content_hash_for((string) $member['url']) === $hash) {
            return true;
        }
        $vectors = $this->embed([$text]);
        if (!$vectors) {
            return false;
        }
        $this->store->upsert((string) $member['url'], (string) $member['market'], (string) $member['lang'], $hash, $vectors[0]);
        return true;
    }

    /**
     * Embed a bounded batch of members missing a vector (cron warm-up).
     */
    public function embed_pass(int $limit = 200): int {
        if (!$this->is_configured()) {
            return 0;
        }
        $embedded = 0;
        foreach ($this->registry->members_missing_embedding($limit) as $member) {
            if ($this->ensure_embedded($member)) {
                $embedded++;
            }
        }
        return $embedded;
    }

    /**
     * Call the embedding endpoint: {model, input:[texts]} -> vectors.
     *
     * @param array<int,string> $texts
     *
     * @return array<int,array<int,float>>
     */
    public function embed(array $texts): array {
        $texts = array_values(array_filter($texts, static fn($t) => $t !== ''));
        if (!$texts || !$this->is_configured()) {
            return [];
        }
        $headers = ['content-type' => 'application/json'];
        $key = defined('HREFL_EMBEDDING_KEY') && HREFL_EMBEDDING_KEY
            ? (string) HREFL_EMBEDDING_KEY
            : (string) Hrefl_Settings::get('embedding_key', '');
        if ($key !== '') {
            $headers['authorization'] = 'Bearer ' . $key;
        }
        $resp = wp_remote_post((string) Hrefl_Settings::get('embedding_endpoint', ''), [
            'headers' => $headers,
            'body'    => wp_json_encode(['model' => Hrefl_Settings::get('embedding_model', ''), 'input' => $texts]),
            'timeout' => 30,
        ]);
        if (is_wp_error($resp) || (int) wp_remote_retrieve_response_code($resp) >= 300) {
            error_log('[hrefl] embedding request failed');
            return [];
        }
        $payload = json_decode((string) wp_remote_retrieve_body($resp), true);
        return self::extract_vectors($payload, count($texts));
    }

    /**
     * The text embedded for a member: title plus its slug.
     *
     * @param array<string,mixed> $member
     */
    private function embed_text(array $member): string {
        return trim(((string) ($member['title'] ?? '')) . ' ' . ((string) ($member['path_key'] ?? '')));
    }

    /**
     * Pull vectors out of a {data:[{embedding:[...]}]} response, in order.
     *
     * @return array<int,array<int,float>>
     */
    public static function extract_vectors(mixed $payload, int $expected): array {
        if (!is_array($payload) || !isset($payload['data']) || !is_array($payload['data'])) {
            return [];
        }
        $vectors = [];
        foreach ($payload['data'] as $row) {
            if (isset($row['embedding']) && is_array($row['embedding'])) {
                $vectors[] = array_map('floatval', $row['embedding']);
            }
        }
        return count($vectors) === $expected ? $vectors : [];
    }
}
