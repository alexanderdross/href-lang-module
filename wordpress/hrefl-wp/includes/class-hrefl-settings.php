<?php
/**
 * Settings accessor: reads plugin options with sane defaults.
 *
 * Options live under the `hrefl_settings` option. The shared HMAC secret is
 * read from the `HREFL_HUB_SECRET` constant (define it in wp-config.php) first,
 * falling back to the stored option - same idea as the Drupal env/key fallback.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class Hrefl_Settings {

    private const OPTION = 'hrefl_settings';

    public static function all(): array {
        $defaults = [
            // 'client' | 'hub' | 'both'  (the main site is usually 'both').
            'role'            => 'both',
            // This site's market key, e.g. global, de, us.
            'market'          => 'global',
            // Hub REST base, e.g. https://main-site.example/wp-json/hrefl/v1
            'hub_url'         => '',
            // This site's own base URL (defaults to home_url()).
            'site_url'        => home_url('/'),
            // langcode|hreflang lines, e.g. "en|en-US".
            'lang_map'        => ['' => ''],
            'emit_head'       => 1,
            'sitemap_enabled' => 1,
            // <priority> emitted for each sitemap URL (0.0-1.0).
            'sitemap_priority' => 0.5,
            // Hub-only: market => absolute owned prefix.
            'markets'         => [],
            // HMAC secret fallback (prefer the HREFL_HUB_SECRET constant).
            'secret'          => '',
            // Tier C - AI adjudication (optional). '' | 'anthropic' | 'copilot'.
            'ai_provider'      => '',
            'ai_endpoint'      => '',
            'ai_model'         => '',
            'ai_api_version'   => '2023-06-01',
            'ai_data_scope'    => 'metadata',
            'ai_key'           => '',
            // Min AI confidence to propose a match (still human-reviewed).
            'ai_confidence'    => 0.6,
            // Tier B - embeddings (optional, self-hosted preferred).
            'embedding_endpoint'  => '',
            'embedding_model'     => '',
            'embedding_key'       => '',
            'embedding_top_k'     => 5,
            'embedding_threshold' => 0.82,
            // Without AI: join the top embedding candidate above this score.
            'embedding_autojoin'  => 0.9,
            // Opt-out of human review: when set, the hub auto-confirms matched
            // mappings (still only if the target validated and there is no
            // hreflang collision). Default OFF - keep a human in the loop.
            'auto_confirm'        => 0,
            // Only auto-confirm matches at or above this confidence (slug matches
            // are 1.0; lower-confidence AI matches still route to human review).
            'auto_confirm_min_confidence' => 0.9,
        ];
        return array_merge($defaults, (array) get_option(self::OPTION, []));
    }

    public static function get(string $key, $default = null) {
        $all = self::all();
        return $all[$key] ?? $default;
    }

    public static function save(array $values): void {
        update_option(self::OPTION, array_merge(self::all(), $values));
    }

    /**
     * The shared HMAC secret: constant first, then stored option.
     */
    public static function secret(): string {
        if (defined('HREFL_HUB_SECRET') && HREFL_HUB_SECRET) {
            return (string) HREFL_HUB_SECRET;
        }
        return (string) self::get('secret', '');
    }

    public static function is_hub(): bool {
        return in_array(self::get('role'), ['hub', 'both'], true);
    }

    public static function is_client(): bool {
        return in_array(self::get('role'), ['client', 'both'], true);
    }

    /**
     * Parse the "langcode|hreflang" lines into a map.
     */
    public static function lang_map(): array {
        $map = [];
        foreach ((array) self::get('lang_map', []) as $langcode => $hreflang) {
            if (is_string($langcode) && $langcode !== '') {
                $map[$langcode] = (string) $hreflang;
            }
        }
        return $map;
    }
}
