<?php
/**
 * Reads the translation graph and reports its health for the dashboard.
 *
 * Reciprocity is structural (a shared group), so the Monitor is belt-and-braces
 * for everything else: coverage, targets that failed validation, hreflang code
 * collisions inside a confirmed group, groups with no x-default (no Global
 * member), and confirmed members with nothing to link to. It only reads; fixing
 * is the editor's job via the review queue / CSV. Mirrors the Drupal Monitor.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class Hrefl_Monitor {

    public function __construct(private Hrefl_Registry $registry) {}

    /**
     * Build the health report consumed by the dashboard.
     *
     * @return array<string,mixed>
     */
    public function report(): array {
        $status    = $this->registry->status_counts();
        $confirmed = $this->registry->all_confirmed_members();
        $issues    = self::analyze($confirmed);

        $total          = array_sum($status);
        $eligible       = $total - ($status['rejected'] ?? 0);
        $confirmedValid = count($confirmed) - count($issues['invalid_targets']);

        return [
            'totals' => [
                'groups'    => $this->registry->count_groups(),
                'members'   => $total,
                'confirmed' => $status['confirmed'] ?? 0,
                'proposed'  => $status['proposed'] ?? 0,
                'rejected'  => $status['rejected'] ?? 0,
            ],
            'coverage' => $eligible > 0 ? round($confirmedValid / $eligible, 4) : 0.0,
            'issues'   => $issues,
            'healthy'  => !$issues['invalid_targets']
                && !$issues['code_collisions']
                && !$issues['missing_x_default']
                && !$issues['lonely_confirmed'],
        ];
    }

    /**
     * Pure graph analysis over a set of confirmed members. Public for testability.
     *
     * @param array<int,array<string,mixed>> $confirmed
     *
     * @return array{
     *   invalid_targets: array<int,array<string,string>>,
     *   code_collisions: array<int,array<string,mixed>>,
     *   missing_x_default: string[],
     *   lonely_confirmed: array<int,array<string,string>>
     * }
     */
    public static function analyze(array $confirmed): array {
        $invalid = [];
        $byGroup = [];
        foreach ($confirmed as $m) {
            if ((int) ($m['valid'] ?? 0) !== 1) {
                $invalid[] = [
                    'url'      => (string) $m['url'],
                    'market'   => (string) $m['market'],
                    'hreflang' => (string) $m['hreflang'],
                ];
            }
            $byGroup[(string) $m['group_id']][] = $m;
        }

        $collisions = [];
        $missingX   = [];
        $lonely     = [];
        foreach ($byGroup as $group => $members) {
            $byCode = [];
            $hasGlobal = false;
            foreach ($members as $m) {
                $byCode[(string) $m['hreflang']][] = (string) $m['url'];
                if (($m['market'] ?? '') === 'global') {
                    $hasGlobal = true;
                }
            }
            foreach ($byCode as $code => $urls) {
                if (count($urls) > 1) {
                    $collisions[] = ['group_id' => $group, 'hreflang' => (string) $code, 'urls' => $urls];
                }
            }
            if (!$hasGlobal && count($members) >= 2) {
                $missingX[] = $group;
            }
            if (count($members) === 1) {
                $lonely[] = ['group_id' => $group, 'url' => (string) $members[0]['url']];
            }
        }

        return [
            'invalid_targets'   => $invalid,
            'code_collisions'   => $collisions,
            'missing_x_default' => $missingX,
            'lonely_confirmed'  => $lonely,
        ];
    }
}
