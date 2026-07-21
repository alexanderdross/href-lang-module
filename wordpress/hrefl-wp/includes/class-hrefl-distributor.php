<?php
/**
 * Pre-computes the per-URL resolved alternate set served to clients.
 *
 * Confirmed + valid only, absolute, one x-default per group (the global member
 * by convention). Mirrors the Drupal Distributor.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class Hrefl_Distributor {

    /** Default serve page size (confirmed members scanned per request). */
    public const PAGE_SIZE = 500;

    public function __construct(private Hrefl_Registry $registry) {}

    /**
     * Walk every page of a market. Convenience wrapper; prefer serve_page() on
     * the request path so a large market never lands in one response.
     *
     * @return array<int,array{url:string,alternates:array,lastmod:?int}>
     */
    public function for_market(string $market): array {
        $pages = [];
        $after = 0;
        do {
            $batch = $this->serve_page($market, $after, self::PAGE_SIZE);
            $pages = array_merge($pages, $batch['pages']);
            $after = $batch['next'];
        } while ($after !== null);
        return $pages;
    }

    /**
     * One cursor page of resolved alternates for a market.
     *
     * The cursor is the last member *scanned* (not the last emitted), so
     * self-only groups that are skipped still advance it.
     *
     * @return array{pages:array<int,array{url:string,alternates:array,lastmod:?int}>,next:?int}
     */
    public function serve_page(string $market, int $after_id = 0, int $limit = self::PAGE_SIZE): array {
        $limit = max(1, $limit);
        $members = $this->registry->confirmed_for_market($market, $after_id, $limit);
        $pages = [];
        $resolved = [];
        $last_id = null;
        foreach ($members as $member) {
            $last_id = (int) $member['id'];
            $group = (string) $member['group_id'];
            if (!isset($resolved[$group])) {
                $resolved[$group] = $this->resolve_group($group);
            }
            $alternates = $resolved[$group];
            if (count($alternates) <= 1) {
                continue;
            }
            $pages[] = [
                'url'        => (string) $member['url'],
                'alternates' => $alternates,
                'lastmod'    => !empty($member['changed']) ? (int) $member['changed'] : null,
            ];
        }
        return ['pages' => $pages, 'next' => self::next_cursor(count($members), $limit, $last_id)];
    }

    /**
     * The cursor for the next serve page, or null when the market is exhausted.
     *
     * A full batch (scanned === limit) means there may be more, so page from the
     * last id seen; a short batch is the final page. Pure for testability.
     */
    public static function next_cursor(int $scanned, int $limit, ?int $last_id): ?int {
        return ($scanned === $limit && $last_id !== null) ? $last_id : null;
    }

    private function resolve_group(string $group): array {
        $alternates = [];
        $x_default = null;
        foreach ($this->registry->members_of_group($group) as $member) {
            if ($member['status'] !== 'confirmed' || (int) $member['valid'] !== 1) {
                continue;
            }
            $alternates[] = ['hreflang' => (string) $member['hreflang'], 'href' => (string) $member['url']];
            if ($member['market'] === 'global' && $x_default === null) {
                $x_default = (string) $member['url'];
            }
        }
        if ($x_default !== null) {
            $alternates[] = ['hreflang' => 'x-default', 'href' => $x_default];
        }
        return $alternates;
    }
}
