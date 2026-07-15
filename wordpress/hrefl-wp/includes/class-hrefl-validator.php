<?php
/**
 * Enforces the hreflang correctness rules on an alternate set before emission.
 *
 * Absolute http(s) URLs only, valid codes normalized to lower-UPPER, one entry
 * per code, at most one x-default. Mirrors the Drupal HreflangValidator.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

final class Hrefl_Validator {

    private const X_DEFAULT = 'x-default';

    /**
     * @param array<int,array{hreflang:string,href:string}> $alternates
     * @return array<int,array{hreflang:string,href:string}>
     */
    public static function clean(array $alternates): array {
        $out = [];
        $seen = [];
        $x = false;
        foreach ($alternates as $alt) {
            $href = trim((string) ($alt['href'] ?? ''));
            $code = self::normalize_code((string) ($alt['hreflang'] ?? ''));
            if ($href === '' || $code === '' || !self::is_absolute($href)) {
                continue;
            }
            if ($code === self::X_DEFAULT) {
                if ($x) {
                    continue;
                }
                $x = true;
            } elseif (!self::is_valid_code($code)) {
                continue;
            }
            if (isset($seen[$code])) {
                continue;
            }
            $seen[$code] = true;
            $out[] = ['hreflang' => $code, 'href' => $href];
        }
        return $out;
    }

    public static function normalize_code(string $code): string {
        $code = str_replace('_', '-', trim($code));
        if ($code === '') {
            return '';
        }
        if (strcasecmp($code, self::X_DEFAULT) === 0) {
            return self::X_DEFAULT;
        }
        $parts = explode('-', $code);
        $lang = strtolower($parts[0]);
        if (isset($parts[1]) && $parts[1] !== '') {
            return $lang . '-' . strtoupper($parts[1]);
        }
        return $lang;
    }

    public static function is_valid_code(string $code): bool {
        if ($code === self::X_DEFAULT) {
            return true;
        }
        return (bool) preg_match('/^[a-z]{2,3}(-[A-Z]{2})?$/', $code);
    }

    public static function is_absolute(string $href): bool {
        $scheme = wp_parse_url($href, PHP_URL_SCHEME);
        $host = wp_parse_url($href, PHP_URL_HOST);
        return is_string($scheme) && is_string($host) && $host !== ''
            && in_array(strtolower($scheme), ['http', 'https'], true);
    }
}
