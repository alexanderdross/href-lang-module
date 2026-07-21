<?php
/**
 * Turns an hreflang code into a human-readable selector label.
 *
 * "en-US" -> "English (United States)", "de" -> "Deutsch", "x-default" ->
 * "International". Uses the intl extension when available, then a compact
 * curated map, then a readable fallback derived from the code itself - so the
 * selector never shows a bare "en-US" to visitors. Mirrors the intent of the
 * Drupal selector's label.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class Hrefl_Locale {

    /** Common language subtags -> endonym (the language's own name). */
    private const LANGUAGES = [
        'en' => 'English', 'de' => 'Deutsch', 'fr' => 'Français', 'es' => 'Español',
        'it' => 'Italiano', 'pt' => 'Português', 'nl' => 'Nederlands', 'pl' => 'Polski',
        'sv' => 'Svenska', 'da' => 'Dansk', 'fi' => 'Suomi', 'no' => 'Norsk', 'nb' => 'Norsk bokmål',
        'cs' => 'Čeština', 'sk' => 'Slovenčina', 'hu' => 'Magyar', 'ro' => 'Română',
        'ru' => 'Русский', 'uk' => 'Українська', 'tr' => 'Türkçe', 'el' => 'Ελληνικά',
        'ja' => '日本語', 'zh' => '中文', 'ko' => '한국어', 'ar' => 'العربية', 'he' => 'עברית',
    ];

    /** Common region subtags -> English region name. */
    private const REGIONS = [
        'US' => 'United States', 'GB' => 'United Kingdom', 'CA' => 'Canada', 'AU' => 'Australia',
        'DE' => 'Germany', 'AT' => 'Austria', 'CH' => 'Switzerland', 'FR' => 'France',
        'ES' => 'Spain', 'MX' => 'Mexico', 'IT' => 'Italy', 'PT' => 'Portugal', 'BR' => 'Brazil',
        'NL' => 'Netherlands', 'BE' => 'Belgium', 'SE' => 'Sweden', 'NO' => 'Norway',
        'DK' => 'Denmark', 'FI' => 'Finland', 'PL' => 'Poland', 'CZ' => 'Czechia',
        'JP' => 'Japan', 'CN' => 'China', 'TW' => 'Taiwan', 'KR' => 'South Korea',
        '419' => 'Latin America',
    ];

    public static function label(string $code): string {
        if ($code === 'x-default') {
            return __('International', 'hrefl');
        }

        [$lang, $script, $region] = self::parts($code);

        // Curated endonyms first (deterministic + nicer for a selector); the
        // intl extension covers the long tail; else fall back to the code.
        if (isset(self::LANGUAGES[$lang])) {
            $language = self::LANGUAGES[$lang];
        } elseif (class_exists('Locale') && ($n = \Locale::getDisplayLanguage($lang)) && strtolower($n) !== strtolower($lang)) {
            $language = $n;
        } else {
            $language = strtoupper($lang);
        }

        $regionName = '';
        if ($region !== '') {
            $regionName = self::REGIONS[$region] ?? $region;
        }

        return $regionName !== '' ? sprintf('%s (%s)', $language, $regionName) : $language;
    }

    /**
     * @return array{0:string,1:string,2:string} [language, script, region]
     */
    private static function parts(string $code): array {
        $bits = explode('-', $code);
        $lang = strtolower($bits[0] ?? '');
        $script = '';
        $region = '';
        foreach (array_slice($bits, 1) as $bit) {
            if (preg_match('/^[A-Za-z]{4}$/', $bit)) {
                $script = ucfirst(strtolower($bit));
            } elseif (preg_match('/^([A-Za-z]{2}|\d{3})$/', $bit)) {
                $region = ctype_digit($bit) ? $bit : strtoupper($bit);
            }
        }
        return [$lang, $script, $region];
    }
}
