<?php
/**
 * The hub's translation-group registry (source of truth).
 *
 * Reciprocity is guaranteed by the shared-group model. Mirrors the Drupal
 * Registry over two tables (group, member).
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class Hrefl_Registry {

    private function members(): string {
        global $wpdb;
        return $wpdb->prefix . 'hrefl_member';
    }

    private function groups(): string {
        global $wpdb;
        return $wpdb->prefix . 'hrefl_group';
    }

    public function create_group(): string {
        global $wpdb;
        $uuid = wp_generate_uuid4();
        $wpdb->insert($this->groups(), ['group_id' => $uuid, 'updated' => time()]);
        return $uuid;
    }

    /**
     * Insert or update a member keyed by its URL.
     */
    public function upsert_member(array $m): int {
        global $wpdb;
        $t = $this->members();
        $urlHash = hash('sha256', (string) $m['url']);
        $fields = [
            'group_id' => (string) $m['group_id'],
            'market'   => (string) $m['market'],
            'lang'     => (string) ($m['language'] ?? ''),
            'hreflang' => (string) ($m['hreflang'] ?? ''),
            'url'      => (string) $m['url'],
            'url_hash' => $urlHash,
            'path_key' => self::slug((string) $m['url']),
            'title'    => isset($m['title']) ? (string) $m['title'] : null,
            'status'   => (string) ($m['status'] ?? 'proposed'),
            'valid'    => (int) ($m['valid'] ?? 0),
            'changed'  => (int) ($m['changed'] ?? 0),
        ];
        // Only touch confidence when the caller sets it (a matcher), so a plain
        // re-ingest never resets a scored member back to 0.
        if (isset($m['confidence'])) {
            $fields['confidence'] = (float) $m['confidence'];
        }
        $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$t} WHERE url_hash = %s", $urlHash));
        if ($existing) {
            $wpdb->update($t, $fields, ['id' => (int) $existing]);
            return (int) $existing;
        }
        $wpdb->insert($t, $fields);
        return (int) $wpdb->insert_id;
    }

    public function group_for_url(string $url): ?string {
        global $wpdb;
        $g = $wpdb->get_var($wpdb->prepare(
            "SELECT group_id FROM {$this->members()} WHERE url_hash = %s",
            hash('sha256', $url)
        ));
        return $g ?: null;
    }

    public function member_by_url(string $url): ?array {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->members()} WHERE url_hash = %s",
            hash('sha256', $url)
        ), ARRAY_A);
        return $row ?: null;
    }

    public function members_of_group(string $group): array {
        global $wpdb;
        return (array) $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->members()} WHERE group_id = %s",
            $group
        ), ARRAY_A);
    }

    public function set_status(int $id, string $status): void {
        global $wpdb;
        $wpdb->update($this->members(), ['status' => $status], ['id' => $id]);
    }

    public function members_needing_match(int $limit = 200): array {
        global $wpdb;
        // Fair pass: never-matched and longest-ago-matched rows first, so a
        // backlog beyond the limit cannot starve the tail or re-spend work on
        // the same first N rows every run. Mirrors the Drupal last_matched order.
        return (array) $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->members()}
             WHERE status IN ('proposed','held')
             ORDER BY last_matched ASC, id ASC
             LIMIT %d",
            $limit
        ), ARRAY_A);
    }

    /**
     * Stamp a member as matched now, sending it to the back of the fair queue.
     */
    public function stamp_matched(int $id): void {
        global $wpdb;
        $wpdb->update($this->members(), ['last_matched' => time()], ['id' => $id]);
    }

    /**
     * Members that have no stored embedding vector yet (Tier-B warm-up).
     */
    public function members_missing_embedding(int $limit = 200): array {
        global $wpdb;
        $m = $this->members();
        $e = $wpdb->prefix . 'hrefl_embedding';
        return (array) $wpdb->get_results($wpdb->prepare(
            "SELECT m.* FROM {$m} m
             LEFT JOIN {$e} e ON e.url_hash = m.url_hash
             WHERE e.url_hash IS NULL AND m.status IN ('proposed','held','confirmed')
             ORDER BY m.id ASC LIMIT %d",
            $limit
        ), ARRAY_A);
    }

    public function members_by_slug(array $slugs, string $exclude_market): array {
        global $wpdb;
        $slugs = array_values(array_unique(array_filter($slugs, static fn($s) => $s !== '')));
        if (!$slugs) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($slugs), '%s'));
        $sql = "SELECT * FROM {$this->members()} WHERE path_key IN ($ph) AND market <> %s";
        $args = array_merge($slugs, [$exclude_market]);
        return (array) $wpdb->get_results($wpdb->prepare($sql, $args), ARRAY_A);
    }

    /**
     * Members whose target has not been validated yet.
     */
    public function members_needing_validation(int $limit = 100): array {
        global $wpdb;
        return (array) $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->members()} WHERE valid = 0 LIMIT %d",
            $limit
        ), ARRAY_A);
    }

    public function set_valid(int $id, bool $valid): void {
        global $wpdb;
        $wpdb->update($this->members(), ['valid' => $valid ? 1 : 0], ['id' => $id]);
    }

    public function confirmed_for_market(string $market, int $after_id = 0, int $limit = 0): array {
        global $wpdb;
        // Ordered by the auto-increment PK so a cursor (last id seen) can page
        // the whole market across serve requests, instead of loading every
        // confirmed page of a large corpus into one response.
        $sql = "SELECT * FROM {$this->members()} WHERE market = %s AND status = 'confirmed' AND valid = 1 AND id > %d ORDER BY id ASC";
        $params = [$market, $after_id];
        if ($limit > 0) {
            $sql .= ' LIMIT %d';
            $params[] = $limit;
        }
        return (array) $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);
    }

    public function all_needing_review(int $limit = 500): array {
        return $this->members_needing_match($limit);
    }

    public function delete_empty_groups(): void {
        global $wpdb;
        $wpdb->query(
            "DELETE g FROM {$this->groups()} g
             LEFT JOIN {$this->members()} m ON m.group_id = g.group_id
             WHERE m.id IS NULL"
        );
    }

    public function load_member(int $id): ?array {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->members()} WHERE id = %d", $id), ARRAY_A);
        return $row ?: null;
    }

    /**
     * Every member, oldest first, for the CSV review export.
     *
     * @return array<int,array<string,mixed>>
     */
    public function all_members(): array {
        global $wpdb;
        return (array) $wpdb->get_results("SELECT * FROM {$this->members()} ORDER BY group_id ASC, id ASC", ARRAY_A);
    }

    /**
     * The member id for a URL, or null. Used to apply a CSV decision row.
     */
    public function member_id_for_url(string $url): ?int {
        $member = $this->member_by_url($url);
        return $member ? (int) $member['id'] : null;
    }

    /**
     * Member counts keyed by status, for the health dashboard.
     *
     * @return array<string,int>
     */
    public function status_counts(): array {
        global $wpdb;
        $rows = (array) $wpdb->get_results("SELECT status, COUNT(*) AS n FROM {$this->members()} GROUP BY status", ARRAY_A);
        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r['status']] = (int) $r['n'];
        }
        return $out;
    }

    /**
     * Every confirmed member, for the health dashboard graph checks.
     *
     * @return array<int,array<string,mixed>>
     */
    public function all_confirmed_members(): array {
        global $wpdb;
        return (array) $wpdb->get_results("SELECT * FROM {$this->members()} WHERE status = 'confirmed' ORDER BY group_id ASC", ARRAY_A);
    }

    /**
     * Total translation groups.
     */
    public function count_groups(): int {
        global $wpdb;
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->groups()}");
    }

    /**
     * Proposed members whose target has already validated, oldest first.
     *
     * The candidate set for the optional auto-confirm pass; the confirm guard
     * still applies the no-collision check per member.
     *
     * @return array<int,array<string,mixed>>
     */
    public function proposed_valid_members(int $limit = 200, float $min_confidence = 0.0): array {
        global $wpdb;
        return (array) $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->members()} WHERE status = 'proposed' AND valid = 1 AND confidence >= %f ORDER BY id ASC LIMIT %d",
            $min_confidence,
            $limit
        ), ARRAY_A);
    }

    /**
     * Leaf slug of a URL (for URL-pattern matching).
     */
    public static function slug(string $url): string {
        $path = (string) wp_parse_url($url, PHP_URL_PATH);
        $parts = array_values(array_filter(explode('/', $path), static fn($s) => $s !== ''));
        return $parts ? strtolower(rawurldecode(end($parts))) : '';
    }
}
