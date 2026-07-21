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

    public function __construct(private Hrefl_Registry $registry) {}

    /**
     * @return array<int,array{url:string,alternates:array,lastmod:?int}>
     */
    public function for_market(string $market): array {
        $pages = [];
        $resolved = [];
        foreach ($this->registry->confirmed_for_market($market) as $member) {
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
        return $pages;
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
